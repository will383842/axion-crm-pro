<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\Imap\Verp;
use App\Envoi\PasserelleLocale;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Kumo\RegistreInjections;
use App\Envoi\Serveur\Kumo\RegistreMemoire;
use App\Envoi\Serveur\Kumo\TransportSmtpTunnel;
use App\Envoi\Serveur\ServeurEnvoiKumo;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use Illuminate\Support\Facades\Log;
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
    expect($reg->tout(WS_K9))->toBe([]); // rien enregistré : ni ligne, ni compteur, ni pause
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => false]);
    kumoFabrique($t, null, null, $reg)->injecter(msgK9());
    expect($t->connexions)->toBe(0)->and($reg->tout(WS_K9))->toBe([]);
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
    expect($reg->lire(WS_K9, 'm1')['jeton'])->toBe($premier);
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
    expect($reg->lire(WS_K9, 'k1')['etat'])->toBe('reporte');
    // Rappel avant l'échéance : aucune nouvelle connexion.
    $maintenant = $maintenant->modify('+5 minutes');
    $s->injecter(msgK9());
    expect($t->connexions)->toBe(1);
    // Après l'échéance : retour à l'envoi, une seule tentative de plus.
    $maintenant = $maintenant->modify('+20 minutes');
    expect($s->injecter(msgK9())->accepte)->toBeTrue();
    expect($t->connexions)->toBe(2)->and($reg->lire(WS_K9, 'k1')['etat'])->toBe('accepte');
});

test('coupure après DATA : a_verifier, jamais renvoyé, état consulté avant toute réinjection', function () {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    $reg = new RegistreMemoire;
    $s = kumoFabrique($t, null, null, $reg);
    $r = $s->injecter(msgK9());
    expect($r->accepte)->toBeFalse()->and($t->connexions)->toBe(1)->and($reg->lire(WS_K9, 'k1')['etat'])->toBe('a_verifier');
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
    expect($reg->lire(WS_K9, 'k1')['etat'])->toBe('reporte')->and($t->interrogations)->toBe(0); // PAS a_verifier
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
    expect($reg->enPause(WS_K9))->toBeTrue();
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
    expect($c->commandes)->toBe(['pause'])->and($reg->enPause(WS_K9))->toBeTrue();
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
    expect($r->ecrireSi(WS_K9, 'k', null, null, $l))->toBeTrue()->and($r->ecrireSi(WS_K9, 'k', null, null, $l))->toBeFalse();
    expect($r->ecrireSi(WS_K9, 'k', 'reporte', 1, ['etat' => 'accepte'] + $l))->toBeFalse()->and($r->lire(WS_K9, 'k')['etat'])->toBe('injection_en_cours');
    expect($r->ecrireSi(WS_K9, 'k', 'injection_en_cours', 1, ['etat' => 'accepte'] + $l))->toBeTrue()->and($r->lire(WS_K9, 'k')['etat'])->toBe('accepte');
});

test('deux workers, même clé, lecture périmée : un seul envoi', function () {
    $reg = new RegistreMemoire;
    // Le 2e worker a lu AVANT l'écriture du 1er : sa lecture est périmée (null), seul le CAS le arrête.
    $perime = new class($reg) implements RegistreInjections
    {
        public function __construct(private readonly RegistreMemoire $r) {}

        public function lire(string $workspaceId, string $cle): ?array
        {
            return null;
        }

        public function ecrire(string $workspaceId, string $cle, array $ligne): void
        {
            $this->r->ecrire($workspaceId, $cle, $ligne);
        }

        public function ecrireSi(string $workspaceId, string $cle, ?string $etatAttendu, ?int $instantAttendu, array $ligne): bool
        {
            return $this->r->ecrireSi($workspaceId, $cle, $etatAttendu, $instantAttendu, $ligne);
        }

        public function tout(string $workspaceId): array
        {
            return $this->r->tout($workspaceId);
        }

        public function enPause(string $workspaceId): bool
        {
            return $this->r->enPause($workspaceId);
        }

        public function fixerPause(string $workspaceId, bool $pause): void
        {
            $this->r->fixerPause($workspaceId, $pause);
        }

        public function noter5xx(string $workspaceId, int $instant): void
        {
            $this->r->noter5xx($workspaceId, $instant);
        }

        public function compter5xx(string $workspaceId, int $depuis): int
        {
            return $this->r->compter5xx($workspaceId, $depuis);
        }

        public function reinitialiser5xx(string $workspaceId): void
        {
            $this->r->reinitialiser5xx($workspaceId);
        }

        public function prochaineSequence(string $workspaceId): int
        {
            return $this->r->prochaineSequence($workspaceId);
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
    $reg->ecrire(WS_K9, 'vieille', $vieille);
    $reg->ecrire(WS_K9, 'recente', ['id' => 'r', 'instant' => $maintenant->getTimestamp() - 30] + $vieille);
    expect($s->reconcilierOrphelines(5))->toBe(1);
    expect($reg->lire(WS_K9, 'vieille')['etat'])->toBe('a_verifier')->and($reg->lire(WS_K9, 'recente')['etat'])->toBe('injection_en_cours');
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

// ───────── Audit d'exactitude K9 : tests écrits ROUGES d'abord ─────────

const WS_K9_B = '00000000-0000-4000-8000-000000000002';

/** @param array<string, mixed> $c */
function kumoEspace(string $ws, FauxTransportKumo $t, RegistreInjections $reg, ?CommandeurServeur $c = null, ?Closure $horloge = null): ServeurEnvoiKumo
{
    $fixe = new DateTimeImmutable('2026-11-08 10:00:00');

    return new ServeurEnvoiKumo($ws, 'src1', $t, $t, $reg, $c ?? new CommandeurEspion, new DepotMemoire, SECRET_K9, $horloge ?? fn () => $fixe);
}

function msgEspace(string $ws, string $cle): MessageAEnvoyer
{
    return new MessageAEnvoyer($ws, 'temoin@example.test', 'from@example.test', 'S', 'C', $cle);
}

test('(1) échec AUTH/certificat : Log::critical sans secret, pause, aucun réessai (zéro connexion ensuite)', function () {
    Log::spy();
    $t = new FauxTransportKumo;
    $t->scenario = ['echec_auth', 250];
    $reg = new RegistreMemoire;
    $c = new CommandeurEspion;
    $s = kumoFabrique($t, $c, null, $reg);
    $r = $s->injecter(msgK9('a1'));
    expect($r->accepte)->toBeFalse()->and($s->etat()->enPause)->toBeTrue();
    Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $m, array $ctx = []) => ! str_contains($m . json_encode($ctx), 'secret') && ! isset($ctx['exception']) && ! isset($ctx['trace']));
    // Pas de réessai de 15 minutes : l'autre message ne se connecte PAS.
    $s->injecter(msgK9('a2'));
    expect($t->connexions)->toBe(1);
    // Le message en échec d'AUTH n'est pas reporté à +15 minutes : au plus tôt demain.
    expect($r->reporteAu->getTimestamp())->toBeGreaterThan((new DateTimeImmutable('2026-11-08 10:00:00'))->modify('+1 hour')->getTimestamp());
});

test('(2) enveloppe invalide : refusée AVANT tout état, zéro connexion, refus journalisé', function (string $dest) {
    Log::spy();
    $t = new FauxTransportKumo;
    $reg = new RegistreMemoire;
    $m = new MessageAEnvoyer(WS_K9, $dest, 'from@example.test', 'S', 'C', 'bad');
    expect(fn () => kumoFabrique($t, null, null, $reg)->injecter($m))->toThrow(ServeurEnvoiRefuse::class);
    expect($t->connexions)->toBe(0)->and($reg->tout(WS_K9))->toBe([]);
    Log::shouldHaveReceived('warning')->once();
})->with(['a b@example.test', 'é@example.test', 'a@@x']);

// I3 : un refus LOCAL du transport (jeton, transaction, flux, secret) ne concerne pas le message : rien n'est parti,
// donc report + alerte critique, JAMAIS « echec » terminal (sinon une panne de configuration tuerait toute la file).
// Le test garde ses garanties d'origine (ni a_verifier, ni 5xx compté, refus journalisé) et prouve en plus l'absence d'échec.
test('(2) refus local du transport (jeton/transaction) : report + alerte critique, jamais echec ni a_verifier ni 5xx compté', function () {
    Log::spy();
    $t = new FauxTransportKumo;
    $t->scenario = ['envoi_refuse_local'];
    $reg = new RegistreMemoire;
    $r = kumoFabrique($t, null, null, $reg)->injecter(msgK9('r1'));
    expect($r->accepte)->toBeFalse();
    expect($reg->lire(WS_K9, 'r1')['etat'])->toBe('reporte')->and($reg->compter5xx(WS_K9, 0))->toBe(0);
    Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $m, array $ctx = []) => ($ctx['workspace_id'] ?? null) === WS_K9);
});

// B1 : 5xx de connexion (bannière) => report + pause + alerte, aucun echec, aucun 5xx compté.
test('(B1) 5xx de connexion : report et pause, jamais echec', function () {
    Log::spy();
    $t = new FauxTransportKumo;
    $t->scenario = ['refus_connexion'];
    $reg = new RegistreMemoire;
    $s = kumoFabrique($t, null, null, $reg);
    $r = $s->injecter(msgK9('b1'));
    expect($r->accepte)->toBeFalse()->and($reg->lire(WS_K9, 'b1')['etat'])->toBe('reporte')
        ->and($s->etat()->enPause)->toBeTrue()->and($reg->compter5xx(WS_K9, 0))->toBe(0);
    Log::shouldHaveReceived('critical')->once();
});

test('(I3) source mal formée : refusée à la construction', function (string $source) {
    expect(fn () => new ServeurEnvoiKumo(WS_K9, $source, new FauxTransportKumo, new FauxTransportKumo, new RegistreMemoire, new CommandeurEspion, new DepotMemoire, SECRET_K9))
        ->toThrow(InvalidArgumentException::class);
})->with(['', 'a b', "x\r\ny", str_repeat('s', 65)]);

test('(3) X-Envoi-Source : la source de l’adaptateur est transmise au transport', function () {
    $t = new FauxTransportKumo;
    kumoFabrique($t)->injecter(msgK9('s1'));
    expect($t->derniereSource)->toBe('src1');
});

test('(4) événements : curseur = numéro de séquence stable (k1 a_verifier, k2 accepté, puis k1 confirmé)', function () {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data', 250];
    $s = kumoFabrique($t);
    $s->injecter(msgK9('k1'));                       // k1 : a_verifier
    $id2 = $s->injecter(msgK9('k2'))->idInjection;   // k2 : accepté (séquence 1)
    $lot1 = $s->lireEvenements(null, 1);
    expect($lot1->evenements)->toHaveCount(1)->and($lot1->evenements[0]->idInjection)->toBe($id2);
    $t->etatDistant = EtatMessage::Envoye;
    $id1 = $s->injecter(msgK9('k1'))->idInjection;   // k1 confirmé (séquence 2)
    $lot2 = $s->lireEvenements($lot1->curseurSuivant, 10);
    expect(array_map(fn ($e) => $e->idInjection, $lot2->evenements))->toBe([$id1]);   // ni perdu ni dupliqué
    $lot3 = $s->lireEvenements($lot2->curseurSuivant, 10);
    expect($lot3->evenements)->toBe([]);
    $tous = array_map(fn ($e) => $e->idInjection, $s->lireEvenements(null, 10)->evenements);
    expect($tous)->toBe([$id2, $id1]);
});

test('curseur négatif ou illisible => 0', function (string $curseur) {
    $s = kumoFabrique();
    $s->injecter(msgK9('c1'));
    expect($s->lireEvenements($curseur, 10)->evenements)->toHaveCount(1);
})->with(['-5', 'abc']);

test('(6) RegistreMemoire refusé hors environnement testing', function () {
    $ancien = app()->environment();
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => new RegistreMemoire)->toThrow(RuntimeException::class);
    } finally {
        app()->detectEnvironment(fn () => $ancien);
    }
});

test('(7) registre par espace : pause et compteur de 5xx isolés, mêmes clés sans collision', function () {
    $reg = new RegistreMemoire;
    $tA = new FauxTransportKumo;
    $tB = new FauxTransportKumo;
    $tA->scenario = [550, 550, 550];
    $a = kumoEspace(WS_K9, $tA, $reg);
    $b = kumoEspace(WS_K9_B, $tB, $reg);
    foreach (['x', 'y', 'z'] as $k) {
        try {
            $a->injecter(msgEspace(WS_K9, $k));
        } catch (RuntimeException) {
        }
    }
    expect($a->etat()->enPause)->toBeTrue()->and($b->etat()->enPause)->toBeFalse();
    expect($reg->compter5xx(WS_K9_B, 0))->toBe(0);
    // Même clé d'idempotence dans l'autre espace : pas de collision, envoi normal.
    expect($b->injecter(msgEspace(WS_K9_B, 'x'))->accepte)->toBeTrue()->and($tB->connexions)->toBe(1);
    expect(array_keys($reg->tout(WS_K9_B)))->toBe(['x'])->and(array_keys($reg->tout(WS_K9)))->toBe(['x', 'y', 'z']);
});

test('(8) exception inattendue : journalisée SANS message (donc sans secret) avant a_verifier', function () {
    Log::spy();
    $t = new FauxTransportKumo;
    $t->scenario = ['panne_secret'];
    $reg = new RegistreMemoire;
    kumoFabrique($t, null, null, $reg)->injecter(msgK9('p1'));
    expect($reg->lire(WS_K9, 'p1')['etat'])->toBe('a_verifier');
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $m, array $ctx = []) => ! str_contains($m . json_encode($ctx), 'secret-visible-xyz') && ($ctx['classe'] ?? null) === RuntimeException::class);
});

test('mineur : la reprise remet le compteur de 5xx à zéro', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [550, 550, 550, 550];
    $c = new CommandeurEspion;
    $s = kumoFabrique($t, $c);
    foreach (['a', 'b'] as $k) {
        try {
            $s->injecter(msgK9($k));
        } catch (RuntimeException) {
        }
    }
    $s->reprise();
    foreach (['c', 'd'] as $k) {
        try {
            $s->injecter(msgK9($k));
        } catch (RuntimeException) {
        }
    }
    expect($c->commandes)->toBe(['reprise'])->and($s->etat()->enPause)->toBeFalse(); // 2 + 2 : jamais 3 d'affilée après la reprise
});

test('mineur : le CAS compare aussi l’instant (ABA sur reporte)', function () {
    $r = new RegistreMemoire;
    $l = ['id' => 'i', 'etat' => 'reporte', 'reporte_au' => 5, 'instant' => 100, 'jeton' => str_repeat('a', 32)];
    $r->ecrire(WS_K9, 'k', $l);
    $r->ecrire(WS_K9, 'k', ['instant' => 200] + $l); // un autre worker est passé par injection_en_cours et revenu à reporte
    expect($r->ecrireSi(WS_K9, 'k', 'reporte', 100, ['etat' => 'injection_en_cours'] + $l))->toBeFalse();
    expect($r->ecrireSi(WS_K9, 'k', 'reporte', 200, ['etat' => 'injection_en_cours'] + $l))->toBeTrue();
});

test('mineur : étatDistant et la décision sur existant interprètent pareil EnFile/Rebond/Refuse', function (EtatMessage $distant, string $attendu) {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    $reg = new RegistreMemoire;
    $s = kumoFabrique($t, null, null, $reg);
    $s->injecter(msgK9('e1'));
    $id = $reg->lire(WS_K9, 'e1')['id'];
    $t->etatDistant = $distant;
    expect($s->etatEnvoi($id))->toBe($distant)->and($reg->lire(WS_K9, 'e1')['etat'])->toBe($attendu);
})->with([
    'enfile' => [EtatMessage::EnFile, 'accepte'],
    'rebond' => [EtatMessage::Rebond, 'accepte'],
    'envoye' => [EtatMessage::Envoye, 'accepte'],
    'refuse' => [EtatMessage::Refuse, 'echec'],
    'inconnu' => [EtatMessage::Inconnu, 'a_verifier'],
]);

test('mineur : le report d’une clé déjà reportée utilise l’horloge injectée (fuseau compris)', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [451];
    $h = new DateTimeImmutable('2026-11-08 10:00:00', new DateTimeZone('Asia/Tokyo'));
    $s = kumoFabrique($t, null, fn () => $h);
    $s->injecter(msgK9('h1'));
    $r = $s->injecter(msgK9('h1'));
    expect($r->reporteAu->getTimezone()->getName())->toBe('Asia/Tokyo');
});

// ---------------------------------------------------------------------------------------------------------------------
// L8-F14a (B2) — le signal `incertain` : la passerelle ne rend la place QUE sur un refus CERTAIN.
// ---------------------------------------------------------------------------------------------------------------------

/** Passerelle de test devant un Kumo scripté : plafond $n, mode témoins respecté (témoin@example.test). */
function kumoPasserelle(ServeurEnvoiKumo $kumo, DepotMemoire $depot, int $n): PasserelleLocale
{
    return new PasserelleLocale($kumo, $depot, true, $n, fn () => new DateTimeImmutable('2026-11-17 10:00:00', new DateTimeZone('Europe/Paris')), WS_K9, 300, fn () => WS_K9);
}

test('B2 Kumo : coupure après DATA => report INCERTAIN, la place reste comptée (plafond 1 jamais dépassé), alerte, aucun renvoi', function () {
    Log::spy();
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    $depot = new DepotMemoire;
    $kumo = kumoFabrique($t);
    expect($kumo->injecter(msgK9('x'))->incertain)->toBeTrue(); // le signal lui-même
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data', 250];
    $p = kumoPasserelle(kumoFabrique($t), $depot, 1);

    expect($p->injecter(msgK9('k1'))->accepte)->toBeFalse();
    expect($depot->etatInjection(WS_K9, 'k1'))->toBe('a_verifier')
        ->and($depot->injectesLe(WS_K9, new DateTimeImmutable('2026-11-17')))->toBe(1);
    Log::shouldHaveReceived('critical')->once();
    // Plafond 1 déjà pris par l'incertain : le suivant est reporté SANS appeler Kumo.
    expect($p->injecter(msgK9('k2'))->accepte)->toBeFalse()->and($t->connexions)->toBe(1);
});

test('B2 Kumo : clé injection_en_cours ou a_verifier dans le registre => incertain ; report net (4xx) => PAS incertain', function () {
    $reg = new RegistreMemoire;
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    $s = kumoFabrique($t, null, null, $reg);
    $s->injecter(msgK9('a'));                                  // a_verifier
    expect($s->injecter(msgK9('a'))->incertain)->toBeTrue();   // état inconnu côté serveur
    $reg->ecrire(WS_K9, 'b', ['id' => 'i', 'etat' => 'injection_en_cours', 'reporte_au' => null, 'instant' => 1, 'jeton' => str_repeat('a', 32)]);
    expect($s->injecter(msgK9('b'))->incertain)->toBeTrue();   // un autre worker est en route
    $t->scenario = [451];
    $net = $s->injecter(msgK9('c'));
    expect($net->accepte)->toBeFalse()->and($net->incertain)->toBeFalse(); // 4xx : rien n'est parti
});

test('B2 Kumo : un 4xx (report net) LIBÈRE la place et un 5xx définitif LIBÈRE sans alerte « incertaine »', function () {
    Log::spy();
    $depot = new DepotMemoire;
    $t = new FauxTransportKumo;
    $t->scenario = [451, 550];
    $p = kumoPasserelle(kumoFabrique($t), $depot, 1);
    $jour = new DateTimeImmutable('2026-11-17');

    expect($p->injecter(msgK9('k1'))->accepte)->toBeFalse();
    expect($depot->etatInjection(WS_K9, 'k1'))->toBeNull()->and($depot->injectesLe(WS_K9, $jour))->toBe(0);
    expect(fn () => $p->injecter(msgK9('k2')))->toThrow(RefusDefinitifServeur::class);
    expect($depot->etatInjection(WS_K9, 'k2'))->toBeNull()->and($depot->injectesLe(WS_K9, $jour))->toBe(0);
    Log::shouldNotHaveReceived('critical');
});
