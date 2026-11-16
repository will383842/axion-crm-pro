<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi C3-0 — pour la pause globale (M1, A3) : couples (espace, domaine d'envoi, adaptateur) dont l'adaptateur n'est pas « faux ».
 * Lecture sans contexte d'espace (SECURITY DEFINER), comme `envoi_adaptateurs_reels_portes()`. Ne rend aucune adresse :
 * seulement des noms de domaine d'envoi. INERTE : aucun appelant dans ce lot.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_couples_reels_portes() RETURNS TABLE (workspace_id UUID, domaine TEXT, adaptateur TEXT) AS $f$
                SELECT d.workspace_id, d.nom, d.adaptateur FROM public.envoi_domaines d WHERE d.adaptateur <> 'faux' ORDER BY d.workspace_id, d.adaptateur, d.nom;
            $f$ LANGUAGE sql STABLE SECURITY DEFINER SET search_path = pg_catalog, public;
            REVOKE ALL ON FUNCTION envoi_couples_reels_portes() FROM PUBLIC;
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_couples_reels_portes() TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la fonction est conservée (aucun risque pour les données, mais un appelant futur en dépendra). */
    public function down(): void {}
};
