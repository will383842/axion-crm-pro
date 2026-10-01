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

// ── Seconde relecture A09 de #273 ──────────────────────────────────────────

test('un nom qui EST l hote (Ouest-France, Paris-Normandie, France-Antilles) est verifie ; seule l adresse avec extension est retiree', function () {
    expect(SiteMedia::juger(['OUEST FRANCE'], 'https://www.ouest-france.fr/', smrLu("Ouest-France : toute l'actualité en continu"))[0])->toBe(SiteMedia::VERIFIE)
        ->and(SiteMedia::juger(['PARIS NORMANDIE'], 'https://www.paris-normandie.fr/', smrLu('Paris-Normandie : actualités en Normandie'))[0])->toBe(SiteMedia::VERIFIE)
        ->and(SiteMedia::juger(['FRANCE ANTILLES'], 'https://www.france-antilles.fr/', smrLu('France-Antilles Martinique — actualité'))[0])->toBe(SiteMedia::VERIFIE)
        // l'adresse recopiée, elle, ne prouve rien ; le parking est rejeté
        ->and(SiteMedia::correspond(['OUEST FRANCE'], 'ouest-france.fr', 'https://ouest-france.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::correspond(['OUEST FRANCE'], 'www.ouest-france.fr', 'https://ouest-france.fr/')['ok'])->toBeFalse()
        ->and(SiteMedia::juger(['OUEST FRANCE'], 'https://ouest-france.fr/', smrLu('ouest-france.fr — domaine à vendre', 'actualité')))
        ->toBe([SiteMedia::NON_CONFORME, 'https://ouest-france.fr/', SiteMedia::MOTIF_PARKING]);
});

test('parking : les signaux du CORPS d un vrai media (page longue) ne rejettent pas ; ceux d une page courte, si', function () {
    $long = static fn (string $phrase): string => str_repeat('Les vendanges commencent dans la vallée et la rédaction suit les récoltes. ', 20) . $phrase;
    $vignoble = smrLu('Le Vigneron ZZ — actualité du vin', $long('Ce domaine est classé grand cru depuis 1855.'));
    $annonce = smrLu('Le Vigneron ZZ — actualité du vin', $long('Petites annonces : domaine à vendre en Bourgogne.'));
    $tech = smrLu('Le Vigneron ZZ — actualité du vin', $long('GoDaddy rachète un concurrent ; le domaine parked par un fonds.'));
    foreach ([$vignoble, $annonce, $tech] as $lu) {
        expect(SiteMedia::estParking($lu['zones']))->toBeFalse()
            ->and(SiteMedia::juger(['LE VIGNERON ZZ'], 'https://levigneronzz.test/', $lu)[0])->toBe(SiteMedia::VERIFIE);
    }
    // page courte : parking ; et beaucoup d'<article> : jamais un parking
    expect(SiteMedia::estParking(smrLu('Le Vigneron ZZ', 'Ce domaine est à vendre. GoDaddy.')['zones']))->toBeTrue()
        ->and(SiteMedia::estParking(smrLu('Le Vigneron ZZ', 'Ce domaine est à vendre.')['zones'], 5))->toBeFalse()
        // le TITRE, lui, compte toujours
        ->and(SiteMedia::estParking(smrLu('levigneronzz.test is for sale', $long(''))['zones']))->toBeTrue();
});

test('domaine enregistrable : suffixes doubles et hebergeurs partages', function () {
    $cas = [
        'www.edition.leprogres.fr' => 'leprogres.fr',
        'www.a.zz.co.uk' => 'zz.co.uk', 'zz.ac.uk' => 'zz.ac.uk', 'blog.zz.me.uk' => 'zz.me.uk',
        'zz.net.au' => 'zz.net.au', 'www.zz.co.nz' => 'zz.co.nz', 'a.zz.co.za' => 'zz.co.za',
        'zz.tm.fr' => 'zz.tm.fr', 'x.zz.nom.fr' => 'zz.nom.fr', 'www.zz.asso.fr' => 'zz.asso.fr', 'zz.com.fr' => 'zz.com.fr',
        'monjournal.wixsite.com' => 'monjournal.wixsite.com', 'zz.wordpress.com' => 'zz.wordpress.com',
        'www.zz.blogspot.com' => 'zz.blogspot.com', 'zz.blogspot.fr' => 'zz.blogspot.fr',
        'zz.over-blog.com' => 'zz.over-blog.com', 'zz.github.io' => 'zz.github.io', 'zz.netlify.app' => 'zz.netlify.app',
        'zz.webflow.io' => 'zz.webflow.io', 'zz.e-monsite.com' => 'zz.e-monsite.com', 'zz.jimdofree.com' => 'zz.jimdofree.com',
        'zz.weebly.com' => 'zz.weebly.com', 'zz.canalblog.com' => 'zz.canalblog.com', 'zz.hautetfort.com' => 'zz.hautetfort.com',
    ];
    foreach ($cas as $hote => $attendu) {
        expect(SiteMedia::domaineEnregistrable($hote))->toBe($attendu);
    }
    // une redirection d'un site hébergé vers un AUTRE site du même hébergeur est refusée
    expect(SiteMedia::juger(['EURONEWS'], 'https://euronews.wixsite.com/', smrLu('Euronews', 'actualités', 200, 'https://autre.wixsite.com/')))
        ->toBe([SiteMedia::NON_CONFORME, 'https://euronews.wixsite.com/', SiteMedia::MOTIF_REDIRECTION]);
});
