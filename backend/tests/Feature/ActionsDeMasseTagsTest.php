<?php

/**
 * ACTIONS DE MASSE sur les tags (plan §2.11).
 *
 * Poser une étiquette sur une sélection, c'est ce qui permet de constituer un
 * segment de campagne : « ces 40 fiches roumaines → `campagne-ro-sept` ».
 * Sans cela, il faut ouvrir 40 fiches une à une.
 *
 * 🔴 Les tests qui comptent ici ne sont pas ceux du chemin heureux, mais ceux
 * des REFUS. Une action de masse mal gardée est le genre de fonctionnalité
 * qui abîme des milliers de lignes en une seconde, et personne ne s'en aperçoit
 * avant longtemps.
 */

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-masse', 'name' => 'WS', 'settings' => [],
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'masse@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->user->assignRole('owner');
    $this->actingAs($this->user);
});

function creerTag(string $workspaceId, string $slug, bool $verrouille = false): int
{
    return DB::table('tags')->insertGetId([
        'workspace_id' => $workspaceId, 'slug' => $slug, 'name' => $slug,
        'rules' => '[]', 'category' => 'custom', 'kind' => 'manual',
        'is_locked' => $verrouille, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function creerFiche(string $workspaceId, string $nom): Company
{
    return Company::create([
        'workspace_id' => $workspaceId,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => $nom, 'signals' => [], 'metadata' => [],
    ]);
}

test('poser un tag sur une sélection', function () {
    creerTag($this->workspace->id, 'campagne-ro');
    $a = creerFiche($this->workspace->id, 'A');
    $b = creerFiche($this->workspace->id, 'B');
    $c = creerFiche($this->workspace->id, 'C');

    $r = $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$a->id, $b->id], 'tag' => 'campagne-ro', 'action' => 'add',
    ])->assertOk();

    expect($r->json('modifiees'))->toBe(2);
    expect(DB::table('company_tag')->count())->toBe(2);
    expect(DB::table('company_tag')->where('company_id', $c->id)->exists())->toBeFalse();
});

test('reposer un tag déjà présent n’est pas une erreur, c’est une non-action', function () {
    creerTag($this->workspace->id, 'campagne-ro');
    $a = creerFiche($this->workspace->id, 'A');

    $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$a->id], 'tag' => 'campagne-ro', 'action' => 'add',
    ])->assertOk();

    $r = $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$a->id], 'tag' => 'campagne-ro', 'action' => 'add',
    ])->assertOk();

    // Pas de doublon, pas d'erreur : l'action est idempotente.
    expect($r->json('modifiees'))->toBe(0);
    expect(DB::table('company_tag')->count())->toBe(1);
});

test('retirer un tag d’une sélection', function () {
    $tagId = creerTag($this->workspace->id, 'campagne-ro');
    $a = creerFiche($this->workspace->id, 'A');
    $b = creerFiche($this->workspace->id, 'B');
    DB::table('company_tag')->insert([
        ['company_id' => $a->id, 'tag_id' => $tagId, 'assigned_by' => 'user'],
        ['company_id' => $b->id, 'tag_id' => $tagId, 'assigned_by' => 'user'],
    ]);

    $r = $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$a->id], 'tag' => 'campagne-ro', 'action' => 'remove',
    ])->assertOk();

    expect($r->json('modifiees'))->toBe(1);
    expect(DB::table('company_tag')->where('company_id', $b->id)->exists())->toBeTrue();
});

test('🔴 un tag VERROUILLÉ est refusé — on ne fabrique pas une provenance', function () {
    // Les `src:*` disent d'où vient une fiche : c'est un FAIT constaté par la
    // collecte. Laisser un humain en poser un, c'est laisser inventer une
    // provenance — et toute la traçabilité RGPD repose dessus.
    creerTag($this->workspace->id, 'src:site-formulaire-audit', verrouille: true);
    $a = creerFiche($this->workspace->id, 'A');

    $r = $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$a->id], 'tag' => 'src:site-formulaire-audit', 'action' => 'add',
    ]);

    expect($r->status())->toBe(422);
    expect($r->json('error'))->toBe('tag_verrouille');
    expect(DB::table('company_tag')->count())->toBe(0);
});

test('🔴 un tag INCONNU est refusé — pas de création à la volée', function () {
    // Une faute de frappe créerait `campagne-ro-setp` sans rien dire, et le
    // segment serait introuvable le lendemain.
    $a = creerFiche($this->workspace->id, 'A');

    $r = $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$a->id], 'tag' => 'campagne-ro-setp', 'action' => 'add',
    ]);

    expect($r->status())->toBe(422);
    expect($r->json('error'))->toBe('tag_inconnu');
    expect(DB::table('tags')->where('slug', 'campagne-ro-setp')->exists())->toBeFalse();
});

test('🔴 une fiche d’un AUTRE univers est ignorée, et le dit', function () {
    creerTag($this->workspace->id, 'campagne-ro');
    $autre = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-autre-m', 'name' => 'Autre', 'settings' => [],
    ]);
    $mienne = creerFiche($this->workspace->id, 'MIENNE');
    $ailleurs = creerFiche($autre->id, 'AILLEURS');

    $r = $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$mienne->id, $ailleurs->id], 'tag' => 'campagne-ro', 'action' => 'add',
    ])->assertOk();

    expect($r->json('modifiees'))->toBe(1);
    // Le compte des ignorées est RENDU : une action qui annonce « fait » en
    // ayant écarté la moitié des lignes est pire qu'une erreur franche.
    expect($r->json('ignorees'))->toBe(1);
    expect(DB::table('company_tag')->where('company_id', $ailleurs->id)->exists())->toBeFalse();
});

test('🔴 la sélection est BORNÉE — 501 identifiants sont refusés', function () {
    creerTag($this->workspace->id, 'campagne-ro');

    // Sur 4,29 M de fiches, une case cochée par mégarde deviendrait
    // irréversible. Le plafond tient dans une requête et reste annulable.
    $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => range(1, 501), 'tag' => 'campagne-ro', 'action' => 'add',
    ])->assertStatus(422);
});

test('un VIEWER ne peut rien modifier en masse', function () {
    creerTag($this->workspace->id, 'campagne-ro');
    $a = creerFiche($this->workspace->id, 'A');

    $lecteur = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.com', 'name' => 'L',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $lecteur->assignRole('viewer');
    $this->actingAs($lecteur);

    $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$a->id], 'tag' => 'campagne-ro', 'action' => 'add',
    ])->assertForbidden();

    expect(DB::table('company_tag')->count())->toBe(0);
});

test('l’action est posée comme décision HUMAINE, pas comme observation', function () {
    creerTag($this->workspace->id, 'campagne-ro');
    $a = creerFiche($this->workspace->id, 'A');

    $this->postJson('/api/v1/companies/tags/bulk', [
        'ids' => [$a->id], 'tag' => 'campagne-ro', 'action' => 'add',
    ])->assertOk();

    // `assigned_by` distingue ce qu'un humain a posé de ce qu'une règle a
    // déduit. Les confondre ferait passer une décision pour une observation —
    // et fausserait tout audit de provenance.
    expect(DB::table('company_tag')->where('company_id', $a->id)->value('assigned_by'))->toBe('user');
});
