<?php

/**
 * L2-back — API de LECTURE des origines (contrat v3). Lecture seule, filtres
 * en liste fermée, entrées mises à l'écart (`masque_le`) jamais comptées à
 * l'écran, historique complet fiche + archive. Adresses en `@example.invalid`.
 */

use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    OriginesJeu::allumer();
    $this->axion = OriginesJeu::espace('axion-ia');
    OriginesJeu::ouvrir($this->axion->id);
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'admin', 'ol.admin@example.invalid'));
    $ws = $this->axion->id;

    // Jeu fixe : 3 réseaux, 4 mots, 1 opposée, 1 entrée masquée, 1 entrée en archive.
    $this->p1 = OriginesJeu::personne($ws, 'ol.un@example.invalid', [
        OriginesJeu::entree(['mot' => 'guide', 'reseau' => 'instagram', 'date' => '2026-11-01T10:00:00Z', 'post_ref' => 'post_ref_aaaa01']),
        OriginesJeu::entree(['mot' => 'ia', 'reseau' => 'linkedin', 'date' => '2026-11-02T10:00:00Z', 'post_ref' => 'post_ref_aaaa02', 'famille' => 'guide_reseau', 'detail' => 'guide_ia']),
    ]);
    $this->p2 = OriginesJeu::personne($ws, 'ol.deux@example.invalid', [
        OriginesJeu::entree(['mot' => 'agent', 'reseau' => 'tiktok', 'date' => '2026-11-03T10:00:00Z', 'post_ref' => 'post_ref_aaaa03']),
        OriginesJeu::entree(['mot' => 'secret', 'reseau' => 'tiktok', 'date' => '2026-11-03T11:00:00Z', 'post_ref' => 'post_ref_aaaa04', 'masque_le' => '2026-11-04T00:00:00Z']),
    ]);
    $this->p3 = OriginesJeu::personne($ws, 'ol.trois@example.invalid', [
        OriginesJeu::entree(['mot' => 'guide', 'reseau' => 'instagram', 'date' => '2026-11-03T12:00:00Z', 'post_ref' => 'post_ref_aaaa05']),
    ], ['opposition_at' => now()]);
    OriginesJeu::archive($ws, $this->p1, OriginesJeu::entree(['mot' => 'ancien', 'reseau' => 'instagram', 'date' => '2026-10-01T10:00:00Z', 'post_ref' => 'post_ref_arch01']));
});

test('OL 1 — interrupteur v3 éteint : 200 {actif:false}, aucune lecture des origines', function () {
    config(['origines.v3_enabled' => false]);
    DB::enableQueryLog();

    foreach (['apercu', 'par-reseau', 'par-mot', 'tendance', 'personnes'] as $route) {
        $this->getJson('/api/v1/crm/origines/' . $route)->assertOk()->assertJson(['actif' => false]);
    }
    $this->getJson('/api/v1/crm/personnes/' . $this->p1 . '/origines')->assertOk()->assertJson(['actif' => false]);

    $lues = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'origines') && ! str_contains($q['query'], 'information_schema') && ! str_contains($q['query'], 'pg_'));
    expect($lues->filter(fn ($q) => str_contains($q['query'], 'jsonb_array_elements') || str_contains($q['query'], 'personne_origines_archive'))->count())->toBe(0);
});

test('OL 2 — aperçu : agrégats exacts, l’entrée masquée n’est comptée nulle part', function () {
    $r = $this->getJson('/api/v1/crm/origines/apercu')->assertOk()->json();

    // p1: 2 fiche + 1 archive = 3 ; p2: 1 visible (1 masquée exclue) ; p3: 1 → 5 entrées, 3 personnes.
    expect($r['actif'])->toBeTrue()
        ->and($r['personnes'])->toBe(3)
        ->and($r['demandes'])->toBe(5)
        ->and($r['opposees'])->toBe(1);
    expect(json_encode($r))->not->toContain('secret');
    $parFamille = collect($r['par_famille'])->keyBy('famille');
    expect($parFamille['guide_reseau']['demandes'])->toBe(5);
});

test('OL 3 — par réseau et par mot : comptes exacts, mot masqué absent, pagination 25', function () {
    $reseaux = collect($this->getJson('/api/v1/crm/origines/par-reseau')->assertOk()->json('data'))->keyBy('reseau');
    expect($reseaux['instagram']['demandes'])->toBe(3)   // 2 fiche (p1, p3) + 1 archive
        ->and($reseaux['linkedin']['demandes'])->toBe(1)
        ->and($reseaux['tiktok']['demandes'])->toBe(1);   // la masquée n'est pas comptée

    $mots = $this->getJson('/api/v1/crm/origines/par-mot')->assertOk();
    $parMot = collect($mots->json('data'))->keyBy('mot');
    expect($parMot['guide']['demandes'])->toBe(2)
        ->and($parMot->has('secret'))->toBeFalse()
        ->and($mots->json('meta.per_page'))->toBe(25);
});

test('OL 4 — tendance : 30 points exactement, jours vides à 0 ; jours=500 → 422', function () {
    $r = $this->getJson('/api/v1/crm/origines/tendance')->assertOk()->json('data');
    expect($r)->toHaveCount(30)
        ->and(collect($r)->every(fn ($p) => isset($p['jour']) && is_int($p['demandes'])))->toBeTrue();

    $this->getJson('/api/v1/crm/origines/tendance?jours=7')->assertOk()->assertJsonCount(7, 'data');
    $this->getJson('/api/v1/crm/origines/tendance?jours=500')->assertStatus(422);
    $this->getJson('/api/v1/crm/origines/tendance?jours=3')->assertStatus(422);
});

test('OL 5 — filtres seuls et combinés ; valeur hors liste → 422 sans écho', function () {
    $this->getJson('/api/v1/crm/origines/par-reseau?reseau=instagram')->assertOk();
    $a = $this->getJson('/api/v1/crm/origines/apercu?reseau=tiktok')->json();
    expect($a['demandes'])->toBe(1);
    $b = $this->getJson('/api/v1/crm/origines/apercu?oppose=oui')->json();
    expect($b['demandes'])->toBe(1)->and($b['personnes'])->toBe(1);
    $c = $this->getJson('/api/v1/crm/origines/apercu?oppose=non&reseau=instagram')->json();
    expect($c['demandes'])->toBe(2);
    $d = $this->getJson('/api/v1/crm/origines/apercu?du=2026-11-02&au=2026-11-02')->json();
    expect($d['demandes'])->toBe(1);
    $e = $this->getJson('/api/v1/crm/origines/apercu?mot=guide&famille=guide_reseau&detail=guide_ia')->json();
    expect($e['demandes'])->toBe(2);

    foreach (['famille=ZZ_INCONNUE_ZZ', 'reseau=ZZ_RESEAU_ZZ', 'detail=ZZ_DETAIL_ZZ', 'mot=%27%20OR%201=1', 'oppose=peut-etre', 'du=hier', 'du=2020-01-01&au=2026-11-01'] as $q) {
        $rep = $this->getJson('/api/v1/crm/origines/apercu?' . $q);
        $rep->assertStatus(422);
        expect($rep->getContent())->not->toContain('ZZ_INCONNUE_ZZ')->not->toContain('ZZ_RESEAU_ZZ')->not->toContain('ZZ_DETAIL_ZZ')->not->toContain('OR 1=1');
    }
});

test('OL 6 — liste nominative : masquée pour un viewer, filtrée', function () {
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'viewer', 'ol.viewer@example.invalid'));

    $r = $this->getJson('/api/v1/crm/origines/personnes?reseau=linkedin')->assertOk();
    $txt = $r->getContent();
    expect($txt)->not->toContain('ol.un@example.invalid')
        ->and($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0.email'))->toMatch('/^o\*\*\*@/');
});

test('OL 7 — historique complet : fiche + archive, ordre décroissant, liste blanche, pagination stable', function () {
    $ws = $this->axion->id;
    $gros = OriginesJeu::personne($ws, 'ol.gros@example.invalid', array_map(
        fn (int $i) => OriginesJeu::entree([
            'post_ref' => sprintf('post_ref_gros%03d', $i), 'date' => sprintf('2026-10-%02dT10:%02d:00Z', ($i % 28) + 1, $i % 60),
            'pseudo' => 'ne-doit-pas-sortir', 'texte' => 'ne-doit-pas-sortir', 'nom' => 'ne-doit-pas-sortir', 'commentaire_ref' => 'EMPREINTE_BRUTE',
        ]),
        range(1, 50),
    ));
    foreach ([1, 2, 3] as $i) {
        OriginesJeu::archive($ws, $gros, OriginesJeu::entree(['post_ref' => 'post_ref_old' . $i, 'date' => '2026-09-0' . $i . 'T10:00:00Z']));
    }
    OriginesJeu::archive($ws, $gros, OriginesJeu::entree(['post_ref' => 'post_ref_masq', 'date' => '2026-09-09T10:00:00Z']), '2026-11-04 00:00:00+00');

    $page1 = $this->getJson("/api/v1/crm/personnes/{$gros}/origines?par_page=30")->assertOk();
    expect($page1->json('data'))->toHaveCount(30)->and($page1->json('meta.total'))->toBe(53);
    $suite = $page1->json('meta.suivant');
    $page2 = $this->getJson("/api/v1/crm/personnes/{$gros}/origines?par_page=30&curseur={$suite}")->assertOk();
    expect($page2->json('data'))->toHaveCount(23)->and($page2->json('meta.suivant'))->toBeNull();

    $tout = array_merge($page1->json('data'), $page2->json('data'));
    $dates = array_column($tout, 'date');
    $triees = $dates;
    rsort($triees);
    expect($dates)->toBe($triees)
        ->and(count(array_unique(array_column($tout, 'post_ref'))))->toBe(53);

    $brut = json_encode($tout);
    foreach (['"pseudo"', '"texte"', '"nom"', 'commentaire_ref', 'EMPREINTE_BRUTE', 'ne-doit-pas-sortir', 'post_ref_masq', 'masque_le'] as $interdit) {
        expect($brut)->not->toContain($interdit);
    }
    expect(array_keys($tout[0]))->toEqualCanonicalizing(['famille', 'detail', 'canal', 'reseau', 'compte', 'mot', 'guide', 'date', 'post_ref', 'post_url', 'version_texte', 'utm_campaign']);
});

test('OL 8 — IDOR : personne d’un autre espace, inexistante ou non numérique → 404 au même corps', function () {
    $autre = OriginesJeu::espace('ws-autre-ol');
    $etrangere = OriginesJeu::personne($autre->id, 'ol.etrangere@example.invalid', [OriginesJeu::entree()]);

    $a = $this->getJson("/api/v1/crm/personnes/{$etrangere}/origines");
    $b = $this->getJson('/api/v1/crm/personnes/99999999/origines');
    $a->assertNotFound();
    $b->assertNotFound();
    expect($a->json('message'))->toBe($b->json('message'));
    $this->getJson('/api/v1/crm/personnes/abc/origines')->assertNotFound();
});

test('OL 9 — vue sauvegardée entity=origines : créée, relue, isolée par utilisateur et par espace', function () {
    $filtres = ['famille' => 'guide_reseau', 'detail' => 'guide_ia', 'reseau' => 'instagram', 'mot' => 'guide', 'oppose' => 'non', 'du' => '2026-10-01', 'au' => '2026-11-04'];
    $this->postJson('/api/v1/saved-views', ['entity' => 'origines', 'name' => 'Instagram guide', 'filters' => $filtres])->assertSuccessful();

    $liste = $this->getJson('/api/v1/saved-views?entity=origines')->assertOk();
    expect(json_encode($liste->json()))->toContain('Instagram guide');

    $autre = OriginesJeu::espace('ws-vues-ol');
    $this->actingAs(OriginesJeu::utilisateur($autre->id, 'admin', 'ol.autrevues@example.invalid'));
    expect(json_encode($this->getJson('/api/v1/saved-views?entity=origines')->json()))->not->toContain('Instagram guide');
});

test('OL 10 — lecture seule : aucune requête d’écriture sur toutes les routes de lecture', function () {
    DB::enableQueryLog();
    $urls = ['apercu', 'par-reseau', 'par-mot', 'tendance', 'personnes'];
    foreach ($urls as $u) {
        $this->getJson('/api/v1/crm/origines/' . $u)->assertOk();
    }
    $this->getJson("/api/v1/crm/personnes/{$this->p1}/origines")->assertOk();

    $ecritures = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|truncate|drop|alter|create)\b/i', $q['query']) === 1
        && ! str_contains($q['query'], 'audit_logs') && ! str_contains($q['query'], 'personal_access_tokens'));
    expect($ecritures->pluck('query')->all())->toBe([]);
});

test('OL 11 — aucun journal avec filtres ni corps de requête', function () {
    $source = file_get_contents(app_path('Http/Controllers/Api/Crm/OriginesController.php')) . file_get_contents(app_path('Crm/Origines/RequetesOrigines.php'));
    expect($source)->not->toMatch('/Log::/');
});
