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
        ->toBe(['ok' => false, 'motif' => SiteMedia::MOTIF_SANS_MOT, 'n' => 0]);
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

// ── Relecture A09 de #273 ──────────────────────────────────────────────────

/** Une page lue (forme rendue par `LecturePageAccueil::lire`). */
function smrLu(string $identite, string $texte = '', int $code = 200, ?string $finale = null): array
{
    return [
        'statut' => 'site',
        'zones' => ['titre' => $identite, 'menu' => '', 'texte' => $texte, 'identite' => $identite],
        'structure' => ['articles' => 0, 'dates' => 0],
        'code' => $code,
    ] + ($finale === null ? [] : ['finale' => $finale]);
}

test('une page qui RECOPIE son adresse ne prouve rien : euronews.fr, le-progres.fr, progres.fr, eco-ain.fr', function () {
    expect(SiteMedia::correspond(['EURONEWS'], 'euronews.fr', 'https://euronews.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['EURONEWS'], 'www.euronews.fr', 'https://euronews.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['LE PROGRES'], 'le-progres.fr — domaine à vendre', 'https://le-progres.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['LE PROGRES'], 'le-progres', 'https://le-progres.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['LE PROGRES'], 'progres.fr is for sale', 'https://progres.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(["L'ECO DE L'AIN"], 'eco-ain.fr', 'https://eco-ain.fr/')['ok'])->toBeFalse()
        // l'hôte d'ARRIVÉE est retiré aussi
        ->and(SiteMedia::correspond(['ZORGLUB'], 'zorglub.test', 'https://zorglub.fr/', ['zorglub.test'])['ok'])->toBeFalse()
        // témoin : le vrai titre passe
        ->and(SiteMedia::correspond(['EURONEWS'], 'Euronews : actualités internationales', 'https://euronews.fr/')['ok'])->toBeTrue();
});

test('jugement : seule une reponse 2xx est jugee — une 404 personnalisee reprenant le nom est injoignable', function () {
    $page = 'Euronews — la page demandée est introuvable';
    expect(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', smrLu($page, 'actualités', 404))[0])->toBe(SiteMedia::INJOIGNABLE)
        ->and(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', smrLu($page, 'actualités', 500))[0])->toBe(SiteMedia::INJOIGNABLE)
        ->and(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', smrLu('Euronews', 'actualités', 200))[0])->toBe(SiteMedia::VERIFIE);
});

test('jugement : arrivee sur un AUTRE domaine ou chez un parkeur = non conforme ; meme domaine (www, sous-domaine) accepte', function () {
    $lu = fn (?string $finale) => smrLu('Euronews', 'actualités', 200, $finale);
    expect(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', $lu('https://autre-media.test/')))
        ->toBe([SiteMedia::NON_CONFORME, 'https://euronews.test/', SiteMedia::MOTIF_REDIRECTION])
        ->and(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', $lu('https://sedoparking.com/euronews.test'))[2])->toBe(SiteMedia::MOTIF_PARKING)
        ->and(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', $lu('https://www.dan.com/buy-domain/euronews.test'))[2])->toBe(SiteMedia::MOTIF_PARKING)
        ->and(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', $lu('https://www.euronews.test/fr/'))[0])->toBe(SiteMedia::VERIFIE)
        ->and(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', $lu('https://fr.euronews.test/'))[0])->toBe(SiteMedia::VERIFIE)
        ->and(SiteMedia::domaineEnregistrable('www.edition.zz.co.uk'))->toBe('zz.co.uk');
});

test('jugement : une page de PARKING est non conforme, meme si elle porte le nom', function () {
    foreach ([
        smrLu('Euronews', 'This domain is for sale. Buy this domain.'),
        smrLu('Euronews — domaine à vendre', 'actualités'),
        smrLu('Euronews', 'Ce nom de domaine est à vendre sur Sedo'),
        smrLu('Euronews for sale', 'actualités'),
    ] as $lu) {
        expect(SiteMedia::juger(['EURONEWS'], 'https://euronews.test/', $lu))
            ->toBe([SiteMedia::NON_CONFORME, 'https://euronews.test/', SiteMedia::MOTIF_PARKING]);
    }
});

test('un seul mot distinctif (LA MONTAGNE) : sans indice de media, a-confirmer (non fiable) ; avec, verifie', function () {
    $station = SiteMedia::juger(['LA MONTAGNE'], 'https://lamontagne.test/', smrLu('La Montagne — station de ski', 'Forfaits, pistes, hébergements'));
    $media = SiteMedia::juger(['LA MONTAGNE'], 'https://lamontagne.test/', smrLu('La Montagne', 'Toute l\'actualité, la rédaction, abonnement'));
    expect($station[0])->toBe(SiteMedia::A_CONFIRMER)
        ->and(in_array(SiteMedia::A_CONFIRMER, SiteMedia::STATUTS_VERIFIES, true))->toBeFalse()
        ->and(SiteMedia::urlVerifiee(['statut' => SiteMedia::A_CONFIRMER, 'url' => 'https://lamontagne.test/']))->toBeNull()
        ->and($media[0])->toBe(SiteMedia::VERIFIE)
        // deux mots distinctifs : l'indice n'est pas exigé
        ->and(SiteMedia::juger(['ZZ ALPHA BETA'], 'https://zz.test/', smrLu('Alpha Beta'))[0])->toBe(SiteMedia::VERIFIE);
});

test('marques : BFM dans BFMTV (et dans l adresse), FRANCE 3 / FRANCE 24 en bloc, candidat m6.fr', function () {
    expect(SiteMedia::correspond(['BFM TV'], 'BFMTV — actualités', 'https://www.bfmtv.com/')['ok'])->toBeTrue()
        ->and(SiteMedia::correspond(['BFM TV'], 'BFMTV', 'https://autre-site.fr/')['ok'])->toBeFalse() // pas dans l'adresse
        ->and(SiteMedia::motsDistinctifs('FRANCE 3'))->toBe(['france 3'])
        ->and(SiteMedia::motsDistinctifs('FRANCE 3 ALSACE'))->toBe(['france 3', 'alsace'])
        ->and(SiteMedia::correspond(['FRANCE 24'], 'France 24 - Actualités internationales', 'https://www.france24.com/')['ok'])->toBeTrue()
        ->and(SiteMedia::correspond(['FRANCE 24'], 'France 2 — programmes', 'https://www.france24.com/')['ok'])->toBeFalse()
        ->and(SiteMedia::candidats(['M6'], 4))->toBe(['https://m6.fr/', 'https://m6.com/']);
});

test('robots.txt aux fins de ligne CR SEULES : les Disallow sont lus (regression)', function () {
    $agent = LecturePageAccueil::AGENT_ROBOTS;
    expect(LecturePageAccueil::robotsAutorise("User-agent: *\rDisallow: /\r", $agent, '/')['autorise'])->toBeFalse()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: *\r\nDisallow: /prive/\r\n", $agent, '/prive/x')['autorise'])->toBeFalse()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: *\nDisallow: /\n", $agent, '/')['autorise'])->toBeFalse();
});
