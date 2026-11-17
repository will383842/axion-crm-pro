<?php

use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Depot\DepotTentatives;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\PasserelleLocale;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Kumo\RegistreMemoire;
use App\Envoi\Serveur\ServeurEnvoiKumo;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Envoi\Smtp\AdaptateurSmtp;
use App\Envoi\Smtp\BoiteSmtp;
use App\Envoi\Smtp\DepotBoites;
use App\Envoi\Smtp\ResolveurHote;
use App\Envoi\Smtp\SourceMessageSigne;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

require_once __DIR__ . '/../../Unit/Envoi/Kumo/FauxTransportKumo.php';

uses(TestCase::class, RefreshDatabase::class);

/** R06-D — le code SMTP du serveur est conservé dans `envoi_tentatives.code_smtp`. */
beforeEach(function () {
    $this->ws = F::espace('zz-r06d');
    $this->maintenant = new DateTimeImmutable('2026-12-03 10:00:00', new DateTimeZone('Europe/Paris'));
    $this->depot = new DepotSocleDb;
    $this->depot->eteindreTemoins($this->ws, 'test-r06d');
    WorkspaceContext::set($this->ws);
    $this->passerelle = fn ($serveur, int $plafond = 300, ?DepotTentatives $t = null): PasserelleLocale => new PasserelleLocale(
        $serveur,
        $this->depot,
        true,
        $plafond,
        fn () => $this->maintenant,
        $this->ws,
        300,
        fn () => WorkspaceContext::current(),
        null,
        $t ?? new DepotTentatives,
        new ResolveurEnMemoire(['gmail.example.test' => 'gmail']),
    );
    $this->msg = fn (string $cle): MessageAEnvoyer => new MessageAEnvoyer($this->ws, 'd@gmail.example.test', 'from@example.test', 'S', 'C', $cle);
    $this->lignes = fn (): array => DB::table('envoi_tentatives')->where('workspace_id', $this->ws)->get()->map(fn ($l) => [$l->issue, $l->code_smtp])->all();
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('un refus définitif 550 écrit une ligne refusee avec code_smtp = 550', function () {
    $p = ($this->passerelle)((new FauxServeurEnvoi)->refuserDefinitivement(550));
    expect(fn () => $p->injecter(($this->msg)('k1')))->toThrow(RefusDefinitifServeur::class);

    expect(($this->lignes)())->toBe([['refusee', 550]]);
});

test('un report d\'échec 421 écrit reportee avec code_smtp = 421', function () {
    $p = ($this->passerelle)((new FauxServeurEnvoi)->reporterEchec(421));
    $r = $p->injecter(($this->msg)('k2'));

    expect($r->codeSmtp)->toBe(421)
        ->and(($this->lignes)())->toBe([['reportee', 421]]);
});

test('un code hors 200 à 599 est refusé à la construction', function () {
    foreach ([199, 600, 0, -1] as $code) {
        expect(fn () => ResultatInjection::reporteEchec(new DateTimeImmutable('2026-12-04'), $code))->toThrow(InvalidArgumentException::class);
        expect(fn () => new RefusDefinitifServeur('x', $code))->toThrow(InvalidArgumentException::class);
    }
    expect((new RefusDefinitifServeur('x'))->codeSmtp)->toBeNull()
        ->and(ResultatInjection::reporteEchec(new DateTimeImmutable('2026-12-04'))->codeSmtp)->toBeNull();
});

test('garde : un report d\'attente local (plafond) n\'écrit aucune tentative', function () {
    $p = ($this->passerelle)(new FauxServeurEnvoi, 0);
    $r = $p->injecter(($this->msg)('k3'));

    expect($r->accepte)->toBeFalse()
        ->and(DB::table('envoi_tentatives')->count())->toBe(0);
});

test('garde : un report d\'attente de pause du serveur n\'écrit aucune tentative', function () {
    $faux = new FauxServeurEnvoi;
    $faux->pause();
    $r = ($this->passerelle)($faux)->injecter(($this->msg)('k4'));

    expect($r->accepte)->toBeFalse()
        ->and(DB::table('envoi_tentatives')->count())->toBe(0);
});

test('contrôle : un report d\'échec sans code écrit reportee + null', function () {
    // Contrôle positif seulement : le vrai témoin (retrait du garde-fou de PasserelleLocale qui fait rougir la garde « pause ») est dans la PR.
    $faux = (new FauxServeurEnvoi)->reporterEchec(null);
    ($this->passerelle)($faux)->injecter(($this->msg)('k5'));

    expect(($this->lignes)())->toBe([['reportee', null]]);
});

/** E5 — le rejeu d'une clé déjà en `echec` rend le code du refus d'origine (jamais NULL). */
function e5Kumo(FauxTransportKumo $t, RegistreMemoire $registre, DepotSocleDb $depot, string $ws): ServeurEnvoiKumo
{
    config(['envoi.reel_actif' => true, 'envoi.k1_actif' => true, 'envoi.smtp_tolere_transaction_englobante' => true]);

    return new ServeurEnvoiKumo($ws, 'src1', $t, $t, $registre, new class implements CommandeurServeur
    {
        public function commander(string $workspaceId, string $source, string $commandeId, string $commande, array $cibles = []): bool
        {
            return true;
        }
    }, $depot, 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', fn () => new DateTimeImmutable('2026-12-03 10:00:00'));
}

function e5Code(Closure $appel): ?int
{
    try {
        $appel();
    } catch (RefusDefinitifServeur $e) {
        return $e->codeSmtp;
    }

    return -1; // aucun refus levé
}

test('E5 Kumo : le rejeu d\'une clé en echec après un 550 rend le code 550', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [550];
    $s = e5Kumo($t, new RegistreMemoire, $this->depot, $this->ws);
    $m = ($this->msg)('e5k');

    expect(e5Code(fn () => $s->injecter($m)))->toBe(550)
        ->and(e5Code(fn () => $s->injecter($m)))->toBe(550)
        ->and($t->connexions)->toBe(1);
});

test('E5 Kumo : un refus lu par interrogation sans code connu reste sans code (null)', function () {
    $t = new FauxTransportKumo;
    $t->scenario = ['coupure_apres_data'];
    $t->etatDistant = EtatMessage::Refuse;
    $s = e5Kumo($t, new RegistreMemoire, $this->depot, $this->ws);
    $m = ($this->msg)('e5i');
    $s->injecter($m); // a_verifier

    expect(e5Code(fn () => $s->injecter($m)))->toBeNull()
        ->and(e5Code(fn () => $s->injecter($m)))->toBeNull();
});

test('E5 Kumo : un 550 enregistré puis relu par interrogation garde son code', function () {
    $t = new FauxTransportKumo;
    $t->scenario = [550];
    $reg = new RegistreMemoire;
    $s = e5Kumo($t, $reg, $this->depot, $this->ws);
    $m = ($this->msg)('e5r');
    e5Code(fn () => $s->injecter($m));
    $ligne = $reg->lire($this->ws, 'e5r');
    $reg->ecrire($this->ws, 'e5r', ['etat' => 'a_verifier'] + $ligne);
    $t->etatDistant = EtatMessage::Refuse;

    expect(e5Code(fn () => $s->injecter($m)))->toBe(550);
});

test('E5 SMTP : le rejeu d\'une clé en echec après un 550 rend le code 550, sans second envoi', function () {
    config(['envoi.smtp_boucle_tests' => true, 'envoi.smtp_tolere_transaction_englobante' => true]);
    $resolveur = new class implements ResolveurHote
    {
        public function resoudre(string $hote): array
        {
            return [['127.0.0.1']];
        }
    };
    $boite = (new DepotBoites($resolveur))->creer($this->ws, 'from@example.test', '127.0.0.1', 2525, 'aucun', 'utilisateur', 'secret-de-test-e5');
    $envois = new ArrayObject(['n' => 0]);
    $fabrique = fn (BoiteSmtp $b) => new class($envois) implements TransportInterface
    {
        public function __construct(private ArrayObject $envois) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            $this->envois['n']++;
            throw new UnexpectedResponseException('refus', 550);
        }

        public function __toString(): string
        {
            return 'faux://';
        }
    };
    $source = new class implements SourceMessageSigne
    {
        public function brut(MessageAEnvoyer $message): string
        {
            return "From: from@example.test\r\nTo: d@gmail.example.test\r\nSubject: S\r\n\r\nC\r\n";
        }
    };
    $s = new AdaptateurSmtp($this->ws, $boite, $source, new DepotBoites($resolveur), app(DepotSocle::class), $fabrique, $resolveur);
    $m = ($this->msg)('e5s');

    expect(e5Code(fn () => $s->injecter($m)))->toBe(550)
        ->and(e5Code(fn () => $s->injecter($m)))->toBe(550)
        ->and($envois['n'])->toBe(1);
});
