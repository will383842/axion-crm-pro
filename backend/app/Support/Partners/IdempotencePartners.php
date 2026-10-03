<?php

namespace App\Support\Partners;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

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
 * Deux requêtes simultanées sur la même clé : la contrainte d'unicité tranche
 * (`INSERT … ON CONFLICT ON CONSTRAINT` nommée, `CONTRAINTE_UNICITE`), la
 * perdante voit sa transaction annulée (traitement compris) puis reçoit la
 * réponse rejouée ou le 409. Seule CETTE contrainte mène au rejeu ou au 409 :
 * une violation d'unicité levée par le traitement lui-même (sur une autre
 * table) remonte telle quelle, transaction annulée.
 *
 * Une réponse 5xx n'est JAMAIS mémorisée : la transaction est annulée
 * (traitement compris), aucune ligne n'est insérée, et la reprise sous la même
 * clé RETENTE réellement le traitement. Une 2xx/3xx/4xx est mémorisée et
 * rejouée. (Le rôle applicatif n'a que SELECT/INSERT : ne rien insérer est la
 * seule façon de ne pas mémoriser.)
 */
final class IdempotencePartners
{
    public const TABLE = 'partners_idempotence';

    /** Nom de l'unicité (route, clé) posée par la migration (vérifié par test). */
    public const CONTRAINTE_UNICITE = self::TABLE . '_route_cle_idempotence_unique';

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

        DB::beginTransaction();
        try {
            [$code, $resume] = $traitement();
            if ($resume !== null && strlen($resume) > self::RESUME_MAX) {
                throw new LogicException('Résumé de réponse trop long pour la table d’idempotence.');
            }

            // 5xx = erreur côté CRM : rien n'est inséré, la reprise retentera.
            $inseree = $code >= 500 ? null : DB::affectingStatement(
                'INSERT INTO ' . self::TABLE . ' (route, cle_idempotence, empreinte_corps, code_reponse, resume_reponse, recu_le) '
                . 'VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT ON CONSTRAINT ' . self::CONTRAINTE_UNICITE . ' DO NOTHING',
                [$route, $cle, $empreinte, $code, $resume, now()],
            );
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($inseree === null) {
            DB::rollBack();

            return response()->json($rendu($code, $resume), $code);
        }

        if ($inseree === 0) {
            // Course perdue sur (route, clé) : traitement annulé, la gagnante fait foi.
            DB::rollBack();

            return self::rejouer($route, $cle, $empreinte, $rendu)
                ?? response()->json(['erreur' => 'cle_reutilisee'], 409);
        }

        DB::commit();

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
