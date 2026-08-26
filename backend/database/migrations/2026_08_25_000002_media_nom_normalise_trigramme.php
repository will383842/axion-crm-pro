<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `media.name_normalized` + index trigramme — sans quoi l'écran de rattachement
 * ne peut pas exister.
 *
 * ── Le calcul qui rend cette migration obligatoire ─────────────────────────
 * Le fichier presse apporte **373 chaînes `media_raw` distinctes** (mesuré sur
 * les 412 contacts : seuls 11 groupes partagent une chaîne). Chacune doit être
 * rapprochée des **55 830 lignes** de `media`.
 *
 * Sans index trigramme, chaque rapprochement est un balayage séquentiel de la
 * table entière : 373 balayages de 55 830 lignes. L'écran mettrait plusieurs
 * secondes par ligne à s'afficher — c'est-à-dire qu'il ne serait pas utilisé,
 * et que les 412 fiches resteraient non rattachées. L'index n'est pas une
 * optimisation ici, c'est la condition d'existence de la fonctionnalité.
 *
 * ── Pourquoi une colonne GÉNÉRÉE plutôt qu'un index sur `name` ────────────
 * On calque `companies.denomination_normalized`, qui porte déjà exactement ce
 * dispositif (`idx_companies_denomination_trgm`). Normaliser LES DEUX CÔTÉS est
 * ce qui fait marcher le rapprochement : « Le Figaro » et « LE FIGARO », « TF1
 * Séries Films » et « TF1 SERIES FILMS » ne se ressemblent pas assez en
 * trigrammes bruts pour franchir un seuil raisonnable, alors qu'ils sont le
 * même média — et la base contient DÉJÀ ces paires (« TF1 SERIES FILMS » /
 * « TF1 Séries Films », « TF1 » en double, « 01net » sous quatre formes).
 *
 * Un index fonctionnel `ON media (normalize_name(name) gin_trgm_ops)` aurait
 * marché aussi, mais la colonne stockée se lit dans un `SELECT`, se compare à
 * l'œil pendant un arbitrage, et sert au regroupement des doublons internes de
 * `media` — un chantier qui viendra, et qui aura besoin de la voir.
 *
 * ── Coût en production ────────────────────────────────────────────────────
 * `ADD COLUMN ... GENERATED ... STORED` réécrit la table : 55 830 lignes, donc
 * quelques secondes et un verrou ACCESS EXCLUSIVE sur `media` pendant ce temps.
 * Aucune écriture concurrente n'est attendue sur cette table (elle n'est
 * alimentée que par des commandes d'import lancées à la main), mais mieux vaut
 * ne pas jouer cette migration pendant un `media:enrich`.
 *
 * PUREMENT ADDITIVE. `down()` rend l'état d'avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        // L'extension est déjà installée (cf. 2026_05_16_000001) ; le
        // `IF NOT EXISTS` est là pour le cas d'un environnement reconstruit à
        // partir d'un dump partiel, où l'ordre des extensions a pu varier.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement(<<<'SQL'
            ALTER TABLE media ADD COLUMN IF NOT EXISTS name_normalized TEXT
            GENERATED ALWAYS AS (normalize_name(name)) STORED
        SQL);

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_media_name_trgm
             ON media USING gin (name_normalized gin_trgm_ops)',
        );

        // Le rapprochement filtre TOUJOURS par famille avant de scorer : un
        // journaliste ne se rattache pas à une société de production. Sur
        // « TF1 », ce seul filtre fait tomber 22 candidats à environ 8. L'index
        // composite sert ce filtre-là, que `idx_media_family` seul ne couvre
        // pas quand on ajoute le workspace.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_media_workspace_family
             ON media (workspace_id, media_family)',
        );

        DB::statement("COMMENT ON COLUMN media.name_normalized IS 'Nom normalisé (normalize_name) — support de l''index trigramme du rattachement presse. Calque de companies.denomination_normalized.'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_media_workspace_family');
        DB::statement('DROP INDEX IF EXISTS idx_media_name_trgm');
        DB::statement('ALTER TABLE media DROP COLUMN IF EXISTS name_normalized');
    }
};
