<?php

use App\Crm\Ingest\ContenusAccords;
use App\Crm\Ingest\SiteSyncRejection;

/**
 * CONTRAT DE FORMAT — mots-clés et codes de guides (Studio, site, CRM).
 *
 * L'accord : le format est IMPOSÉ côté émetteur, le CRM accepte tout ce qui
 * respecte ce format. Rien n'est figé : aucune liste de mots ni de guides ici.
 *   - Studio (mot-clé)  : /^[A-Z0-9]{2,20}$/ — capitales A-Z et chiffres.
 *     Le CRM le range en minuscules (« CONVERSATION2026 » → « conversation2026 »).
 *   - Site (code guide) : /^(gde|adr)-[a-z0-9-]{6,24}$/ — plus l'ancien « guide-ia ».
 *     Le CRM le garde tel quel : il ne le met PAS en minuscules, un code en
 *     capitales est donc refusé (`invalid_token`).
 * Si l'un de ces formats change, ce test doit rougir : il faut alors se remettre
 * d'accord avec l'autre équipe avant de toucher au CRM.
 *
 * Valeurs fictives seulement (dépôt PUBLIC).
 */
function origineAvecMot(string $mot): ?array
{
    return ContenusAccords::depuisRacine(['origine_contenu' => ['mot' => $mot]], true)?->origine;
}

test('un mot du Studio est accepté et rangé en minuscules', function (string $mot, string $attendu) {
    expect(origineAvecMot($mot)['mot'])->toBe($attendu);
})->with([
    'mot long avec chiffres' => ['CONVERSATION2026', 'conversation2026'],
    'mot simple' => ['GUIDE', 'guide'],
]);

test('un mot du Studio est aussi rangé en minuscules dans l’historique des origines', function () {
    $a = ContenusAccords::depuisRacine(['origines' => [['mot' => 'CONVERSATION2026', 'date' => '2026-11-02T10:00:00Z']]], true);

    expect($a->origines[0]['mot'])->toBe('conversation2026');
});

test('un mot hors format est refusé invalid_token', function (string $mot) {
    try {
        origineAvecMot($mot);
        $this->fail('Le mot aurait dû être refusé.');
    } catch (SiteSyncRejection $e) {
        expect($e->errorCode)->toBe('invalid_token');
    }
})->with([
    'accent' => ['création'],
    'espace' => ['MOT CLE'],
]);

test('un code de guide du site est accepté tel quel', function (string $guide) {
    $a = ContenusAccords::depuisRacine([
        'origine_contenu' => ['guide' => $guide],
        'guides_recus' => [['guide' => $guide, 'date' => '2026-11-02T10:00:00Z']],
    ], true);

    expect($a->origine['guide'])->toBe($guide)
        ->and($a->guides[0]['guide'])->toBe($guide);
})->with(['gde-conversation', 'adr-offre01', 'guide-ia']);

test('un code de guide en capitales est refusé invalid_token', function (string $guide) {
    try {
        ContenusAccords::depuisRacine(['guides_recus' => [['guide' => $guide, 'date' => '2026-11-02T10:00:00Z']]], true);
        $this->fail('Le guide aurait dû être refusé.');
    } catch (SiteSyncRejection $e) {
        expect($e->errorCode)->toBe('invalid_token');
    }
})->with(['Gde-Conversation']);
