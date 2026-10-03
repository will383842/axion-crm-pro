<?php

/**
 * « SANTÉ DU SYSTÈME » — `GET /api/v1/observability/summary` (2026-10-03).
 *
 * En production, l'écran restait sur « Chargement de la santé du système… » :
 * deux comptages sur `companies` (4,35 M de fiches) prenaient 4,6 s et 4,1 s
 * sous le rôle applicatif, recalculés à chaque ouverture et toutes les 30 s.
 *
 * Ce que ces gardes tiennent :
 *  1. le résumé est mis en cache PAR ESPACE (la clé porte son identifiant ;
 *     un espace ne lit jamais le résumé d'un autre) ;
 *  2. un résumé INCOMPLET (une rubrique tombée dans son filet F39-007) est
 *     rendu, mais jamais mis en cache ;
 *  3. une rubrique SANS filet qui échoue reste un 500, et rien ne part en cache ;
 *  4. la route est bornée (`delai-sql:20`) ;
 *  5. sous le RÔLE DE PRODUCTION (`axion_app`, sécurité par espace forcée), les
 *     deux comptages passent par leur index — sous le propriétaire, tout paraît
 *     rapide (#287, #292, #294) — et l'index partiel Google Places est valide,
 *     au prédicat de la requête.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Http\Controllers\Api\ObservabilityController;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use App\Services\Scraping\GooglePlacesClient;
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

const SDS_URL = '/api/v1/observability/summary';

beforeEach(function () {
    Cache::flush();
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
});

function sdsCompte(string $ws): User
{
    return User::create([
        'id' => (string) Str::uuid(), 'email' => 'sds-' . Str::random(8) . '@example.invalid', 'name' => 'ZZ santé',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
}

/** Un espace avec `$n` fiches archivées « sans e-mail ». */
function sdsEspace(string $prefixe, int $n): string
{
    $ws = F::espace($prefixe);
    for ($i = 0; $i < $n; $i++) {
        F::fiche($ws, "ZZ Archivée {$i}", ['archive_reason' => 'no_email']);
    }

    return $ws;
}

test('le résumé est mis en cache par espace, et un espace ne lit jamais celui d un autre', function () {
    $a = sdsEspace('zz-sds-a', 2);
    $b = sdsEspace('zz-sds-b', 1);

    $this->actingAs(sdsCompte($a));
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 2);

    // Une fiche archivée de plus : le résumé en cache ne bouge pas…
    F::fiche($a, 'ZZ Archivée tard', ['archive_reason' => 'no_email']);
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 2);

    // … B, lui, lit SES chiffres, jamais ceux de A en cache.
    $this->actingAs(sdsCompte($b));
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 1);

    // Cache oublié : A relit la base.
    Cache::forget(ObservabilityController::cle($a));
    $this->actingAs(sdsCompte($a));
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 3);

    expect(ObservabilityController::cle($a))->not->toBe(ObservabilityController::cle($b))
        ->and(ObservabilityController::cle($a))->toContain($a);
});

test('un résumé incomplet (rubrique tombée dans son filet) est rendu, mais jamais mis en cache', function () {
    $ws = sdsEspace('zz-sds-incomplet', 1);
    $this->actingAs(sdsCompte($ws));

    $this->mock(GooglePlacesClient::class)
        ->shouldReceive('currentMonthUsage')->andThrow(new RuntimeException('ZZ panne simulée'));

    $this->getJson(SDS_URL)->assertOk()
        ->assertJsonPath('data.google_places_quota.used', 0)
        ->assertJsonPath('data.archive_reasons.no_email', 1);

    expect(Cache::has(ObservabilityController::cle($ws)))->toBeFalse();

    // TÉMOIN : la panne levée, le résumé complet, lui, part en cache — sans
    // ce témoin, un cache simplement cassé ferait passer le cas ci-dessus.
    $this->mock(GooglePlacesClient::class, function ($m) {
        $m->shouldReceive('currentMonthUsage')->andReturn(3);
        $m->shouldReceive('monthlyQuotaLimit')->andReturn(100);
    });
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.google_places_quota.used', 3);

    expect(Cache::has(ObservabilityController::cle($ws)))->toBeTrue();
});

test('une rubrique sans filet qui échoue reste un 500, et rien ne part en cache (F39-007)', function () {
    $ws = sdsEspace('zz-sds-500', 1);
    $this->actingAs(sdsCompte($ws));

    // `countWaterfallErrors24h` n'a délibérément aucun filet. La transaction
    // du test annule le renommage ensuite.
    DB::statement('ALTER TABLE scraper_runs RENAME TO scraper_runs_zz_absente');

    $this->getJson(SDS_URL)->assertStatus(500);

    expect(Cache::has(ObservabilityController::cle($ws)))->toBeFalse();
});

test('la route est bornée à vingt secondes de SQL, et le calcul à un budget de vingt secondes', function () {
    $route = app('router')->getRoutes()->match(Request::create(SDS_URL, 'GET'));

    expect($route->gatherMiddleware())->toContain('delai-sql:20')
        ->and(ObservabilityController::BUDGET_MS)->toBeLessThanOrEqual(20000);
});

// ── Sous le rôle de production ──────────────────────────────────────────────

function sdsApp(): Connection
{
    return DB::connection('pgsql_app');
}

/** Le plan d'une requête, sous `axion_app`, sans balayage séquentiel permis. */
function sdsPlan(string $espace, Builder $q): string
{
    sdsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
    sdsApp()->statement('SET enable_seqscan = off');

    $lignes = sdsApp()->select('EXPLAIN ' . $q->toSql(), $q->getBindings());

    return implode("\n", array_map(static fn ($l): string => (string) array_values((array) $l)[0], $lignes));
}

afterEach(function () {
    // Connexion jamais ouverte par ce test : surtout ne pas en ouvrir une.
    if (! array_key_exists('pgsql_app', DB::getConnections())) {
        return;
    }

    try {
        sdsApp()->statement('RESET enable_seqscan');
        sdsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    } catch (Throwable) {
        // Connexion déjà perdue : elle part avec le `disconnect()`.
    }
    sdsApp()->disconnect();
});

test('index idx_companies_google_places_en_attente présent, valide, et au prédicat de la requête', function () {
    $index = DB::selectOne(
        'SELECT i.indisvalid AS valide, pg_get_indexdef(i.indexrelid) AS def
           FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
          WHERE c.relname = ?',
        ['idx_companies_google_places_en_attente'],
    );

    expect($index)->not->toBeNull()
        ->and((bool) $index->valide)->toBeTrue()
        ->and($index->def)->toContain('(workspace_id)')
        // Le SENS de chaque condition, pas seulement ses mots : un prédicat
        // inversé indexerait l'inverse de la file, et ne servirait plus.
        ->and($index->def)->toMatch("/'google_places_pending'(::text)?\)* IS NOT NULL/")
        ->and($index->def)->toMatch("/'enriched_at'(::text)?\)* IS NULL/")
        ->and($index->def)->not->toMatch("/'google_places_pending'(::text)?\)* IS NULL/")
        ->and($index->def)->not->toMatch("/'enriched_at'(::text)?\)* IS NOT NULL/");
});

test('sous axion_app : les deux comptages de la santé du système passent par leur index', function () {
    $role = sdsApp()->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

    $espace = (string) Str::uuid();

    $planGoogle = sdsPlan($espace, ObservabilityController::requeteGooglePlacesEnAttente($espace)->selectRaw('count(*) AS aggregate'));
    expect($planGoogle)->toContain('idx_companies_google_places_en_attente')
        ->and($planGoogle)->not->toContain('Seq Scan');

    $planArchives = sdsPlan($espace, ObservabilityController::requeteMotifsArchivage($espace));
    expect($planArchives)->toContain('idx_companies_archive_reason')
        ->and($planArchives)->not->toContain('Seq Scan');
});
