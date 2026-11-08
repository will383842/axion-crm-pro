<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Imap\Verp;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Kumo\RegistreInjections;
use App\Envoi\Serveur\Kumo\RegistreMemoire;
use App\Envoi\Serveur\Kumo\TransportSmtpTunnel;
use App\Envoi\Serveur\ServeurEnvoiKumo;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
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

const SECRET_K9 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; // valeur factice, entropie nulle (Gitleaks)

/** @param ?Closure(): DateTimeImmutable $horloge */
function kumoFabrique(?FauxTransportKumo $t = null, ?CommandeurServeur $c = null, ?Closure $horloge = null, ?RegistreInjections $registre = null): ServeurEnvoiKumo
{
    $t ??= new FauxTransportKumo;
    $fixe = new DateTimeImmutable('2026-11-08 10:00:00');

    return new ServeurEnvoiKumo(WS_K9, 'src1', $t, $t, $registre ?? new RegistreMemoire, $c ?? new CommandeurEspion, new DepotMemoire, SECRET_K9, $horloge ?? fn () => $fixe);
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
    $reg = new RegistreMemoire;
    $r = kumoFabrique($t, null, null, $reg)->injecter(msgK9());
    expect($r->accepte)->toBeFalse()->and($t->connexions)->toBe(0)->and($t->interrogations)->toBe(0);
    expect($reg->tout())->toBe([]); // rien enregistré : ni ligne, ni compteur, ni pause
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => false]);
    kumoFabrique($t, null, null, $reg)->injecter(msgK9());
    expect($t->connexions)->toBe(0)->and($reg->tout())->toBe([]);
});

test('hors liste blanche des témoins : refus, zéro connexion', function () {
    $t = new FauxTransportKumo;
    expect(fn () => kumoFabrique($t)->injecter(msgK9('h', 'inconnu@example.test')))->toThrow(EnvoiRefuse::class);
    expect($t->connexions)->toBe(0);
});

test('jeton PAR message : deux messages => deux jetons distincts, valides, d’au moins 128 bits', function () {
    $t = new FauxTransportKumo;
    $s = kumoFabrique($t);
    $s->injecter(msgK9('m1'));
    $j1 = $t->dernierJeton;
    $s->injecter(msgK9('m2'));
    $j2 = $t->dernierJeton;
    expect($j1)->toMatch('/^[0-9a-f]{32,64}$/')->and($j2)->toMatch('/^[0-9a-f]{32,64}$/')->and($j1)->not->toBe($j2);
    expect($j1)->not->toContain(SECRET_K9)->and($j1)->not->toBe(SECRET_K9);
});

test('jeton : un renvoi de la même clé garde le même jeton (et le jeton est stocké dans la ligne du registre)', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [451, 250];
    $reg = new RegistreMemoire;
    $maintenant = new DateTimeImmutable('2026-11-08 10:00:00');
    $s = kumoFabrique($t, null, function () use (&$maintenant) {
        return $maintenant;
    }, $reg);
    $s->injecter(msgK9('m1'));
    $premier = $t->dernierJeton;
    expect($reg->lire('m1')['jeton'])->toBe($premier);
    $maintenant = $maintenant->modify('+2 hours');
    expect($s->injecter(msgK9('m1'))->accepte)->toBeTrue();
    expect($t->dernierJeton)->toBe($premier)->and($t->connexions)->toBe(2);
    // Même clé, autre instance de l'adaptateur (autre worker) : même jeton, dérivé et non tiré au hasard.
    $t2 = new FauxTransportKumo;
    kumoFabrique($t2)->injecter(msgK9('m1'));
    expect($t2->dernierJeton)->toBe($premier);
});

test('le secret de dérivation trop court est refusé à la construction', function () {
    $t = new FauxTransportKumo;
    expect(fn () => new ServeurEnvoiKumo(WS_K9, 's', $t, $t, new RegistreMemoire, new CommandeurEspion, new DepotMemoire, 'court'))
        ->toThrow(InvalidArgumentException::class);
});

test('4xx : report de tranche, une seule tentative, jamais de boucle', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [451, 250];
    $reg = new RegistreMemoire;
    $maintenant = new DateTimeImmutable('2026-11-08 10:00:00');
    $s = kumoFabrique($t, null, function () use (&$maintenant) {
        return $maintenant;
    }, $reg);
    $r = $s->injecter(msgK9());
    expect($r->accepte)->toBeFalse()->and($r->reporteAu)->not->toBeNull()->and($t->connexions)->toBe(1);
    expect($reg->lire('k1')['etat'])->toBe('reporte');
    // Rappel avant l'échéance : aucune nouvelle connexion.
    $maintenant = $maintenant->modify('+5 minutes');
    $s->injecter(msgK9());
    expect($t->connexions)->toBe(1);
    // Après l'échéance : retour à l'envoi, une seule tentative de plus.
    $maintenant = $maintenant->modify('+20 minutes');
    expect($s->injecter(msgK9())->accepte)->toBeTrue();
    expect($t->connexions)->toBe(2)->and($reg->lire('k1')['etat'])->toBe('accepte');
});

test('coupure après DATA : a_verifier, jamais renvoyé, état consulté avant toute réinjection', function () {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    $reg = new RegistreMemoire;
    $s = kumoFabrique($t, null, null, $reg);
    $r = $s->injecter(msgK9());
    expect($r->accepte)->toBeFalse()->and($t->connexions)->toBe(1)->and($reg->lire('k1')['etat'])->toBe('a_verifier');
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
    $reg = new RegistreMemoire;
    $maintenant = new DateTimeImmutable('2026-11-08 10:00:00');
    $s = kumoFabrique($t, null, function () use (&$maintenant) {
        return $maintenant;
    }, $reg);
    expect($s->injecter(msgK9())->accepte)->toBeFalse();
    expect($reg->lire('k1')['etat'])->toBe('reporte')->and($t->interrogations)->toBe(0); // PAS a_verifier
    $maintenant = $maintenant->modify('+1 hour');
    expect($s->injecter(msgK9())->accepte)->toBeTrue()->and($t->connexions)->toBe(2);
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

test('pause() pose la pause AVANT la commande : passerelle injoignable => plus aucune injection', function () {
    $c = new class implements CommandeurServeur
    {
        public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
        {
            throw ServeurEnvoiRefuse::motif('passerelle injoignable');
        }
    };
    $t = new FauxTransportKumo;
    $s = kumoFabrique($t, $c);
    expect(fn () => $s->pause())->toThrow(ServeurEnvoiRefuse::class);
    expect($s->etat()->enPause)->toBeTrue();
    $r = $s->injecter(msgK9());
    expect($r->accepte)->toBeFalse()->and($t->connexions)->toBe(0);
});

test('la pause est persistée dans le registre : une autre instance (autre worker) la voit', function () {
    $reg = new RegistreMemoire;
    kumoFabrique(null, null, null, $reg)->pause();
    $t = new FauxTransportKumo;
    $autre = kumoFabrique($t, null, null, $reg);
    expect($autre->etat()->enPause)->toBeTrue()->and($autre->injecter(msgK9())->accepte)->toBeFalse()->and($t->connexions)->toBe(0);
    $autre->reprise();
    expect(kumoFabrique(null, null, null, $reg)->etat()->enPause)->toBeFalse();
});

test('reprise : si la commande échoue, la pause reste posée (sûr par défaut)', function () {
    $reg = new RegistreMemoire;
    kumoFabrique(null, null, null, $reg)->pause();
    $ko = new class implements CommandeurServeur
    {
        public function commander(string $workspaceId, string $source, string $commandeId, string $commande): bool
        {
            throw ServeurEnvoiRefuse::motif('injoignable');
        }
    };
    expect(fn () => kumoFabrique(null, $ko, null, $reg)->reprise())->toThrow(ServeurEnvoiRefuse::class);
    expect($reg->enPause())->toBeTrue();
});

test('5xx : la fenêtre de 10 minutes compte avec une horloge avancée', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [550, 550, 550];
    $c = new CommandeurEspion;
    $maintenant = new DateTimeImmutable('2026-11-08 10:00:00');
    $s = kumoFabrique($t, $c, function () use (&$maintenant) {
        return $maintenant;
    });
    foreach (['a', 'b', 'c'] as $k) {
        try {
            $s->injecter(msgK9($k));
        } catch (RuntimeException) {
        }
        $maintenant = $maintenant->modify('+6 minutes'); // a→10:00, b→10:06, c→10:12 : jamais 3 dans 10 min
    }
    expect($c->commandes)->toBe([]);
});

test('5xx : 3 en 10 minutes sont comptés dans le registre partagé (compteur persisté)', function () {
    $reg = new RegistreMemoire;
    $c = new CommandeurEspion;
    foreach (['a', 'b', 'c'] as $k) {
        $t = new FauxTransportKumo;
        $t->scenario = [550];
        try {
            kumoFabrique($t, $c, null, $reg)->injecter(msgK9($k)); // une instance NEUVE à chaque fois
        } catch (RuntimeException) {
        }
    }
    expect($c->commandes)->toBe(['pause'])->and($reg->enPause())->toBeTrue();
});

test('un échec lu par interrogation (sort Refuse) ne compte PAS comme un 5xx', function () {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data', 'coupure_apres_data', 'coupure_apres_data'];
    $c = new CommandeurEspion;
    $s = kumoFabrique($t, $c);
    foreach (['a', 'b', 'c'] as $k) {
        $s->injecter(msgK9($k)); // a_verifier
    }
    $t->etatDistant = EtatMessage::Refuse;
    foreach (['a', 'b', 'c'] as $k) {
        expect(fn () => $s->injecter(msgK9($k)))->toThrow(RuntimeException::class);
    }
    // Un SEUL vrai 5xx ensuite : 1 < 3, pas de pause (les 3 refus lus ne s'ajoutent pas).
    $t->scenario = [550];
    expect(fn () => $s->injecter(msgK9('d')))->toThrow(RuntimeException::class);
    expect($c->commandes)->toBe([])->and($s->etat()->enPause)->toBeFalse();
});

test('registre : écriture conditionnelle (compare-and-set)', function () {
    $r = new RegistreMemoire;
    $l = ['id' => 'i', 'etat' => 'injection_en_cours', 'reporte_au' => null, 'instant' => 1, 'jeton' => str_repeat('a', 32)];
    expect($r->ecrireSi('k', null, $l))->toBeTrue()->and($r->ecrireSi('k', null, $l))->toBeFalse();
    expect($r->ecrireSi('k', 'reporte', ['etat' => 'accepte'] + $l))->toBeFalse()->and($r->lire('k')['etat'])->toBe('injection_en_cours');
    expect($r->ecrireSi('k', 'injection_en_cours', ['etat' => 'accepte'] + $l))->toBeTrue()->and($r->lire('k')['etat'])->toBe('accepte');
});

test('deux workers, même clé, lecture périmée : un seul envoi', function () {
    $reg = new RegistreMemoire;
    // Le 2e worker a lu AVANT l'écriture du 1er : sa lecture est périmée (null), seul le CAS le arrête.
    $perime = new class($reg) implements RegistreInjections
    {
        public function __construct(private readonly RegistreMemoire $r) {}

        public function lire(string $cle): ?array
        {
            return null;
        }

        public function ecrire(string $cle, array $ligne): void
        {
            $this->r->ecrire($cle, $ligne);
        }

        public function ecrireSi(string $cle, ?string $etatAttendu, array $ligne): bool
        {
            return $this->r->ecrireSi($cle, $etatAttendu, $ligne);
        }

        public function tout(): array
        {
            return $this->r->tout();
        }

        public function enPause(): bool
        {
            return $this->r->enPause();
        }

        public function fixerPause(bool $pause): void
        {
            $this->r->fixerPause($pause);
        }

        public function noter5xx(int $instant): void
        {
            $this->r->noter5xx($instant);
        }

        public function compter5xx(int $depuis): int
        {
            return $this->r->compter5xx($depuis);
        }
    };
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    kumoFabrique($t, null, null, $reg)->injecter(msgK9('x'));   // worker 1 : part, sort incertain
    $r = kumoFabrique($t, null, null, $perime)->injecter(msgK9('x')); // worker 2 : lecture périmée
    expect($r->accepte)->toBeFalse()->and($t->connexions)->toBe(1);
});

test('orphelines : une injection_en_cours ancienne devient a_verifier (jamais renvoyée), la récente reste', function () {
    $reg = new RegistreMemoire;
    $maintenant = new DateTimeImmutable('2026-11-08 10:00:00');
    $t = new FauxTransportKumo;
    $s = kumoFabrique($t, null, function () use (&$maintenant) {
        return $maintenant;
    }, $reg);
    $vieille = ['id' => 'v', 'etat' => 'injection_en_cours', 'reporte_au' => null, 'instant' => $maintenant->getTimestamp() - 3600, 'jeton' => str_repeat('b', 32)];
    $reg->ecrire('vieille', $vieille);
    $reg->ecrire('recente', ['id' => 'r', 'instant' => $maintenant->getTimestamp() - 30] + $vieille);
    expect($s->reconcilierOrphelines(5))->toBe(1);
    expect($reg->lire('vieille')['etat'])->toBe('a_verifier')->and($reg->lire('recente')['etat'])->toBe('injection_en_cours');
    expect($s->injecter(msgK9('vieille'))->accepte)->toBeFalse()->and($t->connexions)->toBe(0);
});

test('les options TLS du tunnel : certificat validé, nom pair, TLS 1.2 minimum, CA configurable', function () {
    $o = TransportSmtpTunnel::optionsTls('/etc/ssl/ca.pem', 'tunnel.exemple.test')['ssl'];
    expect($o['verify_peer'])->toBeTrue()->and($o['verify_peer_name'])->toBeTrue()->and($o['peer_name'])->toBe('tunnel.exemple.test')
        ->and($o['cafile'])->toBe('/etc/ssl/ca.pem')->and($o['SNI_enabled'])->toBeTrue();
    // TLS 1.2 et 1.3 seulement : ni le défaut de Symfony (qui admet TLS 1.1), ni 1.0/1.1 (les bits se recoupent : égalité exacte).
    expect($o['crypto_method'])->toBe(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
        ->not->toBe(STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT);
    expect(TransportSmtpTunnel::optionsTls(null, null)['ssl'])->not->toHaveKey('cafile')->not->toHaveKey('peer_name');
});

test('l’identifiant d’injection est le jeton mis en forme d’UUID : le VERP rb-<jeton> se rattache à l’injection', function () {
    config(['envoi.cle_empreinte' => 'cle-empreinte-de-test-0123456789']);
    $t = new FauxTransportKumo;
    $r = kumoFabrique($t)->injecter(msgK9('rb1'));
    expect(str_replace('-', '', $r->idInjection))->toBe($t->dernierJeton);
    $adresse = Verp::adressePour($r->idInjection, 'exemple.test');
    expect($adresse)->toStartWith('rb-' . $t->dernierJeton)->and(Verp::idInjectionDepuis($adresse))->toBe($r->idInjection);
});
