<?php

use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Envoi\Serveur\JournalCommandes;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Serveur\SignatureEnvoi;
use App\Envoi\Serveur\VerificateurHmacEnvoi;
use App\Envoi\Smtp\ResolveurHote;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'crm.ingest.replay_store' => 'array',
        'envoi.reel_actif' => true,
        'envoi.serveur.secret' => 'secret-de-test',
        'envoi.serveur.url' => 'https://envoi.exemple.test',
        'envoi.serveur.tolere_transaction_englobante' => true, // RefreshDatabase ouvre une transaction englobante
    ]);
    app()->instance(ResolveurHote::class, new class implements ResolveurHote
    {
        public function resoudre(string $hote): array
        {
            return ['93.184.216.34'];
        }
    });
    $this->ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'T', 'slug' => 'k1-' . substr($this->ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    WorkspaceContext::run($this->ws, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $this->ws, 'source' => 'srv1', 'url_base' => 'https://zz.invalid']));
});

/** Réponse signée comme le ferait le serveur, liée au nonce de la requête. */
function envoiReponseSignee(array $donnees, ?string $ws = null, string $secret = 'secret-de-test', int $statut = 200): Closure
{
    return function (Request $r) use ($donnees, $ws, $secret, $statut) {
        $espace = $ws ?? $r->header('X-Envoi-Workspace')[0];
        $corps = json_encode($donnees + ['workspace_id' => $espace], JSON_THROW_ON_ERROR);
        $sig = SignatureEnvoi::signer($secret, SignatureEnvoi::chargeReponse($r->header('X-Envoi-Nonce')[0], $espace, $statut, $corps));

        return Http::response($corps, $statut, ['X-Envoi-Signature' => SignatureEnvoi::entete('1', $sig)]);
    };
}

function envoiCmd(): string
{
    return (string) Str::uuid();
}

test('journal : intention unique par espace, rejeu = no-op, autre corps = refus', function () {
    $j = app(JournalCommandes::class);
    $id = envoiCmd();
    expect($j->enregistrer($this->ws, $id, 'pause'))->toBeTrue()
        ->and($j->enregistrer($this->ws, $id, 'pause'))->toBeFalse()
        ->and(fn () => $j->enregistrer($this->ws, $id, 'retrait'))->toThrow(ServeurEnvoiRefuse::class);
    expect(WorkspaceContext::run($this->ws, fn () => DB::table('envoi_journal_commandes')->whereNotNull('commande_id')->count()))->toBe(1);
});

test('journal : le même commande_id peut exister dans deux espaces (UNIQUE par espace)', function () {
    $ws2 = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $ws2, 'name' => 'T2', 'slug' => 'k1b-' . substr($ws2, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $id = envoiCmd();
    $j = app(JournalCommandes::class);
    expect($j->enregistrer($this->ws, $id, 'pause'))->toBeTrue()->and($j->enregistrer($ws2, $id, 'pause'))->toBeTrue();
});

test('commander : requête signée (méthode+chemin+nonce+corps) acceptée ; second appel après acquittement = déjà émis', function () {
    Http::fake(['*' => envoiReponseSignee([])]);
    $client = app(CommandeurServeur::class);
    $id = envoiCmd();
    expect($client)->toBeInstanceOf(ClientServeurEnvoiHmac::class)
        ->and($client->commander($this->ws, 'srv1', $id, 'pause'))->toBeTrue()
        ->and($client->commander($this->ws, 'srv1', $id, 'pause'))->toBeFalse();
    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) {
        $ent = $r->header('X-Envoi-Signature')[0];
        $v = new VerificateurHmacEnvoi;

        return $r->url() === 'https://envoi.exemple.test/v1/commandes/pause'
            && str_starts_with($ent, 'k=1,sha256=')
            && $v->verifier('secret-de-test', 'srv1', $r->header('X-Envoi-Timestamp')[0], $r->header('X-Envoi-Nonce')[0], $ent, $r->body(), 'POST', '/v1/commandes/pause') === 200;
    });
});

test('COMMANDE PERDUE : panne réseau = pas d’acquittement ; renvoi avec le même commande_id', function () {
    $id = envoiCmd();
    Http::fake(['*' => Http::response('boom', 500)]);
    $client = app(CommandeurServeur::class);
    expect(fn () => $client->commander($this->ws, 'srv1', $id, 'pause'))->toThrow(ServeurEnvoiRefuse::class);
    expect(WorkspaceContext::run($this->ws, fn () => DB::table('envoi_journal_commandes')->where('commande', JournalCommandes::ACQUITTEMENT)->count()))->toBe(0);

    Http::fake(['*' => envoiReponseSignee([])]);
    expect($client->commander($this->ws, 'srv1', $id, 'pause'))->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => str_contains($r->body(), $id));
    expect(WorkspaceContext::run($this->ws, fn () => DB::table('envoi_journal_commandes')->where('commande', JournalCommandes::ACQUITTEMENT)->count()))->toBe(1);
});

test('même commande_id avec un autre corps : refus, aucun appel', function () {
    Http::fake(['*' => envoiReponseSignee([])]);
    $client = app(CommandeurServeur::class);
    $id = envoiCmd();
    $client->commander($this->ws, 'srv1', $id, 'pause');
    expect(fn () => $client->commander($this->ws, 'srv1', $id, 'retrait'))->toThrow(ServeurEnvoiRefuse::class);
    Http::assertSentCount(1);
});

test('commande_id non UUID ou nom de commande invalide : refus', function () {
    Http::fake();
    $c = app(CommandeurServeur::class);
    expect(fn () => $c->commander($this->ws, 'srv1', 'cmd-1', 'pause'))->toThrow(ServeurEnvoiRefuse::class)
        ->and(fn () => $c->commander($this->ws, 'srv1', envoiCmd(), '../x'))->toThrow(ServeurEnvoiRefuse::class);
    Http::assertNothingSent();
});

test('transaction ouverte : appel refusé (sans la dérogation de test)', function () {
    config(['envoi.serveur.tolere_transaction_englobante' => false]);
    Http::fake();
    expect(DB::transactionLevel())->toBeGreaterThan(0)
        ->and(fn () => app(CommandeurServeur::class)->commander($this->ws, 'srv1', envoiCmd(), 'pause'))->toThrow(ServeurEnvoiRefuse::class);
    Http::assertNothingSent();
});

test('interrupteur ENVOI_REEL_ACTIF éteint : aucun appel', function () {
    config(['envoi.reel_actif' => false]);
    Http::fake();
    expect(fn () => app(TireurEvenements::class)->tirer($this->ws, 'srv1', null, 10, new DateTimeImmutable))->toThrow(ServeurEnvoiRefuse::class)
        ->and(fn () => app(CommandeurServeur::class)->commander($this->ws, 'srv1', envoiCmd(), 'pause'))->toThrow(ServeurEnvoiRefuse::class);
    Http::assertNothingSent();
});

test('SSRF : l’adresse vient de la configuration, jamais de envoi_serveurs.url_base', function () {
    Http::fake(['*' => envoiReponseSignee(['evenements' => []])]);
    app(TireurEvenements::class)->tirer($this->ws, 'srv1', null, 10, new DateTimeImmutable);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://envoi.exemple.test/v1/evenements?') && ! str_contains($r->url(), 'zz.invalid'));
});

test('SSRF : configuration http, hôte privé ou absente = refus, aucun appel', function (string $url, array $ips) {
    config(['envoi.serveur.url' => $url]);
    app()->instance(ResolveurHote::class, new class($ips) implements ResolveurHote
    {
        public function __construct(private array $ips) {}

        public function resoudre(string $hote): array
        {
            return $this->ips;
        }
    });
    Http::fake();
    expect(fn () => app(TireurEvenements::class)->tirer($this->ws, 'srv1', null, 10, new DateTimeImmutable))->toThrow(ServeurEnvoiRefuse::class);
    Http::assertNothingSent();
})->with([
    'http' => ['http://envoi.exemple.test', ['93.184.216.34']],
    'privé' => ['https://envoi.exemple.test', ['10.1.2.3']],
    'absente' => ['', ['93.184.216.34']],
]);

test('tirer : lit un lot signé (GET /v1/evenements), plafonne limite à 500, sans ouverture', function () {
    Http::fake(['*' => envoiReponseSignee(['evenements' => [['event_id' => 'e1', 'seq' => 1, 'type' => 'livre', 'id_injection' => 'm1']], 'curseur_suivant' => '1'])]);
    $lot = app(TireurEvenements::class)->tirer($this->ws, 'srv1', '0', 100000, new DateTimeImmutable('-1 hour'));
    expect($lot->evenements)->toHaveCount(1)->and($lot->curseurSuivant)->toBe('1');
    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $r->method() === 'GET' && $q['limite'] === '500'
            && (new VerificateurHmacEnvoi)->verifier('secret-de-test', 'srv1', $r->header('X-Envoi-Timestamp')[0], $r->header('X-Envoi-Nonce')[0], $r->header('X-Envoi-Signature')[0], (string) parse_url($r->url(), PHP_URL_QUERY), 'GET', '/v1/evenements') === 200;
    });
});

test('réponse : signature fausse, autre espace, non signée = rejetées', function (Closure $reponse) {
    Http::fake(['*' => $reponse]);
    expect(fn () => app(TireurEvenements::class)->tirer($this->ws, 'srv1', null, 10, new DateTimeImmutable))->toThrow(ServeurEnvoiRefuse::class);
})->with([
    'secret différent' => [fn () => envoiReponseSignee(['evenements' => []], null, 'autre-secret')],
    'autre espace' => [fn () => envoiReponseSignee(['evenements' => []], (string) Str::uuid())],
    'non signée' => [fn () => Http::response(['workspace_id' => 'x', 'evenements' => []], 200)],
]);

test('réponse rejouée (signée pour un autre nonce) : rejetée', function () {
    $ancienne = null;
    Http::fake(['*' => function (Request $r) use (&$ancienne) {
        $corps = json_encode(['workspace_id' => $this->ws, 'evenements' => []]);
        $sig = SignatureEnvoi::signer('secret-de-test', SignatureEnvoi::chargeReponse((string) Str::uuid(), $this->ws, 200, $corps));

        return Http::response($corps, 200, ['X-Envoi-Signature' => $sig]);
    }]);
    expect(fn () => app(TireurEvenements::class)->tirer($this->ws, 'srv1', null, 10, new DateTimeImmutable))->toThrow(ServeurEnvoiRefuse::class);
});

test('réponse : clés manquantes, > 500 événements, trop grosse, curseur qui recule = erreur propre', function (array $donnees) {
    Http::fake(['*' => envoiReponseSignee($donnees)]);
    expect(fn () => app(TireurEvenements::class)->tirer($this->ws, 'srv1', '50', 1000, new DateTimeImmutable))->toThrow(ServeurEnvoiRefuse::class);
})->with([
    'sans evenements' => [[]],
    'événement incomplet' => [['evenements' => [['event_id' => 'e1']]]],
    'type invalide' => [['evenements' => [['event_id' => 'e1', 'seq' => 1, 'type' => 'Ouvert!', 'id_injection' => 'm']]]],
    '501 événements' => [['evenements' => array_fill(0, 501, ['event_id' => 'e', 'seq' => 1, 'type' => 'livre', 'id_injection' => 'm'])]],
    'curseur en arrière' => [['evenements' => [], 'curseur_suivant' => '10']],
]);

test('réponse trop volumineuse : refus', function () {
    Http::fake(['*' => envoiReponseSignee(['evenements' => [], 'bourrage' => str_repeat('a', 1_100_000)])]);
    expect(fn () => app(TireurEvenements::class)->tirer($this->ws, 'srv1', null, 10, new DateTimeImmutable))->toThrow(ServeurEnvoiRefuse::class);
});

test('le message d’exception ne contient aucun extrait de la réponse', function () {
    Http::fake(['*' => Http::response('SECRET-DANS-LA-REPONSE', 500)]);
    try {
        app(TireurEvenements::class)->tirer($this->ws, 'srv1', null, 10, new DateTimeImmutable);
        $this->fail('attendu');
    } catch (ServeurEnvoiRefuse $e) {
        expect($e->getMessage())->not->toContain('SECRET-DANS-LA-REPONSE');
    }
});

test('secret par serveur et identifiant de clé configurables', function () {
    config(['envoi.serveur.secret' => '', 'envoi.serveur.secrets' => ['srv1' => 'secret-srv1'], 'envoi.serveur.cles' => ['srv1' => '9']]);
    Http::fake(['*' => envoiReponseSignee(['evenements' => []], null, 'secret-srv1')]);
    app(TireurEvenements::class)->tirer($this->ws, 'srv1', null, 10, new DateTimeImmutable);
    Http::assertSent(fn (Request $r) => str_starts_with($r->header('X-Envoi-Signature')[0], 'k=9,'));
});

test('secret vide : aucun appel réseau, refus', function () {
    config(['envoi.serveur.secret' => '']);
    Http::fake();
    expect(fn () => app(CommandeurServeur::class)->commander($this->ws, 'srv1', envoiCmd(), 'pause'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('serveur inconnu : refus', function () {
    Http::fake();
    expect(fn () => app(TireurEvenements::class)->tirer($this->ws, 'autre', null, 10, new DateTimeImmutable))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('le client n’est pas un singleton', function () {
    expect(app(ClientServeurEnvoiHmac::class))->not->toBe(app(ClientServeurEnvoiHmac::class));
});
