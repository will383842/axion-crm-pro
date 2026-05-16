<?php

namespace App\Http\Controllers\Api;

use App\Models\RgpdRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RgpdRequestsController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function store(Request $r): JsonResponse { return $this->notImplemented('11'); }
    public function process(Request $r, RgpdRequest $req): JsonResponse { return $this->notImplemented('11'); }
    public function export(string $token): JsonResponse { return $this->notImplemented('11'); }
}
