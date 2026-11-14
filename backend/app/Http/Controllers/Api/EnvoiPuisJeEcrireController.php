<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Conformite\PuisJeEcrire;
use App\Http\Middleware\AuditHashChainLogger;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * POST /envoi/puis-je-ecrire, adresse dans le corps JSON (lot R05). LECTURE SEULE : le POST sert uniquement à garder
 * l'adresse hors de l'URL ; rien n'est écrit (pas même une ligne d'audit). Ne rend que `peut` et un motif regroupé.
 */
class EnvoiPuisJeEcrireController extends ApiController
{
    public function __invoke(Request $request, PuisJeEcrire $service): JsonResponse
    {
        // Lecture seule : le journal d'audit générique (qui trace tout POST) ne doit rien écrire ici.
        $request->attributes->set(AuditHashChainLogger::ATTRIBUT_JOURNAL_PROPRE, true);
        $v = $request->validate(['adresse' => ['required', 'string', 'max:254', 'email:filter']]);

        return response()->json($service->repondre((string) WorkspaceContext::current(), $v['adresse'], Carbon::now()));
    }
}
