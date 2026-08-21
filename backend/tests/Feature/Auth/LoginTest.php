<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeUser(string $email = 'user@example.com', string $password = 'CorrectPassword12345!'): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'test-ws-' . Str::random(6),
        'name' => 'Test WS',
    ]);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Test User',
        'password_hash' => Hash::make($password),
        'current_workspace_id' => $workspace->id,
    ]);
}

/** Une IP cliente unique, pour ne partager aucun compteur de throttle. */
function loginTestIp(): string
{
    return '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
}

/**
 * Chaque test rejoue une requête de login TELLE QUE LE SPA L'ÉMET. Trois
 * détails manquaient, et ce sont eux — pas le code de production — qui
 * rendaient ce fichier rouge et dépendant de l'ordre d'exécution.
 *
 * 1. Une IP cliente propre à chaque test. Le login est plafonné à 5
 *    tentatives/minute/IP, DEUX fois : par le middleware `throttle:login`
 *    (RouteServiceProvider) et par AuthService lui-même (clé
 *    `login:{ip}:{email}`). Ces compteurs vivent dans un cache PARTAGÉ — le
 *    conteneur impose `CACHE_STORE=redis` et le `array` de phpunit.xml ne
 *    l'écrase pas (PHPUnit n'écrase jamais une variable d'environnement déjà
 *    définie sans `force="true"`). Toutes les requêtes partant de 127.0.0.1,
 *    elles se partageaient le MÊME compteur d'un test à l'autre et d'une suite
 *    à l'autre : la 6e requête du fichier recevait 429 quel que soit le test
 *    visé, et « login with correct credentials succeeds » échouait selon le
 *    tirage de l'ordre aléatoire.
 *
 * 2. Un `Origin` de domaine « stateful ». L'authentification est en mode
 *    Sanctum SPA (cookie de session) : `EnsureFrontendRequestsAreStateful`
 *    n'injecte `StartSession` que si le `Referer`/`Origin` de la requête
 *    figure dans `sanctum.stateful`. Sans cet en-tête, aucune session n'est
 *    démarrée et `AuthService::attemptLogin()` part en 500 sur
 *    `$request->session()->regenerate()` — panne que le 429 masquait. Le
 *    navigateur du SPA (https://app.localhost → https://api.localhost, donc
 *    cross-origin) envoie toujours cet en-tête.
 *
 * 3. Un jeton CSRF valide, puisque la requête « stateful » traverse aussi
 *    `ValidateCsrfToken`. On le FOURNIT (session + en-tête `X-CSRF-TOKEN`)
 *    plutôt que de neutraliser le middleware : la protection CSRF reste
 *    réellement exercée. Sans cela le verdict dépendrait de `APP_ENV` — le
 *    middleware ne s'auto-désactive qu'en env `testing`, or le conteneur
 *    impose `APP_ENV=local` : 419 en local, 422 en CI, pour le même code.
 */
beforeEach(function () {
    $statefulDomain = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $csrfToken = 'axion-login-test-csrf-token';

    $this->withServerVariables(['REMOTE_ADDR' => loginTestIp()]);
    $this->withHeader('Origin', 'https://' . $statefulDomain);
    $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);
});

test('login with correct credentials succeeds', function () {
    $user = makeUser();
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'CorrectPassword12345!',
    ])->assertOk()->assertJsonStructure(['user', 'requires_2fa']);
});

test('login with wrong password fails 422', function () {
    $user = makeUser();
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'WrongPassword999!',
    ])->assertStatus(422);
});

test('login increments failed_login_count on wrong password', function () {
    $user = makeUser();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrongPasswordOK1!'])->assertStatus(422);
    expect($user->fresh()->failed_login_count)->toBe(1);
});

/**
 * Verrouillage de compte : 10 échecs → `locked_until` à +24 h
 * (AuthService::MAX_FAILED_ATTEMPTS / LOCK_DURATION_SECONDS).
 *
 * La garde n'était pas cassée : le test était irréalisable tel qu'écrit. Depuis
 * une IP unique, le throttle plafonne à 5 tentatives par minute (route ET
 * service) ; les tentatives 6 à 10 repartaient en 429 sans jamais atteindre
 * AuthService, donc `failed_login_count` stagnait à 5 et le compte n'était
 * jamais verrouillé. Dans la vraie vie la garde se déclenche quand même : soit
 * l'attaquant persiste sur deux minutes, soit — cas rejoué ici — il répartit
 * ses tentatives sur plusieurs IP. `failed_login_count` est porté par
 * l'UTILISATEUR, pas par l'IP : la rotation d'adresses ne doit rien y changer.
 *
 * Les assertions sont volontairement plus strictes qu'à l'origine : chaque
 * tentative doit être réellement TRAITÉE (422, jamais 429), sans quoi le test
 * pourrait de nouveau se croire concluant sans avoir atteint le compteur.
 */
test('login locks user after 10 failed attempts', function () {
    $user = makeUser();
    for ($i = 0; $i < 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => loginTestIp()]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => "wrong{$i}attempt12!"])
            ->assertStatus(422);
    }
    $fresh = $user->fresh();
    expect($fresh->failed_login_count)->toBe(10);
    expect($fresh->locked_until)->not->toBeNull();
    expect($fresh->locked_until->isFuture())->toBeTrue();
});

test('login rejects locked account even with correct password', function () {
    $user = makeUser();
    $user->locked_until = now()->addHours(24);
    $user->save();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'CorrectPassword12345!',
    ])->assertStatus(422);
});

/**
 * 🔴 CE TEST GARDAIT UN DÉFAUT. Il exigeait qu'un mot de passe de moins de 12
 * caractères soit refusé PAR LA VALIDATION, à la connexion. Conséquence : un
 * compte dont le mot de passe est plus court — créé par un script, une reprise,
 * un import — recevait « le mot de passe doit contenir au moins 12 caractères »
 * alors qu'il venait de saisir le bon. Impasse totale, et message qui désigne la
 * mauvaise cause. Mesuré le 2026-08-19 (audit 360, F35-011).
 *
 * La complexité se contrôle là où un mot de passe est CHOISI, pas là où il est
 * présenté. À la connexion, un mot de passe court est simplement... un mot de
 * passe : s'il est faux, l'authentification le refuse ; s'il est bon, elle ouvre
 * la session.
 */
test('un mot de passe court et faux est refusé par l authentification, pas par la validation', function () {
    $reponse = $this->postJson('/api/v1/auth/login', ['email' => 'test@test.com', 'password' => 'short']);

    expect($reponse->status())->toBe(422);
    // Le refus porte sur les identifiants, pas sur la forme du mot de passe.
    $reponse->assertJsonValidationErrorFor('email');
    expect($reponse->json('errors.password'))->toBeNull();
});

test('un mot de passe court mais CORRECT ouvre la session', function () {
    $user = makeUser('court@test.com', 'court');

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'court'])
        ->assertOk()
        ->assertJsonStructure(['user', 'requires_2fa']);
});

/**
 * Version précédente : la 429 n'était assertée que si elle tombait au bon tour
 * de boucle ; si le throttle ne se déclenchait jamais, le test se terminait
 * SANS AUCUNE assertion, donc vert. On collecte désormais les 6 statuts et on
 * les vérifie tous : 5 tentatives traitées (422) puis la 6e refusée (429).
 */
test('login throttles after 5 attempts per minute', function () {
    $statuses = [];
    for ($i = 0; $i < 6; $i++) {
        $statuses[] = $this->postJson('/api/v1/auth/login', [
            'email' => 'spam@test.com',
            'password' => 'IncorrectPassword12!',
        ])->getStatusCode();
    }

    expect(array_slice($statuses, 0, 5))->each->toBe(422);
    expect($statuses[5])->toBe(429);
});

test('me endpoint returns 401 when not authenticated', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

/**
 * 🔴 UN 500 N'EST PAS UN CONTRAT.
 *
 * Tout client qui n'envoie pas d'`Origin`/`Referer` de domaine stateful —
 * curl, Postman, client mobile, ou `SANCTUM_STATEFUL_DOMAINS` mal réglé — ne
 * traverse pas `StartSession`. La régénération d'identifiant de session
 * (protection contre la FIXATION de session, inconditionnelle à dessein)
 * explosait alors en 500, avec une trace complète en journal.
 *
 * On répond désormais explicitement, SANS rien affaiblir : la protection reste
 * entière, seul le message change. « Le serveur est cassé » devient « cette
 * requête ne peut pas aboutir ainsi, et voilà pourquoi ».
 */
test('une requête SANS session reçoit un refus explicite, jamais une 500', function () {
    $reponse = $this->postJson('/api/v1/auth/login', [
        'email' => 'inexistant@example.com',
        'password' => 'PasswordTest12345!',
    ]);

    expect($reponse->status())->not->toBe(500);

    // Soit la requête est stateful et l'authentification tranche (422),
    // soit elle ne l'est pas et on le dit (419). Jamais une panne serveur.
    expect($reponse->status())->toBeIn([419, 422, 429]);

    if ($reponse->status() === 419) {
        expect($reponse->json('error'))->toBe('session_requise');
        // Le message doit indiquer la SORTIE, pas seulement l'échec.
        expect($reponse->json('message'))->toContain("jeton d'API");
    }
});
