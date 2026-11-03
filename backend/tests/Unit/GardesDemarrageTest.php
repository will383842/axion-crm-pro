<?php

/**
 * GARDES DE DÉMARRAGE (S2, ADR-08) : en production, le démarrage est refusé si
 * le courrier d'authentification est mal réglé. Aucune valeur réelle ici.
 */

use App\Socle\Demarrage\GardesDemarrage;

// `Tests\TestCase` est déjà lié au dossier Unit par tests/Pest.php.

function reglerProduction(array $reglages = []): void
{
    config(array_merge([
        'mail.gardes_demarrage' => true,
        'mail.default' => 'smtp',
        'mail.auth_mailer' => 'ses',
        'crm.mock_mode' => false,
        'crm.mock_mail' => false,
    ], $reglages));
}

function verifierEnProduction(): void
{
    app()['env'] = 'production';
    try {
        GardesDemarrage::verifier(app());
    } finally {
        app()['env'] = 'testing';
    }
}

test('production bien réglée : démarrage accepté', function () {
    reglerProduction();

    verifierEnProduction();

    expect(true)->toBeTrue();
});

test('production sans mailer d authentification : démarrage refusé', function () {
    reglerProduction(['mail.auth_mailer' => '']);

    expect(fn () => verifierEnProduction())->toThrow(RuntimeException::class);
});

test('production avec un mailer d authentification inconnu : démarrage refusé', function () {
    reglerProduction(['mail.auth_mailer' => 'absent-du-catalogue']);

    expect(fn () => verifierEnProduction())->toThrow(RuntimeException::class);
});

test('production avec mailer d authentification identique au mailer par défaut : refusé', function () {
    reglerProduction(['mail.default' => 'smtp', 'mail.auth_mailer' => 'smtp']);

    expect(fn () => verifierEnProduction())->toThrow(RuntimeException::class);
});

test('production avec MOCK_MAIL allumé alors que l envoi réel l est : refusé', function () {
    reglerProduction(['crm.mock_mail' => true]);

    expect(fn () => verifierEnProduction())->toThrow(RuntimeException::class);
});

test('production avec MOCK_MODE allumé alors que l envoi réel l est : refusé', function () {
    reglerProduction(['crm.mock_mode' => true]);

    expect(fn () => verifierEnProduction())->toThrow(RuntimeException::class);
});

test('le message de refus ne contient aucune valeur de configuration', function () {
    reglerProduction(['mail.auth_mailer' => 'smtp', 'mail.default' => 'smtp']);

    try {
        verifierEnProduction();
        $message = '';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBe('')->and($message)->not->toContain('smtp');
});

test('drapeau éteint (défaut) : aucune garde, même mal réglé', function () {
    reglerProduction(['mail.gardes_demarrage' => false, 'mail.auth_mailer' => '']);

    verifierEnProduction();

    expect(true)->toBeTrue();
});

test('hors production : aucune garde', function () {
    reglerProduction(['mail.auth_mailer' => '']);

    GardesDemarrage::verifier(app());

    expect(true)->toBeTrue();
});

test('production avec un mailer d authentification de transport log : refusé sans valeur de configuration', function () {
    reglerProduction([
        'mail.auth_mailer' => 'authlog',
        'mail.mailers.authlog' => ['transport' => 'log'],
    ]);

    $message = '';
    try {
        verifierEnProduction();
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBe('')->and($message)->not->toContain('authlog');
});

test('production avec un mailer d authentification failover contenant log : refusé', function () {
    reglerProduction([
        'mail.auth_mailer' => 'authbasc',
        'mail.mailers.authbasc' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
    ]);

    expect(fn () => verifierEnProduction())->toThrow(RuntimeException::class);
});

test('production avec MOCK_MODE allumé : refusé même avec un transport sans envoi', function () {
    reglerProduction([
        'mail.auth_mailer' => 'authlog',
        'mail.mailers.authlog' => ['transport' => 'array'],
        'crm.mock_mode' => true,
    ]);

    expect(fn () => verifierEnProduction())->toThrow(RuntimeException::class);
});
