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

    /**
     * Test du matin : fermé au palier 0 (D4). Aucun message ne part, rien n'est écrit, aucune lecture :
     * `ServeurEnvoi` n'est jamais résolu. Le stop gagne : ni envoi, ni levée de pause ou de frein.
     */
    public function test(): JsonResponse
    {
        return response()->json(['error' => 'envoi_ferme_palier_0', 'message' => 'Les envois sont fermés au palier 0 : le test du matin ne part pas et rien n’a changé.'], 409);
    }
}
