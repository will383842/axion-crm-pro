<?php

use App\Services\Dedup\DeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 🔴 L'ANTI-RÉINSERTION ÉTAIT AVEUGLE — DANS LES DEUX SENS.
 *
 * `opt_out` porte deux écritures de nature différente : l'email en clair
 * (opposition ordinaire) et l'`email_hash` seul (opposition née d'un
 * EFFACEMENT, où l'on n'a plus le droit de garder l'adresse).
 *
 * Avant le 2026-08-16 :
 *   · `DeduplicationService::addOptOut()` n'écrivait QUE l'email en clair.
 *     La garde du funnel de collecte, qui interroge uniquement `email_hash`,
 *     ne voyait donc AUCUNE opposition née d'un effacement console — la
 *     personne était ré-insérée au prochain re-scrape.
 *   · `isOptedOut()` ne lisait QUE l'email en clair. Toute opposition née du
 *     site (hash seul) lui était invisible.
 *
 * Ces tests verrouillent les deux moitiés. Ils rougissent si l'une des deux
 * écritures ou l'une des deux lectures redevient borgne.
 */
test('un effacement écrit l’EMPREINTE, que la garde du scraping sait lire', function () {
    $email = 'efface@example.com';

    app(DeduplicationService::class)->addOptOut($email, null, source: 'gdpr_erasure');

    $ligne = DB::table('opt_out')->where('email', $email)->first();

    expect($ligne)->not->toBeNull()
        ->and($ligne->email_hash)->toBe(hash('sha256', $email))
        // La garde du funnel filtre sur ce scope : sans lui, elle ne voit rien.
        ->and($ligne->scope)->toBe('business');

    // Reproduction EXACTE de la requête de `ScrapedRecordIngestService` :
    // c'est elle qui décide si une fiche revient par un re-scrape.
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

    $ligne = DB::table('opt_out')->first();

    expect($ligne->email_hash)->toBe(hash('sha256', 'mixte@example.com'))
        ->and(app(DeduplicationService::class)->isOptedOut('MIXTE@EXAMPLE.COM'))->toBeTrue();
});

test('une adresse jamais opposée reste scrapable', function () {
    app(DeduplicationService::class)->addOptOut('quelquun@example.com', null, source: 'manual');

    expect(app(DeduplicationService::class)->isOptedOut('quelquundautre@example.com'))->toBeFalse();
});
