<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiActRegisterController extends ApiController
{
    /**
     * @OA\Get(path="/ai-act-register", tags={"RGPD"}, summary="Registre AI Act (art. 9-15) — systèmes IA déployés",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="Liste des entrées"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    /**
     * @OA\Post(path="/ai-act-register", tags={"RGPD"}, summary="Crée une entrée AI Act (Sprint 11)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function store(Request $r): JsonResponse { return $this->notImplemented('11'); }
}
