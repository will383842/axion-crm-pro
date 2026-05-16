<?php

namespace App\Http\Controllers\Api\Phase2;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends ApiController
{
    public function __invoke(Request $r): JsonResponse
    {
        return $this->notImplemented('Phase 2');
    }
}
