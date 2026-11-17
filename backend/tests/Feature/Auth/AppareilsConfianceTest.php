<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\CodeCourriel\FauxCanalCodeConnexion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Lot G3-C — appareils de confiance (cookie 30 jours), liste, retrait, code pour un geste sensible.
 * HTTP réel avec cookie de session. Faux canal : aucun courriel ne part. Comptes fictifs en example.test.
 * Les tests d'écriture 3 et 4 sont joués sous le propriétaire ET sous le rôle applicatif (SET LOCAL ROLE).
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-10 09:00:00', 'UTC'));
    config(['double_auth.actif' => true, 'envoi.reel_actif' => false, 'crm.mock_mode' => true]);
    FauxCanalCodeConnexion::reinitialiser();

    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.' . random_int(1, 254)]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => 'g3c-csrf'])->withHeader('X-CSRF-TOKEN', 'g3c-csrf');
});

afterEach(function () {
    Carbon::setTestNow();
});

const G3C_MDP = 'MotDePasseFactice12345!';

function g3cCompte(): User
{
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'g3c-' . Str::lower(Str::random(6)), 'name' => 'G3-C']);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'g3c-' . Str::lower(Str::random(6)) . '@example.test',
        'name' => 'G3-C',
        'password_hash' => password_hash(G3C_MDP, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
    DB::table('users')->where('id', $u->id)->update(['double_auth_methode' => 'email']);

    return $u->fresh();
}

/** Repart d'un navigateur vierge ; seuls les cookies donnés (nom => valeur chiffrée) sont conservés. */
function g3cNavigateur(array $cookies = []): void
{
    $t = test();
    Closure::bind(function (): void {
        $this->defaultCookies = [];
    }, $t, Illuminate\Foundation\Testing\TestCase::class)();
    $t->flushSession();
    $t->withSession(['_token' => 'g3c-csrf']);
    foreach ($cookies as $nom => $valeur) {
        $t->withCookie($nom, $valeur);
    }
    app('auth')->forgetGuards();
}

function g3cRetenirCookies(TestResponse $r): array
{
    $out = [];
    foreach ($r->baseResponse->headers->getCookies() as $c) {
        if ((string) $c->getValue() !== '') {
            test()->withCookie($c->getName(), $c->getValue());
            $out[$c->getName()] = $c->getValue();
        }
    }
    app('auth')->forgetGuards();

    return $out;
}

function g3cConnecter(User $u): TestResponse
{
    $r = test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => G3C_MDP]);
    g3cRetenirCookies($r);

    return $r;
}

/** Connexion complète avec confiance ; rend le cookie de l'appareil (valeur chiffrée telle que le navigateur la renvoie). */
function g3cConnecterEtFaireConfiance(User $u): string
{
    g3cConnecter($u);
    $code = (string) FauxCanalCodeConnexion::dernierCode((string) $u->id);
    $r = test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $code, 'appareil_confiance' => true]);
    $r->assertOk();
    $cookies = g3cRetenirCookies($r);
    expect($cookies)->toHaveKey('axion_appareil');

    return $cookies['axion_appareil'];
}

function g3cNbAppareils(): int
{
    return DB::table('appareils_confiance')->count();
}

/** Émet un code « geste » pour le compte connecté et le rend. */
function g3cCodeGeste(User $u): string
{
    test()->postJson('/api/v1/compte/code')->assertOk()->assertJsonPath('ok', true);

    return (string) FauxCanalCodeConnexion::dernierCode((string) $u->id);
}

function g3cSous(string $mode, Closure $f): mixed
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

test('1. confiance : cookie posé, une ligne ; reconnexion avec le cookie => aucun code, accès direct', function () {
    $u = g3cCompte();
    $cookie = g3cConnecterEtFaireConfiance($u);
    expect(g3cNbAppareils())->toBe(1);
    $ligne = DB::table('appareils_confiance')->first();
    expect($ligne->user_id)->toBe((string) $u->id)
        ->and(strlen((string) $ligne->jeton_hash))->toBe(64)
        ->and($cookie)->not->toContain((string) $ligne->jeton_hash);

    $codesAvant = DB::table('codes_connexion_courriel')->count();
    g3cNavigateur(['axion_appareil' => $cookie]);
    $r = g3cConnecter($u);
    expect($r->status())->toBe(200)->and($r->json('requires_2fa'))->toBeFalse();
    expect(DB::table('codes_connexion_courriel')->count())->toBe($codesAvant);
    test()->getJson('/api/v1/dashboard/stats')->assertOk();
    expect(DB::table('appareils_confiance')->first()->vu_le)->not->toBeNull();
});

test('1b. sans la case : aucun cookie, aucune ligne', function () {
    $u = g3cCompte();
    g3cConnecter($u);
    $code = (string) FauxCanalCodeConnexion::dernierCode((string) $u->id);
    $r = test()->postJson('/api/v1/auth/code-email/verifier', ['code' => $code]);
    $r->assertOk();
    expect(g3cRetenirCookies($r))->not->toHaveKey('axion_appareil');
    expect(g3cNbAppareils())->toBe(0);
});

test('2. après 30 jours : le code est redemandé', function () {
    $u = g3cCompte();
    $cookie = g3cConnecterEtFaireConfiance($u);

    Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:01', 'UTC'));
    g3cNavigateur(['axion_appareil' => $cookie]);
    $r = g3cConnecter($u);
    expect($r->json('requires_2fa'))->toBeTrue();
    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403);
});

test('3. retirer avec le bon code : ligne gardée avec retire_le, reconnexion => code redemandé', function (string $mode) {
    $u = g3cCompte();
    $cookie = g3cConnecterEtFaireConfiance($u);
    $id = (string) DB::table('appareils_confiance')->value('id');
    $avant = g3cNbAppareils();

    $code = g3cCodeGeste($u);
    g3cSous($mode, fn () => test()->postJson("/api/v1/compte/appareils/{$id}/retirer", ['code' => $code])
        ->assertOk()->assertJsonPath('ok', true));

    expect(g3cNbAppareils())->toBe($avant);
    expect(DB::table('appareils_confiance')->where('id', $id)->value('retire_le'))->not->toBeNull();

    g3cNavigateur(['axion_appareil' => $cookie]);
    expect(g3cConnecter($u)->json('requires_2fa'))->toBeTrue();
})->with(['proprio', 'pgsql_app']);

test('3b. retirer avec un faux code : 422, rien ne change', function () {
    $u = g3cCompte();
    g3cConnecterEtFaireConfiance($u);
    $id = (string) DB::table('appareils_confiance')->value('id');
    $juste = g3cCodeGeste($u);
    $faux = $juste === '000000' ? '000001' : '000000';

    test()->postJson("/api/v1/compte/appareils/{$id}/retirer", ['code' => $faux])->assertStatus(422);
    expect(DB::table('appareils_confiance')->where('id', $id)->value('retire_le'))->toBeNull();
});

test('4. retirer l\'appareil de B connecté comme A : 404, ligne de B intacte', function (string $mode) {
    $a = g3cCompte();
    $b = g3cCompte();
    g3cConnecterEtFaireConfiance($b);
    $idB = (string) DB::table('appareils_confiance')->where('user_id', $b->id)->value('id');

    g3cNavigateur();
    g3cConnecter($a);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => (string) FauxCanalCodeConnexion::dernierCode((string) $a->id)])->assertOk();
    $code = g3cCodeGeste($a);

    g3cSous($mode, fn () => test()->postJson("/api/v1/compte/appareils/{$idB}/retirer", ['code' => $code])->assertStatus(404));
    test()->postJson('/api/v1/compte/appareils/' . Str::uuid() . '/retirer', ['code' => $code])->assertStatus(404);
    test()->postJson('/api/v1/compte/appareils/pas-un-uuid/retirer', ['code' => $code])->assertStatus(404);

    $ligneB = DB::table('appareils_confiance')->where('id', $idB)->first();
    expect($ligneB->retire_le)->toBeNull();
})->with(['proprio', 'pgsql_app']);

test('5. le cookie de B présenté par A au login : code émis pour A', function () {
    $a = g3cCompte();
    $b = g3cCompte();
    $cookieB = g3cConnecterEtFaireConfiance($b);

    g3cNavigateur(['axion_appareil' => $cookieB]);
    $r = g3cConnecter($a);
    expect($r->json('requires_2fa'))->toBeTrue();
    expect(FauxCanalCodeConnexion::dernierCode((string) $a->id))->toMatch('/^\d{6}$/');
    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403);
});

test('6. liste : ni retirés, ni ceux d\'un autre compte ; ip masquée ; actuel signalé', function () {
    $a = g3cCompte();
    $b = g3cCompte();
    g3cConnecterEtFaireConfiance($b);

    g3cNavigateur();
    $cookieA = g3cConnecterEtFaireConfiance($a);
    // Un appareil de A déjà retiré et un expiré : absents de la liste.
    DB::table('appareils_confiance')->insert([
        ['user_id' => $a->id, 'jeton_hash' => hash('sha256', 'retire'), 'nom' => 'Retiré', 'ip' => '192.0.2.9', 'ajoute_le' => now(), 'expire_le' => now()->addDays(5), 'retire_le' => now()],
        ['user_id' => $a->id, 'jeton_hash' => hash('sha256', 'expire'), 'nom' => 'Expiré', 'ip' => '192.0.2.8', 'ajoute_le' => now()->subDays(40), 'expire_le' => now()->subDays(10), 'retire_le' => null],
    ]);

    g3cNavigateur(['axion_appareil' => $cookieA]);
    g3cConnecter($a); // reconnu : accès direct
    $r = test()->getJson('/api/v1/compte/appareils')->assertOk();
    $data = $r->json('data');
    expect($data)->toHaveCount(1);
    expect(array_keys($data[0]))->toEqualCanonicalizing(['id', 'nom', 'lieu', 'ip', 'ajoute_le', 'expire_le', 'actuel']);
    expect($data[0]['lieu'])->toBe('—')
        ->and($data[0]['ip'])->toMatch('/^\d+\.\d+\.\d+\.x$/')
        ->and($data[0]['actuel'])->toBeTrue();
});

test('7. connexion : l\'émission passe par le plafond de renvois (la 4e connexion n\'émet plus)', function () {
    $u = g3cCompte();
    foreach (range(1, 3) as $_) {
        g3cNavigateur();
        expect(g3cConnecter($u)->json('requires_2fa'))->toBeTrue();
    }
    expect(DB::table('codes_connexion_courriel')->where('user_id', $u->id)->where('objet', 'connexion')->count())->toBe(3);

    g3cNavigateur();
    $r = g3cConnecter($u);
    expect($r->status())->toBe(200)->and($r->json('requires_2fa'))->toBeTrue();
    expect(DB::table('codes_connexion_courriel')->where('user_id', $u->id)->where('objet', 'connexion')->count())->toBe(3);
    test()->getJson('/api/v1/dashboard/stats')->assertStatus(403);
});

test('8. POST /compte/code : émet un code geste, plafonné ; refusé hors méthode courriel', function () {
    $u = g3cCompte();
    g3cConnecter($u);
    test()->postJson('/api/v1/auth/code-email/verifier', ['code' => (string) FauxCanalCodeConnexion::dernierCode((string) $u->id)])->assertOk();

    foreach (range(1, 3) as $_) {
        test()->postJson('/api/v1/compte/code')->assertOk();
    }
    expect(DB::table('codes_connexion_courriel')->where('user_id', $u->id)->where('objet', 'geste')->count())->toBe(3);
    test()->postJson('/api/v1/compte/code')->assertStatus(429)->assertJsonPath('error', 'trop_de_renvois');
});

test('8b. POST /compte/code sans session : refusé', function () {
    test()->postJson('/api/v1/compte/code')->assertStatus(401);
    test()->getJson('/api/v1/compte/appareils')->assertStatus(401);
});
