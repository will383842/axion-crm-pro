<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * SOURCE DE VÉRITÉ UNIQUE de l'inventaire des tables scopées par workspace.
 *
 * ── Pourquoi cette classe existe ────────────────────────────────────────────
 *
 * Avant elle, la liste des tables à protéger existait en DEUX exemplaires
 * recopiés à la main :
 *
 *   1. `database/migrations/2026_08_14_000001_harden_workspace_isolation.php`
 *      — qui calcule sa liste dynamiquement, mais retire quatre noms :
 *        `sessions`, `user_workspaces`, `audit_logs`, `audit_logs_default` ;
 *   2. `tests/Feature/RlsTest.php` (test structurel « les tables scopées
 *      portent FORCE ROW LEVEL SECURITY ») — qui n'en retirait que TROIS :
 *        `sessions`, `user_workspaces`, `audit_logs_default`.
 *
 * Deux listes qui divergent d'un nom, c'est déjà une liste de trop. La
 * divergence n'était pas visible parce que `audit_logs` est une table
 * PARTITIONNÉE (`relkind = 'p'`) : le scan filtre `relkind = 'r'`, donc elle
 * n'apparaissait jamais — l'exclusion était TACITE, et une exclusion tacite est
 * indistinguable d'un oubli. Le jour où `audit_logs` cesserait d'être
 * partitionnée, le test structurel se mettrait à exiger d'elle ce que la
 * migration ne lui donne pas, et personne ne comprendrait pourquoi.
 *
 * Ici, tout part du MÊME scan et des MÊMES constantes. `EtancheiteParTableTest`
 * vérifie en plus que les constantes de cette classe et celles de la migration
 * disent la même chose (`Tests\Feature\EtancheiteParTableTest`, test
 * « les exclusions … »), ce qui rend la recopie impossible à faire dériver en
 * silence.
 */
final class EtancheiteWorkspace
{
    /**
     * Chemin de la migration de durcissement, relatif à `database/`.
     */
    public const MIGRATION_DURCISSEMENT = 'migrations/2026_08_14_000001_harden_workspace_isolation.php';

    /**
     * Tables qui portent `workspace_id` mais que l'on N'ISOLE PAS, avec le motif.
     *
     * ⚠️ Ces motifs ne sont pas décoratifs : une table absente d'un test sans
     * justification écrite est un oubli qu'on ne peut plus distinguer d'une
     * décision. Les deux entrées ci-dessous SONT des décisions.
     *
     * @var array<string, string>
     */
    public const EXCLUSIONS_MOTIVEES = [
        'sessions' => "Infrastructure d'authentification : une session est lue AVANT qu'un "
            . "workspace courant existe. L'isoler rendrait toute connexion impossible.",
        'user_workspaces' => "C'est la table qui DIT à quel workspace on appartient. La scoper sur "
            . 'le workspace courant est un problème de la poule et de l’œuf : on ne pourrait plus '
            . 'changer de workspace.',
    ];

    /**
     * Relations qui portent `workspace_id` mais que le scan ne voit PAS, et
     * pourquoi. Le test d'inventaire vérifie que chacune est bien dans l'état
     * annoncé : si `audit_logs` redevient une table ordinaire, ou si
     * `coverage_matrix_cells` cesse d'être une vue matérialisée, la garde
     * rougit et force une décision explicite.
     *
     * @var array<string, array{relkind: string, motif: string}>
     */
    public const ABSENTES_PAR_CONSTRUCTION = [
        'audit_logs' => [
            'relkind' => 'p',
            'motif' => "Table PARTITIONNÉE par pg_partman (relkind='p'). Le scan ne retient que "
                . "les tables ordinaires (relkind='r'), donc elle en est absente par construction. "
                . "C'est aussi le motif d'exclusion de la migration de durcissement : RLS + "
                . 'partitions gérées dynamiquement est un piège connu du dépôt (create_parent v4/v5).',
        ],
        'audit_logs_default' => [
            'relkind' => 'r',
            'motif' => "Partition ENFANT d'audit_logs (relispartition = true). Écartée par la clause "
                . '`NOT c.relispartition` du scan, pas par son nom.',
        ],
        'coverage_matrix_cells' => [
            'relkind' => 'm',
            'motif' => "VUE MATÉRIALISÉE (relkind='m'). Une vue matérialisée ne porte pas de policy : "
                . "elle est rafraîchie par le propriétaire et hérite de ce qu'il a le droit de lire. "
                . 'Son étanchéité se juge sur les tables sources, pas sur elle.',
        ],
    ];

    /**
     * Tables de RÉFÉRENCE où `workspace_id IS NULL` signifie légitimement
     * « ligne globale, partagée par tous les workspaces ».
     *
     * Pour elles — et pour elles seules — la policy conserve la branche
     * `workspace_id IS NULL`. Conséquence directe sur le critère « sans
     * contexte → 0 ligne » : il ne peut pas s'appliquer tel quel, puisque les
     * lignes globales restent visibles PAR CONSTRUCTION. La forme correcte du
     * critère est donc : « sans contexte, aucune ligne RATTACHÉE à un workspace
     * n'est visible ». C'est ce que contrôlent les tests, et c'est écrit ici
     * plutôt que d'être une exception silencieuse dans une boucle.
     *
     * ⚠️ Cette liste doit être IDENTIQUE à `GLOBAL_ROW_TABLES` de la migration
     * de durcissement ; `EtancheiteParTableTest` le vérifie.
     *
     * @var array<string, string>
     */
    public const LIGNES_GLOBALES = [
        'llm_use_cases' => 'Catalogue des cas d’usage LLM : 10 lignes globales en production, '
            . 'partagées par tous les workspaces. Sa policy garde la branche `workspace_id IS NULL`, '
            . 'jamais la branche `current_setting(...) IS NULL` — ce n’est donc PAS un repli permissif.',
    ];

    /**
     * 🔴 DÉFAUTS CONNUS, MESURÉS, NON CORRIGÉS — et pourquoi ils sont ici.
     *
     * Une table listée ici est RETIRÉE des contrôles d'étanchéité par table…
     * mais `EtancheiteParTableTest` porte un test qui EXIGE que le défaut soit
     * encore là. Le jour où quelqu'un le corrige, c'est CE test qui rougit,
     * avec pour seule façon de le calmer : retirer la table d'ici. Une
     * dérogation qui ne se périme pas toute seule finit par devenir la règle.
     *
     * ── HISTORIQUE : la dérogation s'est bien périmée ───────────────────────
     *
     * Cette liste a contenu `email_verification_logs` du 2026-08-18 au
     * 2026-08-20 (constat A07-002). Sans contexte workspace, la table rendait
     * TOUTES les lignes de TOUS les espaces : sa policy d'origine
     * `email_verif_workspace_isolation` (migration 2026_05_19_000001) portait
     * un repli permissif écrit en `COALESCE(NULLIF(current_setting(...), ''),
     * workspace_id::text)` — toujours vrai sans contexte —, et son nom
     * RACCOURCI l'avait fait échapper au `DROP POLICY IF EXISTS
     * <table>_workspace_isolation` du durcissement L0.
     *
     * Elle a été supprimée le 2026-08-20 par
     * `2026_08_20_100000_supprimer_policy_permissive_survivante_email_verification_logs.php`.
     * La table est depuis dans le contrôle GÉNÉRAL, comme toutes les autres.
     * Le mécanisme a fonctionné exactement comme prévu : c'est le test
     * « DÉFAUT CONNU » qui a rougi le jour de la correction.
     *
     * @var array<string, string>
     */
    public const DEFAUTS_CONNUS = [];

    /**
     * Inventaire BRUT : toute relation du schéma courant portant `workspace_id`,
     * quel que soit son `relkind`. C'est le scan qui décide, jamais une liste.
     *
     * @return list<object{name: string, relkind: string, relispartition: bool, relrowsecurity: bool, relforcerowsecurity: bool}>
     */
    public static function inventaireBrut(Connection $connexion): array
    {
        /** @var list<object{name: string, relkind: string, relispartition: bool, relrowsecurity: bool, relforcerowsecurity: bool}> $rows */
        $rows = $connexion->select(
            "SELECT c.relname AS name,
                    c.relkind::text AS relkind,
                    c.relispartition,
                    c.relrowsecurity,
                    c.relforcerowsecurity
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_attribute a ON a.attrelid = c.oid
                                  AND a.attname = 'workspace_id'
                                  AND a.attnum > 0
                                  AND NOT a.attisdropped
              WHERE n.nspname = current_schema()
              ORDER BY c.relname",
        );

        return $rows;
    }

    /**
     * Tables ORDINAIRES (hors partitions, hors vues matérialisées) portant
     * `workspace_id` — c'est-à-dire exactement le périmètre que la migration de
     * durcissement calcule, exclusions comprises.
     *
     * @return list<string>
     */
    public static function tablesScopees(Connection $connexion): array
    {
        $tables = [];
        foreach (self::inventaireBrut($connexion) as $relation) {
            if ($relation->relkind !== 'r' || $relation->relispartition) {
                continue;
            }
            if (array_key_exists($relation->name, self::EXCLUSIONS_MOTIVEES)) {
                continue;
            }
            $tables[] = $relation->name;
        }

        if ($tables === []) {
            throw new RuntimeException(
                "Aucune table scopée trouvée : le scan est cassé, ou la base n'est pas migrée. "
                . 'Un inventaire vide ferait passer TOUS les contrôles par vacuité.',
            );
        }

        return $tables;
    }

    /**
     * Périmètre réellement contrôlé par les tests d'étanchéité par table :
     * les tables scopées, MOINS les défauts connus (cf. DEFAUTS_CONNUS).
     *
     * @return list<string>
     */
    public static function tablesControlees(Connection $connexion): array
    {
        return array_values(array_filter(
            self::tablesScopees($connexion),
            static fn (string $table): bool => ! array_key_exists($table, self::DEFAUTS_CONNUS),
        ));
    }

    /**
     * Liste d'exclusion DÉCLARÉE PAR LA MIGRATION, lue dans son source.
     *
     * On la lit plutôt que de la recopier : c'est la seule façon qu'une
     * divergence entre la migration et les tests ne puisse pas s'installer en
     * silence. La constante est `private` (donc invisible par réflexion sur une
     * classe anonyme non instanciée) : on lit le fichier.
     *
     * @return list<string>
     */
    public static function exclusionsDeLaMigration(): array
    {
        return self::constanteDeLaMigration('EXCLUDED_TABLES');
    }

    /**
     * Tables à lignes globales DÉCLARÉES PAR LA MIGRATION, lues dans son source.
     *
     * @return list<string>
     */
    public static function lignesGlobalesDeLaMigration(): array
    {
        return self::constanteDeLaMigration('GLOBAL_ROW_TABLES');
    }

    /**
     * @return list<string>
     */
    private static function constanteDeLaMigration(string $constante): array
    {
        $chemin = database_path(self::MIGRATION_DURCISSEMENT);
        $source = @file_get_contents($chemin);

        if ($source === false) {
            throw new RuntimeException(
                'Migration de durcissement introuvable : ' . $chemin
                . '. Si elle a été renommée, corriger EtancheiteWorkspace::MIGRATION_DURCISSEMENT — '
                . "et surtout ne pas se contenter de retirer ce contrôle : c'est lui qui empêche "
                . "les deux listes d'exclusion de diverger.",
            );
        }

        if (preg_match('/private const ' . preg_quote($constante, '/') . ' = \[(.*?)\];/s', $source, $bloc) !== 1) {
            throw new RuntimeException(
                "La constante {$constante} n'a pas été retrouvée dans " . $chemin
                . '. Le contrôle de cohérence des listes est aveugle tant que ce n’est pas réparé.',
            );
        }

        preg_match_all("/'([a-z0-9_]+)'/", $bloc[1], $noms);

        /** @var list<string> $liste */
        $liste = $noms[1];
        sort($liste);

        return $liste;
    }
}
