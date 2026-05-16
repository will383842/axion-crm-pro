<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedViewsController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function store(Request $r): JsonResponse { return $this->notImplemented('10'); }
    public function show(int $savedView): JsonResponse { return $this->notImplemented('10'); }
    public function update(Request $r, int $savedView): JsonResponse { return $this->notImplemented('10'); }
    public function destroy(int $savedView): JsonResponse { return $this->notImplemented('10'); }
}
