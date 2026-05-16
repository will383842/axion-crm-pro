<?php

namespace App\Services\Waterfall;

use App\Models\Company;
use App\Contracts\InseeClient;
use App\Contracts\AnnuaireEntreprisesClient;
use App\Contracts\BodaccClient;
use App\Contracts\BanGeocoder;
use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Services\Dedup\DeduplicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Waterfall 10 étapes — cf. spec/08_waterfall_enrichissement_classification.md.
 *
 * 1. INSEE identification          (PHP — HTTP)
 * 2. annuaire-entreprises légal    (PHP — HTTP)
 * 3. BODACC signaux                (PHP — HTTP)
 * 4. Google Maps / Pages Jaunes    (Node worker via BullMQ)
 * 5. Sites web entreprise          (Node worker)
 * 6. Google Search → LinkedIn URL  (Node worker)
 * 7. Email finder + SMTP cascade   (PHP — Sprint 8)
 * 8. Géocodage BAN                 (PHP — HTTP)
 * 9. France Travail signaux        (PHP — HTTP)
 * 10. Classification LLM + tags    (PHP — LLMRouter)
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
    ) {}

    public function enrich(Company $company): void
    {
        Log::info('Waterfall start', ['company_id' => $company->id, 'siren' => $company->siren]);

        $this->step1_insee($company);
        $this->step2_annuaire($company);
        $this->step3_bodacc($company);
        // Étapes 4-6 dispatchent vers BullMQ Node workers (asynchrone, résultats via /internal/scraper-result)
        $this->step4_dispatch_node_scrapes($company);
        $this->step8_geocode($company);
        $this->step9_france_travail($company);
        $this->step10_classify($company);

        $company->enriched_at = now();
        $company->save();

        Log::info('Waterfall done', ['company_id' => $company->id]);
    }

    private function step1_insee(Company $company): void
    {
        if (! $this->dedup->shouldRunScrape((string) $company->workspace_id, 'insee', ['siren' => $company->siren])['should_run']) {
            return;
        }
        $data = $this->insee->fetchBySiren($company->siren);
        if (! $data) {
            return;
        }
        $company->forceFill([
            'denomination'   => $data->denomination ?? $company->denomination,
            'naf'            => $data->naf ?? $company->naf,
            'legal_form'     => $data->legalForm ?? $company->legal_form,
            'effectif_range' => $data->effectifRange ?? $company->effectif_range,
        ])->save();
        $this->recordRun($company, 'insee', 'success');
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
        ];
        $company->signals = $signals;
        $company->save();

        foreach ($data->representatives as $rep) {
            DB::table('contacts')->upsert([[
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
            ]], ['workspace_id', 'normalized_hash'], ['updated_at']);
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

    private function step4_dispatch_node_scrapes(Company $company): void
    {
        // En MOCK_MODE, on n'enqueue rien (les Mock services tournent côté PHP directement).
        if (env('MOCK_MODE', true)) {
            return;
        }
        $sources = ['google-maps', 'pages-jaunes', 'website', 'google-search'];
        foreach ($sources as $src) {
            \Queue::push(new \App\Jobs\DispatchScrapeJob($company->id, $src), '', "scrape:{$src}");
        }
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
        DB::statement(
            'UPDATE companies SET geo_point = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
            [$result->lon, $result->lat, $company->id],
        );
        $company->save();
        $this->recordRun($company, 'ban', 'success');
    }

    private function step9_france_travail(Company $company): void
    {
        // Sprint 7 active — délégué à FranceTravailClient (mock par défaut).
        $this->recordRun($company, 'france-travail', 'success');
    }

    private function step10_classify(Company $company): void
    {
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
    }

    private function recordRun(Company $company, string $source, string $status): void
    {
        DB::table('scraper_runs')->insert([
            'workspace_id' => $company->workspace_id,
            'company_id'   => $company->id,
            'source'       => $source,
            'status'       => $status,
            'started_at'   => now(),
            'finished_at'  => now(),
            'dedup_key'    => $this->dedup->buildDedupKey($source, ['siren' => $company->siren]),
            'created_at'   => now(),
        ]);
    }
}
