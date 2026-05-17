<?php

namespace App\Http\Controllers\Api;

use App\Models\ScraperRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScraperRunsController extends ApiController
{
    /**
     * @OA\Get(path="/scraper-runs", tags={"Scraping"}, summary="Historique scraper runs",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => [], 'meta' => ['total' => 0]]); }

    /**
     * @OA\Get(path="/scraper-runs/{run}", tags={"Scraping"}, summary="Détail d'un run",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="run", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"))
     */
    public function show(ScraperRun $run): JsonResponse { return $this->ok($run); }

    /**
     * @OA\Post(path="/scraper-runs/{run}/cancel", tags={"Scraping"}, summary="Annule un run en cours (Sprint 6)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="run", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function cancel(ScraperRun $run): JsonResponse { return $this->notImplemented('6'); }

    /**
     * @OA\Post(path="/scraper-runs/{run}/retry", tags={"Scraping"}, summary="Relance un run échoué (Sprint 6)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="run", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function retry(ScraperRun $run): JsonResponse { return $this->notImplemented('6'); }
}
