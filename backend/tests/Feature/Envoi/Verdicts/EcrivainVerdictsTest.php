<?php

/**
 * VERDICTS-CODE-1 — l'écrivain (T1.11, T1.12) et la valeur VerdictAdresse.
 */

use App\Envoi\Verdicts\EcrivainVerdicts;
use App\Envoi\Verdicts\VerdictAdresse;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__ . '/VerdictsOutils.php';

uses(TestCase::class, RefreshDatabase::class);

afterEach(fn () => WorkspaceContext::clear());

function vdcVerdict(string $verdict = 'valide', string $motif = 'mx', ?DateTimeImmutable $le = null): VerdictAdresse
{
    return new VerdictAdresse($verdict, $motif, $le ?? new DateTimeImmutable('now'));
}

test('T1.11 insert seul : un second appel identique < 20 j ne crée rien, ≥ 20 j crée une ligne', function () {
    $ws = vdcEspace();
    $e = new EcrivainVerdicts;
    $emp = vdcEmpreinte('zoe@societe-a.example.test');
    $t0 = new DateTimeImmutable('2026-11-01 10:00:00');

    expect($e->ecrire($ws, [$emp => vdcVerdict('valide', 'mx', $t0)], 'interne-v1', $t0))->toBe(1);
    $l = vdcLignes($ws)[0];
    expect($l->source)->toBe('interne-v1')->and($l->motif)->toBe('mx')->and($l->empreinte_adresse)->toBe($emp);

    $t19 = $t0->modify('+19 days');
    expect($e->ecrire($ws, [$emp => vdcVerdict('valide', 'mx', $t19)], 'interne-v1', $t19))->toBe(0);
    $t20 = $t0->modify('+20 days');
    expect($e->ecrire($ws, [$emp => vdcVerdict('valide', 'mx', $t20)], 'interne-v1', $t20))->toBe(1)
        ->and(count(vdcLignes($ws)))->toBe(2);

    // un verdict, une source ou un motif différent : nouvelle ligne tout de suite
    expect($e->ecrire($ws, [$emp => vdcVerdict('invalide', 'rebond_dur', $t20)], 'interne-v1', $t20))->toBe(1)
        ->and($e->ecrire($ws, [$emp => vdcVerdict('invalide', 'rebond_dur', $t20)], 'autre-v1', $t20))->toBe(1)
        ->and($e->ecrire($ws, [$emp => null], 'interne-v1', $t20))->toBe(0)
        ->and(count(vdcLignes($ws)))->toBe(4);
});

test('T1.12 hors du WorkspaceContext de l’espace → exception, 0 ligne', function () {
    $ws = vdcEspace(false);
    $autre = vdcEspace(false);
    $emp = vdcEmpreinte('zoe@societe-a.example.test');
    $e = new EcrivainVerdicts;

    expect(fn () => $e->ecrire($ws, [$emp => vdcVerdict()], 'interne-v1', new DateTimeImmutable('now')))->toThrow(RuntimeException::class);
    WorkspaceContext::set($autre);
    expect(fn () => $e->ecrire($ws, [$emp => vdcVerdict()], 'interne-v1', new DateTimeImmutable('now')))->toThrow(RuntimeException::class);
    expect(DB::table('envoi_verdicts_adresse')->count())->toBe(0);
});

test('source invalide et valeur VerdictAdresse hors liste refusées', function () {
    $ws = vdcEspace();
    expect(fn () => (new EcrivainVerdicts)->ecrire($ws, [vdcEmpreinte('a@b.example.test') => vdcVerdict()], 'Source Invalide!', new DateTimeImmutable('now')))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new VerdictAdresse('inconnu', 'mx', new DateTimeImmutable('now')))->toThrow(InvalidArgumentException::class);
    expect(fn () => new VerdictAdresse('valide', 'pas-un-motif', new DateTimeImmutable('now')))->toThrow(InvalidArgumentException::class);
    expect(VerdictAdresse::MOTIFS)->toBe(['syntaxe', 'jetable', 'rebond_dur', 'role_technique', 'generique', 'mx', 'a', 'mx_nul', 'sans_courrier', 'inexistant']);
});
