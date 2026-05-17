<?php

namespace App\Http\Controllers\Api\Phase2;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends ApiController
{
    /**
     * @OA\Get(path="/analytics", tags={"Phase 2"}, summary="Analytics (Phase 2 — stub 501)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function __invoke(Request $r): JsonResponse
    {
        return $this->notImplemented('Phase 2');
    }
}
