<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController extends ApiController
{
    public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
    public function markRead(int $n): JsonResponse { return $this->notImplemented('10'); }
    public function markAllRead(): JsonResponse { return $this->notImplemented('10'); }
}
