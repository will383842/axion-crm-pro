<?php

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

/**
 * B14-010 — COMPLEMENT, PAS DOUBLON.
 *
 * Le constat « l'effacement d'un journaliste n'emet rien, dans le controleur
 * meme qui emet pour l'opposition » est DEJA ferme, correctif ET garde, dans
 * `tests/Feature/Crm/CanalSiteCrmTest.php` (trois tests marques `B14-010`) :
 * l'effacement met une ligne `erasure`, une fiche sans email n'en met aucune,
 * un effacement hors espace (404) n'emet rien.
 *
 * Ce fichier n'y revient pas. Il ajoute les trois mesures que cette garde ne
 * fait pas, et qui portent precisement sur le PATRON A-011 du constat — deux
 * formes du meme geste cote a cote dans un seul controleur :
 *
 *   1. l'instrument lit le TYPE d'evenement, il ne compte pas « une ligne » :
 *      `optOut()` et `destroy()`, joues dans le meme test, doivent produire
 *      DEUX types distincts, chacun sur SA personne ;
 *   2. temoin negatif — une simple LECTURE de fiche n'emet rien, donc
 *      l'instrument ne rapporte pas une emission la ou il n'y en a aucune ;
 *   3. le seul autre effacement de personne physique cable sur une route de la
 *      console (`DELETE /contacts/{id}`) rend encore 501 : il n'efface rien,
 *      donc il n'a rien a emettre AUJOURD'HUI. C'est le chiffre du jour qu'on
 *      fige, pour que le jour ou quelqu'un l'implemente, la garde rougisse.
 */
function effacementJournalisteHash(string $email): string
{
    return hash('sha256', mb_strtolower(trim($email)));
}

function effacementJournalisteFiche(string $workspaceId, ?string $email): int
{
    return (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $workspaceId,
        'last_name' => 'Durand',
        'first_name' => 'Camille',
        'email' => $email,
        'phone' => '+33612345678',
        'source' => 'ours',
        'opt_out' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-effacement-' . Str::random(6),
        'name' => 'WS',
        'settings' => [],
    ]);

    $this->utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.test',
        'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);

    // ⚠️ LE ROLE MONTE DANS LE `beforeEach` DEPUIS QUE F36-001 EST BRANCHE.
    //
    // Il n'etait pose que dans UN des tests de ce fichier. Tant qu'aucune route
    // n'exigeait de permission, les autres s'en passaient ; depuis,
    // `POST /journalists/{id}/opt-out` exige `rgpd.handle` et
    // `DELETE /journalists/{id}` exige `companies.delete`. Le compte de cette
    // suite EST celui de la console qui traite les demandes RGPD : `owner`.
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);
    $this->utilisateur->assignRole('owner');

    $this->actingAs($this->utilisateur);
});

// ── LES DEUX FORMES DU MEME CONTROLEUR, DANS LE MEME TEST ───────────────────

test('l\'opposition et l\'effacement emettent DEUX types distincts', function () {
    // C'est le coeur du constat : les deux methodes vivent a quelques lignes
    // l'une de l'autre et une seule emettait. Les jouer SEPAREMENT laisserait
    // vert un `destroy()` qui emettrait un `consent_optout` — c'est-a-dire un
    // canal qui dirait au site « cette personne s'oppose » la ou le CRM a
    // efface sa fiche. Le site n'en ferait pas la meme chose.
    $oppose = effacementJournalisteFiche($this->workspace->id, 'oppose@example.test');
    $efface = effacementJournalisteFiche($this->workspace->id, 'efface@example.test');

    expect(DB::table('crm_outbound_events')->count())->toBe(0);

    $this->postJson("/api/v1/journalists/{$oppose}/opt-out")->assertOk();
    $this->deleteJson("/api/v1/journalists/{$efface}")->assertNoContent();

    // TEMOIN DE COUVERTURE : les deux gestes ont REELLEMENT porte. Sans ces
    // deux lignes, une route devenue inerte donnerait « rien fait, donc rien
    // emis » — et le compte a 2 ci-dessous serait la seule chose a rougir.
    expect(DB::table('journalists')->where('id', $oppose)->value('opt_out'))->toBeTrue()
        ->and(DB::table('journalists')->where('id', $efface)->value('deleted_at'))->not->toBeNull();

    $opposition = DB::table('crm_outbound_events')->where('event_type', 'consent_optout')->first();
    $effacement = DB::table('crm_outbound_events')->where('event_type', 'erasure')->first();

    expect(DB::table('crm_outbound_events')->count())->toBe(2)
        ->and($opposition)->not->toBeNull()
        ->and($effacement)->not->toBeNull()
        // Chaque type porte SA personne : un croisement des deux hash passerait
        // une garde qui se contenterait de compter les types presents.
        ->and($opposition->email_hash)->toBe(effacementJournalisteHash('oppose@example.test'))
        ->and($effacement->email_hash)->toBe(effacementJournalisteHash('efface@example.test'))
        ->and($effacement->origin)->toBe('crm')
        ->and($effacement->status)->toBe('pending');

    // La surface est ce qui permet de retrouver, cote site, D'OU vient l'ordre.
    $payload = json_decode((string) $effacement->payload, true);
    expect($payload['surface'])->toBe('console:journalists')
        ->and($payload['journalist_id'])->toBe($efface);
});

// ── TEMOIN NEGATIF ──────────────────────────────────────────────────────────

test('TEMOIN NEGATIF : une LECTURE de fiche n\'emet rien', function () {
    // Prouve que l'instrument sait discriminer. Une garde qui verifierait « il
    // existe au moins une ligne d'outbox » passerait aussi ce scenario.
    $id = effacementJournalisteFiche($this->workspace->id, 'lecture@example.test');

    $this->getJson("/api/v1/journalists/{$id}")->assertOk();

    expect(DB::table('crm_outbound_events')->count())->toBe(0);
});

// ── SITE JUMEAU : RIEN A FERMER AUJOURD'HUI, DONC ON FIGE LE CHIFFRE ────────

test('l\'effacement d\'un CONTACT ne peut pas emettre : il n\'efface pas encore', function () {
    // `ContactsController::destroy()` est le seul autre effacement de personne
    // physique cable sur une route de la console. Il rend 501 : il n'efface
    // RIEN, donc il n'y a pas d'emission manquante a lui reprocher.
    //
    // Le jour ou quelqu'un l'implemente, ce test rougit et rappelle que
    // l'effacement d'une personne doit partir au site comme celui d'un
    // journaliste — sinon le patron A-011 se rejoue une fois de plus.
    // (le role est desormais pose dans le `beforeEach`)
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0'),
        'denomination' => 'ZZ Effacement',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contactId = DB::table('contacts')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'company_id' => $companyId,
        'last_name' => 'ZZ Contact',
        'email' => 'contact@example.test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->deleteJson("/api/v1/contacts/{$contactId}")->assertStatus(501);

    expect(DB::table('contacts')->where('id', $contactId)->value('deleted_at'))->toBeNull()
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});
