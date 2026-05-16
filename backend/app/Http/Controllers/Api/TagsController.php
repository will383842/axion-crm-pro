<?php

namespace App\Http\Controllers\Api;

use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagsController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function store(Request $r): JsonResponse { return $this->notImplemented('10'); }
    public function update(Request $r, Tag $tag): JsonResponse { return $this->notImplemented('10'); }
    public function destroy(Tag $tag): JsonResponse { return $this->notImplemented('10'); }
}
