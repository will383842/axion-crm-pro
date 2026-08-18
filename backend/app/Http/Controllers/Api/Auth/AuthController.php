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

    /**
     * @OA\Post(path="/auth/login", tags={"Auth"}, summary="Login Sanctum SPA (email + password)",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"email","password"},
     *
     *         @OA\Property(property="email", type="string", format="email"),
     *         @OA\Property(property="password", type="string", minLength=12),
     *         @OA\Property(property="remember", type="boolean", default=false))),
     *
     *     @OA\Response(response=200, description="Loggé (vérifier requires_2fa)"),
     *     @OA\Response(response=422, description="Credentials invalides ou locked"),
     *     @OA\Response(response=419, description="Requête non stateful (pas de session)"),
     *     @OA\Response(response=429, description="Throttle"))
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // 🔴 Une requête SANS session ne peut pas ouvrir de session.
        //
        // La connexion établit une session stateful (cookie Sanctum) et
        // régénère l'identifiant de session — c'est la protection contre la
        // FIXATION de session, elle reste inconditionnelle. Mais un client qui
        // n'envoie pas d'`Origin`/`Referer` de domaine stateful (curl, Postman,
        // client mobile, ou `SANCTUM_STATEFUL_DOMAINS` mal réglé) ne traverse
        // pas `StartSession` : la régénération explosait alors en 500, avec une
        // trace complète en journal.
        //
        // Un 500 n'est pas un contrat : il dit « le serveur est cassé » là où
        // la vérité est « cette requête ne peut pas aboutir ainsi ». On répond
        // donc explicitement, sans rien affaiblir de la protection.
        if (! $request->hasSession()) {
            return response()->json([
                'error' => 'session_requise',
                'message' => "Cette route ouvre une session : la requête doit provenir d'un domaine stateful (en-tête Origin ou Referer). Pour un accès machine, utilisez un jeton d'API.",
            ], 419);
        }

        $result = $this->auth->attemptLogin(
            $request,
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (bool) $request->validated('remember', false),
        );

        return $this->ok([
            'user' => $result['user']->only(['id', 'email', 'name', 'current_workspace_id', 'totp_enabled_at', 'first_login_completed_at', 'onboarding_tour_completed_at']),
            'requires_2fa' => $result['requires_2fa'],
        ]);
    }

    /**
     * @OA\Post(path="/auth/logout", tags={"Auth"}, summary="Logout (invalide session)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=204, description="Déconnecté"))
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return response()->json(['ok' => true], 204);
    }

    /**
     * @OA\Get(path="/auth/me", tags={"Auth"}, summary="User courant + rôles",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=401, description="Unauthenticated"))
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return $this->ok([
            'user' => $user->only(['id', 'email', 'name', 'locale', 'timezone', 'current_workspace_id', 'totp_enabled_at', 'first_login_completed_at', 'onboarding_tour_completed_at']),
            'roles' => $user->getRoleNames(),
        ]);
    }

    /**
     * Sprint 18.4 — marque le tour d'onboarding comme vu.
     * Endpoint sécurité-sensible mais idempotent.
     */
    /**
     * @OA\Post(path="/auth/onboarding/complete", tags={"Auth"}, summary="Marque l'onboarding tour comme vu (idempotent)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=401, description="Unauthenticated"))
     */
    public function completeOnboardingTour(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        if ($user->onboarding_tour_completed_at === null) {
            $user->onboarding_tour_completed_at = now();
            $user->save();
        }

        return $this->ok([
            // `->` et non `?->` : la branche ci-dessus vient de garantir la
            // valeur non nulle. Le `?->` était toléré tant que la propriété
            // n'était pas déclarée sur le modèle ; elle l'est désormais.
            'onboarding_tour_completed_at' => $user->onboarding_tour_completed_at->toIso8601String(),
        ]);
    }
}
