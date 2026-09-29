<?php

/**
 * DOUBLONS — les règles PURES du rapprochement (chantier 5).
 *
 * Chaque règle face à son témoin. Fixtures FICTIVES (dépôt public) : domaines
 * en `.example.invalid`, SIREN 94xxxxxxx.
 */

use App\Crm\Doublons\Rapprochement;

/** @return array{siren: ?string, country_code: ?string, foreign_id: ?string, nom: ?string, cp: ?string, domaine: ?string, source: ?string} */
function rapFiche(array $valeurs = []): array
{
    return array_merge([
        'siren' => null, 'country_code' => 'FR', 'foreign_id' => null, 'nom' => 'zz alpha',
        'cp' => '69001', 'domaine' => 'zz-alpha.example.invalid', 'source' => 'insee',
    ], $valeurs);
}

test('le domaine d un site : schéma, www, chemin et casse retirés', function () {
    expect(Rapprochement::domaineSite('https://www.ZZ-Alpha.example.invalid/contact?x=1'))->toBe('zz-alpha.example.invalid')
        ->and(Rapprochement::domaineSite('zz-alpha.example.invalid'))->toBe('zz-alpha.example.invalid')
        ->and(Rapprochement::domaineSite('http://zz-alpha.example.invalid.'))->toBe('zz-alpha.example.invalid')
        ->and(Rapprochement::domaineSite(''))->toBeNull()
        ->and(Rapprochement::domaineSite(null))->toBeNull()
        ->and(Rapprochement::domaineSite('pas un site'))->toBeNull();
});

test('une page de plateforme n est pas un site : deux pages Facebook ne font pas « le même site »', function () {
    expect(Rapprochement::domaineSite('https://www.facebook.com/zz-club-a'))->toBeNull()
        ->and(Rapprochement::domaineSite('https://fr-fr.facebook.com/zz-club-b'))->toBeNull()
        ->and(Rapprochement::domaineSite('https://sites.google.com/view/zz'))->toBeNull()
        ->and(Rapprochement::domaineSite('https://zz-asso.wixsite.com/zz'))->toBeNull()
        // TÉMOIN : un domaine propre qui CONTIENT « facebook » n'est pas une plateforme.
        ->and(Rapprochement::domaineSite('https://zz-facebook.com.example.invalid'))->toBe('zz-facebook.com.example.invalid');
});

test('deux SIREN différents ne forment JAMAIS une paire ; même SIREN ou un seul SIREN, si', function () {
    expect(Rapprochement::pairePossible('940000001', '940000002'))->toBeFalse()
        ->and(Rapprochement::pairePossible('940000001', '940000001'))->toBeTrue()
        ->and(Rapprochement::pairePossible(null, '940000001'))->toBeTrue()
        ->and(Rapprochement::pairePossible('940000001', ' '))->toBeTrue()
        ->and(Rapprochement::pairePossible(null, null))->toBeTrue();
});

test('le motif d une paire : site et code postal, site seul, code postal seul, rien', function () {
    $sans = ['siren' => null, 'cp' => '69001', 'domaine' => 'zz-alpha.example.invalid'];
    $avec = static fn (array $v): array => array_merge(['siren' => '940000001', 'cp' => '69001', 'domaine' => 'zz-alpha.example.invalid'], $v);

    expect(Rapprochement::motifNomIdentique($sans, $avec([])))->toBe(Rapprochement::NOM_CP_SITE)
        ->and(Rapprochement::motifNomIdentique($sans, $avec(['cp' => '38000'])))->toBe(Rapprochement::NOM_SITE)
        ->and(Rapprochement::motifNomIdentique($sans, $avec(['domaine' => null])))->toBe(Rapprochement::NOM_CP)
        ->and(Rapprochement::motifNomIdentique($sans, $avec(['domaine' => 'zz-autre.example.invalid'])))->toBe(Rapprochement::NOM_CP)
        ->and(Rapprochement::motifNomIdentique($sans, $avec(['cp' => '38000', 'domaine' => null])))->toBeNull()
        // Deux fiches sans SIREN : jamais « certaines », toujours à vérifier.
        ->and(Rapprochement::motifNomIdentique($sans, $avec(['siren' => null])))->toBe(Rapprochement::SANS_SIREN_NOM)
        ->and(Rapprochement::motifNomIdentique($sans, $avec(['siren' => null, 'cp' => '38000', 'domaine' => null])))->toBeNull()
        // Deux SIREN différents : aucune paire, même nom, même code postal, même site.
        ->and(Rapprochement::motifNomIdentique(array_merge($sans, ['siren' => '940000009']), $avec([])))->toBeNull();
    // Un code postal absent ne rapproche rien.
    expect(Rapprochement::motifNomIdentique(['siren' => null, 'cp' => null, 'domaine' => null], $avec(['cp' => null, 'domaine' => null])))->toBeNull();
});

test('preuve certaine : fiche de collecte sans SIREN + fiche INSEE, même nom, même code postal, même site', function () {
    $garde = rapFiche(['siren' => '940000001']);
    $absorbee = rapFiche(['source' => 'evenements-pro']);

    expect(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, $garde, $absorbee, true))->toBeTrue()
        // Chaque condition, retirée une à une, fait tomber la preuve.
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, $garde, $absorbee, false))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, rapFiche(['siren' => '940000001', 'source' => 'annuaire-entreprises']), $absorbee, true))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, rapFiche(['siren' => null]), $absorbee, true))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, $garde, rapFiche(['siren' => '940000002', 'source' => 'evenements-pro']), true))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, $garde, rapFiche(['source' => 'evenements-pro', 'nom' => 'zz alpha bis']), true))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, $garde, rapFiche(['source' => 'evenements-pro', 'cp' => '69002']), true))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, $garde, rapFiche(['source' => 'evenements-pro', 'domaine' => null]), true))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, $garde, rapFiche(['source' => 'evenements-pro', 'domaine' => 'zz-autre.example.invalid']), true))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::NOM_CP_SITE, rapFiche(['siren' => '940000001', 'nom' => '']), rapFiche(['source' => 'evenements-pro', 'nom' => '']), true))->toBeFalse();
});

test('preuve certaine : même SIREN, même identifiant ; aucun autre motif n est jamais certain', function () {
    expect(Rapprochement::preuveCertaine(Rapprochement::MEME_SIREN, rapFiche(['siren' => '940000001']), rapFiche(['siren' => '940000001']), false))->toBeTrue()
        ->and(Rapprochement::preuveCertaine(Rapprochement::MEME_SIREN, rapFiche(['siren' => '940000001']), rapFiche(['siren' => '940000002']), false))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::MEME_SIREN, rapFiche(), rapFiche(), false))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::MEME_IDENTIFIANT, rapFiche(['foreign_id' => 'evt:zz']), rapFiche(['foreign_id' => 'evt:zz']), false))->toBeTrue()
        ->and(Rapprochement::preuveCertaine(Rapprochement::MEME_IDENTIFIANT, rapFiche(['foreign_id' => 'evt:zz']), rapFiche(['foreign_id' => 'evt:zz', 'country_code' => 'BE']), false))->toBeFalse()
        ->and(Rapprochement::preuveCertaine(Rapprochement::MEME_IDENTIFIANT, rapFiche(['foreign_id' => 'evt:zz', 'siren' => '940000001']), rapFiche(['foreign_id' => 'evt:zz', 'siren' => '940000002']), false))->toBeFalse();
    foreach ([Rapprochement::NOM_SITE, Rapprochement::NOM_CP, Rapprochement::SANS_SIREN_NOM, 'inconnu'] as $motif) {
        expect(Rapprochement::preuveCertaine($motif, rapFiche(['siren' => '940000001']), rapFiche(['source' => 'evenements-pro']), true))->toBeFalse();
    }
    expect(Rapprochement::MOTIFS_CERTAINS)->toBe([Rapprochement::MEME_SIREN, Rapprochement::MEME_IDENTIFIANT, Rapprochement::NOM_CP_SITE]);
});

test('nature d une adresse partagée : le propriétaire par le domaine, puis la NAF des fiches', function () {
    $email = 'compta@zz-cabinet.example.invalid';
    $proprio = static fn (?string $naf): array => ['naf' => $naf, 'domaine' => 'zz-cabinet.example.invalid'];
    $cliente = static fn (?string $naf): array => ['naf' => $naf, 'domaine' => 'zz-client.example.invalid'];

    expect(Rapprochement::natureAdresse($email, [$proprio('69.20Z'), $cliente('47.11A')]))->toBe(Rapprochement::CABINET_COMPTABLE)
        ->and(Rapprochement::natureAdresse($email, [$proprio('8211Z'), $cliente('47.11A')]))->toBe(Rapprochement::DOMICILIATION)
        ->and(Rapprochement::natureAdresse($email, [$proprio('70.10Z'), $cliente('47.11A')]))->toBe(Rapprochement::GROUPE)
        // Sans propriétaire : la NAF d'une des fiches porteuses.
        ->and(Rapprochement::natureAdresse($email, [$cliente('47.11A'), $cliente('69.20Z')]))->toBe(Rapprochement::CABINET_COMPTABLE)
        ->and(Rapprochement::natureAdresse($email, [$cliente('82.11Z'), $cliente('47.11A')]))->toBe(Rapprochement::DOMICILIATION)
        ->and(Rapprochement::natureAdresse($email, [$cliente('47.11A'), $cliente(null)]))->toBe(Rapprochement::INCONNUE)
        // Un sous-domaine de la fiche propriétaire est à elle.
        ->and(Rapprochement::natureAdresse('compta@mail.zz-cabinet.example.invalid', [$proprio('69.20Z'), $cliente('47.11A')]))->toBe(Rapprochement::CABINET_COMPTABLE);
});

test('une messagerie grand public n a pas de propriétaire par le domaine', function () {
    $gmail = static fn (?string $naf): array => ['naf' => $naf, 'domaine' => 'gmail.com'];
    expect(Rapprochement::natureAdresse('zz.dirigeant@gmail.com', [$gmail('70.10Z'), $gmail('47.11A')]))->toBe(Rapprochement::INCONNUE)
        // TÉMOIN : la NAF parle encore.
        ->and(Rapprochement::natureAdresse('zz.dirigeant@gmail.com', [$gmail('69.20Z'), $gmail('47.11A')]))->toBe(Rapprochement::CABINET_COMPTABLE);
});
