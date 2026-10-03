<?php

/**
 * PASTILLES « À TRAITER » DU MENU — `GET /v1/crm/a-traiter/compteurs`
 * (audit UX du 02/10/2026, lot 8).
 *
 * Ce que ces gardes tiennent :
 *  1. la forme : `{ doublons: int|null, a_rattacher: int|null }` ;
 *  2. le chiffre du menu = le total de l'écran (`/doublons`, `/crm/arbitrage`) ;
 *  3. le cloisonnement : un espace ne voit jamais les compteurs d'un autre, ni
 *     par la requête, ni par le cache ;
 *  4. un compteur en échec vaut `null`, JAMAIS 0, et n'emporte pas l'autre ;
 *  5. sans authentification : 401 ; drapeau de la console fermé : 404 ;
 *  6. les deux requêtes utilisent un index SOUS LE RÔLE DE PRODUCTION
 *     (`axion_app`, sécurité par espace forcée) — sous le propriétaire, tout
 *     paraît rapide (#287, #292, #294).
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Console\FilesATraiter;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Taxonomy;
use App\Http\Controllers\Api\Crm\ATraiterController;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const CAT_URL = '/api/v1/crm/a-traiter/compteurs';

beforeEach(function () {
    config(['crm.console_v2' => true]);
    Cache::flush();
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
});

function catCompte(string $ws, string $role = 'viewer'): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'cat-' . Str::random(8) . '@example.invalid', 'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole($role);

    return $user;
}

/** Une paire de doublons EN ATTENTE dans l'espace. */
function catPaire(string $ws, string $nom): int
{
    $a = F::fiche($ws, $nom, ['postcode' => '69200']);
    $b = F::sansSiren($ws, $nom, ['postcode' => '69200']);

    return (int) DB::table('duplicate_flags')->insertGetId([
        'workspace_id' => $ws, 'entity_type' => 'company', 'entity_a_id' => $a, 'entity_b_id' => $b,
        'similarity' => 0.85, 'motif' => Rapprochement::NOM_CP, 'fusion_auto' => false,
    ]);
}

/** @param  array<string, mixed>  $payload */
function catActivite(string $ws, array $payload, ?int $sujet = null): int
{
    return (int) DB::table('activities')->insertGetId([
        'workspace_id' => $ws, 'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => now()->subDay(), 'person_key' => hash('sha256', Str::random(12)),
        'external_ref' => 'site:event:' . Str::uuid(),
        'subject_type' => $sujet === null ? null : 'company', 'subject_id' => $sujet,
        'title' => 'Formulaire — ZZ', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(),
    ]);
}

/** Un espace peuplé : 2 paires en attente, 2 personnes à rattacher, et du bruit qui ne compte pas. */
function catEspacePeuple(string $prefixe): string
{
    $ws = F::espace($prefixe);
    catPaire($ws, 'ZZ Alpha');
    catPaire($ws, 'ZZ Beta');
    // Bruit : une paire revue, une paire dont une fiche est à la corbeille.
    DB::table('duplicate_flags')->where('id', catPaire($ws, 'ZZ Revue'))->update(['reviewed_at' => now(), 'resolution' => 'keep_both']);
    $corbeille = catPaire($ws, 'ZZ Corbeille');
    DB::table('companies')->where('id', DB::table('duplicate_flags')->where('id', $corbeille)->value('entity_b_id'))
        ->update(['deleted_at' => now()]);

    catActivite($ws, ['pending_match' => ['denomination' => 'ZZ UN']]);
    catActivite($ws, ['pending_match' => ['denomination' => 'ZZ DEUX']]);
    // Bruit : une écartée, une déjà rattachée, une sans rapprochement en attente.
    catActivite($ws, ['pending_match' => ['denomination' => 'ZZ ÉCARTÉE'], 'arbitrage_dismissed' => ['reason' => 'test']]);
    catActivite($ws, ['pending_match' => ['denomination' => 'ZZ RATTACHÉE']], F::fiche($ws, 'ZZ Cible'));
    catActivite($ws, ['autre' => true]);

    return $ws;
}

test('la forme de la réponse, et le chiffre du menu = le total de chaque écran', function () {
    $ws = catEspacePeuple('zz-cat-forme');
    $this->actingAs(catCompte($ws));

    $r = $this->getJson(CAT_URL)->assertOk();

    expect($r->json())->toBe(['doublons' => 2, 'a_rattacher' => 2]);
    expect($r->json('doublons'))->toBe($this->getJson('/api/v1/doublons')->assertOk()->json('meta.total'));
    expect($r->json('a_rattacher'))->toBe($this->getJson('/api/v1/crm/arbitrage')->assertOk()->json('meta.total'));
});

test('cloisonnement : un espace ne voit jamais les compteurs d un autre, ni par le cache', function () {
    $a = catEspacePeuple('zz-cat-a');
    $b = F::espace('zz-cat-b');
    catPaire($b, 'ZZ Seule');

    // A d'abord : son résultat part en cache.
    $this->actingAs(catCompte($a));
    $this->getJson(CAT_URL)->assertOk()->assertExactJson(['doublons' => 2, 'a_rattacher' => 2]);

    // B ensuite : SES chiffres (des entiers, pas null), jamais ceux de A.
    $this->actingAs(catCompte($b));
    $this->getJson(CAT_URL)->assertOk()->assertExactJson(['doublons' => 1, 'a_rattacher' => 0]);

    expect(ATraiterController::cle($a))->not->toBe(ATraiterController::cle($b))
        ->and(ATraiterController::cle($a))->toContain($a);
});

test('la réponse est mise en cache 60 s par espace', function () {
    $ws = catEspacePeuple('zz-cat-cache');
    $this->actingAs(catCompte($ws));
    $this->getJson(CAT_URL)->assertOk()->assertJsonPath('doublons', 2);

    catPaire($ws, 'ZZ Nouvelle');
    $this->getJson(CAT_URL)->assertOk()->assertJsonPath('doublons', 2);

    Cache::forget(ATraiterController::cle($ws));
    $this->getJson(CAT_URL)->assertOk()->assertJsonPath('doublons', 3);
});

test('écarter une paire ou un événement remet la pastille à jour sans attendre le cache', function () {
    $ws = catEspacePeuple('zz-cat-geste');
    $this->actingAs(catCompte($ws, 'operator'));
    $this->getJson(CAT_URL)->assertOk()->assertExactJson(['doublons' => 2, 'a_rattacher' => 2]);

    $paire = (int) DB::table('duplicate_flags')->where('workspace_id', $ws)->whereNull('reviewed_at')
        ->whereNotIn('entity_b_id', DB::table('companies')->whereNotNull('deleted_at')->select('id'))->value('id');
    $this->postJson("/api/v1/doublons/{$paire}/ignorer")->assertOk();
    $this->getJson(CAT_URL)->assertOk()->assertJsonPath('doublons', 1);

    $activite = (int) FilesATraiter::aRattacher($ws)->value('id');
    $this->postJson("/api/v1/crm/arbitrage/{$activite}/dismiss", ['reason' => 'ZZ test'])->assertOk();
    $this->getJson(CAT_URL)->assertOk()->assertJsonPath('a_rattacher', 1);
});

test('un compteur en échec vaut null, jamais 0, et n emporte pas l autre', function () {
    $ws = catEspacePeuple('zz-cat-echec');
    $this->actingAs(catCompte($ws));

    // La file des doublons devient illisible (table introuvable) le temps du
    // test — la transaction du test l'annule ensuite.
    DB::statement('ALTER TABLE duplicate_flags RENAME TO duplicate_flags_zz_absente');

    $r = $this->getJson(CAT_URL)->assertOk();

    expect($r->json('doublons'))->toBeNull()
        ->and(array_key_exists('doublons', $r->json()))->toBeTrue()
        ->and($r->json('a_rattacher'))->toBe(2);
});

test('univers vivier : pas de file d arbitrage, donc null (pas de pastille), jamais 0', function () {
    $vivier = (string) DB::table('workspaces')->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)->value('id');
    expect($vivier)->not->toBe('');
    $this->actingAs(catCompte($vivier));

    $this->getJson(CAT_URL)->assertOk()
        ->assertJsonPath('a_rattacher', null)
        ->assertJsonPath('doublons', 0);
});

test('sans authentification : 401 ; drapeau de la console fermé : 404', function () {
    $this->getJson(CAT_URL)->assertUnauthorized();

    config(['crm.console_v2' => false]);
    $this->actingAs(catCompte(F::espace('zz-cat-ferme')));
    $this->getJson(CAT_URL)->assertNotFound();
});

test('la route est bornée à trois secondes de SQL', function () {
    $route = app('router')->getRoutes()->match(Request::create(CAT_URL, 'GET'));

    expect($route->gatherMiddleware())->toContain('delai-sql:3')
        ->and($route->gatherMiddleware())->toContain('crm-console');
});

// ── Sous le rôle de production ──────────────────────────────────────────────

function catApp(): Connection
{
    return DB::connection('pgsql_app');
}

/** Le plan d'un comptage, sous `axion_app`, sans balayage séquentiel permis. */
function catPlan(string $espace, Builder $file): string
{
    $q = $file->selectRaw('count(*) AS aggregate');
    catApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
    catApp()->statement('SET enable_seqscan = off');

    $lignes = catApp()->select('EXPLAIN ' . $q->toSql(), $q->getBindings());

    return implode("\n", array_map(static fn ($l): string => (string) array_values((array) $l)[0], $lignes));
}

afterEach(function () {
    // Connexion jamais ouverte par ce test : surtout ne pas en ouvrir une.
    if (! array_key_exists('pgsql_app', DB::getConnections())) {
        return;
    }

    try {
        catApp()->statement('RESET enable_seqscan');
        catApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    } catch (Throwable) {
        // Connexion déjà perdue : elle part avec le `disconnect()`.
    }
    catApp()->disconnect();
});

test('index idx_activities_a_rattacher présent, valide, et au prédicat de la file', function () {
    $index = DB::selectOne(
        'SELECT i.indisvalid AS valide, pg_get_indexdef(i.indexrelid) AS def
           FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
          WHERE c.relname = ?',
        ['idx_activities_a_rattacher'],
    );

    expect($index)->not->toBeNull()
        ->and((bool) $index->valide)->toBeTrue()
        ->and($index->def)->toContain('(workspace_id, occurred_at, id)')
        ->and($index->def)->toContain('subject_id IS NULL')
        ->and($index->def)->toContain("'pending_match'")
        ->and($index->def)->toContain("'arbitrage_dismissed'");
});

test('sous axion_app : « Personnes à rattacher » passe par l index partiel, « Doublons » par des index', function () {
    $role = catApp()->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

    $espace = (string) Str::uuid();

    $planRattacher = catPlan($espace, FilesATraiter::aRattacher($espace));
    expect($planRattacher)->toContain('idx_activities_a_rattacher')
        ->and($planRattacher)->not->toContain('Seq Scan');

    $planDoublons = catPlan($espace, FilesATraiter::doublons($espace));
    expect($planDoublons)->not->toContain('Seq Scan');
});
