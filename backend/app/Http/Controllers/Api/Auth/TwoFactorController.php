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

    /**
     * @OA\Post(path="/auth/2fa/setup", tags={"Auth"}, summary="Initie l'enrolment TOTP (QR code + secret)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="QR + secret retournés"),
     *     @OA\Response(response=401, description="Unauthenticated"))
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        return $this->ok($this->service->startEnrolment($user));
    }

    /**
     * @OA\Post(path="/auth/2fa/confirm", tags={"Auth"}, summary="Confirme l'enrolment TOTP (active 2FA + génère recovery codes)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"code"},
     *         @OA\Property(property="code", type="string", maxLength=6))),
     *     @OA\Response(response=200, description="2FA activé + recovery_codes"))
     */
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

    /**
     * @OA\Post(path="/auth/2fa/verify", tags={"Auth"}, summary="Vérifie un code TOTP au login",
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"code"},
     *         @OA\Property(property="code", type="string"))),
     *     @OA\Response(response=200, description="Vérifié"),
     *     @OA\Response(response=422, description="Code invalide"))
     */
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
