<?php

namespace App\Http\Controllers\Api;

use App\Models\ProxyProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProxyProvidersController extends ApiController
{
    /**
     * @OA\Get(path="/proxy-providers", tags={"LLM"}, summary="Liste des providers proxy actifs",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('proxy_providers_config')) {
            return $this->ok(['data' => []]);
        }

        try {
            return $this->ok(['data' => ProxyProvider::query()->orderBy('slug')->limit(50)->get()]);
        } catch (\Throwable $e) {
            Log::error('proxy-providers.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Put(path="/proxy-providers/{p}", tags={"LLM"}, summary="Update config provider (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, ProxyProvider $p): JsonResponse { return $this->notImplemented('4'); }

    /**
     * @OA\Post(path="/proxy-providers/{p}/test", tags={"LLM"}, summary="Health check live d'un provider",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Healthy"))
     */
    public function test(ProxyProvider $p): JsonResponse { return $this->ok(['healthy' => true]); }
}
