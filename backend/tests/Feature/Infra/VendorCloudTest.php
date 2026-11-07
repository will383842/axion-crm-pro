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
    $GLOBALS['vendorCloudTmp'][] = $dir;
    mkdir($dir . '/backend/vendor/composer', 0777, true);
    file_put_contents($dir . '/backend/composer.json', '{}');
    file_put_contents($dir . '/backend/composer.lock', json_encode([
        'content-hash' => md5('vendor-cloud'),
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

afterAll(function () {
    foreach ((array) ($GLOBALS['vendorCloudTmp'] ?? []) as $d) {
        if (is_dir($d)) {
            exec('rm -rf ' . escapeshellarg($d));
        }
    }
});

/**
 * Fabrique un « serveur » file:// : archive vendor/ + .sha256 pour le composer.lock du faux dépôt.
 * $sha = null (somme juste) ou une somme volontairement fausse.
 */
function fauxServeurVendorCloud(string $depot, ?string $sha = null, bool $avecArchive = true): string
{
    $srv = $depot . '/serveur';
    mkdir($srv);
    if (! $avecArchive) {
        return $srv;
    }
    $nom = 'vendor-' . substr(hash_file('sha256', $depot . '/backend/composer.lock'), 0, 16) . '.tar.gz';
    exec('tar -czf ' . escapeshellarg("{$srv}/{$nom}") . ' -C ' . escapeshellarg($depot . '/backend') . ' vendor');
    $somme = $sha ?? hash_file('sha256', "{$srv}/{$nom}");
    file_put_contents("{$srv}/{$nom}.sha256", "{$somme}  {$nom}\n");

    return $srv;
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

test('CI-A3 — le workflow : GITHUB_TOKEN seul, déclencheurs EXACTS, deux jobs aux droits séparés', function () {
    $texte = (string) file_get_contents(racineVendorCloud() . '/.github/workflows/vendor-cloud.yml');
    $wf = (array) Yaml::parse($texte);

    // Déclencheurs : exactement push (main, composer.lock) + workflow_dispatch. Rouge si pull_request(_target) apparaît.
    $on = (array) ($wf['on'] ?? $wf[true] ?? []);
    expect(array_keys($on))->toEqualCanonicalizing(['push', 'workflow_dispatch']);
    expect($on['push'])->toBe(['branches' => ['main'], 'paths' => ['backend/composer.lock']]);

    // Workflow : aucun droit par défaut ; les droits sont déclarés job par job.
    expect($wf['permissions'] ?? null)->toBe([]);
    $jobs = (array) $wf['jobs'];
    expect(array_keys($jobs))->toEqualCanonicalizing(['fabriquer', 'publier']);
    expect(((array) $jobs['fabriquer'])['permissions'] ?? null)->toBe(['contents' => 'read']);
    expect(((array) $jobs['publier'])['permissions'] ?? null)->toBe(['contents' => 'write']);

    // Les deux jobs ne tournent que sur main.
    foreach ($jobs as $nom => $job) {
        expect(((array) $job)['if'] ?? null)->toBe("github.ref == 'refs/heads/main'", "Le job {$nom} doit être limité à main.");
    }
    expect(((array) $jobs['publier'])['needs'] ?? null)->toBe('fabriquer');

    // « fabriquer » ne garde pas le jeton et ne publie rien ; « publier » n'exécute aucun code tiers.
    $fab = (string) json_encode($jobs['fabriquer']);
    expect($fab)->toContain('persist-credentials')->toContain('upload-artifact');
    expect($fab)->not->toContain('gh release');
    $pub = (string) json_encode($jobs['publier']);
    expect($pub)->toContain('download-artifact')->toContain('gh release');
    expect($pub)->not->toContain('composer')->not->toContain('setup-php')->not->toContain('actions\/checkout');

    expect($texte)->not->toMatch('/secrets\.(?!GITHUB_TOKEN)/');
    expect($texte)->not->toContain('pull_request');
});

test('CI-A3 — TÉMOIN : la lecture de `on` voit un déclencheur pull_request ajouté', function () {
    $wf = (array) Yaml::parse("on:\n  push:\n    branches: [main]\n  pull_request: {}\n");
    expect(array_keys((array) ($wf['on'] ?? $wf[true])))->toContain('pull_request');
});

test('CI-A3 — le risque accepté (sha256 = intégrité, pas authenticité) est écrit partout', function () {
    $racine = racineVendorCloud();
    foreach (['infra/scripts/vendor-cloud.sh', '.github/workflows/vendor-cloud.yml', 'CONTRIBUTING.md'] as $f) {
        expect((string) file_get_contents("{$racine}/{$f}"))->toContain('authenticité', "{$f} doit documenter le risque accepté.");
    }
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
            if (($h['command'] ?? '') === 'bash infra/scripts/vendor-cloud.sh || true') {
                expect($h['timeout'] ?? null)->toBe(120);
            }
        }
    }
    expect($commandes)->toContain('bash infra/scripts/vendor-cloud.sh || true');
});

test('CI-A3 — TÉMOIN : une archive correcte est installée (URL file://)', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    $srv = fauxServeurVendorCloud($dir);
    exec('rm -rf ' . escapeshellarg($dir . '/backend/vendor'));
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir, 'VENDOR_CLOUD_URL_BASE' => 'file://' . $srv]);

    expect($r['code'])->toBe(0, $r['sortie']);
    expect(is_file($dir . '/backend/vendor/composer/installed.json'))->toBeTrue($r['sortie']);
    expect(is_dir($dir . '/backend/vendor.ancien'))->toBeFalse('L\'ancien vendor doit être supprimé après échange.');
});

test('CI-A3 — un sha256 différent est REFUSÉ et dit « intégrité de transfert »', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    $srv = fauxServeurVendorCloud($dir, str_repeat('0', 64));
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir, 'VENDOR_CLOUD_URL_BASE' => 'file://' . $srv]);

    expect($r['code'])->not->toBe(0);
    expect($r['sortie'])->toContain('sha256 différent')->toContain('intégrité de transfert');
    // Le vendor de la session n'a pas bougé.
    expect(is_file($dir . '/backend/vendor/composer/installed.json'))->toBeTrue();
});

test('CI-A3 — une archive absente (404) est dite clairement, sans échec', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    $srv = fauxServeurVendorCloud($dir, null, false);
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir, 'VENDOR_CLOUD_URL_BASE' => 'file://' . $srv]);

    expect($r['code'])->toBe(0, $r['sortie']);
    expect($r['sortie'])->toContain("n'existe pas");
});

test('CI-A3 — un paquet EN TROP dans le vendor est REFUSÉ', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    file_put_contents($dir . '/backend/vendor/composer/installed.json', json_encode(['packages' => [
        ['name' => 'acme/un', 'version' => '1.2.3'],
        ['name' => 'acme/deux', 'version' => 'v4.5.6'],
        ['name' => 'acme/intrus', 'version' => '0.0.1'],
    ]]));
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir], ['verifier']);

    expect($r['code'])->not->toBe(0);
    expect($r['sortie'])->toContain('acme/intrus')->toContain('en trop');
});

test('CI-A3 — un paquet dev-* dont la référence diffère est REFUSÉ', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    file_put_contents($dir . '/backend/composer.lock', json_encode([
        'packages' => [['name' => 'acme/un', 'version' => 'dev-main', 'dist' => ['reference' => 'aaa'], 'source' => ['reference' => 'aaa']]],
        'packages-dev' => [['name' => 'acme/deux', 'version' => 'v4.5.6']],
    ]));
    file_put_contents($dir . '/backend/vendor/composer/installed.json', json_encode(['packages' => [
        ['name' => 'acme/un', 'version' => 'dev-main', 'dist' => ['reference' => 'bbb'], 'source' => ['reference' => 'bbb']],
        ['name' => 'acme/deux', 'version' => 'v4.5.6'],
    ]]));
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir], ['verifier']);

    expect($r['code'])->not->toBe(0);
    expect($r['sortie'])->toContain('acme/un')->toContain('référence');
});

test('CI-A3 — un vendor qui est un lien symbolique est REFUSÉ', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    $srv = $dir . '/serveur';
    mkdir($srv);
    $tmp = $dir . '/fab';
    mkdir($tmp);
    symlink('/etc', $tmp . '/vendor');
    $nom = 'vendor-' . substr(hash_file('sha256', $dir . '/backend/composer.lock'), 0, 16) . '.tar.gz';
    exec('tar -czf ' . escapeshellarg("{$srv}/{$nom}") . ' -C ' . escapeshellarg($tmp) . ' vendor');
    file_put_contents("{$srv}/{$nom}.sha256", hash_file('sha256', "{$srv}/{$nom}") . "  {$nom}\n");
    $r = lancerVendorCloud(['CLAUDE_CODE_REMOTE' => 'true', 'VENDOR_CLOUD_RACINE' => $dir, 'VENDOR_CLOUD_URL_BASE' => 'file://' . $srv]);

    expect($r['code'])->not->toBe(0);
    expect($r['sortie'])->toContain('lien symbolique');
    expect(is_link($dir . '/backend/vendor'))->toBeFalse();
});

test('CI-A3 — le refus est aussi écrit sur stdout (visible dans la session)', function () {
    $dir = fauxDepotVendorCloud('1.2.3');
    $srv = fauxServeurVendorCloud($dir, str_repeat('0', 64));
    $cmd = 'env -i PATH=' . escapeshellarg((string) getenv('PATH')) . ' CLAUDE_CODE_REMOTE=true VENDOR_CLOUD_RACINE=' . escapeshellarg($dir)
        . ' VENDOR_CLOUD_URL_BASE=' . escapeshellarg('file://' . $srv) . ' bash ' . escapeshellarg(scriptVendorCloud()) . ' 2>/dev/null';
    exec($cmd, $sortie);

    expect(implode("\n", $sortie))->toContain('REFUSÉE');
});

test('CI-A3 — le script durcit curl, le dump-autoload et l\'échange du vendor', function () {
    $s = (string) file_get_contents(scriptVendorCloud());
    expect($s)->toContain('--fail')->toContain('--max-time 120')->toContain('--retry 2');
    expect($s)->toContain('dump-autoload --no-scripts --no-plugins');
    expect($s)->toContain('vendor.ancien');
});
