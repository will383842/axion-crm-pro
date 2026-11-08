<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission `rgpd.view_pii` — voir EN CLAIR la ligne d'une demande RGPD
 * (adresse, note libre, jeton d'export).
 *
 * `contacts.view_pii` ne convenait pas : `operator` la possède. Réservée à
 * owner et admin ; tous les autres rôles sont masqués. Ajout seulement.
 *
 * 🔴 Migration et non seeder : les seeders ne tournent pas au déploiement, et
 * le masquage s'active en l'ABSENCE du droit (le doute va vers le silence).
 */
return new class extends Migration
{
    private const PERMISSION = 'rgpd.view_pii';

    private const ROLES = ['owner', 'admin'];

    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            [
                'description' => 'Voir en clair les demandes d\'effacement (adresse, note, jeton)',
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
