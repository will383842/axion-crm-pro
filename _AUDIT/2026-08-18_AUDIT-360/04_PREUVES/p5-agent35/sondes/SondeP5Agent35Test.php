<?php

/**
 * SONDE DE CONTRE-VERIFICATION P5 — agent 35.
 * Fichier NON versionne, pose par l'agent de contre-verification. Il ne garde
 * rien : il MESURE ce que les gardes de l'agent 35 ne mesurent pas.
 */

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function ipP5(): string
{
    return '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
}

function userP5(string $email, ?string $totp = null, bool $premierLoginFait = true, string $mdp = 'CorrectPassword12345!'): User
{
    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'p5-' . Str::random(8), 'name' => 'P5']);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'P5',
        'password_hash' => password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => $premierLoginFait ? now() : null,
        'totp_secret' => $totp,
        'totp_enabled_at' => $totp ? now() : null,
    ]);
}

beforeEach(function () {
    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $csrf = 'p5-csrf';
    $this->withServerVariables(['REMOTE_ADDR' => ipP5()]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf);
});

function connexionP5(string $email, string $mdp = 'CorrectPassword12345!'): \Illuminate\Testing\TestResponse
{
    $t = test();
    $r = $t->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $mdp]);
    foreach ($r->baseResponse->headers->getCookies() as $c) {
        if ((string) $c->getValue() !== '') {
            $t->withCookie($c->getName(), $c->getValue());
        }
    }
    try {
        $t->withHeader('X-CSRF-TOKEN', app('session.store')->token());
    } catch (\Throwable) {
    }
    app('auth')->forgetGuards();

    return $r;
}

// ── [P5-A] GET /users : quel statut REELLEMENT, code casse ou non ? ──────────
test('P5-A — GET /users : statut et corps exacts', function () {
    $u = userP5('p5-users@p5.test');
    connexionP5($u->email);

    $r = test()->getJson('/api/v1/users');
    fwrite(STDERR, "\n[P5-A] GET /users statut=" . $r->status() . " corps=" . $r->getContent() . "\n");

    expect($r->status())->toBeInt();
});

// ── [P5-B] Impasse de la premiere connexion ─────────────────────────────────
test('P5-B — compte neuf : que se passe-t-il si l ecran ne sait que VERIFIER ?', function () {
    $u = userP5('p5-neuf@p5.test', premierLoginFait: false);
    $login = connexionP5($u->email);

    $metier = test()->getJson('/api/v1/contacts');
    $verif = test()->postJson('/api/v1/auth/2fa/verify', ['code' => '123456']);
    $setup = test()->postJson('/api/v1/auth/2fa/setup');

    fwrite(STDERR, sprintf(
        "\n[P5-B] login=%d requires_2fa=%s | /contacts=%d %s | 2fa/verify=%d %s | 2fa/setup=%d\n",
        $login->status(),
        var_export($login->json('requires_2fa'), true),
        $metier->status(),
        (string) $metier->json('error'),
        $verif->status(),
        substr((string) $verif->getContent(), 0, 160),
        $setup->status(),
    ));

    expect($login->status())->toBe(200);
});

// ── [P5-C] La 2FA est-elle contournable par un jeton d API ? ────────────────
test('P5-C — jeton d API et 2FA exigee', function () {
    $g = new Google2FA();
    $u = userP5('p5-jeton2fa@p5.test', totp: $g->generateSecretKey());
    $jeton = $u->createToken('p5')->plainTextToken;

    app('auth')->forgetGuards();
    $r = test()->withHeaders(['Authorization' => 'Bearer ' . $jeton])->getJson('/api/v1/contacts');

    fwrite(STDERR, "\n[P5-C] /contacts avec Bearer, compte 2FA ACTIVE, sans /2fa/verify : statut=" . $r->status()
        . " erreur=" . (string) $r->json('error') . "\n");

    expect($r->status())->toBeInt();
});

// ── [P5-D] Enumeration par le temps SANS memorisation statique ──────────────
// En production l'API est servie par `php -S` : chaque requete est un cycle PHP
// neuf, donc la propriete statique `$hachagesFactices` NE SURVIT PAS d'une
// requete a l'autre. La garde F35-009 mesure 5 requetes dans UN SEUL processus :
// seule la premiere paie la fabrication du hachage factice, les 4 autres la
// reutilisent, et c'est la mediane des 4 qui est retenue.
// On remet ici la condition de production : on vide la statique avant CHAQUE
// tentative sur compte inconnu.
test('P5-D — enumeration par le temps, statique videe a chaque requete', function () {
    config(['hashing.bcrypt.rounds' => 10]);
    app()->forgetInstance('hash');
    app()->forgetInstance('hash.driver');

    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'p5-' . Str::random(8), 'name' => 'P5']);
    User::create([
        'id' => (string) Str::uuid(),
        'email' => 'p5-connu@p5.test',
        'name' => 'P5',
        'password_hash' => password_hash('CorrectPassword12345!', PASSWORD_BCRYPT, ['cost' => 10]),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => now(),
    ]);

    $viderStatique = function () {
        $r = new ReflectionClass(AuthService::class);
        if ($r->hasProperty('hachagesFactices')) {
            $p = $r->getProperty('hachagesFactices');
            $p->setAccessible(true);
            $p->setValue(null, []);
        }
    };

    $mesure = function (string $email, bool $vider) use ($viderStatique): float {
        $t = [];
        for ($i = 0; $i < 5; $i++) {
            if ($vider) {
                $viderStatique();
            }
            $d = microtime(true);
            test()->withServerVariables(['REMOTE_ADDR' => ipP5()])
                ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'MauvaisMotDePasse1!']);
            $t[] = microtime(true) - $d;
        }
        sort($t);

        return $t[2];
    };

    $connu = $mesure('p5-connu@p5.test', false);
    $inconnuMemorise = $mesure('p5-jamais-vu@p5.test', false);
    $inconnuFroid = $mesure('p5-jamais-vu2@p5.test', true);

    fwrite(STDERR, sprintf(
        "\n[P5-D] medianes : compte connu %.1f ms | inconnu (statique CHAUDE, condition de la garde) %.1f ms (rapport %.2f)"
        . " | inconnu (statique VIDEE, condition de php -S) %.1f ms (rapport %.2f)\n",
        $connu * 1000,
        $inconnuMemorise * 1000,
        max($connu, $inconnuMemorise) / max(min($connu, $inconnuMemorise), 1e-6),
        $inconnuFroid * 1000,
        max($connu, $inconnuFroid) / max(min($connu, $inconnuFroid), 1e-6),
    ));

    expect($connu)->toBeFloat();
});
