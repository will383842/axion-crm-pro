<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * L3c-0 (ADR contrat v3 § 6, I3) — `rgpd.handle` n'appartient qu'au propriétaire.
 *
 * MIGRATION DE DONNÉES, idempotente : le seeder ne tourne pas en production,
 * donc un compte admin DÉJÀ en base garderait le droit de déposer et de traiter
 * une demande RGPD. On retire ici le seul lien rôle↔permission (global ET par
 * espace), et les droits posés directement sur un compte administrateur.
 * Aucune donnée de personne n'est touchée. Rejouable sans effet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $handleId = DB::table('permissions')->where('name', 'rgpd.handle')->value('id');
        if ($handleId === null) {
            return;
        }

        $adminIds = DB::table('roles')->where('name', 'admin')->pluck('id')->all();
        $ownerIds = DB::table('roles')->where('name', 'owner')->pluck('id')->all();

        if ($adminIds !== []) {
            DB::table('role_has_permissions')
                ->where('permission_id', $handleId)
                ->whereIn('role_id', $adminIds)
                ->delete();

            // Droit posé directement sur un compte administrateur qui n'est
            // PAS aussi propriétaire (un propriétaire le garde).
            $proprietaires = $ownerIds === []
                ? []
                : DB::table('model_has_roles')->whereIn('role_id', $ownerIds)->get(['model_id', 'model_type'])
                    ->map(static fn (object $l): string => $l->model_type . '|' . $l->model_id)->all();

            $administrateurs = DB::table('model_has_roles')->whereIn('role_id', $adminIds)->get(['model_id', 'model_type']);
            foreach ($administrateurs as $a) {
                if (in_array($a->model_type . '|' . $a->model_id, $proprietaires, true)) {
                    continue;
                }
                DB::table('model_has_permissions')
                    ->where('permission_id', $handleId)
                    ->where('model_id', $a->model_id)
                    ->where('model_type', $a->model_type)
                    ->delete();
            }
        }

        // Spatie met la carte rôles↔permissions en cache : sans cette purge le
        // retrait resterait sans effet jusqu'à 24 h.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Un droit retiré ne se rend pas silencieusement : le retour arrière
        // ne réattribue rien.
    }
};
