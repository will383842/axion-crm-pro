<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Reprise des horodatages décalés de +2 h (cf. `config/database.php`).
 *
 * ── CE QUE CETTE COMMANDE NE FAIT JAMAIS ────────────────────────────────────
 * Elle n'exécute AUCUN `DELETE`, AUCUN `INSERT`, AUCUN `DROP`. Uniquement des
 * `UPDATE` sur des colonnes de type `timestamptz`. Le nombre de lignes de
 * chaque table est relevé avant et après, et la transaction est annulée s'il a
 * bougé. Aucune fiche — contact, entreprise, candidat — ne peut disparaître.
 *
 * ── LE DISCRIMINANT ─────────────────────────────────────────────────────────
 * Toutes les valeurs stockées ne sont PAS fausses : ce que Postgres a écrit
 * lui-même (`DEFAULT now()`, `UPDATE … = now()`, backfills SQL) a toujours été
 * juste, et une correction en bloc le casserait. On distingue les deux à la
 * microseconde :
 *
 *   · Laravel sérialise `Y-m-d H:i:s` — JAMAIS de microsecondes ;
 *   · `now()` de Postgres en a toujours.
 *
 * Donc `date_part('microseconds', col) % 1000000 = 0` ⇒ écrit par
 * l'application ⇒ à corriger. Sinon : déjà juste, on n'y touche pas.
 *
 * Angles morts assumés, et c'est pourquoi `--simuler` est le défaut :
 *   · un `now()` tombant pile sur la microseconde 0 (~1 sur 10⁶) ;
 *   · une donnée IMPORTÉE déjà juste et arrondie à la seconde.
 * Les deux se repèrent dans le rapport de simulation, table par table.
 *
 * ── LA TRANSFORMATION ───────────────────────────────────────────────────────
 *   (col AT TIME ZONE 'UTC') AT TIME ZONE 'Europe/Paris'
 * `AT TIME ZONE 'UTC'` rend l'heure murale que l'application voulait écrire ;
 * la seconde conversion la réinterprète comme une heure de Paris. Postgres
 * applique le décalage en vigueur À CETTE DATE-LÀ : −2 h en été, −1 h en
 * hiver, sans constante à choisir.
 *
 * ── LES DÉCLENCHEURS : LE PIÈGE QUI COÛTE L'HISTORIQUE ──────────────────────
 * Constaté à l'essai sur une COPIE de la base, le 2026-08-16 : quatorze tables
 * portent `trg_set_updated_at`, qui repose `updated_at = now()` à CHAQUE
 * `UPDATE`. Une reprise naïve corrigeait donc `created_at`… et écrasait au
 * passage la date de modification de chaque ligne touchée par l'heure du jour.
 * Les lignes ne disparaissent pas, mais leur historique, si.
 * Pire, `companies` et `contacts` portent en plus un déclencheur de RECALCUL
 * DE SCORE : la reprise aurait relancé un recalcul sur des millions de fiches,
 * pour une opération censée ne toucher que des horodatages.
 *
 * On suspend donc les déclencheurs UTILISATEUR le temps de la reprise, DANS la
 * transaction (`ALTER TABLE … DISABLE TRIGGER USER` est transactionnel sous
 * Postgres : une annulation les rétablit d'office). Cela exige d'être
 * PROPRIÉTAIRE des tables — d'où `--connexion=pgsql_owner` par défaut — et
 * prend un verrou ACCESS EXCLUSIVE bref sur chaque table : à faire en fenêtre
 * de maintenance, jamais en pleine charge.
 */
class CorrigerHorodatages extends Command
{
    protected $signature = 'horodatages:corriger
        {--appliquer : écrire réellement (par défaut : simulation seule)}
        {--table=* : restreindre à ces tables (défaut : toutes)}
        {--taille=50000 : taille des tranches}
        {--connexion=pgsql_owner : connexion à utiliser (le rôle doit être PROPRIÉTAIRE des tables)}';

    protected $description = 'Reprend les horodatages écrits +2 h en avance par l’application (simulation par défaut)';

    /**
     * Tables volontairement écartées.
     *
     * `audit_logs` : journal scellé par chaîne de hachage. Le retoucher a
     * posteriori est exactement ce que ce journal existe pour empêcher — et
     * comme `created_at` n'entre pas dans le calcul du hachage, la chaîne
     * survivrait à la modification, ce qui rendrait une falsification
     * indétectable. Le décalage y est documenté, pas corrigé.
     *
     * `migrations` : horodatages d'outillage, sans portée métier.
     */
    private const TABLES_ECARTEES = ['audit_logs', 'audit_logs_default', 'migrations'];

    public function handle(): int
    {
        $appliquer = (bool) $this->option('appliquer');

        if (! $this->verifierOrdreDesOperations($appliquer)) {
            return self::FAILURE;
        }

        $cibles = $this->colonnesACorriger((array) $this->option('table'));

        if ($cibles === []) {
            $this->warn('Aucune colonne à traiter.');

            return self::SUCCESS;
        }

        $this->line($appliquer ? '⚠️  MODE ÉCRITURE' : 'Mode simulation — aucune écriture.');
        $this->newLine();

        $total = 0;
        $manuelles = [];

        foreach ($cibles as $table => $colonnes) {
            $cle = $this->clePrimaireSimple($table);

            if ($cle === null) {
                // Pas de clé primaire à colonne unique : on ne peut ni tronçonner
                // ni relire de façon déterministe. On le DIT plutôt que de
                // passer silencieusement — une reprise muette sur une table
                // oubliée est pire qu'une reprise refusée.
                $manuelles[$table] = $colonnes;

                continue;
            }

            $aCorriger = $this->compter($table, $colonnes);
            $total += $aCorriger;

            $this->line(sprintf(
                '%-32s %-8s lignes à reprendre : %s',
                $table,
                '(' . count($colonnes) . ' col.)',
                number_format($aCorriger, 0, ',', ' '),
            ));

            if ($appliquer && $aCorriger > 0) {
                $this->appliquerSurTable($table, $cle, $colonnes);
            }
        }

        $this->newLine();
        $this->info('Total de lignes concernées : ' . number_format($total, 0, ',', ' '));

        if ($manuelles !== []) {
            $this->newLine();
            $this->warn('Tables SANS clé primaire à colonne unique — NON traitées, à reprendre à la main :');
            foreach ($manuelles as $table => $colonnes) {
                $this->warn('  · ' . $table . ' → ' . implode(', ', $colonnes));
            }
        }

        if (! $appliquer) {
            $this->newLine();
            $this->comment('Relancer avec --appliquer pour écrire. Sauvegarde préalable OBLIGATOIRE.');
        }

        return self::SUCCESS;
    }

    /**
     * Refuse d'écrire si le correctif de connexion est DÉJÀ actif.
     *
     * Une fois `DB_TIMEZONE` posé, l'application écrit juste — mais toujours à
     * la seconde pleine. Les lignes neuves et correctes deviennent alors
     * indistinguables des anciennes et fausses, et la reprise les décalerait à
     * leur tour. L'ordre est donc : reprendre les données, PUIS poser la
     * variable. Ce garde-fou existe pour que l'inversion coûte un message
     * d'erreur, et non une base entière.
     */
    private function verifierOrdreDesOperations(bool $appliquer): bool
    {
        $fuseauSession = $this->db()->selectOne('SHOW timezone')->TimeZone;

        if (! $appliquer || $fuseauSession === 'Etc/UTC' || $fuseauSession === 'UTC') {
            return true;
        }

        $this->error('REFUS : la session Postgres est déjà en « ' . $fuseauSession . ' ».');
        $this->error('Le correctif de connexion (DB_TIMEZONE) est donc déjà actif, et');
        $this->error('l’application écrit désormais des horodatages JUSTES — à la seconde');
        $this->error('pleine, comme les anciens. Plus rien ne les distingue : appliquer la');
        $this->error('reprise maintenant décalerait aussi les lignes correctes.');
        $this->newLine();
        $this->error('Retirer DB_TIMEZONE, redémarrer, reprendre les données, puis le remettre.');

        return false;
    }

    /**
     * @param  array<int,string>  $filtre
     * @return array<string,array<int,string>>
     */
    private function colonnesACorriger(array $filtre): array
    {
        $lignes = $this->db()->select(
            "SELECT c.table_name, c.column_name
               FROM information_schema.columns c
               JOIN information_schema.tables t
                 ON t.table_schema = c.table_schema
                AND t.table_name = c.table_name
              WHERE c.table_schema = 'public'
                AND t.table_type = 'BASE TABLE'
                AND c.data_type = 'timestamp with time zone'
              ORDER BY c.table_name, c.column_name",
        );

        $cibles = [];

        foreach ($lignes as $ligne) {
            if (in_array($ligne->table_name, self::TABLES_ECARTEES, true)) {
                continue;
            }
            if ($filtre !== [] && ! in_array($ligne->table_name, $filtre, true)) {
                continue;
            }
            $cibles[$ligne->table_name][] = $ligne->column_name;
        }

        return $cibles;
    }

    private function clePrimaireSimple(string $table): ?string
    {
        $lignes = $this->db()->select(
            'SELECT a.attname
               FROM pg_index i
               JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
              WHERE i.indrelid = ?::regclass AND i.indisprimary',
            ['public.' . $table],
        );

        return count($lignes) === 1 ? $lignes[0]->attname : null;
    }

    /** @param  array<int,string>  $colonnes */
    private function compter(string $table, array $colonnes): int
    {
        $ou = implode(' OR ', array_map(
            fn (string $c) => sprintf('(%s IS NOT NULL AND %s)', $this->q($c), $this->estEcritParLApplication($c)),
            $colonnes,
        ));

        return (int) $this->db()->selectOne("SELECT count(*) AS n FROM {$this->q($table)} WHERE {$ou}")->n;
    }

    /** @param  array<int,string>  $colonnes */
    private function appliquerSurTable(string $table, string $cle, array $colonnes): void
    {
        $taille = max(1000, (int) $this->option('taille'));

        // Chaque colonne décide POUR ELLE-MÊME, dans un seul UPDATE : la ligne
        // reste cohérente, et `updated_at` n'est jamais corrigé séparément de
        // `created_at`.
        $set = implode(', ', array_map(
            fn (string $c) => sprintf(
                '%s = CASE WHEN %s IS NOT NULL AND %s THEN (%s AT TIME ZONE \'UTC\') AT TIME ZONE \'Europe/Paris\' ELSE %s END',
                $this->q($c),
                $this->q($c),
                $this->estEcritParLApplication($c),
                $this->q($c),
                $this->q($c),
            ),
            $colonnes,
        ));

        $avant = (int) $this->db()->selectOne("SELECT count(*) AS n FROM {$this->q($table)}")->n;

        $declencheurs = $this->declencheursUtilisateur($table);

        $this->db()->transaction(function () use ($table, $cle, $set, $taille, $avant, $declencheurs) {
            // Suspension DANS la transaction : une annulation les rétablit
            // d'office, il n'existe donc aucun chemin où la table ressort sans
            // ses déclencheurs. Sans cette suspension, `trg_set_updated_at`
            // écraserait la date de modification de chaque ligne reprise, et le
            // recalcul de score partirait sur des millions de fiches.
            if ($declencheurs !== []) {
                $this->db()->statement("ALTER TABLE {$this->q($table)} DISABLE TRIGGER USER");
                $this->line('    · déclencheurs suspendus : ' . implode(', ', $declencheurs));
            }

            $dernier = null;
            $traitees = 0;

            while (true) {
                $borne = $this->db()->select(
                    sprintf(
                        'SELECT %s AS v FROM %s %s ORDER BY %s LIMIT 1 OFFSET %d',
                        $this->q($cle),
                        $this->q($table),
                        $dernier === null ? '' : sprintf('WHERE %s > ?', $this->q($cle)),
                        $this->q($cle),
                        $taille - 1,
                    ),
                    $dernier === null ? [] : [$dernier],
                );

                $haut = $borne[0]->v ?? null;

                $where = $dernier === null
                    ? ($haut === null ? '1=1' : sprintf('%s <= ?', $this->q($cle)))
                    : ($haut === null
                        ? sprintf('%s > ?', $this->q($cle))
                        : sprintf('%s > ? AND %s <= ?', $this->q($cle), $this->q($cle)));

                $liens = array_values(array_filter([$dernier, $haut], fn ($v) => $v !== null));

                $traitees += $this->db()->update("UPDATE {$this->q($table)} SET {$set} WHERE {$where}", $liens);

                if ($haut === null) {
                    break;
                }
                $dernier = $haut;
            }

            $apres = (int) $this->db()->selectOne("SELECT count(*) AS n FROM {$this->q($table)}")->n;

            // Ceinture ET bretelles : la commande ne supprime rien, mais on
            // refuse de valider une transaction où le compte a bougé.
            if ($apres !== $avant) {
                throw new \RuntimeException(
                    "ANNULATION {$table} : {$avant} lignes avant, {$apres} après. Aucune écriture validée.",
                );
            }

            if ($declencheurs !== []) {
                $this->db()->statement("ALTER TABLE {$this->q($table)} ENABLE TRIGGER USER");
            }

            $this->line("    → {$table} : {$traitees} mises à jour, {$apres} lignes (inchangé).");
        });

        // Contrôle APRÈS commit : les déclencheurs doivent être revenus. Une
        // table qui ressortirait sans les siens serait un dégât silencieux —
        // les `updated_at` cesseraient d'être tenus à jour sans que rien ne le
        // signale.
        if ($declencheurs !== [] && $this->declencheursUtilisateur($table) === []) {
            throw new \RuntimeException(
                "ALERTE {$table} : les déclencheurs n'ont PAS été rétablis. Intervention manuelle requise.",
            );
        }
    }

    /** @return array<int,string> */
    private function declencheursUtilisateur(string $table): array
    {
        $lignes = $this->db()->select(
            'SELECT t.tgname FROM pg_trigger t
              WHERE t.tgrelid = ?::regclass AND NOT t.tgisinternal AND t.tgenabled <> \'D\'
              ORDER BY t.tgname',
            ['public.' . $table],
        );

        return array_map(fn ($l) => $l->tgname, $lignes);
    }

    private function db(): Connection
    {
        return DB::connection((string) $this->option('connexion'));
    }

    private function estEcritParLApplication(string $colonne): string
    {
        return sprintf("date_part('microseconds', %s)::int %% 1000000 = 0", $this->q($colonne));
    }

    private function q(string $identifiant): string
    {
        return '"' . str_replace('"', '""', $identifiant) . '"';
    }
}
