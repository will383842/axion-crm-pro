<?php

use App\Envoi\Sante\Constat;
use App\Envoi\Sante\EvaluateurSante;
use App\Envoi\Sante\MesuresSante;

const T_NOW = '2026-11-13 10:00:00';

function seuilsSante(): array
{
    return [
        'file_age_s' => ['attention' => 900, 'critique' => 3600],
        'disque_libre_pct' => ['attention' => 20, 'critique' => 10],
        'memoire_libre_pct' => ['attention' => 15, 'critique' => 7],
        'certificat_jours' => ['attention' => 30, 'critique' => 7],
        'horloge_ecart_s' => ['attention' => 60, 'critique' => 300],
    ];
}

/** Mesures saines, avec écrasement d'un champ. */
function mesuresSaines(array $surcharge = []): MesuresSante
{
    $now = (new DateTimeImmutable(T_NOW, new DateTimeZone('UTC')))->getTimestamp();

    return MesuresSante::depuis($surcharge + [
        'file_age_s' => 10, 'disque_libre_pct' => 60.0, 'memoire_libre_pct' => 50.0, 'certificat_jours' => 80,
        'tunnel_ok' => true, 'ntp_synchro' => true, 'horloge_unix' => $now,
    ]);
}

function constatsDe(MesuresSante $m): array
{
    $out = [];
    foreach (EvaluateurSante::evaluer($m, new DateTimeImmutable(T_NOW, new DateTimeZone('UTC')), seuilsSante()) as $c) {
        $out[$c->code] = $c->gravite;
    }

    return $out;
}

test('des mesures saines ne donnent aucun constat', function () {
    expect(constatsDe(mesuresSaines()))->toBe([]);
});

test('chaque seuil chiffré franchi donne le bon constat et la bonne gravité', function (array $surcharge, string $code, string $gravite) {
    expect(constatsDe(mesuresSaines($surcharge)))->toBe([$code => $gravite]);
})->with([
    'file 901 s' => [['file_age_s' => 901], 'file_age', Constat::ATTENTION],
    'file 3601 s' => [['file_age_s' => 3601], 'file_age', Constat::CRITIQUE],
    'disque 19 %' => [['disque_libre_pct' => 19.0], 'disque', Constat::ATTENTION],
    'disque 9 %' => [['disque_libre_pct' => 9.0], 'disque', Constat::CRITIQUE],
    'mémoire 14 %' => [['memoire_libre_pct' => 14.0], 'memoire', Constat::ATTENTION],
    'mémoire 6 %' => [['memoire_libre_pct' => 6.0], 'memoire', Constat::CRITIQUE],
    'certificat 30 j' => [['certificat_jours' => 30], 'certificat', Constat::ATTENTION],
    'certificat 7 j' => [['certificat_jours' => 7], 'certificat', Constat::CRITIQUE],
    'tunnel coupé' => [['tunnel_ok' => false], 'tunnel', Constat::ATTENTION],
    'NTP non synchronisé' => [['ntp_synchro' => false], 'ntp', Constat::ATTENTION],
]);

test('juste en deçà du seuil : rien', function (array $surcharge) {
    expect(constatsDe(mesuresSaines($surcharge)))->toBe([]);
})->with([
    [['file_age_s' => 900]],
    [['disque_libre_pct' => 20.0]],
    [['memoire_libre_pct' => 15.0]],
    [['certificat_jours' => 31]],
]);

test('écart d’horloge > 5 min = critique ; 5 min pile = attention seulement', function () {
    $now = (new DateTimeImmutable(T_NOW, new DateTimeZone('UTC')))->getTimestamp();
    expect(constatsDe(mesuresSaines(['horloge_unix' => $now + 301])))->toBe(['horloge' => Constat::CRITIQUE]);
    expect(constatsDe(mesuresSaines(['horloge_unix' => $now - 301])))->toBe(['horloge' => Constat::CRITIQUE]);
    expect(constatsDe(mesuresSaines(['horloge_unix' => $now + 300])))->toBe(['horloge' => Constat::ATTENTION]);
    expect(constatsDe(mesuresSaines(['horloge_unix' => $now + 30])))->toBe([]);
});

test('une mesure absente est signalée (jamais vert par défaut)', function () {
    $m = MesuresSante::depuis(['file_age_s' => 10]);
    $codes = array_keys(constatsDe($m));
    expect($codes)->toContain('mesure_absente_disque', 'mesure_absente_memoire', 'mesure_absente_certificat', 'mesure_absente_horloge');
});
