<?php

use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\PasserelleLocale;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ServeurEspion;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * P1-LN-A (D8) — le registre de refus R13 est relu SOUS LE VERROU, juste avant l'injection : une opposition ajoutée
 * entre le temps A du séquenceur et l'injection gagne. Lecture seule : rien n'est écrit vers le site. La portée SIREN
 * n'est pas relue ici (limite assumée, appliquée au temps A par `EnvoiAutorise`).
 */
beforeEach(function () {
    $this->ws = F::espace('zz-lna');
    $this->autre = F::espace('zz-lnb');
    $this->depot = new DepotSocleDb;
    $this->jour = new DateTimeImmutable('2026-11-17 12:00:00', new DateTimeZone('Europe/Paris'));
    WorkspaceContext::set($this->ws);
    $this->depot->eteindreTemoins($this->ws, 'test-lna');
    $this->registre = new RegistreOppositionManuel;
    $this->msg = fn (string $dest): MessageAEnvoyer => new MessageAEnvoyer($this->ws, $dest, 'from@example.test', 'S', 'C', 'k-' . md5($dest));
    $this->passerelle = fn ($serveur): PasserelleLocale => new PasserelleLocale(
        $serveur,
        $this->depot,
        true,
        5,
        fn () => $this->jour,
        $this->ws,
        300,
        fn () => WorkspaceContext::current(),
    );
    $this->inscrire = function (string $espace, string $portee, string $valeur): void {
        WorkspaceContext::run($espace, fn () => $this->registre->inscrire($espace, $portee, $valeur, 'test P1-LN-A', 'test'));
    };
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('un domaine inscrit après le temps A et avant l\'injection : le message ne part pas (refus sous le verrou)', function () {
    $serveur = new ServeurEspion(fn () => ResultatInjection::accepte('id-1'));
    ($this->inscrire)($this->ws, 'domaine', 'societe.example.test');
    expect(fn () => ($this->passerelle)($serveur)->injecter(($this->msg)('jean@societe.example.test')))->toThrow(EnvoiRefuse::class, 'Opposition');
    expect($serveur->appels)->toBe(0)->and($this->depot->etatInjection($this->ws, 'k-' . md5('jean@societe.example.test')))->toBeNull();
});

test('un domaine du registre bloque ses sous-domaines', function () {
    ($this->inscrire)($this->ws, 'domaine', 'societe.example.test');
    expect($this->depot->adresseOpposee($this->ws, 'a@mail.rh.societe.example.test'))->toBeTrue()
        ->and($this->depot->adresseOpposee($this->ws, 'a@autre.example.test'))->toBeFalse();
});

test('garde : une adresse inscrite par RegistreOppositionManuel::inscrire après le temps A ne part pas', function () {
    $serveur = new ServeurEspion(fn () => ResultatInjection::accepte('id-1'));
    ($this->inscrire)($this->ws, 'adresse', 'paul@propre.example.test');
    expect(fn () => ($this->passerelle)($serveur)->injecter(($this->msg)('paul@propre.example.test')))->toThrow(EnvoiRefuse::class, 'Opposition');
    expect($serveur->appels)->toBe(0);
});

test('garde : une adresse propre part', function () {
    $serveur = new ServeurEspion(fn () => ResultatInjection::accepte('id-1'));
    expect(($this->passerelle)($serveur)->injecter(($this->msg)('clair@propre.example.test'))->accepte)->toBeTrue()
        ->and($serveur->appels)->toBe(1);
});

test('garde : un domaine du registre de l\'espace B ne bloque pas l\'espace A', function () {
    ($this->inscrire)($this->autre, 'domaine', 'societe.example.test');
    WorkspaceContext::set($this->ws);
    expect($this->depot->adresseOpposee($this->ws, 'jean@societe.example.test'))->toBeFalse()
        ->and($this->depot->adresseOpposee($this->autre, 'jean@societe.example.test'))->toBeTrue();
});

test('garde (D14) : une adresse inscrite dans un espace reste vue depuis tous les espaces', function () {
    ($this->inscrire)($this->autre, 'adresse', 'vue@propre.example.test');
    WorkspaceContext::set($this->ws);
    expect($this->depot->adresseOpposee($this->ws, 'vue@propre.example.test'))->toBeTrue();
});

test('lecture seule : la vérification n\'écrit ni dans le registre, ni dans opt_out, ni vers le site', function () {
    ($this->inscrire)($this->ws, 'domaine', 'societe.example.test');
    $avant = [DB::table('envoi_oppositions_registre')->count(), DB::table('opt_out')->count(), DB::table('crm_outbound_events')->count()];
    $this->depot->adresseOpposee($this->ws, 'x@societe.example.test');
    $this->depot->adresseOpposee($this->ws, 'x@propre.example.test');
    expect([DB::table('envoi_oppositions_registre')->count(), DB::table('opt_out')->count(), DB::table('crm_outbound_events')->count()])->toBe($avant);
});
