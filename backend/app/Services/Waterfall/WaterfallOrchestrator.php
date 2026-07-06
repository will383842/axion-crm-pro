<?php

namespace App\Services\Waterfall;

use App\Contracts\AnnuaireEntreprisesClient;
use App\Contracts\BanGeocoder;
use App\Contracts\BodaccClient;
use App\Contracts\InseeClient;
use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Models\AudienceMember;
use App\Models\Company;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Classification\AutoClassifierService;
use App\Services\Dedup\DeduplicationService;
use App\Services\Domain\DomainFinderService;
use App\Services\Legal\MentionsLegalesScraperService;
use App\Services\Scraping\GooglePlacesClient;
use App\Services\Tags\AutoTaggerService;
use App\Services\Triage\TriageAutoService;
use App\Support\WaterfallSentry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Waterfall enrichment — Sprint Pipeline 360° (16 étapes).
 *
 * Ordre canonique :
 *  1.  INSEE identification              (PHP — HTTP)
 *  2.  annuaire-entreprises légal        (PHP — HTTP)
 *  3.  BODACC signaux                    (PHP — HTTP)
 *  3b. NOUVEAU DomainFinder              (PHP — cascade DDG/PJ)
 *  3c. NOUVEAU MentionsLegales scrape    (PHP — HTTP)
 *  4.  Google Maps / Pages Jaunes / Web  (Node BullMQ, skip si MOCK_SCRAPERS)
 *  7.  Email finder + SMTP cascade       (PHP)
 *  8.  Géocodage BAN                     (PHP — HTTP)
 *  9.  France Travail signaux            (PHP — HTTP, stub)
 *  10. Classification LLM                (PHP — LLMRouter)
 *  10b. NOUVEAU AutoClassifier           (denormalize geo+size+sector)
 *  10c. NOUVEAU AutoTagger               (tags structurés auto+llm)
 *  11. NOUVEAU TriageAuto                (prospection_status final)
 *  12. NOUVEAU AutoSegment audiences     (add to matching audiences)
 */
class WaterfallOrchestrator
{
    public function __construct(
        private readonly InseeClient $insee,
        private readonly AnnuaireEntreprisesClient $annuaire,
        private readonly BodaccClient $bodacc,
        private readonly BanGeocoder $ban,
        private readonly LLMClient $llm,
        private readonly DeduplicationService $dedup,
        private readonly \App\Services\Email\EmailFinderService $emailFinder,
        // Sprint Pipeline 360° — nouveaux services injectés
        private readonly DomainFinderService $domainFinder,
        private readonly MentionsLegalesScraperService $mentionsLegales,
        private readonly AutoClassifierService $autoClassifier,
        private readonly AutoTaggerService $autoTagger,
        private readonly TriageAutoService $triage,
        private readonly AudienceBuilderService $audienceBuilder,
        // Sprint H9 — Google Places API (server-side, remplace scrape Google Maps Node)
        private readonly ?GooglePlacesClient $googlePlaces = null,
    ) {}

    public function enrich(Company $company): void
    {
        Log::info('Waterfall start', ['company_id' => $company->id, 'siren' => $company->siren]);

        // Sprint H3 — Si étape INSEE détecte entreprise radiée → archive + SKIP tout le waterfall.
        // RÉSILIENCE : un échec INSEE (ex. IP TLS-bloquée) ne doit PAS tuer le waterfall —
        // on a déjà les données INSEE de la collecte, l'enrichissement n'en dépend pas.
        try {
            if ($this->step1_insee($company) === 'archived') {
                $company->enriched_at = now();
                $company->save();
                Log::info('Waterfall short-circuit (entreprise radiée)', [
                    'company_id' => $company->id, 'siren' => $company->siren,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('step1 insee failed (skipped)', ['company_id' => $company->id, 'error' => $e->getMessage()]);
        }

        $this->safe(fn () => $this->step2_annuaire($company), 'step2_annuaire', $company);
        $this->safe(fn () => $this->step3_bodacc($company), 'step3_bodacc', $company);
        $this->step3b_find_domain($company);
        $this->step3c_mentions_legales($company);
        $this->step3d_google_places($company);
        $this->step4_dispatch_node_scrapes($company);
        $this->step7_email_finder($company);
        $this->safe(fn () => $this->step8_geocode($company), 'step8_geocode', $company);
        $this->step9_france_travail($company);
        $this->step10_classify($company);
        $this->step10b_auto_classify($company);
        $this->step10c_auto_tag($company);
        $this->step11_triage_auto($company);
        $this->step12_auto_segment($company);

        $company->enriched_at = now();
        $company->save();

        Log::info('Waterfall done', ['company_id' => $company->id, 'status' => $company->prospection_status]);
    }

    /**
     * Retourne 'archived' si l'entreprise est radiée (court-circuit waterfall),
     * 'ok' si active ou état inconnu (poursuite normale).
     */
    private function step1_insee(Company $company): string
    {
        if (! $this->dedup->shouldRunScrape((string) $company->workspace_id, 'insee', ['siren' => $company->siren])['should_run']) {
            return 'ok';
        }
        $data = $this->insee->fetchBySiren($company->siren);
        if (! $data) {
            return 'ok';
        }

        // Sprint H3 — Garde-fou état admin : entreprise radiée → archived_no_email + reason
        if ($data->etatAdministratif !== null && $data->etatAdministratif !== 'A') {
            $company->forceFill([
                'denomination'        => $data->denomination ?? $company->denomination,
                'prospection_status'  => 'archived_no_email',
                'archive_reason'      => 'entreprise_radiee',
            ])->save();
            $this->recordRun($company, 'insee', 'success');
            return 'archived';
        }

        $company->forceFill([
            'denomination'   => $data->denomination ?? $company->denomination,
            'naf'            => $data->naf ?? $company->naf,
            'legal_form'     => $data->legalForm ?? $company->legal_form,
            'effectif_range' => $data->effectifRange ?? $company->effectif_range,
        ])->save();
        $this->recordRun($company, 'insee', 'success');
        return 'ok';
    }

    private function step2_annuaire(Company $company): void
    {
        $data = $this->annuaire->fetchBySiren($company->siren);
        if (! $data) {
            return;
        }
        $signals = $company->signals ?: [];
        $signals['legal'] = [
            'ca'               => $data->chiffreAffaires,
            'resultat_net'     => $data->resultatNet,
            'bilans_last_year' => $data->bilansLastYear,
            'dirigeants'       => $data->representatives,
        ];
        $company->signals = $signals;
        $company->save();

        foreach ($data->representatives as $rep) {
            try {
                DB::table('contacts')->insertOrIgnore([[
                    'workspace_id'      => $company->workspace_id,
                    'company_id'        => $company->id,
                    'first_name'        => $rep['first_name'] ?? null,
                    'last_name'         => $rep['last_name'],
                    'role'              => $rep['role'] ?? 'dirigeant',
                    'discovery_source'  => 'annuaire-entreprises',
                    'sources'           => json_encode(['annuaire-entreprises']),
                    'metadata'          => json_encode($rep),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]]);
            } catch (\Throwable $e) {
                \Log::warning('contact insert failed', ['rep' => $rep, 'error' => $e->getMessage()]);
            }
        }
        $this->recordRun($company, 'annuaire-entreprises', 'success');
    }

    private function step3_bodacc(Company $company): void
    {
        $items = $this->bodacc->fetchAnnouncementsBySiren($company->siren);
        if (empty($items)) {
            return;
        }
        $signals = $company->signals ?: [];
        $signals['bodacc'] = array_slice(array_map(fn ($a) => $a->toArray(), $items), 0, 20);
        $signals['recent'] = $signals['recent'] ?? [];
        foreach ($items as $a) {
            $signals['recent'][] = ['type' => $a->type, 'at' => $a->publishedAt];
        }
        $company->signals = $signals;
        $company->save();
        $this->recordRun($company, 'bodacc', 'success');
    }

    private function step3b_find_domain(Company $company): void
    {
        if ($company->website) {
            return;  // déjà trouvé en amont
        }
        try {
            $url = $this->domainFinder->find($company);
            if ($url) {
                $company->website = $url;
                $company->save();
                $this->recordRun($company, 'domain-finder', 'success');
            }
        } catch (\Throwable $e) {
            WaterfallSentry::capture($company, 'domain-finder', $e);
            Log::warning('domain finder failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'domain-finder', 'failed');
        }
    }

    private function step3c_mentions_legales(Company $company): void
    {
        if (! $company->website) {
            return;
        }
        try {
            $found = $this->mentionsLegales->scrape($company);
            $this->recordRun($company, 'mentions-legales', $found ? 'success' : 'partial');
        } catch (\Throwable $e) {
            WaterfallSentry::capture($company, 'mentions-legales', $e);
            Log::warning('mentions-legales scrape failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'mentions-legales', 'failed');
        }
    }

    /**
     * Sprint H9 + H12 + H14 — Google Places API (server-side, intelligent).
     * Enrichit phone + website + address + lat/lon + horaires + note Google.
     *
     * Logique de skip (économiser le quota gratuit Google) :
     *  1. Déjà enrichi (signals.google_places.enriched_at présent) → skip
     *  2. Smart skip H14 : entreprise a DÉJÀ email+phone+website → skip
     *     (les données vitales sont là, pas besoin de gaspiller un crédit Places
     *     pour récupérer juste la note Google + horaires)
     *  3. Quota mensuel dépassé → marque pending pour retraitement mois suivant
     *
     * Flag de désactivation : services.google.places.smart_skip=false force
     * l'appel Places sur toutes les entreprises (utile pour les workspaces
     * qui veulent enrichir avec note Google + photos systématiquement).
     */
    private function step3d_google_places(Company $company): void
    {
        if ($this->googlePlaces === null || ! $company->denomination) {
            return;
        }
        // Skip si déjà enrichi
        $existingSignals = $company->signals ?? [];
        if (! empty($existingSignals['google_places']['enriched_at'])) {
            return;
        }
        // Sprint H14 — Smart skip : l'entreprise a-t-elle déjà toutes les données vitales ?
        if (config('services.google.places.smart_skip', true) && $this->hasEssentialData($company)) {
            $signals = $existingSignals;
            $signals['google_places_skipped'] = [
                'reason' => 'has_essential_data',
                'at'     => now()->toIso8601String(),
            ];
            unset($signals['google_places_pending']);
            $company->signals = $signals;
            $company->save();
            return;
        }
        try {
            $ville = $company->city_name ?? $company->city ?? '';
            $query = trim($company->denomination . ' ' . $ville);
            $reason = null;
            $place = $this->googlePlaces->searchText($query, 'FR', $reason);

            // Sprint H12 — Quota épuisé → on marque pending pour retraitement plus tard
            if ($place === null && $reason === 'quota_exceeded') {
                $signals = $company->signals ?: [];
                $signals['google_places_pending'] = [
                    'reason'     => 'monthly_quota_exceeded',
                    'queued_at'  => now()->toIso8601String(),
                ];
                $company->signals = $signals;
                $company->save();
                $this->recordRun($company, 'google-places', 'partial');
                return;
            }

            if ($place === null) {
                // 'not_found' / 'http_error' / 'no_api_key' / 'exception'
                // On marque "skipped" avec la raison pour traçabilité — pas de retry mensuel,
                // c'est sémantiquement différent du quota.
                if ($reason !== null && $reason !== 'no_api_key') {
                    $signals = $company->signals ?: [];
                    $signals['google_places_skipped'] = [
                        'reason' => $reason,
                        'at'     => now()->toIso8601String(),
                    ];
                    $company->signals = $signals;
                    $company->save();
                }
                return;
            }
            $data = $this->googlePlaces->flatten($place);

            // Backfill UNIQUEMENT les champs vides (on n'écrase pas l'existant)
            $touched = false;
            if (! $company->phone && $data['phone']) {
                $company->phone = $data['phone'];
                $touched = true;
            }
            if (! $company->website && $data['website']) {
                $company->website = $data['website'];
                $touched = true;
            }
            if (! $company->address && $data['address']) {
                $company->address = $data['address'];
                $touched = true;
            }
            if ($company->lat === null && $data['lat'] !== null) {
                $company->lat = $data['lat'];
                $company->lon = $data['lon'];
                $touched = true;
            }

            // Stocke le payload complet dans signals.google_places + timestamp d'enrichissement
            // (Sprint H12 : enriched_at sert au skip ré-enrichissement + traçabilité)
            $signals = $company->signals ?: [];
            $signals['google_places'] = [
                'place_id'         => $data['google_place_id'],
                'display_name'     => $data['display_name'],
                'rating'           => $data['rating'],
                'user_rating_count'=> $data['user_rating_count'],
                'business_status'  => $data['business_status'],
                'primary_type'     => $data['primary_type'],
                'types'            => $data['types'],
                'opening_hours'    => $data['opening_hours'],
                'enriched_at'      => now()->toIso8601String(),
            ];
            unset($signals['google_places_pending'], $signals['google_places_skipped']);
            $company->signals = $signals;

            if ($touched || ! empty($signals['google_places'])) {
                $company->save();
            }
            $this->recordRun($company, 'google-places', 'success');
        } catch (\Throwable $e) {
            WaterfallSentry::capture($company, 'google-places', $e);
            Log::warning('google-places failed', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
            $this->recordRun($company, 'google-places', 'failed');
        }
    }

    /**
     * Sprint H14 + H16 (2026-05-18) — Vérifie si l'entreprise a déjà l'essentiel
     * pour une campagne email : un email exploitable (contact valid|catchall|
     * unknown OU email_generic). Si oui, on n'a pas besoin de Google Places.
     *
     * Politique Will H16 : l'email est ROI. Phone/website/horaires/note Google
     * sont des bonus utiles mais pas critiques pour outreach email →
     * on économise drastiquement le quota Google Places en skippant dès qu'on
     * a au moins un canal de contact email.
     *
     * Conséquence : Google Places n'est appelé QUE pour les entreprises sans
     * email (typiquement celles dont MentionsLégales H10 n'a pas trouvé de
     * mail public sur les 18 URLs scrapées). Ces entreprises bénéficient
     * d'un dernier essai d'enrichissement Google qui peut leur apporter
     * un téléphone vérifié + un site web officiel manqué par Annuaire.
     */
    private function hasEssentialData(Company $company): bool
    {
        if (! empty($company->email_generic)) {
            return true;
        }
        $hasContact = \Illuminate\Support\Facades\DB::table('contacts')
            ->where('company_id', $company->id)
            ->whereIn('email_status', \App\Services\Triage\TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
            ->exists();
        return $hasContact;
    }

    private function step4_dispatch_node_scrapes(Company $company): void
    {
        if (env('MOCK_MODE', true) || env('MOCK_SCRAPERS', true)) {
            return;
        }
        $context = [
            'siren'        => $company->siren,
            'denomination' => $company->denomination,
            'naf'          => $company->naf,
        ];
        // Sprint H9 — google-maps retiré : remplacé par GooglePlaces API server-side (step3d).
        // Reste pour les workers Node : pages-jaunes (Webshare proxy requis si activé),
        // website scrape, google-search (fallback URL discovery).
        foreach (['pages-jaunes', 'website', 'google-search'] as $src) {
            \App\Jobs\DispatchScrapeJob::dispatch($company->id, $src, $context, $company->website);
        }
    }

    private function step7_email_finder(Company $company): void
    {
        $domain = $company->website ? parse_url($company->website, PHP_URL_HOST) : null;
        if (! $domain) {
            return;
        }
        $domain = preg_replace('/^www\./', '', (string) $domain);

        $contacts = DB::table('contacts')
            ->where('company_id', $company->id)
            ->where(function ($q) {
                $q->whereNull('email')->orWhereNotIn('email_status', ['valid', 'catchall']);
            })
            ->whereNotNull('last_name')
            ->limit(20)
            ->get();

        foreach ($contacts as $c) {
            try {
                $results = $this->emailFinder->find(
                    (string) ($c->first_name ?? ''),
                    (string) $c->last_name,
                    (string) $domain,
                );
                if (empty($results)) {
                    continue;
                }
                $best = $results[0];
                DB::table('contacts')->where('id', $c->id)->update([
                    'email'        => $best->email,
                    'email_status' => $best->status,
                    'email_score'  => $best->score,
                    'email_pattern'=> str_replace([$company->id . '@'], '@', $best->email),
                    'last_verified_at' => now(),
                    'updated_at'   => now(),
                ]);
            } catch (\Throwable $e) {
                WaterfallSentry::capture($company, 'email-finder', $e);
                Log::warning('email finder failed', ['contact_id' => $c->id, 'error' => $e->getMessage()]);
            }
        }
        $this->recordRun($company, 'email-finder', 'success');
    }

    private function step8_geocode(Company $company): void
    {
        if (! $company->address && ! $company->postcode) {
            return;
        }
        $result = $this->ban->geocode((string) $company->address, $company->postcode);
        if (! $result) {
            return;
        }
        $company->lat = $result->lat;
        $company->lon = $result->lon;
        $company->insee = $result->insee ?? $company->insee;

        // Backfill BAN signals pour AutoClassifier (step10b)
        $signals = $company->signals ?: [];
        $signals['ban'] = [
            'city'          => $result->city ?? null,
            'insee_commune' => $result->insee ?? null,
            'postcode'      => $result->postcode ?? null,
        ];
        $company->signals = $signals;

        DB::statement(
            'UPDATE companies SET geo_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
            [$result->lon, $result->lat, $company->id],
        );
        $company->save();
        $this->recordRun($company, 'ban', 'success');
    }

    private function step9_france_travail(Company $company): void
    {
        $this->recordRun($company, 'france-travail', 'success');
    }

    private function step10_classify(Company $company): void
    {
        try {
            $req = new LLMRequestData(
                useCaseSlug: 'classify_company_axion',
                variables: [
                    'denomination'    => $company->denomination,
                    'naf'             => $company->naf,
                    'effectif_range'  => $company->effectif_range,
                    'ext_website_text'=> '',
                ],
            );
            $resp = $this->llm->complete($req);
            $decoded = $resp->asJson();
            if ($decoded) {
                $company->priority = $decoded['priority'] ?? $company->priority;
                $signals = $company->signals ?: [];
                $signals['llm_classification'] = $decoded;
                $company->signals = $signals;
                $company->save();
            }
            DB::statement('SELECT recompute_company_quality_score(?)', [$company->id]);
            $this->recordRun($company, 'llm-classify', 'success');
        } catch (\Throwable $e) {
            WaterfallSentry::capture($company, 'llm-classify', $e);
            Log::warning('llm classify failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'llm-classify', 'failed');
        }
    }

    private function step10b_auto_classify(Company $company): void
    {
        try {
            $changed = $this->autoClassifier->classify($company);
            $this->recordRun($company, 'auto-classify', $changed ? 'success' : 'partial');
        } catch (\Throwable $e) {
            WaterfallSentry::capture($company, 'auto-classify', $e);
            Log::warning('auto-classify failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'auto-classify', 'failed');
        }
    }

    private function step10c_auto_tag(Company $company): void
    {
        try {
            $delta = $this->autoTagger->syncTags($company);
            Log::debug('auto-tag delta', ['company_id' => $company->id, 'delta' => $delta]);
            $this->recordRun($company, 'auto-tag', 'success');
        } catch (\Throwable $e) {
            WaterfallSentry::capture($company, 'auto-tag', $e);
            Log::warning('auto-tag failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'auto-tag', 'failed');
        }
    }

    private function step11_triage_auto(Company $company): void
    {
        try {
            $result = $this->triage->triage($company);
            Log::debug('triage result', ['company_id' => $company->id, 'result' => $result]);
            $this->recordRun($company, 'triage-auto', 'success');
        } catch (\Throwable $e) {
            WaterfallSentry::capture($company, 'triage-auto', $e);
            Log::warning('triage failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'triage-auto', 'failed');
        }
    }

    private function step12_auto_segment(Company $company): void
    {
        // Skip si company archivée (pas de point d'ajouter aux audiences)
        if ($company->prospection_status === 'archived_no_email') {
            return;
        }
        try {
            $audienceIds = $this->audienceBuilder->evaluateForCompany($company);
            foreach ($audienceIds as $audienceId) {
                // Sprint H8 — élargi contactable (valid|catchall|unknown).
                // Récupère contacts contactables + insère 1 row par contact
                // (ou company-only si aucun mais email_generic présent).
                $contactIds = DB::table('contacts')
                    ->where('company_id', $company->id)
                    ->whereIn('email_status', \App\Services\Triage\TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
                    ->pluck('id')
                    ->all();

                if (empty($contactIds)) {
                    AudienceMember::firstOrCreate([
                        'audience_id' => $audienceId,
                        'company_id'  => $company->id,
                        'contact_id'  => null,
                    ], [
                        'workspace_id' => $company->workspace_id,
                        'added_at'     => now(),
                    ]);
                } else {
                    foreach ($contactIds as $contactId) {
                        AudienceMember::firstOrCreate([
                            'audience_id' => $audienceId,
                            'company_id'  => $company->id,
                            'contact_id'  => $contactId,
                        ], [
                            'workspace_id' => $company->workspace_id,
                            'added_at'     => now(),
                        ]);
                    }
                }
                // Update member_count (approx — refresh complet via cron daily)
                DB::table('email_audiences')
                    ->where('id', $audienceId)
                    ->update([
                        'member_count' => DB::raw('(SELECT COUNT(*) FROM audience_members WHERE audience_id = email_audiences.id)'),
                        'updated_at'   => now(),
                    ]);
            }
            $this->recordRun($company, 'auto-segment', 'success');
        } catch (\Throwable $e) {
            WaterfallSentry::capture($company, 'auto-segment', $e);
            Log::warning('auto-segment failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'auto-segment', 'failed');
        }
    }

    /** Exécute une étape en isolant ses erreurs — ne casse JAMAIS le waterfall. */
    private function safe(callable $fn, string $step, Company $company): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("{$step} failed (skipped)", [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function recordRun(Company $company, string $source, string $status): void
    {
        try {
            DB::table('scraper_runs')->insert([
                'workspace_id' => $company->workspace_id,
                'company_id'   => $company->id,
                'source'       => $source,
                'status'       => $status,
                'started_at'   => now(),
                'finished_at'  => now(),
                'dedup_key'    => $this->dedup->buildDedupKey($source, ['siren' => $company->siren, 't' => time()]),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('scraper_runs insert failed', ['source' => $source, 'error' => $e->getMessage()]);
        }
    }
}
