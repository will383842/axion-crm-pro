<?php

/**
 * GARDES DE L'AUTHENTIFICATION — posées par l'audit 360, agent 35 (2026-08-19).
 *
 * Chacune de ces gardes a été VUE ROUGIR sur `e8924b8` avant que le correctif
 * correspondant ne soit écrit. La sortie rouge est archivée dans
 * `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-35/gardes-ROUGE-avant-correctif.txt`.
 *
 * Chaque test nomme le constat qu'il garde (F35-nnn), pour qu'on sache, dans six
 * mois, ce qu'on casse en le supprimant.
 */

use App\Models\User;
use App\Models\Workspace;
use App\Rules\NotPwnedPassword;
use App\Services\Auth\HibpChecker;
use App\Services\Auth\MagicLinkService;
use App\Services\Auth\TwoFactorService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Une IP propre par requête : le login est plafonné à 5/min/IP, deux fois. */
function ipA35(): string
{
    return '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
}

function utilisateurA35(string $email, string $mdp = 'CorrectPassword12345!', ?string $totp = null, bool $premierLoginFait = true): User
{
    $ws = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'a35-' . Str::random(8),
        'name' => 'A35',
    ]);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'A35',
        'password_hash' => password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => $premierLoginFait ? now() : null,
        'totp_secret' => $totp,
        'totp_enabled_at' => $totp ? now() : null,
    ]);
}

/**
 * Chaque requete part d une IP propre, d un Origin de domaine stateful et d un jeton
 * CSRF valide. Les trois sont necessaires ET suffisants, et c est deja ce que fait
 * `LoginTest.php` : le conteneur impose `APP_ENV=local`, ou `ValidateCsrfToken` ne
 * s auto-desactive pas. On FOURNIT le jeton plutot que de neutraliser le middleware :
 * la protection CSRF reste reellement exercee.
 */
beforeEach(function () {
    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $csrf = 'a35-garde-csrf';

    $this->withServerVariables(['REMOTE_ADDR' => ipA35()]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf);
});

/**
 * Ouvre une VRAIE session : cookie rejoue, cache des gardes vide.
 *
 * Sans le `forgetGuards()`, un test Laravel « reste connecte » parce que
 * `SessionGuard` garde l utilisateur en memoire dans le conteneur : on mesurerait
 * le banc, pas la session. Sans le rejeu du cookie, on ne mesurerait rien du tout.
 */
function connexionA35(string $email, string $mdp = 'CorrectPassword12345!'): \Illuminate\Testing\TestResponse
{
    $t = test();
    $reponse = $t->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $mdp]);

    foreach ($reponse->baseResponse->headers->getCookies() as $c) {
        if ((string) $c->getValue() !== '') {
            $t->withCookie($c->getName(), $c->getValue());
        }
    }
    resynchroniserCsrfA35();
    app('auth')->forgetGuards();

    return $reponse;
}

/**
 * Resynchronise l'en-tete X-CSRF-TOKEN avec la session courante.
 *
 * Toute requete qui ouvre une session appelle `session()->regenerate()` (c'est la
 * protection contre la FIXATION de session, et elle est inconditionnelle) : le
 * jeton CSRF change donc, et l'en-tete que porte encore la sonde devient perime.
 * Sans cette resynchronisation, la requete SUIVANTE part en 419 et l'on croit
 * mesurer un defaut du produit alors qu'on mesure son propre banc.
 * Rencontre pour de vrai le 2026-08-19, sur la garde F35-013.
 */
function resynchroniserCsrfA35(): void
{
    try {
        test()->withHeader('X-CSRF-TOKEN', app('session.store')->token());
    } catch (\Throwable) {
        // Pas de session : rien a resynchroniser.
    }
}

// ─────────────────────────────────────────────────────────────── F35-002 / A07-001
test('F35-002 — l enrolement 2FA ecrit des colonnes qui existent vraiment', function () {
    $colonnes = array_map(
        fn ($c) => $c->column_name,
        DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'users'")
    );

    $utilisateur = utilisateurA35('enrolement@a35.test');
    $enrolement = app(TwoFactorService::class)->startEnrolment($utilisateur);

    expect($enrolement)->toHaveKeys(['secret', 'qr_url']);
    expect($utilisateur->fresh()->totp_secret)->toBe($enrolement['secret']);

    // La colonne écrite doit exister : c'est tout le défaut A07-001.
    expect($colonnes)->toContain('totp_secret');
});

test('F35-002 — confirmer l enrolement pose first_login_completed_at et rend 10 codes de secours', function () {
    $g = new Google2FA();
    $utilisateur = utilisateurA35('confirme@a35.test', premierLoginFait: false);
    $secret = app(TwoFactorService::class)->startEnrolment($utilisateur)['secret'];

    $codes = app(TwoFactorService::class)->confirmEnrolment($utilisateur->fresh(), $g->getCurrentOtp($secret));

    expect($codes)->toHaveCount(10);
    $frais = $utilisateur->fresh();
    expect($frais->first_login_completed_at)->not->toBeNull();
    expect($frais->totp_enabled_at)->not->toBeNull();
    expect($frais->totp_recovery_codes)->toHaveCount(10);
});

test('F35-002 — un code de secours ne sert qu une fois', function () {
    $g = new Google2FA();
    $utilisateur = utilisateurA35('secours@a35.test', premierLoginFait: false);
    $secret = app(TwoFactorService::class)->startEnrolment($utilisateur)['secret'];
    $codes = app(TwoFactorService::class)->confirmEnrolment($utilisateur->fresh(), $g->getCurrentOtp($secret));

    expect(app(TwoFactorService::class)->verify($utilisateur->fresh(), $codes[0]))->toBeTrue();
    expect(app(TwoFactorService::class)->verify($utilisateur->fresh(), $codes[0]))->toBeFalse();
    expect($utilisateur->fresh()->totp_recovery_codes)->toHaveCount(9);
});

test('F35-002 — GET /users ne selectionne aucune colonne inexistante', function () {
    $utilisateur = utilisateurA35('liste@a35.test');
    connexionA35($utilisateur->email);

    $reponse = test()->getJson('/api/v1/users');

    // 200 ou 403 (permission) sont acceptables ; 500 ne l'est pas.
    expect($reponse->status())->not->toBe(500);
});

// ─────────────────────────────────────────────────────────────── F35-003
test('F35-003 — un compte a 2FA active ne franchit rien tant que le code n a pas ete verifie', function () {
    $g = new Google2FA();
    $secret = $g->generateSecretKey();
    $utilisateur = utilisateurA35('gate2fa@a35.test', totp: $secret);

    $login = connexionA35($utilisateur->email);
    expect($login->status())->toBe(200);
    expect($login->json('requires_2fa'))->toBeTrue();

    // Route métier, SANS avoir appelé /auth/2fa/verify.
    test()->getJson('/api/v1/contacts')->assertStatus(403)
        ->assertJsonPath('error', 'two_factor_required');

    // Après vérification du code, la même route s'ouvre.
    test()->postJson('/api/v1/auth/2fa/verify', ['code' => $g->getCurrentOtp($secret)])->assertOk();
    expect(test()->getJson('/api/v1/contacts')->status())->not->toBe(403);
});

test('F35-003 — les routes de la liste blanche restent joignables avant la 2FA', function () {
    $g = new Google2FA();
    $utilisateur = utilisateurA35('gate2fab@a35.test', totp: $g->generateSecretKey());
    connexionA35($utilisateur->email);

    test()->getJson('/api/v1/auth/me')->assertOk();
});

// ─────────────────────────────────────────────────────────────── F35-004
test('F35-004 — HIBP injoignable : le mot de passe est REFUSE, jamais accepte en silence', function () {
    $pile = HandlerStack::create(new MockHandler([
        new ConnectException('cURL error 6', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
        new ConnectException('cURL error 6', new PsrRequest('GET', HibpChecker::API_BASE_URL)),
    ]));
    app()->instance(HibpChecker::class, new HibpChecker(new Client(['handler' => $pile])));

    $validation = Validator::make(['password' => 'password'], ['password' => [new NotPwnedPassword()]]);

    expect($validation->fails())->toBeTrue();
    expect($validation->errors()->first('password'))->toContain('indisponible');
});

test('F35-004 — TEMOIN : HIBP joignable et mot de passe sain, la regle laisse passer', function () {
    $sha = strtoupper(sha1('un-mot-de-passe-vraiment-unique-a35-2026'));
    $corps = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0' . "\r\n";
    $pile = HandlerStack::create(new MockHandler([new \GuzzleHttp\Psr7\Response(200, [], $corps)]));
    app()->instance(HibpChecker::class, new HibpChecker(new Client(['handler' => $pile])));

    $validation = Validator::make(
        ['password' => 'un-mot-de-passe-vraiment-unique-a35-2026'],
        ['password' => [new NotPwnedPassword()]]
    );

    expect($validation->fails())->toBeFalse();
    expect($sha)->toBeString();
});

test('F35-004 — TEMOIN : HIBP joignable et mot de passe compromis, la regle refuse', function () {
    $sha = strtoupper(sha1('password'));
    $corps = substr($sha, 5) . ':9999999' . "\r\n";
    $pile = HandlerStack::create(new MockHandler([new \GuzzleHttp\Psr7\Response(200, [], $corps)]));
    app()->instance(HibpChecker::class, new HibpChecker(new Client(['handler' => $pile])));

    expect(Validator::make(['password' => 'password'], ['password' => [new NotPwnedPassword()]])->fails())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────── F35-005
test('F35-005 — un jeton de reinitialisation de plus de 60 minutes est refuse', function () {
    $utilisateur = utilisateurA35('reset-vieux@a35.test');
    $jeton = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $utilisateur->email],
        ['token' => hash('sha256', $jeton), 'created_at' => now()->subMinutes(61)],
    );

    test()->postJson('/api/v1/auth/password/reset', [
        'email' => $utilisateur->email,
        'token' => $jeton,
        'password' => 'MotDePasseTresLong2026!',
        'password_confirmation' => 'MotDePasseTresLong2026!',
    ])->assertStatus(401)->assertJsonPath('error', 'expired_token');
});

test('F35-005 — TEMOIN : un jeton frais est accepte', function () {
    $utilisateur = utilisateurA35('reset-frais@a35.test');
    $jeton = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $utilisateur->email],
        ['token' => hash('sha256', $jeton), 'created_at' => now()->subMinutes(5)],
    );

    test()->postJson('/api/v1/auth/password/reset', [
        'email' => $utilisateur->email,
        'token' => $jeton,
        'password' => 'MotDePasseTresLong2026!',
        'password_confirmation' => 'MotDePasseTresLong2026!',
    ])->assertOk();
});

test('F35-005 — un jeton de reinitialisation ne sert qu une fois', function () {
    $utilisateur = utilisateurA35('reset-unique@a35.test');
    $jeton = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $utilisateur->email],
        ['token' => hash('sha256', $jeton), 'created_at' => now()],
    );
    $corps = [
        'email' => $utilisateur->email,
        'token' => $jeton,
        'password' => 'MotDePasseTresLong2026!',
        'password_confirmation' => 'MotDePasseTresLong2026!',
    ];

    test()->postJson('/api/v1/auth/password/reset', $corps)->assertOk();
    test()->postJson('/api/v1/auth/password/reset', $corps)->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────── F35-006
test('F35-006 — reinitialiser le mot de passe revoque les jetons d API', function () {
    $utilisateur = utilisateurA35('reset-jetons@a35.test');
    $jetonApi = $utilisateur->createToken('avant-reset')->plainTextToken;

    $jeton = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $utilisateur->email],
        ['token' => hash('sha256', $jeton), 'created_at' => now()],
    );
    test()->postJson('/api/v1/auth/password/reset', [
        'email' => $utilisateur->email,
        'token' => $jeton,
        'password' => 'MotDePasseTresLong2026!',
        'password_confirmation' => 'MotDePasseTresLong2026!',
    ])->assertOk();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $utilisateur->id)->count())->toBe(0);

    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => 'Bearer ' . $jetonApi])
        ->getJson('/api/v1/auth/me')->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────── F35-001
test('F35-001 — une route protegee rend 401, jamais 500, quel que soit l en-tete Accept', function () {
    // ⚠️ get($uri, $entetes) et NON call() : call() n'envoie pas les en-têtes.
    foreach ([
        ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        ['Accept' => '*/*'],
        ['Accept' => 'application/json'],
        ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    ] as $entetes) {
        expect(test()->get('/api/v1/auth/me', $entetes)->status())->toBe(401);
        expect(test()->get('/api/v1/contacts', $entetes)->status())->toBe(401);
    }
});

test('F35-001 — aucune route nommee login n est requise par l application', function () {
    expect(Route::has('login'))->toBeFalse();
    // La garde ci-dessus n'a de sens que parce que la précédente passe : c'est bien
    // que l'API n'a pas besoin de cette route, pas qu'on a ajouté une route bidon.
});

// ─────────────────────────────────────────────────────────────── F35-009
test('F35-009 — un compte inconnu coute le meme travail cryptographique qu un compte connu', function () {
    // On mesure au coût bcrypt de la PRODUCTION, pas à celui du banc.
    // Avec `BCRYPT_ROUNDS=4`, un hachage coûte ~1 ms : l'écriture en base du
    // compteur d'échecs (côté « compte connu ») domine alors le hachage et le
    // rapport reste > 3 même une fois le déséquilibre corrigé. On mesurerait le
    // pilote Postgres, pas la fuite d'information. À coût 10, le hachage pèse
    // ~60 ms et redevient ce qu'il est en production : le poste dominant.
    config(['hashing.bcrypt.rounds' => 10]);
    // Changer la configuration ne suffit pas : `HashManager` construit le
    // `BcryptHasher` UNE fois et lui fige son coût. Sans ce rebuild, `Hash::make()`
    // continue de produire du coût 4 pendant que le compte connu porte du coût 10 —
    // et l'on mesure alors l'écart qu'on vient soi-même de fabriquer.
    app()->forgetInstance('hash');
    app()->forgetInstance('hash.driver');

    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'a35-' . Str::random(8), 'name' => 'A35']);
    User::create([
        'id' => (string) Str::uuid(),
        'email' => 'connu@a35.test',
        'name' => 'A35',
        'password_hash' => password_hash('CorrectPassword12345!', PASSWORD_BCRYPT, ['cost' => 10]),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => now(),
    ]);

    // ⚠️ UNE IP NEUVE PAR REQUÊTE, sans quoi la mesure est fausse : la connexion
    // est plafonnée à 5/min/IP (deux fois : `throttle:login` sur la route ET
    // `AuthService`). Sur une IP fixe, les requêtes 6 et suivantes repartent en
    // 429 SANS jamais atteindre le hachage — on chronomètre alors le limiteur de
    // débit, pas le travail cryptographique, et le rapport explose pour une
    // raison qui n'a rien à voir avec le défaut. Constaté en le faisant.
    $mesure = function (string $email): array {
        $t = [];
        $statuts = [];
        for ($i = 0; $i < 5; $i++) {
            $debut = microtime(true);
            $r = test()->withServerVariables(['REMOTE_ADDR' => ipA35()])
                ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'MauvaisMotDePasse1!']);
            $t[] = microtime(true) - $debut;
            $statuts[] = $r->status();
        }
        sort($t);

        return [$t[intdiv(count($t), 2)], $statuts];
    };

    [$connu, $statutsConnu] = $mesure('connu@a35.test');
    [$inconnu, $statutsInconnu] = $mesure('jamais-vu@a35.test');

    // Témoin d'instrumentation : si une seule requête a été refusée par le
    // limiteur de débit, elle n'a pas haché quoi que ce soit et la mesure ne vaut
    // rien. On le vérifie AVANT de conclure.
    expect($statutsConnu)->each->toBe(422);
    expect($statutsInconnu)->each->toBe(422);

    // Le rapport des médianes doit rester proche de 1. Sans hachage factice, il
    // est de l'ordre de 10 (bcrypt coût 12 d'un côté, rien de l'autre).
    $rapport = max($connu, $inconnu) / max(min($connu, $inconnu), 1e-6);
    expect($rapport)->toBeLessThan(3.0, sprintf(
        'medianes : compte connu %.1f ms, compte inconnu %.1f ms, rapport %.2f',
        $connu * 1000, $inconnu * 1000, $rapport
    ));
});

// ─────────────────────────────────────────────────────────────── F35-010
/**
 * ⚠️ La première version de cette garde vérifiait `expires_at` sur le jeton créé.
 * C'était mesurer le mauvais objet : Sanctum ne remplit cette colonne que si on
 * lui passe une date à `createToken()`. `sanctum.expiration` agit ailleurs — le
 * garde compare `created_at` à `now()->subMinutes(...)` au moment de
 * l'authentification. On garde donc le COMPORTEMENT : un jeton trop vieux est
 * refusé.
 */
test('F35-010 — un jeton d API cesse d etre accepte passe sa duree de vie', function () {
    expect(config('sanctum.expiration'))->not->toBeNull();

    $utilisateur = utilisateurA35('jeton@a35.test');
    $jeton = $utilisateur->createToken('a35');
    $entetes = ['Authorization' => 'Bearer ' . $jeton->plainTextToken];

    test()->withHeaders($entetes)->getJson('/api/v1/auth/me')->assertOk();

    DB::table('personal_access_tokens')->where('id', $jeton->accessToken->id)
        ->update(['created_at' => now()->subMinutes((int) config('sanctum.expiration') + 1)]);
    app('auth')->forgetGuards();

    test()->withHeaders($entetes)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

test('F35-010 — TEMOIN : sans duree de vie configuree, le meme jeton vieilli passe encore', function () {
    $utilisateur = utilisateurA35('jeton-temoin@a35.test');
    $jeton = $utilisateur->createToken('a35');
    $entetes = ['Authorization' => 'Bearer ' . $jeton->plainTextToken];

    DB::table('personal_access_tokens')->where('id', $jeton->accessToken->id)
        ->update(['created_at' => now()->subYears(2)]);

    // C'est exactement l'ancienne configuration : `expiration => null`.
    config(['sanctum.expiration' => null]);
    app('auth')->forgetGuards();

    test()->withHeaders($entetes)->getJson('/api/v1/auth/me')->assertOk();
});

// ─────────────────────────────────────────────────────────────── F35-011
test('F35-011 — un mot de passe court mais CORRECT ouvre bien la session', function () {
    utilisateurA35('court@a35.test', 'court');

    connexionA35('court@a35.test', 'court')->assertOk();
});

test('F35-011 — TEMOIN : un mot de passe court et FAUX est refuse par l authentification', function () {
    utilisateurA35('court2@a35.test', 'court');

    connexionA35('court2@a35.test', 'faux!')->assertStatus(422);
});

// ─────────────────────────────────────────────────────────────── F35-012
test('F35-012 — un compte verrouille est refuse AVANT toute verification du mot de passe', function () {
    $utilisateur = utilisateurA35('verrou@a35.test');
    $utilisateur->forceFill(['locked_until' => now()->addHours(24), 'failed_login_count' => 10])->save();

    connexionA35($utilisateur->email, 'NimporteQuoiFaux123!')->assertStatus(422);

    // Le compteur ne doit PAS continuer de monter sur un compte déjà verrouillé.
    expect($utilisateur->fresh()->failed_login_count)->toBe(10);
});

test('F35-012 — le compteur d echecs retombe apres la fenetre d oubli', function () {
    $utilisateur = utilisateurA35('oubli@a35.test');
    $utilisateur->forceFill([
        'failed_login_count' => 9,
        'last_failed_login_at' => now()->subMinutes(31),
    ])->save();

    connexionA35($utilisateur->email, 'EncoreFauxMaisVieux1!')->assertStatus(422);

    // 9 échecs oubliés + 1 nouvel échec = 1, pas 10 : le compte n'est pas verrouillé.
    $frais = $utilisateur->fresh();
    expect($frais->failed_login_count)->toBe(1);
    expect($frais->locked_until)->toBeNull();
});

// ─────────────────────────────────────────────────────────────── F35-013
test('F35-013 — aucun lien magique n est cree pour une adresse inconnue', function () {
    app(MagicLinkService::class)->issue('inconnu-total@a35.test', '10.0.0.1');

    expect(DB::table('magic_links')->where('email', 'inconnu-total@a35.test')->count())->toBe(0);
});

test('F35-013 — un lien magique orphelin n ouvre pas de session sur un compte cree apres coup', function () {
    $jeton = Str::random(64);
    DB::table('magic_links')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => null,
        'email' => 'futur@a35.test',
        'token_hash' => hash('sha256', $jeton),
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
    ]);
    utilisateurA35('futur@a35.test');

    test()->postJson('/api/v1/auth/magic-link/verify', ['token' => $jeton])->assertStatus(401);
});

test('F35-013 — TEMOIN : un lien magique normal ouvre bien la session, une seule fois', function () {
    $utilisateur = utilisateurA35('magique@a35.test');
    $jeton = Str::random(64);
    DB::table('magic_links')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $utilisateur->id,
        'email' => $utilisateur->email,
        'token_hash' => hash('sha256', $jeton),
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
    ]);

    test()->postJson('/api/v1/auth/magic-link/verify', ['token' => $jeton])->assertOk();
    resynchroniserCsrfA35();
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/auth/magic-link/verify', ['token' => $jeton])->assertStatus(401);
});
