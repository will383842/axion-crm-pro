<?php

/**
 * L7 — Le calcul agrégé ne porte AUCUNE donnée de personne et masque les petites cellules.
 * Le masquage est une fonction pure (sans base) ; le calcul SQL est dans AgregatsEnBaseTest.
 */

use App\Crm\Usine\AgregatsOrigines;
use Tests\TestCase;

uses(TestCase::class);

function l7Ligne(string $mot, int $d, int $g = 5, int $a = 5, int $m = 5, string $reseau = 'instagram', string $compte = 'axion'): array
{
    return ['jour' => '2026-11-03', 'reseau' => $reseau, 'compte' => $compte, 'mot' => $mot,
        'demandes' => $d, 'guides' => $g, 'accords' => $a, 'mises_a_l_ecart' => $m];
}

test('une cellule de 1 ou 2 devient "<3", une cellule de 3 reste un nombre', function () {
    $sortie = AgregatsOrigines::masquer([l7Ligne('guide', 1, 2, 3, 10, 'x'), l7Ligne('autre', 9, 9, 9, 9, 'y')], 3);

    $guide = collect($sortie)->firstWhere('mot', 'guide');
    expect($guide['demandes'])->toBe('<3')->and($guide['guides'])->toBe('<3')
        ->and($guide['accords'])->toBe(3);
});

test('anti-différence : une seule cellule masquée dans un groupe en masque une seconde', function () {
    $sortie = AgregatsOrigines::masquer([l7Ligne('a', 1), l7Ligne('b', 40), l7Ligne('c', 7)], 3);

    $demandes = array_column($sortie, 'demandes', 'mot');
    expect($demandes['a'])->toBe('<3')
        ->and(count(array_filter($demandes, fn ($v) => $v === '<3')))->toBeGreaterThanOrEqual(2);
});

test('une ligne dont toutes les cellules sont < 3 est retirée (aucune ligne déductible)', function () {
    $sortie = AgregatsOrigines::masquer([l7Ligne('seul', 1, 1, 1, 0)], 3);

    expect($sortie)->toBe([]);
});

test('les clés de sortie sont FERMÉES', function () {
    $sortie = AgregatsOrigines::masquer([l7Ligne('a', 10), l7Ligne('b', 10)], 3);

    expect(array_keys($sortie[0]))->toBe(['jour', 'reseau', 'compte', 'mot', 'demandes', 'guides', 'accords', 'mises_a_l_ecart']);
});

/** Aucun groupe (ligne OU colonne) ne garde UNE SEULE cellule masquée avec des cellules visibles. */
function l7SansDifferencePossible(array $sortie, string $metrique): bool
{
    $axes = [
        fn ($l) => 'L|' . $l['jour'] . '|' . $l['reseau'] . '|' . $l['compte'],
        fn ($l) => 'C|' . $l['jour'] . '|' . $l['mot'],
    ];
    foreach ($axes as $cle) {
        $groupes = [];
        foreach ($sortie as $l) {
            $groupes[$cle($l)][] = $l[$metrique] === '<3';
        }
        foreach ($groupes as $masques) {
            $m = count(array_filter($masques));
            if ($m === 1 && count($masques) > 1) {
                return false;
            }
        }
    }

    return true;
}

test('anti-différence sur DEUX axes : ligne (jour/réseau/compte) ET colonne (mot)', function () {
    $sortie = AgregatsOrigines::masquer([
        l7Ligne('a', 1, 9, 9, 9, 'instagram'), l7Ligne('b', 40, 9, 9, 9, 'instagram'), l7Ligne('c', 7, 9, 9, 9, 'instagram'),
        l7Ligne('a', 50, 9, 9, 9, 'tiktok'), l7Ligne('b', 9, 9, 9, 9, 'tiktok'), l7Ligne('c', 12, 9, 9, 9, 'tiktok'),
    ], 3);

    expect(l7SansDifferencePossible($sortie, 'demandes'))->toBeTrue();
    // La colonne « a » : instagram masqué → tiktok/a (50) l'est aussi.
    $a = collect($sortie)->where('mot', 'a')->pluck('demandes', 'reseau');
    expect($a['tiktok'])->toBe('<3');
});

test('anti-différence : le masquage en cascade se stabilise (point fixe) sur toutes les métriques', function () {
    $sortie = AgregatsOrigines::masquer([
        l7Ligne('a', 1, 1, 9, 9, 'x'), l7Ligne('b', 5, 6, 9, 9, 'x'), l7Ligne('a', 8, 7, 9, 9, 'y'),
        l7Ligne('b', 9, 4, 9, 9, 'y'), l7Ligne('c', 20, 20, 9, 9, 'y'),
    ], 3);

    foreach (['demandes', 'guides', 'accords', 'mises_a_l_ecart'] as $m) {
        expect(l7SansDifferencePossible($sortie, $m))->toBeTrue();
    }
});
