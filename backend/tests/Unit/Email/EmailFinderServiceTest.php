<?php

use App\Services\Email\EmailFinderService;

test('PATTERNS contains 18 entries', function () {
    expect(EmailFinderService::PATTERNS)->toHaveCount(18);
});

test('generateCandidates produces 12+ unique valid emails for typical input', function () {
    $svc = new EmailFinderService(
        new \App\Services\Smtp\Mocks\MockSmtpProber(),
        new \App\Services\Dedup\DeduplicationService(),
    );
    $cands = $svc->generateCandidates('Marie', 'Dupont', 'example.com');
    expect(count($cands))->toBeGreaterThanOrEqual(12);
    foreach ($cands as $email) {
        expect(filter_var($email, FILTER_VALIDATE_EMAIL))->not->toBeFalse();
    }
});

test('renderPattern handles accents + apostrophes', function () {
    $svc = new EmailFinderService(
        new \App\Services\Smtp\Mocks\MockSmtpProber(),
        new \App\Services\Dedup\DeduplicationService(),
    );
    $email = $svc->renderPattern('{first}.{last}@{domain}', 'Hélène', "O'Reilly", 'example.com');
    expect($email)->toBe('helene.oreilly@example.com');
});

/**
 * 🔴 Ce test existe parce que la CI ne tourne PAS sur la libc de production.
 *
 * `iconv('ASCII//TRANSLIT')` délègue à la libc : glibc (Ubuntu, la CI) rend
 * « Helene », musl (Alpine, l'IMAGE DE PRODUCTION) rend « H'el`ene ». La prod
 * fabriquait donc `hel`ene.…@…` pour tout prénom accentué — c'est-à-dire en
 * permanence sur une base française — pendant que la CI restait verte.
 *
 * On ne teste donc pas un prénom, mais la PROPRIÉTÉ : quoi qu'il arrive, la
 * partie locale d'une adresse ne contient que des lettres ASCII. Un test sur
 * un prénom se serait contenté d'un environnement ; celui-ci tient partout.
 */
test('aucun accent ne peut faire entrer de ponctuation dans une adresse', function () {
    $svc = new EmailFinderService(
        new \App\Services\Smtp\Mocks\MockSmtpProber(),
        new \App\Services\Dedup\DeduplicationService(),
    );

    $prenoms = ['Hélène', 'Frédéric', 'Benoît', 'François', 'Jérôme', 'Anaïs', 'Chloë', 'Æmilia'];

    foreach ($prenoms as $prenom) {
        $email = $svc->renderPattern('{first}.{last}@{domain}', $prenom, 'Dupont', 'example.com');
        $partieLocale = explode('@', $email)[0];

        expect($partieLocale)->toMatch('/^[a-z]+\.[a-z]+$/')
            ->and($email)->not->toContain('`')
            ->and($email)->not->toContain("'")
            ->and($email)->not->toContain('^')
            ->and(filter_var($email, FILTER_VALIDATE_EMAIL))->not->toBeFalse();
    }
});

test('les initiales aussi sont ASCII (motif {f}.{last})', function () {
    $svc = new EmailFinderService(
        new \App\Services\Smtp\Mocks\MockSmtpProber(),
        new \App\Services\Dedup\DeduplicationService(),
    );

    // Sous musl, `É` devenait «'E» : l'initiale extraite était une APOSTROPHE,
    // et l'adresse commençait par un caractère interdit.
    expect($svc->renderPattern('{f}.{last}@{domain}', 'Étienne', 'Martin', 'example.com'))
        ->toBe('e.martin@example.com');
});

test('detectPatternFromKnownEmails returns dominant pattern', function () {
    $svc = new EmailFinderService(
        new \App\Services\Smtp\Mocks\MockSmtpProber(),
        new \App\Services\Dedup\DeduplicationService(),
    );
    $pattern = $svc->detectPatternFromKnownEmails(
        ['jean.dupont@example.com', 'marie.martin@example.com', 'pierre.durand@example.com'],
        'example.com',
    );
    expect($pattern)->toBe('{first}.{last}@{domain}');
});

test('verifyEmail returns skipped_catchall_provider for big mail providers (Sprint H2)', function () {
    $svc = new EmailFinderService(
        new \App\Services\Smtp\Mocks\MockSmtpProber(),
        new \App\Services\Dedup\DeduplicationService(),
    );
    expect($svc->verifyEmail('jean@gmail.com'))->toBe('skipped_catchall_provider');
    expect($svc->verifyEmail('marie@orange.fr'))->toBe('skipped_catchall_provider');
});

test('verifyEmail returns unknown when no Hunter verifier wired (Sprint H2)', function () {
    $svc = new EmailFinderService(
        new \App\Services\Smtp\Mocks\MockSmtpProber(),
        new \App\Services\Dedup\DeduplicationService(),
    );
    expect($svc->verifyEmail('hi@acme-corp.fr'))->toBe('unknown');
});

test('verifyEmail returns invalid for malformed addresses (Sprint H2)', function () {
    $svc = new EmailFinderService(
        new \App\Services\Smtp\Mocks\MockSmtpProber(),
        new \App\Services\Dedup\DeduplicationService(),
    );
    expect($svc->verifyEmail('not-an-email'))->toBe('invalid');
});
