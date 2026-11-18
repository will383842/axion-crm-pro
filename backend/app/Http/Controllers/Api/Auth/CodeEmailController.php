<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\Auth\CodeCourriel\AppareilsConfiance;
use App\Services\Auth\CodeCourriel\CodesConnexionCourriel;
use App\Services\Auth\CodeCourriel\TropDeRenvois;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * G3-B — franchissement de la double authentification par code reçu par courriel.
 * Tout est filtré sur le compte connecté (jamais le code d'un autre). Le code n'est jamais journalisé.
 */
class CodeEmailController extends ApiController
{
    public function __construct(private readonly CodesConnexionCourriel $codes) {}

    public function verifier(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        if (CodesConnexionCourriel::methode($user) !== 'email') {
            return response()->json(['error' => 'methode_non_courriel', 'message' => 'Ce compte n\'utilise pas le code par courriel.'], 409);
        }

        $request->validate([
            'code' => ['required', 'string', 'between:6,10'],
            'appareil_confiance' => ['sometimes', 'boolean'],
        ]);

        // 6 chiffres : code reçu par courriel. 10 caractères : code de secours (G3-D). Toute autre forme : refusée.
        $code = (string) $request->input('code');
        $accepte = match (true) {
            preg_match('/^\d{6}$/', $code) === 1 => $this->codes->verifier($user, 'connexion', $code),
            strlen($code) === 10 => $this->consommerCodeSecours($user, $code),
            default => false,
        };
        if (! $accepte) {
            AuditLogger::log('auth.code_courriel.refuse', ['user_id' => (string) $user->id]);
            throw ValidationException::withMessages(['code' => ['Code incorrect. Vérifiez-le et réessayez.']]);
        }

        $request->session()->put('2fa_passed_at', now()->toIso8601String());
        $request->session()->regenerate();
        AuditLogger::log('auth.code_courriel.verifie', ['user_id' => (string) $user->id]);

        $reponse = $this->ok(['ok' => true]);

        // G3-C : « Faire confiance à cet appareil » — cookie 30 jours, seule l'empreinte reste en base.
        if ($request->boolean('appareil_confiance')) {
            $jeton = app(AppareilsConfiance::class)->ajouter($user, $request->ip(), $request->userAgent());
            $minutes = 60 * 24 * (int) config('double_auth.duree_appareil_jours', 30);
            $reponse->withCookie(cookie(AppareilsConfiance::COOKIE, $jeton, $minutes, '/', null, true, true, false, 'lax'));
        }

        return $reponse;
    }

    /**
     * G3-D — code de secours à usage unique, même logique que `TwoFactorService::verify` (recopiée : ce service n'est pas touché).
     * La ligne du compte est verrouillée : deux requêtes simultanées avec le même code ne passent pas toutes les deux.
     * Seul le compte connecté est lu.
     */
    private function consommerCodeSecours(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $verrouille = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $jeu = (array) ($verrouille->totp_recovery_codes ?? []);
            foreach ($jeu as $i => $hache) {
                if (Hash::check($code, (string) $hache)) {
                    unset($jeu[$i]);
                    $verrouille->totp_recovery_codes = array_values($jeu);
                    $verrouille->save();
                    $user->totp_recovery_codes = $verrouille->totp_recovery_codes;

                    return true;
                }
            }

            return false;
        });
    }

    public function renvoyer(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }
        if (CodesConnexionCourriel::methode($user) !== 'email') {
            return response()->json(['error' => 'methode_non_courriel', 'message' => 'Ce compte n\'utilise pas le code par courriel.'], 409);
        }

        try {
            $this->codes->renvoyer($user, $request->ip());
        } catch (TropDeRenvois) {
            return response()->json(['error' => 'trop_de_renvois', 'message' => 'Trop de codes demandés. Réessayez dans quelques minutes.'], 429);
        } catch (\Throwable $e) {
            Log::warning('auth.code_courriel.renvoi_echoue', ['user_id' => (string) $user->id, 'erreur' => $e::class]);

            return response()->json(['error' => 'canal_indisponible', 'message' => 'Envoi momentanément impossible.'], 503);
        }

        return $this->ok(['ok' => true, 'renvois_restants' => $this->codes->renvoisRestants($user)]);
    }
}
