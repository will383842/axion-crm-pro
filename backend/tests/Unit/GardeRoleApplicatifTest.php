<?php

/**
 * G4-A — en production, la garde (éteinte par défaut) refuse de démarrer sans le rôle applicatif.
 * Aucune valeur réelle ici : noms et mots de passe fictifs.
 */

use App\Socle\Demarrage\GardeRoleApplicatif;

// `Tests\TestCase` est déjà lié au dossier Unit par tests/Pest.php.

const GRA_PROPRIETAIRE = 'proprietaire_fictif';
const GRA_APPLICATIF = 'applicatif_fictif';
const GRA_MOT_DE_PASSE = 'mdp-fictif-gra-9731';

function graReglages(array $reglages = []): void
{
    config(array_merge([
        'crm.garde_role_applicatif' => true,
        'crm.db_app_role' => true,
        'database.connections.pgsql.username' => GRA_APPLICATIF,
        'database.connections.pgsql_owner.username' => GRA_PROPRIETAIRE,
        'database.connections.pgsql_app.password' => GRA_MOT_DE_PASSE,
    ], $reglages));
}

function graVerifier(string $env = 'production', ?array $argv = null): void
{
    $avant = $_SERVER['argv'] ?? null;
    if ($argv !== null) {
        $_SERVER['argv'] = $argv;
    }
    app()['env'] = $env;
    try {
        GardeRoleApplicatif::verifier(app());
    } finally {
        app()['env'] = 'testing';
        if ($avant === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $avant;
        }
    }
}

test('1. hors production : jamais de refus', function () {
    graReglages(['crm.db_app_role' => false, 'database.connections.pgsql_app.password' => '']);

    foreach (['local', 'testing', 'staging'] as $env) {
        graVerifier($env);
    }

    expect(true)->toBeTrue();
});

test('2. production, garde éteinte : jamais de refus, quel que soit db_app_role', function () {
    graReglages(['crm.garde_role_applicatif' => false, 'crm.db_app_role' => false, 'database.connections.pgsql_app.password' => '']);

    graVerifier();

    expect(config('crm.garde_role_applicatif'))->toBeFalse();
});

test('2b. le drapeau de la garde est éteint par défaut', function () {
    $config = require base_path('config/crm.php');

    expect($config['garde_role_applicatif'])->toBeFalse();
});

test('3. production, garde allumée : trois refus précis, et un cas accepté', function () {
    graReglages(['crm.db_app_role' => false]);
    expect(fn () => graVerifier())->toThrow(RuntimeException::class, 'CRM_DB_APP_ROLE_ENABLED');

    graReglages(['database.connections.pgsql.username' => GRA_PROPRIETAIRE]);
    expect(fn () => graVerifier())->toThrow(RuntimeException::class, 'propriétaire');

    graReglages(['database.connections.pgsql_app.password' => '']);
    expect(fn () => graVerifier())->toThrow(RuntimeException::class, 'DB_APP_PASSWORD');

    graReglages();
    graVerifier();
    expect(true)->toBeTrue();
});

test('4. les messages ne contiennent aucune valeur configurée', function () {
    $cas = [
        ['crm.db_app_role' => false],
        ['database.connections.pgsql.username' => GRA_PROPRIETAIRE],
        ['database.connections.pgsql_app.password' => ''],
    ];
    foreach ($cas as $reglages) {
        graReglages($reglages);
        try {
            graVerifier();
            $message = '';
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }
        expect($message)->not->toBe('')
            ->and($message)->not->toContain(GRA_PROPRIETAIRE)
            ->and($message)->not->toContain(GRA_APPLICATIF)
            ->and($message)->not->toContain(GRA_MOT_DE_PASSE);
    }
});

test('5. migrate --database=pgsql_owner passe ; migrate sans option, ou une autre commande, ne passe pas', function () {
    graReglages(['crm.db_app_role' => false]);

    graVerifier('production', ['artisan', 'migrate', '--database=pgsql_owner', '--force']);
    expect(true)->toBeTrue();

    expect(fn () => graVerifier('production', ['artisan', 'migrate', '--force']))->toThrow(RuntimeException::class);
    expect(fn () => graVerifier('production', ['artisan', 'migrate', '--database=pgsql']))->toThrow(RuntimeException::class);
    expect(fn () => graVerifier('production', ['artisan', 'db:seed', '--database=pgsql_owner']))->toThrow(RuntimeException::class);
});

test('6. aucune requête SQL dans la source', function () {
    $source = (string) file_get_contents(base_path('app/Socle/Demarrage/GardeRoleApplicatif.php'));

    expect($source)->not->toContain('DB::')
        ->and(strtolower($source))->not->toContain('select')
        ->and($source)->not->toContain('PDO');
});
