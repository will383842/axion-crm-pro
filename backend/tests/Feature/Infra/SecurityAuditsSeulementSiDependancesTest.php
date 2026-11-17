<?php

/**
 * LOT A du plan « CI allégée » (#680) : sur PR, les audits de dépendances ne
 * tournent que si une dépendance ou un Dockerfile change ; gitleaks et semgrep
 * restent inconditionnels ; push et planning ne sont jamais filtrés.
 */

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineLotA(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function securiteLotA(): array
{
    return Yaml::parseFile(racineLotA() . '/.github/workflows/security.yml');
}

/** Regex ERE du script livré (ligne grep -Eq du job `changements`). */
function motifDepsLotA(): string
{
    $script = (string) securiteLotA()['jobs']['changements']['steps'][0]['run'];
    Assert::assertSame(1, preg_match("/grep -Eq '([^']+)'/", $script, $m), 'motif deps introuvable');

    return $m[1];
}

function correspondDepsLotA(string $fichier): bool
{
    $motif = '#' . str_replace('#', '\#', motifDepsLotA()) . '#';

    return preg_match($motif, $fichier) === 1;
}

it('gitleaks et semgrep n\'ont aucun filtre', function () {
    $jobs = securiteLotA()['jobs'];
    foreach (['gitleaks', 'semgrep'] as $nom) {
        expect($jobs[$nom])->not->toHaveKey('if')->and($jobs[$nom])->not->toHaveKey('needs');
    }
});

it('le planning est nocturne et push main n\'est pas filtré', function () {
    $on = securiteLotA()[true] ?? securiteLotA()['on'];
    expect($on['schedule'][0]['cron'])->toBe('0 4 * * *');
    expect($on['push']['branches'])->toBe(['main']);
    expect($on['push'])->not->toHaveKey('paths');
});

it('les audits sautent sur PR sans dépendances, jamais ailleurs', function () {
    $jobs = securiteLotA()['jobs'];
    foreach (['composer-audit', 'pnpm-audit'] as $nom) {
        expect($jobs[$nom]['needs'])->toBe('changements');
        expect($jobs[$nom]['if'])->toBe(
            "github.event_name != 'pull_request' || needs.changements.outputs.deps == 'true'",
        );
    }
    $script = (string) $jobs['changements']['steps'][0]['run'];
    expect($script)->toContain('"$EVENEMENT" != "pull_request"')->toContain('deps=true');
});

it('la liste deps couvre chaque lockfile, manifeste, Dockerfile et la matrice pnpm', function () {
    $racine = racineLotA();
    $trouves = trim((string) shell_exec(
        'cd ' . escapeshellarg($racine) . ' && git ls-files | grep -E "(^|/)(composer\.json|composer\.lock|package\.json|pnpm-lock\.yaml|Dockerfile[^/]*)$"',
    ));
    Assert::assertNotSame('', $trouves, 'git ls-files ne rend rien');
    foreach (explode("\n", $trouves) as $f) {
        expect(correspondDepsLotA($f))->toBeTrue($f);
    }
    foreach (securiteLotA()['jobs']['pnpm-audit']['strategy']['matrix']['path'] as $dir) {
        expect(correspondDepsLotA($dir . '/pnpm-lock.yaml'))->toBeTrue($dir);
    }
    foreach (['backend/composer.lock', 'Dockerfile.laravel', '.trivyignore.yaml', '.github/workflows/security.yml'] as $f) {
        expect(correspondDepsLotA($f))->toBeTrue($f);
    }
    foreach (['backend/app/Foo.php', 'docs/x.md', '.github/workflows/ci.yml'] as $f) {
        expect(correspondDepsLotA($f))->toBeFalse($f);
    }
});
