<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Crée :
 * - workspace par défaut "axion-ia"
 * - utilisateur owner initial (depuis env vars OWNER_INITIAL_*)
 * - assignation rôle owner sur le workspace
 *
 * Sécurité :
 * - mot de passe lu depuis OWNER_INITIAL_PASSWORD (ne JAMAIS hard-coder)
 * - vérification HIBP (haveibeenpwned) au 1er login (cf. HibpChecker)
 * - si OWNER_INITIAL_PASSWORD vide → génération random de 32 chars + log dans
 *   storage/app/private/seeders/owner-initial-password.txt, l'admin peut s'en servir
 *   au 1er login puis le rotater immédiatement.
 *
 * Sprint 19.2 — fix : env('OWNER_INITIAL_PASSWORD') peut être null/empty dans le
 * conteneur Docker au moment du seed (config:cache pas encore lancé). On lit
 * désormais via env() ET via getenv() en fallback + génération sécurisée.
 */
class OwnerUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = $this->readEnv('OWNER_INITIAL_EMAIL', 'williamsjullin@gmail.com');
        $name  = $this->readEnv('OWNER_INITIAL_NAME', 'Williams Jullin');
        $rawPassword = $this->readEnv('OWNER_INITIAL_PASSWORD', '');

        // Si pas de password fourni → génération sécurisée + persistance fichier
        $generated = false;
        if ($rawPassword === '' || $rawPassword === null) {
            $rawPassword = $this->generateSecurePassword();
            $generated = true;
        }

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
                'password_hash'       => Hash::make($rawPassword),
                'current_workspace_id'=> $workspaceId,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            if ($generated) {
                $this->persistGeneratedPassword($email, $rawPassword);
            }
        } else {
            // User existant : si pas de password hash → on en pose un (mode rescue)
            $existing = DB::table('users')->where('id', $userId)->first();
            if ($existing && ($existing->password_hash === null || $existing->password_hash === '')) {
                DB::table('users')->where('id', $userId)->update([
                    'password_hash' => Hash::make($rawPassword),
                    'updated_at'    => now(),
                ]);
                if ($generated) {
                    $this->persistGeneratedPassword($email, $rawPassword);
                }
            }
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

    /**
     * Lit une env var via plusieurs canaux (env() Laravel + getenv() PHP natif).
     * Nécessaire dans certains contextes Docker où env() retourne null pendant
     * le seed même si la variable est posée dans le conteneur.
     */
    private function readEnv(string $key, string $default = ''): string
    {
        $value = env($key);
        if ($value === null || $value === false || $value === '') {
            $value = getenv($key);
        }
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    /**
     * Génère un password aléatoire de 32 caractères imprimables.
     */
    private function generateSecurePassword(int $length = 32): string
    {
        // alphabet sûr (sans char ambigu)
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^&*-_=+';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Persiste le password généré sur disk (local, mode 0600) ET log warning.
     * L'admin peut récupérer le password puis supprimer le fichier.
     */
    private function persistGeneratedPassword(string $email, string $password): void
    {
        $line = sprintf(
            "# Axion CRM Pro — owner initial password (généré au seed)\n# Email     : %s\n# Généré le : %s\n# IMPORTANT : copie ce password puis SUPPRIME ce fichier après 1er login.\n%s\n",
            $email,
            now()->toIso8601String(),
            $password,
        );

        try {
            Storage::disk('local')->put('seeders/owner-initial-password.txt', $line);
        } catch (\Throwable $e) {
            // Pas de Storage dispo (tests ?) → on ne casse pas le seed.
        }

        // Output stdout (artisan db:seed l'affichera)
        $this->command?->warn(str_repeat('=', 72));
        $this->command?->warn('OwnerUserSeeder — password généré automatiquement (OWNER_INITIAL_PASSWORD vide).');
        $this->command?->warn(sprintf('  Email    : %s', $email));
        $this->command?->warn(sprintf('  Password : %s', $password));
        $this->command?->warn('  Fichier  : storage/app/private/seeders/owner-initial-password.txt (mode 0600)');
        $this->command?->warn('  Action   : copie ce password puis rotate-le après 1er login.');
        $this->command?->warn(str_repeat('=', 72));
    }
}
