<?php

/**
 * CANAUX INTERNES SIGNÉS — validation de la fenêtre d'horodatage au démarrage.
 *
 * `CRM_INGEST_MAX_CLOCK_SKEW` absente → 300 ; posée → entier de 1 à 3600, sinon
 * `CanauxSignesServiceProvider::boot()` lève une exception et l'application ne
 * démarre pas.
 */

use App\Providers\CanauxSignesServiceProvider;
use App\Support\FenetreHorodatage;
use App\Support\HmacSignature;
use Tests\TestCase;

uses(TestCase::class);

function demarrerCanauxSignes(): void
{
    (new CanauxSignesServiceProvider(app()))->boot();
}

test('démarrage REFUSÉ pour une fenêtre posée mais invalide', function (mixed $valeur) {
    config(['crm.ingest.max_clock_skew_seconds' => $valeur]);

    expect(fn () => demarrerCanauxSignes())->toThrow(RuntimeException::class, 'CRM_INGEST_MAX_CLOCK_SKEW');
})->with([
    'vide' => [''],
    'non numérique' => ['abc'],
    'zéro' => ['0'],
    'négative' => ['-1'],
    'zéro entier' => [0],
    'négative entière' => [-1],
    'décimale' => ['300.5'],
    'déraisonnable' => ['3601'],
    'nulle' => [null],
]);

test('TÉMOIN : une fenêtre valide démarre et est réécrite en entier', function () {
    config(['crm.ingest.max_clock_skew_seconds' => ' 120 ']);

    demarrerCanauxSignes();

    expect(config('crm.ingest.max_clock_skew_seconds'))->toBe(120);
});

test('TÉMOIN : les bornes 1 et 3600 sont acceptées', function () {
    expect(FenetreHorodatage::valider('1'))->toBe(1)
        ->and(FenetreHorodatage::valider(3600))->toBe(3600);
});

test('variable ABSENTE : la configuration livrée vaut 300, et elle démarre', function () {
    $cle = 'CRM_INGEST_MAX_CLOCK_SKEW';
    $avant = [$_SERVER[$cle] ?? null, $_ENV[$cle] ?? null, getenv($cle)];
    unset($_SERVER[$cle], $_ENV[$cle]);
    putenv($cle);

    try {
        $livree = require config_path('crm.php');

        expect($livree['ingest']['max_clock_skew_seconds'])->toBe(300)
            ->and(FenetreHorodatage::valider($livree['ingest']['max_clock_skew_seconds']))->toBe(300);
    } finally {
        [$serveur, $env, $getenv] = $avant;
        if ($serveur !== null) {
            $_SERVER[$cle] = $serveur;
        }
        if ($env !== null) {
            $_ENV[$cle] = $env;
        }
        if ($getenv !== false) {
            putenv("{$cle}={$getenv}");
        }
    }
});

test('le contrôle de démarrage est bien enregistré parmi les fournisseurs', function () {
    $fournisseurs = require base_path('bootstrap/providers.php');

    expect($fournisseurs)->toContain(CanauxSignesServiceProvider::class);
});

test('une fenêtre nulle ou négative passée au code fait ÉCHOUER la vérification', function () {
    $maintenant = (string) time();

    expect(HmacSignature::timestampWithinWindow($maintenant, 0))->toBeFalse()
        ->and(HmacSignature::timestampWithinWindow($maintenant, -1))->toBeFalse()
        // Témoin : la même valeur passe avec une fenêtre positive.
        ->and(HmacSignature::timestampWithinWindow($maintenant, 300))->toBeTrue();
});

test('horodatage absent ou non entier : hors fenêtre', function () {
    expect(HmacSignature::timestampWithinWindow(null, 300))->toBeFalse()
        ->and(HmacSignature::timestampWithinWindow('', 300))->toBeFalse()
        ->and(HmacSignature::timestampWithinWindow('abc', 300))->toBeFalse()
        ->and(HmacSignature::timestampWithinWindow('12.5', 300))->toBeFalse();
});
