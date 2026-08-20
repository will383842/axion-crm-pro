<?php

/**
 * B12-002 / F36-006 (S1) — LE MASQUAGE NE COUVRAIT QUE LES LISTES.
 *
 * Constat mesure le 2026-08-20 sur ce depot : la piece de masquage
 * (`App\Support\MasquageCoordonnees`) existait et etait employee a TROIS
 * endroits — `CompaniesController::index`, `ContactsController::index`,
 * `Crm\ContactsHubController::index`. Elle manquait aux fiches DETAILLEES :
 *
 *   - `GET /api/v1/companies/{id}`  (CompaniesController::show, ligne 349)
 *   - `GET /api/v1/contacts/{id}`   (ContactsController::show, ligne 125)
 *
 * Ces deux routes ne portent AUCUNE permission supplementaire dans
 * `routes/api.php` (lignes 138 et 157) : le role `viewer`, qui n'a que
 * `companies.view`, `llm.view_usage` et `rgpd.view` (seeder
 * PermissionsAndRolesSeeder ligne 68), les atteint toutes les deux.
 *
 * Le masquage de la liste ne coutait donc RIEN a qui voulait la base : les
 * identifiants de `companies` sont des entiers consecutifs. Un viewer lisait la
 * liste masquee, relevait les identifiants, et rappelait chaque fiche en clair.
 *
 * ── Ce que cette garde surveille, et pourquoi elle ne peut pas verdir a vide ─
 *
 * 1. Le premier test est le TEMOIN A CLAIR : un `owner` DOIT voir les
 *    coordonnees entieres sur la fiche detaillee. Une correction qui masquerait
 *    pour tout le monde le ferait rougir. Sans lui, « tout masquer » serait un
 *    vert.
 * 2. Le second test est le TEMOIN DE PRESENCE : il verifie que la fiche porte
 *    bien une entreprise ET un contact, avec des coordonnees non vides. Sans
 *    lui, une reponse vide (404, relation non chargee, tableau `contacts` vide)
 *    ferait passer les assertions de masquage au vert par ABSENCE de donnee.
 * 3. Les assertions de fuite se jouent sur le corps ENTIER de la reponse
 *    (`assertStringNotContainsString`), pas sur un champ : masquer le champ
 *    affiche et laisser la valeur en clair ailleurs dans le JSON (une relation,
 *    un `metadata`) resterait une fuite.
 */

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Valeurs en clair. Elles ne doivent JAMAIS apparaitre dans une reponse servie
// a un compte depourvu de `contacts.view_pii`.
const FICHE_EMAIL_ENTREPRISE = 'contact@acme-fiche.fr';
const FICHE_TEL_ENTREPRISE = '+33612345678';
const FICHE_EMAIL_CONTACT = 'pierre.durand@acme-fiche.fr';
const FICHE_TEL_CONTACT = '+33698765432';

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-fiche', 'name' => 'WS', 'settings' => [],
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $this->entreprise = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '123456789',
        'denomination' => 'ACME',
        'email_generic' => FICHE_EMAIL_ENTREPRISE,
        'phone' => FICHE_TEL_ENTREPRISE,
        'signals' => [], 'metadata' => [],
    ]);

    $this->contact = Contact::create([
        'workspace_id' => $this->workspace->id,
        'company_id' => $this->entreprise->id,
        'last_name' => 'Durand',
        'email' => FICHE_EMAIL_CONTACT,
        'phone' => FICHE_TEL_CONTACT,
        'sources' => [], 'metadata' => [],
    ]);
});

function utilisateurFiche(string $workspaceId, ?string $role): User
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

// ═══════════════════════════════════════════════════════════════════════════
// TEMOINS — ils rendent impossible un vert obtenu par absence de donnee ou par
// masquage universel. Ils passent AVANT correction comme APRES.
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN a clair : un proprietaire lit la fiche detaillee en entier', function () {
    // Une garde qui masquerait pour tout le monde ne protegerait rien : elle
    // rendrait l'outil inutilisable, et on ne s'en apercevrait qu'en prod.
    $this->actingAs(utilisateurFiche($this->workspace->id, 'owner'));

    $reponse = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();

    expect($reponse->json('email_generic'))->toBe(FICHE_EMAIL_ENTREPRISE);
    expect($reponse->json('phone'))->toBe(FICHE_TEL_ENTREPRISE);
    expect($reponse->json('contacts.0.email'))->toBe(FICHE_EMAIL_CONTACT);
    expect($reponse->json('contacts.0.phone'))->toBe(FICHE_TEL_CONTACT);

    $fiche = $this->getJson("/api/v1/contacts/{$this->contact->id}")->assertOk();
    expect($fiche->json('email'))->toBe(FICHE_EMAIL_CONTACT);
    expect($fiche->json('phone'))->toBe(FICHE_TEL_CONTACT);
});

test('TEMOIN de presence : la fiche vue par un viewer porte bien une entreprise ET un contact', function () {
    // Sans ce test, une reponse vide (404, relation absente, tableau contacts
    // vide) ferait verdir toutes les assertions de masquage par ABSENCE.
    $this->actingAs(utilisateurFiche($this->workspace->id, 'viewer'));

    $reponse = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();

    expect($reponse->json('id'))->toBe($this->entreprise->id);
    expect($reponse->json('denomination'))->toBe('ACME');
    expect($reponse->json('contacts'))->toHaveCount(1);
    // Les coordonnees sont PRESENTES (non nulles, non vides) : ce qui suit
    // mesure donc bien un masquage, pas un champ efface.
    expect($reponse->json('email_generic'))->not->toBeNull()->not->toBe('');
    expect($reponse->json('phone'))->not->toBeNull()->not->toBe('');
    expect($reponse->json('contacts.0.email'))->not->toBeNull()->not->toBe('');
    expect($reponse->json('contacts.0.phone'))->not->toBeNull()->not->toBe('');
});

// ═══════════════════════════════════════════════════════════════════════════
// LE DEFAUT — quatrieme et cinquieme endroit qui rendent une coordonnee.
// ═══════════════════════════════════════════════════════════════════════════

test('un viewer ne lit PAS la fiche entreprise en clair', function () {
    $this->actingAs(utilisateurFiche($this->workspace->id, 'viewer'));

    $reponse = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();

    expect($reponse->json('email_generic'))->toBe('c***@acme-fiche.fr');
    expect($reponse->json('phone'))->toBe('+336******78');

    // La fuite se mesure sur le corps ENTIER : masquer le champ affiche et
    // laisser la valeur ailleurs (relation, metadata) resterait une fuite.
    $corps = $reponse->getContent();
    $this->assertIsString($corps);
    $this->assertStringNotContainsString(FICHE_EMAIL_ENTREPRISE, $corps);
    $this->assertStringNotContainsString(FICHE_TEL_ENTREPRISE, $corps);
});

test('un viewer ne lit PAS les contacts imbriques de la fiche entreprise en clair', function () {
    // C'est le pire des deux : la fiche entreprise charge `contacts`, donc elle
    // livre les coordonnees NOMINATIVES, pas seulement l'accueil de la societe.
    $this->actingAs(utilisateurFiche($this->workspace->id, 'viewer'));

    $reponse = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();

    expect($reponse->json('contacts.0.email'))->toBe('p***@acme-fiche.fr');
    expect($reponse->json('contacts.0.phone'))->toBe('+336******32');

    $corps = $reponse->getContent();
    $this->assertIsString($corps);
    $this->assertStringNotContainsString(FICHE_EMAIL_CONTACT, $corps);
    $this->assertStringNotContainsString(FICHE_TEL_CONTACT, $corps);
});

test('un viewer ne lit PAS la fiche contact en clair (site jumeau, non nomme par l audit)', function () {
    // `ContactsController::show` rendait le modele BRUT. La liste des contacts
    // masque via `present()` depuis le 2026-08-15 ; sa fiche, non.
    $this->actingAs(utilisateurFiche($this->workspace->id, 'viewer'));

    $reponse = $this->getJson("/api/v1/contacts/{$this->contact->id}")->assertOk();

    expect($reponse->json('email'))->toBe('p***@acme-fiche.fr');
    expect($reponse->json('phone'))->toBe('+336******32');

    $corps = $reponse->getContent();
    $this->assertIsString($corps);
    $this->assertStringNotContainsString(FICHE_EMAIL_CONTACT, $corps);
    $this->assertStringNotContainsString(FICHE_TEL_CONTACT, $corps);
});

test('un compte SANS role est masque sur la fiche detaillee — la garde ferme par defaut', function () {
    // Un etat inconnu qui vaudrait « montre tout » ne serait pas une garde.
    $this->actingAs(utilisateurFiche($this->workspace->id, null));

    $reponse = $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();

    expect($reponse->json('email_generic'))->toBe('c***@acme-fiche.fr');
    $this->assertStringNotContainsString(FICHE_EMAIL_CONTACT, (string) $reponse->getContent());
});

test('le masquage de la fiche ne PERSISTE pas la valeur masquee en base', function () {
    // Poser l'attribut masque sur le modele Eloquent le rend « sale ». Si quoi
    // que ce soit le sauvegardait ensuite, le masque REMPLACERAIT la donnee :
    // une reduction d'exposition deviendrait une destruction de base.
    $this->actingAs(utilisateurFiche($this->workspace->id, 'viewer'));

    $this->getJson("/api/v1/companies/{$this->entreprise->id}")->assertOk();
    $this->getJson("/api/v1/contacts/{$this->contact->id}")->assertOk();

    expect(Company::find($this->entreprise->id)->email_generic)->toBe(FICHE_EMAIL_ENTREPRISE);
    expect(Company::find($this->entreprise->id)->phone)->toBe(FICHE_TEL_ENTREPRISE);
    expect(Contact::find($this->contact->id)->email)->toBe(FICHE_EMAIL_CONTACT);
    expect(Contact::find($this->contact->id)->phone)->toBe(FICHE_TEL_CONTACT);
});
