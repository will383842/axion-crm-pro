<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX PARTIEL des fiches au site DEVINÉ et NON VÉRIFIÉ (lot N4 « fermer le
 * robinet », 03/10/2026), au service de `App\Crm\Sites\SiteFiable`.
 *
 * ── Pourquoi ──────────────────────────────────────────────────────────────
 * 824 306 fiches portent un site deviné (`website_method` `guess` / `guess2`),
 * souvent faux. La règle « non vérifié » (`SiteFiable::nonVerifieSql`) lit
 * `website_method` ET le marqueur JSON `metadata.site_entreprise.statut`. Les
 * opérateurs JSON ne sont pas « leakproof » : sous la sécurité par espace
 * forcée (`axion_app`), Postgres ne peut les évaluer qu'après la politique,
 * ligne à ligne, sur le tas — les 4,35 M de fiches (9,3 Go) relues à chaque
 * comptage. Même constat que `2026_10_03_000050` (Google Places en attente).
 *
 * Un index PARTIEL dont le prédicat reprend la condition MOT POUR MOT ne porte
 * que les fiches non vérifiées : le planificateur reconnaît le prédicat dans
 * la requête et compte sur l'index seul. `deleted_at IS NULL` : les fiches à
 * la corbeille ne comptent jamais. La colonne `workspace_id` sert le filtre
 * par espace (explicite dans la requête, et posé par la politique).
 *
 * ⚠️ Le prédicat doit rester IDENTIQUE à `SiteFiable::nonVerifieSql()` : une
 * condition réécrite autrement ne serait plus reconnue et l'index ne
 * servirait plus — sans aucune erreur. `SitesDevinesNonVerifiesTest` lit le
 * plan sous `axion_app` et rougit dans ce cas.
 *
 * ── Taille estimée ────────────────────────────────────────────────────────
 * Au plus 824 306 entrées aujourd'hui, une clé `uuid` (16 octets) : sans
 * déduplication, ~28 octets par entrée (en-tête 8 + clé 16 + pointeur 4),
 * soit ≈ 23 Mo, ≈ 26 Mo au remplissage de 90 % — le PIRE cas. Postgres 16
 * déduplique les clés égales d'un B-tree (`deduplicate_items` actif par
 * défaut, l'`uuid` y est éligible) ; il n'y a que quelques espaces, donc
 * quelques valeurs de clé : les entrées se regroupent en listes de
 * pointeurs de 6 octets, ≈ 5 à 8 Mo attendus. L'index RÉTRÉCIT à mesure que
 * le futur lot de vérification pose `site_entreprise.statut = verifie`. Le
 * tri de construction (~25 Mo) tient en `maintenance_work_mem`. Rien à voir
 * avec les 15 Go libres du disque. À mesurer après coup :
 * `SELECT pg_size_pretty(pg_relation_size('idx_companies_site_non_verifie'))`.
 *
 * ── Coût en production ────────────────────────────────────────────────────
 * `CONCURRENTLY`, hors transaction (`$withinTransaction = false`) : aucune
 * écriture bloquée, seul un verrou SHARE UPDATE EXCLUSIVE est pris. Deux
 * passages sur la table, quelques minutes au plus. `lock_timeout` borne
 * l'attente du verrou initial. Un index laissé INVALIDE par une construction
 * interrompue est retiré puis reconstruit (patron de `2026_10_02_000020`).
 * Nom vérifié libre dans le dépôt le 2026-10-03.
 *
 * PUREMENT ADDITIVE : aucune ligne n'est réécrite. `down()` retire l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_companies_site_non_verifie';

    private const DEFINITION = "companies (workspace_id)
        WHERE website_method LIKE 'guess%'
          AND COALESCE(metadata -> 'site_entreprise' ->> 'statut', '') NOT IN ('verifie', 'trouve-verifie')
          AND deleted_at IS NULL";

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        DB::statement("SET lock_timeout = '30s'");
        try {
            $invalide = DB::selectOne(
                'SELECT NOT i.indisvalid AS invalide FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
                [self::NOM],
            );
            if ($invalide !== null && (bool) $invalide->invalide) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::NOM . ' ON ' . self::DEFINITION);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
    }
};
