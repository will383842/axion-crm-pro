<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends ApiController
{
    /**
     * @OA\Get(path="/search", tags={"Workspace"}, summary="Recherche globale ⌘K (companies + contacts + tags)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="q", in="query", required=true, @OA\Schema(type="string", minLength=2)),
     *     @OA\Response(response=200, description="Résultats groupés"))
     */
    public function index(Request $r): JsonResponse
    {
        return $this->ok(['companies' => [], 'contacts' => [], 'tags' => []]);
    }
}
