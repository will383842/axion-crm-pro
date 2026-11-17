<?php

use App\Console\Commands\CrmRoleApplicatifDiagnostic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * G4-0 — `crm:role-applicatif:diagnostic` : constat en LECTURE SEULE du rôle
 * sous lequel l'application parle à Postgres. Rien n'est écrit, aucun réglage
 * n'est changé ; la sortie ne porte que des booléens, des nombres et des noms
 * de rôles publics.
 */

/** @return array<string, string> */
function g40Diagnostic(): array
{
    $code = Artisan::call('crm:role-applicatif:diagnostic');
    expect($code)->toBe(0);

    $constat = [];
    foreach (preg_split('/\R/', trim(Artisan::output())) ?: [] as $ligne) {
        if (str_contains($ligne, '=')) {
            [$cle, $valeur] = explode('=', $ligne, 2);
            $constat[$cle] = $valeur;
        }
    }

    return $constat;
}

function g40BasculerSurRoleApp(): void
{
    config(['database.default' => 'pgsql_app']);
    DB::purge('pgsql_app');
}

afterEach(function () {
    config(['database.default' => 'pgsql']);
    DB::purge('pgsql_app');
});

test('G4-0 connexion par défaut propriétaire : superutilisateur actif', function () {
    config(['crm.db_app_role' => false]);

    $c = g40Diagnostic();

    expect($c['CONNEXION_DEFAUT_SUPERUSER'])->toBe('oui')
        ->and($c['DRAPEAU_CONFIG'])->toBe('false')
        ->and($c['VERDICT'])->toBe('superutilisateur_actif');
});

test('G4-0 connexion par défaut sur le rôle applicatif : rôle applicatif actif', function () {
    config(['crm.db_app_role' => true]);
    g40BasculerSurRoleApp();

    $c = g40Diagnostic();

    expect($c['CONNEXION_DEFAUT_SUPERUSER'])->toBe('non')
        ->and($c['CONNEXION_DEFAUT_EST_PROPRIETAIRE'])->toBe('non')
        ->and($c['DRAPEAU_CONFIG'])->toBe('true')
        ->and($c['VERDICT'])->toBe('role_applicatif_actif');
});

test('G4-0 drapeau vrai mais connexion propriétaire : incohérent', function () {
    config(['crm.db_app_role' => true]);

    expect(g40Diagnostic()['VERDICT'])->toBe('incoherent');
});

test('G4-0 la transaction est en lecture seule : une écriture lève 25006', function () {
    $commande = new CrmRoleApplicatifDiagnostic;

    $code = null;
    try {
        $commande->enLectureSeule(fn () => DB::statement('CREATE TEMP TABLE g40_essai (a int)'));
    } catch (Throwable $e) {
        $code = $e->getCode();
    }

    expect($code)->toBe('25006');

    // Test de source : la première instruction de la transaction est bien READ ONLY.
    $source = (string) file_get_contents(app_path('Console/Commands/CrmRoleApplicatifDiagnostic.php'));
    expect($source)->toContain('SET TRANSACTION READ ONLY')
        ->and($source)->toContain('enLectureSeule(');
});

test('G4-0 mot de passe applicatif vide : non configurée, sans tentative de connexion', function () {
    config(['database.connections.pgsql_app.password' => '']);
    DB::purge('pgsql_app');

    $c = g40Diagnostic();

    expect($c['ROLE_APP_CONNEXION'])->toBe('non_configuree')
        ->and(array_key_exists('pgsql_app', DB::getConnections()))->toBeFalse();
});

test('G4-0 connexion applicative refusée : « refusee » sans message d’erreur', function () {
    config(['database.connections.pgsql_app.password' => 'mauvais_mdp_g40_example']);
    DB::purge('pgsql_app');

    $code = Artisan::call('crm:role-applicatif:diagnostic');
    $sortie = Artisan::output();

    expect($code)->toBe(0)
        ->and($sortie)->toContain('ROLE_APP_CONNEXION=refusee')
        ->and($sortie)->not->toContain('mauvais_mdp_g40_example')
        ->and($sortie)->not->toContain('password authentication')
        ->and($sortie)->not->toContain('SQLSTATE');
});

test('G4-0 rien de secret : ni mot de passe, ni hôte, ni nom de variable de secret', function () {
    Artisan::call('crm:role-applicatif:diagnostic');
    $sortie = Artisan::output();

    $mdp = (string) config('database.connections.pgsql_app.password');
    $hote = (string) config('database.connections.pgsql_app.host');

    expect($mdp)->not->toBe('')
        ->and($sortie)->not->toContain($mdp)
        ->and($sortie)->not->toContain($hote)
        ->and($sortie)->not->toContain('DB_APP_PASSWORD');
});

test('G4-0 ne change aucun réglage et ne touche à aucun drapeau interdit', function () {
    $avant = [config('crm.db_app_role'), config('envoi.reel_actif'), config('database.default')];

    Artisan::call('crm:role-applicatif:diagnostic');

    expect([config('crm.db_app_role'), config('envoi.reel_actif'), config('database.default')])->toBe($avant);

    $source = (string) file_get_contents(app_path('Console/Commands/CrmRoleApplicatifDiagnostic.php'));
    foreach (['ENVOI_REEL_ACTIF', 'ORIGINES_LECTURE_ENABLED', 'CRM_GUIDE_LIEN_ENABLED', 'USINE_AGREGATS_CRM_ENABLED', 'CRM_DB_APP_ROLE_ENABLED'] as $drapeau) {
        expect($source)->not->toContain($drapeau);
    }
});

test('G4-0 workflow : manuel, lecture seule, trois services, rien de dangereux', function () {
    $chemin = base_path('../.github/workflows/diag-role-applicatif.yml');
    expect(is_file($chemin))->toBeTrue();

    $lignes = preg_split('/\R/', (string) file_get_contents($chemin)) ?: [];
    $texte = implode("\n", array_filter($lignes, fn (string $l) => ! str_starts_with(ltrim($l), '#')));

    expect($texte)->toContain('workflow_dispatch')
        ->and($texte)->toContain('default_transaction_read_only=on')
        ->and($texte)->toContain('crm:role-applicatif:diagnostic')
        ->and($texte)->not->toMatch('/^\s*push:/m')
        ->and($texte)->not->toMatch('/^\s*pull_request/m')
        ->and($texte)->not->toMatch('/^\s*schedule:/m');

    foreach (['api', 'horizon', 'scheduler'] as $service) {
        expect($texte)->toContain($service);
    }
    foreach (['migrate', 'restart', 'up -d', 'cat .env', 'printenv'] as $interdit) {
        expect($texte)->not->toContain($interdit);
    }
});
