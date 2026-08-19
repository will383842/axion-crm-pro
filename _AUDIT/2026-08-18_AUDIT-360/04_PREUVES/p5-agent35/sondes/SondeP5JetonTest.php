<?php

/**
 * SONDE P5 — EnsureTwoFactorPassed face a un CLIENT MACHINE.
 *
 * Le middleware est pose dans le groupe `api` GLOBAL, donc AVANT le middleware
 * de route `auth:sanctum`. Il lit `$request->user()`, c'est-a-dire le garde par
 * defaut (`web`, pilote par la session). Question : que voit-il d'une requete
 * porteuse d'un `Authorization: Bearer`, sans `Origin` de domaine stateful et
 * sans cookie de session -- c'est-a-dire un `curl`, une supervision, un client
 * mobile ?
 */

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('P5-E — jeton d API SANS Origin stateful, compte a 2FA ACTIVE', function () {
    $g = new Google2FA();
    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'p5e-' . Str::random(8), 'name' => 'P5E']);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'p5e-machine@p5.test',
        'name' => 'P5E',
        'password_hash' => password_hash('CorrectPassword12345!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => now(),
        'totp_secret' => $g->generateSecretKey(),
        'totp_enabled_at' => now(),
    ]);

    $jeton = $u->createToken('p5e')->plainTextToken;
    app('auth')->forgetGuards();

    // AUCUN Origin, AUCUN cookie, AUCUNE session : le profil d'un client machine.
    $r = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
        ->withHeaders(['Authorization' => 'Bearer ' . $jeton, 'Accept' => 'application/json'])
        ->getJson('/api/v1/contacts');

    fwrite(STDERR, sprintf(
        "\n[P5-E] client machine (Bearer, sans Origin stateful, sans session), compte 2FA ACTIVE,\n"
        . "        JAMAIS passe par /auth/2fa/verify :\n"
        . "        GET /api/v1/contacts -> statut=%d  erreur=%s\n"
        . "        (403 two_factor_required = la 2FA est exigee ; 200 = elle est contournee)\n",
        $r->status(),
        var_export($r->json('error'), true),
    ));

    // TEMOIN : le meme jeton sur une route de la liste blanche.
    app('auth')->forgetGuards();
    $me = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.10'])
        ->withHeaders(['Authorization' => 'Bearer ' . $jeton, 'Accept' => 'application/json'])
        ->getJson('/api/v1/auth/me');
    fwrite(STDERR, "[P5-E] TEMOIN : GET /api/v1/auth/me avec le meme jeton -> statut=" . $me->status()
        . " (prouve que le jeton est bien accepte par auth:sanctum)\n");

    expect($me->status())->toBe(200);
});
