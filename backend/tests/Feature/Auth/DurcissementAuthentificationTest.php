<?php

/**
 * LOT S1 — anti-rejeu et limiteur par compte.
 *
 * Trois gardes :
 *  1. un jeton à usage unique (lien magique, réinitialisation) ne sert JAMAIS deux fois,
 *     même si deux requêtes arrivent en même temps (consommation atomique) ;
 *  2. un compte visé depuis plusieurs adresses est limité (limiteur PAR COMPTE, en plus
 *     du limiteur par adresse), derrière le drapeau `crm.auth_limiteur_compte` ;
 *  3. les réponses ne révèlent pas si un compte existe.
 */

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\MagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function ipS1(): string
{
    return '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
}

function utilisateurS1(string $email): User
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 's1-' . Str::random(8),
        'name' => 'S1',
    ]);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Personne fictive',
        'password_hash' => password_hash('MotDePasseCorrect2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
}

/** Pose un lien magique valide et rend son jeton en clair. */
function lienMagiqueS1(User $utilisateur): string
{
    $jeton = Str::random(64);
    DB::table('magic_links')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $utilisateur->id,
        'email' => $utilisateur->email,
        'token_hash' => hash('sha256', $jeton),
        'ip' => null,
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
    ]);

    return $jeton;
}

/** Une requête telle que l'émet le SPA : IP propre, Origin « stateful », jeton CSRF fourni. */
function appelS1(string $chemin, array $corps, ?string $ip = null): Illuminate\Testing\TestResponse
{
    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $csrf = 's1-csrf';

    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip ?? ipS1()])
        ->withHeader('Origin', 'https://' . $domaine)
        ->withSession(['_token' => $csrf])
        ->withHeader('X-CSRF-TOKEN', $csrf)
        ->postJson($chemin, $corps);
}

// ───────────────────────────────────────────────────────── 1. Anti-rejeu

test('S1 — un lien magique déjà utilisé est refusé', function () {
    $jeton = lienMagiqueS1(utilisateurS1('rejeu-lien@example.test'));

    appelS1('/api/v1/auth/magic-link/verify', ['token' => $jeton])->assertOk();
    appelS1('/api/v1/auth/magic-link/verify', ['token' => $jeton])->assertStatus(401);
});

test('S1 — un lien magique ne sert qu une fois, même si un concurrent passe entre la lecture et l écriture', function () {
    $utilisateur = utilisateurS1('course-lien@example.test');
    $jeton = lienMagiqueS1($utilisateur);
    $hachage = hash('sha256', $jeton);

    // Le « concurrent » tente de consommer le lien, de façon atomique, dès que la
    // requête testée a touché la table. Avec « lire puis écrire », les deux gagnent.
    $victoiresConcurrent = 0;
    $enCours = false;
    DB::listen(function ($requete) use (&$victoiresConcurrent, &$enCours, $hachage) {
        if ($enCours || ! str_contains($requete->sql, 'magic_links')) {
            return;
        }
        $enCours = true;
        $victoiresConcurrent += DB::table('magic_links')
            ->where('token_hash', $hachage)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
        $enCours = false;
    });

    $reussi = app(MagicLinkService::class)->consume($jeton) !== null;

    expect(($reussi ? 1 : 0) + $victoiresConcurrent)->toBeLessThanOrEqual(1);
});

test('S1 — un jeton de réinitialisation ne sert qu une fois, même en concurrence', function () {
    $utilisateur = utilisateurS1('course-reset@example.test');
    $jeton = Str::random(64);
    $hachage = hash('sha256', $jeton);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => $utilisateur->email],
        ['token' => $hachage, 'created_at' => now()],
    );

    $victoiresConcurrent = 0;
    $enCours = false;
    DB::listen(function ($requete) use (&$victoiresConcurrent, &$enCours, $hachage) {
        if ($enCours || ! str_contains($requete->sql, 'password_reset_tokens')) {
            return;
        }
        $enCours = true;
        $victoiresConcurrent += DB::table('password_reset_tokens')->where('token', $hachage)->delete();
        $enCours = false;
    });

    $reponse = appelS1('/api/v1/auth/password/reset', [
        'email' => $utilisateur->email,
        'token' => $jeton,
        'password' => 'NouveauMotDePasse2026!',
        'password_confirmation' => 'NouveauMotDePasse2026!',
    ]);

    expect(($reponse->status() === 200 ? 1 : 0) + $victoiresConcurrent)->toBeLessThanOrEqual(1);
});

// ───────────────────────────────────────────────── 2. Limiteur par compte

test('S1 — au-delà de N essais sur UN compte depuis plusieurs adresses, la connexion est limitée', function () {
    config(['crm.auth_limiteur_compte' => true, 'crm.auth_limite_compte_essais' => 4]);
    $utilisateur = utilisateurS1('viser-compte@example.test');

    // Cinq adresses différentes : le limiteur par adresse ne se déclenche jamais.
    for ($i = 0; $i < 4; $i++) {
        appelS1('/api/v1/auth/login', ['email' => $utilisateur->email, 'password' => 'faux-' . $i], '198.51.100.' . ($i + 1))
            ->assertStatus(422);
    }

    // Même avec le BON mot de passe, une cinquième adresse est refusée : le compte est limité.
    $reponse = appelS1('/api/v1/auth/login', ['email' => $utilisateur->email, 'password' => 'MotDePasseCorrect2026!'], '198.51.100.9');
    $reponse->assertStatus(422);
    expect($reponse->json('errors.email.0'))->not->toBe(__('auth.failed'));
});

test('S1 — drapeau éteint : le limiteur par compte ne change rien', function () {
    config(['crm.auth_limiteur_compte' => false, 'crm.auth_limite_compte_essais' => 2]);
    $utilisateur = utilisateurS1('drapeau-eteint@example.test');

    for ($i = 0; $i < 3; $i++) {
        appelS1('/api/v1/auth/login', ['email' => $utilisateur->email, 'password' => 'faux'], '198.51.100.' . ($i + 20))
            ->assertStatus(422);
    }
    appelS1('/api/v1/auth/login', ['email' => $utilisateur->email, 'password' => 'MotDePasseCorrect2026!'], '198.51.100.30')
        ->assertOk();
});

test('S1 — les demandes de lien magique sont limitées par compte sans rien révéler', function () {
    config(['crm.auth_limiteur_compte' => true, 'crm.auth_limite_compte_essais' => 2, 'crm.mock_mode' => true]);
    $utilisateur = utilisateurS1('bombe-courriel@example.test');

    for ($i = 0; $i < 4; $i++) {
        appelS1('/api/v1/auth/magic-link', ['email' => $utilisateur->email], '198.51.100.' . ($i + 40))
            ->assertOk()->assertJsonPath('data.sent', true);
    }

    // Seuls les deux premiers liens ont été émis ; les autres demandes sont absorbées en silence.
    expect(DB::table('magic_links')->where('user_id', $utilisateur->id)->count())->toBe(2);
});

// ──────────────────────────────────────────── 3. Aucune fuite d'existence

test('S1 — connexion : réponse identique pour un compte connu et inconnu', function () {
    $utilisateur = utilisateurS1('connu@example.test');

    $connu = appelS1('/api/v1/auth/login', ['email' => $utilisateur->email, 'password' => 'faux']);
    $inconnu = appelS1('/api/v1/auth/login', ['email' => 'inconnu@example.test', 'password' => 'faux']);

    expect($inconnu->status())->toBe($connu->status());
    expect($inconnu->json())->toBe($connu->json());
});

test('S1 — lien magique : réponse identique pour un compte connu et inconnu', function () {
    config(['crm.mock_mode' => true]);
    $utilisateur = utilisateurS1('connu-lien@example.test');

    $connu = appelS1('/api/v1/auth/magic-link', ['email' => $utilisateur->email]);
    $inconnu = appelS1('/api/v1/auth/magic-link', ['email' => 'inconnu-lien@example.test']);

    expect($inconnu->status())->toBe($connu->status());
    expect($inconnu->json())->toBe($connu->json());
});

test('S1 — réinitialisation : un jeton valide pour une adresse sans compte répond comme un jeton invalide', function () {
    $jeton = Str::random(64);
    DB::table('password_reset_tokens')->updateOrInsert(
        ['email' => 'sans-compte@example.test'],
        ['token' => hash('sha256', $jeton), 'created_at' => now()],
    );
    $corps = fn (string $email, string $token) => [
        'email' => $email,
        'token' => $token,
        'password' => 'NouveauMotDePasse2026!',
        'password_confirmation' => 'NouveauMotDePasse2026!',
    ];

    $sansCompte = appelS1('/api/v1/auth/password/reset', $corps('sans-compte@example.test', $jeton));
    $invalide = appelS1('/api/v1/auth/password/reset', $corps('autre@example.test', Str::random(64)));

    expect($sansCompte->status())->toBe($invalide->status());
    expect($sansCompte->json())->toBe($invalide->json());
});
