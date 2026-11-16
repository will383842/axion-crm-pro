<?php

use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\EffetsOpposition;
use App\Envoi\Desinscription\EnvoiRetraitsDemandes;
use App\Envoi\Desinscription\OppositionEnregistree;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\Desinscription\RetraitEnvoisEnFileServeur;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\JournalCommandes;
use App\Envoi\Serveur\RejoueurCommandes;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Serveur\SignatureEnvoi;
use App\Envoi\Smtp\ResolveurHote;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot C3-3 — retrait réel lié (boîte de retraits + tâche planifiée + commande v3), sur le FAUX serveur d'envoi.
 * Aucun vrai serveur : Http::fake répond comme la passerelle (réponse signée). Données fictives (example.test), jamais d'adresse
 * en clair : seulement des empreintes. Chaque test de base est rejoué SOUS LE RÔLE APPLICATIF ET SOUS LE PROPRIÉTAIRE.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-12-10 12:00:00', 'UTC'));
    retrStatut(200);
    $this->pki = [];
    foreach (['ca', 'cert', 'cle'] as $n) {
        $f = (string) tempnam(sys_get_temp_dir(), 'envoi-c33-' . $n);
        file_put_contents($f, "-----BEGIN FAKE-----\n-----END FAKE-----\n");
        $this->pki[$n] = $f;
    }
    config([
        'crm.ingest.replay_store' => 'array',
        'envoi.serveur.secrets' => ['srv1' => 'secret-de-test'],
        'envoi.serveur.urls' => ['srv1' => 'https://envoi.example.test'],
        'envoi.serveur.ca_path' => $this->pki['ca'],
        'envoi.serveur.client_cert_path' => $this->pki['cert'],
        'envoi.serveur.client_key_path' => $this->pki['cle'],
        'envoi.serveur.tolere_transaction_englobante' => true, // RefreshDatabase ouvre une transaction englobante
    ]);
    app()->instance(ResolveurHote::class, new class implements ResolveurHote
    {
        public function resoudre(string $hote): array
        {
            return ['93.184.216.34'];
        }
    });
    $this->ws = retrEspace();
    $this->wsB = retrEspace();
    retrSource($this->ws);
    retrSource($this->wsB);
    $this->empreinte = str_repeat('a', 64);
});

afterEach(function () {
    Carbon::setTestNow();
    foreach ($this->pki as $f) {
        @unlink($f);
    }
});

function retrEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'RT', 'slug' => 'rt-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function retrSource(string $ws): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $ws, 'source' => 'srv1', 'url_base' => 'https://zz.invalid']));
}

function retrMode(string $mode): void
{
    if ($mode === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
}

function retrInterrupteurs(bool $actifs): void
{
    config(['envoi.reel_actif' => $actifs, 'envoi.k1_actif' => $actifs]);
}

/**
 * Une inscription pour l'empreinte donnée, avec une injection réservée par étape demandée ; rend les id_injection.
 *
 * @param  list<array{adaptateur?: string, rang?: int, cree_le?: string}>  $injections
 * @return list<string>
 */
function retrInscription(string $ws, string $empreinte, array $injections, int $contact = 1): array
{
    $ids = [];
    WorkspaceContext::run($ws, function () use ($ws, $empreinte, $injections, $contact, &$ids) {
        $sid = (string) Str::uuid();
        DB::table('envoi_sequences')->insert(['id' => $sid, 'workspace_id' => $ws, 'nom' => 'Séquence fictive']);
        $ins = (string) Str::uuid();
        DB::table('envoi_inscriptions')->insert([
            'id' => $ins, 'workspace_id' => $ws, 'sequence_id' => $sid, 'contact_id' => $contact, 'entreprise_id' => 9000 + $contact,
            'empreinte_adresse' => $empreinte, 'prochaine_echeance' => now()->addDay()->format('c'), 'version_figee' => '{}',
        ]);
        foreach ($injections as $i => $inj) {
            $id = (string) Str::uuid();
            DB::table('envoi_injections_locales')->insert([
                'workspace_id' => $ws, 'id_injection' => $id, 'cle_idempotence' => 'seq-' . $ins . '-' . ($inj['rang'] ?? $i + 1),
                'adaptateur' => $inj['adaptateur'] ?? 'kumo', 'cree_le' => $inj['cree_le'] ?? now()->format('c'),
            ]);
            $ids[] = $id;
        }
    });

    return $ids;
}

function retrDemandes(string $ws): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_retraits_demandes')->where('workspace_id', $ws)->orderBy('cree_le')->get()->all());
}

/** Compte brut hors RLS (rôle propriétaire le temps du comptage), puis rôle de départ rétabli. */
function retrCompte(string $table): int
{
    $role = DB::selectOne('SELECT current_user AS u')->u;
    DB::statement('RESET ROLE');
    $n = (int) DB::selectOne("SELECT count(*) AS n FROM {$table}")->n;
    if ($role === config('database.connections.pgsql_app.username')) {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', $role) . '"');
    }

    return $n;
}

/** Statut que renvoie le faux serveur (200 par défaut ; 503 = panne). */
function retrStatut(?int $nouveau = null): int
{
    static $statut = 200;
    if ($nouveau !== null) {
        $statut = $nouveau;
    }

    return $statut;
}

/** Réponse signée comme le ferait la passerelle (liée au nonce). */
function retrReponse(string $secret = 'secret-de-test'): Closure
{
    return function (Request $r) use ($secret) {
        $statut = retrStatut();
        $espace = $r->header('X-Envoi-Workspace')[0];
        $corps = json_encode(['ok' => true, 'workspace_id' => $espace], JSON_THROW_ON_ERROR);
        $sig = SignatureEnvoi::signer($secret, SignatureEnvoi::chargeReponse($r->header('X-Envoi-Nonce')[0], $espace, $statut, $corps));

        return Http::response($corps, $statut, ['X-Envoi-Signature' => SignatureEnvoi::entete('1', $sig)]);
    };
}

/** @return list<Request> les POST /v1/commandes/retrait envoyés */
function retrPosts(): array
{
    $v = [];
    foreach (Http::recorded() as [$requete]) {
        if ($requete->method() === 'POST' && str_ends_with($requete->url(), '/v1/commandes/retrait')) {
            $v[] = $requete;
        }
    }

    return $v;
}

dataset('modes_retr', ['app', 'proprio']);

test('C3-3 (1) retirerPour : une demande en_attente, le nombre de messages à retirer, AUCUNE requête réseau dans la transaction', function (string $mode) {
    Http::fake();
    retrInterrupteurs(true);
    $ids = retrInscription($this->ws, $this->empreinte, [['rang' => 1], ['rang' => 2]]);
    retrMode($mode);
    $n = DB::transaction(fn () => app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte));
    expect($n)->toBe(2);
    $d = retrDemandes($this->ws);
    expect($d)->toHaveCount(1)->and($d[0]->statut)->toBe('en_attente')->and(trim($d[0]->empreinte_adresse))->toBe($this->empreinte)
        ->and($d[0]->commande_id)->toBeNull()->and((int) $d[0]->tentatives)->toBe(0);
    Http::assertNothingSent();
    expect($ids)->toHaveCount(2);
})->with('modes_retr');

test('C3-3 (1 bis) via l\'écouteur de l\'opposition : la demande naît, aucun appel réseau', function () {
    Http::fake();
    retrInterrupteurs(true);
    retrInscription($this->ws, $this->empreinte, [['rang' => 1], ['rang' => 2]]);
    $arret = new class implements ArretParEntreprise
    {
        public function declencher(string $workspaceId, string $empreinteAdresse): void {}
    };
    DB::transaction(fn () => (new EffetsOpposition(app(RetraitEnvoisEnFile::class), $arret))->handle(new OppositionEnregistree($this->ws, $this->empreinte, 'adresse', 'lien')));
    expect(retrDemandes($this->ws))->toHaveCount(1);
    Http::assertNothingSent();
});

test('C3-3 (1 ter) une demande en attente pour la même empreinte n\'est pas dupliquée', function () {
    retrInscription($this->ws, $this->empreinte, [['rang' => 1]]);
    $r = app(RetraitEnvoisEnFile::class);
    $r->retirerPour($this->ws, $this->empreinte);
    $r->retirerPour($this->ws, $this->empreinte);
    expect(retrDemandes($this->ws))->toHaveCount(1);
});

test('C3-3 (2) commande planifiée, interrupteurs vrais : UN POST /v1/commandes/retrait avec exactement ces cibles ; demande envoyée ; rejeu = même corps', function (string $mode) {
    $ids = retrInscription($this->ws, $this->empreinte, [['rang' => 1], ['rang' => 2]]);
    retrInscription($this->ws, str_repeat('c', 64), [['rang' => 1]], 2); // une autre personne : jamais ciblée
    app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte);
    retrInterrupteurs(true);
    Http::fake(retrReponse());
    retrMode($mode);

    $bilan = app(EnvoiRetraitsDemandes::class)->envoyer();
    expect($bilan['envoyees'])->toBe(1);
    $posts = retrPosts();
    expect($posts)->toHaveCount(1);
    $corps = json_decode($posts[0]->body(), true);
    expect(array_keys($corps))->toBe(['commande_id', 'commande', 'cibles'])->and($corps['commande'])->toBe('retrait')
        ->and($corps['cibles'])->toEqualCanonicalizing($ids)->and($corps['cibles'])->toHaveCount(2);
    $d = retrDemandes($this->ws);
    expect($d[0]->statut)->toBe('envoye')->and($d[0]->commande_id)->toBe($corps['commande_id'])->and((int) $d[0]->cibles_nb)->toBe(2)->and($d[0]->traite_le)->not->toBeNull();

    // Le journal garde les cibles ET l'empreinte du corps exact.
    $journal = app(JournalCommandes::class);
    expect($journal->cibles($this->ws, $corps['commande_id']))->toBe($corps['cibles']);
    $details = json_decode((string) WorkspaceContext::run($this->ws, fn () => DB::table('envoi_journal_commandes')->where('commande_id', $corps['commande_id'])->value('details')), true);
    expect($details['corps_sha256'])->toBe(hash('sha256', $posts[0]->body()))->and($details['cibles'])->toBe($corps['cibles']);
})->with('modes_retr');

test('C3-3 (2 bis) rejeu d\'une commande non acquittée : le MÊME corps (mêmes cibles, même ordre)', function () {
    $ids = retrInscription($this->ws, $this->empreinte, [['rang' => 1], ['rang' => 2]]);
    retrInterrupteurs(true);
    retrStatut(503);
    Http::fake(retrReponse()); // 1er envoi : échec, l'intention reste non acquittée
    app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte);
    $bilan = app(EnvoiRetraitsDemandes::class)->envoyer();
    expect($bilan['envoyees'])->toBe(0)->and($bilan['echecs'])->toBe(1)->and(retrDemandes($this->ws)[0]->statut)->toBe('en_attente');
    expect(retrPosts())->toHaveCount(1);

    // Une injection de plus est réservée entre-temps : le rejeu ne doit PAS la glisser dans la même commande.
    retrInscription($this->ws, $this->empreinte, [['rang' => 1]], 3);
    retrStatut(200);
    $rejeu = app(RejoueurCommandes::class)->rejouer();
    expect($rejeu['rejouees'])->toBe(1);
    $posts = retrPosts();
    expect($posts)->toHaveCount(2)->and($posts[1]->body())->toBe($posts[0]->body());
    expect(json_decode($posts[1]->body(), true)['cibles'])->toEqualCanonicalizing($ids);
});

test('C3-3 (2 ter) le client refuse localement un retrait sans cibles, des cibles à une pause, et plus de 100 cibles', function () {
    retrInterrupteurs(true);
    Http::fake(retrReponse());
    $c = app(ClientServeurEnvoiHmac::class);
    $id = (string) Str::uuid();
    expect(fn () => $c->commander($this->ws, 'srv1', $id, 'retrait'))->toThrow(ServeurEnvoiRefuse::class)
        ->and(fn () => $c->commander($this->ws, 'srv1', $id, 'retrait', []))->toThrow(ServeurEnvoiRefuse::class)
        ->and(fn () => $c->commander($this->ws, 'srv1', $id, 'pause', [(string) Str::uuid()]))->toThrow(ServeurEnvoiRefuse::class)
        ->and(fn () => $c->commander($this->ws, 'srv1', $id, 'retrait', ['pas-un-uuid']))->toThrow(ServeurEnvoiRefuse::class)
        ->and(fn () => $c->commander($this->ws, 'srv1', $id, 'retrait', array_map(fn () => (string) Str::uuid(), range(1, 101))))->toThrow(ServeurEnvoiRefuse::class);
    $meme = (string) Str::uuid();
    expect(fn () => $c->commander($this->ws, 'srv1', $id, 'retrait', [$meme, $meme]))->toThrow(ServeurEnvoiRefuse::class);
    Http::assertNothingSent();
    expect(WorkspaceContext::run($this->ws, fn () => DB::table('envoi_journal_commandes')->whereNotNull('commande_id')->count()))->toBe(0);
});

test('C3-3 (2 quater) même commande_id avec d\'autres cibles : refus local, alerte, rien n\'est renvoyé', function () {
    retrInterrupteurs(true);
    Http::fake(retrReponse());
    Log::spy();
    $c = app(ClientServeurEnvoiHmac::class);
    $id = (string) Str::uuid();
    expect($c->commander($this->ws, 'srv1', $id, 'retrait', [(string) Str::uuid()]))->toBeTrue();
    expect(fn () => $c->commander($this->ws, 'srv1', $id, 'retrait', [(string) Str::uuid()]))->toThrow(ServeurEnvoiRefuse::class);
    expect(retrPosts())->toHaveCount(1);
    Log::shouldHaveReceived('critical')->with('envoi.commande_id_reutilise_avec_autre_corps', Mockery::any())->once();
});

test('C3-3 (3) interrupteurs faux : zéro requête, la demande reste en_attente, tentatives = 1', function (string $mode) {
    Http::fake();
    retrInscription($this->ws, $this->empreinte, [['rang' => 1], ['rang' => 2]]);
    app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte);
    retrInterrupteurs(false);
    retrMode($mode);
    $bilan = app(EnvoiRetraitsDemandes::class)->envoyer();
    Http::assertNothingSent();
    $d = retrDemandes($this->ws);
    expect($bilan['envoyees'])->toBe(0)->and($d[0]->statut)->toBe('en_attente')->and((int) $d[0]->tentatives)->toBe(1);
    // Un second passage : toujours rien, une tentative de plus.
    app(EnvoiRetraitsDemandes::class)->envoyer();
    expect((int) retrDemandes($this->ws)[0]->tentatives)->toBe(2);
    Http::assertNothingSent();
})->with('modes_retr');

test('C3-3 (3 bis) interrupteurs faux puis vrais : la demande part avec son commande_id (écrit avant l\'appel) ', function () {
    retrInscription($this->ws, $this->empreinte, [['rang' => 1]]);
    app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte);
    retrInterrupteurs(false);
    app(EnvoiRetraitsDemandes::class)->envoyer();
    $id = retrDemandes($this->ws)[0]->commande_id;
    expect($id)->not->toBeNull();
    retrInterrupteurs(true);
    Http::fake(retrReponse());
    app(EnvoiRetraitsDemandes::class)->envoyer();
    $posts = retrPosts();
    expect($posts)->toHaveCount(1)->and(json_decode($posts[0]->body(), true)['commande_id'])->toBe($id);
    expect(retrDemandes($this->ws)[0]->statut)->toBe('envoye');
});

test('C3-3 (4) adresse sans injection kumo (faux serveur seulement) : sans_objet, zéro requête', function (string $mode) {
    Http::fake();
    retrInterrupteurs(true);
    retrInscription($this->ws, $this->empreinte, [['adaptateur' => 'faux', 'rang' => 1], ['adaptateur' => 'faux', 'rang' => 2]]);
    retrMode($mode);
    expect(app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte))->toBe(0);
    $bilan = app(EnvoiRetraitsDemandes::class)->envoyer();
    Http::assertNothingSent();
    $d = retrDemandes($this->ws);
    expect($bilan['sans_objet'])->toBe(1)->and($d[0]->statut)->toBe('sans_objet')->and((int) $d[0]->cibles_nb)->toBe(0)->and($d[0]->traite_le)->not->toBeNull();
})->with('modes_retr');

test('C3-3 (4 bis) une injection de plus de 30 jours n\'est pas ciblée', function () {
    retrInscription($this->ws, $this->empreinte, [['rang' => 1, 'cree_le' => '2026-10-01T00:00:00+00:00']]);
    expect(app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte))->toBe(0);
});

test('C3-3 (5) cloisonnement : les injections de la même empreinte dans l\'espace B ne sont jamais envoyées pour A', function (string $mode) {
    $idsA = retrInscription($this->ws, $this->empreinte, [['rang' => 1]]);
    $idsB = retrInscription($this->wsB, $this->empreinte, [['rang' => 1], ['rang' => 2], ['rang' => 3]]);
    retrMode($mode);
    expect(app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte))->toBe(1);
    expect(retrDemandes($this->wsB))->toBe([]);
    retrInterrupteurs(true);
    Http::fake(retrReponse());
    app(EnvoiRetraitsDemandes::class)->envoyer();
    $posts = retrPosts();
    expect($posts)->toHaveCount(1)->and(json_decode($posts[0]->body(), true)['cibles'])->toBe($idsA)
        ->and($posts[0]->header('X-Envoi-Workspace')[0])->toBe($this->ws);
    expect(array_intersect(json_decode($posts[0]->body(), true)['cibles'], $idsB))->toBe([]);
})->with('modes_retr');

test('C3-3 (6) la liaison de TransportServiceProvider gagne sur celle de EnvoiServiceProvider ; la porte reste fermée', function () {
    expect(app(RetraitEnvoisEnFile::class))->toBeInstanceOf(RetraitEnvoisEnFileServeur::class)
        ->and(app(RetraitEnvoisEnFile::class))->not->toBeInstanceOf(RetraitEnvoisEnFileAbsent::class)
        ->and(config('envoi.reel_actif'))->toBeFalse()
        ->and(app(PorteEnvoiReelle::class)->ouverte())->toBeFalse();
});

test('C3-3 (7) aucune ligne de personne supprimée ni modifiée (inscriptions, oppositions)', function (string $mode) {
    retrInscription($this->ws, $this->empreinte, [['rang' => 1], ['rang' => 2]]);
    retrMode($mode);
    $avant = [retrCompte('envoi_inscriptions'), retrCompte('envoi_oppositions'), md5(json_encode(DB::table('envoi_inscriptions')->orderBy('id')->get()))];
    app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte);
    retrInterrupteurs(true);
    Http::fake(retrReponse());
    app(EnvoiRetraitsDemandes::class)->envoyer();
    expect([retrCompte('envoi_inscriptions'), retrCompte('envoi_oppositions'), md5(json_encode(DB::table('envoi_inscriptions')->orderBy('id')->get()))])->toBe($avant);
    expect(retrCompte('envoi_injections_locales'))->toBe(2);
})->with('modes_retr');

test('C3-3 (8) alerte critique « retrait en retard » : demande > 5 minutes ET envoi réel actif, sans empreinte ni adresse', function () {
    retrInscription($this->ws, $this->empreinte, [['rang' => 1]]);
    app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte);
    Carbon::setTestNow(Carbon::now()->addMinutes(6));
    Log::spy();
    retrInterrupteurs(false);
    app(EnvoiRetraitsDemandes::class)->envoyer();
    Log::shouldNotHaveReceived('critical'); // éteint : pas d'alerte (rien ne peut partir)
    retrInterrupteurs(true);
    retrStatut(503);
    Http::fake(retrReponse());
    $bilan = app(EnvoiRetraitsDemandes::class)->envoyer();
    expect($bilan['en_retard'])->toBe(1);
    Log::shouldHaveReceived('critical')->withArgs(fn (string $m, array $c) => str_starts_with($m, 'envoi.retrait_en_retard') && ! str_contains(json_encode($c), $this->empreinte))->once();
});

test('C3-3 (9) la commande envoi:retraits:envoyer existe et envoie les demandes', function () {
    retrInscription($this->ws, $this->empreinte, [['rang' => 1]]);
    app(RetraitEnvoisEnFile::class)->retirerPour($this->ws, $this->empreinte);
    retrInterrupteurs(true);
    Http::fake(retrReponse());
    $this->artisan('envoi:retraits:envoyer')->assertSuccessful();
    expect(retrDemandes($this->ws)[0]->statut)->toBe('envoye');
});

test('C3-3 (10) la tâche planifiée : chaque minute, sans chevauchement, gardée par la présence de la table', function () {
    Artisan::call('list', ['--format' => 'txt']);
    $evts = array_values(array_filter(app(Schedule::class)->events(), fn ($e) => str_contains((string) $e->command, 'envoi:retraits:envoyer')));
    expect($evts)->toHaveCount(1)->and($evts[0]->expression)->toBe('* * * * *')->and($evts[0]->withoutOverlapping)->toBeTrue();
});
