<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Kumo\RegistreMemoire;
use App\Envoi\Serveur\ServeurEnvoiKumo;
use Tests\Unit\Envoi\DepotMemoire;

require_once __DIR__ . '/../../../Envoi/KitConformiteServeurEnvoi.php';
require_once __DIR__ . '/FauxTransportKumo.php';

const WS_K9 = '00000000-0000-4000-8000-000000000001';

final class CommandeurEspion implements CommandeurServeur
{
    /** @var list<string> */
    public array $commandes = [];

    public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
    {
        $this->commandes[] = $commande;

        return true;
    }
}

function kumoFabrique(?FauxTransportKumo $t = null, ?CommandeurEspion $c = null, ?DateTimeImmutable $maintenant = null): ServeurEnvoiKumo
{
    $depot = new DepotMemoire;
    $t ??= new FauxTransportKumo;
    $horloge = $maintenant ?? new DateTimeImmutable('2026-11-08 10:00:00');

    return new ServeurEnvoiKumo(WS_K9, 'src1', $t, $t, new RegistreMemoire, $c ?? new CommandeurEspion, $depot, 'jeton-de-test', fn () => $horloge);
}

function msgK9(string $cle = 'k1', string $dest = 'temoin@example.test'): MessageAEnvoyer
{
    return new MessageAEnvoyer(WS_K9, $dest, 'from@example.test', 'S', 'C', $cle);
}

beforeEach(function () {
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true]);
});

kitConformiteServeurEnvoi(function () {
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true]);

    return kumoFabrique();
});

test('verrou fermé : zéro connexion, rien enregistré', function () {
    config(['envoi.reel_actif' => false]);
    $t = new FauxTransportKumo;
    $r = kumoFabrique($t)->injecter(msgK9());
    expect($r->accepte)->toBeFalse()->and($t->connexions)->toBe(0)->and($t->interrogations)->toBe(0);
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => false]);
    kumoFabrique($t)->injecter(msgK9());
    expect($t->connexions)->toBe(0);
});

test('hors liste blanche des témoins : refus, zéro connexion', function () {
    $t = new FauxTransportKumo;
    expect(fn () => kumoFabrique($t)->injecter(msgK9('h', 'inconnu@example.test')))->toThrow(EnvoiRefuse::class);
    expect($t->connexions)->toBe(0);
});

test('le jeton X-Envoi-Jeton est transmis au transport', function () {
    $t = new FauxTransportKumo;
    kumoFabrique($t)->injecter(msgK9());
    expect($t->dernierJeton)->toBe('jeton-de-test');
});

test('4xx : report de tranche, une seule tentative, jamais de boucle', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [451, 451, 451];
    $s = kumoFabrique($t);
    $r = $s->injecter(msgK9());
    expect($r->accepte)->toBeFalse()->and($r->reporteAu)->not->toBeNull()->and($t->connexions)->toBe(1);
    // Rappel avant l'échéance : aucune nouvelle connexion.
    $s->injecter(msgK9());
    expect($t->connexions)->toBe(1);
});

test('coupure après DATA : a_verifier, jamais renvoyé, état consulté avant toute réinjection', function () {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    $s = kumoFabrique($t);
    $r = $s->injecter(msgK9());
    expect($r->accepte)->toBeFalse()->and($t->connexions)->toBe(1);
    // Rappel : etatEnvoi est interrogé, et le sort reste inconnu => toujours aucun renvoi.
    $r2 = $s->injecter(msgK9());
    expect($r2->accepte)->toBeFalse()->and($t->interrogations)->toBeGreaterThan(0)->and($t->connexions)->toBe(1);
});

test('coupure après DATA puis le serveur confirme : accepté sans renvoi', function () {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    $s = kumoFabrique($t);
    $s->injecter(msgK9());
    $t->etatDistant = EtatMessage::Envoye;
    $r = $s->injecter(msgK9());
    expect($r->accepte)->toBeTrue()->and($t->connexions)->toBe(1);
});

test('coupure avant DATA : report simple, rien n’est parti', function () {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_avant_data', 250];
    $s = kumoFabrique($t);
    expect($s->injecter(msgK9())->accepte)->toBeFalse();
});

test('3 x 5xx en 10 minutes : pause envoyée par la passerelle', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [550, 550, 550];
    $c = new CommandeurEspion;
    $s = kumoFabrique($t, $c);
    foreach (['a', 'b'] as $k) {
        expect(fn () => $s->injecter(msgK9($k)))->toThrow(RuntimeException::class);
        expect($c->commandes)->toBe([]);
    }
    expect(fn () => $s->injecter(msgK9('c')))->toThrow(RuntimeException::class);
    expect($c->commandes)->toBe(['pause']);
});

test('2 x 5xx seulement : pas de pause', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [550, 550];
    $c = new CommandeurEspion;
    $s = kumoFabrique($t, $c);
    foreach (['a', 'b'] as $k) {
        try {
            $s->injecter(msgK9($k));
        } catch (RuntimeException) {
        }
    }
    expect($c->commandes)->toBe([]);
});

test('pause, reprise et retrait passent par la passerelle (commandes signées)', function () {
    $c = new CommandeurEspion;
    $s = kumoFabrique(null, $c);
    $s->pause();
    $s->reprise();
    $s->retirer();
    expect($c->commandes)->toBe(['pause', 'reprise', 'retrait']);
});
