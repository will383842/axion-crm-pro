<?php

/**
 * GARDE DE LA CHAÎNE D'AUDIT — audit 360, B16-001 (S0).
 *
 * La chaîne est hachée `sha256(prev || ligne || secret)`. Le secret venait de
 * `env('AUDIT_HASH_CHAIN_SECRET', 'dev-only-secret-change-me')` — une valeur
 * **publiée dans le code source** — et il est la **chaîne vide en production**.
 *
 * Dans les deux cas, quiconque peut lire une ligne peut en forger une autre :
 * la chaîne cesse d'être une preuve. Le pire n'était pas la faiblesse, c'était
 * le mensonge : `verifyChain()` répondait **`true`**, et l'API `valid: true`,
 * sur un journal entièrement réécrivable.
 *
 * Ces gardes vérifient qu'on ne prétend plus prouver ce qu'on ne peut pas prouver.
 */

use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * ⚠️ Depuis P5-HMAC-002, `AuditHashChain` lit
 * `config('services.audit.hash_chain_secret')` et non plus `env()` brut. Or
 * `config/` est resolu UNE FOIS a l'amorcage : une variable d'environnement
 * posee apres coup n'y arrive jamais. Sans la ligne `config([...])`, ces gardes
 * mesureraient la valeur d'amorcage quel que soit le secret qu'elles croient
 * poser -- c'est-a-dire qu'elles mesureraient autre chose que ce qu'elles
 * annoncent, le defaut meme que tout ce lot poursuit.
 */
function imposerSecretChaine(?string $valeur): void
{
    $cle = 'AUDIT_HASH_CHAIN_SECRET';
    if ($valeur === null) {
        unset($_SERVER[$cle], $_ENV[$cle]);
        putenv($cle);
        config(['services.audit.hash_chain_secret' => '']);

        return;
    }
    $_SERVER[$cle] = $valeur;
    $_ENV[$cle] = $valeur;
    putenv("{$cle}={$valeur}");
    config(['services.audit.hash_chain_secret' => $valeur]);
}

/** Un compte admin, seul role autorise a lire l'etat de la chaine. */
function compteAuditeur(): User
{
    test()->seed(PermissionsAndRolesSeeder::class);

    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'aud-' . Str::random(8),
        'name' => 'Espace audit',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'aud-' . Str::random(6) . '@audit.test',
        'name' => 'Auditeur',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspace->id);
    $user->assignRole('admin');

    return $user;
}

afterEach(function () {
    imposerSecretChaine(null);
});

test('B16-001 — sans secret, la chaine ne se declare JAMAIS valide', function () {
    imposerSecretChaine('');
    $chaine = new AuditHashChain;

    $chaine->record(['method' => 'GET', 'path' => '/test', 'status' => 200]);

    // La chaîne est parfaitement cohérente avec elle-même — et ne prouve rien.
    expect($chaine->secretEstUtilisable())->toBeFalse();
    expect($chaine->verifyChain())->toBeFalse();
});

test('B16-001 — le secret de developpement publie dans le code est REFUSE', function () {
    imposerSecretChaine(AuditHashChain::SECRET_DE_DEVELOPPEMENT);
    $chaine = new AuditHashChain;

    expect($chaine->secretEstUtilisable())->toBeFalse();
    expect($chaine->verifyChain())->toBeFalse();
    expect($chaine->raisonSecretInutilisable())->toContain('developpement');
});

test('B16-001 — TEMOIN : avec un vrai secret, une chaine intacte est valide', function () {
    imposerSecretChaine('un-secret-de-chaine-reellement-secret-2026');
    $chaine = new AuditHashChain;

    $chaine->record(['method' => 'GET', 'path' => '/a', 'status' => 200]);
    $chaine->record(['method' => 'POST', 'path' => '/b', 'status' => 201]);

    // Sans ce témoin, un correctif qui répondrait TOUJOURS false passerait pour
    // une réussite.
    expect($chaine->secretEstUtilisable())->toBeTrue();
    expect($chaine->verifyChain())->toBeTrue();
});

test('B16-001 — TEMOIN : avec un vrai secret, une ligne falsifiee est detectee', function () {
    imposerSecretChaine('un-secret-de-chaine-reellement-secret-2026');
    $chaine = new AuditHashChain;

    $chaine->record(['method' => 'GET', 'path' => '/origine', 'status' => 200]);

    // On réécrit le chemin sans toucher aux condensés : c'est la falsification
    // que la chaîne est censée voir.
    DB::table('audit_logs')->update(['path' => '/falsifie']);

    expect($chaine->verifyChain())->toBeFalse();
});

test('B16-001 — l endpoint dit POURQUOI il ne peut pas verifier', function () {
    imposerSecretChaine('');

    $utilisateur = compteAuditeur();

    $reponse = $this->actingAs($utilisateur)->getJson('/api/v1/audit-logs/verify-chain');

    $reponse->assertOk();
    expect($reponse->json('valid'))->toBeFalse();
    expect($reponse->json('verifiable'))->toBeFalse();
    expect($reponse->json('raison'))->toContain('AUDIT_HASH_CHAIN_SECRET');
});
