<?php

/**
 * HORODATAGES EN HEURE DE PARIS — vérification qui REMPLACE l’ancien lot S8
 * (plan 28 § 1.3 n° 3, lot R0).
 *
 * Elle FIGE le fuseau de la connexion PostgreSQL : l’application, la session de
 * chaque connexion (par défaut, propriétaire, rôle de production) et le réglage
 * `DB_TIMEZONE` doivent dire « Europe/Paris ». Le décalage de deux heures
 * observé en août (une campagne prévue pour 3 h tournait à 5 h) venait d’une
 * session Postgres qui ne suivait pas `app.timezone` ; `HorodatagesFuseauTest`
 * (déjà dans la suite) mesure l’alignement, ce fichier fige la VALEUR.
 *
 * Le filet constate : si un jour le fuseau doit changer, c’est une décision de
 * Will, écrite dans la PR avec « Filet modifié : pourquoi ».
 */

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const NR_FUSEAU = 'Europe/Paris';

test('le fuseau de l’application est Europe/Paris', function () {
    expect(config('app.timezone'))->toBe(NR_FUSEAU)
        ->and(date_default_timezone_get())->toBe(NR_FUSEAU);
});

test('la session PostgreSQL de la connexion par défaut est en Europe/Paris', function () {
    expect(DB::selectOne('SHOW timezone')->TimeZone)->toBe(NR_FUSEAU);
});

test('chaque connexion nommée (propriétaire, rôle de production) est en Europe/Paris', function () {
    foreach (['pgsql_owner', 'pgsql_app'] as $nom) {
        expect(config("database.connections.{$nom}.timezone"))->toBe(NR_FUSEAU, $nom);
        expect(DB::connection($nom)->selectOne('SHOW timezone')->TimeZone)->toBe(NR_FUSEAU);
        DB::connection($nom)->disconnect();
    }
});

test('un instant d’été et un instant d’hiver gardent le bon décalage de Paris (+02 puis +01)', function () {
    $ete = DB::selectOne("select to_char('2026-07-14 12:00:00+00'::timestamptz, 'HH24:MI TZ OF') as h")->h;
    $hiver = DB::selectOne("select to_char('2026-01-14 12:00:00+00'::timestamptz, 'HH24:MI TZ OF') as h")->h;

    expect($ete)->toStartWith('14:00')->and($ete)->toContain('+02')
        ->and($hiver)->toStartWith('13:00')->and($hiver)->toContain('+01');
});

test('un horodatage écrit par l’application revient au même instant et à la même heure murale', function () {
    $ecrit = CarbonImmutable::parse('2026-10-31 03:00:00', NR_FUSEAU);

    $relu = CarbonImmutable::parse(DB::selectOne('select ?::timestamptz as t', [$ecrit->toIso8601String()])->t);

    expect($relu->getTimestamp())->toBe($ecrit->getTimestamp())
        ->and($relu->setTimezone(NR_FUSEAU)->format('H:i'))->toBe('03:00');
});
