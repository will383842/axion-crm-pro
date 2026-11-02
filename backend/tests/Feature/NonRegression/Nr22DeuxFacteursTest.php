<?php

/**
 * NR-22 — R14, la connexion : le code de la double authentification.
 *
 * Le plan 22 demande « mot de passe, code, lien magique, mot de passe oublié ».
 * Le mot de passe, le lien magique et le mot de passe oublié sont couverts par
 * les gardes existantes rattachées au groupe ; le CODE ne l’était pas : un
 * contrôleur qui acceptait n’importe quel code laissait tout le filet vert.
 *
 * Cette garde passe par la VRAIE route HTTP (`POST /api/v1/auth/2fa/verify`) :
 * un mauvais code est refusé (422) et n’ouvre rien ; le bon code est accepté.
 *
 * Fixtures FICTIVES (`@example.test`).
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
    $csrf = 'nr22-garde-csrf';

    // Une IP propre : le login est plafonné à 5/min/IP.
    $this->withServerVariables(['REMOTE_ADDR' => '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254)]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf);
});

/** Ouvre une vraie session pour un compte dont la double authentification est active. */
function nr22ConnecterAvecCode(string $secret): void
{
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'nr22-' . Str::lower(Str::random(6)), 'name' => 'NR-22']);
    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'nr22-' . Str::lower(Str::random(6)) . '@example.test',
        'name' => 'NR-22',
        'password_hash' => password_hash('MotDePasseFactice12345!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
        'totp_secret' => $secret,
        'totp_enabled_at' => now(),
    ]);

    $t = test();
    $reponse = $t->postJson('/api/v1/auth/login', ['email' => $utilisateur->email, 'password' => 'MotDePasseFactice12345!']);
    expect($reponse->status())->toBe(200)
        ->and($reponse->json('requires_2fa'))->toBeTrue();

    foreach ($reponse->baseResponse->headers->getCookies() as $c) {
        if ((string) $c->getValue() !== '') {
            $t->withCookie($c->getName(), $c->getValue());
        }
    }
    app('auth')->forgetGuards();
}

test('NR-22 un mauvais code de double authentification est refusé et n’ouvre rien', function () {
    $g = new Google2FA;
    $secret = $g->generateSecretKey();
    nr22ConnecterAvecCode($secret);

    $juste = $g->getCurrentOtp($secret);
    $faux = $juste === '000000' ? '000001' : '000000';

    test()->postJson('/api/v1/auth/2fa/verify', ['code' => $faux])->assertStatus(422);
    test()->postJson('/api/v1/auth/2fa/verify', ['code' => 'pas-un-code'])->assertStatus(422);

    // Les routes métier restent fermées tant que le code n’a pas été accepté.
    test()->getJson('/api/v1/contacts')->assertStatus(403)->assertJsonPath('error', 'two_factor_required');
});

test('NR-22 le bon code de double authentification est accepté et ouvre les routes métier', function () {
    $g = new Google2FA;
    $secret = $g->generateSecretKey();
    nr22ConnecterAvecCode($secret);

    test()->postJson('/api/v1/auth/2fa/verify', ['code' => $g->getCurrentOtp($secret)])
        ->assertOk()->assertJsonPath('verified', true);

    expect(test()->getJson('/api/v1/contacts')->status())->not->toBe(403);
});
