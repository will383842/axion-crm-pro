<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RGPD : anonymisation des IPs dans audit_logs + sessions > 30 jours.
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
 */
class AnonymizeOldIps extends Command
{
    protected $signature = 'rgpd:anonymize-ips {--dry-run}';

    protected $description = 'Anonymise les IPs > 30 jours dans audit_logs + sessions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays(30);

        if ($dryRun) {
            $auditCount = DB::table('audit_logs')
                ->where('created_at', '<', $cutoff)
                ->whereNotNull('ip')
                ->count();
        } else {
            // `set_masklen` remplace la division inexistante. `network()` met à
            // zéro les bits hors masque, `host()` rend la forme textuelle sans
            // suffixe, et le retour en `inet` respecte le type de la colonne :
            //   192.168.42.123        → /24 → 192.168.42.0
            //   2001:db8:1234:5678::1 → /48 → 2001:db8:1234::
            //
            // On compte les lignes touchées avec `affectingStatement` :
            // `statement()` ne rend qu'un booléen, et un booléen ne permet ni à
            // l'exploitant ni à une garde de dire si quoi que ce soit a bougé —
            // c'est aussi ce qui rendait l'échec de cette commande invisible
            // dans son propre message de sortie (« audit_logs=updated »).
            $auditCount = DB::affectingStatement(<<<'SQL'
                UPDATE audit_logs
                SET ip = host(network(set_masklen(ip::cidr, CASE WHEN family(ip) = 4 THEN 24 ELSE 48 END)))::inet
                WHERE created_at < ? AND ip IS NOT NULL
                SQL, [$cutoff]);
        }

        $sessionsCount = $dryRun
            ? DB::table('sessions')->where('last_activity', '<', $cutoff->timestamp)->whereNotNull('ip_address')->count()
            : DB::table('sessions')
                ->where('last_activity', '<', $cutoff->timestamp)
                ->whereNotNull('ip_address')
                ->update(['ip_address' => null]);

        $this->info('anonymise IPs : audit_logs=' . $auditCount
            . ' sessions=' . $sessionsCount . ' (dry-run=' . ($dryRun ? 'true' : 'false') . ')');

        return self::SUCCESS;
    }
}
