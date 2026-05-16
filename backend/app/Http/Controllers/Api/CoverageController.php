<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoverageController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['cells' => []]); }
    public function nextZone(Request $r): JsonResponse { return $this->notImplemented('9'); }
    public function launch(Request $r): JsonResponse { return $this->notImplemented('9'); }
    public function showCell(int $cell): JsonResponse { return $this->notImplemented('9'); }
}
