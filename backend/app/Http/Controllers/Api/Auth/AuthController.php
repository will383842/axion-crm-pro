<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function login(Request $request): JsonResponse { return $this->notImplemented('3'); }
    public function logout(Request $request): JsonResponse { return $this->notImplemented('3'); }
    public function me(Request $request): JsonResponse { return $this->ok(['user' => $request->user()]); }
}
