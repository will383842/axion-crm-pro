<?php

use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Models\User;
use App\Services\Auth\CodeCourriel\CanalCodeConnexion;
use App\Services\Auth\CodeCourriel\CanalCodeConnexionCourriel;
use App\Services\Auth\CodeCourriel\CanalFermeException;
use App\Services\Auth\CodeCourriel\CodesConnexionCourriel;
use App\Services\Auth\CodeCourriel\FauxCanalCodeConnexion;
use App\Services\Auth\CodeCourriel\TropDeRenvois;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot G3-A — canal du code de connexion par courriel et service des codes (aucune route).
 * Aucun courriel réel : le faux canal ou Mail::fake(). Comptes fictifs en example.test.
 * Les tests d'écriture (4, 5, 7) sont joués SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE) ET SOUS LE PROPRIÉTAIRE.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-10 09:00:00', 'UTC'));
    config(['double_auth.actif' => true, 'envoi.reel_actif' => false, 'crm.mock_mode' => true]);
    FauxCanalCodeConnexion::reinitialiser();
});

afterEach(function () {
    Carbon::setTestNow();
});

function ccUtilisateur(): User
{
    $id = (string) Str::uuid();
    DB::table('users')->insert(['id' => $id, 'email' => 'cc-' . substr($id, 0, 8) . '@example.test', 'name' => 'Compte fictif']);

    return User::query()->findOrFail($id);
}

/** Joue $f sous le mode : « app » = rôle applicatif non propriétaire, « proprio » = propriétaire. */
function ccSous(string $mode, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    $r = $f();
    DB::statement('RESET ROLE');

    return $r;
}

function ccService(): CodesConnexionCourriel
{
    return app(CodesConnexionCourriel::class);
}

function ccNbLignes(): int
{
    return DB::table('codes_connexion_courriel')->count();
}

function ccDernier(User $u): string
{
    return (string) FauxCanalCodeConnexion::dernierCode((string) $u->id);
}

it('G3-A 1 : drapeau éteint => CanalFermeException, aucune ligne, aucun canal appelé', function () {
    config(['double_auth.actif' => false]);
    $u = ccUtilisateur();

    expect(fn () => ccService()->emettre($u, 'connexion', null))->toThrow(CanalFermeException::class);
    expect(ccNbLignes())->toBe(0);
    expect(FauxCanalCodeConnexion::dernierCode((string) $u->id))->toBeNull();
});

it('G3-A 2 : ENVOI_REEL_ACTIF éteint => faux canal, rien ne part ; allumé et hors simulacre => vrai canal', function () {
    Mail::fake();
    $u = ccUtilisateur();

    expect(ccService()->canal())->toBeInstanceOf(FauxCanalCodeConnexion::class);
    expect(ccService()->canal()->estReel())->toBeFalse();
    ccService()->emettre($u, 'connexion', null);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    expect(ccDernier($u))->toMatch('/^\d{6}$/');

    // « false » (chaîne) ne doit JAMAIS compter pour vrai : la comparaison est stricte.
    config(['envoi.reel_actif' => 'false', 'crm.mock_mode' => false]);
    expect(ccService()->canal())->toBeInstanceOf(FauxCanalCodeConnexion::class);

    // Simulacre actif : faux canal même si l'envoi est allumé.
    config(['envoi.reel_actif' => true, 'crm.mock_mode' => true]);
    expect(ccService()->canal())->toBeInstanceOf(FauxCanalCodeConnexion::class);

    config(['envoi.reel_actif' => true, 'crm.mock_mode' => false]);
    $canal = ccService()->canal();
    expect($canal)->toBeInstanceOf(CanalCodeConnexionCourriel::class);
    expect($canal->estReel())->toBeTrue();
});

it('G3-A 2c : le vrai canal écrit sur le transport d\'authentification, sans lien, avec le code', function () {
    config(['mail.auth_mailer' => 'array']);
    $u = ccUtilisateur();
    $transport = Mail::mailer('array')->getSymfonyTransport();
    $avant = count($transport->messages());

    (new CanalCodeConnexionCourriel)->envoyer($u, '012345', 'connexion');

    $messages = $transport->messages();
    expect(count($messages))->toBe($avant + 1);
    $corps = $messages[$avant]->toString();
    expect($corps)->toContain('012345')->and($corps)->toContain((string) $u->email)->and($corps)->not->toContain('http');
});

it('G3-A 2b : un canal lié au conteneur est lu en premier', function () {
    $faux = new FauxCanalCodeConnexion;
    app()->instance(CanalCodeConnexion::class, $faux);
    config(['envoi.reel_actif' => true, 'crm.mock_mode' => false]);

    expect(ccService()->canal())->toBe($faux);
});

it('G3-A 3 : le code n\'est ni stocké en clair ni journalisé', function () {
    $journal = [];
    Log::listen(function ($m) use (&$journal) {
        $journal[] = $m->message . ' ' . json_encode($m->context);
    });
    $u = ccUtilisateur();
    ccService()->emettre($u, 'connexion', '203.0.113.7');
    $code = ccDernier($u);

    $ligne = (array) DB::table('codes_connexion_courriel')->first();
    expect(json_encode($ligne))->not->toContain($code);
    expect($ligne['code_hash'])->toBe(hash_hmac('sha256', $code, config('app.key')));
    expect(collect($journal)->contains(fn ($l) => str_contains($l, 'auth.code_courriel.faux_canal')))->toBeTrue();
    expect(collect($journal)->contains(fn ($l) => str_contains($l, $code)))->toBeFalse();
    expect(collect($journal)->contains(fn ($l) => str_contains($l, $u->email)))->toBeFalse();
});

it('G3-A 4 : bon code une seule fois ; 5 essais faux verrouillent ; expiré refusé', function (string $mode) {
    $u = ccUtilisateur();
    $svc = ccService();

    ccSous($mode, function () use ($u, $svc) {
        $svc->emettre($u, 'connexion', null);
        $code = ccDernier($u);
        expect($svc->verifier($u, 'connexion', $code))->toBeTrue();
        expect($svc->verifier($u, 'connexion', $code))->toBeFalse();
        expect(DB::table('codes_connexion_courriel')->whereNotNull('consomme_le')->count())->toBe(1);
    });

    ccSous($mode, function () use ($u, $svc) {
        $svc->emettre($u, 'geste', null);
        $bon = ccDernier($u);
        $faux = $bon === '000000' ? '000001' : '000000';
        for ($i = 0; $i < 5; $i++) {
            expect($svc->verifier($u, 'geste', $faux))->toBeFalse();
        }
        expect($svc->verifier($u, 'geste', $bon))->toBeFalse();
        expect((int) DB::table('codes_connexion_courriel')->where('objet', 'geste')->value('essais'))->toBe(5);
    });

    ccSous($mode, function () use ($u, $svc) {
        $svc->emettre($u, 'connexion', null);
        $code = ccDernier($u);
        Carbon::setTestNow(Carbon::now()->addMinutes(10)->addSecond());
        expect($svc->verifier($u, 'connexion', $code))->toBeFalse();
    });
})->with(['app', 'proprio']);

it('G3-A 4b : à la dixième minute moins une seconde le code est encore bon', function () {
    $u = ccUtilisateur();
    ccService()->emettre($u, 'connexion', null);
    $code = ccDernier($u);
    Carbon::setTestNow(Carbon::now()->addMinutes(9)->addSeconds(59));

    expect(ccService()->verifier($u, 'connexion', $code))->toBeTrue();
});

it('G3-A 5 : une seconde émission remplace la première, rien n\'est effacé', function (string $mode) {
    $u = ccUtilisateur();
    $svc = ccService();

    ccSous($mode, function () use ($u, $svc) {
        $svc->emettre($u, 'connexion', null);
        $ancien = ccDernier($u);
        $avant = ccNbLignes();
        Carbon::setTestNow(Carbon::now()->addMinute());
        $svc->emettre($u, 'connexion', null);
        $nouveau = ccDernier($u);

        expect(ccNbLignes())->toBe($avant + 1);
        expect(DB::table('codes_connexion_courriel')->whereNotNull('remplace_le')->count())->toBe(1);
        if ($ancien !== $nouveau) {
            expect($svc->verifier($u, 'connexion', $ancien))->toBeFalse();
        }
        expect($svc->verifier($u, 'connexion', $nouveau))->toBeTrue();
        expect(ccNbLignes())->toBe($avant + 1);
    });
})->with(['app', 'proprio']);

it('G3-A 5b : sous le rôle applicatif, effacer un code est refusé (42501)', function () {
    $u = ccUtilisateur();
    ccService()->emettre($u, 'connexion', null);

    $code = null;
    try {
        DB::transaction(function () {
            ccSous('app', fn () => DB::table('codes_connexion_courriel')->delete());
        });
    } catch (QueryException $e) {
        $code = (string) $e->getCode();
    }
    expect($code)->toBe('42501');
    expect(ccNbLignes())->toBe(1);
});

it('G3-A 6 : la quatrième émission en 10 minutes lève TropDeRenvois, la onzième minute rouvre', function () {
    $u = ccUtilisateur();
    $svc = ccService();
    $svc->emettre($u, 'connexion', null);
    $svc->renvoyer($u, null);
    $svc->renvoyer($u, null);

    expect(fn () => $svc->renvoyer($u, null))->toThrow(TropDeRenvois::class);
    expect(ccNbLignes())->toBe(3);

    Carbon::setTestNow(Carbon::now()->addMinutes(10)->addSecond());
    $svc->renvoyer($u, null);
    expect(ccNbLignes())->toBe(4);
});

it('G3-A 7 : le code de A ne valide pas B', function (string $mode) {
    $a = ccUtilisateur();
    $b = ccUtilisateur();
    $svc = ccService();

    ccSous($mode, function () use ($a, $b, $svc) {
        $svc->emettre($a, 'connexion', null);
        $codeA = ccDernier($a);
        expect($svc->verifier($b, 'connexion', $codeA))->toBeFalse();
        $svc->emettre($b, 'connexion', null);
        expect($svc->verifier($b, 'connexion', $codeA === ccDernier($b) ? '' : $codeA))->toBeFalse();
        expect($svc->verifier($a, 'connexion', $codeA))->toBeTrue();
    });
})->with(['app', 'proprio']);

it('G3-A 8 : adresse de compte opposée => aucun code, aucune ligne, aucune erreur visible', function () {
    $u = ccUtilisateur();
    DB::table('email_suppressions')->insert([
        'scope' => 'business',
        'email_hash' => ListeSuppression::empreinte((string) $u->email),
        'reason' => ListeSuppression::MANUEL,
        'source' => 'test',
        'occurrences' => 1,
    ]);
    $journal = [];
    Log::listen(function ($m) use (&$journal) {
        $journal[] = $m->message . ' ' . json_encode($m->context);
    });

    ccService()->emettre($u, 'connexion', null);

    expect(ccNbLignes())->toBe(0);
    expect(FauxCanalCodeConnexion::dernierCode((string) $u->id))->toBeNull();
    expect(collect($journal)->contains(fn ($l) => str_contains($l, 'auth.code_courriel.adresse_opposee')))->toBeTrue();
    expect(collect($journal)->contains(fn ($l) => str_contains($l, (string) $u->email)))->toBeFalse();
});

it('G3-A 8b : opposition PROPRE À L\'ESPACE (registre, domaine) => aucun code, sous app ET proprio', function (string $mode) {
    $u = ccUtilisateur();
    $ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $ws, 'slug' => 'cc-' . substr($ws, 0, 8), 'name' => 'Espace fictif']);
    DB::table('user_workspaces')->insert(['user_id' => $u->id, 'workspace_id' => $ws, 'role_slug' => 'owner']);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_oppositions_registre')->insert([
        'workspace_id' => $ws,
        'portee' => 'domaine',
        'empreinte' => RegistreOppositionManuel::empreinte('example.test'),
        'valeur' => 'example.test',
        'motif' => 'test',
        'source' => 'manuel',
        'auteur' => 'test',
    ]));

    ccSous($mode, fn () => ccService()->emettre($u, 'connexion', null));

    expect(ccNbLignes())->toBe(0);
    expect(FauxCanalCodeConnexion::dernierCode((string) $u->id))->toBeNull();
})->with(['app', 'proprio']);

it('G3-A 9 : si le canal échoue, la ligne reste (rien ne s\'efface)', function () {
    $u = ccUtilisateur();
    app()->instance(CanalCodeConnexion::class, new class implements CanalCodeConnexion
    {
        public function envoyer(User $u, string $code, string $objet): void
        {
            throw new RuntimeException('canal en panne');
        }

        public function estReel(): bool
        {
            return false;
        }
    });

    expect(fn () => ccService()->emettre($u, 'connexion', null))->toThrow(RuntimeException::class);
    expect(ccNbLignes())->toBe(1);
});
