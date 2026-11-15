<?php

/**
 * Envoi L8-ESSAI (D10) — essai à blanc de bout en bout. Données fictives (`*.example.test`), faux serveur, aucune route, aucun envoi
 * réel : tout se passe dans une transaction annulée ; seule la ligne de résultat (`envoi_essais_blanc`, sans adresse) reste.
 */

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Orchestration\EssaiABlanc;
use App\Envoi\Orchestration\RapportEssai;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Alertes\Alerte;
use App\Support\WorkspaceContext;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Tables dont le comptage ne doit pas bouger : toutes les `envoi_*` (sauf le résultat), contacts, personnes, suppressions, oppositions, sortant. */
function essaiComptages(): array
{
    $tables = collect(DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE 'envoi\\_%' AND table_name <> 'envoi_essais_blanc' ORDER BY 1"))
        ->pluck('table_name')->all();
    $tables = array_merge($tables, ['contacts', 'personnes', 'email_suppressions', 'opt_out', 'crm_outbound_events', 'companies']);
    $n = [];
    foreach ($tables as $t) {
        if (Schema::hasTable($t)) {
            $n[$t] = DB::table($t)->count();
        }
    }

    return $n;
}

beforeEach(function () {
    $this->ws = F::espace('zz-essai');
    config([
        'envoi.reel_actif' => false, 'envoi.cle_desinscription' => str_repeat('k', 40), 'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'],
    ]);
});

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

test('le parcours complet passe : toutes les étapes sont vertes et le résultat est écrit sans adresse', function () {
    $rapport = app(EssaiABlanc::class)->executer($this->ws, 'test-essai');

    expect($rapport)->toBeInstanceOf(RapportEssai::class)
        ->and(array_keys($rapport->etapes()))->toBe(EssaiABlanc::ETAPES)
        ->and($rapport->reussi())->toBeTrue()
        ->and(array_unique(array_values($rapport->etapes())))->toBe([true]);
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $this->ws)->first());
    expect($ligne)->not->toBeNull()
        ->and((bool) $ligne->reussi)->toBeTrue()
        ->and($ligne->auteur)->toBe('test-essai')
        ->and((string) $ligne->etapes)->not->toContain('@');
});

test('après l\'essai, aucune ligne nouvelle hors envoi_essais_blanc (STOP, rebond, opposition et sortant compris)', function () {
    $avant = essaiComptages();

    app(EssaiABlanc::class)->executer($this->ws, 'test-essai');

    expect(essaiComptages())->toBe($avant)
        ->and(config('envoi.reel_actif'))->toBeFalse();
});

test('ni ServeurEnvoi du conteneur, ni RecepteurRetoursCrm, ni Alerte ne sont résolus ; aucun courriel, aucune écriture de cache ni fichier temporaire', function () {
    app()->bind(ServeurEnvoi::class, fn () => throw new RuntimeException('ServeurEnvoi du conteneur résolu'));
    app()->bind(RecepteurRetoursCrm::class, fn () => throw new RuntimeException('RecepteurRetoursCrm résolu'));
    app()->bind(Alerte::class, fn () => throw new RuntimeException('Alerte réelle résolue'));
    Mail::fake();
    $ecritures = 0;
    Event::listen(KeyWritten::class, function () use (&$ecritures): void {
        $ecritures++;
    });
    $temp = fn (): array => glob(sys_get_temp_dir() . '/imap-retour-*') ?: [];
    $avantTemp = $temp();

    $rapport = app(EssaiABlanc::class)->executer($this->ws, 'test-essai');

    expect($rapport->reussi())->toBeTrue()
        ->and($ecritures)->toBe(0)
        ->and($temp())->toBe($avantTemp);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('le faux serveur est construit APRÈS l\'ouverture de la transaction, sur un jour fictif lointain', function () {
    $niveauAvant = DB::transactionLevel();
    $vu = null;
    $essai = new EssaiABlanc(function () use (&$vu): FauxServeurEnvoi {
        $vu = DB::transactionLevel();

        return new FauxServeurEnvoi;
    });

    $essai->executer($this->ws, 'test-essai');

    expect($vu)->toBeGreaterThan($niveauAvant)
        ->and(EssaiABlanc::JOUR_FICTIF)->toBeGreaterThan((int) date('Y') + 20);
});

test('une étape rouge : rapport rouge, résultat rouge écrit, code de sortie non nul', function () {
    app()->bind(EssaiABlanc::class, fn () => new EssaiABlanc(fn () => (new FauxServeurEnvoi)->refuserDefinitivement(550)));

    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => $this->ws, '--auteur' => 'test-essai']);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->not->toContain('@');
    $ligne = WorkspaceContext::run($this->ws, fn () => DB::table('envoi_essais_blanc')->where('workspace_id', $this->ws)->first());
    expect((bool) $ligne->reussi)->toBeFalse();
});

test('la commande réussit en code 0, sans adresse en sortie, et aucune route ne l\'expose', function () {
    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => $this->ws, '--auteur' => 'test-essai']);

    expect($code)->toBe(0)->and(Artisan::output())->not->toContain('@');
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toContain('essai');
    }
});

test('un espace inconnu : échec propre, aucun résultat écrit', function () {
    $code = Artisan::call('envoi:essai-a-blanc', ['espace' => '00000000-0000-4000-8000-000000000000']);

    expect($code)->not->toBe(0)->and(DB::table('envoi_essais_blanc')->count())->toBe(0);
});
