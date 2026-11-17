<?php

namespace App\Http\Controllers\Api\Compte;

use App\Http\Controllers\Api\ApiController;
use App\Services\Auth\CodeCourriel\AppareilsConfiance;
use App\Services\Auth\CodeCourriel\CodesConnexionCourriel;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * G3-C — écran « Mon compte et sécurité » : appareils de confiance et code reçu pour un geste sensible.
 * Tout est filtré sur le compte connecté. Le code n'est jamais journalisé.
 */
class SecuriteCompteController extends ApiController
{
    public function __construct(
        private readonly AppareilsConfiance $appareils,
        private readonly CodesConnexionCourriel $codes,
    ) {}

    public function appareils(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok(['data' => $this->appareils->lister($user, $request->cookie(AppareilsConfiance::COOKIE))]);
    }

    public function retirerAppareil(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (CodesConnexionCourriel::methode($user) !== 'email') {
            return $this->methodeNonCourriel();
        }
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        // Inconnu ou d'un autre compte : 404 avant de toucher au code (aucun oracle).
        $existe = Str::isUuid($id)
            && DB::table('appareils_confiance')->where('id', $id)->where('user_id', $user->id)->whereNull('retire_le')->exists();
        if (! $existe) {
            return response()->json(['error' => 'not_found', 'message' => 'Appareil introuvable.'], 404);
        }

        if (! $this->codes->verifier($user, 'geste', (string) $request->input('code'))) {
            AuditLogger::log('auth.code_courriel.refuse', ['user_id' => (string) $user->id, 'objet' => 'geste']);
            throw ValidationException::withMessages(['code' => ['Code incorrect. Vérifiez-le et réessayez.']]);
        }

        $this->appareils->retirer($user, $id);
        AuditLogger::log('compte.appareil.retire', ['user_id' => (string) $user->id, 'appareil_id' => $id]);

        return $this->ok(['ok' => true]);
    }

    public function envoyerCode(Request $request): JsonResponse
    {
        $user = $request->user();
        if (CodesConnexionCourriel::methode($user) !== 'email') {
            return $this->methodeNonCourriel();
        }

        // Même plafond que les renvois (G3-A : 3 par 10 minutes), compté sur les codes « geste ».
        $depuis = CarbonImmutable::now()->subMinutes(10);
        $emises = DB::table('codes_connexion_courriel')
            ->where('user_id', $user->id)->where('objet', 'geste')
            ->where('cree_le', '>', $depuis->toIso8601String())
            ->count();
        if ($emises >= (int) config('double_auth.renvois_max', 3)) {
            return response()->json(['error' => 'trop_de_renvois', 'message' => 'Trop de codes demandés. Réessayez dans quelques minutes.'], 429);
        }

        try {
            $this->codes->emettre($user, 'geste', $request->ip());
        } catch (\Throwable $e) {
            Log::warning('auth.code_courriel.geste_echoue', ['user_id' => (string) $user->id, 'erreur' => $e::class]);

            return response()->json(['error' => 'canal_indisponible', 'message' => 'Envoi momentanément impossible.'], 503);
        }

        return $this->ok(['ok' => true]);
    }

    private function methodeNonCourriel(): JsonResponse
    {
        return response()->json(['error' => 'methode_non_courriel', 'message' => 'Ce compte n\'utilise pas le code par courriel.'], 409);
    }
}
