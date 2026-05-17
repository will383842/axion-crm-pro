<?php

namespace App\Http\Controllers\Api;

use App\Models\LlmUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlmUseCasesController extends ApiController
{
    /**
     * @OA\Get(path="/llm/use-cases", tags={"LLM"}, summary="Liste des 9 use cases LLM (router)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

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
