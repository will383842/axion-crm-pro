<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\CodeCourriel\CanalCodeConnexion;
use App\Services\Auth\CodeCourriel\FauxCanalCodeConnexion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * G3-F — `crm:compte:double-auth` : passe UN compte en double authentification par courriel,
 * ou le ramène à l'application. Comptes fictifs en example.test, aucun courriel réel.
 */
afterEach(function () {
    app()->forgetInstance(CanalCodeConnexion::class);
    app()->offsetUnset(CanalCodeConnexion::class);
});

function g3fCompte(string $nom = 'a'): string
{
    $id = (string) Str::uuid();
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'g3f-' . Str::lower(Str::random(6)), 'name' => 'G3-F']);
    DB::table('users')->insert([
        'id' => $id, 'email' => "g3f-{$nom}@example.test", 'name' => 'Compte fictif',
        'current_workspace_id' => $espace->id,
    ]);

    return "g3f-{$nom}@example.test";
}

function g3fMethode(string $email): ?string
{
    return DB::table('users')->where('email', $email)->value('double_auth_methode');
}

function g3fCanalReelSimule(): void
{
    config(['double_auth.actif' => true]);
    app()->instance(CanalCodeConnexion::class, new class implements CanalCodeConnexion
    {
        public function envoyer(User $u, string $code, string $objet): void {}

        public function estReel(): bool
        {
            return true;
        }
    });
}

it('G3-F 1 : faux canal => refus, la méthode ne change pas', function () {
    $email = g3fCompte();
    app()->instance(CanalCodeConnexion::class, new FauxCanalCodeConnexion);

    $code = Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'email']);

    expect($code)->toBe(1)->and(g3fMethode($email))->toBeNull();
    expect(Artisan::output())->not->toContain($email);
});

it('G3-F 1b : sans canal lié et envoi réel éteint (défaut) => refus', function () {
    $email = g3fCompte();
    config(['envoi.reel_actif' => false]);

    expect(Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'email']))->toBe(1)
        ->and(g3fMethode($email))->toBeNull();
});

it('G3-F 2 : canal réel simulé => « email » posé, sur ce seul compte, rien n\'est supprimé', function () {
    $a = g3fCompte('a');
    $b = g3fCompte('b');
    g3fCanalReelSimule();
    $avant = DB::table('users')->count();

    $code = Artisan::call('crm:compte:double-auth', ['email' => $a, '--methode' => 'email']);

    expect($code)->toBe(0)
        ->and(g3fMethode($a))->toBe('email')
        ->and(g3fMethode($b))->toBeNull()
        ->and(DB::table('users')->count())->toBe($avant);
});

it('G3-F 3 : --methode=aucune => refus, même avec un canal réel', function () {
    $email = g3fCompte();
    g3fCanalReelSimule();
    DB::table('users')->where('email', $email)->update(['double_auth_methode' => 'email']);

    expect(Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'aucune']))->toBe(1)
        ->and(g3fMethode($email))->toBe('email');
});

it('G3-F 3b : méthode absente ou inconnue => refus', function () {
    $email = g3fCompte();
    g3fCanalReelSimule();

    expect(Artisan::call('crm:compte:double-auth', ['email' => $email]))->toBe(1)
        ->and(Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'sms']))->toBe(1)
        ->and(g3fMethode($email))->toBeNull();
});

it('G3-F 4 : retour à l\'application (totp) possible même avec le faux canal', function () {
    $email = g3fCompte();
    DB::table('users')->where('email', $email)->update(['double_auth_methode' => 'email', 'totp_enabled_at' => now()]);
    app()->instance(CanalCodeConnexion::class, new FauxCanalCodeConnexion);

    expect(Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'totp']))->toBe(0)
        ->and(g3fMethode($email))->toBe('totp');
});

it('G3-F 4b : compte sans TOTP inscrit => --methode=totp refusé, méthode inchangée', function () {
    $email = g3fCompte();
    DB::table('users')->where('email', $email)->update(['double_auth_methode' => 'email']);

    $code = Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'totp']);

    expect($code)->toBe(1)->and(g3fMethode($email))->toBe('email');
    expect(Artisan::output())->not->toContain($email);
});

it('G3-F 2b : double_auth.actif éteint (canal réel simulé) => refus, rien ne change', function () {
    $email = g3fCompte();
    g3fCanalReelSimule();
    config(['double_auth.actif' => false]);

    expect(Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'email']))->toBe(1)
        ->and(g3fMethode($email))->toBeNull();
});

it('G3-F 2c : first_login_completed_at posé s\'il était nul, conservé sinon', function () {
    $nul = g3fCompte('nul');
    $deja = g3fCompte('deja');
    $date = '2026-01-02 03:04:05';
    DB::table('users')->where('email', $deja)->update(['first_login_completed_at' => $date]);
    g3fCanalReelSimule();

    expect(Artisan::call('crm:compte:double-auth', ['email' => $nul, '--methode' => 'email']))->toBe(0)
        ->and(Artisan::call('crm:compte:double-auth', ['email' => $deja, '--methode' => 'email']))->toBe(0);

    expect(DB::table('users')->where('email', $nul)->value('first_login_completed_at'))->not->toBeNull();
    expect(substr((string) DB::table('users')->where('email', $deja)->value('first_login_completed_at'), 0, 19))->toBe($date);
});

it('G3-F 2d : piste d\'audit compte.double_auth.methode, sans adresse', function () {
    $email = g3fCompte();
    g3fCanalReelSimule();

    Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'email']);

    $ligne = DB::table('business_events')->where('action', 'compte.double_auth.methode')->first();
    expect($ligne)->not->toBeNull();
    $ctx = json_decode((string) $ligne->context, true);
    expect($ctx['methode'])->toBe('email')->and($ctx)->toHaveKey('user_id');
    expect((string) $ligne->context)->not->toContain('example.test');
});

it('G3-F 6 : après activation, parcours complet (connexion, requires_2fa, code, accès)', function () {
    $domaine = trim((string) (array_values(array_filter((array) config('sanctum.stateful')))[0] ?? 'localhost'));
    $this->withServerVariables(['REMOTE_ADDR' => '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254)]);
    $this->withHeader('Origin', 'https://' . $domaine);
    $this->withSession(['_token' => 'g3f-csrf'])->withHeader('X-CSRF-TOKEN', 'g3f-csrf');

    $mdp = 'MotDePasseFactice12345!';
    $email = g3fCompte('parcours');
    DB::table('users')->where('email', $email)->update(['password_hash' => password_hash($mdp, PASSWORD_BCRYPT, ['cost' => 4])]);
    $capteur = new class implements CanalCodeConnexion
    {
        public ?string $code = null;

        public function envoyer(User $u, string $code, string $objet): void
        {
            $this->code = $code;
        }

        public function estReel(): bool
        {
            return true;
        }
    };
    config(['double_auth.actif' => true]);
    app()->instance(CanalCodeConnexion::class, $capteur);

    expect(Artisan::call('crm:compte:double-auth', ['email' => $email, '--methode' => 'email']))->toBe(0);

    $r = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $mdp]);
    foreach ($r->baseResponse->headers->getCookies() as $c) {
        if ((string) $c->getValue() !== '') {
            $this->withCookie($c->getName(), $c->getValue());
        }
    }
    app('auth')->forgetGuards();
    expect($r->status())->toBe(200)->and($r->json('requires_2fa'))->toBeTrue();
    expect($capteur->code)->toMatch('/^\d{6}$/');

    $this->getJson('/api/v1/dashboard/stats')->assertStatus(403)->assertJsonPath('error', 'two_factor_required');
    $this->postJson('/api/v1/auth/code-email/verifier', ['code' => $capteur->code])->assertOk();
    $this->getJson('/api/v1/dashboard/stats')->assertOk();
});

it('G3-F 5 : compte inconnu => code 1, message neutre sans l\'adresse', function () {
    g3fCanalReelSimule();

    $code = Artisan::call('crm:compte:double-auth', ['email' => 'inconnu@example.test', '--methode' => 'email']);

    expect($code)->toBe(1)->and(Artisan::output())->not->toContain('inconnu@example.test');
});
