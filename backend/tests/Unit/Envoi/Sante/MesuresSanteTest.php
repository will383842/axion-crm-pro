<?php

use App\Envoi\Sante\MesuresSante;

test('types stricts : une chaîne, un booléen pris pour un nombre ou un nombre pris pour un booléen n’est PAS une mesure', function (string $cle, mixed $valeur, string $champ) {
    expect(MesuresSante::depuis([$cle => $valeur])->{$champ})->toBeNull();
})->with([
    ['file_age_s', '12', 'ageFileSecondes'],
    ['file_age_s', true, 'ageFileSecondes'],
    ['disque_libre_pct', '55.5', 'disqueLibrePct'],
    ['memoire_libre_pct', 'NaN', 'memoireLibrePct'],
    ['certificat_jours', '30', 'certificatJours'],
    ['horloge_unix', '1780000000', 'horlogeUnix'],
    ['tunnel_ok', 1, 'tunnelOk'],
    ['tunnel_ok', 'true', 'tunnelOk'],
    ['ntp_synchro', 0, 'ntpSynchro'],
]);

test('décimaux : un entier attendu avec décimales n’est pas tronqué en silence (-> absent) ; un flottant entier est accepté', function () {
    expect(MesuresSante::depuis(['file_age_s' => 900.9])->ageFileSecondes)->toBeNull()
        ->and(MesuresSante::depuis(['file_age_s' => 900.0])->ageFileSecondes)->toBe(900)
        ->and(MesuresSante::depuis(['certificat_jours' => 6.5])->certificatJours)->toBeNull()
        ->and(MesuresSante::depuis(['disque_libre_pct' => 55])->disqueLibrePct)->toBe(55.0)
        ->and(MesuresSante::depuis(['disque_libre_pct' => 9.99])->disqueLibrePct)->toBe(9.99);
});

test('bornes vérifiées AVANT le cast (int) : 1e30, INF, NAN et valeurs hors plage => absentes', function (string $cle, mixed $valeur, string $champ) {
    expect(MesuresSante::depuis([$cle => $valeur])->{$champ})->toBeNull();
})->with([
    ['file_age_s', 1e30, 'ageFileSecondes'],
    ['file_age_s', -1, 'ageFileSecondes'],
    ['file_age_s', INF, 'ageFileSecondes'],
    ['file_age_s', NAN, 'ageFileSecondes'],
    ['file_age_s', PHP_INT_MAX, 'ageFileSecondes'],
    ['horloge_unix', 1e30, 'horlogeUnix'],
    ['horloge_unix', -5, 'horlogeUnix'],
    ['certificat_jours', 1e12, 'certificatJours'],
    ['disque_libre_pct', 100.1, 'disqueLibrePct'],
    ['disque_libre_pct', -0.1, 'disqueLibrePct'],
    ['memoire_libre_pct', INF, 'memoireLibrePct'],
    ['memoire_libre_pct', 1e9, 'memoireLibrePct'],
]);

test('valeurs limites valides : 0 et 100 %, certificat expiré (négatif)', function () {
    $m = MesuresSante::depuis(['file_age_s' => 0, 'disque_libre_pct' => 0, 'memoire_libre_pct' => 100, 'certificat_jours' => -3, 'horloge_unix' => 0]);
    expect($m->ageFileSecondes)->toBe(0)->and($m->disqueLibrePct)->toBe(0.0)->and($m->memoireLibrePct)->toBe(100.0)->and($m->certificatJours)->toBe(-3)->and($m->horlogeUnix)->toBe(0);
});

test('estVide : aucune mesure lisible', function () {
    expect(MesuresSante::depuis([])->estVide())->toBeTrue()
        ->and(MesuresSante::depuis(['file_age_s' => 'x'])->estVide())->toBeTrue()
        ->and(MesuresSante::depuis(['tunnel_ok' => false])->estVide())->toBeFalse();
});
