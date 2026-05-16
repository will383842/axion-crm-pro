<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiActRegisterController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function store(Request $r): JsonResponse { return $this->notImplemented('11'); }
}
