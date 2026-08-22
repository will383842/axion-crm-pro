<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RGPD : anonymisation des IPs dans audit_logs + users + sessions > 30 jours.
 * Tronque IPv4 au /24 (192.168.42.123 → 192.168.42.0) et IPv6 au /48.
 * Schedule daily à 04:30.
 *
 * 🔴 CETTE TÂCHE N'A JAMAIS FONCTIONNÉ (audit 360, A08-006, sévérité S0).
 *
 * Le SQL portait `ip::cidr / CASE WHEN family(ip) = 4 THEN 24 ELSE 48 END`.
 * Mesure jouée dans le Postgres 16 du dépôt :
 *
 *     ERROR:  operator does not exist: cidr / integer
 *     HINT:   No operator matches the given name and argument types.
 *
 * Postgres n'a AUCUN opérateur `cidr / integer` : la division n'est pas définie
 * sur les types réseau. La fonction qui fixe une longueur de masque est
 * `set_masklen(cidr, int)`. Chaque exécution levait donc une QueryException,
 * l'IP n'était jamais tronquée, et **aucune IP de plus de 30 jours n'a été
 * anonymisée depuis la mise en service** — l'exact contraire de ce que cette
 * docbloc promet et de ce que la politique RGPD annonce.
 *
 * DÉGÂT COLLATÉRAL : l'effacement des IP de `sessions`, lui, était correctement
 * écrit (query builder). Mais il vient APRÈS dans `handle()`, et l'exception de
 * la première requête faisait mourir la commande avant de l'atteindre. Une
 * seule faute de SQL emportait donc les DEUX moitiés de la tâche.
 *
 * POURQUOI LE DÉFAUT A SURVÉCU DEUX ANS : la seule branche qu'un exploitant
 * essaie à la main est `--dry-run`, et elle passe par des `count()` de query
 * builder. Elle n'atteint JAMAIS le SQL fautif, et elle affiche un compte
 * rassurant. Le chemin réel, lui, n'avait aucun test. La garde
 * `tests/Feature/Commands/AutomatismesDePurgeTest.php` joue désormais le chemin
 * RÉEL et vérifie les valeurs tronquées, pas seulement un code de retour.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔴 B15-011 (S3) — `users.last_login_ip` N'ÉTAIT JAMAIS ANONYMISÉE.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Mesure du 2026-08-22 : cette commande ne touchait que `audit_logs` et
 * `sessions`. `AuthService.php:116` écrit pourtant `last_login_ip => $request->ip()`
 * à CHAQUE connexion (colonne INET, migration 2026_05_16_000002:57), et le seul
 * endroit du dépôt qui remet cette colonne à NULL est
 * `GdprErasureService.php:230`, c'est-à-dire un effacement RGPD *demandé*. Sans
 * demande explicite d'un utilisateur, son IP de dernière connexion était donc
 * conservée SANS AUCUNE LIMITE DE DURÉE, alors que la politique de conservation
 * annonce 30 jours pour les IP.
 *
 * POURQUOI TRONQUER, ET PAS METTRE À NULL. `users.last_login_ip` sort en
 * portabilité (`GdprPortabilityService.php:171`). Un NULL ferait disparaître la
 * donnée de l'export ; la troncature /24-/48 la garde lisible (« vous vous êtes
 * connecté depuis 192.168.42.0 ») tout en la rendant non identifiante — et c'est
 * exactement le traitement déjà appliqué à `audit_logs`. Une seule règle pour
 * toutes les IP du produit vaut mieux que deux, surtout dans un document qu'il
 * faut pouvoir expliquer à la CNIL.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔴 B17-005 (S2) — CHAQUE NUIT RÉÉCRIVAIT TOUT L'HISTORIQUE.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Le prédicat était `WHERE created_at < ? AND ip IS NOT NULL` : il ne distingue
 * pas une IP DÉJÀ tronquée d'une IP brute. L'opération est idempotente en
 * VALEUR, elle ne l'était pas en ÉCRITURE — chaque nuit à 04:30, la totalité des
 * lignes de plus de 30 jours était réécrite, y compris les millions déjà
 * anonymisées les nuits précédentes. D'où la troisième condition ci-dessous :
 * on n'écrit que les lignes dont la valeur va réellement changer.
 *
 * ⚠️ CE QUE CE CORRECTIF NE RÉPARE PAS, et qu'il ne doit surtout pas masquer :
 * la colonne `ip` ENTRE dans `AuditHashChain::canonical()` (AuditHashChain.php:198).
 * Tronquer une IP casse donc le chaînage d'intégrité de la ligne concernée. Le
 * volume est divisé (une ligne n'est plus cassée qu'UNE fois au lieu d'une fois
 * par nuit), mais le conflit de fond « anonymiser » vs « journal inaltérable »
 * reste entier : il demande une décision (exclure `ip` du canonique, ou rechaîner
 * après anonymisation), pas un correctif de volume.
 */
class AnonymizeOldIps extends Command
{
    /**
     * L'expression de troncature, paramétrée par le nom de colonne (`%1$s`).
     *
     * Écrite une fois et réutilisée quatre fois : elle apparaît à la fois dans le
     * `SET` et dans le prédicat « ce n'est pas déjà fait » de B17-005. Deux
     * copies divergentes de cette expression, et l'UPDATE se remettrait à
     * réécrire toutes les nuits sans que personne ne le voie.
     */
    private const TRONCATURE = 'host(network(set_masklen(%1$s::cidr, CASE WHEN family(%1$s) = 4 THEN 24 ELSE 48 END)))::inet';

    protected $signature = 'rgpd:anonymize-ips {--dry-run}';

    protected $description = 'Anonymise les IPs > 30 jours dans audit_logs + users + sessions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays(30);

        // `set_masklen` remplace la division inexistante. `network()` met à zéro
        // les bits hors masque, `host()` rend la forme textuelle sans suffixe, et
        // le retour en `inet` respecte le type de la colonne :
        //   192.168.42.123        → /24 → 192.168.42.0
        //   2001:db8:1234:5678::1 → /48 → 2001:db8:1234::
        $tronqueIp = sprintf(self::TRONCATURE, 'ip');
        $tronqueDerniereIp = sprintf(self::TRONCATURE, 'last_login_ip');

        if ($dryRun) {
            // L'essai à blanc porte LE MÊME prédicat que le chemin réel, y compris
            // l'exclusion B17-005. Sinon il annoncerait tout l'historique alors que
            // la commande n'écrirait qu'une poignée de lignes — et l'exploitant
            // n'aurait aucun moyen de voir que le volume nocturne a été réparé.
            $auditCount = DB::table('audit_logs')
                ->where('created_at', '<', $cutoff)
                ->whereNotNull('ip')
                ->whereRaw('ip <> ' . $tronqueIp)
                ->count();

            $usersCount = DB::table('users')
                ->where(fn ($q) => $q->whereNull('last_login_at')->orWhere('last_login_at', '<', $cutoff))
                ->whereNotNull('last_login_ip')
                ->whereRaw('last_login_ip <> ' . $tronqueDerniereIp)
                ->count();
        } else {
            // On compte les lignes touchées avec `affectingStatement` :
            // `statement()` ne rend qu'un booléen, et un booléen ne permet ni à
            // l'exploitant ni à une garde de dire si quoi que ce soit a bougé —
            // c'est aussi ce qui rendait l'échec de cette commande invisible
            // dans son propre message de sortie (« audit_logs=updated »).
            $auditCount = DB::affectingStatement(
                "UPDATE audit_logs
                 SET ip = {$tronqueIp}
                 WHERE created_at < ?
                   AND ip IS NOT NULL
                   AND ip <> {$tronqueIp}",
                [$cutoff]
            );

            // B15-011. `last_login_at IS NULL` est traité comme « ancien » : une
            // ligne qui porte une IP sans date de connexion n'a aucune échéance
            // opposable, et la garder indéfiniment serait le défaut qu'on répare.
            $usersCount = DB::affectingStatement(
                "UPDATE users
                 SET last_login_ip = {$tronqueDerniereIp}
                 WHERE (last_login_at IS NULL OR last_login_at < ?)
                   AND last_login_ip IS NOT NULL
                   AND last_login_ip <> {$tronqueDerniereIp}",
                [$cutoff]
            );
        }

        $sessionsCount = $dryRun
            ? DB::table('sessions')->where('last_activity', '<', $cutoff->timestamp)->whereNotNull('ip_address')->count()
            : DB::table('sessions')
                ->where('last_activity', '<', $cutoff->timestamp)
                ->whereNotNull('ip_address')
                ->update(['ip_address' => null]);

        $this->info('anonymise IPs : audit_logs=' . $auditCount
            . ' users=' . $usersCount
            . ' sessions=' . $sessionsCount . ' (dry-run=' . ($dryRun ? 'true' : 'false') . ')');

        return self::SUCCESS;
    }
}
