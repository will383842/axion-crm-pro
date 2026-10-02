<?php

/**
 * MOT DE PASSE ET SESSION — constat prod du 2026-10-02.
 *
 * Le propriétaire (seul utilisateur) ne parvenait plus à se connecter par mot de
 * passe après une réinitialisation réussie (gestionnaire du navigateur qui
 * pré-remplit l'ancien). Il entrait par lien magique, mais :
 *   - aucun écran ne permettait de changer son mot de passe une fois connecté ;
 *   - le lien ouvrait une session SANS « se souvenir », morte au bout de 2 h ;
 *   - les échecs de connexion ne laissaient aucune trace exploitable.
 *
 * Chaque test nomme ce qu'il garde.
 */

use App\Models\User;
use App\Models\Workspace;
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

uses(TestCase::class, RefreshDatabase::class);

const CMDP_ANCIEN = 'AncienMotDePasse2026!';
const CMDP_NOUVEAU = 'NouveauMotDePasseTresLong-2026';
const CMDP_FAUX = 'MauvaisMotDePasse-X';

function cmdpIp(): string
{
    return '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
}

function cmdpCompte(string $email): User
{
    $ws = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'cmdp-' . Str::random(8),
        'name' => 'CMDP',
    ]);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'CMDP',
        'password_hash' => password_hash(CMDP_ANCIEN, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => now(),
    ]);
}

/** HIBP simulé, joignable et muet : tout mot de passe est « sain ». */
function cmdpHibpSain(): void
{
    $pile = HandlerStack::create(fn () => Create::promiseFor(
        new Response(200, [], 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0' . "\r\n"),
    ));
    app()->instance(HibpChecker::class, new HibpChecker(new Client(['handler' => $pile])));
}

/** Rejoue les cookies d'une réponse et resynchronise le jeton CSRF (cf. A35). */
function cmdpRejouer(TestResponse $reponse): void
{
    $t = test();
    foreach ($reponse->baseResponse->headers->getCookies() as $c) {
        if ((string) $c->getValue() !== '') {
            $t->withCookie($c->getName(), $c->getValue());
        }
    }
    try {
        $t->withHeader('X-CSRF-TOKEN', app('session.store')->token());
    } catch (Throwable) {
        // Pas de session.
    }
    app('auth')->forgetGuards();
}

function cmdpConnexionMotDePasse(User $u): TestResponse
{
    $r = test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => CMDP_ANCIEN]);
    $r->assertOk();
    cmdpRejouer($r);

    return $r;
}

function cmdpConnexionParLien(User $u): TestResponse
{
    $jeton = Str::random(64);
    DB::table('magic_links')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $u->id,
        'email' => $u->email,
        'token_hash' => hash('sha256', $jeton),
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
    ]);

    $r = test()->postJson('/api/v1/auth/magic-link/verify', ['token' => $jeton]);
    $r->assertOk();
    cmdpRejouer($r);

    return $r;
}

function cmdpChanger(?string $ancien = null): TestResponse
{
    $corps = ['password' => CMDP_NOUVEAU, 'password_confirmation' => CMDP_NOUVEAU];
    if ($ancien !== null) {
        $corps['current_password'] = $ancien;
    }

    return test()->postJson('/api/v1/auth/password/change', $corps);
}

beforeEach(function () {
    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $csrf = 'cmdp-csrf';

    $this->withServerVariables(['REMOTE_ADDR' => cmdpIp()]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf);
    cmdpHibpSain();
});

// ─────────────────────────────────────────── changement par l'ancien mot de passe
test('sans session par lien, l ancien mot de passe est EXIGE', function () {
    $u = cmdpCompte('exige@cmdp.test');
    cmdpConnexionMotDePasse($u);

    test()->getJson('/api/v1/auth/password/change')
        ->assertOk()
        ->assertJsonPath('mot_de_passe_actuel_requis', true);

    cmdpChanger()->assertStatus(422)->assertJsonPath('error', 'mot_de_passe_actuel_requis');

    expect(Hash::check(CMDP_ANCIEN, (string) $u->fresh()->password_hash))->toBeTrue();
});

test('un ancien mot de passe FAUX est refuse et rien ne change', function () {
    $u = cmdpCompte('faux@cmdp.test');
    cmdpConnexionMotDePasse($u);

    cmdpChanger('PasLeBonMotDePasse!')->assertStatus(422)
        ->assertJsonPath('error', 'mot_de_passe_actuel_incorrect');

    expect(Hash::check(CMDP_ANCIEN, (string) $u->fresh()->password_hash))->toBeTrue();
});

test('ancien mot de passe correct : meme hachage et memes effets que la reinitialisation, session courante GARDEE', function () {
    $u = cmdpCompte('effets@cmdp.test');
    $u->forceFill(['failed_login_count' => 3, 'last_failed_login_at' => now()])->save();
    $u->createToken('avant-changement');
    cmdpConnexionMotDePasse($u);

    $r = cmdpChanger(CMDP_ANCIEN);
    $r->assertOk()->assertJsonPath('changed', true);

    $frais = $u->fresh();
    expect(Hash::check(CMDP_NOUVEAU, (string) $frais->password_hash))->toBeTrue();
    expect(Hash::check(CMDP_ANCIEN, (string) $frais->password_hash))->toBeFalse();
    expect((int) $frais->failed_login_count)->toBe(0);
    expect($frais->locked_until)->toBeNull();

    // Jetons d'API révoqués, comme à la réinitialisation (F35-006).
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count())->toBe(0);

    // Journal d'audit : la ligne existe, SANS empreinte du corps.
    $ligne = DB::table('audit_logs')->where('event_type', 'MOT_DE_PASSE_MODIFIE')->first();
    expect($ligne)->not->toBeNull();
    expect($ligne->payload_hash)->toBeNull();

    // La session COURANTE survit au changement (AuthenticateSession compris).
    cmdpRejouer($r);
    test()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.email', $u->email);

});

test('le nouveau mot de passe ouvre une session, l ancien non', function () {
    $u = cmdpCompte('relogin@cmdp.test');
    cmdpConnexionMotDePasse($u);
    cmdpChanger(CMDP_ANCIEN)->assertOk();

    // ⚠️ Artefact du banc : `auth:sanctum` a fait `shouldUse('sanctum')` sur
    // l'application PARTAGÉE entre les requêtes du test ; `Auth::login()` de la
    // requête suivante viserait alors le RequestGuard. En production, chaque
    // requête repart d'une application neuve, sur la garde `web`.
    app('auth')->shouldUse('web');
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => cmdpIp()]);
    test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => CMDP_ANCIEN])->assertStatus(422);
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => cmdpIp()]);
    test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => CMDP_NOUVEAU])->assertOk();
});

test('le nouveau mot de passe doit faire 12 caracteres et etre confirme', function () {
    $u = cmdpCompte('regles@cmdp.test');
    cmdpConnexionMotDePasse($u);

    test()->postJson('/api/v1/auth/password/change', [
        'current_password' => CMDP_ANCIEN,
        'password' => 'court',
        'password_confirmation' => 'court',
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    test()->postJson('/api/v1/auth/password/change', [
        'current_password' => CMDP_ANCIEN,
        'password' => CMDP_NOUVEAU,
        'password_confirmation' => CMDP_NOUVEAU . 'x',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

test('la route exige une session authentifiee', function () {
    app('auth')->forgetGuards();
    cmdpChanger(CMDP_ANCIEN)->assertUnauthorized();
});

// ─────────────────────────────────────────────── session ouverte par lien magique
test('session ouverte par LIEN il y a moins de 30 min : l ancien mot de passe n est pas exige, une seule fois', function () {
    $u = cmdpCompte('lien@cmdp.test');
    cmdpConnexionParLien($u);

    test()->getJson('/api/v1/auth/password/change')
        ->assertOk()
        ->assertJsonPath('mot_de_passe_actuel_requis', false);

    $r = cmdpChanger();
    $r->assertOk();
    expect(Hash::check(CMDP_NOUVEAU, (string) $u->fresh()->password_hash))->toBeTrue();

    // La dispense est CONSOMMÉE : un second changement exige l'ancien.
    cmdpRejouer($r);
    cmdpChanger()->assertStatus(422)->assertJsonPath('error', 'mot_de_passe_actuel_requis');
});

test('session ouverte par lien il y a PLUS de 30 min : l ancien mot de passe est de nouveau exige', function () {
    $u = cmdpCompte('lien-vieux@cmdp.test');
    cmdpConnexionParLien($u);

    $this->travel(31)->minutes();

    cmdpChanger()->assertStatus(422)->assertJsonPath('error', 'mot_de_passe_actuel_requis');
    expect(Hash::check(CMDP_ANCIEN, (string) $u->fresh()->password_hash))->toBeTrue();
});

test('TEMOIN : une connexion par MOT DE PASSE ne pose pas la dispense', function () {
    $u = cmdpCompte('temoin@cmdp.test');
    cmdpConnexionMotDePasse($u);

    test()->getJson('/api/v1/auth/password/change')->assertJsonPath('mot_de_passe_actuel_requis', true);
});

test('le lien magique ouvre une session « se souvenir » et met a jour la derniere connexion', function () {
    $u = cmdpCompte('souvenir@cmdp.test');
    expect($u->last_login_at)->toBeNull();

    $r = cmdpConnexionParLien($u);

    $noms = array_map(fn ($c) => $c->getName(), $r->baseResponse->headers->getCookies());
    expect(collect($noms)->contains(fn ($n) => str_starts_with($n, 'remember_web_')))->toBeTrue(
        'Le lien magique doit poser le cookie « se souvenir » : sans lui, la session meurt avec session.lifetime.',
    );

    $frais = $u->fresh();
    expect($frais->last_login_at)->not->toBeNull();
    expect($frais->last_login_ip)->not->toBeNull();
});

// ───────────────────────────────────────────────────────────── durée de session
test('la session dure au moins 12 h par defaut', function () {
    expect((int) config('session.lifetime'))->toBeGreaterThanOrEqual(720);

    // Le DÉFAUT du fichier de configuration, indépendamment du `.env` du banc.
    $source = (string) file_get_contents(config_path('session.php'));
    expect($source)->toContain("env('SESSION_LIFETIME', 720)");
});

// ───────────────────────────────────────────────────── diagnostic des échecs
test('chaque echec de connexion est journalise avec sa cause, JAMAIS le mot de passe', function () {
    $u = cmdpCompte('diag@cmdp.test');
    Log::spy();

    test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => CMDP_FAUX . '1'])->assertStatus(422);
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => cmdpIp()]);
    test()->postJson('/api/v1/auth/login', ['email' => 'personne@cmdp.test', 'password' => CMDP_FAUX . '2'])->assertStatus(422);

    $u->forceFill(['locked_until' => now()->addHour()])->save();
    app('auth')->forgetGuards();
    test()->withServerVariables(['REMOTE_ADDR' => cmdpIp()]);
    test()->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => CMDP_FAUX . '3'])->assertStatus(422);

    $causes = [];
    Log::shouldHaveReceived('info')->withArgs(function ($message, $contexte = []) use (&$causes) {
        if ($message === 'auth.login.echec') {
            $causes[] = $contexte['cause'] ?? null;
            $brut = (string) json_encode($contexte);
            expect($brut)->not->toContain(CMDP_FAUX);
            expect($brut)->not->toContain('$2y$');
            // Revue sécurité A09 : JAMAIS l'adresse tapée (mot de passe collé
            // dans le champ e-mail, donnée d'un tiers). Catégorie + user_id.
            expect($brut)->not->toContain('cmdp.test');
            expect($brut)->not->toContain('cmdp');
            expect(array_key_exists('email', (array) $contexte))->toBeFalse();
        }

        return true;
    });

    expect($causes)->toContain('mot_de_passe');
    expect($causes)->toContain('compte_inconnu');
    expect($causes)->toContain('verrouille');
});

test('le changement reussi est journalise sans valeur', function () {
    $u = cmdpCompte('diag-change@cmdp.test');
    cmdpConnexionMotDePasse($u);
    Log::spy();

    cmdpChanger(CMDP_ANCIEN)->assertOk();

    $vu = false;
    Log::shouldHaveReceived('info')->withArgs(function ($message, $contexte = []) use (&$vu) {
        if ($message === 'password_change.effectue') {
            $vu = true;
            expect(json_encode($contexte))->not->toContain(CMDP_NOUVEAU);
            expect(json_encode($contexte))->not->toContain(CMDP_ANCIEN);
        }

        return true;
    });
    expect($vu)->toBeTrue();
});

test('les journaux de reinitialisation portent l user_id, JAMAIS l adresse', function () {
    $u = cmdpCompte('reset-journal@cmdp.test');
    $jeton = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $u->email],
        ['token' => hash('sha256', $jeton), 'created_at' => now()],
    );
    Log::spy();

    test()->postJson('/api/v1/auth/password/reset', [
        'email' => $u->email,
        'token' => $jeton,
        'password' => CMDP_NOUVEAU,
        'password_confirmation' => CMDP_NOUVEAU,
    ])->assertOk();

    $vu = false;
    Log::shouldHaveReceived('info')->withArgs(function ($message, $contexte = []) use (&$vu, $u) {
        if ($message === 'password_reset.effectue') {
            $vu = true;
            expect($contexte['user_id'] ?? null)->toBe($u->id);
            expect((string) json_encode($contexte))->not->toContain('cmdp.test');
        }

        return true;
    });
    expect($vu)->toBeTrue();
});

test('le cookie « se souvenir » du lien magique vit 30 jours au plus', function () {
    expect((int) config('auth.guards.web.remember'))->toBe(43200);

    $u = cmdpCompte('souvenir-borne@cmdp.test');
    $r = cmdpConnexionParLien($u);

    $souvenir = collect($r->baseResponse->headers->getCookies())
        ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
    expect($souvenir)->not->toBeNull();

    // Sans borne, Laravel le pose pour 400 jours.
    expect($souvenir->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->addMinutes(5)->getTimestamp());
    expect($souvenir->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp());
});
