<?php

use App\Console\Commands\EnvoiTentativesArchiver;
use App\Envoi\Chauffe\LecteurReportsDb;
use App\Envoi\Depot\RetentionTentatives;
use App\Support\WorkspaceContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function retEspace(bool $corbeille = false): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'name' => 'Test', 'slug' => 'ret-' . substr($id, 0, 8),
        'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $corbeille ? now() : null,
    ]);

    return $id;
}

function retTentative(string $ws, int $joursAvant, string $issue = 'acceptee', ?int $code = null, string $fournisseur = 'gmail'): void
{
    WorkspaceContext::run($ws, fn () => DB::statement(
        "INSERT INTO envoi_tentatives (workspace_id, fournisseur, issue, code_smtp, cle_idempotence, cree_le)
         VALUES (?, ?, ?, ?, ?, now() - (? || ' days')::interval)",
        [$ws, $fournisseur, $issue, $code, (string) Str::uuid(), $joursAvant],
    ));
}

function retDetail(string $ws): int
{
    return WorkspaceContext::run($ws, fn () => (int) DB::table('envoi_tentatives')->where('workspace_id', $ws)->count());
}

/** @return array<int, object> */
function retJours(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_tentatives_jours')->where('workspace_id', $ws)->orderBy('jour')->orderBy('issue')->get()->all());
}

function retPersonnes(): array
{
    $n = [];
    foreach (['envoi_oppositions', 'email_suppressions', 'contacts', 'personnes'] as $t) {
        if (Schema::hasTable($t)) {
            $n[$t] = DB::table($t)->count();
        }
    }
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'envoi\\_oppositions%'") as $l) {
        $n[$l->tablename] = DB::table($l->tablename)->count();
    }

    return $n;
}

test('une ligne de 401 jours est agrégée puis retirée, une de 399 jours reste', function () {
    $ws = retEspace();
    retTentative($ws, 401);
    retTentative($ws, 399);

    $bilan = app(RetentionTentatives::class)->archiver();

    expect(retDetail($ws))->toBe(1)
        ->and(array_sum(array_map(fn ($l) => $l->nombre, retJours($ws))))->toBe(1)
        ->and($bilan['lignes'])->toBe(1);
});

test('totaux agrégés = totaux détaillés d\'avant, rejeu sans double comptage', function () {
    $ws = retEspace();
    foreach ([[450, 'acceptee', null], [450, 'acceptee', null], [450, 'reportee', 451], [420, 'refusee', 550], [420, 'refusee', 550]] as [$j, $i, $c]) {
        retTentative($ws, $j, $i, $c);
    }
    retTentative($ws, 10);
    $avant = 5;

    app(RetentionTentatives::class)->archiver();
    $total = fn () => array_sum(array_map(fn ($l) => $l->nombre, retJours($ws)));
    expect($total())->toBe($avant)->and(retDetail($ws))->toBe(1);

    $rejeu = app(RetentionTentatives::class)->archiver();
    expect($total())->toBe($avant)->and($rejeu['lignes'])->toBe(0);
});

test('la commande boucle sur les espaces sous leur contexte et ignore la corbeille', function () {
    $a = retEspace();
    $b = retEspace();
    $c = retEspace(true);
    foreach ([$a, $b, $c] as $ws) {
        retTentative($ws, 500);
    }

    expect(Artisan::call(EnvoiTentativesArchiver::SIGNATURE_PLANIFIEE))->toBe(0);

    expect(retDetail($a))->toBe(0)->and(retDetail($b))->toBe(0)
        ->and(count(retJours($a)))->toBe(1)->and(count(retJours($b)))->toBe(1)
        // espace à la corbeille : jamais lu, détail intact
        ->and(retDetail($c))->toBe(1)->and(retJours($c))->toBe([]);
});

test('l\'archivage ne touche aucune table de personnes', function () {
    $ws = retEspace();
    retTentative($ws, 500);
    $avant = retPersonnes();

    Artisan::call(EnvoiTentativesArchiver::SIGNATURE_PLANIFIEE);

    expect(retPersonnes())->toBe($avant);
});

test('un espace en erreur n\'arrête pas les autres et la commande sort en échec', function () {
    $a = retEspace();
    $b = retEspace();
    retTentative($a, 500);
    retTentative($b, 500);
    // Clash volontaire : le jour de $a est déjà agrégé avec la même clé => la fonction échoue pour $a seul.
    WorkspaceContext::run($a, fn () => DB::statement(
        "INSERT INTO envoi_tentatives_jours (workspace_id, jour, fournisseur, issue, code_smtp, nombre)
         VALUES (?, (now() AT TIME ZONE 'Europe/Paris')::date - 500, 'gmail', 'acceptee', NULL, 7)",
        [$a],
    ));

    expect(Artisan::call(EnvoiTentativesArchiver::SIGNATURE_PLANIFIEE))->toBe(1)
        ->and(retDetail($a))->toBe(1)
        ->and(retDetail($b))->toBe(0);
});

test('tâche planifiée : 04:50, un seul serveur, verrou explicite, sans condition de purge', function () {
    Artisan::call('list', ['--format' => 'txt']);
    $e = collect(app(Schedule::class)->events())->first(fn ($x) => str_contains((string) $x->command, EnvoiTentativesArchiver::SIGNATURE_PLANIFIEE));

    expect($e)->not->toBeNull()
        ->and($e->expression)->toBe('50 4 * * *')
        ->and($e->onOneServer)->toBeTrue()
        ->and($e->withoutOverlapping)->toBeTrue()
        ->and($e->expiresAt)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(360)
        ->and((new ReflectionProperty($e, 'filters'))->getValue($e))->toBe([]);
});

test('garde avec témoin : LecteurReportsDb garde sa fenêtre glissante d\'une heure', function () {
    $ws = retEspace();
    WorkspaceContext::run($ws, fn () => DB::statement(
        "INSERT INTO envoi_tentatives (workspace_id, fournisseur, issue, cle_idempotence, cree_le)
         VALUES (?, 'gmail', 'reportee', 'recente', now() - interval '30 minutes'),
                (?, 'gmail', 'reportee', 'ancienne', now() - interval '3 hours')",
        [$ws, $ws],
    ));
    $maintenant = new DateTimeImmutable('now');
    $lecteur = new LecteurReportsDb;

    expect($lecteur->sur1Heure($ws, 'gmail', $maintenant))->toBe(['envoyes' => 1, 'reports' => 1]);
    // Témoin : une fenêtre élargie (4 h) compterait aussi l'ancienne ligne et ferait rougir la garde.
    expect($lecteur->sur1Heure($ws, 'gmail', $maintenant->modify('+3 hours')))->not->toBe(['envoyes' => 1, 'reports' => 1]);
});
