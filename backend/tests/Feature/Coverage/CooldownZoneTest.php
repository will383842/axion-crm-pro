<?php

use App\Jobs\LaunchZoneScrapingJob;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Dedup\DeduplicationService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Les roles et permissions doivent exister AVANT `assignRole()`.
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * 🔴 LE COOLDOWN DE ZONE N'EXISTAIT QUE SUR LE PAPIER.
 *
 * `ZoneRotator::pickNextZone()` filtre sur
 * `cz.cooldown_until IS NULL OR cz.cooldown_until < now()`, en `LEFT JOIN` sur
 * `coverage_zones`. Mais `markZoneAttempted()` — le SEUL écrivain de cette
 * table dans tout le dépôt — n'avait AUCUN appelant.
 *
 * La table restait donc vide, le `LEFT JOIN` rendait toujours `NULL`, et la
 * condition était **tautologiquement vraie**. Le garde anti-répétition était
 * nul et non avenu : la même cellule pouvait être proposée indéfiniment, et
 * rien n'empêchait de re-scraper la même zone en boucle — au prix des quotas
 * proxy. Constaté le 2026-08-16.
 *
 * ⚠️ Ces tests ne passent PAS par `pickNextZone()` : il lit
 * `coverage_matrix_cells`, qui est une VUE MATÉRIALISÉE alimentée par les
 * entreprises réelles — on ne peut pas y insérer une cellule de test. Ils
 * portent donc sur ce qui était réellement cassé : l'écriture manquante, et
 * son déclenchement depuis le lancement d'une zone.
 */
function utilisateurAvecWorkspace(): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'cov-' . Str::random(6),
        'name' => 'Couverture',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'cov' . Str::random(4) . '@test.local',
        'name' => 'Couverture',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    // ⚠️ LE ROLE EST OBLIGATOIRE DEPUIS QUE F36-001 EST BRANCHE.
    //
    // Cette suite mesure le METIER (creer, lancer, annuler), pas les droits —
    // et son utilisateur n'en avait aucun. Tant qu'aucune route ne portait
    // `permission:`, cela ne se voyait pas ; depuis, elle recevait 403 partout.
    //
    // On lui donne `admin` : le geste teste ici EST celui d'un administrateur,
    // et le lui refuser reviendrait a mesurer la garde au lieu du produit. Les
    // droits sont mesures a leur place, sur trois roles :
    // `tests/Feature/Rgpd/CoucheAutorisationBrancheeTest.php`.
    setPermissionsTeamId($workspace->id);
    $user->assignRole('admin');

    // Chaîne de clés étrangères à remonter : `coverage_zones.department` →
    // `departments.code` → `departments.region_code` → `regions.code`.
    // En production ces référentiels sont peuplés (18 régions, 101
    // départements) ; ici il faut les semer, sinon le marquage échoue — et
    // c'est précisément ce que le `try/catch` du contrôleur rattrape désormais
    // sans casser le lancement.
    DB::table('countries')->insertOrIgnore([
        'code_iso2' => 'FR', 'code_iso3' => 'FRA',
        'name_fr' => 'France', 'name_en' => 'France',
    ]);
    DB::table('regions')->insertOrIgnore([
        'code' => '84', 'name' => 'Auvergne-Rhône-Alpes', 'country_code' => 'FR',
    ]);

    foreach (['38' => 'Isère', '69' => 'Rhône'] as $code => $nom) {
        DB::table('departments')->insertOrIgnore([
            'code' => $code, 'name' => $nom, 'region_code' => '84',
        ]);
    }

    return [$user, $workspace];
}

test('LANCER une zone pose désormais son cooldown', function () {
    Queue::fake();
    [$user, $workspace] = utilisateurAvecWorkspace();

    expect(DB::table('coverage_zones')->count())->toBe(0);

    $this->actingAs($user)
        ->postJson('/api/v1/coverage/launch', ['department' => '38', 'limit' => 10])
        ->assertOk();

    Queue::assertPushed(LaunchZoneScrapingJob::class);

    $zone = DB::table('coverage_zones')->where('workspace_id', $workspace->id)->first();

    expect($zone)->not->toBeNull()
        ->and($zone->department)->toBe('38')
        ->and($zone->cooldown_until)->not->toBeNull();
});

test('relancer la MÊME zone met à jour la ligne, il n’y en a pas deux', function () {
    Queue::fake();
    [$user, $workspace] = utilisateurAvecWorkspace();

    foreach ([1, 2] as $ignore) {
        $this->actingAs($user)
            ->postJson('/api/v1/coverage/launch', ['department' => '38', 'limit' => 10])
            ->assertOk();
    }

    expect(DB::table('coverage_zones')->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('deux zones DIFFÉRENTES ont chacune leur cooldown', function () {
    Queue::fake();
    [$user, $workspace] = utilisateurAvecWorkspace();

    foreach (['38', '69'] as $departement) {
        $this->actingAs($user)
            ->postJson('/api/v1/coverage/launch', ['department' => $departement, 'limit' => 10])
            ->assertOk();
    }

    expect(DB::table('coverage_zones')->where('workspace_id', $workspace->id)->count())->toBe(2);
});

test('un département INEXISTANT ne casse pas le lancement — le cooldown est une optimisation', function () {
    Queue::fake();
    [$user] = utilisateurAvecWorkspace();

    // `department` n'est validé que par `string|max:3` : rien n'empêche un code
    // absent de `departments`. Avant le `try/catch`, la violation de clé
    // étrangère aurait fait echouer TOUTE la requete.
    $this->actingAs($user)
        ->postJson('/api/v1/coverage/launch', ['department' => 'ZZ', 'limit' => 10])
        ->assertOk();

    Queue::assertPushed(LaunchZoneScrapingJob::class);
    expect(DB::table('coverage_zones')->count())->toBe(0);
});

test('isZoneInCooldown bascule avec le marquage, et respecte l’échéance', function () {
    [, $workspace] = utilisateurAvecWorkspace();
    $dedup = app(DeduplicationService::class);

    expect($dedup->isZoneInCooldown($workspace->id, '38', null, null))->toBeFalse();

    $dedup->markZoneAttempted($workspace->id, '38', null, null);
    expect($dedup->isZoneInCooldown($workspace->id, '38', null, null))->toBeTrue();

    // L'échéance passée, la zone redevient attaquable — sans quoi le cooldown
    // serait un blocage définitif, pas un délai.
    DB::table('coverage_zones')->update(['cooldown_until' => now()->subHour()]);
    expect($dedup->isZoneInCooldown($workspace->id, '38', null, null))->toBeFalse();
});
