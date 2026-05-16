<?php

namespace App\Http\Controllers\Api;

use App\Models\ScraperRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScraperRunsController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => [], 'meta' => ['total' => 0]]); }
    public function show(ScraperRun $run): JsonResponse { return $this->ok($run); }
    public function cancel(ScraperRun $run): JsonResponse { return $this->notImplemented('6'); }
    public function retry(ScraperRun $run): JsonResponse { return $this->notImplemented('6'); }
}
