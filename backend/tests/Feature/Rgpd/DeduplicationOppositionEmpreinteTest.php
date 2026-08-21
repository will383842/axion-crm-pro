<?php

use App\Services\Dedup\DeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * L'ECRITURE ET LA LECTURE DE L'OPPOSITION PAR `DeduplicationService`.
 *
 * ⚠️ CE FICHIER S'APPELAIT `AntiReinsertionTest`. LE NOM MENTAIT, ET L'AUDIT
 * 360 L'A NOMME — constat B15-002 (S1), « vert et mesure le mauvais objet ».
 *
 * Il ne mesurait pas l'anti-reinsertion : il n'appelle ni
 * `SiteSyncIngestService`, ni `ScrapedRecordIngestService`, ni
 * `GdprErasureService`. Il exerce `addOptOut()` et `isOptedOut()` en vase clos
 * et recopie la requete d'UN SEUL funnel, celui du scraping.
 *
 * Pire : il affirmait `expect($ligne->scope)->toBe('business')` — il consacrait
 * comme CORRECT le reglage exact qui produisait B15-001 (une personne effacee
 * par la console revenait au vivier a la candidature suivante). Deux mesures
 * du 2026-08-21, sur `axion_crm_test_lot11` :
 *   · a l'etat repare, il passe 4 / 4, 9 assertions ;
 *   · le defaut de B15-001 REMIS dans `GdprErasureService`
 *     (`scopes: ['business']`), il passe encore 4 / 4. Il ne rate pas seulement
 *     le defaut : il le certifie.
 *
 * Et son assertion `->first()` etait rendue vraie par le HASARD de l'ordre
 * physique des lignes : depuis que l'effacement ecrit deux univers, la table
 * porte deux lignes et rien n'ordonnait la requete. Toutes les requetes de ce
 * fichier sont desormais explicitement bornees.
 *
 * ── CE QU'IL MESURE VRAIMENT, ET QUI A DE LA VALEUR ─────────────────────────
 *
 * La frontiere entre l'EMPREINTE et l'ADRESSE EN CLAIR. `opt_out` porte deux
 * natures d'ecriture : l'adresse lisible (opposition ordinaire, historique) et
 * l'empreinte seule (opposition nee d'un EFFACEMENT, ou l'on n'a plus le droit
 * de conserver l'adresse). Les deux moities sont verrouillees ici — l'ecriture
 * doit poser l'empreinte et JAMAIS l'adresse ; la lecture doit voir une ligne
 * qui n'a que l'empreinte. Ces quatre tests gardent cela, et rien d'autre.
 *
 * ── L'ANTI-REINSERTION EST GARDEE AILLEURS ──────────────────────────────────
 *
 *   · `EffacementConsoleAntiReinsertionTest`  — le funnel du vivier, de
 *     l'effacement console a la candidature suivante, bout en bout (B15-001) ;
 *   · `OppositionCouvreTousLesUniversTest`    — les univers LUS dans le schema
 *     et les trois portes qui les interrogent (B15-002).
 */
test('addOptOut ecrit l’EMPREINTE et jamais l’adresse en clair', function () {
    $email = 'efface@example.com';

    app(DeduplicationService::class)->addOptOut($email, null, source: 'gdpr_erasure');

    // Requete BORNEE a un univers : sans cela, `first()` rendait « une ligne
    // parmi deux » et le test dependait de l'ordre physique de la table.
    $ligne = DB::table('opt_out')
        ->where('scope', 'business')
        ->where('email_hash', hash('sha256', $email))
        ->first();

    expect($ligne)->not->toBeNull()
        ->and($ligne->email_hash)->toBe(hash('sha256', $email))
        // 🔴 L'adresse d'une personne EFFACEE n'est pas conservee en clair dans
        // la table qui enregistre son opposition. C'est le seul objet de ce
        // fichier, et il est reel.
        ->and($ligne->email)->toBeNull();
});

test('la porte du SCRAPING (univers « business ») voit l’empreinte — ET ELLE N’EST PAS LA SEULE PORTE', function () {
    $email = 'efface@example.com';

    app(DeduplicationService::class)->addOptOut($email, null, source: 'gdpr_erasure');

    // Reproduction EXACTE de la requete de `ScrapedRecordIngestService` : c'est
    // elle qui decide si une fiche revient par un re-scrape.
    //
    // ⚠️ CE TEST NE DIT RIEN DE L'ANTI-REINSERTION EN GENERAL. Il exerce UNE
    // porte sur trois. Les deux autres — `SiteSyncIngestService::hasOpposed()`
    // (univers `vivier`) et `EligibiliteCampagne::peutRecevoir()` — ont leur
    // propre `where('scope', …)` et sont gardees dans
    // `OppositionCouvreTousLesUniversTest`. L'assertion qui tenait ici
    // (`expect($ligne->scope)->toBe('business')`) affirmait le contraire : elle
    // presentait `business` comme LA valeur juste, ce qui est exactement le
    // defaut B15-001.
    $vueParLaGardeDuScraping = DB::table('opt_out')
        ->where('scope', 'business')
        ->where('email_hash', hash('sha256', $email))
        ->exists();

    expect($vueParLaGardeDuScraping)->toBeTrue();
});

test('une opposition née du SITE (hash seul, sans email) est vue par isOptedOut', function () {
    $email = 'oppose-par-le-site@example.com';

    // Ce que `SiteGdprService::optOut()` écrit : le hash, JAMAIS l'adresse.
    DB::table('opt_out')->insert([
        'email' => null,
        'email_hash' => hash('sha256', $email),
        'scope' => 'business',
        'source' => 'gdpr_erasure_bisystem',
        'created_at' => now(),
    ]);

    expect(app(DeduplicationService::class)->isOptedOut($email))->toBeTrue();
});

test('la normalisation de casse et d’espaces vaut pour l’empreinte comme pour l’adresse', function () {
    app(DeduplicationService::class)->addOptOut('  MiXtE@Example.COM  ', null, source: 'manual');

    // Sur l'ENSEMBLE des lignes ecrites, pas sur « la premiere » : l'ecriture
    // en pose une par univers, et `first()` sans ordre choisissait au hasard.
    $empreintes = DB::table('opt_out')->pluck('email_hash')->unique()->values()->all();

    expect($empreintes)->toBe([hash('sha256', 'mixte@example.com')])
        ->and(app(DeduplicationService::class)->isOptedOut('MIXTE@EXAMPLE.COM'))->toBeTrue();
});

test('une adresse jamais opposée reste scrapable', function () {
    app(DeduplicationService::class)->addOptOut('quelquun@example.com', null, source: 'manual');

    expect(app(DeduplicationService::class)->isOptedOut('quelquundautre@example.com'))->toBeFalse();
});
