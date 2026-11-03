<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L3c-0 (ADR contrat v3 § 6) — MISE À L'ÉCART des propositions de tiers d'une
 * personne qui demande l'effacement. Remplace l'appel à la fonction
 * `propositions_champs_effacer()`, qui ÉCRASAIT les valeurs par « [effacé] »
 * (irréversible).
 *
 * PUREMENT ADDITIVE : une table de marques. Les lignes de `propositions_champs`
 * ne sont pas touchées (valeurs, statut, décision restent intacts) ; une
 * proposition écartée n'apparaît simplement plus dans la file « à valider ».
 * Lever la marque = retirer la ligne ici (par le propriétaire de la base).
 * Le rôle applicatif n'a ni UPDATE, ni DELETE, ni TRUNCATE.
 *
 * RLS : ENABLE + FORCE, politique stricte par espace.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS propositions_champs_ecartees (
                proposition_id BIGINT PRIMARY KEY REFERENCES propositions_champs(id) ON DELETE RESTRICT,
                workspace_id   UUID NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
                motif          TEXT NOT NULL,
                ecartee_le     TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_propositions_champs_ecartees_espace ON propositions_champs_ecartees (workspace_id)');

        DB::statement('ALTER TABLE propositions_champs_ecartees ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE propositions_champs_ecartees FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS propositions_champs_ecartees_workspace_isolation ON propositions_champs_ecartees');
        DB::statement(
            "CREATE POLICY propositions_champs_ecartees_workspace_isolation ON propositions_champs_ecartees FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.propositions_champs_ecartees TO ' . $role);
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.propositions_champs_ecartees FROM ' . $role);
        }
        DB::statement("COMMENT ON TABLE propositions_champs_ecartees IS 'L3c-0 : propositions de tiers mises a l ecart (demande d effacement). Aucune valeur ecrasee ; lever la marque = retirer la ligne.'");
    }

    public function down(): void
    {
        // On ne retire jamais de donnée : la table reste.
        DB::statement("COMMENT ON TABLE propositions_champs_ecartees IS 'L3c-0 : table conservee au retour arriere.'");
    }
};
