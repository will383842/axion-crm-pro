<?php

/**
 * MASQUAGE DES COORDONNÉES POUR LES COMPTES EN LECTURE SEULE (plan §2.10).
 *
 * Un `viewer` doit pouvoir consulter et se repérer, pas repartir avec 665 771
 * adresses lisibles à l'écran. L'export lui était déjà fermé (#82) ; sans
 * masquage, il lui suffisait de faire défiler et de recopier.
 *
 * 🔴 Le test qui compte le plus est le premier : un PROPRIÉTAIRE doit voir les
 * coordonnées EN CLAIR. Une garde qui masque pour tout le monde ne protège
 * rien — elle rend seulement l'outil inutilisable, et on ne s'en aperçoit
 * qu'en production.
 */

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MasquageCoordonnees;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-masq', 'name' => 'WS', 'settings' => [],
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $this->entreprise = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '123456789',
        'denomination' => 'ACME',
        'email_generic' => 'contact@acme.fr',
        'phone' => '+33612345678',
        'signals' => [], 'metadata' => [],
    ]);

    Contact::create([
        'workspace_id' => $this->workspace->id,
        'company_id' => $this->entreprise->id,
        'last_name' => 'Durand',
        'email' => 'pierre.durand@acme.fr',
        'phone' => '+33698765432',
        'sources' => [], 'metadata' => [],
    ]);
});

function utilisateur(string $workspaceId, ?string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.com',
        'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    if ($role !== null) {
        $u->assignRole($role);
    }

    return $u;
}

test('🔴 un propriétaire voit les coordonnées EN CLAIR', function () {
    // Une garde qui masque pour tout le monde ne protège rien.
    $this->actingAs(utilisateur($this->workspace->id, 'owner'));

    $ligne = $this->getJson('/api/v1/contacts')->assertOk()->json('data.0');

    expect($ligne['email'])->toBe('pierre.durand@acme.fr');
    expect($ligne['phone'])->toBe('+33698765432');
});

test('un opérateur aussi — il travaille la donnée', function () {
    $this->actingAs(utilisateur($this->workspace->id, 'operator'));

    expect($this->getJson('/api/v1/contacts')->assertOk()->json('data.0.email'))
        ->toBe('pierre.durand@acme.fr');
});

test('un VIEWER ne voit que des coordonnées masquées', function () {
    $this->actingAs(utilisateur($this->workspace->id, 'viewer'));

    $ligne = $this->getJson('/api/v1/contacts')->assertOk()->json('data.0');

    expect($ligne['email'])->toBe('p***@acme.fr');
    expect($ligne['phone'])->toBe('+336******32');
    // La donnée ne doit PAS transiter en clair « au cas où » : ce serait
    // masquer à l'écran et livrer dans la réponse.
    expect(json_encode($ligne))->not->toContain('pierre.durand@acme.fr');
});

test('un compte SANS rôle est masqué — la garde ferme par défaut', function () {
    // Un état inconnu qui vaudrait « montre tout » ne serait pas une garde.
    $this->actingAs(utilisateur($this->workspace->id, null));

    expect($this->getJson('/api/v1/contacts')->assertOk()->json('data.0.email'))
        ->toBe('p***@acme.fr');
});

test('le masquage couvre AUSSI la liste entreprises et le hub console', function () {
    $this->actingAs(utilisateur($this->workspace->id, 'viewer'));

    $entreprise = $this->getJson('/api/v1/companies')->assertOk()->json('data.0');
    expect($entreprise['email_generic'])->toBe('c***@acme.fr');
    expect($entreprise['phone'])->toBe('+336******78');

    // Fermer un écran sur trois n'aurait fermé qu'une porte sur trois.
    // La console v2 vit derrière son drapeau : sans lui, la route répond 404
    // et le test mesurerait l'inertie, pas le masquage.
    config()->set('crm.console_v2', true);

    $hub = $this->getJson('/api/v1/crm/contacts-hub?temperature=tous')->assertOk()->json('data.0');
    expect($hub['email_generic'])->toBe('c***@acme.fr');
    expect($hub['contacts'][0]['email'])->toBe('p***@acme.fr');
});

test('le masque garde le domaine et la première lettre', function () {
    // Assez pour reconnaître un interlocuteur déjà connu, pas assez pour
    // livrer l'adresse à qui ne la connaît pas.
    expect(MasquageCoordonnees::email('pierre.durand@acme.fr'))->toBe('p***@acme.fr');
    expect(MasquageCoordonnees::email('a@b.fr'))->toBe('a***@b.fr');
    expect(MasquageCoordonnees::email(null))->toBeNull();
    expect(MasquageCoordonnees::email(''))->toBe('');

    // Valeur abîmée (pas d'arobase) : on masque TOUT plutôt que de la laisser
    // passer par défaut.
    expect(MasquageCoordonnees::email('pas-une-adresse'))->toBe('***');
    expect(MasquageCoordonnees::email('@sansLocale.fr'))->toBe('***');
});

test('le masque du téléphone laisse de quoi vérifier, pas de quoi reconstituer', function () {
    expect(MasquageCoordonnees::telephone('+33612345678'))->toBe('+336******78');
    expect(MasquageCoordonnees::telephone('0612345678'))->toBe('0612****78');
    // Trop court pour qu'un masque partiel garde quoi que ce soit.
    expect(MasquageCoordonnees::telephone('12345'))->toBe('***');
    expect(MasquageCoordonnees::telephone(null))->toBeNull();
});
