<?php

/**
 * GARDE DU CLOISONNEMENT ENTRE ESPACES — audit 360, F36-005 / B12-001 (S0).
 *
 * `GET /contacts/{id}` et `GET /companies/{id}` rendaient l'enregistrement trouvé
 * par la résolution de route, SANS aucun filtre d'espace. Un compte de l'espace
 * BETA lisait donc la fiche d'une personne de l'espace ALPHA en devinant un
 * identifiant — et ce sont des entiers consécutifs.
 *
 * La ceinture applicative existait (`WorkspaceScope`), mais elle est inerte tant
 * que `CRM_STRICT_WORKSPACE_SCOPE` est à false, et c'est le défaut. On ne bascule
 * pas ce drapeau : il ferait échouer les 26 tâches planifiées qui s'exécutent
 * sans contexte d'espace (B11-001). La fuite est donc fermée au point d'entrée.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array{0: User, 1: string} le compte et l'identifiant de son espace */
function compteDansEspace(string $nom): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => strtolower($nom) . '-' . Str::random(6),
        'name' => $nom,
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => strtolower($nom) . '-' . Str::random(6) . '@cloison.test',
        'name' => $nom,
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspace->id);
    $user->assignRole('admin');

    return [$user, (string) $workspace->id];
}

function entrepriseDe(string $workspaceId): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $workspaceId,
        'denomination' => 'Entreprise ' . Str::random(5),
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function contactDe(string $workspaceId, int $companyId): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $workspaceId,
        'company_id' => $companyId,
        'first_name' => 'Jean',
        'last_name' => 'Dupont',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

test('F36-005 — un compte ne lit PAS la fiche entreprise d un autre espace', function () {
    [, $espaceAlpha] = compteDansEspace('ALPHA');
    [$beta] = compteDansEspace('BETA');

    $entrepriseAlpha = entrepriseDe($espaceAlpha);

    // 404 et non 403 : « interdit » confirmerait l'existence de la fiche.
    $this->actingAs($beta)->getJson('/api/v1/companies/' . $entrepriseAlpha)->assertNotFound();
});

test('F36-005 — un compte ne lit PAS la fiche contact d un autre espace', function () {
    [, $espaceAlpha] = compteDansEspace('ALPHA');
    [$beta] = compteDansEspace('BETA');

    $contactAlpha = contactDe($espaceAlpha, entrepriseDe($espaceAlpha));

    $this->actingAs($beta)->getJson('/api/v1/contacts/' . $contactAlpha)->assertNotFound();
});

test('F36-005 — TEMOIN : chacun lit bien LES SIENNES', function () {
    [$alpha, $espaceAlpha] = compteDansEspace('ALPHA');

    $entreprise = entrepriseDe($espaceAlpha);
    $contact = contactDe($espaceAlpha, $entreprise);

    // Sans ce témoin, un correctif qui rendrait 404 à TOUT LE MONDE passerait
    // pour une réussite.
    $this->actingAs($alpha)->getJson('/api/v1/companies/' . $entreprise)->assertOk();
    $this->actingAs($alpha)->getJson('/api/v1/contacts/' . $contact)->assertOk();
});

test('F36-005 — les identifiants sont consecutifs : deviner ne coute rien', function () {
    [, $espaceAlpha] = compteDansEspace('ALPHA');

    $premier = entrepriseDe($espaceAlpha);
    $second = entrepriseDe($espaceAlpha);

    // Ce n'est pas un défaut en soi — c'est ce qui rend le défaut précédent
    // exploitable sans effort, et c'est pourquoi la garde compte.
    expect($second)->toBe($premier + 1);
});
