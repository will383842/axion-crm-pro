<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Services\Auth\CodeCourriel\CodesConnexionCourriel;
use App\Services\Auth\CodeCourriel\TropDeRenvois;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        if (! $this->codes->verifier($user, 'connexion', (string) $request->input('code'))) {
            AuditLogger::log('auth.code_courriel.refuse', ['user_id' => (string) $user->id]);
            throw ValidationException::withMessages(['code' => ['Code incorrect. Vérifiez-le et réessayez.']]);
        }

        // `appareil_confiance` est ignoré en G3-B (servi en G3-C).
        $request->session()->put('2fa_passed_at', now()->toIso8601String());
        $request->session()->regenerate();
        AuditLogger::log('auth.code_courriel.verifie', ['user_id' => (string) $user->id]);

        return $this->ok(['ok' => true]);
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
