<?php

/**
 * GARDE : le « vendor » des sessions cloud (lot CI-A3).
 *
 * Dans une session cloud, `composer install` échoue (le proxy GitHub répond 403
 * aux archives codeload). La CI fabrique donc `vendor` et le publie ; la session
 * le télécharge via `infra/scripts/vendor-cloud.sh`. La CI, elle, continue de
 * lancer son propre `composer install` et n'utilise JAMAIS l'archive.
 *
 * Cette garde vérifie ce qui ne dépend pas du réseau : bit exécutable, refus hors
 * cloud, refus d'un vendor périmé (avec témoin), borne de durée du workflow,
 * hook de session. Elle ne prouve PAS le téléchargement à travers le proxy cloud.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineVendorCloud(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function scriptVendorCloud(): string
{
    return racineVendorCloud() . '/infra/scripts/vendor-cloud.sh';
}

/**
 * Exécute le script avec un environnement explicite.
 *
 * @param  array<string, string>  $env
 * @param  list<string>  $args
 * @return array{code: int, sortie: string}
 */
function lancerVendorCloud(array $env, array $args = []): array
{
    $cmd = 'env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' HOME=' . escapeshellarg((string) getenv('HOME'));
    foreach ($env as $k => $v) {
        $cmd .= ' ' . $k . '=' . escapeshellarg($v);
    }
    $cmd .= ' bash ' . escapeshellarg(scriptVendorCloud());
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $sortie = [];
    exec($cmd . ' 2>&1', $sortie, $code);

    return ['code' => $code, 'sortie' => implode("\n", $sortie)];
}

/** Fabrique un faux dépôt : backend/composer.lock + backend/vendor/composer/installed.json. */
function fauxDepotVendorCloud(string $versionInstallee): string
{
    $dir = sys_get_temp_dir() . '/vendor-cloud-' . bin2hex(random_bytes(4));
    mkdir($dir . '/backend/vendor/composer', 0777, true);
    file_put_contents($dir . '/backend/composer.lock', json_encode([
        'packages' => [['name' => 'acme/un', 'version' => '1.2.3']],
        'packages-dev' => [['name' => 'acme/deux', 'version' => 'v4.5.6']],
    ]));
    file_put_contents($dir . '/backend/vendor/composer/installed.json', json_encode([
        'packages' => [
            ['name' => 'acme/un', 'version' => $versionInstallee],
            ['name' => 'acme/deux', 'version' => 'v4.5.6'],
        ],
    ]));

    return $dir;
}

test('CI-A3 — le script existe et est exécutable (bit 100755 dans git)', function () {
    expect(is_file(scriptVendorCloud()))->toBeTrue('infra/scripts/vendor-cloud.sh est absent.');
    expect(is_executable(scriptVendorCloud()))->toBeTrue();

    $mode = trim((string) shell_exec('git -C ' . escapeshellarg(racineVendorCloud())
        . ' ls-files -s -- infra/scripts/vendor-cloud.sh 2>/dev/null'));
    // Hors dépôt git (copie de banc) : on ne peut pas lire le mode git, le bit disque suffit.
    if ($mode !== '') {
        expect($mode)->toStartWith('100755');
    }
});

test('CI-A3 — le script refuse de tourner hors session cloud', function () {
    foreach ([[], ['CLAUDE_CODE_REMOTE' => 'false'], ['CLAUDE_CODE_REMOTE' => '']] as $env) {
        $dir = fauxDepotVendorCloud('1.2.3');
        $r = lancerVendorCloud($env + ['VENDOR_CLOUD_RACINE' => $dir]);

        expect($r['code'])->toBe(0);
        expect($r['sortie'])->toContain('hors session cloud');
        // Il n'a rien fait : pas de dossier temporaire, vendor intact.
        expect(is_file($dir . '/backend/vendor/composer/installed.json'))->toBeTrue();
    }
});

test('CI-A3 — TÉMOIN : un vendor à jour est accepté', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir], ['verifier']);

    expect($r['code'])->toBe(0, $r['sortie']);
    expect($r['sortie'])->toContain('à jour');
});

test('CI-A3 — un vendor périmé (composer.lock modifié) est REFUSÉ', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    // Le composer.lock avance d'une version, le vendor reste ancien.
    file_put_contents($dir . '/backend/composer.lock', json_encode([
        'packages' => [['name' => 'acme/un', 'version' => '1.2.4']],
        'packages-dev' => [['name' => 'acme/deux', 'version' => 'v4.5.6']],
    ]));
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir], ['verifier']);

    expect($r['code'])->not->toBe(0);
    expect($r['sortie'])->toContain('périmé');
    expect($r['sortie'])->toContain('acme/un');
});

test('CI-A3 — un paquet absent du vendor est REFUSÉ', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    file_put_contents($dir . '/backend/composer.lock', json_encode([
        'packages' => [['name' => 'acme/un', 'version' => '1.2.3'], ['name' => 'acme/trois', 'version' => '9.9.9']],
        'packages-dev' => [['name' => 'acme/deux', 'version' => 'v4.5.6']],
    ]));
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir], ['verifier']);

    expect($r['code'])->not->toBe(0);
    expect($r['sortie'])->toContain('acme/trois');
});

test('CI-A3 — le workflow vendor-cloud porte un timeout-minutes sur chaque job', function () {
    $chemin = racineVendorCloud() . '/.github/workflows/vendor-cloud.yml';
    expect(is_file($chemin))->toBeTrue('.github/workflows/vendor-cloud.yml est absent.');

    $wf = (array) Yaml::parseFile($chemin);
    $jobs = (array) ($wf['jobs'] ?? []);
    expect($jobs)->not->toBeEmpty();
    foreach ($jobs as $nom => $job) {
        $borne = ((array) $job)['timeout-minutes'] ?? null;
        expect(is_int($borne) && $borne >= 1 && $borne <= 120)->toBeTrue("Le job {$nom} n'a pas de timeout-minutes (1..120).");
    }
});

test('CI-A3 — TÉMOIN : la lecture voit l\'absence de timeout', function () {
    $nu = (array) Yaml::parse("jobs:\n  a:\n    runs-on: ubuntu-24.04\n");
    expect(((array) $nu['jobs']['a'])['timeout-minutes'] ?? null)->toBeNull();
});

test('CI-A3 — le workflow n\'a que le GITHUB_TOKEN, déclenché par composer.lock sur main', function () {
    $texte = (string) file_get_contents(racineVendorCloud() . '/.github/workflows/vendor-cloud.yml');
    $wf = (array) Yaml::parse($texte);

    expect($wf['permissions'] ?? null)->toBe(['contents' => 'write']);
    expect($texte)->not->toMatch('/secrets\.(?!GITHUB_TOKEN)/');
    expect($texte)->toContain('backend/composer.lock');
    expect($texte)->toContain('workflow_dispatch');
});

test('CI-A3 — ci.yml n\'utilise jamais l\'archive vendor-cloud', function () {
    expect((string) file_get_contents(racineVendorCloud() . '/.github/workflows/ci.yml'))->not->toContain('vendor-cloud');
});

test('CI-A3 — le hook SessionStart lance le script sans jamais bloquer la session', function () {
    $chemin = racineVendorCloud() . '/.claude/settings.json';
    expect(is_file($chemin))->toBeTrue('.claude/settings.json est absent.');

    $json = json_decode((string) file_get_contents($chemin), true);
    $commandes = [];
    foreach ((array) ($json['hooks']['SessionStart'] ?? []) as $groupe) {
        foreach ((array) ($groupe['hooks'] ?? []) as $h) {
            $commandes[] = $h['command'] ?? '';
        }
    }
    expect($commandes)->toContain('bash infra/scripts/vendor-cloud.sh || true');
});
