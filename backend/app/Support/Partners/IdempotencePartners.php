<?php

namespace App\Support\Partners;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lot N11 — idempotence du canal Partners, par l'en-tête `Idempotency-Key`.
 *
 *   - clé jamais vue → le traitement s'exécute ; la clé, l'empreinte sha256
 *     du corps, la date et le CODE de réponse sont enregistrés DANS LA MÊME
 *     TRANSACTION que le traitement ;
 *   - même clé + même corps → réponse REJOUÉE (code d'origine, corps rendu
 *     par la route à partir de ce code), en-tête `Idempotent-Replayed: true`,
 *     sans réexécuter le traitement ;
 *   - même clé + corps différent → 409 `{"erreur":"cle_reutilisee"}`.
 *
 * AUCUNE charge ni donnée personnelle n'est stockée : ni le corps, ni la
 * réponse, ni l'adresse de l'appelant. Le corps de la réponse rejouée est donc
 * RECONSTRUIT par la route (`$rendu`) à partir du seul code ; une route
 * métier future qui voudrait rejouer davantage devra le justifier par ADR.
 *
 * Deux requêtes simultanées sur la même clé : la contrainte d'unicité tranche,
 * la perdante voit sa transaction annulée (traitement compris) puis reçoit la
 * réponse rejouée ou le 409.
 *
 * Appelé APRÈS `VerificateurCanalPartners` : un appelant non authentifié ne
 * peut ni écrire une ligne ni sonder l'existence d'une clé.
 */
final class IdempotencePartners
{
    public const TABLE = 'partners_evenements_recus';

    public const ENTETE = 'Idempotency-Key';

    /** Clé fournie par l'émetteur (un UUID convient). */
    public const MOTIF_CLE = '/^[A-Za-z0-9._:-]{8,128}$/';

    /**
     * @param  Closure(): int  $traitement  exécute l'opération, rend le code HTTP
     * @param  Closure(int): array<string, mixed>  $rendu  corps de la réponse pour ce code
     */
    public static function executer(Request $request, Closure $traitement, Closure $rendu): JsonResponse
    {
        $cle = $request->header(self::ENTETE);
        if (! is_string($cle) || preg_match(self::MOTIF_CLE, $cle) !== 1) {
            return response()->json(['erreur' => 'cle_idempotence_invalide'], 400);
        }

        $empreinte = hash('sha256', $request->getContent());

        $deja = self::rejouer($cle, $empreinte, $rendu);
        if ($deja !== null) {
            return $deja;
        }

        try {
            $code = DB::transaction(function () use ($cle, $empreinte, $traitement): int {
                $code = $traitement();

                DB::table(self::TABLE)->insert([
                    'cle_idempotence' => $cle,
                    'empreinte_corps' => $empreinte,
                    'code_reponse' => $code,
                    'recu_le' => now(),
                ]);

                return $code;
            });
        } catch (UniqueConstraintViolationException) {
            return self::rejouer($cle, $empreinte, $rendu)
                ?? response()->json(['erreur' => 'cle_reutilisee'], 409);
        }

        return response()->json($rendu($code), $code);
    }

    /**
     * @param  Closure(int): array<string, mixed>  $rendu
     */
    private static function rejouer(string $cle, string $empreinte, Closure $rendu): ?JsonResponse
    {
        $ligne = DB::table(self::TABLE)
            ->where('cle_idempotence', $cle)
            ->first(['empreinte_corps', 'code_reponse']);

        if ($ligne === null) {
            return null;
        }

        if (! hash_equals((string) $ligne->empreinte_corps, $empreinte)) {
            return response()->json(['erreur' => 'cle_reutilisee'], 409);
        }

        $code = (int) $ligne->code_reponse;

        return response()->json($rendu($code), $code, ['Idempotent-Replayed' => 'true']);
    }
}
