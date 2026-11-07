<?php

/**
 * GARDE : LE DECOUPAGE DE LA SUITE PEST EN PARTS NE PERD ET NE DOUBLONNE RIEN.
 *
 * La CI repartit les fichiers de test en 4 parts (`tests/ci/decouper-suite.php`).
 * Une part qui oublie un fichier = un test qui ne tourne plus nulle part, et la
 * CI reste verte : c'est le defaut meme que ce decoupage ne doit pas creer.
 * Les fonctions du script sont testees ici ; le script se verifie aussi lui-meme
 * a chaque execution (il sort en erreur si la partition est fausse).
 */

use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__) . '/ci/decouper-suite.php';

function racineBackendDecoupage(): string
{
    return dirname(__DIR__, 2);
}

test('DECOUPAGE — les parts couvrent TOUS les fichiers de test, sans recouvrement', function () {
    $fichiers = decoupageListerFichiers(racineBackendDecoupage());

    // Temoin de non-vacuite : une liste vide verdirait sans rien mesurer.
    expect(count($fichiers))->toBeGreaterThan(100);

    $parts = decoupageRepartir($fichiers, 4);

    expect($parts)->toHaveCount(4);
    expect(decoupageVerifier($parts, array_keys($fichiers)))->toBe([]);

    $tous = array_merge(...array_values($parts));
    expect(count($tous))->toBe(count($fichiers));
    expect(count(array_unique($tous)))->toBe(count($tous));

    foreach ($parts as $numero => $part) {
        expect($part)->not->toBeEmpty("La part {$numero} est vide.");
    }
});

test('DECOUPAGE — la repartition est deterministe', function () {
    $fichiers = decoupageListerFichiers(racineBackendDecoupage());

    expect(decoupageRepartir($fichiers, 4))->toBe(decoupageRepartir($fichiers, 4));
});

test('DECOUPAGE — TEMOIN : la verification rougit si un fichier manque, est en double ou est inconnu', function () {
    $fichiers = ['a/ATest.php' => 30, 'b/BTest.php' => 20, 'c/CTest.php' => 10, 'd/DTest.php' => 5];
    $parts = decoupageRepartir($fichiers, 2);
    $tous = array_keys($fichiers);

    expect(decoupageVerifier($parts, $tous))->toBe([]);

    // Un fichier disparait d'une part.
    $amputee = $parts;
    $amputee[1] = array_values(array_diff($amputee[1], ['a/ATest.php']));
    $amputee[2] = array_values(array_diff($amputee[2], ['a/ATest.php']));
    $erreurs = decoupageVerifier($amputee, $tous);
    expect($erreurs)->not->toBeEmpty();
    expect(implode("\n", $erreurs))->toContain('a/ATest.php');

    // Un fichier se retrouve dans deux parts.
    $double = $parts;
    $double[1][] = 'c/CTest.php';
    $double[2][] = 'c/CTest.php';
    expect(implode("\n", decoupageVerifier($double, $tous)))->toContain('c/CTest.php');

    // Une part reference un fichier qui n'existe pas.
    $inconnu = $parts;
    $inconnu[1][] = 'z/ZTest.php';
    expect(implode("\n", decoupageVerifier($inconnu, $tous)))->toContain('z/ZTest.php');
});

test('DECOUPAGE — le XML genere ne remplace que le bloc <testsuites>, le bloc <php> reste identique', function () {
    $ci = (string) file_get_contents(racineBackendDecoupage() . '/phpunit-ci.xml');
    $genere = decoupageGenererXml($ci, ['tests/Unit/ATest.php', 'tests/Feature/BTest.php']);

    expect($genere)->toContain('<file>tests/Unit/ATest.php</file>');
    expect($genere)->not->toContain('<directory>tests/Unit</directory>');

    preg_match('~<php>.*</php>~s', $ci, $avant);
    preg_match('~<php>.*</php>~s', $genere, $apres);
    expect($apres[0])->toBe($avant[0]);

    // Exactement un remplacement : sans bloc <testsuites>, le script refuse.
    expect(fn () => decoupageGenererXml('<phpunit></phpunit>', ['x']))->toThrow(RuntimeException::class);
});

test('DECOUPAGE — ci.yml : la matrice backend est sans needs, et le verdict porte le nom exige par la protection de branche', function () {
    $ci = (array) Yaml::parseFile(dirname(__DIR__, 3) . '/.github/workflows/ci.yml');
    $jobs = (array) $ci['jobs'];

    expect($jobs)->toHaveKeys(['backend', 'backend-verdict', 'filet-non-regression']);

    $backend = (array) $jobs['backend'];
    expect($backend)->not->toHaveKey('needs');
    expect($backend['strategy']['matrix']['part'] ?? null)->toBe([1, 2, 3, 4]);
    expect($backend['strategy']['fail-fast'] ?? null)->toBeFalse();
    expect($backend['name'] ?? '')->not->toBe('Backend Laravel (install + PHPStan + Pint + Pest)');

    $verdict = (array) $jobs['backend-verdict'];
    expect($verdict['name'])->toBe('Backend Laravel (install + PHPStan + Pint + Pest)');
    expect($verdict['needs'])->toBe(['filet-non-regression', 'backend']);
    expect(trim((string) $verdict['if']))->toBe('always()');
    expect($verdict['timeout-minutes'])->toBe(10);

    // PHPStan et Pint ne tournent que sur la part 1.
    foreach ($backend['steps'] as $etape) {
        $nom = (string) ($etape['name'] ?? '');
        if (str_starts_with($nom, 'PHPStan') || str_starts_with($nom, 'Pint')) {
            expect($etape['if'] ?? null)->toBe('matrix.part == 1');
        }
    }
});
