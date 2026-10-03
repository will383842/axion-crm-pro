<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MISE À JOUR MENSUELLE INSEE (lot N8, 03/10/2026) — `crm:insee:mise-a-jour-mensuelle`.
 *
 * PUREMENT ADDITIVE. Aucune ligne de `companies` n'est réécrite.
 *
 * ── `companies` : trois marqueurs, nullables, sans défaut ──────────────────
 *  - `insee_verifiee_le`       dernière confrontation de la fiche à Sirene par
 *                              la mise à jour : toujours posée sur une fiche
 *                              de provenance tiers ou créée, et sur une fiche
 *                              INSEE quand quelque chose change (une fiche
 *                              INSEE inchangée n'est jamais réécrite). NULL
 *                              sur une fiche tiers = « non vérifiée INSEE »
 *                              (`non_verifiee_insee`) : rafraîchie en premier.
 *  - `insee_ferme_le`          date de fermeture (état administratif `C`).
 *                              La fiche est MARQUÉE, jamais supprimée.
 *  - `insee_non_diffusible_le` date à laquelle l'unité a été vue opposée à la
 *                              diffusion (statut `P` ou `N`) : la fiche est
 *                              marquée et sort de la prospection, jamais
 *                              supprimée.
 *  `ADD COLUMN` nullable sans défaut : catalogue seulement, instantané sur
 *  les 4,35 M de lignes.
 *
 * ── `archive_reason` : la valeur `non_diffusible` ──────────────────────────
 *  La contrainte est reposée `NOT VALID` : elle s'applique à toute écriture
 *  nouvelle, sans relire les 9,3 Go de la table (les valeurs existantes sont
 *  un sous-ensemble de la nouvelle liste — rien à valider). Même patron que
 *  `companies_joignabilite_check`.
 *
 * ── `insee_mises_a_jour` : le journal des passages ─────────────────────────
 *  Une ligne par passage : la date `depuis`, le CURSEUR Sirene de la
 *  prochaine page (reprise après une coupure, une limite ou une durée
 *  atteinte), le bilan chiffré. La dernière ligne `reussie` donne le `depuis`
 *  par défaut du passage suivant. Rien n'y est jamais supprimé par le
 *  produit. RLS : ENABLE + FORCE, politique stricte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            'ALTER TABLE companies
                ADD COLUMN IF NOT EXISTS insee_verifiee_le TIMESTAMPTZ,
                ADD COLUMN IF NOT EXISTS insee_ferme_le DATE,
                ADD COLUMN IF NOT EXISTS insee_non_diffusible_le DATE',
        );
        DB::statement("COMMENT ON COLUMN companies.insee_verifiee_le IS 'Dernière confrontation à Sirene (crm:insee:mise-a-jour-mensuelle). NULL = non vérifiée INSEE.'");
        DB::statement("COMMENT ON COLUMN companies.insee_ferme_le IS 'Fermeture INSEE (état C) : la fiche est marquée, jamais supprimée.'");
        DB::statement("COMMENT ON COLUMN companies.insee_non_diffusible_le IS 'Unité opposée à la diffusion INSEE (statut P/N) : hors prospection, jamais supprimée.'");

        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_archive_reason_check');
        DB::statement(
            "ALTER TABLE companies ADD CONSTRAINT companies_archive_reason_check
             CHECK (archive_reason IS NULL OR archive_reason IN (
                 'entreprise_radiee', 'no_email', 'low_quality_score', 'duplicate', 'manual', 'non_diffusible'
             )) NOT VALID",
        );

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS insee_mises_a_jour (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                depuis        DATE NOT NULL,
                statut        TEXT NOT NULL DEFAULT 'en_cours'
                              CHECK (statut IN ('en_cours', 'reussie', 'echouee')),
                curseur       TEXT NOT NULL DEFAULT '*',
                pages         INTEGER NOT NULL DEFAULT 0,
                bilan         JSONB NOT NULL DEFAULT '{}'::jsonb,
                erreur        TEXT,
                demarree_le   TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le        TIMESTAMPTZ NOT NULL DEFAULT now(),
                terminee_le   TIMESTAMPTZ
            )
            SQL,
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_insee_mises_a_jour_espace
             ON insee_mises_a_jour (workspace_id, statut, demarree_le DESC)',
        );

        DB::statement('ALTER TABLE insee_mises_a_jour ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE insee_mises_a_jour FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS insee_mises_a_jour_workspace_isolation ON insee_mises_a_jour');
        DB::statement(
            "CREATE POLICY insee_mises_a_jour_workspace_isolation ON insee_mises_a_jour FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('insee_mises_a_jour')) {
            $n = (int) (DB::selectOne('SELECT count(*) AS n FROM insee_mises_a_jour')->n ?? 0);
            if ($n > 0) {
                // Rien n'est jamais supprimé en masse : on ne jette pas le
                // journal des passages par un retour arrière.
                throw new RuntimeException(
                    "Retour arrière refusé : {$n} passage(s) de mise à jour INSEE journalisé(s). Les exporter et décider à la main d'abord.",
                );
            }
        }
        $marquees = (int) (DB::selectOne(
            'SELECT count(*) AS n FROM companies WHERE insee_ferme_le IS NOT NULL OR insee_non_diffusible_le IS NOT NULL',
        )->n ?? 0);
        if ($marquees > 0) {
            throw new RuntimeException(
                "Retour arrière refusé : {$marquees} fiche(s) portent un marquage INSEE (fermeture ou non diffusible) qui serait perdu.",
            );
        }

        DB::statement('DROP TABLE IF EXISTS insee_mises_a_jour');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_archive_reason_check');
        DB::statement(
            "ALTER TABLE companies ADD CONSTRAINT companies_archive_reason_check
             CHECK (archive_reason IS NULL OR archive_reason IN (
                 'entreprise_radiee', 'no_email', 'low_quality_score', 'duplicate', 'manual'
             )) NOT VALID",
        );
        DB::statement(
            'ALTER TABLE companies
                DROP COLUMN IF EXISTS insee_non_diffusible_le,
                DROP COLUMN IF EXISTS insee_ferme_le,
                DROP COLUMN IF EXISTS insee_verifiee_le',
        );
    }
};
