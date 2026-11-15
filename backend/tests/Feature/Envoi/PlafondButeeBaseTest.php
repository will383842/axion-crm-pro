<?php

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ServeurEspion;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * P1-PLAFOND (D7) — la butée en base `envoi_compteur_espace` est appelée par la passerelle, en dernier contrôle avant la
 * réservation. Base réelle ; simulateur seulement ; aucun envoi.
 */
beforeEach(function () {
    config(['envoi.plafond_jour' => 300]); // plafond effectif = min(réglage R14, ENVOI_PLAFOND_JOUR, 300)
    $this->ws = F::espace('zz-butee');
    $this->depot = new DepotSocleDb;
    $this->jour = new DateTimeImmutable('2026-11-17 12:00:00', new DateTimeZone('Europe/Paris'));
    WorkspaceContext::set($this->ws);
    $this->depot->eteindreTemoins($this->ws, 'test-butee');
    $this->msg = fn (string $cle, ?string $ws = null): MessageAEnvoyer => new MessageAEnvoyer($ws ?? $this->ws, 'dest@example.test', 'from@example.test', 'S', 'C', $cle);
    $this->passerelle = fn ($serveur = null, ?DepotSocle $depot = null): PasserelleLocale => new PasserelleLocale(
        $serveur ?? new FauxServeurEnvoi,
        $depot ?? $this->depot,
        true,
        300,
        fn () => $this->jour,
        $this->ws,
        300,
        fn () => WorkspaceContext::current(),
    );
    $this->place = fn (?string $ws = null): int => (int) (DB::table('envoi_compteur_espace')->where('workspace_id', $ws ?? $this->ws)->where('jour', '2026-11-17')->value('n') ?? 0);
    $this->poser = fn (int $n, ?string $ws = null) => DB::table('envoi_compteur_espace')->insert(['workspace_id' => $ws ?? $this->ws, 'jour' => '2026-11-17', 'n' => $n]);
    $this->lignes = fn (): int => DB::table('envoi_journal_commandes')->where('workspace_id', $this->ws)->where('commande', 'injection')->count();
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
        // transaction avortée : le rollback défait le reste
    }
});

test('contrôle PHP contourné (dépôt qui répond « 0 injecté ») : la base refuse la 301e réservation', function () {
    ($this->poser)(300);
    $espion = Mockery::mock(DepotSocle::class);
    $espion->shouldReceive('injectesLe')->andReturn(0);
    $espion->shouldReceive('injectesLeGlobal')->andReturn(0);
    foreach (get_class_methods(DepotSocle::class) as $methode) {
        $espion->shouldReceive($methode)->andReturnUsing(fn (...$a) => $this->depot->$methode(...$a));
    }
    $r = ($this->passerelle)(null, $espion)->injecter(($this->msg)('k301'));
    expect($r->accepte)->toBeFalse()->and($r->reporteAu?->format('Y-m-d'))->toBe('2026-11-18')
        ->and(($this->lignes)())->toBe(0)->and(($this->place)())->toBe(300);
});

test('réglage R14 de l\'espace à 12 : le 13e message est reporté', function () {
    DB::table('envoi_reglages')->insert(['workspace_id' => $this->ws, 'cle' => 'regles.plafond_jour', 'valeur' => '12', 'auteur' => 'test']);
    $p = ($this->passerelle)();
    for ($i = 1; $i <= 12; $i++) {
        expect($p->injecter(($this->msg)("k{$i}"))->accepte)->toBeTrue();
    }
    expect($p->injecter(($this->msg)('k13'))->accepte)->toBeFalse()->and(($this->place)())->toBe(12);
});

test('refus de la butée : report +1 jour, ni journal ni compteur global, les péremptions et leurs alertes sont gardées', function () {
    ($this->poser)(300);
    $this->depot->reserverInjection($this->ws, $this->jour, 'vieille', $this->jour->modify('-3 hours'));
    $avant = (int) (DB::table('envoi_compteur_global')->where('jour', '2026-11-17')->value('n') ?? 0);
    Log::spy();
    $r = ($this->passerelle)()->injecter(($this->msg)('neuve'));
    expect($r->accepte)->toBeFalse()->and($r->reporteAu?->format('Y-m-d'))->toBe('2026-11-18')
        ->and($this->depot->etatInjection($this->ws, 'neuve'))->toBeNull()
        ->and((int) DB::table('envoi_compteur_global')->where('jour', '2026-11-17')->value('n'))->toBe($avant)
        ->and($this->depot->etatInjection($this->ws, 'vieille'))->toBe('a_verifier');
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains((string) $m, 'a_verifier'))->once();
});

test('une réservation libérée rend sa place d\'espace, une fois, jamais sous 0', function () {
    ($this->poser)(10);
    $serveur = new ServeurEspion(fn () => throw EnvoiRefuse::envoiReelInactif());
    expect(fn () => ($this->passerelle)($serveur)->injecter(($this->msg)('liberee')))->toThrow(EnvoiRefuse::class);
    expect(($this->place)())->toBe(10); // +1 à la réservation, -1 à la libération
    DB::table('envoi_compteur_espace')->where('workspace_id', $this->ws)->update(['n' => 0]);
    $this->depot->concluireInjection($this->ws, $this->jour, 'liberee', 'liberee');
    expect(($this->place)())->toBe(0);
});

test('garde : deux espaces ne se partagent pas leur compteur', function () {
    $b = F::espace('zz-butee-b');
    $this->depot->eteindreTemoins($b, 'test-butee');
    ($this->poser)(300);
    $r = WorkspaceContext::run($b, fn () => ($this->passerelle)()->injecter(($this->msg)('kb', $b)));
    expect($r->accepte)->toBeTrue()->and(($this->place)($b))->toBe(1)->and(($this->place)())->toBe(300);
});

test('garde : réservation annulée par la transaction ne consomme pas de place ; plafond 0 : aucune réservation', function () {
    try {
        DB::transaction(function () {
            expect($this->depot->reserverPlaceEspace($this->ws, $this->jour, 5))->toBeTrue();
            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }
    expect(($this->place)())->toBe(0);
    DB::table('envoi_reglages')->insert(['workspace_id' => $this->ws, 'cle' => 'regles.plafond_jour', 'valeur' => '0', 'auteur' => 'test']);
    expect(($this->passerelle)()->injecter(($this->msg)('k0'))->accepte)->toBeFalse()
        ->and(DB::table('envoi_compteur_espace')->where('workspace_id', $this->ws)->count())->toBe(0);
});
