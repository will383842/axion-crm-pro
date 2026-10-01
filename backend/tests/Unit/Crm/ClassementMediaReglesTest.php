<?php

/**
 * LES RÈGLES DU CLASSEMENT DES MÉDIAS (chantier F, 2026-10-01) — sans base ni
 * réseau : `ClassementMedia::classer`, `LecturePageAccueil::robotsAutorise`,
 * `LecturePageAccueil::extraire`. Chaque test rougit sans la règle qu'il nomme.
 */

use App\Crm\Etiquettes\FamillesEtiquettes;
use App\Crm\Presse\ClassementMedia;
use App\Crm\Presse\FluxBorne;
use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Taxonomy;
use GuzzleHttp\Psr7\Response;

/** @param array<string, string> $zones */
function cmrClasser(array $zones, array $types = ['presse_mensuel'], bool $incertain = false, array $structure = [], array $diffusion = []): array
{
    return ClassementMedia::classer($zones, $structure, $types, $diffusion, $incertain, ClassementMedia::LECTURE_SITE);
}

test('un mot est trouve en MOT ENTIER seulement (pluriel s / x compris)', function () {
    $t = ClassementMedia::normaliser('Via les Médias : l’Économie des PME, les journaux.');
    expect(ClassementMedia::contient($t, 'ia'))->toBeFalse()
        ->and(ClassementMedia::contient($t, 'economie'))->toBeTrue()
        ->and(ClassementMedia::contient($t, 'pme'))->toBeTrue()
        ->and(ClassementMedia::contient($t, 'media'))->toBeTrue()
        ->and(ClassementMedia::contient($t, 'eco'))->toBeFalse();
});

test('seuil : un mot fort dans le titre suffit, un mot faible seul non, le corps de page seul jamais', function () {
    expect(cmrClasser(['titre' => 'Économie'])['themes'])->toContain('economie-entreprise')
        ->and(cmrClasser(['titre' => 'Argent'])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser(['texte' => 'Économie, bourse, conjoncture, entreprises, business, finance.'])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser([])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser([])['publics'])->toBe(['inconnu']);
});

test('plusieurs themes, et un secteur precis pour la presse professionnelle', function () {
    $c = cmrClasser(['titre' => 'ZZ Batiment — la revue professionnelle du BTP', 'menu' => 'Management . Ressources humaines']);

    expect($c['themes'])->toContain('metiers-secteurs', 'rh-management')
        ->and($c['secteurs'])->toBe(['btp'])
        ->and($c['publics'])->toContain('pros-secteur');
});

test('un SEUL mot de menu ne classe jamais ; une rubrique de quotidien n en fait pas une presse professionnelle', function () {
    expect(cmrClasser(['menu' => 'Management'])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser(['menu' => 'Économie'])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser(['menu' => 'Management . Ressources humaines'])['themes'])->toBe(['rh-management']);

    $quotidien = cmrClasser(['titre' => 'ZZ Le Quotidien', 'menu' => 'Actualité . Immobilier . Transports . Sport . Météo . Espace pros']);
    expect($quotidien['themes'])->not->toContain('metiers-secteurs')
        ->and($quotidien['secteurs'])->toBe([])
        ->and($quotidien['publics'])->not->toContain('pros-secteur')
        ->and($quotidien['themes'])->toContain('grand-public');
    // Témoin : le même secteur dans le TITRE fait une presse professionnelle.
    expect(cmrClasser(['titre' => 'ZZ Le journal de l immobilier'])['secteurs'])->toBe(['immobilier']);
});

test('regional : par le menu, ou par la zone de diffusion donnee par la source', function () {
    expect(cmrClasser(['menu' => 'Lyon . Grenoble'])['themes'])->toContain('regional')
        ->and(cmrClasser(['texte' => 'Lyon, Marseille, Lille, Nantes.'])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser([], diffusion: ['régional'])['themes'])->toBe(['regional'])
        ->and(cmrClasser([], diffusion: ['national'])['themes'])->toBe(['inconnu']);
});

test('format TV : seulement pour la television, dominant ou inconnu, fiction ecartee', function () {
    $tv = ['tv_emission'];
    expect(cmrClasser(['nom' => 'ZZ Le talk-show du soir, débat'], $tv)['format'])->toBe('talk-show')
        ->and(cmrClasser(['nom' => 'ZZ Le JT de 20h'], $tv)['format'])->toBe('jt-info')
        ->and(cmrClasser(['nom' => 'ZZ High-Tech, le magazine geek'], $tv)['format'])->toBe('tech')
        ->and(cmrClasser(['menu' => 'Séries . Jeux . Info . Débats'], ['tv'])['format'])->toBe('inconnu')
        // Chaîne généraliste : le menu ne fait JAMAIS une fiction (nom ou titre seulement).
        ->and(cmrClasser(['menu' => 'Séries . Films . Jeux . Divertissement . Info'], ['tv'])['format'])->toBe('inconnu')
        ->and(cmrClasser(['titre' => 'ZZ Le grand jeu télévisé'], ['tv_emission'])['format'])->toBe('fiction-jeu')
        ->and(cmrClasser(['nom' => 'ZZ Le talk-show du soir'], ['radio'])['format'])->toBeNull();

    $fiction = cmrClasser(['nom' => 'ZZ PME : la série, saison 2, épisode 4, feuilleton'], $tv);
    expect($fiction['format'])->toBe('fiction-jeu')
        ->and($fiction['themes'])->not->toContain('pme-entrepreneurs')
        ->and($fiction['publics'])->not->toContain('dirigeants');
});

test('verdict : media, pas media, ou a-verifier — et jamais pour une fiche qui n est pas incertaine', function () {
    $media = cmrClasser(['menu' => 'À la une . Abonnez-vous . Rubriques', 'texte' => 'La rédaction.'], incertain: true);
    $pas = cmrClasser(['titre' => 'Agence web', 'menu' => 'Nos services . Devis'], incertain: true);
    $mixte = cmrClasser(['menu' => 'À la une . Devis . Nos services . Abonnement'], incertain: true);
    // La structure SEULE (billets datés d'un blog d'éditeur) ne fait pas un média…
    $structure = cmrClasser(['texte' => 'Bonjour.'], incertain: true, structure: ['articles' => 5, 'dates' => 5]);
    // … il faut au moins un signe lexical de média.
    $structureEtSigne = cmrClasser(['texte' => 'La rédaction.'], incertain: true, structure: ['articles' => 5, 'dates' => 5]);

    expect($media['verdict'])->toBe('semble-media')
        ->and($pas['verdict'])->toBe('semble-pas-media')
        ->and($mixte['verdict'])->toBe('a-verifier')
        ->and($structure['verdict'])->toBe('a-verifier')
        ->and($structureEtSigne['verdict'])->toBe('semble-media')
        ->and(cmrClasser(['titre' => 'Agence web'])['verdict'])->toBeNull()
        ->and(ClassementMedia::classer(['titre' => 'Agence web, devis'], [], [], [], true, ClassementMedia::LECTURE_NOM)['verdict'])->toBe('a-verifier');
});

test('robots.txt : groupe de notre agent, sinon *, correspondance la plus longue, Allow a egalite, Crawl-delay', function () {
    $agent = LecturePageAccueil::AGENT_ROBOTS;
    expect(LecturePageAccueil::robotsAutorise('', $agent, '/')['autorise'])->toBeTrue()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: *\nDisallow: /", $agent, '/')['autorise'])->toBeFalse()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: *\nDisallow:", $agent, '/')['autorise'])->toBeTrue()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: *\nDisallow: /prive/", $agent, '/')['autorise'])->toBeTrue()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: *\nDisallow: /\nAllow: /$", $agent, '/')['autorise'])->toBeTrue()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: AxionCRM\nDisallow: /\n\nUser-agent: *\nAllow: /", $agent, '/')['autorise'])->toBeFalse()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nAllow: /", $agent, '/')['autorise'])->toBeTrue()
        ->and(LecturePageAccueil::robotsAutorise("User-agent: *\nCrawl-delay: 30\n", $agent, '/')['delai'])->toBe(30.0)
        ->and(LecturePageAccueil::robotsAutorise("# commentaire\nUser-agent: *  # tous\nDisallow: /*  # tout\n", $agent, '/')['autorise'])->toBeFalse();
});

test('extraction : titre, meta, h1 en zone titre ; menu et h2 ; paragraphes ; compteurs de structure', function () {
    $html = '<html><head><title>ZZ Titre</title><META NAME="Description" CONTENT="ZZ description"></head><body>'
        . '<header><a>ZZ Lien</a></header><h1>ZZ Un</h1><h2>ZZ Deux</h2><p>ZZ paragraphe du 3 mars 2026</p>'
        . '<script>var secret = "ne pas lire";</script>'
        . str_repeat('<article><time>1/2/2026</time></article>', 2) . '</body></html>';

    $l = LecturePageAccueil::extraire($html);

    expect($l['zones']['titre'])->toContain('ZZ Titre', 'ZZ description', 'ZZ Un')
        ->and($l['zones']['menu'])->toContain('ZZ Lien', 'ZZ Deux')
        ->and($l['zones']['texte'])->toContain('ZZ paragraphe')
        ->and(implode(' ', $l['zones']))->not->toContain('ne pas lire')
        ->and($l['structure'])->toBe(['articles' => 2, 'dates' => 2]);
});

test('URL lue : chemin GARDE, fragment retire, ports 80/443 seulement', function () {
    expect(LecturePageAccueil::cible('france.tv/france-5/c-dans-l-air/'))->toBe('https://france.tv/france-5/c-dans-l-air/')
        ->and(LecturePageAccueil::cible('http://WWW.Exemple.test'))->toBe('http://www.exemple.test/')
        ->and(LecturePageAccueil::cible('https://actu.test/lyon?p=1#haut'))->toBe('https://actu.test/lyon?p=1')
        ->and(LecturePageAccueil::cible('https://exemple.test:8080/'))->toBeNull()
        ->and(LecturePageAccueil::cible('ftp://exemple.test'))->toBeNull()
        ->and(LecturePageAccueil::cible(''))->toBeNull()
        ->and(LecturePageAccueil::cible('pas un site'))->toBeNull()
        ->and(LecturePageAccueil::origine('https://actu.test/lyon?p=1'))->toBe('https://actu.test')
        ->and(LecturePageAccueil::cheminRobots('https://actu.test/lyon?p=1'))->toBe('/lyon?p=1');
});

test('taille bornee : flux plafonne PENDANT l ecriture, decompression plafonnee, encodage inconnu refuse', function () {
    [$flux, $id] = FluxBorne::ouvrir(10);
    expect(fwrite($flux, '12345'))->toBe(5)
        ->and(fwrite($flux, 'abcdefgh'))->toBeLessThan(8) // curl voit l'écart et coupe le transfert
        ->and(FluxBorne::depasse($id))->toBeTrue();
    rewind($flux);
    expect(stream_get_contents($flux))->toBe('12345');
    FluxBorne::liberer($id);

    $bombe = (string) gzencode(str_repeat(' ', 20_000_000), 9);
    expect(LecturePageAccueil::decompresser($bombe, ZLIB_ENCODING_GZIP, LecturePageAccueil::CORPS_MAX))->toBeNull()
        ->and(LecturePageAccueil::decompresser((string) gzencode('<p>ok</p>'), ZLIB_ENCODING_GZIP, 100))->toBe('<p>ok</p>')
        ->and(LecturePageAccueil::corps(new Response(200, ['Content-Encoding' => 'br'], 'x'), 100))->toBeNull()
        ->and(LecturePageAccueil::corps(new Response(200, [], str_repeat('a', 101)), 100))->toBeNull()
        ->and(LecturePageAccueil::corps(new Response(200, ['Content-Encoding' => 'deflate'], (string) gzcompress('<p>d</p>')), 100))->toBe('<p>d</p>');

    expect(LecturePageAccueil::entetesAcceptables(new Response(200, ['Content-Type' => 'application/pdf']), 100, true))->toBeFalse()
        ->and(LecturePageAccueil::entetesAcceptables(new Response(200, ['Content-Length' => '101', 'Content-Type' => 'text/html']), 100, true))->toBeFalse()
        ->and(LecturePageAccueil::entetesAcceptables(new Response(200, ['Content-Type' => 'text/html; charset=utf-8']), 100, true))->toBeTrue()
        ->and(LecturePageAccueil::entetesAcceptables(new Response(301, ['Location' => '/x']), 100, true))->toBeTrue();
});

test('toute valeur du classement a sa famille gouvernee et sa categorie', function () {
    $slugs = [];
    foreach (array_keys(Taxonomy::MEDIA_THEMES_CLASSES) as $v) {
        $slugs[] = 'media-sujet:' . $v;
    }
    foreach (array_keys(ClassementMedia::SECTEURS_MOTS) as $v) {
        expect(Taxonomy::SECTEURS)->toHaveKey($v);
        $slugs[] = 'media-sujet:' . ClassementMedia::PREFIXE_SECTEUR . str_replace('_', '-', $v);
    }
    foreach (array_keys(Taxonomy::MEDIA_PUBLICS) as $v) {
        $slugs[] = 'media-public:' . $v;
    }
    foreach (array_keys(Taxonomy::MEDIA_FORMATS) as $v) {
        $slugs[] = 'media-format:' . $v;
    }
    foreach (array_keys(ClassementMedia::VERDICTS) as $v) {
        $slugs[] = 'media-possible:' . $v;
    }
    foreach ($slugs as $slug) {
        expect(FamillesEtiquettes::famille($slug, 'auto')['type'])->toBe(FamillesEtiquettes::TYPE_GOUVERNEE)
            ->and(FamillesEtiquettes::categorieAttendue($slug, 'auto'))->toBe('custom');
    }
    // Les valeurs que `classer` peut rendre sont toutes au référentiel.
    expect(array_diff(array_merge(array_keys(ClassementMedia::THEMES_MOTS), ['metiers-secteurs', 'inconnu']), array_keys(Taxonomy::MEDIA_THEMES_CLASSES)))->toBe([])
        ->and(array_diff(array_merge(array_keys(ClassementMedia::PUBLICS_MOTS), ['inconnu']), array_keys(Taxonomy::MEDIA_PUBLICS)))->toBe([])
        ->and(array_diff(array_merge(array_keys(ClassementMedia::FORMATS_MOTS), ['inconnu']), array_keys(Taxonomy::MEDIA_FORMATS)))->toBe([]);
});

// ── Règles v3 (échantillon de production du 2026-10-01) ────────────────────

test('v4 — DOMINANCE sur les scores reels : LE DAUPHINE LIBERE → regional + grand public, public grand-public', function () {
    // Thèmes retenus par la v3 en production, et leurs scores réels.
    $retenus = ['economie-entreprise' => 22, 'rh-management' => 9, 'regional' => 63, 'grand-public' => 74];
    $gardes = ClassementMedia::dominants($retenus);

    expect($gardes)->toBe(['regional', 'grand-public'])
        ->and(ClassementMedia::publicsDeduits(array_intersect_key($retenus, array_flip($gardes)), ['dirigeants' => 6, 'pros-secteur' => 0, 'grand-public' => 2], 74))
        ->toBe(['grand-public']);
});

test('v4 — DOMINANCE sur les scores reels : ECO DE L AIN → economie, PME, regional, public dirigeants', function () {
    $retenus = ['economie-entreprise' => 37, 'pme-entrepreneurs' => 24, 'regional' => 26, 'grand-public' => 12, 'metiers-secteurs' => 2];
    $gardes = ClassementMedia::dominants($retenus, ['economie-entreprise' => true]);

    expect($gardes)->toBe(['economie-entreprise', 'pme-entrepreneurs', 'regional'])
        ->and(ClassementMedia::publicsDeduits(array_intersect_key($retenus, array_flip($gardes)), ['dirigeants' => 9, 'pros-secteur' => 4, 'grand-public' => 2], 12))
        ->toBe(['dirigeants']);
    // Dans le NOM, un thème faible reste (exception à la dominance).
    expect(ClassementMedia::dominants(['grand-public' => 74, 'economie-entreprise' => 10], ['economie-entreprise' => true]))
        ->toBe(['grand-public', 'economie-entreprise']);
});

test('v4 — pages simulees : quotidien regional, hebdo eco local, Les Echos, Capital, radio locale, 01net', function () {
    $dauphine = cmrClasser([
        'nom' => 'ZZ LE DAUPHINE LIBERE FICTIF',
        // La méta description d'un généraliste énumère ses rubriques : « économie » y est, sans faire un média éco.
        'titre' => 'ZZ Le Dauphiné Libéré — actualités Isère, Savoie, Drôme : faits divers, sport, météo, économie',
        'menu' => 'Isère . Savoie . Haute-Savoie . Grenoble . Annecy . Faits divers . Sport . Football . Rugby . Météo . People . Cinéma . Économie . Emploi . Management',
        'texte' => 'Les entreprises de la région.',
    ], ['presse_quotidien'], diffusion: ['régional']);
    expect($dauphine['themes'])->toBe(['regional', 'grand-public'])
        ->and($dauphine['publics'])->toBe(['grand-public']);

    $ecoAin = cmrClasser([
        'nom' => "ZZ ECO DE L'AIN FICTIF",
        'titre' => "ZZ L'Éco de l'Ain — l'hebdomadaire économique régional : l'actualité locale des entreprises",
        'menu' => 'Entreprises . PME . Entrepreneurs . Création d entreprise . Emploi . Management . Infos locales . Votre département . Agenda . Sport',
        'texte' => 'Le journal des dirigeants et des chefs d entreprise.',
    ], ['presse_hebdo'], diffusion: ['départemental']);
    expect($ecoAin['themes'])->toEqualCanonicalizing(['economie-entreprise', 'pme-entrepreneurs', 'regional'])
        ->and($ecoAin['publics'])->toBe(['dirigeants']);

    $echos = cmrClasser([
        'nom' => 'ZZ LES ECHOS FICTIFS',
        'titre' => 'ZZ Les Echos — actualité économique, financière et boursière',
        'menu' => 'Économie . Finance . Marchés . Bourse . Entreprises . Politique . Monde . Tech . Patrimoine',
    ], ['presse_quotidien']);
    expect($echos['themes'])->toBe(['economie-entreprise'])
        ->and($echos['publics'])->toBe(['dirigeants']);

    $capital = cmrClasser([
        'nom' => 'ZZ CAPITAL FICTIF',
        'titre' => 'ZZ Capital — économie, argent, placements, entreprises et décideurs',
        'menu' => 'Économie . Bourse . Immobilier . Placements . Entreprises . Emploi . Consommation',
    ], ['presse_mensuel']);
    expect($capital['themes'])->toBe(['economie-entreprise'])
        ->and($capital['publics'])->toBe(['dirigeants']);

    $radio = cmrClasser(['nom' => 'ZZ RADIO FICTIVE DU LAC', 'titre' => 'ZZ Radio du Lac', 'menu' => 'Infos locales . Agenda . Podcasts . Musique'], ['radio'], diffusion: ['local']);
    expect($radio['themes'])->toBe(['regional'])
        ->and($radio['publics'])->not->toContain('dirigeants');

    $tech = cmrClasser([
        'nom' => 'ZZ 01NET FICTIF',
        'titre' => 'ZZ 01net — actualité high-tech, tests et bons plans',
        'menu' => 'Tech . Smartphones . Informatique . Jeux vidéo . Intelligence artificielle . Cybersécurité',
    ], ['portail_web']);
    expect($tech['themes'])->toBe(['ia-tech'])
        ->and($tech['publics'])->not->toContain('dirigeants');
});

test('v4 — public : dirigeants jamais si le grand public domine, ni si le theme principal n est pas eco / PME / RH', function () {
    expect(ClassementMedia::publicsDeduits(['economie-entreprise' => 20, 'grand-public' => 30], ['dirigeants' => 9, 'grand-public' => 0], 30))->toBe(['grand-public'])
        ->and(ClassementMedia::publicsDeduits(['ia-tech' => 30, 'economie-entreprise' => 20], ['dirigeants' => 9, 'grand-public' => 0], 0))->toBe([])
        ->and(ClassementMedia::publicsDeduits(['economie-entreprise' => 30], ['dirigeants' => 2, 'grand-public' => 2], 0))->toBe([])
        ->and(ClassementMedia::publicsDeduits(['metiers-secteurs' => 20], ['pros-secteur' => 5, 'grand-public' => 0], 0))->toBe(['pros-secteur'])
        ->and(ClassementMedia::publicsDeduits([], ['dirigeants' => 9], 0))->toBe([]);
});

test('v3 — les deux mots distincts viennent HORS du corps de page', function () {
    expect(cmrClasser(['titre' => 'ZZ Revue fictive', 'menu' => 'Économie . Entreprises'])['themes'])->toBe(['economie-entreprise'])
        ->and(ClassementMedia::analyse(['texte' => ClassementMedia::normaliser('économie bourse')], ClassementMedia::THEMES_MOTS['economie-entreprise'])['mots'])->toBe(0)
        ->and(ClassementMedia::analyse(['menu' => ClassementMedia::normaliser('économie bourse')], ClassementMedia::THEMES_MOTS['economie-entreprise'])['mots'])->toBe(2);
});

test('v3 — ia-tech exige un mot specifique : impression numerique ou informatique ne suffisent pas', function () {
    expect(cmrClasser(['nom' => 'ZZ IMAG IMPRESSION NUMERIQUE'])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser(['nom' => 'ZZ GROUPEMENT DE COMMUNICATION INFORMATIQUE'])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser(['titre' => 'ZZ Le numérique'])['themes'])->toBe(['inconnu'])
        ->and(cmrClasser(['nom' => 'ZZ IA MOTIVATEUR'])['themes'])->toBe(['ia-tech'])
        ->and(cmrClasser(['titre' => 'ZZ Le magazine de l intelligence artificielle'])['themes'])->toContain('ia-tech')
        // Deux mots tech hors corps : retenu.
        ->and(cmrClasser(['menu' => 'Numérique . Cloud . Data'])['themes'])->toBe(['ia-tech']);
});

test('v3 — format TV seulement si TOUTES les lignes media de la fiche sont de la television', function () {
    expect(cmrClasser(['nom' => 'ZZ High-Tech, le magazine geek'], ['tv_emission'])['format'])->toBe('tech')
        ->and(cmrClasser(['nom' => 'ZZ High-Tech, le magazine geek'], ['presse_mensuel', 'tv_emission'])['format'])->toBeNull()
        ->and(cmrClasser(['nom' => 'ZZ High-Tech, le magazine geek'], [])['format'])->toBeNull();
});

test('v3 — media possible : AUCUN theme ni public tant que le verdict n est pas semble-media', function () {
    $pas = cmrClasser(['nom' => 'ZZ IA CONSEIL', 'titre' => 'ZZ IA Conseil — agence web', 'menu' => 'Nos services . Devis . Dirigeants . Professionnels'], ['portail_web'], true);
    $flou = cmrClasser(['titre' => 'ZZ', 'menu' => 'Économie . Entreprises'], ['portail_web'], true);
    $media = cmrClasser(['nom' => 'ZZ IA CONSEIL', 'menu' => 'À la une . Abonnez-vous . Rubriques', 'texte' => 'La rédaction.'], ['portail_web'], true);

    expect($pas['verdict'])->toBe('semble-pas-media')
        ->and([$pas['themes'], $pas['secteurs'], $pas['publics'], $pas['format']])->toBe([[], [], [], null])
        ->and($flou['verdict'])->toBe('a-verifier')
        ->and([$flou['themes'], $flou['publics']])->toBe([[], []]);
    // Un média possible qui SEMBLE un média garde sa ligne éditoriale.
    expect($media['verdict'])->toBe('semble-media')
        ->and($media['themes'])->toContain('ia-tech');
});
