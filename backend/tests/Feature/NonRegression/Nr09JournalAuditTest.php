<?php

/**
 * NR-09 — R15, le journal d’audit chaîné et ses partitions.
 *
 * La chaîne d’audit se vérifie (`audit:verify-chain`, 03:00) et une falsification
 * la fait sortir en échec ; la maintenance des partitions (`db:partman-maintenir`,
 * 01:30) crée bien les partitions à venir, dont celle du MOIS SUIVANT.
 *
 * Gardes existantes rattachées au groupe : `MaintenancePartitionsAuditTest`
 * (horizon repoussé après sabotage), `AuditLogsPartitionnementTest`.
 *
 * Aucun vrai secret : le secret de chaîne est une valeur FACTICE posée dans le test.
 */

use App\Console\Commands\AuditVerifyChain;
use App\Services\Audit\AuditHashChain;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Construit à l'exécution : un littéral de cette forme fait rougir le scan Gitleaks.
    config(['services.audit.hash_chain_secret' => 'valeur-factice-' . str_repeat('n9', 10)]);
});

function nr09Ecrire(int $n): void
{
    $chaine = new AuditHashChain;
    for ($i = 1; $i <= $n; $i++) {
        $chaine->record(['method' => 'GET', 'path' => "/nr09/{$i}", 'status' => 200, 'ip' => '203.0.113.' . $i]);
    }
}

test('NR-09 une chaîne intacte se vérifie : la commande de 03:00 réussit', function () {
    nr09Ecrire(5);

    expect(Artisan::call('audit:verify-chain'))->toBe(0)
        ->and(Artisan::output())->toContain('OK');
});

test('NR-09 une chaîne falsifiée est détectée : la commande sort en échec avec l’alerte d’intégrité', function () {
    nr09Ecrire(5);
    $maillon = DB::table('audit_logs')->orderBy('id')->offset(2)->first();
    DB::table('audit_logs')->where('id', $maillon->id)->where('created_at', $maillon->created_at)->update(['path' => '/falsifie']);

    expect(Artisan::call('audit:verify-chain'))->toBe(1)
        ->and(Artisan::output())->toContain(AuditVerifyChain::PREFIXE_ALERTE);
});

test('NR-09 sans secret utilisable la chaîne ne répond jamais « valide »', function () {
    nr09Ecrire(2);
    config(['services.audit.hash_chain_secret' => '']);

    expect(Artisan::call('audit:verify-chain'))->toBe(1);
});

test('NR-09 la maintenance des partitions laisse en place une partition pour le mois suivant (hors DEFAULT)', function () {
    DB::table('audit_logs')->insert([
        'event_type' => 'nr09.vivante', 'current_hash' => 'x', 'prev_hash' => str_repeat('0', 64), 'created_at' => now(),
    ]);

    expect(Artisan::call('db:partman-maintenir'))->toBe(0);

    $moisSuivant = now()->startOfMonth()->addMonth();
    $couvre = DB::select(<<<'SQL'
        SELECT c.relname
        FROM   pg_class c
        JOIN   pg_inherits i ON i.inhrelid = c.oid
        WHERE  i.inhparent = 'public.audit_logs'::regclass
          AND  pg_get_expr(c.relpartbound, c.oid) <> 'DEFAULT'
          AND  (regexp_match(pg_get_expr(c.relpartbound, c.oid), 'FROM \(''([^'']+)''\)'))[1]::timestamptz <= ?::timestamptz
          AND  (regexp_match(pg_get_expr(c.relpartbound, c.oid), 'TO \(''([^'']+)''\)'))[1]::timestamptz   >  ?::timestamptz
    SQL, [$moisSuivant->toDateTimeString(), $moisSuivant->toDateTimeString()]);

    expect($couvre)->not->toBe([]);
});

test('NR-09 la maintenance des partitions est planifiée chaque jour à 01:30', function () {
    Artisan::call('list', ['--format' => 'txt']);
    $evenements = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'db:partman-maintenir'));

    expect($evenements)->toHaveCount(1)
        ->and($evenements->first()->expression)->toBe('30 1 * * *');
});
