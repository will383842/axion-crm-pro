<?php

/**
 * LOT C du plan « CI allégée » (#680) : sur PR, le job `workers` (contrôle exigé
 * « Workers Node + Playwright ») est SAUTÉ, donc vert, quand la PR ne touche ni
 * `workers/`, ni `Dockerfile.worker`, ni `ci.yml`. Le contrôle exigé garde son nom
 * et se termine toujours. Aucun filtre `paths:` ; en cas de doute, il tourne.
 * Le frontend n'est PAS filtré ici (décision D1 du fondateur).
 */

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function ciLotC(): array
{
    $racine = realpath(base_path('..')) ?: base_path('..');

    return Yaml::parseFile($racine . '/.github/workflows/ci.yml');
}

function motifWorkersLotC(): string
{
    $script = (string) ciLotC()['jobs']['changements']['steps'][0]['run'];
    Assert::assertSame(1, preg_match("/grep -Eq '([^']+)'/", $script, $m), 'motif workers introuvable');

    return $m[1];
}

function correspondWorkersLotC(string $fichier): bool
{
    return preg_match('#' . str_replace('#', '\#', motifWorkersLotC()) . '#', $fichier) === 1;
}

it('workers saute sur PR sans fichier workers, jamais ailleurs, et garde son nom', function () {
    $job = ciLotC()['jobs']['workers'];
    expect($job['name'])->toBe('Workers Node + Playwright')
        ->and($job['needs'])->toBe('changements');
    $condition = (string) $job['if'];
    expect($condition)->toContain('always()')
        ->toContain("github.event_name != 'pull_request'")
        ->toContain("needs.changements.result != 'success'")
        ->toContain("needs.changements.outputs.workers == 'true'");
});

it('le job changements répond « oui » hors PR et en cas de doute', function () {
    $script = (string) ciLotC()['jobs']['changements']['steps'][0]['run'];
    expect($script)->toContain('"$EVENEMENT" != "pull_request"')
        ->toContain('API en erreur')->toContain('3000')
        ->toContain('workers=true')->toContain('workers=false');
    expect(ciLotC()['jobs']['changements']['permissions'])->toBe(['contents' => 'read', 'pull-requests' => 'read']);
});

it('la liste couvre workers/, Dockerfile.worker et ci.yml, et rien d\'autre', function () {
    foreach (['workers/package.json', 'workers/src/a/b.ts', 'Dockerfile.worker', '.github/workflows/ci.yml'] as $f) {
        expect(correspondWorkersLotC($f))->toBeTrue($f);
    }
    foreach (['backend/app/Foo.php', 'frontend/src/a.ts', 'docs/workers/x.md', 'Dockerfile.laravel', '.github/workflows/security.yml'] as $f) {
        expect(correspondWorkersLotC($f))->toBeFalse($f);
    }
});

it('le frontend, le backend et le filet ne dépendent pas de changements', function () {
    $jobs = ciLotC()['jobs'];
    foreach (['frontend', 'backend', 'filet-non-regression', 'donnees-personnelles', 'gardes-infra'] as $nom) {
        $needs = (array) ($jobs[$nom]['needs'] ?? []);
        expect($needs)->not->toContain('changements');
    }
    expect((array) $jobs['backend-verdict']['needs'])->not->toContain('changements');
});

it('aucun filtre paths sur ci.yml', function () {
    $on = ciLotC()[true] ?? ciLotC()['on'];
    expect($on['pull_request'])->not->toHaveKey('paths');
});
