<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Rules\NotPwnedPassword;
use App\Services\Audit\AuditHashChain;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * « Mon compte → Changer mon mot de passe », pour une personne DÉJÀ connectée.
 *
 * 🔴 CONSTAT DU 2026-10-02 — il n'existait AUCUN moyen de changer son mot de
 * passe une fois connecté. Le propriétaire, seul utilisateur, ne parvenait plus à
 * entrer par mot de passe (le gestionnaire du navigateur pré-remplissait
 * l'ancien) ; il entrait par lien magique, et la seule issue pour reprendre la
 * main sur son mot de passe était de ressortir pour une réinitialisation.
 *
 * ── L'ANCIEN MOT DE PASSE EST EXIGÉ, SAUF UNE EXCEPTION ────────────────────
 * Une session ouverte par LIEN MAGIQUE il y a moins de 30 minutes dispense de
 * le donner : la possession de la boîte aux lettres prouve exactement ce que
 * prouve le lien de réinitialisation, qui permet déjà de choisir un nouveau mot
 * de passe sans l'ancien. Passé ce délai, une session restée ouverte sur un
 * poste laissé sans surveillance ne doit pas suffire à prendre le compte.
 *
 * ── MÊMES EFFETS QUE `PasswordResetController::reset()` ────────────────────
 * Même hachage (`Hash::make`), compteurs d'échecs remis à zéro, jetons d'API
 * révoqués, AUTRES sessions coupées. La session courante, elle, reste ouverte :
 * couper la personne qui vient de prouver qui elle est n'apprend rien à personne.
 */
class PasswordChangeController extends ApiController
{
    /** Clé de session posée par `MagicLinkController::verify()`. */
    public const SESSION_PAR_LIEN = 'auth_recente_par_lien';

    /** Horodatage (secondes Unix) de cette ouverture par lien. */
    public const SESSION_PAR_LIEN_A = 'auth_recente_par_lien_a';

    /** Durée pendant laquelle la session par lien dispense de l'ancien mot de passe. */
    public const FENETRE_MINUTES = 30;

    /**
     * Pose le marqueur « session ouverte par lien » sur la session courante.
     * Appelé par `MagicLinkController::verify()` APRÈS la régénération.
     */
    public static function marquerSessionParLien(Request $request): void
    {
        $request->session()->put([
            self::SESSION_PAR_LIEN => true,
            self::SESSION_PAR_LIEN_A => now()->getTimestamp(),
        ]);
    }

    /** Minutes restantes de dispense, ou `null` si l'ancien mot de passe est exigé. */
    public static function minutesRestantesParLien(Request $request): ?int
    {
        if (! $request->hasSession() || $request->session()->get(self::SESSION_PAR_LIEN) !== true) {
            return null;
        }

        $a = $request->session()->get(self::SESSION_PAR_LIEN_A);
        if (! is_int($a)) {
            return null;
        }

        $restant = ($a + self::FENETRE_MINUTES * 60) - now()->getTimestamp();

        return $restant > 0 ? (int) ceil($restant / 60) : null;
    }

    /**
     * @OA\Get(path="/auth/password/change", tags={"Auth"}, summary="L'ancien mot de passe est-il exigé ?",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function status(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $minutes = self::minutesRestantesParLien($request);

        return $this->ok([
            'email' => $user->email,
            'mot_de_passe_actuel_requis' => $minutes === null,
            'minutes_restantes_sans_ancien' => $minutes,
        ]);
    }

    /**
     * @OA\Post(path="/auth/password/change", tags={"Auth"}, summary="Change le mot de passe de la personne connectée",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"password","password_confirmation"},
     *
     *         @OA\Property(property="current_password", type="string"),
     *         @OA\Property(property="password", type="string", minLength=12),
     *         @OA\Property(property="password_confirmation", type="string"))),
     *
     *     @OA\Response(response=200, description="Mot de passe modifié"),
     *     @OA\Response(response=422, description="Ancien mot de passe requis ou incorrect, ou nouveau refusé"),
     *     @OA\Response(response=429, description="Throttle"))
     */
    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['nullable', 'string', 'max:1024'],
            'password' => ['required', 'string', 'confirmed', Password::min(12), new NotPwnedPassword],
        ]);

        /** @var User $user */
        $user = $request->user();
        $parLien = self::minutesRestantesParLien($request) !== null;

        if (! $parLien) {
            $actuel = (string) $request->input('current_password', '');

            if ($actuel === '') {
                Log::info('password_change.refuse', ['user_id' => $user->id, 'cause' => 'ancien_absent']);

                return $this->refus('mot_de_passe_actuel_requis', 'Indiquez votre mot de passe actuel.');
            }

            if (! $user->password_hash || ! Hash::check($actuel, $user->password_hash)) {
                Log::info('password_change.refuse', ['user_id' => $user->id, 'cause' => 'ancien_incorrect']);

                return $this->refus('mot_de_passe_actuel_incorrect', 'Le mot de passe actuel est incorrect.');
            }
        }

        // ── Mêmes effets que la réinitialisation ──────────────────────────────
        $user->password_hash = Hash::make((string) $request->input('password'));
        $user->failed_login_count = 0;
        $user->last_failed_login_at = null;
        $user->locked_until = null;
        // Les cookies « se souvenir de moi » des AUTRES appareils portent l'ancien
        // jeton : sans ce changement, ils rouvriraient une session neuve.
        $user->setRememberToken(Str::random(60));
        $user->save();

        $user->tokens()->delete();

        // La session courante reste ouverte, sur un identifiant neuf. `login()`
        // ré-émet aussi le cookie « se souvenir » avec le nouveau jeton quand la
        // personne en avait un.
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $seSouvenir = $request->cookies->has($guard->getRecallerName());
        $guard->login($user, $seSouvenir);

        $courante = $request->session()->getId();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $courante)
                ->delete();
        }
        // Pilote `redis` (production) : les autres sessions sont coupées à leur
        // prochaine requête par `AuthenticateSession` (Sanctum), qui compare le
        // hachage mémorisé en session à celui de la base — il vient de changer.
        // La session courante, elle, reçoit le nouveau hachage en sortie.

        // La dispense est consommée : elle ne sert qu'une fois.
        $request->session()->forget([self::SESSION_PAR_LIEN, self::SESSION_PAR_LIEN_A]);

        Log::info('password_change.effectue', [
            'user_id' => $user->id,
            'mode' => $parLien ? 'session_par_lien' : 'ancien_mot_de_passe',
        ]);

        $this->journaliser($request, $user);

        return $this->ok(['changed' => true]);
    }

    private function refus(string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'message' => $message,
            'errors' => ['current_password' => [$message]],
        ], 422);
    }

    /** Journal d'audit (chaîne de hachage) : QUI et QUAND, jamais la valeur. */
    private function journaliser(Request $request, User $user): void
    {
        try {
            app(AuditHashChain::class)->record([
                'workspace_id' => self::uuidOuNull($user->current_workspace_id),
                'user_id' => self::uuidOuNull($user->id),
                'method' => 'MOT_DE_PASSE_MODIFIE',
                'path' => 'api/v1/auth/password/change',
                'status' => 200,
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'payload_hash' => null,
            ]);
        } catch (\Throwable $e) {
            // Une trace manquée ne doit pas annuler un changement déjà effectué.
            report($e);
        }
    }

    private static function uuidOuNull(mixed $valeur): ?string
    {
        return is_string($valeur) && Str::isUuid($valeur) ? $valeur : null;
    }
}
