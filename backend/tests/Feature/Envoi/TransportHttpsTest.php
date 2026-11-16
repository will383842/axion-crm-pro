<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Kumo\CoupureTransport;
use App\Envoi\Serveur\Kumo\EchecAuthentification;
use App\Envoi\Serveur\Kumo\InjectionRetiree;
use App\Envoi\Serveur\Kumo\InterrogeurEtatHttps;
use App\Envoi\Serveur\Kumo\RegistreMemoire;
use App\Envoi\Serveur\Kumo\TransportHttpsSigne;
use App\Envoi\Serveur\ServeurEnvoiKumo;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Serveur\SignatureEnvoi;
use App\Envoi\Smtp\ResolveurHote;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Envoi\DepotMemoire;

uses(TestCase::class, RefreshDatabase::class);

const HTTPS_ID = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const HTTPS_VERP = 'rb-0123456789abcdef0123456789abcdef0123456789@bounce.client.example.test';
const HTTPS_CORPS = "From: a@client.example.test\r\nTo: b@dest.example.test\r\nList-Unsubscribe: <https://client.example.test/u/x>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\nSubject: Bonjour\r\n\r\nCorps du message\r\n";

/** Réponse signée par la passerelle factice (liée au nonce et à l'espace de la requête). */
function httpsReponse(array $donnees, int $statut = 202, ?string $ws = null, array $entetes = []): Closure
{
    return function (Request $r) use ($donnees, $statut, $ws, $entetes) {
        $espace = $ws ?? $r->header('X-Envoi-Workspace')[0];
        $corps = json_encode($donnees + ['workspace_id' => $espace], JSON_THROW_ON_ERROR);
        $sig = SignatureEnvoi::signer('secret-de-test', SignatureEnvoi::chargeReponse($r->header('X-Envoi-Nonce')[0], $espace, $statut, $corps));

        return Http::response($corps, $statut, ['X-Envoi-Signature' => SignatureEnvoi::entete('1', $sig)] + $entetes);
    };
}

function httpsMessage(string $ws, ?string $id = HTTPS_ID, ?string $chemin = HTTPS_VERP): MessageAEnvoyer
{
    return new MessageAEnvoyer($ws, 'temoin@example.test', 'a@client.example.test', 'Bonjour', HTTPS_CORPS, 'cle-1', $id, $chemin);
}

beforeEach(function () {
    $pki = [];
    foreach (['ca', 'cert', 'cle'] as $n) {
        $f = (string) tempnam(sys_get_temp_dir(), 'envoi-c24-' . $n);
        file_put_contents($f, "-----BEGIN FAKE-----\n-----END FAKE-----\n");
        $pki[$n] = $f;
    }
    config([
        'crm.ingest.replay_store' => 'array',
        'envoi.serveur.secrets' => ['srv1' => 'secret-de-test'],
        'envoi.serveur.urls' => ['srv1' => 'https://envoi.exemple.test'],
        'envoi.serveur.ca_path' => $pki['ca'],
        'envoi.serveur.client_cert_path' => $pki['cert'],
        'envoi.serveur.client_key_path' => $pki['cle'],
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
    DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'T', 'slug' => 'c24-' . substr($this->ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    WorkspaceContext::run($this->ws, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $this->ws, 'source' => 'srv1', 'url_base' => 'https://zz.invalid']));
    $this->transport = fn () => new TransportHttpsSigne(app(ClientServeurEnvoiHmac::class));
    config(['envoi.smtp_tolere_transaction_englobante' => true]);
    $this->rep = httpsReponse([]);
    Http::preventStrayRequests();
    Http::fake(['*' => fn (Request $r) => ($this->rep)($r)]);
});

function httpsOuvrir(): void
{
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true]);
}

test('1. le message part TEL QUEL en base64, sans X-Envoi-Jeton ni reconstruction', function () {
    httpsOuvrir();
    $this->rep = httpsReponse(['id_injection' => HTTPS_ID, 'etat' => 'en_file']);
    $code = ($this->transport)()->envoyer(httpsMessage($this->ws), 'jeton', 'srv1');
    expect($code)->toBe(250);
    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) {
        $c = json_decode($r->body(), true);

        return $r->url() === 'https://envoi.exemple.test' . SignatureEnvoi::CHEMIN_INJECTIONS
            && array_keys($c) === ['id_injection', 'mail_from', 'rcpt_to', 'message']
            && $c['id_injection'] === HTTPS_ID && $c['mail_from'] === HTTPS_VERP && $c['rcpt_to'] === 'temoin@example.test'
            && base64_decode($c['message'], true) === HTTPS_CORPS
            && str_contains(base64_decode($c['message']), 'List-Unsubscribe-Post: List-Unsubscribe=One-Click')
            && ! str_contains($r->body(), 'X-Envoi-Jeton') && ! str_contains(base64_decode($c['message']), 'X-Envoi-');
    });
});

test('2. chaque statut donne le bon code ou la bonne exception', function () {
    httpsOuvrir();
    $cas = [
        [429, ['code_smtp' => 451], 451],
        [422, ['erreur' => 'refus_definitif', 'code_smtp' => 550], 550],
    ];
    foreach ($cas as [$statut, $json, $attendu]) {
        $this->rep = httpsReponse($json, $statut);
        expect(($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1'))->toBe($attendu);
    }
    $exceptions = [
        [503, ['erreur' => 'indisponible'], fn ($e) => $e instanceof CoupureTransport && $e->apresData === false],
        [504, ['erreur' => 'issue_incertaine'], fn ($e) => $e instanceof CoupureTransport && $e->apresData === true],
        [409, ['erreur' => 'id_injection_deja_utilise'], fn ($e) => $e instanceof CoupureTransport && $e->apresData === true],
        [409, ['erreur' => 'retire'], fn ($e) => $e instanceof InjectionRetiree],
    ];
    foreach ($exceptions as [$statut, $json, $verif]) {
        $this->rep = httpsReponse($json, $statut);
        try {
            ($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1');
            $this->fail('exception attendue pour ' . $statut);
        } catch (Throwable $e) {
            expect($verif($e))->toBeTrue('statut ' . $statut . ' : ' . $e::class);
        }
    }
});

test('2b. 401 non signé = EchecAuthentification ; 401 signé horloge = coupure avant envoi + journal critique ; délai = incertain', function () {
    httpsOuvrir();
    $this->rep = fn () => Http::response('{"erreur":"non_autorise"}', 401);
    expect(fn () => ($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1'))->toThrow(EchecAuthentification::class);

    Log::spy();
    $this->rep = httpsReponse(['erreur' => 'horloge_hors_fenetre'], 401);
    try {
        ($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1');
        $this->fail('coupure attendue');
    } catch (CoupureTransport $e) {
        expect($e->apresData)->toBeFalse();
    }
    Log::shouldHaveReceived('critical')->once();

    $this->rep = fn () => throw new ConnectionException('timeout');
    try {
        ($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1');
        $this->fail('coupure attendue');
    } catch (CoupureTransport $e) {
        expect($e->apresData)->toBeTrue();
    }
});

test('2c. code SMTP absent ou hors plage : refus, jamais inventé', function () {
    httpsOuvrir();
    $this->rep = httpsReponse(['code_smtp' => 250], 429);
    expect(fn () => ($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1'))->toThrow(ServeurEnvoiRefuse::class);
    $this->rep = httpsReponse([], 422);
    expect(fn () => ($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1'))->toThrow(ServeurEnvoiRefuse::class);
});

test('3. sans idInjection ou sans chemin de retour : refus local, AUCUNE requête', function () {
    httpsOuvrir();
    $this->rep = httpsReponse([]);
    expect(fn () => ($this->transport)()->envoyer(httpsMessage($this->ws, null), 'j', 'srv1'))->toThrow(ServeurEnvoiRefuse::class);
    expect(fn () => ($this->transport)()->envoyer(httpsMessage($this->ws, HTTPS_ID, null), 'j', 'srv1'))->toThrow(ServeurEnvoiRefuse::class);
    Http::assertNothingSent();
});

test('4. interrupteurs éteints (défaut) : aucune requête, injection reportée', function () {
    $this->rep = httpsReponse([]);
    expect(fn () => ($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1'))->toThrow(ServeurEnvoiRefuse::class);
    $kumo = new ServeurEnvoiKumo($this->ws, 'srv1', ($this->transport)(), new InterrogeurEtatHttps(app(ClientServeurEnvoiHmac::class), $this->ws, 'srv1'), new RegistreMemoire, app(CommandeurServeur::class), new DepotMemoire, str_repeat('A', 40));
    $r = $kumo->injecter(httpsMessage($this->ws, HTTPS_ID, HTTPS_VERP));
    expect($r->accepte)->toBeFalse();
    Http::assertNothingSent();
});

test('5. réponse signée pour un autre espace : refus', function () {
    httpsOuvrir();
    $this->rep = httpsReponse(['id_injection' => HTTPS_ID, 'etat' => 'en_file'], 202, (string) Str::uuid());
    expect(fn () => ($this->transport)()->envoyer(httpsMessage($this->ws), 'j', 'srv1'))->toThrow(ServeurEnvoiRefuse::class);
});

function httpsScenario6(object $t): void
{
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true]);
    $t->rep = httpsReponse(['id_injection' => HTTPS_ID, 'etat' => 'en_file']);
    expect(($t->transport)()->envoyer(httpsMessage($t->ws), 'j', 'srv1'))->toBe(250);
    // Source de l'espace B (ou inexistante) : « source inconnue », aucune requête de plus.
    $autre = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $autre, 'name' => 'B', 'slug' => 'c24b-' . substr($autre, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    WorkspaceContext::run($autre, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $autre, 'source' => 'srvb', 'url_base' => 'https://zz.invalid']));
    config(['envoi.serveur.secrets' => ['srv1' => 'secret-de-test', 'srvb' => 'secret-b'], 'envoi.serveur.urls' => ['srv1' => 'https://envoi.exemple.test', 'srvb' => 'https://b.exemple.test']]);
    expect(fn () => ($t->transport)()->envoyer(httpsMessage($t->ws), 'j', 'srvb'))->toThrow(ServeurEnvoiRefuse::class, 'source inconnue');
    Http::assertSentCount(1);
}

test('6. preparer() lit les serveurs de l\'espace seulement (propriétaire)', function () {
    httpsScenario6($this);
});

test('6b. idem sous le rôle applicatif pgsql_app', function () {
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    httpsScenario6($this);
});

test('7. ServeurEnvoiKumo : 409 retire = opposition, compteur 5xx inchangé, état echec ; identifiant C1 repris', function () {
    httpsOuvrir();
    $registre = new RegistreMemoire;
    $kumo = new ServeurEnvoiKumo($this->ws, 'srv1', ($this->transport)(), new InterrogeurEtatHttps(app(ClientServeurEnvoiHmac::class), $this->ws, 'srv1'), $registre, app(CommandeurServeur::class), new DepotMemoire, str_repeat('A', 40));
    $this->rep = httpsReponse(['erreur' => 'retire'], 409);
    try {
        $kumo->injecter(httpsMessage($this->ws));
        $this->fail('opposition attendue');
    } catch (EnvoiRefuse $e) {
        expect($e->estOpposition())->toBeTrue($e->getMessage());
    }
    $ligne = $registre->lire($this->ws, 'cle-1');
    expect($ligne['etat'])->toBe('echec')->and($ligne['id'])->toBe(HTTPS_ID)->and($ligne['jeton'])->toBe(str_replace('-', '', HTTPS_ID));
    expect($registre->compter5xx($this->ws, 0))->toBe(0);

    // 422 définitif : compté comme 5xx, contrairement à « retire ».
    $this->rep = httpsReponse(['erreur' => 'refus_definitif', 'code_smtp' => 550], 422);
    expect(fn () => $kumo->injecter(new MessageAEnvoyer($this->ws, 'temoin@example.test', 'a@client.example.test', 'S', HTTPS_CORPS, 'cle-2', '1a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d', HTTPS_VERP)))->toThrow(RefusDefinitifServeur::class);
    expect($registre->compter5xx($this->ws, 0))->toBe(1);
});

test('8. InterrogeurEtatHttps : en_file/refuse/retire/incertain/inconnu', function () {
    httpsOuvrir();
    $i = new InterrogeurEtatHttps(app(ClientServeurEnvoiHmac::class), $this->ws, 'srv1');
    foreach (['en_file' => EtatMessage::EnFile, 'refuse' => EtatMessage::Refuse, 'retire' => EtatMessage::Refuse, 'incertain' => EtatMessage::Inconnu, 'inconnu' => EtatMessage::Inconnu, 'autre' => EtatMessage::Inconnu] as $etat => $attendu) {
        $this->rep = httpsReponse(['id_injection' => HTTPS_ID, 'etat' => $etat], 200);
        expect($i->etat(HTTPS_ID))->toBe($attendu);
    }
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), 'https://envoi.exemple.test/v1/injections/' . HTTPS_ID));
});
