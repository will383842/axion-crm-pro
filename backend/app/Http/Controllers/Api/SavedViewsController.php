<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedViewsController extends ApiController
{
    /**
     * @OA\Get(path="/saved-views", tags={"SavedViews"}, summary="Liste vues sauvegardées (filtres companies/contacts)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    /**
     * @OA\Post(path="/saved-views", tags={"SavedViews"}, summary="Crée vue (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function store(Request $r): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Get(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Show vue (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function show(int $savedView): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Put(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Update vue (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, int $savedView): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Delete(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Delete vue (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function destroy(int $savedView): JsonResponse { return $this->notImplemented('10'); }
}
