<?php

/**
 * FINITIONS P2 — le tri des médias par nom ignore les signes de tête.
 *
 * Constat de l'audit UX du 2026-10-02 : la liste des médias, triée par nom,
 * s'ouvrait sur « + Plus », « / Slash », « "Le Journal" »… La collation range
 * la ponctuation avant les lettres. Ce test rougit si le tri revient au nom
 * brut, ou si l'index qui le sert disparaît.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Models\User;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->espace = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $this->espace, 'slug' => 'zz-tri-' . substr(str_replace('-', '', $this->espace), 0, 8), 'name' => 'ZZ tri médias',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->espace);
    $this->user->assignRole('owner');
    $this->actingAs($this->user);
});

function triMedia(string $espace, string $nom): void
{
    DB::table('media')->insert([
        'workspace_id' => $espace, 'name' => $nom, 'media_type' => 'presse_journal',
        'media_family' => 'editorial', 'source' => 'naf-extract', 'enrich_status' => 'pending',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('medias : un nom qui commence par un signe se range a sa premiere lettre', function () {
    foreach (['Zeta Hebdo', '+ Plus Radio', '/ Slash Info', '"Alpha" Journal', "'Bravo' Magazine", 'Charlie TV', '20 Minutes ZZ'] as $nom) {
        triMedia($this->espace, $nom);
    }

    $noms = collect($this->getJson('/api/v1/media?per_page=100')->assertOk()->json('data'))->pluck('name')->all();

    expect($noms)->toBe([
        '20 Minutes ZZ',
        '"Alpha" Journal',
        "'Bravo' Magazine",
        'Charlie TV',
        '+ Plus Radio',
        '/ Slash Info',
        'Zeta Hebdo',
    ]);

    $inverse = collect($this->getJson('/api/v1/media?per_page=100&sort=-name')->assertOk()->json('data'))->pluck('name')->all();
    expect($inverse)->toBe(array_reverse($noms));
});

test('medias : l index du tri porte la meme expression que le tri', function () {
    $definition = DB::table('pg_indexes')->where('indexname', 'idx_media_tri_nom')->value('indexdef');

    expect($definition)->not->toBeNull()
        ->and($definition)->toContain('regexp_replace')
        ->and($definition)->toContain('workspace_id');
});
