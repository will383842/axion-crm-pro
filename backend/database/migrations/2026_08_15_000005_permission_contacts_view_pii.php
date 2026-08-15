<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission `contacts.view_pii` — voir les coordonnées COMPLÈTES.
 *
 * Plan §2.10 : « les viewers ne voient pas les colonnes de coordonnées
 * complètes en liste ». Un compte en lecture seule n'a pas à repartir avec
 * 665 771 adresses lisibles à l'écran, même sans droit d'export.
 *
 * 🔴 POURQUOI UNE MIGRATION ET PAS LE SEEDER : les seeders NE TOURNENT PAS au
 * déploiement (l'entrypoint ne fait que `migrate --force`). Une permission
 * ajoutée au seeder n'existerait donc jamais en production — et comme le
 * masquage s'active en l'ABSENCE de ce droit, TOUT LE MONDE serait masqué,
 * propriétaire compris. Le sens de la garde impose son véhicule.
 *
 * Accordée à owner, admin et operator — les trois rôles qui travaillent la
 * donnée. Pas à `viewer`, qui est précisément la cible.
 */
return new class extends Migration
{
    private const PERMISSION = 'contacts.view_pii';

    private const ROLES = ['owner', 'admin', 'operator'];

    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            [
                'description' => 'Voir les coordonnées complètes (e-mail, téléphone) en liste',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        foreach (self::ROLES as $role) {
            $roleId = DB::table('roles')->where('name', $role)->whereNull('team_id')->value('id');
            if ($roleId === null) {
                continue;
            }

            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                [],
            );
        }

        // Spatie garde la carte rôles↔permissions 24 h en cache. Sans cette
        // purge, la permission existerait en base et resterait invisible une
        // journée entière — le défaut corrigé dans le seeder en #82.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
