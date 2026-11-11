<?php

use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\LecteurReports;
use App\Envoi\Chauffe\LecteurReportsDb;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Chauffe\Seuils;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Depot\DepotTentatives;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\PasserelleLocale;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\Support\ServeurEspion;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Lot R06 — source réelle du frein « reports en hausse » : table `envoi_tentatives`, écrite à la conclusion (transaction C). */
beforeEach(function () {
    $this->ws = F::espace('zz-r06');
    $this->maintenant = new DateTimeImmutable('2026-12-03 10:00:00', new DateTimeZone('Europe/Paris'));
    $this->depot = new DepotSocleDb;
    $this->depot->eteindreTemoins($this->ws, 'test-r06');
    WorkspaceContext::set($this->ws);
    $this->resolveur = new ResolveurEnMemoire(['gmail.example.test' => 'gmail', 'outlook.example.test' => 'microsoft']);
    $this->passerelle = fn ($serveur): PasserelleLocale => new PasserelleLocale(
        $serveur,
        $this->depot,
        true,
        300,
        fn () => $this->maintenant,
        $this->ws,
        300,
        fn () => WorkspaceContext::current(),
        null,
        new DepotTentatives,
        $this->resolveur,
    );
    $this->msg = fn (string $cle, string $domaine = 'gmail.example.test'): MessageAEnvoyer => new MessageAEnvoyer($this->ws, "d@{$domaine}", 'from@example.test', 'S', 'C', $cle);
    $this->lecteur = fn (string $f = 'gmail'): array => (new LecteurReportsDb)->sur1Heure($this->ws, $f, $this->maintenant);
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('envois acceptés et reports sont comptés par fournisseur sur l\'heure glissante', function () {
    $acc = ($this->passerelle)(new ServeurEspion(fn () => ResultatInjection::accepte('id')));
    $rep = ($this->passerelle)(new ServeurEspion(fn () => ResultatInjection::reporteEchec(new DateTimeImmutable('2026-12-04'))));
    for ($i = 0; $i < 3; $i++) {
        $acc->injecter(($this->msg)("a$i"));
    }
    $rep->injecter(($this->msg)('r1'));

    expect(($this->lecteur)())->toBe(['envoyes' => 4, 'reports' => 1]);
});

test('un report vers un autre fournisseur ne compte pas', function () {
    $rep = ($this->passerelle)(new ServeurEspion(fn () => ResultatInjection::reporteEchec(new DateTimeImmutable('2026-12-04'))));
    $rep->injecter(($this->msg)('r1', 'outlook.example.test'));

    expect(($this->lecteur)('gmail'))->toBe(['envoyes' => 0, 'reports' => 0])
        ->and(($this->lecteur)('microsoft'))->toBe(['envoyes' => 1, 'reports' => 1]);
});

test('rejeu de la même conclusion = une seule ligne (clé unique)', function () {
    $depot = new DepotTentatives;
    $depot->noter($this->ws, 'gmail', 'acceptee', 'cle-1', $this->maintenant);
    $depot->noter($this->ws, 'gmail', 'reportee', 'cle-1', $this->maintenant);

    expect(DB::table('envoi_tentatives')->where('workspace_id', $this->ws)->count())->toBe(1)
        ->and(($this->lecteur)())->toBe(['envoyes' => 1, 'reports' => 0]);
});

test('hors de l\'heure glissante, rien ne compte', function () {
    $depot = new DepotTentatives;
    $depot->noter($this->ws, 'gmail', 'reportee', 'vieille', $this->maintenant->modify('-61 minutes'));
    $depot->noter($this->ws, 'gmail', 'reportee', 'future', $this->maintenant->modify('+5 minutes'));
    $depot->noter($this->ws, 'gmail', 'reportee', 'bonne', $this->maintenant->modify('-59 minutes'));

    expect(($this->lecteur)())->toBe(['envoyes' => 1, 'reports' => 1]);
});

test('annulation de la transaction de conclusion = aucune tentative comptée', function () {
    $serveur = new ServeurEspion(fn () => ResultatInjection::reporteEchec(new DateTimeImmutable('2026-12-04')));
    $p = ($this->passerelle)($serveur);
    try {
        DB::transaction(function () {
            $this->depot->sousVerrouInjection($this->maintenant, function () {
                (new DepotTentatives)->noter($this->ws, 'gmail', 'reportee', 'annulee', $this->maintenant);
                throw new RuntimeException('annulation');
            });
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('envoi_tentatives')->count())->toBe(0);
});

test('une issue incertaine ou un refus local ne crée aucune tentative', function () {
    $p = ($this->passerelle)(new ServeurEspion(fn () => ResultatInjection::incertain(new DateTimeImmutable('2026-12-04'))));
    $p->injecter(($this->msg)('inc'));
    $p2 = ($this->passerelle)(new ServeurEspion(fn () => throw new EnvoiRefuse('local')));
    try {
        $p2->injecter(($this->msg)('loc'));
    } catch (Throwable) {
    }

    expect(DB::table('envoi_tentatives')->count())->toBe(0);
});

test('le frein se déclenche au-dessus du taux max dès l\'échantillon minimum atteint, pas avant', function () {
    $depot = new DepotTentatives;
    $garde = new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null, new LecteurReportsDb);
    $pauses = fn () => WorkspaceContext::run($this->ws, fn () => DB::table('envoi_pauses')->where('workspace_id', $this->ws)
        ->where('motif', DecisionFrein::REPORTS_EN_HAUSSE)->whereNull('levee_le')->count());

    // Sous l'échantillon minimum : 3 reports sur (MIN - 1) envois => rien.
    for ($i = 0; $i < Seuils::REPORTS_ENVOIS_MIN - 1; $i++) {
        $depot->noter($this->ws, 'gmail', $i < 3 ? 'reportee' : 'acceptee', "c$i", $this->maintenant->modify('-10 minutes'));
    }
    $garde->evaluer($this->ws, $this->maintenant);
    expect($pauses())->toBe(0);

    // L'échantillon minimum est atteint : 3 / 10 = 30 % > 20 % => pause de gmail.
    $depot->noter($this->ws, 'gmail', 'acceptee', 'cmin', $this->maintenant->modify('-5 minutes'));
    $garde->evaluer($this->ws, $this->maintenant);
    expect($pauses())->toBe(1);
});

test('la table est en ajout seul, cloisonnée par espace, et la liaison par défaut reste prudente', function () {
    $autre = F::espace('zz-r06b');
    (new DepotTentatives)->noter($this->ws, 'gmail', 'reportee', 'x', $this->maintenant);
    expect((new LecteurReportsDb)->sur1Heure($autre, 'gmail', $this->maintenant))->toBe(['envoyes' => 0, 'reports' => 0])
        ->and(app(LecteurReports::class))->toBeInstanceOf(LecteurReportsDb::class);
    expect(fn () => DB::statement('UPDATE envoi_tentatives SET issue = \'acceptee\''))->toThrow(QueryException::class);
});
