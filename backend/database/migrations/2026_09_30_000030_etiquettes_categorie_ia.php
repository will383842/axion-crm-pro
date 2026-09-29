<?php

use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ÉTIQUETTES RANGÉES (chantier 2, 2026-09-29) — la catégorie `ia`.
 *
 * Les étiquettes proposées par l'IA (`kind = llm`, slug libre tiré de
 * `signals.llm_classification.tags`) n'avaient ni namespace ni famille : elles
 * étaient rangées dans `intent`, mêlées aux étiquettes GOUVERNÉES `svc:` et
 * `src:` (provenance, intérêt), et l'écran les présentait comme « Intent
 * (LLM) ». Elles ont désormais leur catégorie, `ia`.
 *
 * Cette migration ne fait QU'ÉLARGIR le CHECK `tags_category_check` à la liste
 * de `Taxonomy::TAG_CATEGORIES` (garde `SocleCrmTest`). Elle ne déplace AUCUNE
 * étiquette : ranger les étiquettes IA existantes est une ÉCRITURE de données,
 * faite par `crm:referentiels:reclasser` (chiffrée à blanc d'abord), jamais au
 * déploiement.
 *
 * `tags` est une petite table (quelques milliers de lignes) : le `ADD
 * CONSTRAINT` relit la table sous verrou, en quelques millisecondes. Un verrou
 * qui ne vient pas en 30 s fait échouer la migration (et le déploiement, qui
 * le dit) au lieu de mettre la console en file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tags')) {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '30s'");
        DB::statement('ALTER TABLE tags DROP CONSTRAINT IF EXISTS tags_category_check');
        DB::statement(
            'ALTER TABLE tags ADD CONSTRAINT tags_category_check
             CHECK (category IN (' . Taxonomy::sqlList(Taxonomy::TAG_CATEGORIES) . '))',
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('tags')) {
            return;
        }

        // Retour arrière : les étiquettes rangées en `ia` reviennent en
        // `intent`, leur catégorie d'avant, sinon le CHECK restreint refuserait.
        DB::statement("SET LOCAL lock_timeout = '30s'");
        DB::table('tags')->where('category', 'ia')->update(['category' => 'intent']);
        DB::statement('ALTER TABLE tags DROP CONSTRAINT IF EXISTS tags_category_check');
        DB::statement(
            "ALTER TABLE tags ADD CONSTRAINT tags_category_check
             CHECK (category IN ('geo', 'sector', 'size', 'intent', 'custom', 'candidate'))",
        );
    }
};
