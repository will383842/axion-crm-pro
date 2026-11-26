<?php

/**
 * LE COMPTEUR GLOBAL DE DÉPENSE CLAUDE (`BudgetClaude`, table `depenses_claude_jour`).
 *
 * Montants en micro-dollars. Horloge figée en UTC, aucun réseau.
 */

use App\Crm\Claude\BudgetClaude;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const BC_M = 1_000_000;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-14 10:00:00', 'UTC'));
    Config::set('crm.claude', [
        'plafond_total_usd' => null,
        'plafond_jour_usd' => null,
        'plafond_execution_usd' => null,
        'plafonds_postes_usd' => ['recherche-web' => null, 'arbitrage' => null, 'extraction' => null],
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function bcLigne(string $jour, string $poste, int $reserve): void
{
    DB::table('depenses_claude_jour')->insert([
        'jour' => $jour, 'poste' => $poste, 'reserve_micro' => $reserve, 'reel_micro' => 0,
        'appels' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('refuse une réservation au-delà du total, tous jours et tous postes confondus', function () {
    bcLigne('2026-12-01', 'recherche-web', 100 * BC_M);
    bcLigne('2026-12-02', 'arbitrage', 20 * BC_M);
    bcLigne('2026-12-03', 'extraction', 20 * BC_M);
    bcLigne('2026-12-04', 'recherche-web', 59 * BC_M);

    $budget = new BudgetClaude;

    expect($budget->reserver('recherche-web', 2 * BC_M))->toBeNull()
        ->and($budget->reserver('recherche-web', BC_M))->toBe('2026-12-14');
});

it('refuse une réservation au-delà du plafond du jour', function () {
    bcLigne('2026-12-14', 'arbitrage', 10 * BC_M);
    bcLigne('2026-12-13', 'arbitrage', 5 * BC_M);

    $budget = new BudgetClaude;

    expect($budget->reserver('recherche-web', 5 * BC_M + 1))->toBeNull()
        ->and($budget->reserver('recherche-web', 5 * BC_M))->not->toBeNull();
});

it('refuse une réservation au-delà du plafond du poste', function () {
    bcLigne('2026-12-01', 'arbitrage', 19 * BC_M);

    $budget = new BudgetClaude;

    expect($budget->reserver('arbitrage', BC_M + 1))->toBeNull()
        ->and($budget->reserver('arbitrage', BC_M))->not->toBeNull();
});

it('refuse une réservation au-delà du plafond de l\'exécution', function () {
    $budget = new BudgetClaude;

    expect($budget->reserver('recherche-web', 3 * BC_M))->not->toBeNull()
        ->and($budget->reserver('recherche-web', 2 * BC_M + 1))->toBeNull()
        ->and($budget->reserver('recherche-web', 2 * BC_M))->not->toBeNull()
        // Une autre exécution (autre instance) repart de zéro, mais le jour compte.
        ->and((new BudgetClaude)->reserver('recherche-web', BC_M))->not->toBeNull();
});

it('refuse un poste inconnu', function () {
    (new BudgetClaude)->reserver('inconnu', 1);
})->throws(InvalidArgumentException::class);

it('ne laisse la configuration qu\'abaisser le plafond total', function () {
    Log::spy();

    Config::set('crm.claude.plafond_total_usd', '500');
    expect((new BudgetClaude)->plafondTotal())->toBe(200 * BC_M);
    Log::shouldHaveReceived('warning')->once();

    Config::set('crm.claude.plafond_total_usd', '50');
    expect((new BudgetClaude)->plafondTotal())->toBe(50 * BC_M);
});

it('applique un plafond abaissé du jour', function () {
    Config::set('crm.claude.plafond_jour_usd', '1');
    $budget = new BudgetClaude;

    expect($budget->reserver('arbitrage', BC_M + 1))->toBeNull()
        ->and($budget->reserver('arbitrage', BC_M))->not->toBeNull();
});

it('ajuster remplace la réservation par le réel', function () {
    $budget = new BudgetClaude;
    $jour = $budget->reserver('recherche-web', 2 * BC_M);

    $budget->ajuster('recherche-web', $jour, 2 * BC_M, 500_000);

    $ligne = DB::table('depenses_claude_jour')->first();
    expect((int) $ligne->reserve_micro)->toBe(500_000)
        ->and((int) $ligne->reel_micro)->toBe(500_000)
        ->and((int) $ligne->appels)->toBe(1)
        ->and($budget->restant('recherche-web'))->toBe(5 * BC_M - 500_000);
});

it('une réponse illisible garde la réservation maximale', function () {
    $budget = new BudgetClaude;
    $jour = $budget->reserver('recherche-web', 2 * BC_M);

    $budget->ajuster('recherche-web', $jour, 2 * BC_M, null);

    $ligne = DB::table('depenses_claude_jour')->first();
    expect((int) $ligne->reserve_micro)->toBe(2 * BC_M)
        ->and((int) $ligne->reel_micro)->toBe(0);
});

it('deux réservations successives ne dépassent jamais le plafond', function () {
    Config::set('crm.claude.plafond_total_usd', '4');

    $a = new BudgetClaude;
    $b = new BudgetClaude;

    expect($a->reserver('extraction', 3 * BC_M))->not->toBeNull()
        ->and($b->reserver('extraction', 3 * BC_M))->toBeNull()
        ->and($b->reserver('extraction', BC_M))->not->toBeNull()
        ->and((int) DB::table('depenses_claude_jour')->sum('reserve_micro'))->toBe(4 * BC_M);
});

it('restant rend la borne la plus basse', function () {
    $budget = new BudgetClaude;

    expect($budget->restant())->toBe(5 * BC_M)
        ->and($budget->restant('arbitrage'))->toBe(5 * BC_M);
    Config::set('crm.claude.plafonds_postes_usd.arbitrage', '2');
    expect($budget->restant('arbitrage'))->toBe(2 * BC_M);
});

function bcMigration(): Migration
{
    return require database_path('migrations/2026_12_13_000100_depenses_claude_jour.php');
}

it('down() refuse si la table contient des lignes', function () {
    bcLigne('2026-12-01', 'arbitrage', 1);

    expect(fn () => bcMigration()->down())->toThrow(RuntimeException::class, 'Retour arrière refusé');
    expect(DB::table('depenses_claude_jour')->count())->toBe(1);
});

it('down() refuse si le journal llm_usage est rempli', function () {
    $espace = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $espace, 'slug' => 'bc-' . substr($espace, 0, 8), 'name' => 'Espace budget']);
    DB::table('llm_usage')->insert([
        'workspace_id' => $espace, 'use_case_slug' => 'x', 'provider' => 'anthropic',
        'model' => 'claude-haiku-5-5', 'cout_usd_micro' => 10, 'cle_alias' => 'compte_a',
    ]);

    expect(fn () => bcMigration()->down())->toThrow(RuntimeException::class, 'llm_usage');
});

it('NR-17 : aller-retour-aller de la migration', function () {
    $migration = bcMigration();

    $migration->down();
    expect(Schema::hasTable('depenses_claude_jour'))->toBeFalse()
        ->and(Schema::hasColumn('llm_usage', 'cle_alias'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('depenses_claude_jour'))->toBeTrue()
        ->and(Schema::hasColumns('llm_usage', ['cout_usd_micro', 'recherches_web', 'cle_alias', 'poste']))->toBeTrue();
});
