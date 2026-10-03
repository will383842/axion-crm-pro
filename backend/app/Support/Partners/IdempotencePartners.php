<?php

namespace App\Support\Partners;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Lot N11 — idempotence du canal Partners, par l'en-tête `Idempotency-Key`
 * (couvert par la signature, cf. `VerificateurCanalPartners`).
 *
 * L'unicité porte sur (route, clé) : une même clé envoyée à deux routes
 * différentes désigne deux opérations distinctes.
 *
 *   - clé jamais vue sur cette route → le traitement s'exécute ; route, clé,
 *     empreinte sha256 du corps, date, code de réponse et RÉSUMÉ de réponse
 *     sont enregistrés DANS LA MÊME TRANSACTION que le traitement ;
 *   - même clé + même corps → réponse REJOUÉE à l'identique (code et résumé
 *     d'origine), en-tête `Idempotent-Replayed: true`, sans réexécuter ;
 *   - même clé + corps différent → 409 `{"erreur":"cle_reutilisee"}`.
 *
 * Le RÉSUMÉ (64 caractères au plus) est ce qu'il faut à la route pour
 * reconstruire sa réponse d'origine — pour le ping, le mode du canal au moment
 * de la réception. Il ne porte JAMAIS de donnée personnelle ni la charge : ni
 * le corps, ni la réponse complète, ni l'adresse de l'appelant ne sont stockés.
 *
 * Deux requêtes simultanées sur la même clé : la contrainte d'unicité tranche,
 * la perdante voit sa transaction annulée (traitement compris) puis reçoit la
 * réponse rejouée ou le 409.
 */
final class IdempotencePartners
{
    public const TABLE = 'partners_idempotence';

    public const ENTETE = 'Idempotency-Key';

    /**
     * Clé fournie par l'émetteur. Accepte `<uuid>:<type>:<version>` (forme
     * prévue côté Partners). JAMAIS de point : la clé est signée dans
     * « horodatage.clé.corps », le point y est le séparateur.
     */
    public const MOTIF_CLE = '/^[A-Za-z0-9_:-]{8,128}$/';

    public const RESUME_MAX = 64;

    /**
     * @param  Closure(): array{0: int, 1: ?string}  $traitement  exécute l'opération, rend [code HTTP, résumé]
     * @param  Closure(int, ?string): array<string, mixed>  $rendu  corps de la réponse pour ce code et ce résumé
     */
    public static function executer(Request $request, string $route, Closure $traitement, Closure $rendu): JsonResponse
    {
        $cle = $request->header(self::ENTETE);
        if (! is_string($cle) || preg_match(self::MOTIF_CLE, $cle) !== 1) {
            // Défensif : le vérificateur refuse déjà (401) une clé absente ou
            // hors format, puisqu'elle entre dans la signature.
            return response()->json(['erreur' => 'cle_idempotence_invalide'], 400);
        }

        $empreinte = hash('sha256', $request->getContent());

        $deja = self::rejouer($route, $cle, $empreinte, $rendu);
        if ($deja !== null) {
            return $deja;
        }

        try {
            [$code, $resume] = DB::transaction(function () use ($route, $cle, $empreinte, $traitement): array {
                [$code, $resume] = $traitement();
                if ($resume !== null && strlen($resume) > self::RESUME_MAX) {
                    throw new LogicException('Résumé de réponse trop long pour la table d’idempotence.');
                }

                DB::table(self::TABLE)->insert([
                    'route' => $route,
                    'cle_idempotence' => $cle,
                    'empreinte_corps' => $empreinte,
                    'code_reponse' => $code,
                    'resume_reponse' => $resume,
                    'recu_le' => now(),
                ]);

                return [$code, $resume];
            });
        } catch (UniqueConstraintViolationException) {
            return self::rejouer($route, $cle, $empreinte, $rendu)
                ?? response()->json(['erreur' => 'cle_reutilisee'], 409);
        }

        return response()->json($rendu($code, $resume), $code);
    }

    /**
     * @param  Closure(int, ?string): array<string, mixed>  $rendu
     */
    private static function rejouer(string $route, string $cle, string $empreinte, Closure $rendu): ?JsonResponse
    {
        $ligne = DB::table(self::TABLE)
            ->where('route', $route)
            ->where('cle_idempotence', $cle)
            ->first(['empreinte_corps', 'code_reponse', 'resume_reponse']);

        if ($ligne === null) {
            return null;
        }

        if (! hash_equals((string) $ligne->empreinte_corps, $empreinte)) {
            return response()->json(['erreur' => 'cle_reutilisee'], 409);
        }

        $code = (int) $ligne->code_reponse;
        $resume = $ligne->resume_reponse === null ? null : (string) $ligne->resume_reponse;

        return response()->json($rendu($code, $resume), $code, ['Idempotent-Replayed' => 'true']);
    }
}
