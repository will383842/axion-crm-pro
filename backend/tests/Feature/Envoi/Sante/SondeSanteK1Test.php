<?php

use App\Envoi\Sante\SondeSanteK1;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Serveur\SignatureEnvoi;
use App\Envoi\Smtp\ResolveurHote;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::now('UTC')->startOfSecond());
    $this->pki = [];
    foreach (['ca', 'cert', 'cle'] as $n) {
        $f = (string) tempnam(sys_get_temp_dir(), 'envoi-k15-' . $n);
        file_put_contents($f, "-----BEGIN FAKE-----\n-----END FAKE-----\n");
        $this->pki[$n] = $f;
    }
    config([
        'envoi.reel_actif' => true, 'envoi.k1_actif' => true,
        'envoi.serveur.secrets' => ['srv1' => 'secret-de-test'], 'envoi.serveur.urls' => ['srv1' => 'https://envoi.exemple.test'],
        'envoi.serveur.ca_path' => $this->pki['ca'], 'envoi.serveur.client_cert_path' => $this->pki['cert'], 'envoi.serveur.client_key_path' => $this->pki['cle'],
        'envoi.serveur.tolere_transaction_englobante' => true,
    ]);
    app()->instance(ResolveurHote::class, new class implements ResolveurHote
    {
        public function resoudre(string $hote): array
        {
            return ['93.184.216.34'];
        }
    });
    $this->ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'T', 'slug' => 'k15k1-' . substr($this->ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    WorkspaceContext::run($this->ws, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $this->ws, 'source' => 'srv1', 'url_base' => 'https://zz.invalid']));
});

afterEach(function () {
    Carbon::setTestNow();
    foreach ($this->pki as $f) {
        @unlink($f);
    }
});

function reponseSanteSignee(array $donnees, int $statut = 200, bool $avecEspace = true): Closure
{
    return function (Request $r) use ($donnees, $statut, $avecEspace) {
        $ws = $r->header('X-Envoi-Workspace')[0];
        $corps = json_encode($avecEspace ? $donnees + ['workspace_id' => $ws] : $donnees, JSON_THROW_ON_ERROR);
        $sig = SignatureEnvoi::signer('secret-de-test', SignatureEnvoi::chargeReponse($r->header('X-Envoi-Nonce')[0], $ws, $statut, $corps));

        return Http::response($corps, $statut, ['X-Envoi-Signature' => SignatureEnvoi::entete('1', $sig)]);
    };
}

$mesuresOk = ['file_age_s' => 3, 'disque_libre_pct' => 55.5, 'memoire_libre_pct' => 40.0, 'certificat_jours' => 60, 'tunnel_ok' => true, 'ntp_synchro' => true, 'horloge_unix' => 1_780_000_000];

test('E8 : une réponse signée avec des mesures valides donne les mesures ; la requête est un GET signé de /v1/sante', function () use ($mesuresOk) {
    Http::fake(['*' => reponseSanteSignee(['mesures' => $mesuresOk])]);
    $m = app(SondeSanteK1::class)->mesurer($this->ws, 'srv1');
    expect($m->ageFileSecondes)->toBe(3)->and($m->disqueLibrePct)->toBe(55.5)->and($m->horlogeUnix)->toBe(1_780_000_000);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), '/v1/sante') && $r->header('X-Envoi-Signature')[0] !== '');
});

test('E8 : une réponse signée SANS mesures valides = sonde en échec (l’homme mort compte ce silence)', function (array $corps) {
    Http::fake(['*' => reponseSanteSignee($corps)]);
    expect(fn () => app(SondeSanteK1::class)->mesurer($this->ws, 'srv1'))->toThrow(ServeurEnvoiRefuse::class, 'sans mesure valide');
})->with([
    'clé mesures absente' => [['ok' => true]],
    'mesures vides' => [['mesures' => []]],
    'mesures non tableau' => [['mesures' => 'ok']],
    'mesures toutes invalides' => [['mesures' => ['file_age_s' => 'abc', 'disque_libre_pct' => -4, 'tunnel_ok' => 'oui']]],
]);

test('E4 : un 401 SIGNÉ « horloge_hors_fenetre » donne un refus de cause horloge ; un autre 401 signé reste un refus ordinaire', function (string $erreur, bool $horloge) {
    Http::fake(['*' => reponseSanteSignee(['erreur' => $erreur], 401)]);
    try {
        app(SondeSanteK1::class)->mesurer($this->ws, 'srv1');
        $this->fail('refus attendu');
    } catch (ServeurEnvoiRefuse $e) {
        expect($e->estHorloge())->toBe($horloge)->and($e->estConfiguration())->toBeFalse();
    }
})->with([['horloge_hors_fenetre', true], ['non_autorise', false]]);

test('I1 : le 401 signé « horloge_hors_fenetre » DOIT porter workspace_id (contrat) ; sans lui, refus ordinaire, jamais « horloge »', function () {
    Http::fake(['*' => reponseSanteSignee(['erreur' => 'horloge_hors_fenetre'], 401, false)]); // le helper n'ajoute PAS workspace_id
    try {
        app(SondeSanteK1::class)->mesurer($this->ws, 'srv1');
        $this->fail('refus attendu');
    } catch (ServeurEnvoiRefuse $e) {
        expect($e->estHorloge())->toBeFalse();
    }
});

test('E4 : un 401 NON signé (passerelle actuelle) reste un refus ordinaire, jamais pris pour l’horloge', function () {
    Http::fake(['*' => Http::response('{"erreur":"horloge_hors_fenetre"}', 401)]);
    try {
        app(SondeSanteK1::class)->mesurer($this->ws, 'srv1');
        $this->fail('refus attendu');
    } catch (ServeurEnvoiRefuse $e) {
        expect($e->estHorloge())->toBeFalse();
    }
});

test('secret ou adresse absents de la configuration => refus de cause CONFIGURATION', function (string $cle) {
    config(['envoi.serveur.' . $cle => []]);
    Http::fake();
    try {
        app(SondeSanteK1::class)->mesurer($this->ws, 'srv1');
        $this->fail('refus attendu');
    } catch (ServeurEnvoiRefuse $e) {
        expect($e->estConfiguration())->toBeTrue()->and($e->estHorloge())->toBeFalse();
    }
    Http::assertNothingSent();
})->with(['secrets', 'urls']);
