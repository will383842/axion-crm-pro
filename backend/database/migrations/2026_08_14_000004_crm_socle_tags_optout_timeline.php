<?php

use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot L1 — SOCLE DE DONNÉES : tags gouvernés, opposition par univers, timeline.
 *
 * ── 1. TAGS GOUVERNÉS (plan §2.2c) ──────────────────────────────────────────
 * Le système de tags existant est déjà catégorisé (`category`) et typé
 * (`kind`) : on le RÉUTILISE. Deux ajouts :
 *   - `is_locked` : aujourd'hui `POST /v1/tags` est ouvert à tout utilisateur
 *     authentifié du workspace. Un référentiel qu'on peut étendre pendant une
 *     campagne n'est pas un référentiel — les tags verrouillés ne sont
 *     modifiables que par l'admin.
 *   - `namespace` (colonne GÉNÉRÉE, `split_part(slug, ':', 1)`) : la
 *     convention `namespace:valeur` devient interrogeable et indexable au lieu
 *     d'être une discipline. Aucune contrainte n'est posée sur les tags
 *     EXISTANTS (ils resteraient à namespace NULL) : la gouvernance porte sur
 *     le référentiel versionné, pas sur une réécriture rétroactive.
 *   - `category` accepte `candidate` (tags `cand-*:` du vivier).
 *
 * ── 2. OPPOSITION PAR UNIVERS (plan §2.3) ───────────────────────────────────
 * `opt_out` est aujourd'hui GLOBALE et stocke l'email EN CLAIR. Deux ajouts :
 *   - `scope` (`business` | `vivier`) : se désinscrire de la newsletter ne doit
 *     PAS effacer une candidature, et réciproquement. Le plan laisse le choix
 *     entre `workspace_id` et `scope` ; `scope` est retenu — il ne transforme
 *     pas la table en table workspace-scopée (donc aucune interaction avec la
 *     RLS, et pas de ligne globale rendue invisible).
 *   - `email_hash` : opposer sans conserver l'email en clair. C'est le hash qui
 *     empêche la RÉINSERTION par un futur re-scrape ; il survit à l'effacement.
 *
 * ── 3. TIMELINE UNIFIÉE (plan §2.6) ─────────────────────────────────────────
 * On étend `activities` (déjà workspace-scopée, prévue pour ça) plutôt que de
 * créer une table. `kind` porte le vocabulaire FERMÉ ; `type` (texte libre,
 * existant) est conservé pour compatibilité — phase « expand » de
 * expand → migrate → contract.
 *
 * ── INERTIE ─────────────────────────────────────────────────────────────────
 * Purement additive. `tags.namespace` est une colonne générée sur une table de
 * quelques dizaines de lignes (réécriture négligeable, contrairement à
 * `contacts.normalized_hash` sur 1,3 M — cf. note de report du journal).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendTags();
        $this->scopeOptOut();
        $this->extendActivities();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tags DROP COLUMN IF EXISTS namespace');
        DB::statement('ALTER TABLE tags DROP COLUMN IF EXISTS is_locked');
        DB::statement('ALTER TABLE tags DROP CONSTRAINT IF EXISTS tags_category_check');
        DB::statement("ALTER TABLE tags ADD CONSTRAINT tags_category_check CHECK (category IN ('geo', 'sector', 'size', 'intent', 'custom'))");

        foreach (['scope', 'email_hash'] as $column) {
            DB::statement("ALTER TABLE opt_out DROP COLUMN IF EXISTS {$column}");
        }

        foreach (['person_key', 'external_ref', 'kind', 'occurred_at', 'subject_type', 'subject_id', 'title', 'payload'] as $column) {
            DB::statement("ALTER TABLE activities DROP COLUMN IF EXISTS {$column}");
        }
    }

    private function extendTags(): void
    {
        DB::statement('ALTER TABLE tags ADD COLUMN IF NOT EXISTS is_locked BOOLEAN NOT NULL DEFAULT false');

        DB::statement('ALTER TABLE tags DROP CONSTRAINT IF EXISTS tags_category_check');
        DB::statement(
            'ALTER TABLE tags ADD CONSTRAINT tags_category_check
             CHECK (category IN (' . Taxonomy::sqlList(Taxonomy::TAG_CATEGORIES) . '))',
        );

        DB::statement(
            "ALTER TABLE tags ADD COLUMN IF NOT EXISTS namespace TEXT
             GENERATED ALWAYS AS (NULLIF(split_part(slug, ':', 1), slug)) STORED",
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tags_workspace_namespace ON tags (workspace_id, namespace)');

        DB::statement("COMMENT ON COLUMN tags.namespace IS 'Namespace du slug (avant le « : »), NULL si le slug n''en porte pas. Convention de nommage gouvernée : namespace:valeur, minuscules, sans accents.'");
        DB::statement("COMMENT ON COLUMN tags.is_locked IS 'Tag du référentiel versionné : création/modification réservées à l''admin. Un tag ne porte JAMAIS de règle de sécurité ni de règle RGPD — toujours des champs pour cela.'");
    }

    private function scopeOptOut(): void
    {
        DB::statement("ALTER TABLE opt_out ADD COLUMN IF NOT EXISTS scope TEXT NOT NULL DEFAULT 'business'");
        DB::statement('ALTER TABLE opt_out ADD COLUMN IF NOT EXISTS email_hash TEXT');

        DB::statement('ALTER TABLE opt_out DROP CONSTRAINT IF EXISTS opt_out_scope_check');
        DB::statement("ALTER TABLE opt_out ADD CONSTRAINT opt_out_scope_check CHECK (scope IN ('business', 'vivier'))");

        DB::statement('CREATE INDEX IF NOT EXISTS idx_opt_out_scope_email_hash ON opt_out (scope, email_hash) WHERE email_hash IS NOT NULL');

        DB::statement("COMMENT ON COLUMN opt_out.scope IS 'Univers de l''opposition. Les deux listes sont DISTINCTES : se désinscrire de la newsletter n''efface pas une candidature, et réciproquement.'");
        DB::statement("COMMENT ON COLUMN opt_out.email_hash IS 'sha256 de l''email normalisé. Conservé APRÈS effacement pour empêcher la réinsertion par un futur re-scrape — c''est le seul motif légitime de conservation.'");
    }

    private function extendActivities(): void
    {
        DB::statement('ALTER TABLE activities ADD COLUMN IF NOT EXISTS person_key TEXT');
        DB::statement('ALTER TABLE activities ADD COLUMN IF NOT EXISTS external_ref TEXT');
        DB::statement('ALTER TABLE activities ADD COLUMN IF NOT EXISTS kind TEXT');
        DB::statement('ALTER TABLE activities ADD COLUMN IF NOT EXISTS occurred_at TIMESTAMPTZ');
        DB::statement('ALTER TABLE activities ADD COLUMN IF NOT EXISTS subject_type TEXT');
        DB::statement('ALTER TABLE activities ADD COLUMN IF NOT EXISTS subject_id BIGINT');
        DB::statement('ALTER TABLE activities ADD COLUMN IF NOT EXISTS title TEXT');
        DB::statement("ALTER TABLE activities ADD COLUMN IF NOT EXISTS payload JSONB NOT NULL DEFAULT '{}'::jsonb");

        DB::statement('ALTER TABLE activities DROP CONSTRAINT IF EXISTS activities_kind_check');
        DB::statement(
            'ALTER TABLE activities ADD CONSTRAINT activities_kind_check
             CHECK (kind IS NULL OR kind IN (' . Taxonomy::sqlList(Taxonomy::ACTIVITY_KINDS) . '))',
        );

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS activities_workspace_external_ref_key ON activities (workspace_id, external_ref) WHERE external_ref IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_activities_workspace_person_key ON activities (workspace_id, person_key) WHERE person_key IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_activities_workspace_occurred ON activities (workspace_id, occurred_at DESC)');

        DB::statement("COMMENT ON COLUMN activities.kind IS 'Vocabulaire FERMÉ de la timeline (App\\Crm\\Taxonomy::ACTIVITY_KINDS). Remplace progressivement la colonne « type » (texte libre) — phase expand de expand/migrate/contract.'");
        DB::statement("COMMENT ON TABLE activities IS 'Timeline unifiée : un INDEX des touchpoints, pas leur contenu. Chaque ligne référence sa source (external_ref = site:submission:<uuid>) ; le détail reste dans le système source. Les activités vivent dans le workspace de leur fiche : la timeline d''un candidat ne montre JAMAIS ses touchpoints business.'");
    }
};
