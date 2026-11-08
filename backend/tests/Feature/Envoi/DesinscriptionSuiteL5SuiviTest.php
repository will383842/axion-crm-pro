<?php

/**
 * Envoi P0 L5 (suivi) — relance des effets d'opposition : journal « espace supprimé » limité à un par jour,
 * oppositions en attente de leur délai exclues du lot, état des tentatives en base (survit à `cache:clear`),
 * filtre d'espace explicite, garde de la clé en production, hôte des liens fixé par la configuration.
 * Fixtures FICTIVES (dépôt public). Aucun envoi réel.
 */

use App\Envoi\Desinscription\EnregistrerOpposition;
use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Providers\EnvoiServiceProvider;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
    config(['envoi.cle_desinscription' => 'cle-de-test-seulement-0123456789', 'envoi.desinscription_validite_jours' => 30]);
    $this->ws = F::espace('zz-suivi');
});

afterEach(fn () => Carbon::setTestNow());

/** Retrait en panne : chaque appel compte puis échoue (l'opposition reste à relancer). */
function suiviRetraitEnPanne(): object
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

/** Insère une opposition inachevée (sans passer par le contrôleur) ; renvoie son id. */
function suiviOpposition(string $espace, string $adresse): int
{
    $reference = InscriptionsEnvoi::creer($espace, $adresse);

    return WorkspaceContext::run($espace, function () use ($espace, $reference): int {
        $lien = DB::table('envoi_liens_desinscription')->where('reference', $reference)->first();

        return (int) DB::table('envoi_oppositions')->insertGetId([
            'workspace_id' => $espace, 'lien_id' => $lien->id, 'empreinte_adresse' => $lien->empreinte_adresse, 'canal' => 'page',
        ]);
    });
}

test('(a) espace supprimé : le journal n’est pas réémis chaque minute (un par jour et par espace)', function () {
    Log::spy();
    suiviOpposition($this->ws, 'a@zz-suivi.example.invalid');
    DB::table('workspaces')->where('id', $this->ws)->update(['deleted_at' => now()]);

    for ($i = 0; $i < 5; $i++) {
        $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
        Carbon::setTestNow(Carbon::now()->addMinute());
    }
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'espace supprimé'))->once();

    // Le lendemain : de nouveau UN journal (l'opposition reste visible, jamais ignorée).
    Carbon::setTestNow(Carbon::now()->addDay());
    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains((string) $m, 'espace supprimé'))->twice();
});

test('(b) les oppositions en attente de leur délai ne mangent pas le lot de 500', function () {
    $retrait = suiviRetraitEnPanne();
    $adresse = fn (int $i) => "b{$i}@zz-suivi.example.invalid";
    // 500 oppositions qui échoueront, puis une 501e jamais atteinte tant que les 500 occupent le lot.
    $lots = [];
    for ($i = 0; $i < 501; $i++) {
        $lots[] = InscriptionsEnvoi::creer($this->ws, $adresse($i));
    }
    WorkspaceContext::run($this->ws, function () use ($lots): void {
        $liens = DB::table('envoi_liens_desinscription')->whereIn('reference', $lots)->orderBy('id')->get();
        foreach ($liens->chunk(100) as $paquet) {
            DB::table('envoi_oppositions')->insert($paquet->map(fn ($l) => [
                'workspace_id' => $this->ws, 'lien_id' => $l->id, 'empreinte_adresse' => $l->empreinte_adresse, 'canal' => 'page',
            ])->all());
        }
    });

    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(500);                  // 1er passage : le lot, en échec => délai de 2 min posé

    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(501);                  // 2e passage (délais non échus) : la 501e est enfin prise
});

test('(c) l’état des tentatives survit à un cache:clear (stocké en base)', function () {
    $retrait = suiviRetraitEnPanne();
    suiviOpposition($this->ws, 'c@zz-suivi.example.invalid');

    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(1);

    Cache::flush();                                   // ce que fait `cache:clear` au déploiement

    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(1);                     // toujours dans son délai de 2 min : pas de nouvel essai

    Carbon::setTestNow(Carbon::now()->addMinutes(3));
    $this->artisan('envoi:relancer-effets-opposition')->assertSuccessful();
    expect($retrait->n)->toBe(2);
});

test('(d) dejaEnregistree et executer ne voient jamais l’opposition d’un autre espace', function () {
    $autre = F::espace('zz-suivi-autre');
    $reference = InscriptionsEnvoi::creer($this->ws, 'd@zz-suivi.example.invalid');
    $opposition = app(EnregistrerOpposition::class);
    expect($opposition->executer($this->ws, $reference, EnregistrerOpposition::CANAL_PAGE))->toBeTrue();

    expect($opposition->dejaEnregistree($this->ws, $reference))->toBeTrue();
    expect($opposition->dejaEnregistree($autre, $reference))->toBeFalse();
    expect($opposition->executer($autre, $reference, EnregistrerOpposition::CANAL_PAGE))->toBeFalse();
});

test('(e) production : démarrage refusé si la clé de signature des liens fait moins de 32 caractères', function () {
    config(['envoi.cle_desinscription' => str_repeat('k', 31)]);
    $this->app['env'] = 'production';
    expect(fn () => (new EnvoiServiceProvider($this->app))->boot())->toThrow(RuntimeException::class, '32');

    config(['envoi.cle_desinscription' => '']);
    expect(fn () => (new EnvoiServiceProvider($this->app))->boot())->toThrow(RuntimeException::class);

    config(['envoi.cle_desinscription' => str_repeat('k', 32)]);
    (new EnvoiServiceProvider($this->app))->boot();   // 32 caractères : accepté
    expect(true)->toBeTrue();
});

test('(e) la garde ne bloque jamais les tests : environnement testing exclu', function () {
    config(['envoi.cle_desinscription' => 'courte']);
    expect($this->app->environment())->toBe('testing');
    (new EnvoiServiceProvider($this->app))->boot();
    expect(true)->toBeTrue();
});

test('(f) l’hôte du lien vient de la configuration, jamais de la requête', function () {
    config(['envoi.desinscription_hote' => 'api', 'envoi.desinscription_hotes' => ['app' => 'https://app.zz-suivi.example.invalid', 'api' => 'https://api.zz-suivi.example.invalid']]);
    $ref = InscriptionsEnvoi::creer($this->ws, 'f@zz-suivi.example.invalid');

    $this->get('http://hote-pirate.example.invalid/up');   // une requête précédente avec un autre hôte
    $url = JetonDesinscription::url($this->ws, $ref);
    expect($url)->toStartWith('https://api.zz-suivi.example.invalid/desinscription/v1.')->not->toContain('hote-pirate');

    config(['envoi.desinscription_hote' => 'app']);
    expect(JetonDesinscription::url($this->ws, $ref))->toStartWith('https://app.zz-suivi.example.invalid/desinscription/v1.');
});

test('(f) hôte non configuré ou invalide : aucun lien émis (jamais déduit de la requête)', function () {
    $ref = InscriptionsEnvoi::creer($this->ws, 'g@zz-suivi.example.invalid');
    config(['envoi.desinscription_hote' => 'app', 'envoi.desinscription_hotes' => ['app' => '', 'api' => '']]);
    expect(fn () => JetonDesinscription::url($this->ws, $ref))->toThrow(RuntimeException::class);

    config(['envoi.desinscription_hotes' => ['app' => 'pas-une-url', 'api' => '']]);
    expect(fn () => JetonDesinscription::url($this->ws, $ref))->toThrow(RuntimeException::class);

    config(['envoi.desinscription_hote' => 'ailleurs', 'envoi.desinscription_hotes' => ['app' => 'https://app.zz-suivi.example.invalid']]);
    expect(fn () => JetonDesinscription::url($this->ws, $ref))->toThrow(RuntimeException::class);
});
