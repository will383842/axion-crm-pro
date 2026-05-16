<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LlmUsageController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function summary(Request $r): JsonResponse { return $this->ok(['summary' => ['total_eur' => 0]]); }
}
