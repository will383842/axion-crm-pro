<?php

namespace App\Http\Controllers\Api;

use App\Models\ProxyProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProxyProvidersController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function update(Request $r, ProxyProvider $p): JsonResponse { return $this->notImplemented('4'); }
    public function test(ProxyProvider $p): JsonResponse { return $this->ok(['healthy' => true]); }
}
