<?php

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\PasserelleLocale;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
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
    $ancien = $this->jour->modify('-2 hours')->format('c'); // au-delà du délai de 60 minutes
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

test('B2 : un report marqué INCERTAIN (sans exception) garde la place comptée ; le plafond N n\'est jamais dépassé ; alerte', function () {
    Log::spy();
    $serveur = new ServeurEspion(fn ($m) => ResultatInjection::incertain(new DateTimeImmutable('2026-11-18')));
    $p = ($this->passerelle)($serveur, plafond: 2);
    expect($p->injecter(($this->msg)('k1'))->accepte)->toBeFalse();
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBe('a_verifier')
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(1)
        ->and(($this->compteur)())->toBe(1);
    Log::shouldHaveReceived('critical')->once();
    $p->injecter(($this->msg)('k2'));
    // Plafond 2 atteint par deux incertains : un troisième message n'atteint JAMAIS le serveur.
    expect($p->injecter(($this->msg)('k3'))->accepte)->toBeFalse()->and($serveur->appels)->toBe(2)
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(2);
});

test('B2 : refus CERTAIN (report net, EnvoiRefuse, 5xx définitif) libère la place, sans alerte « incertaine »', function () {
    Log::spy();
    $cas = [
        fn () => ResultatInjection::reporte(new DateTimeImmutable('2026-11-18')),
        fn () => throw EnvoiRefuse::toutArreter(),
        fn () => throw new RefusDefinitifServeur('5xx définitif'),
    ];
    foreach ($cas as $i => $comportement) {
        $p = ($this->passerelle)(new ServeurEspion($comportement), plafond: 1);
        try {
            $p->injecter(($this->msg)('k' . $i));
        } catch (EnvoiRefuse|RefusDefinitifServeur) {
        }
        expect($this->depot->etatInjection($this->ws, 'k' . $i))->toBeNull()
            ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(0)->and(($this->compteur)())->toBe(0);
    }
    Log::shouldNotHaveReceived('critical');
});

test('mineur 3 : acceptation TARDIVE (réservation périmée pendant l\'appel) => journal « acceptee » par confirmation, la place ne se libère pas', function () {
    $serveur = new ServeurEspion(function ($m) {
        $this->depot->perimerReservations($this->ws, new DateTimeImmutable('2030-01-01')); // la réservation vieillit pendant l'appel
        expect($this->depot->etatInjection($this->ws, 'k1'))->toBe('a_verifier');

        return ResultatInjection::accepte('id-1');
    });
    expect(($this->passerelle)($serveur)->injecter(($this->msg)())->accepte)->toBeTrue();
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBe('acceptee')->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(1);
});

test('mineur 1 : le compteur de tentatives libérées compte les clés EXACTES « clé » et « clé~n », pas toute clé qui commence par « clé~ »', function () {
    $serveur = new ServeurEspion(fn () => throw EnvoiRefuse::toutArreter());
    $p = ($this->passerelle)($serveur, plafond: 5);
    foreach (['k1~abc', 'k1~'] as $voisine) { // clés voisines libérées : ne doivent pas décaler k1
        try {
            $p->injecter(($this->msg)($voisine));
        } catch (EnvoiRefuse) {
        }
    }
    $ok = ($this->passerelle)(new ServeurEspion(fn () => ResultatInjection::accepte('id')), plafond: 5);
    $ok->injecter(($this->msg)('k1'));
    expect(DB::table('envoi_journal_commandes')->where('workspace_id', $this->ws)->where('commande', 'injection')->where('cle_idempotence', 'k1')->count())->toBe(1)
        ->and(DB::table('envoi_journal_commandes')->where('workspace_id', $this->ws)->where('cle_idempotence', 'k1~2')->count())->toBe(0);
    // Et la vraie suite ~n fonctionne : k2 libérée une fois repart en « k2~2 », libérée deux fois en « k2~3 ».
    foreach ([1, 2] as $_) {
        try {
            $p->injecter(($this->msg)('k2'));
        } catch (EnvoiRefuse) {
        }
    }
    expect(DB::table('envoi_journal_commandes')->where('workspace_id', $this->ws)->where('commande', 'injection')->whereIn('cle_idempotence', ['k2', 'k2~2'])->count())->toBe(2);
});

test('mineur 5 : l\'alerte d\'une réservation périmée part APRÈS le COMMIT de la transaction A, pas dedans', function () {
    $base = DB::transactionLevel();
    $niveau = null;
    Log::shouldReceive('critical')->once()->andReturnUsing(function () use (&$niveau) {
        $niveau = DB::transactionLevel();
    });
    DB::table('envoi_journal_commandes')->insert([
        'workspace_id' => $this->ws, 'commande' => 'injection', 'auteur' => 'passerelle', 'jour' => '2026-11-17',
        'cle_idempotence' => 'vieille', 'statut' => 'reservee', 'cree_le' => $this->jour->modify('-3 hours')->format('c'),
    ]);
    $p = ($this->passerelle)(new ServeurEspion(fn () => ResultatInjection::accepte('id-1')));
    $p->injecter(($this->msg)('k1'));
    expect($niveau)->toBe($base);
});

test('mineur 4 : envoi:perimer-reservations balaie TOUS les espaces (a_verifier, compte toujours) et n\'est PAS planifiée', function () {
    $autre = F::espace('zz-ric-b');
    foreach ([$this->ws, $autre] as $ws) {
        WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->insert([
            'workspace_id' => $ws, 'commande' => 'injection', 'auteur' => 'passerelle', 'jour' => '2026-11-17',
            'cle_idempotence' => 'k-old', 'statut' => 'reservee', 'cree_le' => now()->subHours(3)->format('c'),
        ]));
    }
    Log::spy();
    $this->artisan('envoi:perimer-reservations')->assertExitCode(0);
    foreach ([$this->ws, $autre] as $ws) {
        expect($this->depot->etatInjection($ws, 'k-old'))->toBe('a_verifier');
    }
    Log::shouldHaveReceived('critical')->twice();
    expect(file_get_contents(base_path('routes/console.php')))->not->toContain('perimer-reservations');
});

test('B3 : la passerelle appelle le serveur au niveau de BASE (ServeurEspion::niveaux est lu)', function () {
    $base = DB::transactionLevel();
    $serveur = new ServeurEspion(fn () => ResultatInjection::accepte('id-1'));
    ($this->passerelle)($serveur)->injecter(($this->msg)());
    expect($serveur->niveaux)->toBe([$base]); // ROUGE si l'appel est remis dans la transaction A ou C
});

test('B3 : le simulateur refuse d\'injecter dans une transaction englobante (niveau de base de la suite, pas 0) ; réglage explicite possible', function () {
    $faux = new FauxServeurEnvoi; // construit au niveau de base de la suite (RefreshDatabase : déjà ≥ 1)
    expect($faux->injecter(($this->msg)())->accepte)->toBeTrue();
    expect(fn () => DB::transaction(fn () => $faux->injecter(($this->msg)('k2'))))->toThrow(ServeurEnvoiRefuse::class, 'transaction englobante');
    expect($faux->appels)->toBe(1); // le refus a lieu AVANT tout effet
    // Réglage de suite : base imposée à 0 — le niveau de RefreshDatabase (≥ 1) est alors déjà « englobant ».
    expect(fn () => (new FauxServeurEnvoi(true, 0))->injecter(($this->msg)('k3')))->toThrow(ServeurEnvoiRefuse::class);
    // La passerelle (appel hors transaction) devant le simulateur fonctionne.
    expect(($this->passerelle)($faux)->injecter(($this->msg)('k4'))->accepte)->toBeTrue();
    // Et la même passerelle ROUGIT si un décorateur enveloppe injecter() dans DB::transaction.
    $enveloppe = new ServeurEspion(fn ($m) => DB::transaction(fn () => $faux->injecter($m)));
    expect(fn () => ($this->passerelle)($enveloppe)->injecter(($this->msg)('k5')))->toThrow(ServeurEnvoiRefuse::class);
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

test('la libération ne décrémente le compteur global qu\'UNE fois par réservation, et jamais sans réservation', function () {
    $this->depot->reserverInjection($this->ws, $this->jour, 'k1', $this->jour);
    $this->depot->reserverInjection($this->ws, $this->jour, 'k2', $this->jour);
    expect(($this->compteur)())->toBe(2);
    expect($this->depot->concluireInjection($this->ws, $this->jour, 'jamais', 'liberee'))->toBeFalse();
    expect($this->depot->concluireInjection($this->ws, $this->jour, 'k1', 'liberee'))->toBeTrue()
        ->and(($this->compteur)())->toBe(1);
    // Appel direct répété de la fonction SQL : la conclusion existe déjà, rien ne se décrémente de nouveau.
    $cle = 'k1';
    expect((bool) DB::selectOne('SELECT envoi_injection_liberer(?::uuid, ?) AS ok', [$this->ws, $cle])->ok)->toBeFalse();
    expect(($this->compteur)())->toBe(1);
});

test('rejeu d\'une clé « acceptee » : le serveur n\'est PAS rappelé, l\'identifiant conservé est rendu', function () {
    $serveur = new ServeurEspion(fn () => ResultatInjection::accepte('id-conserve'));
    $p = ($this->passerelle)($serveur);
    expect($p->injecter(($this->msg)())->idInjection)->toBe('id-conserve');
    expect($p->injecter(($this->msg)())->idInjection)->toBe('id-conserve')
        ->and($serveur->appels)->toBe(1);
});

test('rejeu depuis « reservee » avec un serveur dédoublonnant : le renvoi accepté conclut en acceptee', function () {
    $this->depot->reserverInjection($this->ws, $this->jour, 'k1', $this->jour);
    $serveur = new ServeurEspionDedoublonneur(fn () => ResultatInjection::accepte('id-1'));
    expect(($this->passerelle)($serveur)->injecter(($this->msg)())->accepte)->toBeTrue();
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBe('acceptee');
});

test('rejeu depuis « reservee » avec un serveur dédoublonnant : un refus DÉFINITIF (5xx) libère la place, une seule fois', function () {
    $this->depot->reserverInjection($this->ws, $this->jour, 'k1', $this->jour);
    $serveur = new ServeurEspionDedoublonneur(fn () => throw new RefusDefinitifServeur('5xx'));
    $p = ($this->passerelle)($serveur);
    expect(fn () => $p->injecter(($this->msg)()))->toThrow(RefusDefinitifServeur::class);
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBeNull() // libérée : plus de réservation vivante
        ->and($this->depot->injectesLe($this->ws, $this->jour))->toBe(0)
        ->and(DB::table('envoi_journal_commandes')->where('workspace_id', $this->ws)->where('commande', 'injection_issue')->where('statut', 'liberee')->count())->toBe(1);
});

test('rejeu depuis « a_verifier » : un refus définitif ne libère PAS la place (l\'envoi précédent est peut-être parti)', function () {
    $this->depot->reserverInjection($this->ws, $this->jour, 'k1', $this->jour);
    $this->depot->concluireInjection($this->ws, $this->jour, 'k1', 'a_verifier', null);
    $serveur = new ServeurEspionDedoublonneur(fn () => throw new RefusDefinitifServeur('5xx'));
    expect(fn () => ($this->passerelle)($serveur)->injecter(($this->msg)()))->toThrow(RefusDefinitifServeur::class);
    expect($this->depot->etatInjection($this->ws, 'k1'))->toBe('a_verifier');
});

test('la libération refuse une clé d\'un AUTRE espace que le contexte courant', function () {
    $autre = F::espace('zz-ric-c');
    $this->depot->reserverInjection($autre, $this->jour, 'k9', $this->jour);
    // Le contexte courant est $this->ws : la fonction (SECURITY DEFINER) ne doit rien libérer pour $autre.
    expect((bool) DB::selectOne('SELECT envoi_injection_liberer(?::uuid, ?) AS ok', [$autre, 'k9'])->ok)->toBeFalse();
    expect($this->depot->etatInjection($autre, 'k9'))->toBe('reservee');
});

test('ligne « acceptee » sans identifiant en SMTP direct : le serveur n\'est JAMAIS rappelé', function () {
    DB::table('envoi_journal_commandes')->insert([
        'workspace_id' => $this->ws, 'commande' => 'injection', 'auteur' => 'passerelle', 'jour' => '2026-11-17',
        'cle_idempotence' => 'k1', 'statut' => 'acceptee', 'cree_le' => $this->jour->format('c'),
    ]);
    $serveur = new ServeurEspion(fn () => ResultatInjection::accepte('autre'));
    expect(($this->passerelle)($serveur)->injecter(($this->msg)())->accepte)->toBeTrue()
        ->and($serveur->appels)->toBe(0);
});
