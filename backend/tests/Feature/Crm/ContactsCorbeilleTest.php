<?php

/**
 * Lot G1-B (cahier #619, § 4) — corbeille DOUCE des contacts : retirer, restaurer, lister la corbeille, et l'ancien
 * `DELETE /api/v1/contacts/{id}` (qui rendait 501). Aucune ligne n'est jamais effacée. HTTP réel pour les droits ;
 * le service est joué sous le rôle applicatif (`SET LOCAL ROLE` + `WorkspaceContext`) ET sous le propriétaire.
 * Données fictives (example.test).
 */

use App\Crm\Contacts\GestesContact;
use App\Crm\Identite\CleDePersonne;
use App\Crm\Ingest\ContactUpserter;
use App\Crm\Rgpd\MiseAEcart;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Rgpd\GdprErasureService;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.console_v2' => true]);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->espaceA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-cb-a', 'name' => 'Espace A', 'settings' => []]);
    $this->espaceB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-cb-b', 'name' => 'Espace B', 'settings' => []]);
    $this->entrepriseA = cbEntreprise($this->espaceA->id, '900000501');
    $this->entrepriseB = cbEntreprise($this->espaceB->id, '900000502');
    $this->contactA = cbContact($this->espaceA->id, $this->entrepriseA, 'Durand', 'Alice', 'alice@example.test');
    $this->contactB = cbContact($this->espaceB->id, $this->entrepriseB, 'Martin', 'Bob', 'bob@example.test');
});

function cbActeur(string $espaceId, string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Personne factice',
        'password_hash' => Hash::make('MotDePasseDeTest12345!'), 'current_workspace_id' => $espaceId,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espaceId);
    $u->assignRole($role);
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $u->id, 'workspace_id' => $espaceId, 'role_slug' => $role, 'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $u;
}

function cbEntreprise(string $espaceId, string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espaceId, 'siren' => $siren, 'denomination' => 'ZZ TEST ' . $siren,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cbContact(string $espaceId, int $entrepriseId, string $nom, string $prenom, ?string $email, ?string $supprimeLe = null, string $metadata = '{}'): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $espaceId, 'company_id' => $entrepriseId, 'first_name' => $prenom, 'last_name' => $nom,
        'email' => $email, 'person_key' => CleDePersonne::pour($email), 'discovery_source' => 'site', 'sources' => '["site"]',
        'metadata' => $metadata, 'legal_basis' => 'legitimate_interest_b2b', 'deleted_at' => $supprimeLe,
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
}

/** @return array<string, mixed> */
function cbLigne(int $id): array
{
    return (array) DB::table('contacts')->where('id', $id)->first();
}

/** @return array<string, mixed> */
function cbMeta(int $id): array
{
    $m = json_decode((string) cbLigne($id)['metadata'], true);

    return is_array($m) ? $m : [];
}

test('1. viewer et operator → 403 sur retirer, restaurer et DELETE, contact intact ; témoin admin → 200', function () {
    $avant = cbLigne($this->contactA);
    foreach (['viewer', 'operator'] as $role) {
        $this->actingAs(cbActeur($this->espaceA->id, $role));
        $this->postJson("/api/v1/crm/contacts/{$this->contactA}/retirer")->assertForbidden();
        $this->postJson("/api/v1/crm/contacts/{$this->contactA}/restaurer")->assertForbidden();
        $this->deleteJson("/api/v1/contacts/{$this->contactA}")->assertForbidden();
    }
    expect(cbLigne($this->contactA))->toBe($avant)->and(cbLigne($this->contactA)['deleted_at'])->toBeNull();
    expect(DB::table('business_events')->count())->toBe(0);

    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));
    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/retirer")->assertOk()->assertJsonPath('retire', true);
});

test('2. retirer : la ligne EXISTE toujours, deleted_at et marque posés, contacts_retires inchangé, journal sans valeurs', function () {
    $retiresAvant = DB::table('contacts_retires')->count();
    $admin = cbActeur($this->espaceA->id, 'admin');
    $this->actingAs($admin);

    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/retirer")->assertOk();

    expect(DB::table('contacts')->where('id', $this->contactA)->count())->toBe(1);
    $l = cbLigne($this->contactA);
    expect($l['deleted_at'])->not->toBeNull();
    $m = cbMeta($this->contactA);
    expect($m)->toHaveKeys(['retire_console_le', 'retire_console_par'])->and($m['retire_console_par'])->toBe($admin->id);
    expect(DB::table('contacts_retires')->count())->toBe($retiresAvant);

    $brut = json_encode(DB::table('business_events')->where('workspace_id', $this->espaceA->id)->get()->all());
    expect($brut)->toContain('contact.retire')->not->toContain('alice@example.test')->not->toContain('Durand');

    // Idempotent : déjà retiré → 200, marque inchangée.
    $avant = cbMeta($this->contactA);
    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/retirer")->assertOk()->assertJsonPath('retire', true);
    expect(cbMeta($this->contactA))->toBe($avant);
});

test('3. restaurer : deleted_at NULL, marques retirées, restaure_console_le posé, la ligne revient dans GET /contacts', function () {
    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));
    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/retirer")->assertOk();
    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/restaurer")->assertOk();

    expect(cbLigne($this->contactA)['deleted_at'])->toBeNull();
    $m = cbMeta($this->contactA);
    expect($m)->toHaveKey('restaure_console_le')->not->toHaveKey('retire_console_le')->not->toHaveKey('retire_console_par');
    // Pas de GET intermédiaire à vide : dans un même test, il fausserait la liste suivante (mémoïsation de test).
    expect(array_column($this->getJson('/api/v1/contacts')->assertOk()->json('data'), 'id'))->toContain($this->contactA);
    expect(DB::table('business_events')->where('workspace_id', $this->espaceA->id)->pluck('action')->all())
        ->toContain('contact.restaure');
});

test('4. mis à l’écart (RGPD) : absent de la corbeille, restaurer → 409, deleted_at inchangé', function () {
    $id = cbContact($this->espaceA->id, $this->entrepriseA, 'Oppose', 'Paul', 'paul@example.test');
    MiseAEcart::contacts([$id], 'opposition');
    // Même si une marque console s'y trouvait aussi, le refus RGPD prime.
    DB::statement("UPDATE contacts SET metadata = metadata || '{\"retire_console_le\":\"2026-12-01T10:00\"}'::jsonb WHERE id = ?", [$id]);
    $avant = cbLigne($id);
    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));

    $ids = collect($this->getJson('/api/v1/crm/contacts/corbeille')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($id);
    $this->postJson("/api/v1/crm/contacts/{$id}/restaurer")->assertStatus(409);
    expect(cbLigne($id))->toBe($avant)->and(cbLigne($id)['deleted_at'])->not->toBeNull();
});

test('5. en corbeille SANS marque console : absent de la corbeille, restaurer → 404, inchangé', function () {
    $id = cbContact($this->espaceA->id, $this->entrepriseA, 'Manuel', 'Max', 'max@example.test', now()->toDateTimeString());
    $avant = cbLigne($id);
    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));

    expect(collect($this->getJson('/api/v1/crm/contacts/corbeille')->json('data'))->pluck('id')->all())->not->toContain($id);
    $this->postJson("/api/v1/crm/contacts/{$id}/restaurer")->assertNotFound();
    expect(cbLigne($id))->toBe($avant);
});

test('6. autre espace : retirer/restaurer → 404, B intact ; la corbeille de A ne montre jamais B', function () {
    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));
    $avantB = cbLigne($this->contactB);
    $this->postJson("/api/v1/crm/contacts/{$this->contactB}/retirer")->assertNotFound();
    $this->postJson("/api/v1/crm/contacts/{$this->contactB}/restaurer")->assertNotFound();
    $this->deleteJson("/api/v1/contacts/{$this->contactB}")->assertNotFound();
    expect(cbLigne($this->contactB))->toBe($avantB);

    // B a un contact retiré par la console : A ne le voit pas.
    $retireB = cbContact($this->espaceB->id, $this->entrepriseB, 'Retire', 'Bea', 'bea@example.test', now()->toDateTimeString(), '{"retire_console_le":"2026-12-01T10:00"}');
    $this->postJson("/api/v1/crm/contacts/{$retireB}/restaurer")->assertNotFound();
    expect(collect($this->getJson('/api/v1/crm/contacts/corbeille')->json('data'))->pluck('id')->all())->not->toContain($retireB);
    expect(cbLigne($retireB)['deleted_at'])->not->toBeNull();
});

test('7. ingestion après retrait : la ligne reste en corbeille', function () {
    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));
    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/retirer")->assertOk();

    app(ContactUpserter::class)->upsert(
        $this->espaceA->id,
        $this->entrepriseA,
        (string) CleDePersonne::pour('alice@example.test'),
        null,
        'alice@example.test',
        'Alice',
        'Durand',
        null,
        'legitimate_interest_b2b',
    );

    expect(DB::table('contacts')->where('workspace_id', $this->espaceA->id)->count())->toBe(1);
    expect(cbLigne($this->contactA)['deleted_at'])->not->toBeNull();
});

test('8. DELETE /contacts/{id} (admin) → 204, ligne présente, deleted_at et marque console posés', function () {
    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));
    $this->deleteJson("/api/v1/contacts/{$this->contactA}")->assertNoContent();

    expect(DB::table('contacts')->where('id', $this->contactA)->count())->toBe(1);
    expect(cbLigne($this->contactA)['deleted_at'])->not->toBeNull();
    expect(cbMeta($this->contactA))->toHaveKey('retire_console_le');
});

test('9. source : aucune suppression physique dans app/Crm/Contacts/ ni ContactsGestesController', function () {
    $fichiers = array_merge(
        glob(app_path('Crm/Contacts/*.php')) ?: [],
        [app_path('Http/Controllers/Api/Crm/ContactsGestesController.php')],
    );
    expect($fichiers)->not->toBeEmpty();
    foreach ($fichiers as $f) {
        $src = (string) file_get_contents($f);
        expect(preg_match('/forceDelete|->delete\(|DELETE\s+FROM/i', $src))->toBe(0, "suppression physique dans {$f}");
    }
    // Le DELETE HTTP ne supprime pas non plus : il délègue au geste de retrait.
    $ctl = (string) file_get_contents(app_path('Http/Controllers/Api/ContactsController.php'));
    expect(preg_match('/function destroy[^}]*(forceDelete|->delete\()/s', $ctl))->toBe(0);
});

test('10. entreprise en corbeille : restaurer → 409, contact inchangé', function () {
    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));
    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/retirer")->assertOk();
    DB::table('companies')->where('id', $this->entrepriseA)->update(['deleted_at' => now()]);
    $avant = cbLigne($this->contactA);

    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/restaurer")->assertStatus(409);
    expect(cbLigne($this->contactA))->toBe($avant);
});

test('11. la corbeille liste forme attendue, la plus récente d’abord, e-mail masqué si requis', function () {
    $this->actingAs(cbActeur($this->espaceA->id, 'admin'));
    $autre = cbContact($this->espaceA->id, $this->entrepriseA, 'Second', 'Sam', 'sam@example.test');
    $this->postJson("/api/v1/crm/contacts/{$this->contactA}/retirer")->assertOk();
    $this->postJson("/api/v1/crm/contacts/{$autre}/retirer")->assertOk();
    DB::table('contacts')->where('id', $this->contactA)->update(['deleted_at' => now()->subHour()]);

    $r = $this->getJson('/api/v1/crm/contacts/corbeille')->assertOk();
    expect(collect($r->json('data'))->pluck('id')->all())->toBe([$autre, $this->contactA]);
    expect($r->json('data.0'))->toHaveKeys(['id', 'person_key', 'first_name', 'last_name', 'email', 'organisation', 'retire_le']);
    expect($r->json('data.0.organisation'))->toBe('ZZ TEST 900000501');
});

test('12. le service sous rôle applicatif ET propriétaire : retirer/restaurer/corbeille cloisonnés, rien d’effacé', function (string $mode) {
    $ws = $this->espaceA->id;
    $joue = function (callable $f) use ($mode, $ws) {
        DB::beginTransaction();
        try {
            if ($mode === 'app') {
                WorkspaceContext::set($ws);
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }

            return $f(new GestesContact);
        } finally {
            DB::rollBack();
        }
    };

    $r = $joue(function (GestesContact $g) use ($ws) {
        $a = $g->retirer($ws, $this->contactA, 'u1');
        $liste = $g->corbeille($ws);
        $nb = DB::table('contacts')->where('workspace_id', $ws)->count();
        $b = $g->retirer($ws, $this->contactB, 'u1');
        $c = $g->restaurer($ws, $this->contactA);

        return compact('a', 'liste', 'nb', 'b', 'c');
    });
    expect($r['a']['etat'])->toBe('ok')->and($r['nb'])->toBe(1)
        ->and(collect($r['liste'])->pluck('id')->all())->toBe([$this->contactA])
        ->and($r['b']['etat'])->toBe('introuvable')->and($r['c']['etat'])->toBe('ok');
    expect(cbLigne($this->contactB)['deleted_at'])->toBeNull();

    // Une corbeille de B n'apparaît pas dans celle de A.
    DB::table('contacts')->where('id', $this->contactB)->update(['deleted_at' => now(), 'metadata' => '{"retire_console_le":"2026-12-01T10:00"}']);
    $liste = $joue(fn (GestesContact $g) => $g->corbeille($ws));
    expect($liste)->toBe([]);
    expect($joue(fn (GestesContact $g) => $g->restaurer($ws, $this->contactB)['etat']))->toBe('introuvable');
})->with(['app', 'proprio']);

test('13. opposition posée APRÈS le retrait console : restaurer → 409, reste en corbeille', function () {
    $g = new GestesContact;
    WorkspaceContext::run($this->espaceA->id, fn () => $g->retirer($this->espaceA->id, $this->contactA, (string) Str::uuid()));
    DB::table('email_suppressions')->insert(['scope' => 'business', 'email_hash' => ListeSuppression::empreinte('alice@example.test'), 'reason' => 'manual', 'source' => 'test']);

    $r = WorkspaceContext::run($this->espaceA->id, fn () => $g->restaurer($this->espaceA->id, $this->contactA));

    expect($r['etat'])->toBe('mis_a_l_ecart')->and(cbLigne($this->contactA)['deleted_at'])->not->toBeNull();
});

test('14. retirer puis effacement RGPD (GdprErasureService) puis restaurer → 409, sous rôle applicatif ET propriétaire', function (string $mode) {
    $ws = $this->espaceA->id;
    $g = new GestesContact;
    WorkspaceContext::run($ws, fn () => $g->retirer($ws, $this->contactA, (string) Str::uuid()));
    app(GdprErasureService::class)->erase('alice@example.test');
    $avant = cbLigne($this->contactA);

    DB::beginTransaction();
    try {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $r = $g->restaurer($ws, $this->contactA);
    } finally {
        DB::rollBack();
    }

    expect($r['etat'])->toBe('mis_a_l_ecart')->and(cbLigne($this->contactA))->toBe($avant)->and($avant['deleted_at'])->not->toBeNull();
})->with(['app', 'proprio']);

test('15. sans opposition, restaurer fonctionne toujours (témoin du contrôle)', function () {
    $g = new GestesContact;
    WorkspaceContext::run($this->espaceA->id, fn () => $g->retirer($this->espaceA->id, $this->contactA, (string) Str::uuid()));

    $r = WorkspaceContext::run($this->espaceA->id, fn () => $g->restaurer($this->espaceA->id, $this->contactA));

    expect($r['etat'])->toBe('ok')->and(cbLigne($this->contactA)['deleted_at'])->toBeNull();
});
