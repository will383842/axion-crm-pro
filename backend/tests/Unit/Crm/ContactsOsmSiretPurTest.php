<?php

/**
 * `ContactsOsmSiret` — partie PURE (sans base) : lecture du CSV, SIRET exact, e-mail générique seulement,
 * SIRET porté par deux objets OSM = ambigu. Données FICTIVES (ZZ).
 */

use App\Crm\Entreprises\ContactsOsmSiret;

/** @param list<array<string, string>> $lignes */
function csvSiret(array $lignes): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zzsiret');
    $f = fopen($chemin, 'w');
    $defauts = ['siret' => '', 'telephone' => '', 'email' => '', 'site' => ''];
    fputcsv($f, array_keys($defauts), ',', '"', '');
    foreach ($lignes as $l) {
        fputcsv($f, array_values(array_merge($defauts, $l)), ',', '"', '');
    }
    fclose($f);

    return $chemin;
}

it('n\'accepte qu\'un SIRET de 14 chiffres exacts (espaces retirés)', function () {
    expect(ContactsOsmSiret::normaliserSiret('940 000 000 00012'))->toBe('94000000000012')
        ->and(ContactsOsmSiret::normaliserSiret('9400000000001'))->toBeNull()
        ->and(ContactsOsmSiret::normaliserSiret('940000000000123'))->toBeNull()
        ->and(ContactsOsmSiret::normaliserSiret('FR94000000000012'))->toBeNull()
        ->and(ContactsOsmSiret::normaliserSiret(''))->toBeNull();
});

it('ignore les lignes sans SIRET valide et garde seulement l\'e-mail générique', function () {
    $csv = csvSiret([
        ['siret' => '94000000000012', 'email' => 'contact@zz-entreprise.test', 'telephone' => '01 40 00 00 01'],
        ['siret' => '94000000000020', 'email' => 'jean.dupont@zz-entreprise.test'],
        ['siret' => 'pas-un-siret', 'email' => 'contact@zz-autre.test'],
    ]);

    [$points, $bilan] = ContactsOsmSiret::preparer($csv);

    expect($points)->toHaveKeys(['94000000000012', '94000000000020'])->not->toHaveKey('pas-un-siret');
    expect($points['94000000000012']['email'])->toBe('contact@zz-entreprise.test');
    expect($points['94000000000020']['email'])->toBe('');
    expect($bilan['points'])->toBe(3)->and($bilan['siret_invalides'])->toBe(1)->and($bilan['emails_nominatifs_ignores'])->toBe(1);
    unlink($csv);
});

it('met de côté un SIRET porté par deux objets OSM (ambigu, rien n\'est retenu)', function () {
    $csv = csvSiret([
        ['siret' => '94000000000012', 'email' => 'contact@zz-a.test'],
        ['siret' => '94000000000012', 'email' => 'contact@zz-b.test'],
        ['siret' => '94000000000020', 'email' => 'contact@zz-c.test'],
    ]);

    [$points, $bilan] = ContactsOsmSiret::preparer($csv);

    expect($points)->toHaveKey('94000000000020')->not->toHaveKey('94000000000012');
    expect($bilan['siret_ambigus'])->toBe(1);
    unlink($csv);
});

it('garde le site comme simple candidat et écarte réseaux sociaux et annuaires', function () {
    $csv = csvSiret([
        ['siret' => '94000000000012', 'site' => 'www.zz-entreprise.test'],
        ['siret' => '94000000000020', 'site' => 'https://www.facebook.com/zz'],
    ]);

    [$points] = ContactsOsmSiret::preparer($csv);

    expect($points['94000000000012']['site'])->toBe('http://www.zz-entreprise.test');
    expect($points['94000000000020']['site'])->toBe('');
    unlink($csv);
});

it('refuse un CSV sans la colonne siret', function () {
    $chemin = tempnam(sys_get_temp_dir(), 'zzsiret');
    file_put_contents($chemin, "telephone,email,site\n01,a@b.test,\n");

    expect(fn () => ContactsOsmSiret::preparer($chemin))->toThrow(InvalidArgumentException::class);
    unlink($chemin);
});
