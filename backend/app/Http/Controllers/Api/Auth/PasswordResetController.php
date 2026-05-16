<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetController extends ApiController
{
    public function forgot(Request $r): JsonResponse { return $this->notImplemented('3'); }
    public function reset(Request $r): JsonResponse { return $this->notImplemented('3'); }
}
