<?php

use App\Crm\Guides\LienGuide;

/**
 * Lien du guide (fiche CRM, bloc « Contenus et accords »). Règles PURES : le lien
 * vient UNIQUEMENT de la table fermée `config/guides.php`, jamais d'une donnée.
 * Adresses en `exemple.invalid` seulement (dépôt PUBLIC, aucune vraie URL).
 */
function tableGuides(array $publics = null, bool $actif = true): void
{
    config([
        'guides.lien_enabled' => $actif,
        'guides.publics' => $publics ?? [
            'guide-ia' => ['nom' => 'Guide exemple A', 'url' => 'https://exemple.invalid/guides/guide-ia.pdf', 'libelle' => 'Guide exemple A (PDF)'],
        ],
    ]);
}

test('interrupteur éteint par défaut : aucun lien', function () {
    expect(config('guides.lien_enabled'))->toBeFalse();
    tableGuides(actif: false);
    expect(LienGuide::pour('guide-ia'))->toBeNull();
});

test('interrupteur activé et identifiant connu : le lien exact de la table', function () {
    tableGuides();
    expect(LienGuide::pour('guide-ia'))->toBe(['url' => 'https://exemple.invalid/guides/guide-ia.pdf', 'libelle' => 'Guide exemple A (PDF)']);
});

test('identifiant inconnu, vide ou absent : pas de lien', function (?string $id) {
    tableGuides();
    expect(LienGuide::pour($id))->toBeNull();
})->with([null, '', 'guide-ancien-exemple', 'GUIDE-IA', 'guide-ia ', 'https://exemple.invalid/x', '__proto__', 'constructor']);

test('une URL de la table qui n’est pas en https simple est refusée', function (string $url) {
    tableGuides(['guide-ia' => ['url' => $url, 'libelle' => 'x']]);
    expect(LienGuide::pour('guide-ia'))->toBeNull();
})->with([
    'http' => 'http://exemple.invalid/guides/guide-ia.pdf',
    'sans schéma' => '//exemple.invalid/guides/guide-ia.pdf',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html;base64,AAAA',
    'jeton personnel' => 'https://exemple.invalid/telecharger?t=abc',
    'paramètre' => 'https://exemple.invalid/guides/guide-ia.pdf?a=1',
    'fragment' => 'https://exemple.invalid/guides/guide-ia.pdf#x',
    'port' => 'https://exemple.invalid:8443/guides/guide-ia.pdf',
    'identifiant' => 'https://user@exemple.invalid/guides/guide-ia.pdf',
    'idn' => 'https://xn--exemple.invalid/guides/guide-ia.pdf',
    'espace' => 'https://exemple.invalid/guides/guide ia.pdf',
    'vide' => '',
]);

test('un libellé absent ou vide : pas de lien (jamais un lien sans nom)', function () {
    tableGuides(['guide-ia' => ['url' => 'https://exemple.invalid/g.pdf']]);
    expect(LienGuide::pour('guide-ia'))->toBeNull();
});

test('la table de production livrée est éteinte et ne contient que des adresses exemple.invalid à remplacer', function () {
    $table = require base_path('config/guides.php');
    expect($table['lien_enabled'])->toBeFalse()
        ->and(array_keys($table['publics']))->toBe(['guide-ia'])
        ->and($table['publics']['guide-ia']['url'])->toStartWith('https://exemple.invalid/');
    expect(file_get_contents(base_path('config/guides.php')))->toContain('À REMPLACER PAR WILL');
});

test('statut : éteint avant tout, puis refusé sans droit, sinon disponible', function () {
    tableGuides(actif: false);
    expect(LienGuide::statut(true))->toBe('eteint')->and(LienGuide::statut(false))->toBe('eteint');
    tableGuides();
    expect(LienGuide::statut(false))->toBe('refuse')->and(LienGuide::statut(true))->toBe('disponible');
});
