<?php

use App\Crm\Ingest\ContenusAccords;
use App\Crm\Ingest\SiteSyncRejection;

/**
 * PV-6 — règles PURES de « Contenus et accords » (sans base). Valeurs fictives
 * seulement (dépôt PUBLIC).
 */
test('une charge sans les quatre champs ne donne rien', function () {
    expect(ContenusAccords::depuisRacine(['event_id' => 'x']))->toBeNull();
});

test('une charge complète est validée et les dates normalisées en UTC', function () {
    $a = ContenusAccords::depuisRacine([
        'origine_contenu' => ['mot' => 'guide-ia', 'reseau' => 'reseau-a', 'guide' => 'guide-ia', 'date' => '2026-11-02T10:58:00+01:00'],
        'guides_recus' => [['guide' => 'guide-ia', 'date' => '2026-11-02T10:00:00Z']],
        'consentements' => [['nature' => 'information', 'date' => '2026-11-02T10:00:00Z', 'source' => 'mot-guide-ia:reseau-a', 'version' => 'M1']],
        'opposition' => ['actif' => false],
    ]);

    expect($a->origine['date'])->toBe('2026-11-02T09:58:00Z')
        ->and($a->oppose())->toBeFalse();
});

test('le pseudo de réseau et le texte du message sont refusés', function (string $cle) {
    expect(fn () => ContenusAccords::depuisRacine(['origine_contenu' => ['mot' => 'a', $cle => 'x']]))
        ->toThrow(SiteSyncRejection::class);
})->with(['pseudo', 'message', 'texte', 'handle']);

test('une nature inconnue ou une liste trop longue est refusée', function () {
    expect(fn () => ContenusAccords::depuisRacine(['consentements' => [['nature' => 'autre', 'date' => '2026-11-02T10:00:00Z', 'source' => 's', 'version' => 'v']]]))
        ->toThrow(SiteSyncRejection::class);
    $trop = array_fill(0, 51, ['guide' => 'g', 'date' => '2026-11-02T10:00:00Z']);
    expect(fn () => ContenusAccords::depuisRacine(['guides_recus' => $trop]))->toThrow(SiteSyncRejection::class);
});

test('l’union ne retire rien et ne dépend pas de l’ordre', function () {
    $a = [['guide' => 'g1', 'date' => '2026-11-01T10:00:00Z']];
    $b = [['guide' => 'g2', 'date' => '2026-11-02T10:00:00Z']];

    expect(ContenusAccords::fusionnerListes(ContenusAccords::fusionnerListes([], $a), $b))
        ->toBe(ContenusAccords::fusionnerListes(ContenusAccords::fusionnerListes([], $b), $a));
    // une liste plus courte, ou absente, ne retire rien
    expect(ContenusAccords::fusionnerListes($a, []))->toBe($a)
        ->and(ContenusAccords::fusionnerListes($a, null))->toBe($a);
});

test('l’origine la plus récente gagne, dans les deux ordres', function () {
    $vieux = ['mot' => 'ancien'];
    $neuf = ['mot' => 'recent'];
    $t1 = new DateTimeImmutable('2026-11-01T10:00:00Z');
    $t2 = new DateTimeImmutable('2026-11-02T10:00:00Z');

    // ordre normal
    expect(ContenusAccords::fusionnerOrigine($vieux, $t1, $neuf, $t2))->toBe($neuf);
    // ordre inverse : l'ancien arrive après
    expect(ContenusAccords::fusionnerOrigine($neuf, $t2, $vieux, $t1))->toBe($neuf);
    // absente : n'efface rien
    expect(ContenusAccords::fusionnerOrigine($neuf, $t2, null, $t1))->toBe($neuf);
});

test('le bloc de lecture ne laisse sortir que les clés prévues, échappables', function () {
    $bloc = ContenusAccords::bloc(
        json_encode(['mot' => 'm', 'pseudo' => 'ne-doit-pas-sortir']),
        json_encode([['guide' => 'g', 'date' => 'd', 'message' => 'non']]),
        json_encode([['nature' => 'optin', 'date' => 'd', 'source' => 's', 'version' => 'M1', 'texte' => 'non']]),
        null,
    );

    expect(json_encode($bloc))->not->toContain('pseudo')->not->toContain('message')->not->toContain('texte')
        ->and($bloc['opposition']['actif'])->toBeFalse();
    expect(ContenusAccords::bloc(null, null, null, '2026-11-02 10:00:00')['opposition']['actif'])->toBeTrue();
});
