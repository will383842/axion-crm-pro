<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use Throwable;

/**
 * Statistiques du planificateur PostgreSQL maîtrisées, pour les tests qui lisent
 * un plan (`EXPLAIN`) et y cherchent (ou y interdisent) un `Seq Scan`.
 *
 * Le piège : le plan dépend des statistiques de la table. Or elles bougent sans
 * le test — analyse automatique (autovacuum) tombée entre deux mesures, ou
 * `reltuples`/`relpages` laissés par un `ANALYZE` de test précédent, même annulé
 * (ces deux compteurs s'écrivent hors transaction). D'où la méthode :
 *
 *  1. `figer()` coupe l'autovacuum des tables mesurées, le temps du test, et le
 *     rétablit TOUJOURS (`finally`) sans jamais masquer l'erreur du test ;
 *  2. `analyser()` pose les statistiques APRÈS la préparation des données, avant
 *     de comparer les plans ;
 *  3. `rendre()` remet les tables à l'état « sans statistique » que les autres
 *     tests attendent (ex. `EntreprisesVerifierSitesTest`).
 */
final class StatistiquesPlanStables
{
    /**
     * @param  list<string>  $tables
     * @param  ?Connection  $proprietaire  si fourni, `rendre()` y est appelé à la fin (après la restauration)
     */
    public static function figer(Connection $connexion, array $tables, callable $corps, ?Connection $proprietaire = null): mixed
    {
        $erreur = null;
        try {
            self::autovacuum($connexion, $tables, false);

            return $corps();
        } catch (Throwable $e) {
            $erreur = $e;
            throw $e;
        } finally {
            self::sansMasquer($erreur, static fn () => self::autovacuum($connexion, $tables, true));
            if ($proprietaire !== null) {
                self::sansMasquer($erreur, static fn () => self::rendre($proprietaire, $tables));
            }
        }
    }

    /** Coupe (false) ou rétablit (true) l'autovacuum de ces tables. */
    public static function autovacuum(Connection $connexion, array $tables, bool $actif): void
    {
        foreach ($tables as $table) {
            $connexion->statement('ALTER TABLE ' . self::identifiant($table)
                . ($actif ? ' RESET (autovacuum_enabled)' : ' SET (autovacuum_enabled = false)'));
        }
    }

    /** À appeler APRÈS avoir semé les données, AVANT de comparer les plans. */
    public static function analyser(Connection $connexion, array $tables): void
    {
        foreach ($tables as $table) {
            $connexion->statement('ANALYZE ' . self::identifiant($table));
        }
    }

    /**
     * Rend les tables à l'état « vides, sans page morte, SANS statistique ».
     * Le rôle propriétaire est SUPERUSER ; la base est celle des tests. À
     * appeler hors de toute transaction ouverte sur ces tables (`VACUUM`).
     * Les tables sont traitées dans l'ordre donné (enfants d'abord).
     */
    public static function rendre(Connection $proprietaire, array $tables): void
    {
        foreach ($tables as $table) {
            $table = self::identifiant($table);
            $proprietaire->statement('VACUUM ' . $table);
            $proprietaire->statement('DELETE FROM pg_statistic WHERE starelid = ?::regclass', [$table]);
            $proprietaire->statement('UPDATE pg_class SET reltuples = -1, relpages = 0 WHERE oid = ?::regclass', [$table]);
        }
    }

    /** Exécute un nettoyage ; son échec ne remplace pas l'erreur du test déjà en vol. */
    private static function sansMasquer(?Throwable $erreur, callable $nettoyage): void
    {
        try {
            $nettoyage();
        } catch (Throwable $e) {
            if ($erreur === null) {
                throw $e;
            }
        }
    }

    private static function identifiant(string $table): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException('Nom de table refusé : ' . $table);
        }

        return $table;
    }
}
