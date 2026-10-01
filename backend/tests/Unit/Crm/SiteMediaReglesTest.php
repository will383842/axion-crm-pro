<?php

/**
 * LA RÈGLE « CE SITE PORTE-T-IL LE NOM DU MÉDIA ? » (2026-10-01) — sans base
 * ni réseau : `SiteMedia::correspond`, `motsDistinctifs`, `candidats`,
 * `estGenerique`, `urlVerifiee`, et la zone `identite` de
 * `LecturePageAccueil::extraire`. Chaque test rougit sans la règle qu'il nomme.
 * Les trois cas de production (paris.fr, bourse.fr, cholet.fr) y sont.
 */

use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\SiteMedia;

test('les cas de production sont REFUSES : PARIS LIVE → paris.fr, BOURSE DU TEXTILE → bourse.fr, CHOLET REPRO SERVICES → cholet.fr', function () {
    expect(SiteMedia::correspond(['PARIS LIVE'], 'Paris.fr — Ville de Paris', 'https://paris.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['BOURSE DU TEXTILE'], 'Bourse : cours et actualités de la Bourse', 'https://bourse.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['CHOLET REPRO SERVICES'], 'Ville de Cholet — site officiel', 'https://cholet.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['CHOLET REPRO SERVICES'], 'Ville de Cholet — site officiel', 'https://cholet.fr/')['motif'])->toBe(SiteMedia::MOTIF_NOM);
});

test('un vrai site est ACCEPTE : nom dans le titre / og:site_name / h1, ou mot unique dans la page ET l adresse', function () {
    expect(SiteMedia::correspond(['LA VOIX DU NORD'], 'La Voix du Nord — actualités', 'https://www.lavoixdunord.fr/')['ok'])->toBeTrue()
        ->and(SiteMedia::correspond(['LE PROGRES SA'], 'Le Progrès : info Lyon', 'https://leprogres.fr/')['ok'])->toBeTrue()
        ->and(SiteMedia::correspond(['ZZ ALPHA BETA GAMMA'], 'Alpha Gamma', 'https://zz.test/')['ok'])->toBeTrue() // 2 sur 3
        ->and(SiteMedia::correspond(['ZZ ALPHA BETA GAMMA'], 'Alpha', 'https://zz.test/')['ok'])->toBeFalse()
        // une seule forme du nom qui passe suffit (dénomination OU nom du titre)
        ->and(SiteMedia::correspond(['SARL ZZ EXPLOITATION', 'LE ZORGLUB'], 'Le Zorglub', 'https://lezorglub.fr/')['ok'])->toBeTrue()
        // l'émission sous le domaine d'une chaîne : le CHEMIN compte comme adresse
        ->and(SiteMedia::correspond(['ZORGLUB'], 'Zorglub — France 5', 'https://chaine.test/france-5/zorglub/')['ok'])->toBeTrue();
});

test('l ADRESSE seule ne prouve rien : un candidat tire du nom doit porter le nom dans SA PAGE', function () {
    expect(SiteMedia::correspond(['LE PROGRES'], 'Ce domaine est à vendre', 'https://leprogres.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['ZORGLUB'], 'Zorglub', 'https://autre-site.fr/')['ok'])->toBeFalse() // mot unique : page ET adresse
        ->and(SiteMedia::correspond(['ZZ ALPHA BETA'], 'Alpha', 'https://zz.test/')['ok'])->toBeFalse();
});

test('mots distinctifs : hors mots vides, formes juridiques et mots generiques ; sans mot distinctif, jamais conforme', function () {
    expect(SiteMedia::motsDistinctifs('SARL LES ÉDITIONS DU ZORGLUB PARIS'))->toBe(['zorglub'])
        ->and(SiteMedia::motsDistinctifs('M6 Publicité'))->toBe(['m6', 'publicite'])
        ->and(SiteMedia::correspond(['EDITIONS PRESSE PARIS'], 'Editions Presse Paris', 'https://editions-presse-paris.fr/'))
        ->toBe(['ok' => false, 'motif' => SiteMedia::MOTIF_SANS_MOT]);
});

test('domaine partage : non conforme d office SAUF si le nom se lit dans le domaine', function () {
    expect(SiteMedia::partageSansCorrespondance(['PARIS LIVE'], 'paris.fr'))->toBeTrue()
        ->and(SiteMedia::partageSansCorrespondance(['LE PROGRES EDITION DE L AIN'], 'leprogres.fr'))->toBeTrue()
        ->and(SiteMedia::partageSansCorrespondance(['LE PROGRES'], 'leprogres.fr'))->toBeFalse();
});

test('liste noire : domaines generiques, plateformes, annuaires, administration', function () {
    foreach (['paris.fr', 'www.paris.fr', 'france.fr', 'media.fr', 'atelier.fr', 'bourse.fr', 'fr.wikipedia.org', 'facebook.com', 'mairie.gouv.fr', 'annuaire-zz.fr'] as $g) {
        expect(SiteMedia::estGenerique($g))->toBeTrue();
    }
    expect(SiteMedia::estGenerique('leprogres.fr'))->toBeFalse()
        ->and(SiteMedia::estGenerique('cholet-repro.fr'))->toBeFalse();
});

test('candidats : tires du nom, .fr puis .com, accoles puis tirets, avec puis sans article ; jamais generique', function () {
    expect(SiteMedia::candidats(['Le Progrès SA'], 6))->toBe([
        'https://leprogres.fr/', 'https://progres.fr/', 'https://le-progres.fr/',
        'https://leprogres.com/', 'https://progres.com/', 'https://le-progres.com/',
    ])
        ->and(SiteMedia::candidats(['Le Progrès'], 2))->toHaveCount(2)
        ->and(SiteMedia::candidats(['PARIS'], 4))->toBe([])
        ->and(SiteMedia::candidats(['MEDIA'], 4))->toBe([]);
});

test('marqueur : seuls verifie et trouve-verifie donnent une URL a lire', function () {
    expect(SiteMedia::urlVerifiee(['statut' => 'verifie', 'url' => 'https://zz.test/a']))->toBe('https://zz.test/a')
        ->and(SiteMedia::urlVerifiee('{"statut":"trouve-verifie","url":"https://zz.test/"}'))->toBe('https://zz.test/')
        ->and(SiteMedia::urlVerifiee(['statut' => 'non-conforme', 'url' => 'https://zz.test/']))->toBeNull()
        ->and(SiteMedia::urlVerifiee(['statut' => 'injoignable', 'url' => 'https://zz.test/']))->toBeNull()
        ->and(SiteMedia::urlVerifiee(null))->toBeNull()
        ->and(SiteMedia::conditionSql('c'))->toBe("((c.metadata -> 'site_media' ->> 'statut') IN ('verifie','trouve-verifie'))");
});

test('zone identite : titre, og:site_name, og:title, h1 — jamais la meta description', function () {
    $l = LecturePageAccueil::extraire('<html><head><title>ZZ Titre</title><meta name="description" content="ZZ description">'
        . '<meta property="og:site_name" content="ZZ Site"></head><body><h1>ZZ Un</h1><p>ZZ texte</p></body></html>');

    expect($l['zones']['identite'])->toContain('ZZ Titre', 'ZZ Site', 'ZZ Un')
        ->and($l['zones']['identite'])->not->toContain('ZZ description')
        ->and($l['zones']['identite'])->not->toContain('ZZ texte');
});
