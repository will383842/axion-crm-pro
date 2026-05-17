<?php

namespace App\Http\Controllers\Api\Phase2;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ColdEmailController extends ApiController
{
    /**
     * @OA\Get(path="/phase2/cold-email", tags={"Phase 2"}, summary="Cold email (Phase 2 — stub 501)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function __invoke(Request $r): JsonResponse
    {
        return $this->notImplemented('Phase 2');
    }
}
