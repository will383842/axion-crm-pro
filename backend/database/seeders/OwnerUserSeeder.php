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
 * - si OWNER_INITIAL_PASSWORD est vide, le compte est cree SANS mot de passe, et
 *   l'operateur en pose un avec `infra/scripts/definir-mot-de-passe-crm.sh`.
 *
 * 🔴 CE SEEDER N'ECRIT PLUS AUCUN SECRET, NI SUR DISQUE NI SUR LA SORTIE.
 * Il generait un mot de passe de 32 caracteres et l'ecrivait EN CLAIR dans
 * storage/app/private/seeders/owner-initial-password.txt, tout en l'affichant
 * integralement sur la sortie standard du seed - donc dans les journaux de CI et
 * de Docker. Le commentaire annoncait un mode 0600 ; il etait faux : le disque
 * `local` ne declare aucune `visibility`, et LocalFilesystemAdapter n'applique
 * un chmod QUE si l'option lui est passee. Le fichier prenait donc le umask du
 * processus, soit 0644 - lisible par tout utilisateur du serveur.
 * Mesure le 2026-08-19 (audit 360, F35-008).
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
        $name = $this->readEnv('OWNER_INITIAL_NAME', 'Williams Jullin');
        $rawPassword = $this->readEnv('OWNER_INITIAL_PASSWORD', '');
        $sansMotDePasse = ($rawPassword === '' || $rawPassword === null);

        // Workspace
        $workspaceId = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
        if (! $workspaceId) {
            $workspaceId = (string) Str::uuid();
            DB::table('workspaces')->insert([
                'id' => $workspaceId,
                'slug' => 'axion-ia',
                'name' => 'Axion-IA',
                'settings' => '{}',
                'cost_cap_eur' => 1000,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // User owner
        $userId = DB::table('users')->where('email', $email)->value('id');
        if (! $userId) {
            $userId = (string) Str::uuid();
            DB::table('users')->insert([
                'id' => $userId,
                'email' => $email,
                'name' => $name,
                'password_hash' => $sansMotDePasse ? null : Hash::make($rawPassword),
                'current_workspace_id' => $workspaceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            // User existant : si pas de password hash → on en pose un (mode rescue)
            $existing = DB::table('users')->where('id', $userId)->first();
            if ($existing && ($existing->password_hash === null || $existing->password_hash === '') && ! $sansMotDePasse) {
                DB::table('users')->where('id', $userId)->update([
                    'password_hash' => Hash::make($rawPassword),
                    'updated_at' => now(),
                ]);
            }
        }

        // Pivot user_workspaces
        DB::table('user_workspaces')->updateOrInsert(
            ['user_id' => $userId, 'workspace_id' => $workspaceId],
            ['role_slug' => 'owner', 'invited_at' => now(), 'joined_at' => now()],
        );

        if ($sansMotDePasse) {
            $this->expliquerCommentPoserLeMotDePasse($email);
        }
        $this->signalerAncienFichierDeSecret();

        // Spatie : assigne rôle owner sur ce team_id
        $roleId = DB::table('roles')->where('name', 'owner')->whereNull('team_id')->value('id');
        if ($roleId) {
            DB::table('model_has_roles')->updateOrInsert(
                [
                    'role_id' => $roleId,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $userId,
                    'team_id' => $workspaceId,
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
     * Dit une ligne a l'operateur, s'il y a un operateur pour l'entendre.
     *
     * 🔴 LE `?->` EST OBLIGATOIRE, ET LARASTAN A TORT DE LE DIRE INUTILE.
     *
     * Mesure du 2026-08-21 par reflexion sur `Illuminate\Database\Seeder` :
     *
     *   type declare      : AUCUN
     *   valeur par defaut : NULL
     *
     * `$this->command` vaut donc `null` sur toute instance qui n'est pas venue
     * de la console — et ce seeder-la est joue hors console QUATRE fois, par
     * `$this->seed(OwnerUserSeeder::class)` dans `OwnerUserSeederTest`. Sans le
     * `?->`, `null->warn(...)` leve une `Error` et le test « n ecrit AUCUN
     * secret » tombe. Larastan lit le `@var \Illuminate\Console\Command` du
     * docblock du framework, qui ne porte pas le `?` : c'est le docblock qui est
     * faux, pas le code.
     *
     * Les quinze appels passent desormais par ici : une seule occurrence a
     * excuser dans la baseline au lieu de quinze, et la raison est ecrite a
     * cote du geste plutot que dans un fichier de configuration.
     */
    private function avertir(string $ligne): void
    {
        $this->command?->warn($ligne);
    }

    /**
     * Dit à l'opérateur ce qu'il lui reste à faire. NE MONTRE AUCUN SECRET.
     */
    private function expliquerCommentPoserLeMotDePasse(string $email): void
    {
        $this->avertir(str_repeat('=', 72));
        $this->avertir('OwnerUserSeeder — aucun OWNER_INITIAL_PASSWORD fourni.');
        $this->avertir(sprintf('  Compte créé SANS mot de passe : %s', $email));
        $this->avertir('  Pour lui en poser un, sur le serveur, en root :');
        $this->avertir("    read -rsp 'Mot de passe : ' P; echo; \\");
        $this->avertir("      printf '%s' \"\$P\" | bash infra/scripts/definir-mot-de-passe-crm.sh; unset P");
        $this->avertir("  Aucun secret n'est écrit sur disque ni affiché ici : c'est voulu.");
        $this->avertir(str_repeat('=', 72));
    }

    /**
     * Signale, sans y toucher, l'ancien fichier de mot de passe en clair.
     *
     * On ne le supprime PAS : détruire le seul exemplaire d'un secret sans le dire
     * est exactement l'erreur que cet audit a déjà payée une fois. On le nomme,
     * l'opérateur décide.
     */
    private function signalerAncienFichierDeSecret(): void
    {
        $chemin = 'seeders/owner-initial-password.txt';
        if (! Storage::disk('local')->exists($chemin)) {
            return;
        }

        $this->avertir(str_repeat('!', 72));
        $this->avertir('ATTENTION : un mot de passe en CLAIR subsiste sur ce serveur, écrit par');
        $this->avertir('une version précédente de ce seeder :');
        $this->avertir('  storage/app/private/' . $chemin);
        $this->avertir("Ce fichier n'est plus écrit ni utilisé. À supprimer à la main, après");
        $this->avertir('avoir changé le mot de passe du compte concerné.');
        $this->avertir(str_repeat('!', 72));
    }
}
