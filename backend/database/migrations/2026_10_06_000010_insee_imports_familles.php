<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMPORT DES FAMILLES INSEE (04/10/2026) — `crm:insee:importer-familles`.
 *
 * PUREMENT ADDITIVE : une table neuve, aucune ligne existante réécrite.
 *
 * ── `insee_imports_familles` : le journal des passages ─────────────────────
 *  Une ligne par passage d'une famille de catégories juridiques (5, 6, 7, 8,
 *  9 — `App\Crm\Insee\FamillesInsee`) d'un lot (`insee-familles-2026-10`) :
 *  le CURSEUR Sirene de la prochaine page (reprise exacte après un arrêt à
 *  l'heure, une limite, la garde mémoire ou une coupure), le nombre de
 *  fiches de l'espace AVANT le premier lancement et APRÈS le dernier (après
 *  = avant + créées), et le bilan chiffré — des COMPTEURS seulement, aucun
 *  SIREN, aucun nom. Un essai à blanc n'y écrit rien.
 *
 *  RLS : ENABLE + FORCE, politique stricte. Rien ne supprime un passage : ni
 *  DELETE ni TRUNCATE pour le rôle applicatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS insee_imports_familles (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
                famille       TEXT NOT NULL CHECK (famille IN ('5', '6', '7', '8', '9')),
                lot           TEXT NOT NULL CHECK (lot ~ '^[a-z0-9._-]{1,60}$'),
                statut        TEXT NOT NULL DEFAULT 'en_cours'
                              CHECK (statut IN ('en_cours', 'reussie', 'echouee')),
                curseur       TEXT NOT NULL DEFAULT '*',
                pages         INTEGER NOT NULL DEFAULT 0,
                fiches_avant  BIGINT,
                fiches_apres  BIGINT,
                bilan         JSONB NOT NULL DEFAULT '{}'::jsonb,
                erreur        TEXT,
                demarree_le   TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le        TIMESTAMPTZ NOT NULL DEFAULT now(),
                terminee_le   TIMESTAMPTZ
            )
            SQL,
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_insee_imports_familles_espace
             ON insee_imports_familles (workspace_id, famille, lot, id DESC)',
        );

        DB::statement('ALTER TABLE insee_imports_familles ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE insee_imports_familles FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS insee_imports_familles_workspace_isolation ON insee_imports_familles');
        DB::statement(
            "CREATE POLICY insee_imports_familles_workspace_isolation ON insee_imports_familles FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.insee_imports_familles TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.insee_imports_familles FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.insee_imports_familles_id_seq TO ' . $role);
        }

        DB::statement(
            "COMMENT ON TABLE insee_imports_familles IS 'Journal des passages de crm:insee:importer-familles (famille, lot, curseur Sirene de reprise, fiches avant/après, bilan chiffré). Jamais purgé.'",
        );
    }

    public function down(): void
    {
        // Décompte HORS RLS : sous FORCE RLS, un rôle sans BYPASSRLS compterait
        // 0 et laisserait tout jeter.
        DB::statement('SET LOCAL row_security = off');

        $existe = DB::selectOne("SELECT to_regclass('public.insee_imports_familles') IS NOT NULL AS existe");
        if ($existe !== null && (bool) $existe->existe) {
            $n = (int) (DB::selectOne('SELECT count(*) AS n FROM insee_imports_familles')->n ?? 0);
            if ($n > 0) {
                // Rien n'est jamais supprimé en masse.
                throw new RuntimeException(
                    "Retour arrière refusé : {$n} passage(s) d import INSEE par famille journalisé(s). Les exporter et décider à la main d'abord.",
                );
            }
        }

        DB::statement('DROP TABLE IF EXISTS insee_imports_familles');
    }
};
