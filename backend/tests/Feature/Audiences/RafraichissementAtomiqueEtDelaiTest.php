<?php

/**
 * RELECTURE A09 DE #282 — le filet `statement_timeout` ne doit JAMAIS laisser
 * une audience à moitié remplie, ni maquiller un échec en « 0 destinataire ».
 * Fixtures FICTIVES (dépôt public).
 *
 *   1. ATOMICITÉ — une coupure pendant le remplissage (délai SQL, erreur sur
 *      un lot) rend l'ANCIENNE composition intacte. Avant : la suppression
 *      était validée seule, puis le remplissage partait hors transaction.
 *   2. APERÇU — un échec ne rend plus 200 « 0 entreprise, 0 contact ».
 *   3. DÉPASSEMENT — dans l'aperçu comme dans le rafraîchissement, il sort en
 *      503 « trop long », jamais en 500 ni en 422 avec du SQL brut.
 *   4. ROUTES LOURDES — chacune porte son délai élargi, choisi et documenté.
 *   5. JOBS `sync` — un job lancé par une requête web n'hérite pas des 15 s.
 */

use App\Models\EmailAudience;
use App\Models\User;
use App\Services\Audiences\AudienceBuilderService;
use App\Support\DelaiRequeteSql;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->ws = F::espace('zz-audience-atomique');
});

function raeAudience(string $ws): EmailAudience
{
    return EmailAudience::create([
        'workspace_id' => $ws,
        'name' => 'ZZ atomique ' . Str::random(4),
        'criteria' => ['all' => [['field' => 'sector_main', 'op' => 'eq', 'value' => 'industrie']]],
        'is_active' => true,
        'auto_refresh' => true,
    ]);
}

function raeOperateur(object $t): void
{
    $t->seed(PermissionsAndRolesSeeder::class);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'op-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ op',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $t->ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($t->ws);
    $user->assignRole('operator');
    $t->actingAs($user);
}

function raeDepassement(): QueryException
{
    return new QueryException(
        'pgsql',
        'SELECT 1',
        [],
        new PDOException('SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout'),
    );
}

// ── 1. ATOMICITÉ ────────────────────────────────────────────────────────────

test('une coupure pendant le remplissage laisse l ANCIENNE composition intacte', function () {
    $ancienne = F::fiche($this->ws, 'ZZ ancienne', ['sector_main' => 'industrie']);
    F::fiche($this->ws, 'ZZ nouvelle', ['sector_main' => 'industrie']);
    $audience = raeAudience($this->ws);

    // L'ancienne composition, telle qu'un rafraîchissement précédent l'a posée.
    DB::table('audience_members')->insert([
        'audience_id' => $audience->id, 'company_id' => $ancienne, 'workspace_id' => $this->ws,
    ]);
    $audience->update(['member_count' => 1]);

    // La coupure : la lecture des personnes d'un lot échoue (c'est là que
    // tombait le délai SQL — APRÈS la suppression des anciens membres).
    DB::listen(function ($requete): void {
        if (str_contains($requete->sql, 'from "contacts"')) {
            throw new RuntimeException('coupure simulée pendant le remplissage');
        }
    });

    expect(fn () => app(AudienceBuilderService::class)->refresh($audience))
        ->toThrow(RuntimeException::class);

    expect(DB::table('audience_members')->where('audience_id', $audience->id)->pluck('company_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$ancienne], 'Audience à moitié remplie : la suppression a été validée sans le remplissage.');
    expect((int) $audience->fresh()?->member_count)->toBe(1);
});

test('TEMOIN — sans coupure, le rafraichissement remplace bien la composition', function () {
    $ancienne = F::fiche($this->ws, 'ZZ ancienne', ['sector_main' => 'commerce_detail']);
    $audience = raeAudience($this->ws);
    DB::table('audience_members')->insert([
        'audience_id' => $audience->id, 'company_id' => $ancienne, 'workspace_id' => $this->ws,
    ]);

    app(AudienceBuilderService::class)->refresh($audience);

    // `commerce_detail` ne correspond plus au critère : l'ancien membre part.
    expect(DB::table('audience_members')->where('audience_id', $audience->id)->count())->toBe(0);
    expect($audience->fresh()?->refreshed_at)->not->toBeNull();
});

// ── 2 et 3. APERÇU ET RAFRAÎCHISSEMENT PAR L'API ────────────────────────────

test('un apercu en echec ne rend plus 200 « 0 entreprise »', function () {
    raeOperateur($this);
    $this->mock(AudienceBuilderService::class, function ($m): void {
        $m->shouldReceive('preview')->andThrow(new LogicException('panne simulée'));
    });

    $r = $this->postJson('/api/v1/audiences/preview', ['criteria' => ['all' => [['field' => 'sector_main', 'op' => 'eq', 'value' => 'industrie']]]]);

    $r->assertStatus(500)->assertJsonPath('error', 'preview_failed');
    expect($r->json())->not->toHaveKey('companies');
    expect($r->json())->not->toHaveKey('contacts');
});

test('un depassement dans l apercu rend le 503 « trop long »', function () {
    raeOperateur($this);
    $this->mock(AudienceBuilderService::class, function ($m): void {
        $m->shouldReceive('preview')->andThrow(raeDepassement());
    });

    $this->postJson('/api/v1/audiences/preview', ['criteria' => ['all' => [['field' => 'sector_main', 'op' => 'eq', 'value' => 'industrie']]]])
        ->assertStatus(503)
        ->assertJsonPath('error', 'requete_trop_longue')
        ->assertJsonPath('message', DelaiRequeteSql::MESSAGE);
});

test('un depassement dans le rafraichissement rend le 503 propre, pas un 500', function () {
    raeOperateur($this);
    $audience = raeAudience($this->ws);
    $this->mock(AudienceBuilderService::class, function ($m): void {
        $m->shouldReceive('refresh')->andThrow(raeDepassement());
    });

    $this->postJson("/api/v1/audiences/{$audience->id}/refresh")
        ->assertStatus(503)
        ->assertJsonPath('error', 'requete_trop_longue');
});

// ── 4. ROUTES LOURDES ───────────────────────────────────────────────────────

test('chaque route lourde porte son delai SQL elargi', function (string $methode, string $chemin, string $attendu) {
    $route = app('router')->getRoutes()->match(Request::create($chemin, $methode));

    expect($route->gatherMiddleware())->toContain($attendu);
})->with([
    ['POST', '/api/v1/audiences', 'delai-sql:300'],
    ['POST', '/api/v1/audiences/1/refresh', 'delai-sql:300'],
    ['POST', '/api/v1/audiences/preview', 'delai-sql:120'],
    ['POST', '/api/v1/audiences/apercu-destinataires', 'delai-sql:120'],
    ['GET', '/api/v1/audiences/1/destinataires', 'delai-sql:120'],
    ['GET', '/api/v1/rgpd/export/jeton-fictif', 'delai-sql:300'],
    ['POST', '/api/v1/listes-manuelles/1/import', 'delai-sql:300'],
    ['POST', '/api/v1/companies/tags/bulk', 'delai-sql:300'],
    ['POST', '/api/v1/crm/bulk', 'delai-sql:300'],
    ['GET', '/api/v1/crm/personnes/counts', 'delai-sql:120'],
    ['GET', '/api/v1/search', 'delai-sql:30'],
    ['POST', '/api/internal/site-sync', 'delai-sql:300'],
    ['POST', '/api/internal/site-sync/gdpr', 'delai-sql:300'],
]);

// ── 5. JOBS `sync` ──────────────────────────────────────────────────────────

test('un job sync lance pendant une requete web n herite pas des 15 s, puis la requete les retrouve', function () {
    DelaiRequeteSql::poser(15000);

    // Une fermeture en file est SÉRIALISÉE, même en `sync` : une capture par
    // référence n'y survit pas. Le job écrit donc dans une variable globale.
    $GLOBALS['rae_delai_vu_par_le_job'] = 'jamais exécuté';
    dispatch(function (): void {
        $GLOBALS['rae_delai_vu_par_le_job'] = DelaiRequeteSql::courantMs();
    })->onConnection('sync');

    expect($GLOBALS['rae_delai_vu_par_le_job'])->toBe(0);
    expect(DelaiRequeteSql::courantMs())->toBe(15000);

    DB::statement('RESET statement_timeout');
});
