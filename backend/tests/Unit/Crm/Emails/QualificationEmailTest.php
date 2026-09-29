<?php

/**
 * Les règles PURES de la vérification des e-mails : syntaxe, domaine,
 * jetable, webmail, type générique / nominatif. Adresses FICTIVES.
 */

use App\Crm\Emails\QualificationEmail;

test('la syntaxe : une adresse ordinaire passe, les formes fautives non', function () {
    expect(QualificationEmail::syntaxeValide('contact@zz-exemple.fr'))->toBeTrue()
        ->and(QualificationEmail::syntaxeValide('  Contact.Lyon+2026@ZZ-Exemple.fr '))->toBeTrue();

    foreach (['', 'pas-une-adresse', 'zz@', '@zz-exemple.fr', 'zz@@zz-exemple.fr', 'zz@zz-exemple..fr',
        'zz@localhost', 'zz@exemple', 'zz @zz-exemple.fr', 'zz@-zz.fr', 'zz@zz-.fr'] as $fautive) {
        expect(QualificationEmail::syntaxeValide($fautive))->toBeFalse("« {$fautive} » passe pour valide");
    }
    // Libellés conformes, mais 258 caractères : au-delà du chemin SMTP (254).
    // TÉMOIN : la même construction à 253 caractères passe — c'est bien la
    // longueur qui la fait refuser.
    $base = str_repeat('a', 64) . '@' . implode('.', array_fill(0, 3, str_repeat('b', 60)));
    expect(strlen($base . '.example.fr'))->toBe(258)
        ->and(QualificationEmail::syntaxeValide($base . '.example.fr'))->toBeFalse()
        ->and(strlen($base . '.zz.fr'))->toBe(253)
        ->and(QualificationEmail::syntaxeValide($base . '.zz.fr'))->toBeTrue();
});

test('le domaine : en minuscules, point final retiré, internationalisé converti en punycode', function () {
    expect(QualificationEmail::domaine('Zz@ZZ-Exemple.FR.'))->toBe('zz-exemple.fr')
        ->and(QualificationEmail::domaine('contact@exemple-été.fr'))->toBe('xn--exemple-t-i4ab.fr')
        ->and(QualificationEmail::syntaxeValide('contact@exemple-été.fr'))->toBeTrue()
        ->and(QualificationEmail::domaine('sans-arobase'))->toBeNull();
});

test('jetable et webmail : les listes du depot, sans copie', function () {
    expect(QualificationEmail::estJetable('yopmail.com'))->toBeTrue()
        ->and(QualificationEmail::estJetable('mailinator.com'))->toBeTrue()
        ->and(QualificationEmail::estJetable('zz-exemple.fr'))->toBeFalse()
        ->and(QualificationEmail::estWebmail('gmail.com'))->toBeTrue()
        ->and(QualificationEmail::estWebmail('hotmail.co.uk'))->toBeTrue()
        ->and(QualificationEmail::estWebmail('orange.fr'))->toBeTrue()
        ->and(QualificationEmail::estWebmail('zz-exemple.fr'))->toBeFalse();
});

test('le type : les boites generiques de l import des federations, et les roles', function () {
    foreach (['contact', 'infos', 'accueil-lyon', 'secretariat.general', 'direction2', 'ud69', 'communication',
        'noreply', 'support', 'presse', 'cr', 'cci.lyon'] as $local) {
        expect(QualificationEmail::type($local . '@zz-exemple.fr'))->toBe('generique', "« {$local}@ » devrait être générique");
    }
});

test('le type : une personne n est jamais rangee en boite parce que son nom COMMENCE par un mot de la liste', function () {
    // La règle d'origine ne regardait que le préfixe : `cr` (conseil régional)
    // rangeait « cristina.… » en générique, `com` rangeait « comte.… ».
    foreach (['cristina.zz', 'comte.zz', 'udo.zz', 'jean.zz', 'zz.dupond', 'infirmier.zz', 'contactez.zz'] as $local) {
        expect(QualificationEmail::type($local . '@zz-exemple.fr'))->toBe('nominatif', "« {$local}@ » devrait être nominatif");
    }
});
