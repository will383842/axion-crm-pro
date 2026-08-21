<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `health_practitioners` — L'INDEX SUR L'E-MAIL QUI MANQUAIT (constat `C21-001`).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CET INDEX, ET POURQUOI SUR CETTE TABLE-LÀ EN PRIORITÉ
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `health_practitioners` porte `nom`, `prenom`, `specialite`, `rpps`,
 * `address` : **donnée de santé, RGPD art. 9, catégorie particulière**. Trois
 * chemins la cherchent par adresse, et un seul d'entre eux est un confort —
 * les deux autres sont des DROITS de la personne, avec un délai légal :
 *
 *   - `SiteGdprService::erase()`          → art. 17, effacement (workspace + email)
 *   - `GdprErasureService::erase()`       → art. 17, effacement (email seul)
 *   - `GdprPortabilityService::export()`  → art. 15, accès      (email seul)
 *
 * Aucun index n'existait sur `email`. Mesure du 2026-08-20 sur le banc `a35r`,
 * 20 000 lignes (`EXPLAIN` du SQL RÉELLEMENT émis par le service, intercepté
 * par `DB::listen` — cf. la garde citée plus bas) :
 *
 *     AVANT ...  Seq Scan on health_practitioners  (cost=0.00..1043.00 rows=1)
 *     APRÈS ...  Index Scan using idx_health_practitioners_email
 *                                                  (cost=0.29..8.31 rows=1)
 *
 * Le coût estimé passe de 1 043 à 8,31 unités — un rapport de 125, et il
 * grandit linéairement avec la table pendant que celui de l'index reste
 * logarithmique.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA FORME : `(email)` SEUL, ET PARTIEL — PAS `(workspace_id, email)`
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Un composé `(workspace_id, email)` servirait `SiteGdprService`, et **pas**
 * les deux autres appelants, qui cherchent par e-mail SANS borne de workspace :
 * la colonne de tête d'un index B-tree ne se saute pas. Un index sur `email`
 * seul sert les TROIS — la borne `workspace_id` devient un filtre sur les une
 * ou deux lignes remontées, ce qui ne coûte rien.
 *
 * Partiel sur `email IS NOT NULL` : un praticien importé du RPPS n'a
 * généralement pas d'adresse, et une recherche par e-mail ne cherche jamais
 * `NULL`. L'index ne porte donc que les lignes utiles.
 *
 * C'est EXACTEMENT la forme des deux index jumeaux déjà en place — et c'est
 * délibéré, on étend, on ne réinvente pas :
 *
 *     idx_contacts_email    btree (email) WHERE email IS NOT NULL
 *     idx_candidates_email  btree (email) WHERE email IS NOT NULL
 *
 * D'où le nom `idx_health_practitioners_email`, aligné sur ces jumeaux plutôt
 * que sur le préfixe `hp_*` des autres index de cette table : le nom dit à quoi
 * l'index sert, et sa famille est celle des index d'e-mail.
 *
 * ⚠️ VÉRIFICATION DE COLLISION DE NOM, AVANT ÉCRITURE (règle héritée de
 * `G41-003`). Un `CREATE INDEX IF NOT EXISTS` sur un nom DÉJÀ PRIS par un index
 * sur une AUTRE colonne est un **silence**, pas une idempotence : la migration
 * se déclare passée et l'index attendu n'existe pas. Mesuré avant d'écrire ce
 * fichier, sur `axion_crm_test_lot5` :
 *
 *     SELECT count(*) FROM pg_class WHERE relname = 'idx_health_practitioners_email';
 *     → 0
 *
 * Les index existants de la table sont `health_practitioners_pkey`,
 * `hp_workspace_idx`, `hp_workspace_siren_idx`, `hp_workspace_specialite_idx`,
 * `hp_company_idx`, `hp_workspace_rpps_uidx`. Aucune collision.
 *
 * Additif et réversible : aucune donnée touchée, `down()` rend l'état d'avant.
 * `CONCURRENTLY` : pas de verrou d'écriture sur une table que l'import RPPS
 * alimente par lots.
 *
 * Garde : `tests/Feature/Infra/IndexEmailRgpdServentLesRequetesTest.php`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'idx_health_practitioners_email';

    private const SQL = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . '
        ON health_practitioners (email)
        WHERE email IS NOT NULL';

    public function up(): void
    {
        // Un CONCURRENTLY interrompu laisse un index `indisvalid = false` que
        // `IF NOT EXISTS` tient pourtant pour existant : sans ce ménage, une
        // migration rejouée « réussirait » en laissant un index inutilisable.
        // (Idiome posé par `2026_08_15_000002` / `2026_08_17_000001`, repris à
        // l'identique.)
        $invalid = DB::select(
            'SELECT c.relname AS name
               FROM pg_class c
               JOIN pg_index i ON i.indexrelid = c.oid
              WHERE c.relname = ?
                AND NOT i.indisvalid',
            [self::INDEX],
        );

        foreach ($invalid as $row) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $row->name);
        }

        DB::statement(self::SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
    }
};
