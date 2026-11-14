<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Sante\VueSante;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;

/** Écran « Santé des envois » : forme du contrat `openapi/envoi/pilotage.yaml`, espace courant seulement. Lecture seule. */
class EnvoiSanteController extends ApiController
{
    public function index(VueSante $vue): JsonResponse
    {
        return response()->json($vue->pour((string) WorkspaceContext::current(), now()->toDateTimeImmutable()));
    }
}
