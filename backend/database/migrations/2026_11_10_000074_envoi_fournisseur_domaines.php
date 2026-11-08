<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L7 — cache de la résolution MX : domaine => famille de fournisseur. Un domaine et sa
 * famille, JAMAIS une adresse ni une donnée de personne : pas de workspace_id, pas de RLS (même
 * logique que `envoi_compteur_global`). Le rôle applicatif n'a ni DELETE ni TRUNCATE.
 * down() ne retire la table que si elle est vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_fournisseur_domaines (
                domaine     TEXT        PRIMARY KEY,
                fournisseur TEXT        NOT NULL CHECK (fournisseur IN ('gmail', 'microsoft', 'autre')),
                resolu_le   TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement("COMMENT ON TABLE envoi_fournisseur_domaines IS 'Envoi P0 L7 : cache MX domaine => fournisseur. Aucune donnée de personne.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_fournisseur_domaines TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_fournisseur_domaines FROM ' . $role);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM envoi_fournisseur_domaines) THEN
                    RAISE EXCEPTION 'envoi_fournisseur_domaines contient des lignes : retour refusé (aucun effacement)';
                END IF;
            END $$;
            DROP TABLE IF EXISTS envoi_fournisseur_domaines;
        SQL);
    }
};
