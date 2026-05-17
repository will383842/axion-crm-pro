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
use App\Services\Tags\AutoTaggerService;
use App\Services\Triage\TriageAutoService;
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
    ) {}

    public function enrich(Company $company): void
    {
        Log::info('Waterfall start', ['company_id' => $company->id, 'siren' => $company->siren]);

        // Sprint H3 — Si étape INSEE détecte entreprise radiée → archive + SKIP tout le waterfall
        // (économie ressources, plus de Brave/Hunter/etc gaspillés)
        if ($this->step1_insee($company) === 'archived') {
            $company->enriched_at = now();
            $company->save();
            Log::info('Waterfall short-circuit (entreprise radiée)', [
                'company_id' => $company->id, 'siren' => $company->siren,
            ]);
            return;
        }

        $this->step2_annuaire($company);
        $this->step3_bodacc($company);
        $this->step3b_find_domain($company);
        $this->step3c_mentions_legales($company);
        $this->step4_dispatch_node_scrapes($company);
        $this->step7_email_finder($company);
        $this->step8_geocode($company);
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
            Log::warning('mentions-legales scrape failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'mentions-legales', 'failed');
        }
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
        foreach (['google-maps', 'pages-jaunes', 'website', 'google-search'] as $src) {
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
                // Récupère contacts valid + insère 1 row par contact (ou company-only si aucun)
                $contactIds = DB::table('contacts')
                    ->where('company_id', $company->id)
                    ->where('email_status', 'valid')
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
            Log::warning('auto-segment failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);
            $this->recordRun($company, 'auto-segment', 'failed');
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
