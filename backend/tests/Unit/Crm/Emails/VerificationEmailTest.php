<?php

/**
 * La fiche de vérification d'une adresse : verdict, lecture par la campagne,
 * fusion avec ce que l'import des fédérations avait écrit, et le
 * `email_status` d'une personne. Adresses FICTIVES.
 */

use App\Crm\Emails\Dns\ResultatDns;
use App\Crm\Emails\VerificationEmail;
use App\Support\ListeSuppression;

test('le verdict : syntaxe et jetable sans DNS ; le DNS decide du reste ; une panne ne conclut rien', function () {
    $mx = new ResultatDns(ResultatDns::MX, 'mx.zz-exemple.fr');

    expect(VerificationEmail::conclure('pas une adresse', $mx))->toBe(['statut' => 'invalide', 'motif' => 'syntaxe', 'domaine_verifie' => false])
        ->and(VerificationEmail::conclure('zz@yopmail.com', $mx))->toBe(['statut' => 'jetable', 'motif' => 'jetable', 'domaine_verifie' => false])
        ->and(VerificationEmail::conclure('zz@zz-exemple.fr', $mx))->toBe(['statut' => 'valide', 'motif' => 'mx', 'domaine_verifie' => true])
        ->and(VerificationEmail::conclure('zz@zz-exemple.fr', new ResultatDns(ResultatDns::A)))->toBe(['statut' => 'valide', 'motif' => 'a', 'domaine_verifie' => true])
        ->and(VerificationEmail::conclure('zz@zz-exemple.fr', new ResultatDns(ResultatDns::INEXISTANT)))->toBe(['statut' => 'invalide', 'motif' => 'inexistant', 'domaine_verifie' => false])
        ->and(VerificationEmail::conclure('zz@zz-exemple.fr', new ResultatDns(ResultatDns::MX_NUL)))->toBe(['statut' => 'invalide', 'motif' => 'mx_nul', 'domaine_verifie' => false])
        ->and(VerificationEmail::conclure('zz@zz-exemple.fr', new ResultatDns(ResultatDns::INDETERMINE)))->toBeNull()
        ->and(VerificationEmail::conclure('zz@zz-exemple.fr', null))->toBeNull()
        ->and(VerificationEmail::demandeLeDns('zz@yopmail.com'))->toBeFalse()
        ->and(VerificationEmail::demandeLeDns('zz@zz-exemple.fr'))->toBeTrue();
});

test('la campagne ne lit que la verification de CETTE commande, pour CETTE adresse', function () {
    $fiche = ['statut' => 'valide', 'verifie_par' => VerificationEmail::SOURCE, 'empreinte' => ListeSuppression::empreinte('zz@zz-exemple.fr')];

    expect(VerificationEmail::statutDe($fiche, ' ZZ@zz-exemple.fr '))->toBe('valide')
        // L'adresse a changé depuis : la vérification ne vaut plus.
        ->and(VerificationEmail::statutDe($fiche, 'autre@zz-exemple.fr'))->toBeNull()
        // Une « vérification » écrite par autre chose (l'import) n'est pas la nôtre.
        ->and(VerificationEmail::statutDe(['statut' => 'valide', 'source' => 'federations-2026', 'empreinte' => $fiche['empreinte']], 'zz@zz-exemple.fr'))->toBeNull()
        ->and(VerificationEmail::statutDe(array_merge($fiche, ['statut' => 'peut-etre']), 'zz@zz-exemple.fr'))->toBeNull()
        ->and(VerificationEmail::statutDe(null, 'zz@zz-exemple.fr'))->toBeNull();
});

test('la fusion garde la provenance et le type de l import, et marque la verification', function () {
    $import = ['type' => 'nominatif', 'domaine_verifie' => true, 'verifie_le' => '2026-09-01', 'source' => 'federations-2026'];
    $nouvelle = VerificationEmail::fusionner($import, ['type' => 'generique', 'domaine_verifie' => false, 'verifie_le' => '2026-09-30', 'statut' => 'invalide']);

    expect($nouvelle['source'])->toBe('federations-2026')
        ->and($nouvelle['type'])->toBe('nominatif')
        ->and($nouvelle['verifie_par'])->toBe(VerificationEmail::SOURCE)
        ->and($nouvelle['domaine_verifie'])->toBeFalse()
        ->and($nouvelle['verifie_le'])->toBe('2026-09-30')
        ->and(VerificationEmail::change($import, $nouvelle))->toBeTrue()
        ->and(VerificationEmail::change($nouvelle, $nouvelle))->toBeFalse()
        // Sans type connu, celui de la règle.
        ->and(VerificationEmail::fusionner(null, ['type' => 'generique'])['type'])->toBe('generique');
});

test('la date : celle du DNS ; sans DNS, inchangee tant que le verdict ne bouge pas', function () {
    $verdict = ['statut' => 'jetable', 'motif' => 'jetable'];
    $ancienne = ['verifie_par' => VerificationEmail::SOURCE, 'statut' => 'jetable', 'motif' => 'jetable', 'verifie_le' => '2026-09-01'];

    expect(VerificationEmail::date($ancienne, $verdict, '2026-09-20', '2026-09-30'))->toBe('2026-09-20')
        ->and(VerificationEmail::date($ancienne, $verdict, null, '2026-09-30'))->toBe('2026-09-01')
        ->and(VerificationEmail::date($ancienne, ['statut' => 'invalide', 'motif' => 'syntaxe'], null, '2026-09-30'))->toBe('2026-09-30')
        ->and(VerificationEmail::date(null, $verdict, null, '2026-09-30'))->toBe('2026-09-30');
});

test('email_status : un domaine mort l emporte ; un domaine vivant ne ressuscite JAMAIS un rebond dur', function () {
    $email = 'zz@zz-exemple.fr';
    $nous = fn (string $statut): array => ['verifie_par' => VerificationEmail::SOURCE, 'statut' => $statut, 'empreinte' => ListeSuppression::empreinte($email)];

    expect(VerificationEmail::statutContact('valid', 'invalide', null, $email))->toBe('invalid')
        ->and(VerificationEmail::statutContact('catchall', 'jetable', null, $email))->toBe('disposable')
        ->and(VerificationEmail::statutContact(null, 'valide', null, $email))->toBe('valid')
        ->and(VerificationEmail::statutContact('unknown', 'valide', null, $email))->toBe('valid')
        // Un `invalid` venu d'ailleurs (rebond dur, fournisseur) reste.
        ->and(VerificationEmail::statutContact('invalid', 'valide', null, $email))->toBe('invalid')
        ->and(VerificationEmail::statutContact('invalid', 'valide', ['statut' => 'invalide', 'source' => 'autre'], $email))->toBe('invalid')
        ->and(VerificationEmail::statutContact('disposable', 'valide', null, $email))->toBe('disposable')
        // … mais un `invalid` que NOUS avions posé tombe quand le domaine revit.
        ->and(VerificationEmail::statutContact('invalid', 'valide', $nous('invalide'), $email))->toBe('valid')
        ->and(VerificationEmail::statutContact('disposable', 'valide', $nous('jetable'), $email))->toBe('valid')
        // Les statuts plus fins d'un autre outil ne sont pas écrasés par « le domaine reçoit ».
        ->and(VerificationEmail::statutContact('catchall', 'valide', null, $email))->toBe('catchall')
        ->and(VerificationEmail::statutContact('role', 'valide', null, $email))->toBe('role');
});
