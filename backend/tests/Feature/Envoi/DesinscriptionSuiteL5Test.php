<?php

/**
 * Envoi P0 L5 (suite, audits #443) — clé minimale, limite par jeton du POST, repli de
 * la liste de suppression, relance des effets. Fixtures FICTIVES (dépôt public).
 */

use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const SUITE_ADRESSE = 'suite@zz-desinscription.example.invalid';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
    config(['envoi.cle_desinscription' => 'cle-de-test-seulement-0123456789', 'envoi.desinscription_validite_jours' => 30]);
    $this->ws = F::espace('zz-suite');
    $this->ref = InscriptionsEnvoi::creer($this->ws, SUITE_ADRESSE);
    $this->chemin = '/desinscription/' . JetonDesinscription::emettre($this->ws, $this->ref);
});

afterEach(fn () => Carbon::setTestNow());

function suiteRetraitEnPanne(): object
{
    $retrait = new class implements RetraitEnvoisEnFile
    {
        public int $n = 0;

        public function retirerPour(string $workspaceId, string $empreinteAdresse): int
        {
            $this->n++;
            throw new RuntimeException('panne simulée');
        }
    };
    app()->instance(RetraitEnvoisEnFile::class, $retrait);

    return $retrait;
}

test('clé trop courte : aucun jeton émis (32 caractères au moins), 32 acceptés', function () {
    config(['envoi.cle_desinscription' => str_repeat('k', 31)]);
    expect(fn () => JetonDesinscription::emettre($this->ws, $this->ref))->toThrow(RuntimeException::class, '32');
    config(['envoi.cle_desinscription' => str_repeat('k', 32)]);
    expect(JetonDesinscription::emettre($this->ws, $this->ref))->toStartWith('v1.');
});

test('POST : limite large par jeton (30/min) qui ne renvoie jamais 429 et ne refait aucune écriture', function () {
    $this->post($this->chemin)->assertOk();
    $cle = 'desinscription-post:' . hash('sha256', basename($this->chemin));
    for ($i = 0; $i < 40; $i++) {
        $this->post($this->chemin, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    }
    expect(RateLimiter::tooManyAttempts($cle, 30))->toBeTrue();

    // Limite atteinte ET opposition déjà acquise : réponse identique, sans la moindre écriture.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->post($this->chemin)->assertOk();
    $ecritures = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query']) === 1);
    expect($ecritures)->toHaveCount(0);
});

test('POST : limite atteinte mais opposition PAS ENCORE enregistrée : elle est enregistrée (droit d’opposition)', function () {
    $cle = 'desinscription-post:' . hash('sha256', basename($this->chemin));
    for ($i = 0; $i < 100; $i++) {
        RateLimiter::hit($cle, 60);
    }
    $this->post($this->chemin)->assertOk()->assertSee('Désinscription confirmée', false);
    expect(ListeSuppression::estSupprimee(SUITE_ADRESSE))->toBeTrue();
});

test('ListeSuppression : si l’insertion simultanée d’un autre processus fait échouer l’insertion (0), repli sur l’incrément', function () {
    $h = ListeSuppression::empreinte('repli@zz-desinscription.example.invalid');
    $fait = false;
    DB::beforeExecuting(function ($requete, $liaisons, $connexion) use (&$fait, $h) {
        if (! $fait && stripos($requete, 'insert') === 0 && stripos($requete, 'email_suppressions') !== false) {
            $fait = true; // l'« autre processus » insère entre la lecture et l'écriture
            $connexion->table('email_suppressions')->insert([
                'scope' => 'business', 'email' => null, 'email_hash' => $h, 'reason' => 'manual', 'source' => 'autre',
                'occurrences' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }
    });

    ListeSuppression::inscrireEmpreinte($h, ListeSuppression::MANUEL, 'desinscription_lien', 'business');

    expect($fait)->toBeTrue();
    expect(DB::table('email_suppressions')->where('email_hash', $h)->count())->toBe(1);
    expect((int) DB::table('email_suppressions')->where('email_hash', $h)->value('occurrences'))->toBe(2);
});

test('relance : le délai entre deux tentatives d’une opposition en échec double (2, 4, 8 min…)', function () {
    $retrait = suiteRetraitEnPanne();
    $this->post($this->chemin)->assertOk();
    expect($retrait->n)->toBe(1);

    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(2);                    // 1re relance : immédiate

    Carbon::setTestNow(Carbon::now()->addMinute());
    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(2);                    // trop tôt (délai 2 min)

    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(3);                    // délai écoulé

    Carbon::setTestNow(Carbon::now()->addMinutes(3));
    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(3);                    // le délai est passé à 4 min

    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(4);
});

test('relance : alerte critique (une seule fois) après 10 échecs, et on continue d’essayer', function () {
    Log::spy();
    $retrait = suiteRetraitEnPanne();
    $this->post($this->chemin)->assertOk();
    for ($i = 0; $i < 14; $i++) {
        $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
        Carbon::setTestNow(Carbon::now()->addHours(2));
    }
    Log::shouldHaveReceived('critical')->once();
    expect($retrait->n)->toBeGreaterThan(12);        // jamais abandonnée : c'est un droit
});

test('relance : une opposition d’un espace supprimé est journalisée, pas ignorée en silence', function () {
    Log::spy();
    WorkspaceContext::run($this->ws, function () {
        $lien = DB::table('envoi_liens_desinscription')->where('reference', $this->ref)->first();
        DB::table('envoi_oppositions')->insert(['workspace_id' => $this->ws, 'lien_id' => $lien->id, 'empreinte_adresse' => $lien->empreinte_adresse, 'canal' => 'page']);
    });
    DB::table('workspaces')->where('id', $this->ws)->update(['deleted_at' => now()]);

    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();

    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'espace supprimé'))->once();
});
