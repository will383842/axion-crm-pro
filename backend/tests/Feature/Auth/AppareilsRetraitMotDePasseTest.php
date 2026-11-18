<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\CodeCourriel\AppareilsConfiance;
use App\Services\Auth\HibpChecker;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * SEC-APP — un changement ou une réinitialisation du mot de passe ferme aussi les appareils de confiance.
 * Changement : tous sauf celui de la requête (si son cookie est reconnu pour CE compte). Réinitialisation : tous.
 * Rien ne s'efface (« retiré » est une date). Comptes fictifs en example.test.
 * Les tests 1 à 3 sont joués sous le propriétaire ET sous le rôle applicatif (SET LOCAL ROLE).
 */
uses(TestCase::class, RefreshDatabase::class);

const SECAPP_ANCIEN = 'AncienMotDePasse2026!';
const SECAPP_NOUVEAU = 'NouveauMotDePasseTresLong-2026';

beforeEach(function () {
    config(['double_auth.actif' => true, 'envoi.reel_actif' => false, 'crm.mock_mode' => true]);
    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.' . random_int(1, 254)]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => 'secapp-csrf'])->withHeader('X-CSRF-TOKEN', 'secapp-csrf');
    $this->withCredentials();
    $pile = HandlerStack::create(fn () => Create::promiseFor(
        new Response(200, [], 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0' . "\r\n"),
    ));
    app()->instance(HibpChecker::class, new HibpChecker(new Client(['handler' => $pile])));
});

function secappCompte(): User
{
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'secapp-' . Str::lower(Str::random(6)), 'name' => 'SEC-APP']);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'secapp-' . Str::lower(Str::random(6)) . '@example.test',
        'name' => 'SEC-APP',
        'password_hash' => password_hash(SECAPP_ANCIEN, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
    DB::table('users')->where('id', $u->id)->update(['double_auth_methode' => 'email']);

    return $u->fresh();
}

/** Ajoute n appareils au compte ; rend les jetons en clair. @return list<string> */
function secappAppareils(User $u, int $n): array
{
    $jetons = [];
    for ($i = 0; $i < $n; $i++) {
        $jetons[] = app(AppareilsConfiance::class)->ajouter($u, '192.0.2.10', 'Navigateur ' . $i);
    }

    return $jetons;
}

function secappActif(string $jeton): bool
{
    return DB::table('appareils_confiance')->where('jeton_hash', hash('sha256', $jeton))->whereNull('retire_le')->exists();
}

/** Connexion par mot de passe AVEC le cookie d'un appareil de confiance (pas de code demandé) ; rend le jeton. */
function secappConnecter(User $u, string $jetonAppareil): void
{
    test()->withCookie(AppareilsConfiance::COOKIE, $jetonAppareil);
    $r = test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => SECAPP_ANCIEN]);
    $r->assertOk();
    expect($r->json('requires_2fa'))->toBeFalse();
    foreach ($r->baseResponse->headers->getCookies() as $c) {
        if ((string) $c->getValue() !== '' && $c->getName() !== AppareilsConfiance::COOKIE) {
            test()->withCookie($c->getName(), $c->getValue());
        }
    }
    app('auth')->forgetGuards();
}

function secappChanger(): TestResponse
{
    return test()->postJson('/api/v1/auth/password/change', [
        'current_password' => SECAPP_ANCIEN, 'password' => SECAPP_NOUVEAU, 'password_confirmation' => SECAPP_NOUVEAU,
    ]);
}

function secappReinitialiser(User $u): TestResponse
{
    $jeton = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(['email' => $u->email], ['token' => hash('sha256', $jeton), 'created_at' => now()]);

    return test()->postJson('/api/v1/auth/password/reset', [
        'email' => $u->email, 'token' => $jeton, 'password' => SECAPP_NOUVEAU, 'password_confirmation' => SECAPP_NOUVEAU,
    ]);
}

function secappSous(string $mode, Closure $f): mixed
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

test('1. changement avec 3 appareils : B et C retirés, le courant reste, B redemande le code', function (string $mode) {
    $u = secappCompte();
    [$courant, $b, $c] = secappAppareils($u, 3);
    secappConnecter($u, $courant);
    test()->withCookie(AppareilsConfiance::COOKIE, $courant);

    secappSous($mode, fn () => secappChanger()->assertOk()->assertJsonPath('changed', true));

    expect(secappActif($courant))->toBeTrue()->and(secappActif($b))->toBeFalse()->and(secappActif($c))->toBeFalse();
    // Le jeton de B ne reconnaît plus l'appareil : à la connexion suivante, le code est redemandé.
    expect(app(AppareilsConfiance::class)->reconnaitre($u, $b))->toBeFalse();
    expect(app(AppareilsConfiance::class)->reconnaitre($u, $courant))->toBeTrue();
})->with(['proprio', 'pgsql_app']);

test('2. changement sans cookie, ou avec un cookie faux ou d\'un autre compte : les 3 sont retirés', function (string $mode, string $cas) {
    $u = secappCompte();
    $autre = secappCompte();
    $jetons = secappAppareils($u, 3);
    $jetonAutre = secappAppareils($autre, 1)[0];
    secappConnecter($u, $jetons[0]);
    // On remplace le cookie de la requête de changement.
    test()->withCookie(AppareilsConfiance::COOKIE, match ($cas) {
        'absent' => '',
        'faux' => Str::random(48),
        'autre_compte' => $jetonAutre,
    });

    secappSous($mode, fn () => secappChanger()->assertOk());

    foreach ($jetons as $j) {
        expect(secappActif($j))->toBeFalse();
    }
    expect(secappActif($jetonAutre))->toBeTrue();
})->with(['proprio', 'pgsql_app'])->with(['absent', 'faux', 'autre_compte']);

test('3. réinitialisation par lien : tous retirés, même celui du cookie présenté', function (string $mode) {
    $u = secappCompte();
    $jetons = secappAppareils($u, 3);
    test()->withCookie(AppareilsConfiance::COOKIE, $jetons[0]);

    secappSous($mode, fn () => secappReinitialiser($u)->assertOk()->assertJsonPath('reset', true));

    foreach ($jetons as $j) {
        expect(secappActif($j))->toBeFalse();
    }
})->with(['proprio', 'pgsql_app']);

test('4. les appareils d\'un autre compte restent intacts (changement et réinitialisation)', function () {
    $u = secappCompte();
    $autre = secappCompte();
    secappAppareils($u, 2);
    $jetonsAutre = secappAppareils($autre, 2);
    $v = secappCompte();
    $jetonsV = secappAppareils($v, 1);

    secappReinitialiser($u)->assertOk();
    secappConnecter($v, $jetonsV[0]);
    test()->withCookie(AppareilsConfiance::COOKIE, '');
    $r = test()->postJson('/api/v1/auth/password/change', [
        'current_password' => SECAPP_ANCIEN, 'password' => SECAPP_NOUVEAU, 'password_confirmation' => SECAPP_NOUVEAU,
    ]);
    $r->assertOk();

    foreach ($jetonsAutre as $j) {
        expect(secappActif($j))->toBeTrue();
    }
    expect(secappActif($jetonsV[0]))->toBeFalse();
});

test('5. rien n\'est supprimé : le nombre de lignes est inchangé, seule retire_le est posée', function () {
    $u = secappCompte();
    $jetons = secappAppareils($u, 3);
    $avant = DB::table('appareils_confiance')->count();

    secappReinitialiser($u)->assertOk();

    expect(DB::table('appareils_confiance')->count())->toBe($avant);
    expect(DB::table('appareils_confiance')->whereNotNull('retire_le')->count())->toBe(3);
    expect(count($jetons))->toBe(3);
});

test('6. erreur forcée dans retirerTous : l\'ancien mot de passe fonctionne toujours (échec fermé)', function (string $voie) {
    $u = secappCompte();
    $jetons = secappAppareils($u, 2);
    $hashAvant = (string) $u->password_hash;
    $u->createToken('avant');
    // Panne simulée : la base refuse de poser `retire_le` (déclencheur annulé avec la transaction du test).
    DB::unprepared("CREATE FUNCTION secapp_panne() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.retire_le IS NOT NULL THEN RAISE EXCEPTION 'panne simulée'; END IF; RETURN NEW; END; \$\$");
    DB::unprepared('CREATE TRIGGER secapp_panne BEFORE UPDATE ON appareils_confiance FOR EACH ROW EXECUTE FUNCTION secapp_panne()');

    if ($voie === 'changement') {
        secappConnecter($u, $jetons[0]);
        test()->withCookie(AppareilsConfiance::COOKIE, '');
        secappChanger()->assertStatus(500);
    } else {
        secappReinitialiser($u)->assertStatus(500);
    }

    $frais = DB::table('users')->where('id', $u->id)->first();
    expect((string) $frais->password_hash)->toBe($hashAvant);
    expect(Hash::check(SECAPP_ANCIEN, (string) $frais->password_hash))->toBeTrue();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count())->toBe(1);
    expect(secappActif($jetons[0]))->toBeTrue()->and(secappActif($jetons[1]))->toBeTrue();
})->with(['changement', 'reinitialisation']);

test('7. le journal dit combien et pourquoi, jamais de jeton ni d\'IP', function () {
    $u = secappCompte();
    $jetons = secappAppareils($u, 2);
    Log::spy();

    secappReinitialiser($u)->assertOk();

    Log::shouldHaveReceived('info')->withArgs(function ($msg, $ctx = []) use ($u, $jetons) {
        if ($msg !== 'appareils_confiance.retires') {
            return false;
        }
        $texte = json_encode($ctx);

        return $ctx === ['user_id' => $u->id, 'nombre' => 2, 'cause' => 'reinitialisation']
            && ! str_contains((string) $texte, $jetons[0]) && ! str_contains((string) $texte, '192.0.2');
    })->once();
});
