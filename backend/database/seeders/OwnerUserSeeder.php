<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crée :
 * - workspace par défaut "axion-ia"
 * - utilisateur owner initial (depuis env vars OWNER_INITIAL_*)
 * - assignation rôle owner sur le workspace
 *
 * Sécurité :
 * - mot de passe lu depuis OWNER_INITIAL_PASSWORD (ne JAMAIS hard-coder)
 * - vérification HIBP (haveibeenpwned) côté admin onboarding au 1er login
 * - si OWNER_INITIAL_PASSWORD vide → magic-link only (l'owner se loggera via lien email)
 */
class OwnerUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('OWNER_INITIAL_EMAIL', 'williamsjullin@gmail.com');
        $name  = (string) env('OWNER_INITIAL_NAME', 'Williams Jullin');
        $rawPassword = (string) env('OWNER_INITIAL_PASSWORD', '');

        // Workspace
        $workspaceId = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
        if (! $workspaceId) {
            $workspaceId = (string) Str::uuid();
            DB::table('workspaces')->insert([
                'id'         => $workspaceId,
                'slug'       => 'axion-ia',
                'name'       => 'Axion-IA',
                'settings'   => '{}',
                'cost_cap_eur' => 1000,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // User owner
        $userId = DB::table('users')->where('email', $email)->value('id');
        if (! $userId) {
            $userId = (string) Str::uuid();
            DB::table('users')->insert([
                'id'                  => $userId,
                'email'               => $email,
                'name'                => $name,
                'password_hash'       => $rawPassword !== '' ? Hash::make($rawPassword) : null,
                'current_workspace_id'=> $workspaceId,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        // Pivot user_workspaces
        DB::table('user_workspaces')->updateOrInsert(
            ['user_id' => $userId, 'workspace_id' => $workspaceId],
            ['role_slug' => 'owner', 'invited_at' => now(), 'joined_at' => now()],
        );

        // Spatie : assigne rôle owner sur ce team_id
        $roleId = DB::table('roles')->where('name', 'owner')->whereNull('team_id')->value('id');
        if ($roleId) {
            DB::table('model_has_roles')->updateOrInsert(
                [
                    'role_id'    => $roleId,
                    'model_type' => 'App\\Models\\User',
                    'model_id'   => $userId,
                    'team_id'    => $workspaceId,
                ],
                [],
            );
        }
    }
}
