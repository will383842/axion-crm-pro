<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\CodeCourriel\CodesConnexionCourriel;
use App\Services\Auth\CodeCourriel\FauxCanalCodeConnexion;
use App\Services\Auth\CodeCourriel\TropDeRenvois;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Lot AUTH-RL — relectures de G3-A/G3-B : verrou sur le renvoi des codes, lien magique ⇒ requires_2fa,
 * session régénérée après le code. Comptes fictifs en example.test. Faux canal : aucun courriel ne part.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['double_auth.actif' => true, 'envoi.reel_actif' => false, 'crm.mock_mode' => true]);
    FauxCanalCodeConnexion::reinitialiser();

    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.' . random_int(1, 254)]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => 'rl-csrf'])->withHeader('X-CSRF-TOKEN', 'rl-csrf');
    $this->withCredentials();
});

const RL_MDP = 'MotDePasseFactice12345!';

/** Compte écrit en auto-commit (le second processus doit le voir) ; nettoyé par l'appelant. @return array{string, string} */
function rlCompteValide(): array
{
    $o = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $uid = (string) Str::uuid();
    $o->table('workspaces')->insert(['id' => $ws, 'name' => 'AUTH-RL', 'slug' => 'rl-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $o->table('users')->insert([
        'id' => $uid, 'email' => 'rl-' . substr($uid, 0, 8) . '@example.test', 'name' => 'AUTH-RL',
        'password_hash' => 'x', 'current_workspace_id' => $ws, 'double_auth_methode' => 'email',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$ws, $uid];
}

function rlNettoyer(string $ws, string $uid): void
{
    $o = DB::connection('pgsql_owner');
    $o->table('codes_connexion_courriel')->where('user_id', $uid)->delete();
    $o->table('users')->where('id', $uid)->delete();
    $o->table('workspaces')->where('id', $ws)->delete();
}

test('1. deux renvois simultanés : le second attend le premier, voit son code et le plafond est tenu', function (string $mode) {
    config(['double_auth.renvois_max' => 3]);
    [$ws, $uid] = rlCompteValide();
    $o = DB::connection('pgsql_owner');
    $script = (string) tempnam(sys_get_temp_dir(), 'rl-course-');
    try {
        // Deux codes déjà émis : il reste UN renvoi permis.
        foreach ([1, 2] as $_) {
            $o->statement("INSERT INTO codes_connexion_courriel (user_id, objet, code_hash, expire_le, cree_le) VALUES (?, 'connexion', 'h', now() + interval '10 minutes', now())", [$uid]);
        }

        // Le premier renvoi (second processus) tient le verrou du compte, signale, puis pose le 3e code et valide.
        $c = config('database.connections.pgsql_owner');
        $signal = 7300270;
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']);
        $code = <<<'PHP'
            <?php
            $pdo = new PDO(%s, %s, %s, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $pdo->query("SELECT pg_advisory_xact_lock(hashtextextended('code_courriel|' || %s, 0))");
            $pdo->query('SELECT pg_advisory_lock(%d)');
            usleep(2000000);
            $pdo->exec("INSERT INTO codes_connexion_courriel (user_id, objet, code_hash, expire_le, cree_le) VALUES (%s, 'connexion', 'h', now() + interval '10 minutes', now())");
            $pdo->commit();
            echo "VALIDE";
            PHP;
        file_put_contents($script, sprintf($code, var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) $c['password'], true), var_export($uid, true), $signal, var_export($uid, true)));
        $tubes = [];
        $processus = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
        expect($processus)->not->toBeFalse();

        $tient = false;
        for ($i = 0; $i < 200 && ! $tient; $i++) {
            $tient = (int) $o->selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND granted AND pid <> pg_backend_pid()", [$signal])->n > 0;
            if (! $tient) {
                usleep(50000);
            }
        }
        expect($tient)->toBeTrue('Le second processus n a pas pris le verrou.');

        $u = User::query()->findOrFail($uid);
        $refuse = false;
        $avant = microtime(true);
        // Point de sauvegarde : si le renvoi écrit (verrou absent), ses lignes ne bloquent pas le nettoyage.
        DB::beginTransaction();
        try {
            if ($mode === 'pgsql_app') {
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }
            app(CodesConnexionCourriel::class)->renvoyer($u, '198.51.100.1');
        } catch (TropDeRenvois) {
            $refuse = true;
        } finally {
            DB::rollBack();
            if ($mode === 'pgsql_app') {
                DB::statement('RESET ROLE');
            }
        }
        $duree = microtime(true) - $avant;

        $fin = trim((string) stream_get_contents($tubes[1]));
        $erreurs = (string) stream_get_contents($tubes[2]);
        fclose($tubes[1]);
        fclose($tubes[2]);
        proc_close($processus);

        expect($fin)->toBe('VALIDE', 'Le premier renvoi a échoué : ' . $erreurs)
            ->and($refuse)->toBeTrue('Le second renvoi aurait dû être refusé (plafond tenu).')
            ->and($duree)->toBeGreaterThan(1.0)
            ->and($o->table('codes_connexion_courriel')->where('user_id', $uid)->count())->toBe(3)
            ->and(FauxCanalCodeConnexion::dernierCode($uid))->toBeNull();
    } finally {
        @unlink($script);
        rlNettoyer($ws, $uid);
    }
})->with(['proprio', 'pgsql_app']);

function rlCompteHttp(?string $methode): User
{
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'rl-' . Str::lower(Str::random(6)), 'name' => 'AUTH-RL']);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'rl-' . Str::lower(Str::random(6)) . '@example.test',
        'name' => 'AUTH-RL',
        'password_hash' => password_hash(RL_MDP, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
    if ($methode !== null) {
        DB::table('users')->where('id', $u->id)->update(['double_auth_methode' => $methode]);
    }

    return $u->fresh();
}

function rlLien(User $u): string
{
    $jeton = Str::random(64);
    DB::table('magic_links')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $u->id, 'email' => $u->email,
        'token_hash' => hash('sha256', $jeton), 'ip' => '198.51.100.9',
        'expires_at' => now()->addMinutes(10), 'created_at' => now(),
    ]);

    return $jeton;
}

/** Identifiant de session posé par la réponse (cookie chiffré), ou null. */
function rlIdSession(TestResponse $r): ?string
{
    foreach ($r->baseResponse->headers->getCookies() as $c) {
        if ($c->getName() === config('session.cookie') && (string) $c->getValue() !== '') {
            return CookieValuePrefix::remove(app('encrypter')->decrypt((string) $c->getValue(), false));
        }
    }

    return null;
}

test('2. lien magique pour un compte « courriel » : requires_2fa vrai', function () {
    $courriel = rlCompteHttp('email');
    test()->postJson('/api/v1/auth/magic-link/verify', ['token' => rlLien($courriel)])
        ->assertOk()->assertJsonPath('requires_2fa', true);
});

test('2b. lien magique pour un compte sans double authentification : requires_2fa faux', function () {
    $simple = rlCompteHttp(null);
    test()->postJson('/api/v1/auth/magic-link/verify', ['token' => rlLien($simple)])
        ->assertOk()->assertJsonPath('requires_2fa', false);
});

test('3. la session est régénérée (identifiant différent) après la vérification du code', function () {
    $u = rlCompteHttp('email');
    $login = test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => RL_MDP]);
    $login->assertOk()->assertJsonPath('requires_2fa', true);
    $avant = rlIdSession($login);
    app('auth')->forgetGuards();

    // Le navigateur renvoie le cookie de session reçu à la connexion (comme en production).
    expect($avant)->not->toBeNull();
    test()->withCookie((string) config('session.cookie'), (string) $avant);
    $code = (string) FauxCanalCodeConnexion::dernierCode((string) $u->id);
    $r = test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $code]);
    $r->assertOk();
    $apres = rlIdSession($r);

    expect($avant)->not->toBeNull()->and($apres)->not->toBeNull()->and($apres)->not->toBe($avant);
});
