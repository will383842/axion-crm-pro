<?php

/**
 * LES RÈGLES DU CLASSEMENT DES MÉDIAS (chantier F, 2026-10-01) — sans base ni
 * réseau : `ClassementMedia::classer`, `LecturePageAccueil::robotsAutorise`,
 * `LecturePageAccueil::extraire`. Chaque test rougit sans la règle qu'il nomme.
 */

use App\Crm\Etiquettes\FamillesEtiquettes;
use App\Crm\Presse\ClassementMedia;
use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Taxonomy;

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
    $c = cmrClasser(['titre' => 'ZZ Batiment — la revue professionnelle du BTP', 'menu' => 'Chantiers . Management . Région']);

    expect($c['themes'])->toContain('metiers-secteurs', 'rh-management')
        ->and($c['secteurs'])->toBe(['btp'])
        ->and($c['publics'])->toContain('pros-secteur');
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
    $structure = cmrClasser(['texte' => 'Article.'], incertain: true, structure: ['articles' => 5, 'dates' => 5]);

    expect($media['verdict'])->toBe('semble-media')
        ->and($pas['verdict'])->toBe('semble-pas-media')
        ->and($mixte['verdict'])->toBe('a-verifier')
        ->and($structure['verdict'])->toBe('semble-media')
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

test('base du site : schema, hote, et rien d inlisible', function () {
    expect(LecturePageAccueil::base('exemple.test/a/b'))->toBe('https://exemple.test')
        ->and(LecturePageAccueil::base('http://WWW.Exemple.test/'))->toBe('http://www.exemple.test')
        ->and(LecturePageAccueil::base('ftp://exemple.test'))->toBeNull()
        ->and(LecturePageAccueil::base(''))->toBeNull()
        ->and(LecturePageAccueil::base('pas un site'))->toBeNull();
});

test('toute valeur du classement a sa famille gouvernee et sa categorie', function () {
    $slugs = [];
    foreach (array_keys(Taxonomy::MEDIA_THEMES_CLASSES) as $v) {
        $slugs[] = 'media-theme:' . $v;
    }
    foreach (array_keys(ClassementMedia::SECTEURS_MOTS) as $v) {
        expect(Taxonomy::SECTEURS)->toHaveKey($v);
        $slugs[] = 'media-theme:' . ClassementMedia::PREFIXE_SECTEUR . str_replace('_', '-', $v);
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
