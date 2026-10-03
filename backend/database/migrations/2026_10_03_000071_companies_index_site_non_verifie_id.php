<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX PARTIEL ORDONNÉ DES SITES NON VÉRIFIÉS (lot N6, 03/10/2026).
 *
 * `crm:entreprises:verifier-sites` lit les fiches au site non vérifié par
 * paquets, dans l'ordre des identifiants, à partir d'un curseur :
 *
 *     WHERE … AND c.id > :curseur AND <SiteFiable::nonVerifieSql('c')>
 *     ORDER BY c.id LIMIT 40
 *
 * L'index de #305 (`idx_companies_site_non_verifie`, sur `workspace_id`)
 * sert le COMPTAGE, pas ce parcours : il ne porte pas `id`, et chaque paquet
 * devrait relire puis trier les ≈ 824 000 entrées. Celui-ci porte le MÊME
 * prédicat (repris mot pour mot de `SiteFiable::nonVerifieSql` — voir
 * l'avertissement de cette classe) sur `id` : chaque paquet lit ~40 entrées,
 * déjà dans l'ordre. Sous `axion_app` (sécurité par espace forcée), les
 * opérateurs JSON ne sont pas « leakproof » : seul un index partiel qui porte
 * déjà la condition évite le balayage. Le test `EntreprisesVerifierSitesTest`
 * lit le plan sous `axion_app`.
 *
 * Taille attendue : ≈ 824 000 entrées × ~20 octets ≈ 18 à 25 Mo. L'index
 * diminue à mesure que les sites sont vérifiés.
 *
 * CONCURRENTLY (hors transaction) : aucun verrou bloquant sur `companies`
 * (4,35 M lignes). Un index INVALIDE laissé par une construction interrompue
 * est reconstruit. Additive : aucune donnée réécrite ni supprimée.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_companies_site_non_verifie_id';

    private const DEFINITION = "companies (id)
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
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};
