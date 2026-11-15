<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Exceptions\CampagneRefusee;
use App\Envoi\Sequence\DepotSequences;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Séquences (contrat backend/openapi/envoi/sequences.yaml). Lecture et pause : `users.manage` ; reprise : `workspaces.manage`.
 * Compteurs seulement (aucun corps de message, aucune personne). Aucune route ne résout ServeurEnvoi et aucune n'envoie.
 * Le nom de la méthode de reprise est `reprise` : `reprendre` est interdit dans les routes d'envoi.
 */
class EnvoiSequencesController extends ApiController
{
    public function index(DepotSequences $depot): JsonResponse
    {
        return response()->json(['sequences' => $depot->lister($this->espace())]);
    }

    public function show(string $id, DepotSequences $depot): JsonResponse
    {
        return $this->reponse($depot->lire($this->espace(), $id));
    }

    public function pause(Request $request, string $id, DepotSequences $depot): JsonResponse
    {
        return $this->reponse($depot->geste($this->espace(), $id, 'pause', (string) $request->user()?->id));
    }

    public function reprise(Request $request, string $id, DepotSequences $depot): JsonResponse
    {
        try {
            return $this->reponse($depot->geste($this->espace(), $id, 'reprise', (string) $request->user()?->id));
        } catch (CampagneRefusee $e) {
            return response()->json(['error' => $e->codeErreur, 'message' => $e->getMessage()], $e->getCode());
        }
    }

    /** @param  array<string, mixed>|null  $fiche */
    private function reponse(?array $fiche): JsonResponse
    {
        return $fiche === null
            ? response()->json(['error' => 'introuvable', 'message' => 'Séquence introuvable.'], 404)
            : response()->json($fiche);
    }

    private function espace(): string
    {
        return (string) WorkspaceContext::current();
    }
}
