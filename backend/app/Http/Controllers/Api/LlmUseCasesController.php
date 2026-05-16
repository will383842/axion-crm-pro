<?php

namespace App\Http\Controllers\Api;

use App\Models\LlmUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlmUseCasesController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function update(Request $r, LlmUseCase $useCase): JsonResponse { return $this->notImplemented('4'); }
    public function prompts(LlmUseCase $useCase): JsonResponse { return $this->ok(['versions' => []]); }
    public function updatePrompt(Request $r, LlmUseCase $useCase, int $v): JsonResponse { return $this->notImplemented('4'); }
}
