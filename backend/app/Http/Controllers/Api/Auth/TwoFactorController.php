<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends ApiController
{
    public function __construct(private readonly TwoFactorService $service) {}

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        return $this->ok($this->service->startEnrolment($user));
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        $recoveryCodes = $this->service->confirmEnrolment($user, (string) $request->input('code'));
        return $this->ok(['recovery_codes' => $recoveryCodes]);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        if (! $this->service->verify($user, (string) $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'Code invalide.']);
        }

        $request->session()->put('2fa_passed_at', now()->toIso8601String());
        return $this->ok(['verified' => true]);
    }
}
