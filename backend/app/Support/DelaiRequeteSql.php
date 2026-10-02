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
     * ⚠️ PAS DE TRANSACTION, À DESSEIN, ET CE QUE ÇA IMPLIQUE (relecture A09) :
     *
     *   - `SET` (de session) et non `SET LOCAL` : `SET LOCAL` exige une
     *     transaction et durerait jusqu'à la fin de la transaction ENGLOBANTE,
     *     pas jusqu'à la fin de `$calcul` — appelé dans une transaction plus
     *     large, il élargirait le délai de tout le reste de celle-ci. Le `SET`
     *     est, lui, restauré explicitement dans le `finally`.
     *   - Appelé DANS une transaction : si `$calcul` y échoue, la transaction
     *     est avortée et la restauration échoue à son tour ; c'est sans
     *     danger — l'annulation de la transaction annule aussi le `SET` fait
     *     en son sein (Postgres rétablit la valeur d'avant), et le délai web
     *     d'origine revient.
     *   - L'élargissement ne vaut QUE pour la connexion courante et la durée
     *     de `$calcul`. Ce n'est pas un contournement de sécurité : il ne
     *     donne accès à aucune donnée de plus, il ne sert qu'aux calculs
     *     MIS EN CACHE dont le recalcul tourne pendant une requête web.
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

    /**
     * Délais mis de côté pendant un job exécuté EN LIGNE (connexion `sync`).
     *
     * @var list<int|null>
     */
    private static array $pileJobsSync = [];

    /**
     * Un job `sync` lancé par une requête web s'exécute DANS cette requête,
     * donc sous ses 15 s. Or un job est un traitement de fond : il ne doit
     * jamais hériter du délai d'un écran. Vérifié en prod le 2026-10-02 :
     * `QUEUE_CONNECTION=redis` (les jobs tournent dans Horizon, connexion sans
     * limite) — ce garde couvre le repli `sync` (poste local, tests, panne de
     * Redis basculée à la main). Les autres connexions ne sont pas touchées.
     */
    public static function libererPourJobSync(?string $connexionFile): void
    {
        if ($connexionFile !== 'sync') {
            return;
        }

        try {
            $avant = self::courantMs();
            if ($avant !== null && $avant !== 0) {
                self::poser(0);
            }
        } catch (\Throwable) {
            $avant = null;
        }

        self::$pileJobsSync[] = $avant;
    }

    /** Rend à la requête web son délai, une fois le job `sync` terminé. */
    public static function restaurerApresJobSync(?string $connexionFile): void
    {
        if ($connexionFile !== 'sync' || self::$pileJobsSync === []) {
            return;
        }

        $avant = array_pop(self::$pileJobsSync);
        if ($avant === null || $avant === 0) {
            return;
        }

        try {
            self::poser($avant);
        } catch (\Throwable) {
            // Transaction avortée par le job : la requête web échouera de
            // toute façon, rien à restaurer.
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
