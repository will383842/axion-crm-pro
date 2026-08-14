<?php

namespace App\Http\Controllers\Internal;

use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestRejection;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endpoint interne signé HMAC sha256, appelé par les workers Node après scraping.
 *
 * HISTOIRE (lot L3) : depuis sa création (« Sprint 6 — DeduplicationService +
 * waterfall.advance() + écriture scraper_runs »), ce contrôleur ne faisait que
 * LOGGER — le chaînon manquant constaté par l'audit scraping §A.1 : les workers
 * Node envoyaient, l'API répondait « ingested: true », et RIEN n'était ingéré.
 * Un job vert qui ne relaie rien est le pire des états (leçon IndexNow).
 *
 * Depuis L3, il est branché sur le FUNNEL D'INGESTION UNIQUE — derrière le
 * drapeau `CRM_SCRAPE_FUNNEL_ENABLED` :
 *   - OFF (défaut) : comportement HISTORIQUE inchangé (log-only, 200) — le lot
 *     est fusionnable sans activer quoi que ce soit ;
 *   - ON : validation du pivot `ScrapedRecord` (422 si invalide — le producteur
 *     saura ENFIN que son message est mauvais), puis ingestion complète.
 */
class ScraperResultController extends ApiController
{
    public function __construct(private readonly ScrapedRecordIngestService $ingest) {}

    public function store(Request $r): JsonResponse
    {
        $sig = $r->header('X-Worker-Signature');
        $secret = (string) env('WORKER_INTERNAL_HMAC_SECRET', '');
        $body = $r->getContent();
        $expected = hash_hmac('sha256', $body, $secret);

        if ($sig === null || ! hash_equals($expected, $sig)) {
            Log::warning('Internal scraper result rejected (bad HMAC)', ['ip' => $r->ip()]);

            return response()->json(['error' => 'bad_signature'], 401);
        }

        $payload = $r->json()->all();

        if (! filter_var(config('crm.scrape_funnel.enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            // Comportement historique, à l'identique : log et 200.
            Log::info('ScraperResult ingested', [
                'run_id' => $payload['run_id'] ?? null,
                'source' => $payload['source'] ?? null,
                'status' => $payload['status'] ?? null,
            ]);

            return $this->ok(['ingested' => true]);
        }

        try {
            $record = ScrapedRecord::fromArray($payload);
            $outcome = $this->ingest->ingest($record);
        } catch (ScrapeIngestRejection $rejection) {
            Log::warning('scraper-result refusé', [
                'code' => $rejection->errorCode,
                'message' => $rejection->getMessage(),
            ]);

            return response()->json([
                'error' => $rejection->errorCode,
                'message' => $rejection->getMessage(),
                'details' => $rejection->details,
            ], $rejection->status);
        } catch (Throwable $e) {
            Log::error('scraper-result en erreur', ['exception' => $e->getMessage()]);

            return response()->json(['error' => 'ingest_failed'], 500);
        }

        return $this->ok(['ingested' => true, 'result' => $outcome->toArray()]);
    }
}
