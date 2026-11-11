<?php

namespace App\Http\Controllers\Api;

use App\Envoi\Conformite\PuisJeEcrire;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** GET /envoi/puis-je-ecrire?adresse= (lot R05). Lecture seule ; ne rend que `peut` et un motif regroupé. */
class EnvoiPuisJeEcrireController extends ApiController
{
    public function __invoke(Request $request, PuisJeEcrire $service): JsonResponse
    {
        $v = $request->validate(['adresse' => ['required', 'string', 'max:254', 'email:filter']]);

        return response()->json($service->repondre((string) WorkspaceContext::current(), $v['adresse'], Carbon::now()));
    }
}
