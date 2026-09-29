<?php

/**
 * La fiche de vérification d'une adresse : verdict, lecture par la campagne,
 * fusion avec ce que l'import des fédérations avait écrit, et le
 * `email_status` d'une personne. Adresses FICTIVES.
 */

use App\Crm\Emails\Dns\ResultatDns;
use App\Crm\Emails\VerificationEmail;

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
    $fiche = ['statut' => 'valide', 'verifie_par' => VerificationEmail::SOURCE, 'empreinte' => VerificationEmail::empreinte('zz@zz-exemple.fr')];

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

test('email_status : un domaine mort degrade ; le domaine revenu retablit ce qui etait AVANT, jamais davantage', function () {
    $email = 'zz@zz-exemple.fr';
    // La fiche de vérification telle que la commande l'écrit après un passage.
    $fiche = fn (string $statut, array $issue): array => [
        'verifie_par' => VerificationEmail::SOURCE, 'statut' => $statut, 'empreinte' => VerificationEmail::empreinte($email),
        'email_status_avant' => $issue['avant'], 'email_status_pose' => $issue['pose'],
    ];
    // Un passage : (statut actuel, verdict, fiche d'avant) → (nouveau statut, nouvelle fiche).
    $passage = function (?string $actuel, string $verdict, ?array $ancienne) use ($email, $fiche): array {
        $issue = VerificationEmail::statutContact($actuel, $verdict, $ancienne, $email);

        return [$issue['statut'], $fiche($verdict, $issue)];
    };

    // Vide ou inconnu : `valid`, posé par nous — et défait par nous.
    [$s, $f] = $passage(null, 'valide', null);
    expect($s)->toBe('valid');
    [$s, $f] = $passage($s, 'invalide', $f);
    expect($s)->toBe('invalid');
    [$s] = $passage($s, 'valide', $f);
    expect($s)->toBe('valid');

    // 🔴 E1 — le chemin complet : rebond dur (`invalid` posé par
    // `crm:campagne:retours`), vérification pendant une panne du domaine,
    // domaine revenu. Toujours `invalid`.
    [$s, $f] = $passage('invalid', 'invalide', null);
    expect($s)->toBe('invalid')->and($f['email_status_pose'])->toBeNull();
    [$s] = $passage($s, 'valide', $f);
    expect($s)->toBe('invalid');

    // `catchall` et `role`, dégradés pendant une panne, reviennent TELS QUELS.
    foreach (['catchall', 'role', 'valid'] as $autre) {
        [$s, $f] = $passage($autre, 'invalide', null);
        expect($s)->toBe('invalid')->and($f['email_status_avant'])->toBe($autre);
        [$s, $f] = $passage($s, 'jetable', $f);
        expect($s)->toBe('disposable')->and($f['email_status_avant'])->toBe($autre);
        [$s] = $passage($s, 'valide', $f);
        expect($s)->toBe($autre, "« {$autre} » dégradé puis rétabli");
    }

    // Quelqu'un a changé le statut APRÈS nous (un rebond dur) : il a raison.
    [$s, $f] = $passage('catchall', 'invalide', null);
    [$s] = $passage('invalid', 'valide', array_merge($f, ['email_status_pose' => 'disposable']));
    expect($s)->toBe('invalid');

    // Une fiche de vérification écrite pour une AUTRE adresse ne vaut rien.
    [$s, $f] = $passage('catchall', 'invalide', null);
    expect(VerificationEmail::statutContact('invalid', 'valide', $f, 'autre@zz-exemple.fr')['statut'])->toBe('invalid');

    // 🔴 ORDRE INVERSE (2e relecture) : nous posons `invalid`, PUIS un rebond
    // dur réécrit `invalid` — la même valeur, la fiche ne peut pas le voir.
    // C'est la liste de suppression (`$interdite`) qui retient la réversion.
    [$s, $f] = $passage(null, 'invalide', null);
    expect($s)->toBe('invalid')->and($f['email_status_pose'])->toBe('invalid');
    $retenu = VerificationEmail::statutContact('invalid', 'valide', $f, $email, static fn (): bool => true);
    expect($retenu)->toBe(['statut' => 'invalid', 'avant' => null, 'pose' => null])
        // TÉMOIN : sans rebond ni opposition, le domaine revenu rétablit.
        ->and(VerificationEmail::statutContact('invalid', 'valide', $f, $email, static fn (): bool => false)['statut'])->toBe('valid');
    // La liste n'est interrogée QUE lorsqu'une réversion est en jeu.
    $interrogee = false;
    VerificationEmail::statutContact('catchall', 'valide', null, $email, function () use (&$interrogee): bool {
        $interrogee = true;

        return true;
    });
    expect($interrogee)->toBeFalse();

    // `valide` n'écrase jamais un statut plus fin posé par un autre outil.
    expect(VerificationEmail::statutContact('catchall', 'valide', null, $email)['statut'])->toBe('catchall')
        ->and(VerificationEmail::statutContact('role', 'valide', null, $email)['statut'])->toBe('role')
        ->and(VerificationEmail::statutContact('unknown', 'valide', null, $email)['statut'])->toBe('valid');
});
