<?php

use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Envoi\Serveur\JournalCommandes;
use App\Envoi\Serveur\VerificateurHmacEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.ingest.replay_store' => 'array', 'envoi.serveur.secret' => 'secret-de-test']);
    $this->ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $this->ws, 'name' => 'T', 'slug' => 'k1-' . substr($this->ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    WorkspaceContext::run($this->ws, fn () => DB::table('envoi_serveurs')->insert(['workspace_id' => $this->ws, 'source' => 'srv1', 'url_base' => 'https://envoi.invalid']));
});

test('journal des commande_id : unique, le rejeu est un no-op', function () {
    $j = app(JournalCommandes::class);
    expect($j->enregistrer($this->ws, 'cmd-1', 'pause'))->toBeTrue()
        ->and($j->enregistrer($this->ws, 'cmd-1', 'pause'))->toBeFalse();
    expect(WorkspaceContext::run($this->ws, fn () => DB::table('envoi_commandes')->count()))->toBe(1);
});

test('commander : requête signée acceptée par le vérificateur ; même commande_id = aucun second appel', function () {
    Http::fake(['*' => Http::response([], 200)]);
    $client = app(CommandeurServeur::class);
    expect($client)->toBeInstanceOf(ClientServeurEnvoiHmac::class)
        ->and($client->commander($this->ws, 'srv1', 'cmd-7', 'pause'))->toBeTrue()
        ->and($client->commander($this->ws, 'srv1', 'cmd-7', 'pause'))->toBeFalse();
    Http::assertSentCount(1);
    Http::assertSent(function (Request $r) {
        return (new VerificateurHmacEnvoi)->verifier('secret-de-test', 'srv1', $r->header('X-Envoi-Timestamp')[0], $r->header('X-Envoi-Nonce')[0], $r->header('X-Envoi-Signature')[0], $r->body()) === 200;
    });
});

test('tirer : lit un lot signé (GET), sans ouverture', function () {
    Http::fake(['*' => Http::response(['evenements' => [['event_id' => 'e1', 'seq' => 1, 'type' => 'livre', 'id_injection' => 'm1']], 'curseur_suivant' => '1'], 200)]);
    $lot = app(TireurEvenements::class)->tirer($this->ws, 'srv1', '0', 100, new DateTimeImmutable('-1 hour'));
    expect($lot->evenements)->toHaveCount(1)->and($lot->curseurSuivant)->toBe('1');
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), 'https://envoi.invalid/evenements?'));
});

test('secret vide : aucun appel réseau, refus', function () {
    config(['envoi.serveur.secret' => '']);
    Http::fake();
    expect(fn () => app(CommandeurServeur::class)->commander($this->ws, 'srv1', 'cmd-9', 'pause'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

test('serveur inconnu : refus', function () {
    Http::fake();
    expect(fn () => app(TireurEvenements::class)->tirer($this->ws, 'autre', null, 10, new DateTimeImmutable))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});
