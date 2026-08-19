<?php

/**
 * GARDE DES ÉCRITURES MÉTIER — audit 360, F36-003 (S0).
 *
 * Les routes de création, modification et suppression d'entreprises, de contacts
 * et d'étiquettes n'exigeaient AUCUNE permission. Mesuré : un compte `viewer`,
 * dont le rôle s'appelle littéralement « Lecture seule », créait, modifiait et
 * **supprimait définitivement** — le DELETE rendait 204.
 *
 * Le modèle de droits était pourtant juste et déjà semé :
 *   viewer   → companies.view seulement
 *   operator → create + update, PAS delete (« CRUD sans destruction »)
 *   admin/owner → tout
 *
 * Ces gardes vérifient les TROIS niveaux, parce que vérifier seulement le viewer
 * laisserait passer un correctif qui refuserait tout le monde.
 */

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function compteEcriture(string $role): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ecr-' . Str::random(8),
        'name' => 'Espace écritures',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@ecritures.test',
        'name' => ucfirst($role),
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspace->id);
    $user->assignRole($role);

    return $user;
}

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * ⚠️ DE VRAIS ENREGISTREMENTS, PAS DES IDENTIFIANTS INVENTES.
 *
 * La resolution du modele (`SubstituteBindings`) passe AVANT la garde de
 * permission dans la pile de Laravel. Un identifiant qui n'existe pas — ou pire,
 * qui n'a pas le bon type : `tags.id` est un BIGINT, pas un UUID — fait echouer
 * la requete Postgres et rend 500 AVANT que le droit ne s'exprime. On mesurerait
 * alors le pilote de base, pas le controle d'acces. Constate en jouant ces gardes.
 */
function uneEntreprise(string $workspaceId): int
{
    // `companies.id` est un BIGINT, pas un UUID — comme `tags.id`. C'est
    // exactement pourquoi on ne fabrique pas d'identifiant : on en demande un.
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $workspaceId,
        'denomination' => 'Entreprise de test',
        // `companies_identity_anchor_check` : une fiche doit porter un SIREN ou
        // un identifiant etranger. Le schema refuse une entreprise sans ancre
        // d'identite — c'est une bonne contrainte, on la respecte.
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function uneEtiquette(string $workspaceId): int
{
    return (int) DB::table('tags')->insertGetId([
        'workspace_id' => $workspaceId,
        'name' => 'Etiquette de test ' . Str::random(6),
        'slug' => 'etq-' . Str::random(8),
        'color' => '#123456',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('F36-003 — un compte LECTURE SEULE ne peut pas creer une etiquette', function () {
    $this->actingAs(compteEcriture('viewer'))
        ->postJson('/api/v1/tags', ['name' => 'Interdit', 'color' => '#ff0000'])
        ->assertForbidden();
});

test('F36-003 — un compte LECTURE SEULE ne peut pas supprimer une etiquette', function () {
    $viewer = compteEcriture('viewer');
    $etiquette = uneEtiquette((string) $viewer->current_workspace_id);

    $this->actingAs($viewer)->deleteJson('/api/v1/tags/' . $etiquette)->assertForbidden();

    // Et elle est toujours la : le refus a bien empeche la destruction.
    expect(DB::table('tags')->where('id', $etiquette)->exists())->toBeTrue();
});

test('F36-003 — un compte LECTURE SEULE ne peut pas creer une entreprise', function () {
    $this->actingAs(compteEcriture('viewer'))
        ->postJson('/api/v1/companies', ['name' => 'Interdite'])
        ->assertForbidden();
});

test('F36-003 — un compte LECTURE SEULE ne peut pas supprimer une entreprise', function () {
    $viewer = compteEcriture('viewer');
    $entreprise = uneEntreprise((string) $viewer->current_workspace_id);

    $this->actingAs($viewer)->deleteJson('/api/v1/companies/' . $entreprise)->assertForbidden();

    expect(DB::table('companies')->where('id', $entreprise)->exists())->toBeTrue();
});

test('F36-003 — un OPERATEUR peut modifier mais PAS detruire (« CRUD sans destruction »)', function () {
    $operateur = compteEcriture('operator');

    // Il crée : c'est son métier.
    expect($this->actingAs($operateur)->postJson('/api/v1/tags', ['name' => 'Permise', 'color' => '#00ff00'])->status())
        ->not->toBe(403);

    // Il ne détruit pas : c'est la définition même de son rôle.
    $entreprise = uneEntreprise((string) $operateur->current_workspace_id);
    $this->actingAs($operateur)->deleteJson('/api/v1/companies/' . $entreprise)->assertForbidden();
    expect(DB::table('companies')->where('id', $entreprise)->exists())->toBeTrue();
});

test('F36-003 — TEMOIN : un ADMIN franchit toutes ces gardes', function () {
    $admin = compteEcriture('admin');

    // Sans ce témoin, un correctif qui refuserait TOUT LE MONDE passerait pour
    // une réussite. Le statut d'arrivée dépend du métier ; seul 403 est interdit.
    expect($this->actingAs($admin)->postJson('/api/v1/tags', ['name' => 'Admin', 'color' => '#0000ff'])->status())
        ->not->toBe(403);
    expect($this->actingAs($admin)->deleteJson('/api/v1/companies/' . uneEntreprise((string) $admin->current_workspace_id))->status())
        ->not->toBe(403);
});

test('F36-003 — TEMOIN : la LECTURE reste ouverte a tous', function () {
    // La garde doit discriminer l'action, jamais la personne.
    expect($this->actingAs(compteEcriture('viewer'))->getJson('/api/v1/tags')->status())
        ->not->toBe(403);
});
