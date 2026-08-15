<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class PermissionsAndRolesSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'companies.view',        'description' => 'Voir entreprises'],
            ['name' => 'companies.create',      'description' => 'Créer entreprises'],
            ['name' => 'companies.update',      'description' => 'Éditer entreprises'],
            ['name' => 'companies.delete',      'description' => 'Supprimer entreprises'],
            ['name' => 'scraping.run',          'description' => 'Lancer scraping'],
            ['name' => 'scraping.config',       'description' => 'Configurer sources scraping'],
            ['name' => 'llm.config',            'description' => 'Configurer LLM router'],
            ['name' => 'llm.view_usage',        'description' => 'Voir usage LLM'],
            ['name' => 'proxies.config',        'description' => 'Configurer proxies'],
            ['name' => 'rgpd.view',             'description' => 'Voir requêtes RGPD'],
            ['name' => 'rgpd.handle',           'description' => 'Traiter requêtes RGPD'],
            ['name' => 'workspaces.manage',     'description' => 'Gérer workspaces'],
            ['name' => 'users.manage',          'description' => 'Gérer utilisateurs'],
            ['name' => 'audit.view',            'description' => 'Voir audit log'],
            ['name' => 'data.export',           'description' => 'Exporter données'],
            // Voir les coordonnées COMPLÈTES en liste (§2.10). Déclarée ICI en
            // plus de la migration 2026_08_15_000005 : la migration sert les
            // bases DÉJÀ semées (production), ce seeder sert les installations
            // fraîches et les tests, où les rôles n'existent pas encore quand
            // les migrations passent. Les deux chemins doivent donner le même
            // résultat, sinon la garde dépend de l'ordre d'installation.
            ['name' => 'contacts.view_pii',     'description' => 'Voir les coordonnées complètes'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['description' => $perm['description'], 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // Rôles globaux (team_id NULL)
        $roles = [
            'owner' => 'Propriétaire — accès total',
            'admin' => 'Administrateur workspace',
            'operator' => 'Opérateur — CRUD sans destruction',
            'viewer' => 'Lecture seule',
        ];

        foreach ($roles as $name => $description) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'web', 'team_id' => null],
                ['description' => $description, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // Mapping rôle → permissions
        $rolePerms = [
            'owner' => array_column($permissions, 'name'),
            'admin' => ['companies.view', 'companies.create', 'companies.update', 'companies.delete',
                'scraping.run', 'scraping.config', 'llm.config', 'llm.view_usage',
                'proxies.config', 'rgpd.view', 'rgpd.handle', 'users.manage', 'audit.view', 'data.export', 'contacts.view_pii'],
            'operator' => ['companies.view', 'companies.create', 'companies.update',
                'scraping.run', 'llm.view_usage', 'rgpd.view', 'data.export', 'contacts.view_pii'],
            'viewer' => ['companies.view', 'llm.view_usage', 'rgpd.view'],
        ];

        foreach ($rolePerms as $roleName => $permNames) {
            $roleId = DB::table('roles')->where('name', $roleName)->whereNull('team_id')->value('id');
            foreach ($permNames as $permName) {
                $permId = DB::table('permissions')->where('name', $permName)->value('id');
                if ($roleId && $permId) {
                    DB::table('role_has_permissions')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $permId],
                        [],
                    );
                }
            }
        }

        // 🔴 INDISPENSABLE : ce seeder écrit en SQL BRUT, donc Spatie ne voit
        // rien passer. Or il met la carte rôles↔permissions en cache pour
        // 24 heures (`config/permission.php`). Sans cette purge, un droit
        // ajouté ou retiré ici reste SANS EFFET pendant une journée entière —
        // et en test, la carte chargée avant le seeder rend `can()` faux alors
        // que la base dit le contraire. C'est ce qui rendait la garde d'export
        // impossible à faire passer.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
