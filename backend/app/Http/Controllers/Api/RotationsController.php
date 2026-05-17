<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RotationsController extends ApiController
{
    /**
     * @OA\Get(path="/rotations", tags={"Rotations"}, summary="Liste les rotations LLM (round-robin / cost-based)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }

    /**
     * @OA\Put(path="/rotations/{rotation}", tags={"Rotations"}, summary="Update rotation (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="rotation", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, int $rotation): JsonResponse { return $this->notImplemented('4'); }
}
