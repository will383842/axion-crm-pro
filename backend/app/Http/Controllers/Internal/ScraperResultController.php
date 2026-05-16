<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint interne signé HMAC sha256, appelé par les workers Node après scraping.
 * Vérifie la signature, ingère le ScrapeResult, met à jour `scraper_runs` + déclenche
 * la prochaine étape du waterfall si nécessaire.
 */
class ScraperResultController extends ApiController
{
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
        // Sprint 6 — DeduplicationService + waterfall.advance() + écriture scraper_runs.
        Log::info('ScraperResult ingested', [
            'run_id' => $payload['run_id'] ?? null,
            'source' => $payload['source'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);

        return $this->ok(['ingested' => true]);
    }
}
