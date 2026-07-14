<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Durcissement de la TAXONOMIE MÉDIAS (chantier 2026-07-14).
 *
 * Décisions produit tranchées (Will) :
 *  1. Sortir la PRODUCTION AUDIOVISUELLE de la base presse SANS la supprimer :
 *     nouvelle colonne `media_family` (editorial | audiovisual_production). Les
 *     NAF 5911/5912 (media_type='production_audiovisuelle') → audiovisual_production,
 *     tout le reste → editorial. La console/export filtre là-dessus.
 *  2. Backfill `region_code` depuis le référentiel `departments` (SSOT dept→région).
 *  3. Sortir `enrich_status` de son état « pending » figé : un média qui porte déjà
 *     un email OU un site confirmé est de fait enrichi → 'enriched'.
 *  4. Contrainte CHECK sur `media_type` (garde-fou : plus de valeur hors-taxonomie),
 *     posée NOT VALID puis VALIDATE pour ne pas bloquer si une valeur historique
 *     inattendue traîne (on log alors sans faire échouer la migration).
 *
 * Migration ADDITIVE + idempotente (IF NOT EXISTS / DROP … IF EXISTS), 100 % SQL
 * raw via DB::statement() (aucun query builder → aucun risque de confusion PDO sur
 * les opérateurs jsonb `?`).
 */
return new class extends Migration
{
    /** Set canonique media_type autorisé (taxonomie + valeurs historiques + blog). */
    private const MEDIA_TYPES = [
        'presse_quotidien', 'presse_hebdo', 'presse_mensuel',
        'presse_journal', 'presse_revue', 'presse_autre',
        'radio', 'tv', 'tv_emission', 'agence_presse',
        'portail_web', 'blog', 'production_audiovisuelle',
    ];

    public function up(): void
    {
        // ── 1. Nouvelle famille média (rédactionnel vs production audiovisuelle) ──
        // VARCHAR(30) : 'audiovisual_production' = 22 caractères (20 était trop court → 22001).
        DB::statement("ALTER TABLE media ADD COLUMN IF NOT EXISTS media_family VARCHAR(30) NOT NULL DEFAULT 'editorial'");

        // Backfill : la production audiovisuelle (NAF 5911/5912) sort de l'éditorial.
        DB::statement("UPDATE media SET media_family = 'audiovisual_production' WHERE media_type = 'production_audiovisuelle'");

        // ── 2. Backfill region_code depuis le référentiel departments (SSOT géo) ──
        DB::statement(<<<'SQL'
            UPDATE media m
            SET region_code = d.region_code
            FROM departments d
            WHERE m.region_code IS NULL
              AND m.department_code IS NOT NULL
              AND m.department_code = d.code
        SQL);

        // ── 3. Backfill enrich_status : email OU site confirmé ⇒ 'enriched' ───────
        DB::statement(<<<'SQL'
            UPDATE media
            SET enrich_status = 'enriched',
                enriched_at   = COALESCE(enriched_at, now())
            WHERE enrich_status = 'pending'
              AND (email IS NOT NULL OR (website IS NOT NULL AND website_status = 'found'))
        SQL);

        // ── 4. Index sur la famille (filtre console/export) ───────────────────────
        DB::statement('CREATE INDEX IF NOT EXISTS idx_media_family ON media (media_family)');

        // ── 5. Contrainte CHECK media_type (NOT VALID puis VALIDATE) ──────────────
        $allowed = "'" . implode("','", self::MEDIA_TYPES) . "'";
        DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_media_type_check');
        DB::statement("ALTER TABLE media ADD CONSTRAINT media_media_type_check CHECK (media_type IN ({$allowed})) NOT VALID");
        try {
            DB::statement('ALTER TABLE media VALIDATE CONSTRAINT media_media_type_check');
        } catch (\Throwable $e) {
            // Une valeur media_type hors taxonomie traîne en base : on garde la
            // contrainte NOT VALID (elle protège les futures écritures) sans faire
            // échouer la migration. À nettoyer via un correctif de données dédié.
            Log::warning('media_media_type_check reste NOT VALID (valeur hors taxonomie détectée).', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_media_type_check');
        DB::statement('DROP INDEX IF EXISTS idx_media_family');
        DB::statement('ALTER TABLE media DROP COLUMN IF EXISTS media_family');
    }
};
