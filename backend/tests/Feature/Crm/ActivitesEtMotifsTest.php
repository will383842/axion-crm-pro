<?php

use App\Crm\ActivitesEtMotifs;
use Database\Seeders\ActivitesEtMotifsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * ACTIVITÉS et MOTIFS D'ÉCHANGE (§2.3) — étape 1a, pièce 2.
 *
 * Ces tests gardent trois choses, dans cet ordre d'importance :
 *
 *   1. que le CONTENU LIVRÉ D'ORIGINE soit là — sans motif sélectionnable, le
 *      critère « un appel se consigne en 1 clic avec le bon motif » est mort ;
 *   2. que les tables restent EXTENSIBLES — c'est l'exigence qui a justifié de
 *      ne PAS utiliser un `CHECK` fermé, contrairement au reste de la
 *      taxonomie ; un test qui ne la garde pas laisserait quelqu'un « durcir »
 *      la table de bonne foi et casser le §29 critère 2 ;
 *   3. qu'un re-semis n'ÉCRASE PAS une personnalisation faite depuis la
 *      console — sinon la promesse « extensible depuis la console » est fausse
 *      une fois par déploiement, et personne ne s'en aperçoit avant que
 *      quelqu'un se plaigne que « ça revient tout seul ».
 */

// ─────────────────────────────────────────────────────────────────────────────
// 1. Le contenu livré d'origine
// ─────────────────────────────────────────────────────────────────────────────

test('les cinq activités du §2.3 sont livrées d\'origine', function () {
    $enBase = DB::table('crm_activites')->orderBy('ordre')->pluck('label', 'slug')->all();

    expect($enBase)->toBe([
        'formation' => 'Formation',
        'accompagnement-1-1' => 'Accompagnement individuel 1:1',
        'audit-ia' => 'Audit IA',
        'implementation' => 'Implémentation et automatisation',
        'site-web-saas' => 'Site web et SaaS',
    ]);
});

test('la formation est la SEULE activité soumise à Qualiopi', function () {
    // Le §2.3 est explicite : « c'est la seule activité soumise à Qualiopi ».
    // Ce drapeau dira plus tard quels dossiers relèvent du référentiel : le
    // poser en trop sur une activité qui n'y est pas soumise ferait entrer des
    // dossiers dans un régime d'obligations qui ne les concerne pas.
    $qualiopi = DB::table('crm_activites')->where('qualiopi', true)->pluck('slug')->all();

    expect($qualiopi)->toBe(['formation']);
});

test('les onze motifs non commerciaux du §2.3 sont livrés d\'origine', function () {
    $enBase = DB::table('crm_motifs')->orderBy('ordre')->pluck('slug')->all();

    expect($enBase)->toBe([
        'presse',
        'partenariat',
        'candidature',
        'investisseur',
        'conference',
        'podcast',
        'support-reclamation',
        'fournisseur',
        'sous-traitant',
        'organisme-financeur',
        'autre',
    ]);
});

test('la candidature est le seul motif réservé à l\'espace vivier', function () {
    // §2.1 : « une trame "candidature" n'existe que dans l'espace vivier ».
    // C'est la seule variance d'espace que le cahier des charges reconnaisse.
    $parEspace = DB::table('crm_motifs')->orderBy('slug')->pluck('espace', 'slug')->all();

    expect(array_keys($parEspace, 'vivier', true))->toBe(['candidature']);
    expect(array_keys($parEspace, 'tous', true))->toBe(['autre']);
});

test('le code et la base disent la même chose', function () {
    // Témoin de cohérence : la constante PHP est la référence du semis. Si
    // quelqu'un ajoute un motif au PHP sans re-semer, ce test le dit.
    expect(DB::table('crm_activites')->pluck('slug')->sort()->values()->all())
        ->toBe(collect(array_keys(ActivitesEtMotifs::ACTIVITES))->sort()->values()->all());

    expect(DB::table('crm_motifs')->pluck('slug')->sort()->values()->all())
        ->toBe(collect(array_keys(ActivitesEtMotifs::MOTIFS))->sort()->values()->all());
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. L'extensibilité — l'exigence qui a fait renoncer au CHECK fermé
// ─────────────────────────────────────────────────────────────────────────────

test('un motif maison s\'ajoute SANS migration (§29 critère 2)', function () {
    // C'est exactement ce qu'un `CHECK IN (...)` aurait interdit. Ce test est
    // la garde de la décision : quiconque « durcira » cette table par un enum
    // fermé le verra rougir ici.
    DB::table('crm_motifs')->insert([
        'slug' => 'salon-professionnel',
        'label' => 'Salon professionnel',
        'espace' => 'business',
        'ordre' => 200,
        'actif' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('crm_motifs')->where('slug', 'salon-professionnel')->exists())->toBeTrue();
});

test('une activité maison s\'ajoute SANS migration', function () {
    DB::table('crm_activites')->insert([
        'slug' => 'conseil-strategique',
        'label' => 'Conseil stratégique',
        'qualiopi' => false,
        'ordre' => 60,
        'actif' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('crm_activites')->where('slug', 'conseil-strategique')->exists())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Le re-semis ne détruit pas le travail de la console
// ─────────────────────────────────────────────────────────────────────────────

test('un libellé modifié depuis la console SURVIT à un re-semis', function () {
    DB::table('crm_motifs')->where('slug', 'presse')->update([
        'label' => 'Relations presse et médias',
        'ordre' => 5,
    ]);

    (new ActivitesEtMotifsSeeder)->run();

    $motif = DB::table('crm_motifs')->where('slug', 'presse')->first();

    expect($motif->label)->toBe('Relations presse et médias');
    expect((int) $motif->ordre)->toBe(5);
});

test('un motif DÉSACTIVÉ depuis la console ne se rallume pas tout seul', function () {
    // Même piège que le kill-switch de `scraping_sources` : un re-seed qui
    // remet `actif = true` rallume en production ce que quelqu'un avait coupé.
    DB::table('crm_motifs')->where('slug', 'podcast')->update(['actif' => false]);

    (new ActivitesEtMotifsSeeder)->run();

    expect(DB::table('crm_motifs')->where('slug', 'podcast')->value('actif'))->toBeFalse();
});

test('un re-semis ne duplique aucune ligne', function () {
    $avant = DB::table('crm_motifs')->count() + DB::table('crm_activites')->count();

    (new ActivitesEtMotifsSeeder)->run();
    (new ActivitesEtMotifsSeeder)->run();

    expect(DB::table('crm_motifs')->count() + DB::table('crm_activites')->count())->toBe($avant);
});

test('un motif ajouté à la main survit à un re-semis', function () {
    DB::table('crm_motifs')->insert([
        'slug' => 'jury-ecole',
        'label' => 'Jury d\'école',
        'espace' => 'business',
        'ordre' => 300,
        'actif' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new ActivitesEtMotifsSeeder)->run();

    expect(DB::table('crm_motifs')->where('slug', 'jury-ecole')->exists())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Ce que la base refuse — les contraintes sont dans la BASE, pas dans l'ORM
// ─────────────────────────────────────────────────────────────────────────────

test('un slug en double est refusé par la base', function () {
    DB::table('crm_motifs')->insert([
        'slug' => 'presse',
        'label' => 'Doublon',
        'espace' => 'business',
        'ordre' => 1,
        'actif' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('un espace hors référentiel est refusé par la base', function () {
    // `espace`, LUI, est fermé : il décrit le cloisonnement business / vivier,
    // qui est structurel et gardé par des triggers — pas un réglage de console.
    DB::table('crm_motifs')->insert([
        'slug' => 'motif-espace-invalide',
        'label' => 'Espace inventé',
        'espace' => 'univers-parallele',
        'ordre' => 1,
        'actif' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('un libellé vide est refusé par la base', function () {
    // Une console qui laisse passer un libellé vide produirait une ligne
    // invisible dans une liste déroulante : sélectionnable, et sans nom.
    DB::table('crm_motifs')->insert([
        'slug' => 'motif-sans-libelle',
        'label' => '   ',
        'espace' => 'business',
        'ordre' => 1,
        'actif' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('les espaces reconnus sont exactement ceux du CHECK en base', function () {
    $def = DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        ['crm_motifs_espace_check'],
    );

    expect($def)->not->toBeNull('La contrainte crm_motifs_espace_check n\'existe pas en base.');

    preg_match_all("/'([^']*)'::/", (string) $def->def, $matches);
    $valeurs = array_values(array_unique($matches[1]));
    sort($valeurs);

    $attendu = ActivitesEtMotifs::ESPACES;
    sort($attendu);

    expect($valeurs)->toBe($attendu);
});
