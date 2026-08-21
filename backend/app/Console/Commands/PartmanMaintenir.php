<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 🔴 B10-003 (S1) — « le partitionnement d'`audit_logs` n'est entretenu par
 * personne : la retention 24 mois ne s'appliquera JAMAIS et les partitions
 * s'arretent en fevrier 2027 ».
 *
 * ── LE TROU, MESURE LE 2026-08-20 ─────────────────────────────────────────
 *
 * `Dockerfile.postgres:49-51` compile pg_partman avec `make NO_BGW=1` et
 * l'ecrit noir sur blanc :
 *
 *     « pg_partman a un BGW (background worker) optionnel. On le desactive via
 *       NO_BGW=1 build flag, car on n'utilise pas le maintenance worker
 *       (le cron de partition mgmt sera Laravel scheduler). »
 *
 * Ce remplacant n'a jamais ete ecrit. Recherche jouee sur tout le depot :
 * `run_maintenance` n'y apparait QUE dans un runbook d'incident MANUEL
 * (`infra/runbooks/02-disk-full.md`), dans la garde qui relit ce runbook, et
 * dans des rapports. Aucun `Schedule::command`, aucun cron, aucun BGW.
 *
 * Horizon reel lu sur `axion_crm` et `axion_crm_test_a35r` :
 *
 *     audit_logs_p20270201 => FOR VALUES FROM ('2027-02-01') TO ('2027-03-01')
 *
 * C'est la derniere. `premake = 6` ne cree rien tout seul : c'est le nombre de
 * partitions que `run_maintenance` cree D'AVANCE, a chacun de ses passages.
 * Zero passage = zero partition nouvelle.
 *
 * ── CE QUE LE CONSTAT DIT DE TRAVERS, ET POURQUOI C'EST PIRE ──────────────
 *
 * Le constat annonce qu'apres fevrier 2027 « les insertions dans `audit_logs`
 * echouent faute de partition ». MESURE : c'est FAUX, et la verite est moins
 * confortable.
 *
 *     INSERT INTO audit_logs (…, created_at) VALUES (…, '2027-06-15');
 *     -- INSERT 0 1     → tableoid = audit_logs_default
 *
 * La migration `2026_05_17_000011` pose deliberement une partition DEFAULT
 * (« FILET OBLIGATOIRE »). Rien n'echouera donc : les ecritures d'audit
 * tomberont en silence dans le fourre-tout. Or ce silence VERROUILLE la
 * reparation :
 *
 *     CREATE TABLE audit_logs_p20270601 PARTITION OF audit_logs
 *       FOR VALUES FROM ('2027-06-01') TO ('2027-07-01');
 *     -- ERROR: updated partition constraint for default partition
 *     --        "audit_logs_default" would be violated by some row
 *
 * Des qu'UNE ligne d'un mois futur est tombee dans DEFAULT, pg_partman ne peut
 * PLUS creer la partition de ce mois. La panne se rend elle-meme irreparable,
 * sans jamais lever une seule erreur visible. C'est pourquoi cette commande ne
 * se contente pas d'appeler `run_maintenance` : elle VERIFIE, et elle refuse de
 * rendre 0 sur un etat qui n'est plus rattrapable tout seul.
 *
 * ── CE QUE CETTE COMMANDE NE FAIT PAS, ET C'EST VOULU ─────────────────────
 *
 * `part_config` porte `retention = '24 months'` ET `retention_keep_table =
 * true`. Avec ce reglage, `run_maintenance` DETACHE les partitions de plus de
 * 24 mois — il ne les supprime pas : elles restent des tables autonomes, sur le
 * meme disque, toujours lisibles. La retention LEGALE de 24 mois n'est donc pas
 * tenue par le seul fait de planifier cette commande, et cette commande ne
 * bascule PAS `retention_keep_table` a false : effacer definitivement des
 * journaux d'audit est une decision d'exploitant, pas un correctif d'audit.
 * Ce qui est repare ici, c'est que le mecanisme TOURNE et se PLAINT. La
 * commande DIT a chaque passage que les partitions detachees sont conservees,
 * pour que ce reste-a-faire cesse d'etre invisible.
 *
 * Au 2026-08-20 rien n'est detache : la plus vieille partition est 2026-02, et
 * 24 mois en arriere nous mene a 2024-08. Le premier detachement aura lieu en
 * fevrier 2028.
 *
 * ── UNE MECANIQUE QUE PERSONNE N'AVAIT ECRITE : PAS DE DONNEES, PAS DE ────
 *    PARTITIONS
 *
 * `part_config.infinite_time_partitions` vaut `false` (defaut de pg_partman).
 * Sous ce reglage, `run_maintenance` ne cree PAS des partitions en avance du
 * CALENDRIER : il les cree en avance des DONNEES. Mesure jouee le 2026-08-20
 * sur `axion_crm_test_a35r`, partitions futures supprimees au prealable :
 *
 *     -- audit_logs vide (0 ligne)
 *     SELECT partman.run_maintenance('public.audit_logs', p_jobmon := false);
 *     -- horizon : 2026-08-31  → INCHANGE, rien n'a ete cree
 *
 *     INSERT INTO audit_logs (…, created_at) VALUES (…, now());
 *     SELECT partman.run_maintenance('public.audit_logs', p_jobmon := false);
 *     -- horizon : 2027-02-28  → six mois d'un coup (premake = 6)
 *
 * Consequence pratique : en production, ou `audit_logs` recoit des lignes en
 * continu, planifier cette commande repousse bien l'horizon. Mais sur une base
 * ou l'audit s'est TU, pg_partman n'avancera plus — et c'est alors le silence
 * de l'audit qu'il faut traiter, pas les partitions. La commande distingue donc
 * les deux cas au lieu de crier au loup sur une table calme : elle n'exige un
 * horizon que d'une table VIVANTE (une ligne dans les 32 derniers jours).
 *
 * ── LE ROLE QUI EXECUTE ───────────────────────────────────────────────────
 *
 * Mesure faite sur `axion_crm_test_a35r` :
 *
 *     psql -U axion_app -c "SELECT partman.run_maintenance('public.audit_logs', p_jobmon := false);"
 *     ERROR:  permission denied for schema partman
 *
 * Aujourd'hui `CRM_DB_APP_ROLE_ENABLED` vaut false (`.env.example:76`,
 * `backend/.env:51`) : la connexion par defaut porte `axion`, proprietaire, et
 * la maintenance passe. Le jour ou ce drapeau sera arme, cette commande
 * mourra a chaque passage — exactement comme `coverage:refresh-matrix` a
 * echoue 71 fois sur 71 (A08-001). La reparation durable est la meme que
 * celle d'A08-001 : une fonction `SECURITY DEFINER` appartenant au
 * proprietaire, avec EXECUTE accorde au role applicatif. Elle demande une
 * MIGRATION, qui est hors du perimetre de ce lot : la commande se contente
 * donc de nommer ce manque, fort, dans son message d'echec.
 */
class PartmanMaintenir extends Command
{
    /**
     * Le nom sous lequel la tache est planifiee. Constante plutot que chaine
     * libre : la garde cherche EXACTEMENT ce que le planificateur appelle, donc
     * un renommage de la commande fait rougir la garde au lieu de la laisser
     * verifier une ligne qui n'existe plus.
     */
    public const SIGNATURE_PLANIFIEE = 'db:partman-maintenir';

    /** Prefixe cherche par la garde et par l'exploitation dans les journaux. */
    public const PREFIXE_ALERTE = '[PARTITIONS] maintenance pg_partman EN ECHEC';

    protected $signature = self::SIGNATURE_PLANIFIEE . '
        {--table=public.audit_logs : table parente a entretenir}
        {--horizon-min=3 : nombre de mois de partitions a venir exiges APRES maintenance}';

    protected $description = 'Entretient les partitions pg_partman (cree les partitions a venir, applique la retention). Remplace le BGW desactive au build.';

    public function handle(): int
    {
        $table = (string) $this->option('table');
        $horizonMin = max(1, (int) $this->option('horizon-min'));

        // Les identifiants partent dans du SQL construit : on les borne. Ils
        // viennent d'une option, donc d'un humain ou d'une ligne planifiee.
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}\.[a-z_][a-z0-9_]{0,62}$/', $table)) {
            $this->error("Nom de table parente invalide : « {$table} ». Format attendu : schema.table.");

            return self::FAILURE;
        }

        try {
            return $this->entretenir($table, $horizonMin);
        } catch (QueryException $e) {
            // Le cas le plus probable ici est le refus de droits decrit en
            // en-tete. On le nomme explicitement plutot que de recracher un
            // SQLSTATE : c'est la difference entre une alerte actionnable et un
            // bruit qu'on finit par ignorer.
            $message = self::PREFIXE_ALERTE . " sur « {$table} » : " . $e->getMessage();

            if (str_contains($e->getMessage(), 'permission denied for schema')) {
                $message .= ' — le role de connexion n a pas USAGE sur le schema de pg_partman. '
                    . 'Reparation durable (hors perimetre du lot B10-003, elle demande une migration) : '
                    . 'une fonction SECURITY DEFINER appartenant au proprietaire, avec EXECUTE accorde au '
                    . 'role applicatif, sur le modele de public.rafraichir_matrice_couverture() (A08-001).';
            }

            return $this->avouer($message);
        } catch (Throwable $e) {
            return $this->avouer(self::PREFIXE_ALERTE . " sur « {$table} » : " . $e->getMessage());
        }
    }

    private function entretenir(string $table, int $horizonMin): int
    {
        $partitionnee = (bool) DB::scalar(
            'SELECT EXISTS (
                SELECT 1 FROM pg_partitioned_table p
                JOIN pg_class c ON c.oid = p.partrelid
                WHERE c.oid = ?::regclass
            )',
            [$table],
        );

        $schemaPartman = DB::scalar(
            "SELECT n.nspname FROM pg_extension e
             JOIN pg_namespace n ON n.oid = e.extnamespace
             WHERE e.extname = 'pg_partman'",
        );

        // Une base sans pg_partman ET sans table partitionnee n'a rien a
        // entretenir : c'est le cas d'un poste de developpement monte sur une
        // image Postgres nue. Ce n'est pas un defaut, et faire rougir le
        // planificateur pour cela apprendrait a ignorer ses alertes.
        if (! $partitionnee && $schemaPartman === null) {
            $this->info("« {$table} » n est pas partitionnee et pg_partman est absent : rien a entretenir.");

            return self::SUCCESS;
        }

        // En revanche, une table PARTITIONNEE sans l'extension qui la gere,
        // c'est le trou de B10-003 dans sa forme la plus nue.
        if ($partitionnee && $schemaPartman === null) {
            return $this->avouer(
                self::PREFIXE_ALERTE . " : « {$table} » est PARTITIONNEE mais pg_partman est absent de "
                . 'cette base. Aucune partition a venir ne sera creee et aucune retention ne sera appliquee.',
            );
        }

        if (! $partitionnee) {
            $this->info("« {$table} » n est pas partitionnee : rien a entretenir.");

            return self::SUCCESS;
        }

        // `part_config` vit dans le schema de l'extension, dont le nom a change
        // au fil du depot (`public` avant `2026_08_18_100001`, `partman`
        // depuis). On le resout, on ne le suppose pas.
        $config = DB::selectOne(
            sprintf(
                'SELECT partition_interval, premake, retention, retention_keep_table, control
                 FROM %s.part_config WHERE parent_table = ?',
                $this->identifiant((string) $schemaPartman),
            ),
            [$table],
        );

        if ($config === null) {
            return $this->avouer(
                self::PREFIXE_ALERTE . " : « {$table} » est partitionnee mais ABSENTE de "
                . "{$schemaPartman}.part_config — pg_partman ne la gere pas. run_maintenance n y ferait "
                . 'RIEN, en rendant un succes. C est exactement l etat que laisse le bloc EXCEPTION de la '
                . 'migration 2026_05_17_000011 quand create_parent() echoue : la table retombe sur sa seule '
                . 'partition DEFAULT et plus personne ne cree de partition a venir.',
            );
        }

        $horizonAvant = $this->horizon($table);
        $this->line('Horizon avant maintenance : ' . ($horizonAvant ?? 'AUCUNE partition datee'));

        // ── Le verrou silencieux. On le cherche AVANT d'appeler la maintenance,
        // parce que `run_maintenance` echouerait dessus de facon beaucoup moins
        // lisible (un message sur une partition precise, noye dans un SQLSTATE).
        $poison = $this->lignesFuturesDansDefault($table, (string) $config->control, $horizonAvant);

        if ($poison !== null && $poison['lignes'] > 0) {
            return $this->avouer(
                self::PREFIXE_ALERTE . " : {$poison['lignes']} ligne(s) posterieure(s) a l horizon "
                . "({$horizonAvant}) sont deja tombees dans la partition fourre-tout « {$poison['table']} ». "
                . 'PostgreSQL refuse desormais de creer les partitions de ces mois-la '
                . '(« updated partition constraint for default partition would be violated by some row ») : '
                . 'pg_partman ne peut plus rattraper cet etat seul. Il faut detacher la partition DEFAULT, '
                . 'creer les partitions manquantes, puis y reinjecter ces lignes.',
            );
        }

        // ── Le geste lui-meme.
        DB::select(
            sprintf('SELECT %s.run_maintenance(p_parent_table := ?, p_jobmon := false)', $this->identifiant((string) $schemaPartman)),
            [$table],
        );

        $horizonApres = $this->horizon($table);

        if ($horizonApres === null) {
            return $this->avouer(
                self::PREFIXE_ALERTE . " : apres maintenance, « {$table} » ne porte AUCUNE partition datee.",
            );
        }

        // ── Exiger un horizon d'une table QUI N'ECRIT PLUS serait un faux
        // positif quotidien : `infinite_time_partitions = false` fait que
        // pg_partman avance en fonction des DONNEES, pas de l'horloge (mesure
        // en en-tete). On ne demande donc un horizon qu'a une table vivante.
        // `EXISTS … LIMIT 1` et non `max(control)` : l'elagage de partitions
        // borne la lecture aux deux dernieres partitions et s'arrete a la
        // premiere ligne — `max()` scannerait toute la table d'audit.
        $vivante = (bool) DB::scalar(
            sprintf(
                'SELECT EXISTS (SELECT 1 FROM %s WHERE %s >= now() - INTERVAL \'32 days\' LIMIT 1)',
                $table,
                $this->identifiant((string) $config->control),
            ),
        );

        if (! $vivante) {
            $this->warn(
                "  « {$table} » n a recu AUCUNE ligne depuis 32 jours. pg_partman n avance pas les "
                . 'partitions d une table sans donnees (infinite_time_partitions = false, mesure du '
                . "2026-08-20) : l horizon reste a {$horizonApres}. Ce n est pas un defaut de "
                . 'partitionnement — c est l audit qui s est taise, et c est cela qu il faut regarder.',
            );

            return self::SUCCESS;
        }

        // La maintenance a pu « reussir » sans rien creer (configuration
        // incoherente, intervalle nul, erreur avalee cote extension). On ne
        // croit pas le succes sur parole : on mesure le resultat.
        $plancher = strtotime("+{$horizonMin} months");

        if (strtotime($horizonApres) < $plancher) {
            return $this->avouer(
                self::PREFIXE_ALERTE . " : apres maintenance, les partitions de « {$table} » ne vont "
                . "que jusqu au {$horizonApres}, soit moins de {$horizonMin} mois d avance. "
                . "Reglage lu : premake={$config->premake}, intervalle={$config->partition_interval}.",
            );
        }

        $this->info("Horizon apres maintenance : {$horizonApres} (premake={$config->premake}, intervalle={$config->partition_interval}).");

        // ── Le reste-a-faire, dit a chaque passage plutot que decouvert en 2028.
        if ($config->retention === null) {
            $this->warn("  Aucune retention configuree sur « {$table} » : les partitions s accumulent sans fin.");
        } elseif ((bool) $config->retention_keep_table) {
            $this->warn(
                "  Retention = {$config->retention}, mais retention_keep_table = true : les partitions "
                . 'expirees sont DETACHEES, pas supprimees. Elles restent des tables autonomes, sur le meme '
                . 'disque, toujours lisibles — la retention legale n est donc PAS tenue par ce seul reglage. '
                . 'Basculer ce drapeau efface definitivement des journaux d audit : c est une decision '
                . 'd exploitant, elle n est pas prise ici.',
            );
        }

        return self::SUCCESS;
    }

    /**
     * Borne haute la plus lointaine couverte par une VRAIE partition. La
     * partition DEFAULT est exclue : elle ne couvre aucune periode, elle
     * ramasse ce qui n'a pas de place — la compter comme un horizon rendrait
     * cette commande aveugle a exactement ce qu'elle surveille.
     */
    private function horizon(string $table): ?string
    {
        return DB::scalar(
            'SELECT max((regexp_match(pg_get_expr(c.relpartbound, c.oid), \'TO \(\'\'([^\'\']+)\'\'\)\'))[1]::timestamptz)
             FROM   pg_class c
             JOIN   pg_inherits i ON i.inhrelid = c.oid
             WHERE  i.inhparent = ?::regclass
               AND  pg_get_expr(c.relpartbound, c.oid) <> \'DEFAULT\'',
            [$table],
        );
    }

    /**
     * Compte les lignes deja tombees dans la partition fourre-tout dont la date
     * depasse l'horizon — celles qui interdisent la creation de leur partition.
     *
     * @return array{table: string, lignes: int}|null
     */
    private function lignesFuturesDansDefault(string $table, string $control, ?string $horizon): ?array
    {
        if ($horizon === null) {
            return null;
        }

        $defaut = DB::scalar(
            'SELECT c.relname FROM pg_class c
             JOIN pg_inherits i ON i.inhrelid = c.oid
             WHERE i.inhparent = ?::regclass
               AND pg_get_expr(c.relpartbound, c.oid) = \'DEFAULT\'',
            [$table],
        );

        if ($defaut === null) {
            return null;
        }

        // `control` vient de part_config, `defaut` du catalogue : deux sources
        // internes, mais elles finissent dans du SQL construit. On les borne
        // quand meme — un nom d'objet PostgreSQL peut contenir a peu pres tout
        // si on le cite.
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', (string) $defaut)
            || ! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $control)) {
            return null;
        }

        $lignes = (int) DB::scalar(
            sprintf('SELECT count(*) FROM %s WHERE %s >= ?', $this->identifiant((string) $defaut), $this->identifiant($control)),
            [$horizon],
        );

        return ['table' => (string) $defaut, 'lignes' => $lignes];
    }

    /** Cite un identifiant deja valide par expression reguliere. */
    private function identifiant(string $nom): string
    {
        return '"' . str_replace('"', '""', $nom) . '"';
    }

    /**
     * Deux sorties, parce qu'elles n'ont pas le meme lecteur : `error()` pour
     * l'humain qui lance la commande a la main, `Log::critical` pour la tache
     * planifiee, dont la sortie console ne va NULLE PART. C'est le patron
     * etabli par A08-001 : le planificateur de Laravel n'interprete pas le code
     * de sortie d'une commande planifiee.
     */
    private function avouer(string $message): int
    {
        $this->error($message);
        Log::critical($message);

        return self::FAILURE;
    }
}
