<?php

namespace App\Http\Controllers\Api;

use App\Models\LlmUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LlmUseCasesController extends ApiController
{
    /**
     * @OA\Get(path="/llm/use-cases", tags={"LLM"}, summary="Liste des 9 use cases LLM (router)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('llm_use_cases')) {
            return $this->ok(['data' => []]);
        }

        try {
            return $this->ok(['data' => LlmUseCase::query()->orderBy('slug')->limit(50)->get()]);
        } catch (\Throwable $e) {
            Log::error('llm.use-cases.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Put(path="/llm/use-cases/{useCase}", tags={"LLM"}, summary="Update config use case (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="useCase", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, LlmUseCase $useCase): JsonResponse { return $this->notImplemented('4'); }

    /**
     * @OA\Get(path="/llm/use-cases/{useCase}/prompts", tags={"LLM"}, summary="Versions de prompt pour un use case",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="useCase", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"))
     */
    public function prompts(LlmUseCase $useCase): JsonResponse { return $this->ok(['versions' => []]); }

    /**
     * @OA\Put(path="/llm/use-cases/{useCase}/prompts/{v}", tags={"LLM"}, summary="Update version prompt (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="useCase", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="v", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function updatePrompt(Request $r, LlmUseCase $useCase, int $v): JsonResponse { return $this->notImplemented('4'); }
}
