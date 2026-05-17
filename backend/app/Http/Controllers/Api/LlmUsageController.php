<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlmUsageController extends ApiController
{
    /**
     * @OA\Get(path="/llm/usage", tags={"LLM"}, summary="Historique d'usage LLM (tokens + coût €)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="Liste paginée"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    /**
     * @OA\Get(path="/llm/usage/summary", tags={"LLM"}, summary="Résumé coûts LLM (par use-case, par provider, par jour)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="Agrégations"))
     */
    public function summary(Request $r): JsonResponse { return $this->ok(['summary' => ['total_eur' => 0]]); }
}
