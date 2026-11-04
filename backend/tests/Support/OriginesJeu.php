<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Jeu de données FICTIF des tests de lecture des origines (PR-A, L6 + L2-back).
 * Adresses en `@example.invalid` uniquement (dépôt PUBLIC).
 */
final class OriginesJeu
{
    public static function espace(string $slug): Workspace
    {
        // Certains espaces (ex. `vivier-candidats`) sont déjà posés par les migrations : on les réutilise.
        return Workspace::query()->where('slug', $slug)->first()
            ?? Workspace::create(['id' => (string) Str::uuid(), 'slug' => $slug, 'name' => $slug, 'settings' => []]);
    }

    public static function ouvrir(string $espaceId, bool $ouverte = true): void
    {
        DB::table('fonctions_espace')->updateOrInsert(
            ['espace_id' => $espaceId, 'fonction' => 'origines.lecture'],
            ['ouverte' => $ouverte, 'updated_at' => now()],
        );
    }

    public static function utilisateur(string $espaceId, string $role, string $email): User
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'email' => $email,
            'name' => 'Opérateur origines',
            'password_hash' => Hash::make('PasswordTest12345!'),
            'current_workspace_id' => $espaceId,
            'first_login_completed_at' => now(),
        ]);
        setPermissionsTeamId($espaceId);
        $user->assignRole($role);
        DB::table('user_workspaces')->insertOrIgnore([
            'user_id' => $user->id, 'workspace_id' => $espaceId, 'role_slug' => 'owner',
            'invited_at' => now(), 'joined_at' => now(),
        ]);

        return $user;
    }

    /** @param  array<string, mixed>  $sur */
    public static function entree(array $sur = []): array
    {
        return array_replace([
            'mot' => 'guide', 'reseau' => 'instagram', 'guide' => 'guide-ia', 'date' => '2026-11-01T10:00:00Z',
            'post_ref' => 'post_ref_test_01', 'canal' => 'commentaire', 'famille' => 'guide_reseau', 'detail' => 'guide_ia',
            'compte' => 'page_principale',
        ], $sur);
    }

    /**
     * @param  list<array<string, mixed>>  $origines
     * @param  array<string, mixed>  $sur
     */
    public static function personne(string $espaceId, string $email, array $origines, array $sur = []): int
    {
        return (int) DB::table('personnes')->insertGetId(array_merge([
            'workspace_id' => $espaceId,
            'person_key' => hash('sha256', 'origines-lecture|' . $email),
            'email' => $email,
            'email_hash' => hash('sha256', $email),
            'first_name' => 'Zoé', 'last_name' => 'Test',
            'email_nature' => 'pro',
            'premiere_source' => 'newsletter',
            'premiere_source_at' => now()->subDays(10),
            'derniere_interaction_at' => now()->subDays(10),
            'legal_basis' => 'consent',
            'origines' => json_encode($origines, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ], $sur));
    }

    /** @param  array<string, mixed>  $o */
    public static function archive(string $espaceId, int $personneId, array $o, ?string $masqueLe = null): void
    {
        DB::table('personne_origines_archive')->insert([
            'workspace_id' => $espaceId, 'personne_id' => $personneId,
            'cle' => md5(json_encode($o, JSON_THROW_ON_ERROR)),
            'origine' => json_encode($o, JSON_THROW_ON_ERROR),
            'masque_le' => $masqueLe,
        ]);
    }

    /** Allume tout ce qu'il faut pour lire (interrupteurs + fonction d'espace). */
    public static function allumer(): void
    {
        config([
            'crm.console_v2' => true,
            'crm.ingest.business_workspace' => 'axion-ia',
            'origines.v3_enabled' => true,
            'origines.lecture_enabled' => true,
        ]);
    }
}
