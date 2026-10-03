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
 *  4. le RECALCUL DIFFÉRÉ d'une valeur périmée (après la réponse, hors des
 *     middlewares) compte le bon espace, n'écrit rien dans la clé d'un autre,
 *     et n'écrit jamais un résumé incomplet — sans le signaler en erreur ;
 *  5. la route est bornée (`delai-sql:20`) et `borner()` réduit vraiment le
 *     délai SQL au fil du calcul, puis le rend à sa valeur ;
 *  6. sous le RÔLE DE PRODUCTION (`axion_app`, sécurité par espace forcée), les
 *     deux comptages passent par leur index — sous le propriétaire, tout paraît
 *     rapide (#287, #292, #294) — et l'index partiel Google Places est valide,
 *     au prédicat de la requête.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Exceptions\ResumeObservabiliteIncomplet;
use App\Http\Controllers\Api\ObservabilityController;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use App\Services\Scraping\GooglePlacesClient;
use App\Support\DelaiRequeteSql;
use App\Support\WorkspaceContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SDS_URL = '/api/v1/observability/summary';

/** Au-delà de la fraîcheur du cache : la valeur est périmée, pas expirée. */
function sdsPerimeSecondes(): int
{
    return ObservabilityController::FRAIS_SECONDES + 60;
}

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

// ── Recalcul différé d'une valeur périmée ───────────────────────────────────

/**
 * Exécute les rappels différés encore en attente — APRÈS la réponse et la
 * terminaison des middlewares, comme en production. Si la terminaison du
 * noyau de test les a déjà joués, la file est vide et rien ne se passe.
 */
function sdsJouerLesRappelsDifferes(): void
{
    app(DeferredCallbackCollection::class)->invoke();
}

test('recalcul différé d une valeur périmée : bon espace, hors middlewares, aucune fuite vers un autre espace', function () {
    $a = sdsEspace('zz-sds-diff-a', 2);
    $b = sdsEspace('zz-sds-diff-b', 1);

    $this->actingAs(sdsCompte($a));
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 2);
    sdsJouerLesRappelsDifferes();

    F::fiche($a, 'ZZ Archivée tard', ['archive_reason' => 'no_email']);
    $this->travel(sdsPerimeSecondes())->seconds();

    // L'espace courant au moment où le décompte d'archivage s'exécute.
    $espacesVus = [];
    DB::listen(function (QueryExecuted $q) use (&$espacesVus): void {
        if (str_contains($q->sql, 'archive_reason')) {
            $espacesVus[] = WorkspaceContext::current();
        }
    });

    // Valeur périmée : servie telle quelle, le recalcul part après la réponse.
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 2);
    sdsJouerLesRappelsDifferes();

    // Les middlewares ont rendu le contexte : le recalcul a tourné hors d'eux,
    // et il a reposé LUI-MÊME le bon espace (sans quoi la sécurité par espace
    // compterait zéro en production).
    expect(WorkspaceContext::current())->toBeNull()
        ->and($espacesVus)->not->toBeEmpty()
        ->and(array_unique($espacesVus))->toBe([$a]);

    // La clé de A porte le recalcul ; celle de B n'a jamais été écrite.
    $enCache = Cache::get(ObservabilityController::cle($a));
    expect($enCache['archive_reasons']['no_email'] ?? null)->toBe(3)
        ->and(Cache::has(ObservabilityController::cle($b)))->toBeFalse();

    // B, ensuite, lit SES chiffres.
    $this->actingAs(sdsCompte($b));
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 1);
});

test('recalcul différé incomplet : rien n est écrit, un warning est journalisé, aucune erreur signalée', function () {
    $ws = sdsEspace('zz-sds-diff-incomplet', 2);
    $this->actingAs(sdsCompte($ws));
    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 2);
    sdsJouerLesRappelsDifferes();
    $avant = Cache::get(ObservabilityController::cle($ws));

    F::fiche($ws, 'ZZ Archivée tard', ['archive_reason' => 'no_email']);
    $this->travel(sdsPerimeSecondes())->seconds();
    $this->mock(GooglePlacesClient::class)
        ->shouldReceive('currentMonthUsage')->andThrow(new RuntimeException('ZZ panne simulée'));
    Exceptions::fake();
    Log::spy();

    $this->getJson(SDS_URL)->assertOk()->assertJsonPath('data.archive_reasons.no_email', 2);
    sdsJouerLesRappelsDifferes();

    // La valeur en cache est l'ancienne, complète : le résumé incomplet (qui
    // aurait compté 3) n'a pas été écrit.
    expect(Cache::get(ObservabilityController::cle($ws)))->toBe($avant);
    // Pas d'erreur au gestionnaire d'exceptions (ni journal d'erreur, ni
    // Sentry), mais un warning, sans SQL.
    Exceptions::assertNotReported(ResumeObservabiliteIncomplet::class);
    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $contexte = []): bool => str_contains($message, 'recalcul différé incomplet')
            && $contexte === ['workspace_id' => $ws])
        ->once();
});

// ── Délai SQL ───────────────────────────────────────────────────────────────

test('la route est bornée à vingt secondes de SQL', function () {
    $route = app('router')->getRoutes()->match(Request::create(SDS_URL, 'GET'));

    expect($route->gatherMiddleware())->toContain('delai-sql:20');
});

test('borner() donne à la requête suivante le temps qui RESTE sur le budget, plancher compris', function () {
    $controleur = app(ObservabilityController::class);
    $borner = function (int $ecouleMs): void {
        $this->debutCalcul = hrtime(true) - $ecouleMs * 1_000_000;
        $this->borner();
    };

    $borner->call($controleur, 15000);
    expect(DelaiRequeteSql::courantMs())->toBeGreaterThanOrEqual(4000)->toBeLessThanOrEqual(5000);

    $borner->call($controleur, 2000);
    expect(DelaiRequeteSql::courantMs())->toBeGreaterThanOrEqual(17000)->toBeLessThanOrEqual(18000);

    // Budget épuisé : le plancher, jamais 0 (qui voudrait dire « sans limite »).
    $borner->call($controleur, 60000);
    expect(DelaiRequeteSql::courantMs())->toBe(500);

    DB::statement('RESET statement_timeout');
});

test('pendant le calcul, chaque requête reçoit un délai décroissant ; après, le délai revient à sa valeur', function () {
    $ws = sdsEspace('zz-sds-delai', 1);
    DelaiRequeteSql::poser(12345);

    $poses = [];
    DB::listen(function (QueryExecuted $q) use (&$poses): void {
        if (preg_match('/^SET statement_timeout = (\d+)$/', $q->sql, $m) === 1) {
            $poses[] = (int) $m[1];
        }
    });

    $calculer = function (string $espace): array {
        return $this->calculer($espace);
    };
    $resume = $calculer->call(app(ObservabilityController::class), $ws);

    expect($resume['archive_reasons'])->toBe(['no_email' => 1]);

    // Le dernier SET rend la valeur d'avant ; les autres viennent de borner().
    $restauration = array_pop($poses);
    expect($restauration)->toBe(12345)
        // Une pose par requête SQL du résumé (dix).
        ->and(count($poses))->toBeGreaterThanOrEqual(10);
    foreach ($poses as $i => $ms) {
        expect($ms)->toBeLessThanOrEqual(ObservabilityController::BUDGET_MS)->toBeGreaterThanOrEqual(500);
        if ($i > 0) {
            expect($ms)->toBeLessThanOrEqual($poses[$i - 1]);
        }
    }
    expect(DelaiRequeteSql::courantMs())->toBe(12345);

    DB::statement('RESET statement_timeout');
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
