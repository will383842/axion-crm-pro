<?php

/**
 * API DES ÉVÉNEMENTS — liste, fiche, démarche (2026-09-27). Fixtures fictives.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-evt-api', 'name' => 'WS', 'settings' => [],
    ]);
    $this->user = evtApiCompte($this->workspace->id, 'admin');
    $this->actingAs($this->user);

    $this->club = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->workspace->id, 'siren' => null, 'country_code' => 'FR',
        'foreign_id' => 'evt:zz-club-api', 'entity_nature' => 'reseau', 'denomination' => 'ZZ Club API',
        'signals' => json_encode(['contact_form_url' => 'https://zz-club.example.invalid/contact']),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

function evtApiCompte(string $workspaceId, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@example.invalid',
        'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($workspaceId);
    $user->assignRole($role);

    return $user;
}

/** @param  array<string, mixed>  $attrs */
function evtApiEvenement(string $workspaceId, array $attrs = [], ?int $organisateur = null): int
{
    $id = (int) DB::table('events')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'external_ref' => 'zz-' . Str::random(8),
        'nom' => 'ZZ Événement',
        'type' => 'salon',
        'date_debut' => now()->addDays(10)->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
    if ($organisateur !== null) {
        DB::table('event_organizers')->insert([
            'event_id' => $id, 'company_id' => $organisateur, 'workspace_id' => $workspaceId, 'created_at' => now(),
        ]);
    }

    return $id;
}

test('la liste filtre par periode et renvoie les organisateurs', function () {
    $avenir = evtApiEvenement($this->workspace->id, ['nom' => 'ZZ A venir'], $this->club);
    $passe = evtApiEvenement($this->workspace->id, ['nom' => 'ZZ Passe', 'date_debut' => now()->subDays(10)->toDateString()]);
    $recurrent = evtApiEvenement($this->workspace->id, ['nom' => 'ZZ BNI', 'date_debut' => null, 'recurrence' => 'chaque mardi']);

    $ids = fn (string $periode) => collect($this->getJson('/api/v1/evenements?periode=' . $periode)->assertOk()->json('data'))->pluck('id')->all();

    expect($ids('a_venir'))->toBe([$avenir])
        ->and($ids('passes'))->toBe([$passe])
        ->and($ids('sans_date'))->toBe([$recurrent]);

    $ligne = $this->getJson('/api/v1/evenements?periode=a_venir')->json('data.0');
    expect($ligne['organisateurs'][0]['id'])->toBe($this->club)
        ->and($ligne['organisateurs'][0])->not->toHaveKey('email_generic');
});

test('la recherche trouve un evenement par le nom de son organisateur', function () {
    $trouve = evtApiEvenement($this->workspace->id, ['nom' => 'ZZ Petit dejeuner'], $this->club);
    evtApiEvenement($this->workspace->id, ['nom' => 'ZZ Autre']);

    $ids = collect($this->getJson('/api/v1/evenements?q=Club%20API')->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$trouve]);
});

test('un evenement d un autre espace est introuvable', function () {
    $autre = (string) Str::uuid();
    Workspace::create(['id' => $autre, 'slug' => 'ws-evt-autre', 'name' => 'Autre', 'settings' => []]);
    $etranger = evtApiEvenement($autre);

    $this->getJson('/api/v1/evenements/' . $etranger)->assertNotFound();
    $this->patchJson('/api/v1/evenements/' . $etranger . '/demarche', ['intervention' => 'proposee'])->assertNotFound();
    expect(collect($this->getJson('/api/v1/evenements?periode=')->json('data'))->pluck('id')->all())->not->toContain($etranger);
});

test('la fiche expose le formulaire de l organisateur, jamais ses coordonnees', function () {
    $id = evtApiEvenement($this->workspace->id, [], $this->club);

    $fiche = $this->getJson('/api/v1/evenements/' . $id)->assertOk()->json();

    expect($fiche['organisateurs'][0]['contact_form_url'])->toBe('https://zz-club.example.invalid/contact')
        ->and($fiche['organisateurs'][0])->not->toHaveKey('email_generic')
        ->and($fiche['organisateurs'][0])->not->toHaveKey('phone');
});

test('faire avancer la demarche ecrit l etat ET une ligne d historique par etape', function () {
    $id = evtApiEvenement($this->workspace->id, [], $this->club);

    $fiche = $this->patchJson('/api/v1/evenements/' . $id . '/demarche', [
        'participation' => 'inscrit',
        'intervention' => 'proposee',
        'prochaine_relance_at' => '2026-10-20',
        'demarche_note' => 'ZZ relancer apres le salon',
    ])->assertOk()->json();

    expect($fiche['participation'])->toBe('inscrit')
        ->and($fiche['intervention'])->toBe('proposee')
        ->and(collect($fiche['historique'])->pluck('kind')->sort()->values()->all())
        ->toBe(['evenement_inscrit', 'intervention_proposee']);

    // Témoin : renvoyer les MÊMES étapes n'ajoute AUCUNE ligne d'historique.
    $this->patchJson('/api/v1/evenements/' . $id . '/demarche', ['participation' => 'inscrit', 'intervention' => 'proposee'])->assertOk();
    expect(DB::table('activities')->where('subject_type', 'event')->where('subject_id', $id)->count())->toBe(2);
});

test('les relances a faire ne listent que les dates echues', function () {
    $due = evtApiEvenement($this->workspace->id, ['prochaine_relance_at' => now()->subDay()]);
    evtApiEvenement($this->workspace->id, ['prochaine_relance_at' => now()->addWeek()]);
    evtApiEvenement($this->workspace->id);

    $ids = collect($this->getJson('/api/v1/evenements?relance=a_faire')->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$due]);
});

test('une valeur hors vocabulaire est refusee', function () {
    $id = evtApiEvenement($this->workspace->id);

    $this->patchJson('/api/v1/evenements/' . $id . '/demarche', ['intervention' => 'peut-etre'])->assertStatus(422);
    // Une date que Postgres refuserait donnerait une 500 : refusée avant.
    $this->patchJson('/api/v1/evenements/' . $id . '/demarche', ['prochaine_relance_at' => 'next monday'])->assertStatus(422);
    $this->getJson('/api/v1/evenements?type=kermesse')->assertStatus(422);
});

test('un compte en lecture seule lit mais ne fait pas avancer la demarche', function () {
    $id = evtApiEvenement($this->workspace->id);
    $this->actingAs(evtApiCompte($this->workspace->id, 'viewer'));

    $this->getJson('/api/v1/evenements/' . $id)->assertOk();
    $this->patchJson('/api/v1/evenements/' . $id . '/demarche', ['intervention' => 'proposee'])->assertForbidden();
    expect(DB::table('events')->where('id', $id)->value('intervention'))->toBe('aucune');
});

test('le bloc d une entreprise d un autre espace est introuvable', function () {
    $autre = (string) Str::uuid();
    Workspace::create(['id' => $autre, 'slug' => 'ws-evt-autre-bloc', 'name' => 'Autre', 'settings' => []]);
    $etrangere = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $autre, 'siren' => '900000931', 'denomination' => 'ZZ Etrangere',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    evtApiEvenement($autre, [], $etrangere);

    $this->getJson('/api/v1/companies/' . $etrangere . '/evenements')->assertNotFound();
});

test('la note de demarche est masquee a un compte sans droit sur les coordonnees', function () {
    $id = evtApiEvenement($this->workspace->id, ['demarche_note' => 'ZZ rappeler zz.note@example.invalid']);

    expect($this->getJson('/api/v1/evenements/' . $id)->json('demarche_note'))->toContain('zz.note@example.invalid');

    $this->actingAs(evtApiCompte($this->workspace->id, 'viewer'));
    expect($this->getJson('/api/v1/evenements/' . $id)->json('demarche_note'))->toBeNull();
});

test('le bloc de la fiche entreprise liste les evenements de l organisateur', function () {
    $sien = evtApiEvenement($this->workspace->id, [], $this->club);
    evtApiEvenement($this->workspace->id);

    $ids = collect($this->getJson('/api/v1/companies/' . $this->club . '/evenements')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$sien]);
});
