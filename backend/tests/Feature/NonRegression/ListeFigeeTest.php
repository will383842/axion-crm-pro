<?php

/**
 * LISTE FIGÉE DU FILET (plan 22 § 6, point 2).
 *
 * `LISTE.json` nomme chaque garde du filet. Ce test vérifie que :
 *  - les 24 risques NR-01 à NR-24 (et la garde des horodatages de Paris) y figurent ;
 *  - chaque fichier de test NEUF existe, et chaque titre listé s’y trouve ;
 *  - chaque test des fichiers neufs est listé (rien n’est ajouté en silence) ;
 *  - chaque garde EXISTANTE citée existe ET est rattachée au groupe Pest
 *    `non-regression` dans `tests/Pest.php` ;
 *  - chaque garde du frontend existe.
 * Retirer un test sans le retirer de la liste, ou l’inverse, fait rougir.
 * Jamais de quarantaine : un test du filet ne se saute pas.
 */

use Tests\TestCase;

uses(TestCase::class);

/** @return array{version: int, gardes: list<array<string, mixed>>} */
function liste(): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/LISTE.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function titresDe(string $fichier): array
{
    $source = (string) file_get_contents($fichier);
    $titres = [];
    if (preg_match_all("/^\s*(?:test|it)\(\s*'((?:[^'\\\\]|\\\\.)*)'/m", $source, $m)) {
        foreach ($m[1] as $t) {
            $titres[] = str_replace("\\'", "'", $t);
        }
    }
    if (preg_match_all('/^\s*(?:test|it)\(\s*"((?:[^"\\\\]|\\\\.)*)"/m', $source, $m)) {
        array_push($titres, ...$m[1]);
    }

    return $titres;
}

test('LISTE le filet contient les 24 risques NR-01 à NR-24 et la garde des horodatages de Paris', function () {
    $ids = array_column(liste()['gardes'], 'id');

    foreach (range(1, 24) as $n) {
        expect($ids)->toContain(sprintf('NR-%02d', $n));
    }
    expect($ids)->toContain('HORODATAGES-PARIS');
});

test('LISTE chaque fichier neuf listé existe et porte tous les titres listés', function () {
    foreach (liste()['gardes'] as $garde) {
        foreach ($garde['nouveaux'] as $fichier => $titres) {
            $chemin = __DIR__ . '/' . $fichier;
            expect(is_file($chemin))->toBeTrue("{$garde['id']} : {$fichier} est introuvable");
            $presents = titresDe($chemin);
            foreach ($titres as $titre) {
                expect($presents)->toContain($titre);
            }
        }
    }
});

test('LISTE tous les tests des fichiers neufs sont listés (rien n’est ajouté en silence)', function () {
    $listes = [];
    foreach (liste()['gardes'] as $garde) {
        foreach ($garde['nouveaux'] as $fichier => $titres) {
            array_push($listes, ...array_map(fn ($t) => $fichier . '::' . $t, $titres));
        }
    }

    $reels = [];
    foreach (glob(__DIR__ . '/*Test.php') ?: [] as $chemin) {
        $nom = basename($chemin);
        if ($nom === 'ListeFigeeTest.php') {
            continue; // la liste ne se liste pas elle-même, titre par titre
        }
        array_push($reels, ...array_map(fn ($t) => $nom . '::' . $t, titresDe($chemin)));
    }

    sort($listes);
    sort($reels);
    expect(array_values(array_diff($reels, $listes)))->toBe([])
        ->and(array_values(array_diff($listes, $reels)))->toBe([]);
});

test('LISTE chaque garde existante citée existe et est rattachée au groupe non-regression', function () {
    $pest = (string) file_get_contents(base_path('tests/Pest.php'));

    foreach (liste()['gardes'] as $garde) {
        foreach ($garde['existants'] as $fichier) {
            expect(is_file(base_path('tests/Feature/' . $fichier)))->toBeTrue("{$garde['id']} : {$fichier} est introuvable");
            expect(str_contains($pest, "'Feature/{$fichier}'"))->toBeTrue("{$garde['id']} : {$fichier} n’est pas rattaché au groupe dans tests/Pest.php");
        }
    }
});

test('LISTE chaque garde du frontend listée existe', function () {
    foreach (liste()['gardes'] as $garde) {
        foreach ($garde['frontend'] as $fichier) {
            expect(is_file(base_path('../' . $fichier)))->toBeTrue("{$garde['id']} : {$fichier} est introuvable");
        }
    }
});

test('LISTE tout fichier de test du filet porte le groupe non-regression (dossier rattaché dans Pest.php)', function () {
    expect((string) file_get_contents(base_path('tests/Pest.php')))->toContain("->in('Feature/NonRegression')");
});
