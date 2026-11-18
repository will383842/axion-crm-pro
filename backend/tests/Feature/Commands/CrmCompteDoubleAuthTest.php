<?php

use App\Models\User;
use App\Services\Auth\CodeCourriel\CanalCodeConnexion;
use App\Services\Auth\CodeCourriel\FauxCanalCodeConnexion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * G3-F — `crm:compte-double-auth` : passe UN compte en double authentification par courriel,
 * ou le ramène à l'application. Comptes fictifs en example.test, aucun courriel réel.
 */
afterEach(function () {
    app()->forgetInstance(CanalCodeConnexion::class);
    app()->offsetUnset(CanalCodeConnexion::class);
});

function g3fCompte(string $nom = 'a'): string
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(['id' => $id, 'email' => "g3f-{$nom}@example.test", 'name' => 'Compte fictif']);

    return "g3f-{$nom}@example.test";
}

function g3fMethode(string $email): ?string
{
    return DB::table('users')->where('email', $email)->value('double_auth_methode');
}

function g3fCanalReelSimule(): void
{
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

    $code = Artisan::call('crm:compte-double-auth', ['email' => $email, '--methode' => 'email']);

    expect($code)->toBe(1)->and(g3fMethode($email))->toBeNull();
    expect(Artisan::output())->not->toContain($email);
});

it('G3-F 1b : sans canal lié et envoi réel éteint (défaut) => refus', function () {
    $email = g3fCompte();
    config(['envoi.reel_actif' => false]);

    expect(Artisan::call('crm:compte-double-auth', ['email' => $email, '--methode' => 'email']))->toBe(1)
        ->and(g3fMethode($email))->toBeNull();
});

it('G3-F 2 : canal réel simulé => « email » posé, sur ce seul compte, rien n\'est supprimé', function () {
    $a = g3fCompte('a');
    $b = g3fCompte('b');
    g3fCanalReelSimule();
    $avant = DB::table('users')->count();

    $code = Artisan::call('crm:compte-double-auth', ['email' => $a, '--methode' => 'email']);

    expect($code)->toBe(0)
        ->and(g3fMethode($a))->toBe('email')
        ->and(g3fMethode($b))->toBeNull()
        ->and(DB::table('users')->count())->toBe($avant);
});

it('G3-F 3 : --methode=aucune => refus, même avec un canal réel', function () {
    $email = g3fCompte();
    g3fCanalReelSimule();
    DB::table('users')->where('email', $email)->update(['double_auth_methode' => 'email']);

    expect(Artisan::call('crm:compte-double-auth', ['email' => $email, '--methode' => 'aucune']))->toBe(1)
        ->and(g3fMethode($email))->toBe('email');
});

it('G3-F 3b : méthode absente ou inconnue => refus', function () {
    $email = g3fCompte();
    g3fCanalReelSimule();

    expect(Artisan::call('crm:compte-double-auth', ['email' => $email]))->toBe(1)
        ->and(Artisan::call('crm:compte-double-auth', ['email' => $email, '--methode' => 'sms']))->toBe(1)
        ->and(g3fMethode($email))->toBeNull();
});

it('G3-F 4 : retour à l\'application (totp) possible même avec le faux canal', function () {
    $email = g3fCompte();
    DB::table('users')->where('email', $email)->update(['double_auth_methode' => 'email']);
    app()->instance(CanalCodeConnexion::class, new FauxCanalCodeConnexion);

    expect(Artisan::call('crm:compte-double-auth', ['email' => $email, '--methode' => 'totp']))->toBe(0)
        ->and(g3fMethode($email))->toBe('totp');
});

it('G3-F 5 : compte inconnu => code 1, message neutre sans l\'adresse', function () {
    g3fCanalReelSimule();

    $code = Artisan::call('crm:compte-double-auth', ['email' => 'inconnu@example.test', '--methode' => 'email']);

    expect($code)->toBe(1)->and(Artisan::output())->not->toContain('inconnu@example.test');
});
