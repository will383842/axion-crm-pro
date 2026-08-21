<?php

use Database\Seeders\OwnerUserSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Seed les rôles d'abord (dépendance owner role)
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * Pose des variables d'environnement VISIBLES PAR `env()`, puis restaure l'état
 * initial — quoi qu'il arrive.
 *
 * ⚠️ `putenv()` SEUL ne suffit pas. `OwnerUserSeeder::readEnv()` interroge
 * d'abord `env()` et ne retombe sur `getenv()` que si `env()` ne rend rien.
 * Or `.env` définit déjà `OWNER_INITIAL_EMAIL=williamsjullin@gmail.com`, valeur
 * chargée dans `$_ENV`/`$_SERVER` au boot : `env()` la renvoyait, la valeur
 * `putenv()` du test n'était jamais consultée, et le seeder créait l'owner par
 * défaut au lieu de celui attendu. On écrit donc sur les trois canaux que lit
 * la chaîne Dotenv → `env()` (ServerConst, EnvConst, Putenv).
 *
 * Passer `null` en valeur SUPPRIME la variable des trois canaux.
 *
 * @param  array<string, string|null>  $vars
 */
function withOwnerEnv(array $vars, Closure $fn): void
{
    $previous = [];
    foreach (array_keys($vars) as $key) {
        $previous[$key] = [
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'putenv' => getenv($key),
        ];
    }

    $apply = function (string $key, string|false|null $value): void {
        if ($value === null || $value === false) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    };

    foreach ($vars as $key => $value) {
        $apply($key, $value);
    }

    try {
        $fn();
    } finally {
        foreach ($previous as $key => $state) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($state['env'] !== null) {
                $_ENV[$key] = $state['env'];
            }
            if ($state['server'] !== null) {
                $_SERVER[$key] = $state['server'];
            }
            if ($state['putenv'] !== false) {
                putenv("{$key}={$state['putenv']}");
            }
        }
    }
}

test('OwnerUserSeeder crée workspace + owner avec password depuis env', function () {
    withOwnerEnv([
        'OWNER_INITIAL_EMAIL' => 'owner@test.local',
        'OWNER_INITIAL_NAME' => 'Owner Test',
        'OWNER_INITIAL_PASSWORD' => 'ExplicitPassword!9876',
    ], function () {
        $this->seed(OwnerUserSeeder::class);

        $workspace = DB::table('workspaces')->where('slug', 'axion-ia')->first();
        expect($workspace)->not->toBeNull();

        $user = DB::table('users')->where('email', 'owner@test.local')->first();
        expect($user)->not->toBeNull();
        expect($user->name)->toBe('Owner Test');
        expect($user->password_hash)->not->toBeNull();
        expect($user->password_hash)->not->toBe('');
        expect(Hash::check('ExplicitPassword!9876', $user->password_hash))->toBeTrue();
        expect($user->current_workspace_id)->toBe($workspace->id);
    });
});

/**
 * 🔴 CE TEST GARDAIT UN DÉFAUT. Il exigeait que le seeder ÉCRIVE le mot de passe
 * généré dans `storage/app/private/seeders/owner-initial-password.txt` — en clair,
 * et sans le `chmod 0600` que le code annonçait (Flysystem n'en pose aucun sans
 * option `visibility` : le fichier prenait le umask, soit 0644, lisible par tout
 * utilisateur du serveur). Le même secret partait aussi sur la sortie standard du
 * seed, donc dans les journaux de CI et de Docker.
 * Mesuré le 2026-08-19 (audit 360, F35-008).
 *
 * Le seeder n'écrit plus AUCUN secret. Sans `OWNER_INITIAL_PASSWORD`, le compte
 * est créé sans mot de passe, et l'opérateur en pose un avec
 * `infra/scripts/definir-mot-de-passe-crm.sh` — un chemin où le secret ne touche
 * ni le disque, ni `argv`, ni un journal.
 */
test('OwnerUserSeeder n écrit AUCUN secret quand OWNER_INITIAL_PASSWORD est vide', function () {
    Storage::fake('local');

    withOwnerEnv([
        'OWNER_INITIAL_EMAIL' => 'owner2@test.local',
        'OWNER_INITIAL_PASSWORD' => null,  // unset sur les trois canaux
    ], function () {
        $this->seed(OwnerUserSeeder::class);

        $user = DB::table('users')->where('email', 'owner2@test.local')->first();
        expect($user)->not->toBeNull();

        // Le compte existe, mais SANS mot de passe : il n'y a rien à écrire
        // quelque part « au cas où ».
        expect($user->password_hash)->toBeNull();

        // Et surtout : aucun fichier de secret, nulle part.
        Storage::disk('local')->assertMissing('seeders/owner-initial-password.txt');
    });
});

test('OwnerUserSeeder pose bien le mot de passe quand OWNER_INITIAL_PASSWORD est fourni', function () {
    Storage::fake('local');

    withOwnerEnv([
        'OWNER_INITIAL_EMAIL' => 'owner2b@test.local',
        'OWNER_INITIAL_PASSWORD' => 'UnMotDePasseFourni!2026',
    ], function () {
        $this->seed(OwnerUserSeeder::class);

        $user = DB::table('users')->where('email', 'owner2b@test.local')->first();
        expect($user->password_hash)->not->toBeNull();
        expect(Hash::check('UnMotDePasseFourni!2026', $user->password_hash))->toBeTrue();

        // Même dans ce cas, rien n'est écrit sur disque.
        Storage::disk('local')->assertMissing('seeders/owner-initial-password.txt');
    });
});

test('OwnerUserSeeder est idempotent (re-run ne casse pas)', function () {
    withOwnerEnv([
        'OWNER_INITIAL_EMAIL' => 'owner3@test.local',
        'OWNER_INITIAL_PASSWORD' => 'FirstPassword!2345',
    ], function () {
        $this->seed(OwnerUserSeeder::class);
        $userBefore = DB::table('users')->where('email', 'owner3@test.local')->first();
        expect($userBefore)->not->toBeNull();

        // Re-run
        $this->seed(OwnerUserSeeder::class);
        $userAfter = DB::table('users')->where('email', 'owner3@test.local')->first();

        expect($userAfter->id)->toBe($userBefore->id);
        expect($userAfter->password_hash)->toBe($userBefore->password_hash);

        // Un seul workspace axion-ia
        expect(DB::table('workspaces')->where('slug', 'axion-ia')->count())->toBe(1);
    });
});

test('OwnerUserSeeder pose pivot user_workspaces avec role owner', function () {
    withOwnerEnv([
        'OWNER_INITIAL_EMAIL' => 'owner4@test.local',
        'OWNER_INITIAL_PASSWORD' => 'PivotTest!8821',
    ], function () {
        $this->seed(OwnerUserSeeder::class);

        $user = DB::table('users')->where('email', 'owner4@test.local')->first();
        $workspace = DB::table('workspaces')->where('slug', 'axion-ia')->first();
        expect($user)->not->toBeNull();
        expect($workspace)->not->toBeNull();

        $pivot = DB::table('user_workspaces')
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        expect($pivot)->not->toBeNull();
        expect($pivot->role_slug)->toBe('owner');
    });
});
