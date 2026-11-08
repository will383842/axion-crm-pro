<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L7 — journal des envois agrégés CRM → Usine. ADDITIF SEULEMENT.
 *
 * Aucune donnée de personne (jour, horodatage, nombre de lignes, statut HTTP,
 * classe d'erreur). Table globale d'infrastructure, en AJOUT SEUL pour le rôle
 * applicatif (ni UPDATE, ni DELETE, ni TRUNCATE). Aucun DROP ; `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS usine_agregats_envois (
                id          BIGSERIAL   PRIMARY KEY,
                jour        DATE        NOT NULL,
                genere_le   TIMESTAMPTZ NOT NULL,
                lignes      INTEGER     NOT NULL DEFAULT 0,
                statut_http INTEGER     NULL,
                erreur      TEXT        NULL,
                cree_le     TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_usine_agregats_envois_jour ON usine_agregats_envois (jour, cree_le)');
        DB::statement("COMMENT ON TABLE usine_agregats_envois IS 'L7 : journal des envois agreges CRM vers Usine. Aucune donnee de personne. Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.usine_agregats_envois FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.usine_agregats_envois TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.usine_agregats_envois_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : le journal est conservé. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE usine_agregats_envois IS 'L7 : journal conserve au retour arriere.'");
    }
};
