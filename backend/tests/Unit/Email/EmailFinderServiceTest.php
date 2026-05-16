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
