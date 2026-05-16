<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MagicLinkController extends ApiController
{
    public function request(Request $r): JsonResponse { return $this->notImplemented('3'); }
    public function verify(Request $r): JsonResponse { return $this->notImplemented('3'); }
}
