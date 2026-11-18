<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\CodeCourriel\FauxCanalCodeConnexion;
use App\Services\Auth\TwoFactorService;
use Carbon\Carbon;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Pest\TestSuite;
use Tests\TestCase;

/*
 * Lot G3-D — codes de secours : consulter, régénérer, accepter à la connexion par code courriel.
 * HTTP réel avec cookie de session. Faux canal : aucun courriel ne part. Comptes fictifs en example.test.
 * Les tests d'écriture sont joués sous le propriétaire ET sous le rôle applicatif (SET LOCAL ROLE).
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-10 09:00:00', 'UTC'));
    config(['double_auth.actif' => true, 'envoi.reel_actif' => false, 'crm.mock_mode' => true]);
    FauxCanalCodeConnexion::reinitialiser();

    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.' . random_int(1, 254)]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => 'g3d-csrf'])->withHeader('X-CSRF-TOKEN', 'g3d-csrf');
    $this->withCredentials(); // sans cela, le client n'envoie aucun cookie aux requêtes JSON
});

afterEach(function () {
    Carbon::setTestNow();
});

const G3D_MDP = 'MotDePasseFactice12345!';

function g3dCompte(): User
{
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'g3d-' . Str::lower(Str::random(6)), 'name' => 'G3-D']);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'g3d-' . Str::lower(Str::random(6)) . '@example.test',
        'name' => 'G3-D',
        'password_hash' => password_hash(G3D_MDP, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
    DB::table('users')->where('id', $u->id)->update(['double_auth_methode' => 'email']);

    return $u->fresh();
}

/** Repart d'un navigateur vierge ; seuls les cookies donnés (nom => valeur chiffrée) sont conservés. */
function g3dNavigateur(array $cookies = []): void
{
    $t = test();
    $cas = TestSuite::getInstance()->test;
    (new ReflectionProperty($cas, 'defaultCookies'))->setValue($cas, []);
    $t->flushSession();
    $t->withSession(['_token' => 'g3d-csrf']);
    foreach ($cookies as $nom => $valeur) {
        $t->withCookie($nom, $valeur);
    }
    // `auth:sanctum` a fait de « sanctum » le garde par défaut du processus : le login suivant exige « web ».
    app('auth')->shouldUse('web');
    app('auth')->forgetGuards();
}

function g3dRetenirCookies(TestResponse $r): array
{
    $out = [];
    foreach ($r->baseResponse->headers->getCookies() as $c) {
        // Seul le cookie d'appareil est renvoyé au serveur : la session tient par le magasin partagé (comme en G3-B).
        // Le client de test chiffre lui-même ses cookies : on lui rend donc la valeur EN CLAIR.
        if ($c->getName() === 'axion_appareil' && (string) $c->getValue() !== '') {
            $valeur = CookieValuePrefix::remove(app('encrypter')->decrypt((string) $c->getValue(), false));
            test()->withCookie('axion_appareil', $valeur);
            $out['axion_appareil'] = $valeur;
        }
    }
    app('auth')->forgetGuards();

    return $out;
}

function g3dConnecter(User $u): TestResponse
{
    $r = test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => G3D_MDP]);
    g3dRetenirCookies($r);

    return $r;
}

/** Émet un code « geste » pour le compte connecté et le rend. */
function g3dCodeGeste(User $u): string
{
    test()->postJson('/api/v1/compte/code')->assertOk()->assertJsonPath('ok', true);

    return (string) FauxCanalCodeConnexion::dernierCode((string) $u->id);
}

function g3dSous(string $mode, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/** Pose un jeu de codes de secours (en clair rendu) sur le compte. @return list<string> */
function g3dPoserCodes(User $u, int $n = 10): array
{
    $codes = [];
    $hash = [];
    for ($i = 0; $i < $n; $i++) {
        $codes[] = Str::random(10);
        $hash[] = Hash::make($codes[$i]);
    }
    $u->forceFill(['totp_recovery_codes' => $hash])->save();

    return $codes;
}

function g3dBrut(User $u): ?string
{
    return DB::table('users')->where('id', $u->id)->value('totp_recovery_codes');
}

/** Connexion complète par le code reçu par courriel (compte « courriel ») ; la session franchit la double authentification. */
function g3dEntrer(User $u): void
{
    g3dConnecter($u);
    $code = (string) FauxCanalCodeConnexion::dernierCode((string) $u->id);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $code])->assertOk();
    app('auth')->forgetGuards();
}

test('1. régénérer avec le bon code : 10 codes, restants = 10, l\'ancien jeu est refusé à la connexion', function (string $mode) {
    $u = g3dCompte();
    $anciens = g3dPoserCodes($u, 4);
    g3dEntrer($u);
    test()->getJson('/api/v1/compte/codes-secours')->assertOk()->assertExactJson(['restants' => 4]);

    $code = g3dCodeGeste($u);
    $r = g3dSous($mode, fn () => test()->postJson('/api/v1/compte/codes-secours/regenerer', ['code' => $code]));
    $r->assertOk();
    $nouveaux = $r->json('recovery_codes');
    expect($nouveaux)->toHaveCount(10);
    foreach ($nouveaux as $c) {
        expect(strlen($c))->toBe(10);
    }
    test()->getJson('/api/v1/compte/codes-secours')->assertOk()->assertExactJson(['restants' => 10]);
    // Rien en clair en base.
    expect((string) g3dBrut($u))->not->toContain($nouveaux[0]);

    g3dNavigateur();
    g3dConnecter($u);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $anciens[0]])->assertStatus(422);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $nouveaux[0]])->assertOk();
})->with(['proprio', 'pgsql_app']);

test('2. un code de secours à la connexion : 200, restants = 9, le même code est refusé ensuite', function (string $mode) {
    $u = g3dCompte();
    $codes = g3dPoserCodes($u);
    g3dConnecter($u);
    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403);

    g3dSous($mode, fn () => test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $codes[3]])->assertOk()->assertJsonPath('ok', true));
    test()->getJson('/api/v1/dashboard/stats')->assertOk();
    test()->getJson('/api/v1/compte/codes-secours')->assertOk()->assertExactJson(['restants' => 9]);

    g3dNavigateur();
    g3dConnecter($u);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $codes[3]])->assertStatus(422);
    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403);
    // Un autre code du même jeu reste bon.
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $codes[4]])->assertOk();
})->with(['proprio', 'pgsql_app']);

test('3. mauvais code de geste : 422, le jeu est inchangé', function () {
    $u = g3dCompte();
    $codes = g3dPoserCodes($u, 3);
    g3dEntrer($u);
    $avant = g3dBrut($u);
    $juste = g3dCodeGeste($u);
    $faux = $juste === '000000' ? '000001' : '000000';

    test()->postJson('/api/v1/compte/codes-secours/regenerer', ['code' => $faux])->assertStatus(422)->assertJsonPath('errors.code.0', 'Code incorrect. Vérifiez-le et réessayez.');
    expect(g3dBrut($u))->toBe($avant);
    test()->postJson('/api/v1/compte/codes-secours/regenerer', [])->assertStatus(422);
    expect(g3dBrut($u))->toBe($avant);
    expect($codes)->toHaveCount(3);
});

test('4. cloisonnement : un compte ne voit, ne régénère et n\'utilise que ses codes', function () {
    $a = g3dCompte();
    $b = g3dCompte();
    g3dPoserCodes($a, 3);
    $codesB = g3dPoserCodes($b, 7);
    $avantB = g3dBrut($b);
    g3dEntrer($a);

    test()->getJson('/api/v1/compte/codes-secours')->assertOk()->assertExactJson(['restants' => 3]);
    $code = g3dCodeGeste($a);
    test()->postJson('/api/v1/compte/codes-secours/regenerer', ['code' => $code])->assertOk();
    expect(g3dBrut($b))->toBe($avantB);

    // Un code de secours de B, présenté par A (connexion neuve) : refusé, et B garde son jeu.
    g3dNavigateur();
    g3dConnecter($a);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $codesB[0]])->assertStatus(422);
    expect(g3dBrut($b))->toBe($avantB);
});

test('5. un compte avec TOTP qui régénère garde un TOTP valide ; un code de longueur 7 à 9 est refusé', function () {
    $u = g3dCompte();
    $u->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_enabled_at' => now()])->save();
    $u = $u->fresh();
    g3dPoserCodes($u, 2);
    g3dEntrer($u);
    $secret = DB::table('users')->where('id', $u->id)->value('totp_secret');

    $code = g3dCodeGeste($u);
    $r = test()->postJson('/api/v1/compte/codes-secours/regenerer', ['code' => $code]);
    $r->assertOk();
    expect(DB::table('users')->where('id', $u->id)->value('totp_secret'))->toBe($secret);
    expect(DB::table('users')->where('id', $u->id)->value('totp_enabled_at'))->not->toBeNull();
    // Les nouveaux codes sont aussi ceux que le TOTP accepte (même colonne).
    expect(app(TwoFactorService::class)->verify($u->fresh(), $r->json('recovery_codes.0')))->toBeTrue();

    g3dNavigateur();
    g3dConnecter($u);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => 'abcdefgh'])->assertStatus(422);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => str_repeat('x', 11)])->assertStatus(422);
});

test('6. compte qui n\'est pas en méthode courriel : 409 sur la régénération et sur un code de secours', function () {
    $u = g3dCompte();
    g3dPoserCodes($u, 2);
    DB::table('users')->where('id', $u->id)->update(['double_auth_methode' => null]);
    test()->actingAs($u->fresh(), 'web');
    app('auth')->shouldUse('web');

    test()->postJson('/api/v1/compte/codes-secours/regenerer', ['code' => '123456'])->assertStatus(409);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => Str::random(10)])->assertStatus(409);
});
