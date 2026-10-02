<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TABLE `job_batches` — panne constatée en production le 2026-10-02 à 21 h 42.
 *
 *     php artisan audiences:full-refresh
 *     → Audiences vues : 3 · rafraîchies : 0 · en échec : 3
 *     SQLSTATE[42P01]: relation "job_batches" does not exist
 *
 * `AudienceBuilderService::refresh()` bascule en `Bus::batch` au-delà de
 * 5 000 entreprises (`RefreshAudienceChunkJob`, file `audiences-refresh`) ;
 * `config/queue.php` range les lots dans `job_batches` — et AUCUNE migration
 * ne la créait. Toute audience de plus de 5 000 entreprises échouait donc, à
 * la main comme au rafraîchissement nocturne par espace (#284).
 *
 * Schéma : exactement celui de `php artisan make:queue-batches-table`
 * (Laravel 12). Rejouable : rien n'est fait si la table existe déjà.
 *
 * ── Droits ───────────────────────────────────────────────────────────────
 *
 * La migration tourne sous le rôle PROPRIÉTAIRE (`--database=pgsql_owner`),
 * l'application et Horizon sous le rôle applicatif `axion_app`. Les
 * privilèges par défaut posés par `2026_08_14_000001_harden_workspace_isolation`
 * lui donnent déjà SELECT/INSERT/UPDATE/DELETE sur toute table créée ensuite
 * par le propriétaire ; on les pose AUSSI explicitement, comme
 * `2026_10_01_000001_doublons` : un privilège par défaut ne s'applique qu'aux
 * tables créées par le rôle qui l'a déclaré, et un « permission denied » à la
 * place d'un « does not exist » serait la même panne.
 *
 * Pas de RLS : table technique de la file, sans `workspace_id` (comme
 * `sessions`, `cache`, `migrations`). Le lot ne porte que des compteurs et le
 * rappel sérialisé, qui ne capture que l'identifiant de l'audience et son
 * espace (cf. `AudienceBuilderService::refreshViaBatch`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.job_batches TO ' . $role);
        }
    }

    /**
     * Retour arrière : retire la table. Elle ne porte que l'état des lots en
     * cours ou récents (compteurs) ; aucune donnée métier n'y vit.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};
