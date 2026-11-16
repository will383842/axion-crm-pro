<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VERDICTS (décision du pilotage, relecture #610) — les verdicts d'adresse sont en AJOUT SEUL pour le rôle
 * applicatif : les privilèges par défaut de 2026_08_14_000001 lui avaient donné UPDATE et DELETE.
 * REVOKE rejouable ; le propriétaire n'est pas concerné. `down()` inerte : on ne rend aucun droit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE ON public.envoi_verdicts_adresse FROM ' . $role);
        }
    }

    /** Retour arrière INERTE (corps vide voulu) : les droits retirés ne sont pas rendus. */
    public function down(): void {}
};
