<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->attemptLogin(
            $request,
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (bool) $request->validated('remember', false),
        );

        return $this->ok([
            'user'         => $result['user']->only(['id', 'email', 'name', 'current_workspace_id', 'totp_enabled_at', 'first_login_completed_at']),
            'requires_2fa' => $result['requires_2fa'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);
        return response()->json(['ok' => true], 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        return $this->ok([
            'user'  => $user->only(['id', 'email', 'name', 'locale', 'timezone', 'current_workspace_id', 'totp_enabled_at', 'first_login_completed_at']),
            'roles' => $user->getRoleNames(),
        ]);
    }
}
