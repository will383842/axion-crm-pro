<?php

use App\Crm\Ingest\ContenusAccords;
use App\Crm\Ingest\OrigineDictionnaire;
use App\Crm\Ingest\SiteSyncEvent;
use App\Crm\Ingest\SiteSyncRejection;

/**
 * Contrat v3 d'origine (ADR-CONTRAT-V3-ORIGINE) — règles PURES, sans base.
 * Valeurs fictives, adresses en .test (dépôt PUBLIC).
 */
function origineV3(array $sur = []): array
{
    return array_replace([
        'mot' => 'guide', 'reseau' => 'instagram', 'guide' => 'guide-ia',
        'date' => '2026-11-01T10:00:00Z',
        'post_ref' => 'post_ref_test_01', 'post_url' => 'https://exemple.test/p/post_ref_test_01',
        'canal' => 'commentaire', 'famille' => 'guide_reseau', 'detail' => 'guide_ia',
        'compte' => 'page_principale', 'version_texte' => 'm1-v1', 'utm_campaign' => 'guide_reseau',
    ], $sur);
}

function evenementV3(int $version, array $sur = []): array
{
    return array_replace([
        'schema_version' => $version,
        'event_id' => '9647335f-3698-593e-89bb-cef0e9870a0e',
        'event_type' => 'lead_magnet_requested',
        'occurred_at' => '2026-11-01T10:00:00Z',
        'subject_ref' => 'site:guide-reseau:run_0001',
        'person' => ['person_key' => hash('sha256', 'origines|personne@exemple.test'), 'email' => 'personne@exemple.test'],
    ], $sur);
}

// ── Interrupteur éteint par défaut ──────────────────────────────────────────

test('l\'interrupteur v3 est éteint par défaut', function () {
    expect(config('origines.v3_enabled'))->toBeFalse();
});

test('interrupteur éteint : une charge v3 est refusée, v1 et v2 restent acceptées', function () {
    config(['origines.v3_enabled' => false]);

    try {
        SiteSyncEvent::fromArray(evenementV3(3));
        $this->fail('attendu : refus 503');
    } catch (SiteSyncRejection $e) {
        // 503 : temporaire, la ligne reste en attente côté site (rien n'est perdu).
        expect($e->status)->toBe(503)->and($e->errorCode)->toBe('origines_v3_disabled');
    }
    expect(SiteSyncEvent::fromArray(evenementV3(1)))->toBeInstanceOf(SiteSyncEvent::class);
    expect(SiteSyncEvent::fromArray(evenementV3(2)))->toBeInstanceOf(SiteSyncEvent::class);
});

test('interrupteur allumé : versions 1, 2, 3 acceptées, 4 refusée', function () {
    config(['origines.v3_enabled' => true]);

    foreach ([1, 2, 3] as $v) {
        expect(SiteSyncEvent::fromArray(evenementV3($v)))->toBeInstanceOf(SiteSyncEvent::class);
    }
    expect(fn () => SiteSyncEvent::fromArray(evenementV3(4)))->toThrow(SiteSyncRejection::class);
});

test('« origines » n\'existe qu\'en v3 : en v1 ou v2 c\'est un champ inconnu', function (int $v) {
    config(['origines.v3_enabled' => true]);

    expect(fn () => SiteSyncEvent::fromArray(evenementV3($v, ['origines' => [origineV3()]])))
        ->toThrow(SiteSyncRejection::class, 'origines');
})->with([1, 2]);

test('v3 : les origines sont lues en liste validée', function () {
    config(['origines.v3_enabled' => true]);

    $e = SiteSyncEvent::fromArray(evenementV3(3, ['origines' => [origineV3()]]));

    expect($e->contenusAccords->origines)->toHaveCount(1)
        ->and($e->contenusAccords->origines[0]['famille'])->toBe('guide_reseau')
        ->and($e->contenusAccords->origines[0]['date'])->toBe('2026-11-01T10:00:00Z');
});

test('v3 : forme de la fixture partagée (origines dans payload) lue aussi', function () {
    config(['origines.v3_enabled' => true]);
    $charge = json_decode(file_get_contents(base_path('tests/fixtures/contrats/charge-crm-v3.json')), true, 512, JSON_THROW_ON_ERROR);
    $charge['person']['person_key'] = hash('sha256', 'origines|personne@exemple.test');

    $e = SiteSyncEvent::fromArray($charge);

    expect($e->contenusAccords->origines)->toHaveCount(1)
        ->and($e->contenusAccords->origine['mot'])->toBe('guide');
});

// ── Validation ──────────────────────────────────────────────────────────────

test('une clé inconnue reste refusée en v3 (unknown_field)', function (string $cle) {
    expect(fn () => ContenusAccords::depuisRacine(['origines' => [origineV3([$cle => 'x'])]], true))
        ->toThrow(SiteSyncRejection::class);
})->with(['pseudo', 'nom', 'texte', 'message', 'profil']);

test('famille ou détail hors dictionnaire refusés', function (array $sur) {
    expect(fn () => ContenusAccords::depuisRacine(['origines' => [origineV3($sur)]], true))
        ->toThrow(SiteSyncRejection::class);
})->with([
    'famille inconnue' => [['famille' => 'inconnue']],
    'détail inconnu' => [['detail' => 'inconnu']],
    'détail d\'une autre famille' => [['famille' => 'carrousel', 'detail' => 'guide_ia']],
    'détail sans famille' => [['famille' => null]],
]);

test('canal : seuls commentaire et message_prive ; message_prive refusé pour LinkedIn et TikTok', function () {
    expect(fn () => ContenusAccords::depuisRacine(['origines' => [origineV3(['canal' => 'autre'])]], true))->toThrow(SiteSyncRejection::class);
    expect(fn () => ContenusAccords::depuisRacine(['origines' => [origineV3(['canal' => 'message_prive', 'reseau' => 'linkedin'])]], true))->toThrow(SiteSyncRejection::class);
    expect(fn () => ContenusAccords::depuisRacine(['origines' => [origineV3(['canal' => 'message_prive', 'reseau' => 'tiktok'])]], true))->toThrow(SiteSyncRejection::class);
    expect(ContenusAccords::depuisRacine(['origines' => [origineV3(['canal' => 'message_prive'])]], true)->origines)->toHaveCount(1);
});

test('post_ref et post_url : forme stricte', function (array $sur) {
    expect(fn () => ContenusAccords::depuisRacine(['origines' => [origineV3($sur)]], true))->toThrow(SiteSyncRejection::class);
})->with([
    'post_ref trop court' => [['post_ref' => 'abc']],
    'post_ref avec espace' => [['post_ref' => 'post ref test 01']],
    'http' => [['post_url' => 'http://exemple.test/p/1']],
    'port' => [['post_url' => 'https://exemple.test:8443/p/1']],
    'identifiant' => [['post_url' => 'https://u:p@exemple.test/p/1']],
    'paramètre' => [['post_url' => 'https://exemple.test/p/1?x=1']],
    'fragment' => [['post_url' => 'https://exemple.test/p/1#a']],
    'trop long' => [['post_url' => 'https://exemple.test/' . str_repeat('a', 300)]],
]);

test('plus de 20 origines dans une charge : too_many_origines', function () {
    $liste = array_fill(0, 21, origineV3());

    try {
        ContenusAccords::depuisRacine(['origines' => $liste], true);
        $this->fail('attendu : refus');
    } catch (SiteSyncRejection $e) {
        expect($e->getMessage())->toContain('origines');
    }
});

test('commentaire_ref est gardé sous forme d\'empreinte, jamais en clair', function () {
    $a = ContenusAccords::depuisRacine(['origines' => [origineV3(['commentaire_ref' => 'cmt_ref_test_0001'])]], true);

    expect(json_encode($a->origines))->not->toContain('cmt_ref_test_0001')
        ->and($a->origines[0]['commentaire_ref'])->toMatch('/^[0-9a-f]{64}$/');
});

// ── Dictionnaire ────────────────────────────────────────────────────────────

test('le dictionnaire applicatif valide famille › détail', function () {
    expect(OrigineDictionnaire::valide('guide_reseau', 'guide_ia'))->toBeTrue()
        ->and(OrigineDictionnaire::valide('autre', 'autre_non_classe'))->toBeTrue()
        ->and(OrigineDictionnaire::valide('autre', 'guide_ia'))->toBeFalse()
        ->and(OrigineDictionnaire::valide('x', 'autre_non_classe'))->toBeFalse();
});

// ── Union sans retrait, plafond, archive ────────────────────────────────────

test('union idempotente, dédoublonnée sur (post_ref, canal, date), triée par date', function () {
    $a = ContenusAccords::depuisRacine(['origines' => [origineV3(), origineV3(['post_ref' => 'post_ref_test_02', 'date' => '2026-11-02T10:00:00Z'])]], true)->origines;

    $r1 = ContenusAccords::fusionnerOrigines([], $a);
    $r2 = ContenusAccords::fusionnerOrigines($r1['garde'], $a);

    expect($r1['garde'])->toHaveCount(2)
        ->and($r2['garde'])->toBe($r1['garde'])
        ->and($r1['garde'][0]['date'])->toBe('2026-11-01T10:00:00Z')
        ->and($r1['archive'])->toBe([]);
});

test('même post_ref + canal + date : une seule entrée ; canal différent : deux', function () {
    $x = ContenusAccords::depuisRacine(['origines' => [origineV3(), origineV3(['detail' => 'autre_non_classe'])]], true)->origines;
    expect(ContenusAccords::fusionnerOrigines([], $x)['garde'])->toHaveCount(1);

    $y = ContenusAccords::depuisRacine(['origines' => [origineV3(), origineV3(['canal' => 'message_prive'])]], true)->origines;
    expect(ContenusAccords::fusionnerOrigines([], $y)['garde'])->toHaveCount(2);
});

test('origines v1/v2 sans post_ref : dédoublonnage sur (mot, reseau, guide, date), null traité comme valeur', function () {
    $sans = static fn (string $g) => ['mot' => 'guide', 'reseau' => 'instagram', 'guide' => $g, 'date' => '2026-11-01T10:00:00Z'];
    $r = ContenusAccords::fusionnerOrigines([$sans('a')], [$sans('a'), $sans('b')]);

    expect($r['garde'])->toHaveCount(2);
});

test('au-delà de 50 : les plus anciennes passent à l\'archive, rien n\'est supprimé', function () {
    $tout = [];
    for ($i = 0; $i < 55; $i++) {
        $tout[] = origineV3(['post_ref' => sprintf('post_ref_%04d', $i), 'date' => gmdate('Y-m-d\TH:i:s\Z', 1_793_000_000 + $i * 60)]);
    }

    $r = ContenusAccords::fusionnerOrigines([], $tout);

    expect($r['garde'])->toHaveCount(50)
        ->and($r['archive'])->toHaveCount(5)
        ->and($r['archive'][0]['post_ref'])->toBe('post_ref_0000')
        ->and($r['garde'][0]['post_ref'])->toBe('post_ref_0005');

    // Rejouer la même charge ne change rien (idempotent).
    $deux = ContenusAccords::fusionnerOrigines($r['garde'], $tout);
    expect($deux['garde'])->toHaveCount(50)->and($deux['archive'])->toHaveCount(5);
});
