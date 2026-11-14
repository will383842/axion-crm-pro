<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R09 — date du prochain envoi d'une campagne (ADD COLUMN NULL, ajout seul).
 * Colonne ignorée par l'ancien code (deux conteneurs pendant la mise en ligne) ; le déclencheur de R08 garde id, espace,
 * créateur et date de création, et impose version + 1 : la date ne change donc que par un geste versionné.
 * Le rôle applicatif reçoit UPDATE sur CETTE colonne seulement ; toujours aucun droit sur `sequence_id`.
 * `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE envoi_campagnes ADD COLUMN IF NOT EXISTS envoi_prevu_le TIMESTAMPTZ NULL');

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            DB::statement('GRANT UPDATE (envoi_prevu_le) ON public.envoi_campagnes TO "' . str_replace('"', '""', $roleApplicatif) . '"');
        }
    }

    /** Retour arrière INERTE : la date prévue est conservée. */
    public function down(): void {}
};
