<?php

/**
 * GARDE : LE TABLEAU DE BORD NE FAIT PLUS PASSER UNE ABSENCE DE CHIFFRE POUR
 * UN ZÉRO — audit UX de la console du 2026-10-02, P0-1 (lot 1).
 *
 * Deux mensonges mesurés sur `GET /dashboard/stats` :
 *
 *  1. Sans espace de travail courant, le serveur rendait le gabarit à zéros.
 *     L'écran en concluait « Votre base est vide » — sur 4,3 M de fiches.
 *     Désormais : HTTP 409 `no_workspace`.
 *  2. Un compteur dont la requête échouait (délai dépassé, colonne absente)
 *     rendait 0. Désormais : `null`, journalisé sans SQL, et le résultat
 *     partiel n'entre pas dans le cache.
 *
 * La panne est simulée par `beforeExecuting` : l'exception part AVANT que la
 * requête n'atteigne Postgres. Une vraie erreur SQL, dans la transaction de
 * `RefreshDatabase`, avorterait la transaction et ferait échouer toutes les
 * requêtes suivantes — on mesurerait alors autre chose que ce compteur-là.
 */

use App\Http\Controllers\Api\DashboardController;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array{0: User, 1: string} */
function honneteCompte(string $suffixe): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-honnete-' . strtolower($suffixe) . '-' . Str::random(4),
        'name' => 'Honnete ' . $suffixe,
        'settings' => [],
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'honnete-' . strtolower($suffixe) . '-' . Str::random(4) . '@example.invalid',
        'name' => 'Operateur ' . $suffixe,
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $compte->id,
        'workspace_id' => $espace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    return [$compte, (string) $espace->id];
}

function honneteFiche(string $espace, string $siren): void
{
    DB::table('companies')->insert([
        'workspace_id' => $espace,
        'denomination' => 'Fiche ' . $siren,
        'siren' => $siren,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Fait échouer le comptage de `contacts` tant que `$actif` vaut vrai.
 * Passé par référence : le test peut « réparer » la base en cours de route.
 */
function honnetePanneContacts(bool &$actif): void
{
    DB::connection()->beforeExecuting(function (string $sql) use (&$actif): void {
        if ($actif && stripos($sql, 'from "contacts"') !== false && stripos($sql, 'count(') !== false) {
            throw new RuntimeException('panne simulee du comptage des contacts');
        }
    });
}

beforeEach(function () {
    Cache::flush();
});

test('sans aucun espace rattache : 409 no_workspace, et aucun chiffre', function () {
    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'sans-espace-' . Str::random(4) . '@example.invalid',
        'name' => 'Sans espace',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => null,
        'first_login_completed_at' => now(),
    ]);

    $reponse = $this->actingAs($compte)->getJson('/api/v1/dashboard/stats');

    $reponse->assertStatus(409)
        ->assertExactJson([
            'error' => 'no_workspace',
            'message' => "Aucun espace de travail n'est rattaché à votre compte.",
        ]);

    // C'EST L'ASSERTION QUI COMPTE : plus aucun zéro que l'écran pourrait lire
    // comme « base vide ».
    expect($reponse->json('companies_total'))->toBeNull();
});

test('TEMOIN : avec un espace, la meme route repond 200 avec ses chiffres', function () {
    [$compte, $espace] = honneteCompte('T');
    honneteFiche($espace, '830000001');

    $reponse = $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($reponse->json('companies_total'))->toBe(1);
    expect($reponse->json('contacts_qualified'))->toBe(0);
});

test('un compteur en echec rend null, pas 0 — les autres restent justes', function () {
    [$compte, $espace] = honneteCompte('N');
    honneteFiche($espace, '830000011');
    $panne = true;
    honnetePanneContacts($panne);

    $reponse = $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($reponse->json('contacts_qualified'))->toBeNull(
        "Un comptage en panne rendait 0 : l'ecran affichait « 0 contact » au lieu de « — ».",
    );
    expect($reponse->json('companies_total'))->toBe(1);
});

test('la panne est journalisee, sans le texte SQL', function () {
    [$compte] = honneteCompte('L');
    $panne = true;
    honnetePanneContacts($panne);
    /** @var list<array{message: string, contexte: array<string, mixed>}> $journal */
    $journal = [];
    Log::listen(function (MessageLogged $e) use (&$journal): void {
        if ($e->level === 'warning') {
            $journal[] = ['message' => $e->message, 'contexte' => $e->context];
        }
    });

    $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')->assertOk();

    $pannes = array_values(array_filter(
        $journal,
        fn (array $l): bool => $l['message'] === 'dashboard: compteur indisponible',
    ));

    expect($pannes)->toHaveCount(1);
    expect($pannes[0]['contexte']['table'] ?? null)->toBe('contacts');
    $brut = strtolower((string) json_encode($pannes[0]['contexte']));
    expect($brut)->not->toContain('select')
        ->and($brut)->not->toContain('panne simulee');
});

test('un resultat partiel n est PAS mis en cache : la requete suivante retente', function () {
    [$compte, $espace] = honneteCompte('C');
    honneteFiche($espace, '830000021');
    $panne = true;
    honnetePanneContacts($panne);
    $this->actingAs($compte);

    expect($this->getJson('/api/v1/dashboard/stats')->json('contacts_qualified'))->toBeNull();
    expect(Cache::has(DashboardController::cle($espace)))->toBeFalse(
        'Un resultat partiel est entre dans le cache : ses null resteraient 30 min a l ecran.',
    );

    // La base se « répare » : la requête suivante recompte, et ce résultat
    // complet-là, lui, est gardé.
    $panne = false;
    expect($this->getJson('/api/v1/dashboard/stats')->json('contacts_qualified'))->toBe(0);
    expect(Cache::has(DashboardController::cle($espace)))->toBeTrue(
        'TEMOIN : un resultat complet doit, lui, etre mis en cache.',
    );
});

test('le cloisonnement est inchange, y compris quand un compteur tombe', function () {
    [$a, $espaceA] = honneteCompte('A');
    [$b, $espaceB] = honneteCompte('B');
    honneteFiche($espaceA, '830000031');
    honneteFiche($espaceA, '830000032');
    honneteFiche($espaceB, '830000041');
    $panne = true;
    honnetePanneContacts($panne);

    expect($this->actingAs($a)->getJson('/api/v1/dashboard/stats')->json('companies_total'))->toBe(2);
    expect($this->actingAs($b)->getJson('/api/v1/dashboard/stats')->json('companies_total'))->toBe(1);
    expect(DashboardController::cle($espaceA))->not->toBe(DashboardController::cle($espaceB));
});

/**
 * Fait échouer, AVANT Postgres, toute requête dont le SQL contient `$aiguille`
 * (comparaison sans casse).
 */
function honnetePanneSur(string $aiguille): void
{
    DB::connection()->beforeExecuting(function (string $sql) use ($aiguille): void {
        if (stripos($sql, $aiguille) !== false) {
            throw new RuntimeException('panne simulee');
        }
    });
}

/**
 * Les warnings journalisés pendant `$travail`.
 *
 * @return list<array{message: string, contexte: array<string, mixed>}>
 */
function honneteJournal(callable $travail): array
{
    $journal = [];
    Log::listen(function (MessageLogged $e) use (&$journal): void {
        if ($e->level === 'warning') {
            $journal[] = ['message' => $e->message, 'contexte' => $e->context];
        }
    });

    $travail();

    return $journal;
}

test('membre d un espace mais aucun selectionne : 409 workspace_not_selected', function () {
    [, $espace] = honneteCompte('M');
    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'membre-sans-courant-' . Str::random(4) . '@example.invalid',
        'name' => 'Membre sans espace courant',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => null,
        'first_login_completed_at' => now(),
    ]);
    DB::table('user_workspaces')->insert([
        'user_id' => $compte->id,
        'workspace_id' => $espace,
        'role_slug' => 'operator',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')
        ->assertStatus(409)
        ->assertExactJson([
            'error' => 'workspace_not_selected',
            'message' => "Aucun espace de travail n'est sélectionné sur votre compte. Contactez l'administrateur pour qu'il en sélectionne un.",
        ]);
});

test('une appartenance REVOQUEE ne compte pas : 409 no_workspace', function () {
    [, $espace] = honneteCompte('R');
    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'revoque-' . Str::random(4) . '@example.invalid',
        'name' => 'Revoque',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => null,
        'first_login_completed_at' => now(),
    ]);
    DB::table('user_workspaces')->insert([
        'user_id' => $compte->id,
        'workspace_id' => $espace,
        'role_slug' => 'operator',
        'invited_at' => now(),
        'joined_at' => now(),
        'revoked_at' => now(),
    ]);

    $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')
        ->assertStatus(409)
        ->assertJsonPath('error', 'no_workspace');
});

test('qualite en panne : quality_distribution vaut null, pas 0 / 0 / 0', function () {
    [$compte, $espace] = honneteCompte('Q');
    honneteFiche($espace, '830000051');
    // Le seul SQL du contrôleur qui porte `FILTER (WHERE quality_score` : la
    // requête de qualité, et elle seule.
    honnetePanneSur('FILTER (WHERE quality_score');

    $r = $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($r->json('quality_distribution'))->toBeNull();
    expect($r->json('quality_avg'))->toBeNull();
    // Témoin : le reste est compté.
    expect($r->json('companies_total'))->toBe(1);
    expect(Cache::has(DashboardController::cle($espace)))->toBeFalse();
});

test('repartition par taille en panne : size_distribution vaut null, pas des zeros', function () {
    [$compte, $espace] = honneteCompte('S');
    honneteFiche($espace, '830000061');
    honnetePanneSur('group by "size_category"');

    $r = $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($r->json('size_distribution'))->toBeNull();
    expect($r->json('companies_total'))->toBe(1);
    expect(Cache::has(DashboardController::cle($espace)))->toBeFalse();
});

test('une VRAIE QueryException : classe et SQLSTATE journalises, ni le SQL ni la valeur liee', function () {
    [$compte] = honneteCompte('Q2');
    $temoin = 'VALEUR-TEMOIN-7f3a91';
    $levee = null;

    DB::connection()->beforeExecuting(function (string $sql) use ($temoin, &$levee): void {
        if (stripos($sql, 'from "contacts"') !== false && stripos($sql, 'count(') !== false) {
            // Un délai dépassé tel que Postgres le signale (SQLSTATE 57014).
            $pdo = new class('SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout') extends PDOException
            {
                protected $code = '57014';
            };
            $levee = new QueryException('pgsql', $sql, [$temoin], $pdo);

            throw $levee;
        }
    });

    $journal = honneteJournal(fn () => $this->actingAs($compte)->getJson('/api/v1/dashboard/stats')->assertOk());

    // TÉMOIN : l'exception levée porte bien le SQL ET la valeur — sans quoi
    // l'absence de fuite ci-dessous ne prouverait rien.
    expect($levee)->toBeInstanceOf(QueryException::class);
    expect($levee->getMessage())->toContain($temoin)->toContain('from "contacts"');

    $pannes = array_values(array_filter(
        $journal,
        fn (array $l): bool => $l['message'] === 'dashboard: compteur indisponible',
    ));
    expect($pannes)->toHaveCount(1);
    expect($pannes[0]['contexte']['exception'] ?? null)->toBe(QueryException::class);
    expect($pannes[0]['contexte']['sqlstate'] ?? null)->toBe('57014');

    $brut = strtolower((string) json_encode($pannes[0]['contexte']));
    expect($brut)->not->toContain(strtolower($temoin))
        ->and($brut)->not->toContain('select')
        ->and($brut)->not->toContain('workspace_id');
});
