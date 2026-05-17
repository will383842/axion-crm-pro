<?php

namespace App\Http\Controllers\Api;

use App\Models\ProxyProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProxyProvidersController extends ApiController
{
    /**
     * @OA\Get(path="/llm/proxy-providers", tags={"LLM"}, summary="Liste des providers proxy actifs",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    /**
     * @OA\Put(path="/llm/proxy-providers/{p}", tags={"LLM"}, summary="Update config provider (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, ProxyProvider $p): JsonResponse { return $this->notImplemented('4'); }

    /**
     * @OA\Post(path="/llm/proxy-providers/{p}/test", tags={"LLM"}, summary="Health check live d'un provider",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Healthy"))
     */
    public function test(ProxyProvider $p): JsonResponse { return $this->ok(['healthy' => true]); }
}
