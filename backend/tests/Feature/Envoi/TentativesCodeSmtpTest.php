<?php

use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Depot\DepotTentatives;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

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

test('témoin de la garde : le même scénario en échec (sans code) écrit une tentative, la garde peut donc rougir', function () {
    // Seule la NATURE du report (attente ou échec) décide : l'attente ne note rien, l'échec note `reportee`.
    $faux = (new FauxServeurEnvoi)->reporterEchec(null);
    ($this->passerelle)($faux)->injecter(($this->msg)('k5'));

    expect(($this->lignes)())->toBe([['reportee', null]]);
});
