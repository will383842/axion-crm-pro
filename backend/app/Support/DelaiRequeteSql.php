<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * DÉLAI MAXIMAL D'UNE REQUÊTE SQL — le filet de sécurité des requêtes WEB.
 *
 * ── CONSTAT DU 2026-10-02 ───────────────────────────────────────────────────
 *
 * `show statement_timeout` rendait `0` en production : AUCUNE limite. La liste
 * « Contacts » (`/crm/contacts-hub?temperature=actifs`) tournait plus de 100 s
 * sur 4,3 M de fiches, chaque visite en relançait une, les requêtes
 * s'empilaient sur un serveur à 2 CPU, l'onglet gelait, la session se perdait.
 *
 * Une requête d'ÉCRAN qui dépasse quinze secondes n'aboutira jamais à rien
 * d'utile : l'opérateur est parti, ou a recliqué. La laisser courir, c'est
 * voler le processeur aux requêtes suivantes.
 *
 * ── CE QUI EST BORNÉ, ET CE QUI NE L'EST PAS ────────────────────────────────
 *
 *   - BORNÉ : toute requête HTTP de l'API (`LimiteDureeRequetesSql`, posé en
 *     tête du groupe `api`). La connexion php-fpm n'est pas persistante : la
 *     limite meurt avec la requête.
 *   - JAMAIS BORNÉ : les commandes artisan (joignabilité, classement, imports)
 *     et les tâches de file. Elles ne traversent pas le middleware : leur
 *     connexion garde la valeur du serveur (`0`). Un test le garde.
 *   - ÉLARGI LOCALEMENT : les calculs lourds MAIS mis en cache qui s'exécutent
 *     pendant une requête web (compteurs du hub, tableau de bord), via
 *     `etendu()`. Leur recalcul part APRÈS la réponse (`Cache::flexible`) et
 *     hériterait sinon des quinze secondes de l'écran.
 */
final class DelaiRequeteSql
{
    /** SQLSTATE `query_canceled` — ce que Postgres lève au dépassement. */
    public const SQLSTATE_ANNULEE = '57014';

    /** Le message rendu à l'écran, en français, sans jargon. */
    public const MESSAGE = 'La recherche prend trop de temps, affinez les filtres.';

    /** Délai par défaut des requêtes web, en millisecondes. */
    public static function delaiWebMs(): int
    {
        $valeur = config('database.statement_timeout_web_ms', 15000);

        return is_numeric($valeur) ? max(0, (int) $valeur) : 15000;
    }

    /** Pose le délai sur la connexion. `0` = aucune limite. */
    public static function poser(int $millisecondes, ?Connection $connexion = null): void
    {
        $connexion ??= DB::connection();
        if ($connexion->getDriverName() !== 'pgsql') {
            return;
        }

        // Entier forcé : jamais de valeur libre interpolée dans du SQL.
        $connexion->statement('SET statement_timeout = ' . max(0, $millisecondes));
    }

    /** La valeur courante, en millisecondes (`null` hors Postgres). */
    public static function courantMs(?Connection $connexion = null): ?int
    {
        $connexion ??= DB::connection();
        if ($connexion->getDriverName() !== 'pgsql') {
            return null;
        }

        // `current_setting` rend une durée lisible (« 15s », « 0 ») : on passe
        // par `pg_settings`, qui la donne dans son unité de base (ms).
        $valeur = $connexion->scalar("SELECT setting FROM pg_settings WHERE name = 'statement_timeout'");

        return is_numeric($valeur) ? (int) $valeur : null;
    }

    /**
     * Exécute `$calcul` avec un délai ÉLARGI, puis restaure le précédent — y
     * compris sur exception. Ne RÉDUIT jamais un délai : si la connexion est
     * déjà sans limite (commande artisan), elle le reste.
     *
     * @template T
     *
     * @param  callable(): T  $calcul
     * @return T
     */
    public static function etendu(int $secondes, callable $calcul): mixed
    {
        $avant = self::courantMs();

        if ($avant === null || $avant === 0 || $avant >= $secondes * 1000) {
            return $calcul();
        }

        self::poser($secondes * 1000);

        try {
            return $calcul();
        } finally {
            self::poser($avant);
        }
    }

    /** L'exception est-elle un dépassement du délai ? */
    public static function estDepassement(\Throwable $e): bool
    {
        return $e instanceof QueryException
            && ((string) $e->getCode() === self::SQLSTATE_ANNULEE
                || str_contains($e->getMessage(), 'canceling statement due to statement timeout'));
    }
}
