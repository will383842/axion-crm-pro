<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\CodeCourriel\CanalCodeConnexion;
use App\Services\Auth\CodeCourriel\CodesConnexionCourriel;
use App\Services\Auth\CodeCourriel\FauxCanalCodeConnexion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Lot G3-B — connexion par code courriel (routes verifier / renvoyer, gardes, requires_2fa, /auth/me).
 * HTTP réel avec cookie de session (modèle Nr22DeuxFacteursTest). Faux canal : aucun courriel ne part.
 * Comptes fictifs en example.test.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-10 09:00:00', 'UTC'));
    config(['double_auth.actif' => true, 'envoi.reel_actif' => false, 'crm.mock_mode' => true]);
    FauxCanalCodeConnexion::reinitialiser();

    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $csrf = 'g3b-csrf';
    $this->withServerVariables(['REMOTE_ADDR' => '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254)]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf);
});

afterEach(function () {
    Carbon::setTestNow();
});

const G3B_MDP = 'MotDePasseFactice12345!';

function g3bCompte(?string $methode, bool $totp = false, bool $premierPassage = true): User
{
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'g3b-' . Str::lower(Str::random(6)), 'name' => 'G3-B']);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'g3b-' . Str::lower(Str::random(6)) . '@example.test',
        'name' => 'G3-B',
        'password_hash' => password_hash(G3B_MDP, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => $premierPassage ? now() : null,
        'totp_secret' => $totp ? 'JBSWY3DPEHPK3PXP' : null,
        'totp_enabled_at' => $totp ? now() : null,
    ]);
    if ($methode !== null) {
        DB::table('users')->where('id', $u->id)->update(['double_auth_methode' => $methode]);
    }

    return $u->fresh();
}

/** Connexion HTTP réelle : le cookie de session est repris pour les appels suivants. */
function g3bConnecter(User $u): TestResponse
{
    $t = test();
    $r = $t->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => G3B_MDP]);
    foreach ($r->baseResponse->headers->getCookies() as $c) {
        if ((string) $c->getValue() !== '') {
            $t->withCookie($c->getName(), $c->getValue());
        }
    }
    app('auth')->forgetGuards();

    return $r;
}

test('1. compte courriel : login émet un code, le tableau de bord reste fermé jusqu\'au bon code', function () {
    $u = g3bCompte('email');
    $r = g3bConnecter($u);
    expect($r->status())->toBe(200)->and($r->json('requires_2fa'))->toBeTrue();

    $code = FauxCanalCodeConnexion::dernierCode((string) $u->id);
    expect($code)->toMatch('/^\d{6}$/');

    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403)->assertJsonPath('error', 'two_factor_required');
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $code, 'appareil_confiance' => true])
        ->assertOk()->assertJsonPath('ok', true);
    test()->getJson('/api/v1/dashboard/stats')->assertOk();
});

test('2. mauvais code : 422 et rien ne s\'ouvre', function () {
    $u = g3bCompte('email');
    g3bConnecter($u);
    $juste = FauxCanalCodeConnexion::dernierCode((string) $u->id);
    $faux = $juste === '000000' ? '000001' : '000000';

    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $faux])
        ->assertStatus(422)->assertJsonPath('errors.code.0', 'Code incorrect. Vérifiez-le et réessayez.');
    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403);
});

test('3. drapeau éteint : connexion comme avant, /auth/me ne dit jamais email, verifier = 409', function () {
    config(['double_auth.actif' => false]);
    $u = g3bCompte('email');
    $r = g3bConnecter($u);
    expect($r->status())->toBe(200)->and($r->json('requires_2fa'))->toBeFalse();
    expect(FauxCanalCodeConnexion::dernierCode((string) $u->id))->toBeNull();
    expect(DB::table('codes_connexion_courriel')->count())->toBe(0);

    test()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('two_factor_method', null);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => '123456'])
        ->assertStatus(409)->assertJsonPath('error', 'methode_non_courriel');
    test()->getJson('/api/v1/dashboard/stats')->assertOk();
});

test('4. compte TOTP : inchangé, aucun code courriel émis', function () {
    $u = g3bCompte(null, totp: true);
    $r = g3bConnecter($u);
    expect($r->json('requires_2fa'))->toBeTrue();
    expect(DB::table('codes_connexion_courriel')->count())->toBe(0);
    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403)->assertJsonPath('error', 'two_factor_required');
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => '123456'])->assertStatus(409);
});

test('5. /auth/me : two_factor_method vaut email, totp ou null', function () {
    // courriel (drapeau allumé)
    g3bConnecter(g3bCompte('email'));
    test()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('two_factor_method', 'email');
});

test('5b. /auth/me : totp et null', function () {
    g3bConnecter(g3bCompte(null, totp: true));
    test()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('two_factor_method', 'totp');
});

test('5c. /auth/me : null sans méthode', function () {
    g3bConnecter(g3bCompte(null));
    test()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('two_factor_method', null);
});

test('6. plafond de 3 codes par 10 min (connexion comprise) : le renvoi suivant rend 429', function () {
    $u = g3bCompte('email');
    g3bConnecter($u); // 1 code émis à la connexion
    test()->postJson('/api/v1/auth/code-email/renvoyer')->assertOk()->assertJsonPath('renvois_restants', 1);
    test()->postJson('/api/v1/auth/code-email/renvoyer')->assertOk()->assertJsonPath('renvois_restants', 0);
    test()->postJson('/api/v1/auth/code-email/renvoyer')->assertStatus(429)->assertJsonPath('error', 'trop_de_renvois');
});

test('7. cloisonnement : connecté comme A, le code de B est refusé', function () {
    $a = g3bCompte('email');
    $b = g3bCompte('email');
    // B reçoit un code (connexion de B, puis on repart d'une session propre pour A).
    (new CodesConnexionCourriel)->emettre($b, 'connexion', null);
    $codeB = FauxCanalCodeConnexion::dernierCode((string) $b->id);

    g3bConnecter($a);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $codeB])->assertStatus(422);
    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403);
});

test('8. les deux routes sont atteignables avant le premier passage', function () {
    $u = g3bCompte('email', premierPassage: false);
    g3bConnecter($u);
    $code = FauxCanalCodeConnexion::dernierCode((string) $u->id);

    test()->postJson('/api/v1/auth/code-email/renvoyer')->assertOk();
    $code = FauxCanalCodeConnexion::dernierCode((string) $u->id);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $code])->assertOk();
});

test('9. échec du canal : la connexion répond 200 requires_2fa, renvoyer répond 503', function () {
    $u = g3bCompte('email');
    app()->instance(CanalCodeConnexion::class, new class implements CanalCodeConnexion
    {
        public function envoyer(User $u, string $code, string $objet): void
        {
            throw new RuntimeException('panne');
        }

        public function estReel(): bool
        {
            return false;
        }
    });
    $r = g3bConnecter($u);
    expect($r->status())->toBe(200)->and($r->json('requires_2fa'))->toBeTrue();
    test()->postJson('/api/v1/auth/code-email/renvoyer')->assertStatus(503)->assertJsonPath('error', 'canal_indisponible');
});

test('10. connexion sous le rôle applicatif : aucun 42501', function () {
    $u = g3bCompte('email');
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        $r = g3bConnecter($u);
        expect($r->status())->toBe(200)->and($r->json('requires_2fa'))->toBeTrue();
        $code = FauxCanalCodeConnexion::dernierCode((string) $u->id);
        expect($code)->toMatch('/^\d{6}$/');
        test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $code])->assertOk();
    } finally {
        DB::statement('RESET ROLE');
    }
});
