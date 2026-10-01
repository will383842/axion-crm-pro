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
        'zones' => ['titre' => $identite, 'menu' => '', 'texte' => $texte, 'identite' => $identite, 'corps' => $texte],
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
        // deux mots distinctifs : la preuve de média est exigée aussi (v. relecture A09)
        ->and(SiteMedia::juger(['ZZ ALPHA BETA'], 'https://zz.test/', smrLu('Alpha Beta'))[0])->toBe(SiteMedia::A_CONFIRMER)
        ->and(SiteMedia::juger(['ZZ ALPHA BETA'], 'https://zz.test/', smrLu('Alpha Beta', 'La rédaction'))[0])->toBe(SiteMedia::VERIFIE);
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

// ── Troisième relecture A09 de #273 ────────────────────────────────────────

/** Une page de parking LONGUE : phrases de parkeur + plus de 120 mots de liens sponsorisés. */
function smrParkingLong(string $titre): array
{
    $liens = str_repeat('Related links: Assurance auto, Credit immobilier, Billets avion, Hotel pas cher. ', 15);

    return smrLu($titre, 'This domain may be for sale. Buy this domain on GoDaddy. Sedo parking. ' . $liens);
}

test('parking LONG a etiquette nue (deux mots et un mot) : non conforme — phrases de parkeur lues dans tout le corps', function () {
    expect(SiteMedia::juger(['VOSGES MATIN'], 'https://www.vosges-matin.fr/', smrParkingLong('vosges-matin')))
        ->toBe([SiteMedia::NON_CONFORME, 'https://www.vosges-matin.fr/', SiteMedia::MOTIF_PARKING])
        ->and(SiteMedia::juger(['ZORGLUBIA'], 'https://zorglubia.fr/', smrParkingLong('zorglubia')))
        ->toBe([SiteMedia::NON_CONFORME, 'https://zorglubia.fr/', SiteMedia::MOTIF_PARKING])
        // même avec des <article> : une phrase de parkeur reste un parking
        ->and(SiteMedia::estParking(smrParkingLong('vosges-matin')['zones'], 5))->toBeTrue();
});

test('parking COURT au niveau du jugement : non conforme', function () {
    expect(SiteMedia::juger(['LE VIGNERON ZZ'], 'https://levigneronzz.test/', smrLu('Le Vigneron ZZ', 'Ce domaine est à vendre. GoDaddy.')))
        ->toBe([SiteMedia::NON_CONFORME, 'https://levigneronzz.test/', SiteMedia::MOTIF_PARKING]);
});

test('preuve de media HORS LIENS exigee pour tout verifie : « site en construction », liens sponsorises seuls → a-confirmer', function () {
    $construction = SiteMedia::juger(['VOSGES MATIN'], 'https://www.vosges-matin.fr/', smrLu('vosges-matin', 'Site en construction'));
    $sponsorises = smrLu('vosges-matin');
    $sponsorises['zones']['menu'] = str_repeat('Actualités Rédaction Abonnement Articles ', 40);
    $sponsorises['zones']['texte'] = str_repeat('Actualités sponsorisées ', 40); // texte des liens, absent de `corps`
    expect($construction[0])->toBe(SiteMedia::A_CONFIRMER)
        ->and(SiteMedia::juger(['VOSGES MATIN'], 'https://www.vosges-matin.fr/', $sponsorises)[0])->toBe(SiteMedia::A_CONFIRMER)
        // la structure suffit : 3 <article> ou 3 dates
        ->and(SiteMedia::preuveMedia(['identite' => 'Vosges Matin'], ['articles' => 3, 'dates' => 0, 'articles_texte' => 3, 'dates_texte' => 0]))->toBeTrue()
        ->and(SiteMedia::preuveMedia(['identite' => 'Vosges Matin'], ['articles' => 0, 'dates' => 3, 'articles_texte' => 0, 'dates_texte' => 3]))->toBeTrue()
        ->and(SiteMedia::preuveMedia(['identite' => 'Vosges Matin'], ['articles' => 9, 'dates' => 9, 'articles_texte' => 2, 'dates_texte' => 2]))->toBeFalse();
});

test('temoins : les vrais medias, pages realistes, restent verifies', function () {
    $temoins = [
        [['OUEST FRANCE'], 'https://www.ouest-france.fr/', "Ouest-France : toute l'actualité en continu", 'Bretagne, Normandie, Pays de la Loire.'],
        [['LE PROGRES'], 'https://www.leprogres.fr/', 'Le Progrès : info et actu Lyon, Rhône, Loire', 'Abonnez-vous.'],
        [['EURONEWS'], 'https://fr.euronews.com/', 'Euronews : actualités internationales', 'En direct.'],
        [["L'ECO DE L'AIN"], 'https://www.eco-ain.fr/', "L'Éco de l'Ain — l'actualité économique de l'Ain", 'Entreprises.'],
        [['BFM TV'], 'https://www.bfmtv.com/', 'BFMTV — Actualités en continu', 'Politique, économie, international.'],
    ];
    foreach ($temoins as [$noms, $url, $titre, $texte]) {
        expect(SiteMedia::juger($noms, $url, smrLu($titre, $texte))[0])->toBe(SiteMedia::VERIFIE);
    }
    // et une page sans indice lexical, mais structurée (articles datés), aussi
    $structuree = smrLu('Le Progrès', 'Lyon, Rhône, Loire.');
    $structuree['structure'] = ['articles' => 12, 'dates' => 12, 'articles_texte' => 12, 'dates_texte' => 12];
    expect(SiteMedia::juger(['LE PROGRES'], 'https://www.leprogres.fr/', $structuree)[0])->toBe(SiteMedia::VERIFIE);
});

test('zone corps : texte des paragraphes SANS celui de leurs liens', function () {
    $l = LecturePageAccueil::extraire('<html><head><title>ZZ</title></head><body>'
        . '<p>ZZ texte propre <a href="#">ZZ lien sponsorisé</a> suite</p><p><a href="#">ZZ que des liens</a></p></body></html>');

    expect($l['zones']['corps'])->toContain('ZZ texte propre', 'suite')
        ->and($l['zones']['corps'])->not->toContain('ZZ lien sponsorisé')
        ->and($l['zones']['corps'])->not->toContain('ZZ que des liens')
        ->and($l['zones']['texte'])->toContain('ZZ lien sponsorisé');
});

// ── Quatrième relecture A09 de #273 ────────────────────────────────────────

test('dates : seules celles ecrites HORS LIENS prouvent un media (« Offre 01/10/2026 » en lien ne compte pas)', function () {
    $liens = LecturePageAccueil::extraire('<html><body>'
        . str_repeat('<p><a href="#">Offre 01/10/2026</a></p>', 3)
        . str_repeat('<a href="#"><time>1 octobre 2026</time></a>', 3) . '</body></html>');
    $ecrites = LecturePageAccueil::extraire('<html><body>'
        . str_repeat('<p>Publié le 01/10/2026 par la rédaction</p>', 3) . '</body></html>');

    expect($liens['structure']['dates'])->toBeGreaterThanOrEqual(3) // compteur brut (classement) inchangé
        ->and($liens['structure']['dates_texte'])->toBe(0)
        ->and(SiteMedia::preuveMedia(['identite' => 'ZZ'], $liens['structure']))->toBeFalse()
        ->and($ecrites['structure']['dates_texte'])->toBe(3)
        ->and(SiteMedia::preuveMedia(['identite' => 'ZZ'], $ecrites['structure']))->toBeTrue();
});

test('articles : un <article> ne compte que s il porte du texte HORS liens', function () {
    $vides = LecturePageAccueil::extraire('<html><body>'
        . str_repeat('<article><a href="#">Assurance auto pas chère crédit immobilier billets avion hôtel</a></article>', 3) . '</body></html>');
    $pleins = LecturePageAccueil::extraire('<html><body>'
        . str_repeat('<article><h3>ZZ brève</h3><p>Le conseil municipal a voté hier soir le budget de la ville.</p></article>', 3) . '</body></html>');

    expect($vides['structure']['articles'])->toBe(3)
        ->and($vides['structure']['articles_texte'])->toBe(0)
        ->and(SiteMedia::preuveMedia(['identite' => 'ZZ'], $vides['structure']))->toBeFalse()
        ->and($pleins['structure']['articles_texte'])->toBe(3)
        ->and(SiteMedia::preuveMedia(['identite' => 'ZZ'], $pleins['structure']))->toBeTrue();
});

test('radio : « Radio Zorglub — ecoutez en direct », lecteur sans paragraphe → verifie', function () {
    $lu = ['statut' => 'site', 'code' => 200]
        + LecturePageAccueil::extraire('<html><head><title>Radio Zorglub — écoutez en direct</title></head><body><div id="lecteur"></div></body></html>');

    expect(SiteMedia::juger(['RADIO ZORGLUB'], 'https://www.radiozorglub.test/', $lu)[0])->toBe(SiteMedia::VERIFIE)
        ->and(SiteMedia::indiceMedia(['identite' => 'Chaîne TV — la grille des programmes']))->toBeTrue()
        ->and(SiteMedia::indiceMedia(['identite' => 'Nos forfaits et tarifs']))->toBeFalse();
});

test('apostrophes et elision : « Toute l’actualite » / « l actualite » declenchent l indice', function () {
    expect(SiteMedia::normaliser('Toute l’actualité'))->toBe(' toute l actualite ')
        ->and(SiteMedia::normaliser("L'ACTUALITÉ"))->toBe(' l actualite ')
        ->and(SiteMedia::indiceMedia(['identite' => 'Toute l’actualité de la Zorglubie']))->toBeTrue()
        ->and(SiteMedia::indiceMedia(['identite' => "Toute l'actualité"]))->toBeTrue()
        ->and(SiteMedia::indiceMedia(['identite' => 'Toute l‘actualité']))->toBeTrue()
        ->and(SiteMedia::indiceMedia(['corps' => 'toute l actualite']))->toBeTrue();
});
