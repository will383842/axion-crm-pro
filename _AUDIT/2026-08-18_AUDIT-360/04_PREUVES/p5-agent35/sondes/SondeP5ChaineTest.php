<?php

/**
 * SONDE P5 — LA CHAINE COMPLETE : un compte neuf peut-il ENTRER ?
 *
 * C'est la seule question qui compte pour ce lot : le serveur exige desormais
 * la 2FA (`EnsureTwoFactorPassed`) et l'enrolement (`EnforceFirstLoginSetup`).
 * On rejoue, dans l'ordre exact, les appels que fait l'interface de 26fa980 :
 *   1. POST /auth/login
 *   2. GET  /auth/me            (la page decide de l'etape avec `totp_enabled_at`)
 *   3. POST /auth/2fa/setup     (bouton « Commencer »)
 *   4. POST /auth/2fa/confirm   (bouton « Activer »)
 *   5. GET  /contacts           (bouton « J'ai note mes codes, continuer »)
 *   6. POST /auth/2fa/verify    (si l'etape 5 renvoie vers /2fa)
 *   7. GET  /contacts
 */

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $this->withServerVariables(['REMOTE_ADDR' => '10.4.4.4']);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => 'p5-chaine'])->withHeader('X-CSRF-TOKEN', 'p5-chaine');
});

test('P5-H — chaine complete, compte neuf', function () {
    $g = new Google2FA();
    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'p5h-' . Str::random(8), 'name' => 'P5H']);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'p5h-neuf@p5.test',
        'name' => 'Neuf',
        'password_hash' => password_hash('CorrectPassword12345!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => null,
    ]);

    $journal = [];
    $rejouerCookies = function (\Illuminate\Testing\TestResponse $r) {
        foreach ($r->baseResponse->headers->getCookies() as $c) {
            if ((string) $c->getValue() !== '') {
                $this->withCookie($c->getName(), $c->getValue());
            }
        }
        try {
            $this->withHeader('X-CSRF-TOKEN', app('session.store')->token());
        } catch (\Throwable) {
        }
        app('auth')->forgetGuards();
    };

    // 1. connexion
    $login = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'CorrectPassword12345!']);
    $rejouerCookies($login);
    $journal[] = sprintf('1. POST /auth/login              -> %d  requires_2fa=%s', $login->status(), var_export($login->json('requires_2fa'), true));

    // 2. /auth/me — la page a besoin de `totp_enabled_at` pour choisir son etape
    $moi = $this->getJson('/api/v1/auth/me');
    $aLaCle = array_key_exists('totp_enabled_at', (array) ($moi->json('user') ?? []));
    $journal[] = sprintf(
        '2. GET  /auth/me                 -> %d  le corps porte-t-il `user.totp_enabled_at` ? %s',
        $moi->status(),
        $aLaCle ? 'OUI' : 'NON  <<< la page ne saurait pas ou elle en est'
    );
    $journal[] = '   corps de /auth/me : ' . substr((string) $moi->getContent(), 0, 300);

    // 3. bouton « Commencer »
    $setup = $this->postJson('/api/v1/auth/2fa/setup');
    $secret = $setup->json('secret');
    $journal[] = sprintf('3. POST /auth/2fa/setup          -> %d  secret=%s  qr_url=%s', $setup->status(), $secret ? 'fourni' : 'ABSENT', $setup->json('qr_url') ? 'fourni' : 'ABSENT');

    // 4. bouton « Activer »
    $confirm = $this->postJson('/api/v1/auth/2fa/confirm', ['code' => $g->getCurrentOtp((string) $secret)]);
    $journal[] = sprintf('4. POST /auth/2fa/confirm        -> %d  codes de secours : %d', $confirm->status(), count((array) $confirm->json('recovery_codes')));

    // 5. bouton « J'ai note mes codes, continuer » -> l'ecran d'accueil
    $accueil1 = $this->getJson('/api/v1/contacts');
    $journal[] = sprintf('5. GET  /contacts                -> %d  erreur=%s', $accueil1->status(), var_export($accueil1->json('error'), true));

    // 6. si renvoye vers /2fa : saisie du code
    $verify = $this->postJson('/api/v1/auth/2fa/verify', ['code' => $g->getCurrentOtp((string) $secret)]);
    $journal[] = sprintf('6. POST /auth/2fa/verify         -> %d', $verify->status());

    // 7. l'ecran d'accueil, enfin
    $accueil2 = $this->getJson('/api/v1/contacts');
    $journal[] = sprintf('7. GET  /contacts                -> %d  erreur=%s', $accueil2->status(), var_export($accueil2->json('error'), true));

    // Les deux ecrans du tableau de bord que l'accueil appelle vraiment.
    $journal[] = sprintf('8. GET  /audit-logs (ActivityFeed)-> %d', $this->getJson('/api/v1/audit-logs')->status());

    fwrite(STDERR, "\n[P5-H] CHAINE COMPLETE, COMPTE NEUF\n       " . implode("\n       ", $journal) . "\n");

    expect($accueil2->status())->toBe(200);
});
