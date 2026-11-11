<?php

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Envoi\Smtp\EnvoiSmtpRefuse;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ServeurEspion;
use Tests\Support\ServeurEspionDedoublonneur;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * L8-F14a — « réserver, injecter, conclure » (dette K9 I2). Base réelle, rôle du test : la réservation compte dans le
 * plafond, l'appel au serveur se fait hors transaction, la conclusion se note une fois.
 */
beforeEach(function () {
    $this->ws = F::espace('zz-ric');
    $this->depot = new DepotSocleDb;
    $this->jour = new DateTimeImmutable('2026-11-17 12:00:00', new DateTimeZone('Europe/Paris'));
    WorkspaceContext::set($this->ws);
    // Le mode témoins est testé ailleurs : on l'éteint par la commande locale (déclencheur envoi_drapeaux_garde intact).
    $this->depot->eteindreTemoins($this->ws, 'test-ric');
    $this->msg = fn (string $cle = 'k1', ?string $ws = null): MessageAEnvoyer => new MessageAEnvoyer($ws ?? $this->ws, 'dest@example.test', 'from@example.test', 'S', 'C', $cle);
    $this->passerelle = fn ($serveur, int $plafond = 5, int $global = 300): PasserelleLocale => new PasserelleLocale(
        $serveur,
        $this->depot,
        true,
        $plafond,
        fn () => $this->jour,
        $this->ws,
        $global,
        fn () => WorkspaceContext::current(),
    );
    $this->compteur = fn (): int => (int) (DB::table('envoi_compteur_global')->where('jour', '2026-11-17')->value('n') ?? 0);
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
        // transaction avortée par un test d'erreur SQL : le rollback défait le reste
    }
});

test('la réservation COMPTE dans le plafond dès avant l\'appel au serveur (jour et global)', function () {
    $vu = [];
    $serveur = new ServeurEspion(function (MessageAEnvoyer $m, $s) use (&$vu) {
        $vu = [$this->depot->injectesLe($this->ws, $this->jour), ($this->compteur)(), $this->depot->etatInjection($this->ws, 'k1')];

        return ResultatInjection::accepte('id-1');
    });
    expect(($this->passerelle)($serveur)->injecter(($this->msg)())->accepte)->toBeTrue();
    expect($vu)->toBe([1, 1, 'reservee'])
        ->and($this->depot->etatInjection($this->ws, 'k1'))->toBe('acceptee')
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(1);
});

test('deux injections « concurrentes » ne dépassent jamais le plafond : la seconde, pendant l\'appel de la première, est reportée', function () {
    $second = null;
    $p = null;
    $serveur = new ServeurEspion(function (MessageAEnvoyer $m, $s) use (&$second, &$p) {
        if ($m->cleIdempotence === 'k1') {
            $second = $p->injecter(($this->msg)('k2')); // plafond 1 : la place de k1 est déjà réservée
        }

        return ResultatInjection::accepte('id-' . $m->cleIdempotence);
    });
    $p = ($this->passerelle)($serveur, plafond: 1);
    expect($p->injecter(($this->msg)('k1'))->accepte)->toBeTrue();
    expect($second->accepte)->toBeFalse()
        ->and($serveur->appels)->toBe(1)
        ->and($this->depot->etatInjection($this->ws, 'k1'))->toBe('acceptee') // état final de k1 : conclue, pas restée réservée
        ->and($this->depot->etatInjection($this->ws, 'k2'))->toBeNull()
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(1);
});

test('un refus net LIBÈRE la place (jour et global) ; un nouvel essai de la même clé repart et compte à nouveau', function () {
    $refuser = true;
    $serveur = new ServeurEspion(function (MessageAEnvoyer $m, $s) use (&$refuser) {
        if ($refuser) {
            throw EnvoiRefuse::toutArreter();
        }

        return ResultatInjection::accepte('id-1');
    });
    $p = ($this->passerelle)($serveur, plafond: 1);
    expect(fn () => $p->injecter(($this->msg)()))->toThrow(EnvoiRefuse::class);
    expect($this->depot->injectesLe($this->ws, $this->jour))->toBe(0)
        ->and(($this->compteur)())->toBe(0)
        ->and($this->depot->etatInjection($this->ws, 'k1'))->toBeNull();

    $refuser = false;
    expect($p->injecter(($this->msg)())->accepte)->toBeTrue();
    expect($this->depot->injectesLe($this->ws, $this->jour))->toBe(1)
        ->and(($this->compteur)())->toBe(1)
        ->and($this->depot->etatInjection($this->ws, 'k1'))->toBe('acceptee');
});

test('un report par le serveur (accepte = faux) libère aussi la place', function () {
    $serveur = new ServeurEspion(fn () => ResultatInjection::reporte(new DateTimeImmutable('2026-11-18')));
    expect(($this->passerelle)($serveur)->injecter(($this->msg)())->accepte)->toBeFalse();
    expect($this->depot->injectesLe($this->ws, $this->jour))->toBe(0)->and(($this->compteur)())->toBe(0);
});

test('une issue incertaine COMPTE toujours, une alerte part, et il n\'y a AUCUN renvoi en SMTP direct', function () {
    Log::spy();
    $serveur = new ServeurEspion(fn () => throw new RuntimeException('coupure après envoi possible'));
    $p = ($this->passerelle)($serveur, plafond: 1);
    expect(fn () => $p->injecter(($this->msg)()))->toThrow(RuntimeException::class);
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBe('a_verifier')
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(1);
    Log::shouldHaveReceived('critical')->once();

    // Rejeu de la même clé : refusé, le serveur n'est PAS rappelé.
    expect(fn () => $p->injecter(($this->msg)()))->toThrow(EnvoiRefuse::class, 'aucun renvoi automatique');
    expect($serveur->appels)->toBe(1);
});

test('une réservation restée « reservee » au-delà du délai passe en a_verifier, compte toujours, et ne repart pas en SMTP direct', function () {
    Log::spy();
    $ancien = $this->jour->modify('-1 hour')->format('c');
    DB::table('envoi_journal_commandes')->insert([
        'workspace_id' => $this->ws, 'commande' => 'injection', 'auteur' => 'passerelle', 'jour' => '2026-11-17',
        'cle_idempotence' => 'k1', 'statut' => 'reservee', 'cree_le' => $ancien,
    ]);
    $serveur = new ServeurEspion(fn () => ResultatInjection::accepte('id-1'));
    $p = ($this->passerelle)($serveur);
    expect(fn () => $p->injecter(($this->msg)()))->toThrow(EnvoiRefuse::class, 'aucun renvoi automatique');
    expect($serveur->appels)->toBe(0)
        ->and($this->depot->etatInjection($this->ws, 'k1'))->toBe('a_verifier')
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(1);
    Log::shouldHaveReceived('critical')->once();
});

test('avec un serveur qui dédoublonne par clé (simulateur), le renvoi de la même clé est permis', function () {
    $faux = new FauxServeurEnvoi;
    $p = ($this->passerelle)($faux);
    expect($p->injecter(($this->msg)())->accepte)->toBeTrue();
    expect($p->injecter(($this->msg)())->accepte)->toBeTrue()
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(1);
});

test('isolement entre espaces : la même clé dans un autre espace ne voit ni réservation, ni libération', function () {
    $autre = F::espace('zz-ric-b');
    $depot = $this->depot;
    $depot->reserverInjection($this->ws, $this->jour, 'k1', $this->jour);
    WorkspaceContext::set($autre);
    expect($depot->etatInjection($autre, 'k1'))->toBeNull()
        ->and($depot->injectesLe($autre, $this->jour))->toBe(0);
    expect($depot->reserverInjection($autre, $this->jour, 'k1', $this->jour))->toBeTrue();
    expect($depot->concluireInjection($autre, $this->jour, 'k1', 'liberee'))->toBeTrue();
    WorkspaceContext::set($this->ws);
    expect($depot->etatInjection($this->ws, 'k1'))->toBe('reservee')
        ->and($depot->injectesLe($this->ws, $this->jour))->toBe(1);
});

test('une réservation ne se conclut qu\'une fois ; sans réservation, aucune conclusion n\'est posée', function () {
    expect($this->depot->concluireInjection($this->ws, $this->jour, 'jamais', 'acceptee'))->toBeFalse();
    $this->depot->reserverInjection($this->ws, $this->jour, 'k1', $this->jour);
    expect($this->depot->concluireInjection($this->ws, $this->jour, 'k1', 'acceptee'))->toBeTrue()
        ->and($this->depot->concluireInjection($this->ws, $this->jour, 'k1', 'liberee'))->toBeFalse()
        ->and($this->depot->etatInjection($this->ws, 'k1'))->toBe('acceptee');
});

test('B3 : un refus fait AVANT tout envoi (EnvoiSmtpRefuse) est un refus CERTAIN : liberee, aucune alerte « issue incertaine »', function () {
    Log::spy();
    $serveur = new ServeurEspion(fn () => throw EnvoiSmtpRefuse::motif('enveloppe invalide'));
    $p = ($this->passerelle)($serveur, plafond: 1);
    expect(fn () => $p->injecter(($this->msg)()))->toThrow(EnvoiSmtpRefuse::class);
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBeNull()
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(0);
    Log::shouldNotHaveReceived('critical');
});

test('B2 : un renvoi CONFIRMÉ (serveur dédoublonnant) après a_verifier conclut en acceptee, une seule fois', function () {
    $panne = true;
    $serveur = new ServeurEspionDedoublonneur(function () use (&$panne) {
        if ($panne) {
            throw new RuntimeException('coupure après envoi possible');
        }

        return ResultatInjection::accepte('id-1');
    });
    $p = ($this->passerelle)($serveur);
    expect(fn () => $p->injecter(($this->msg)()))->toThrow(RuntimeException::class);
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBe('a_verifier');

    $panne = false;
    expect($p->injecter(($this->msg)())->accepte)->toBeTrue();
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBe('acceptee')
        ->and($this->depot->concluireInjection($this->ws, $this->jour, 'k1', 'acceptee'))->toBeFalse() // déjà confirmée : idempotent
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(1);
    $p->injecter(($this->msg)()); // un second renvoi ne repose aucune confirmation
    expect(DB::table('envoi_journal_commandes')->where('workspace_id', $this->ws)->where('commande', 'injection_confirmee')->count())->toBe(1);
});
