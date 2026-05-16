<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends ApiController
{
    public function verify(Request $r): JsonResponse { return $this->notImplemented('3'); }
    public function setup(Request $r): JsonResponse { return $this->notImplemented('3'); }
    public function confirm(Request $r): JsonResponse { return $this->notImplemented('3'); }
}
