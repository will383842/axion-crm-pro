<?php

/**
 * GARDES C18-008 et C18-007 — « l'arret d'urgence qui n'arrete rien ».
 *
 * Deux constats mesures sur ce depot le 2026-08-20 :
 *
 *  C18-008 (S1) « l'arret d'urgence n'arrete pas la collecte ».
 *      `ScraperRunsController::cancel()` et `ScrapingCampaignsController::cancel()`
 *      ecrivent un drapeau Redis `cancelled:scraper-run:{id}` (TTL 3600) et un
 *      statut en base. Recherche exhaustive dans le depot :
 *          grep -rn "cancelled:scraper-run" backend/ frontend/
 *      → 2 ECRITURES (les deux controleurs), 1 mention en commentaire
 *        (`ScraperRunCancelled.php:15` : « Les workers verifient egalement le
 *        flag Redis »), et ZERO LECTURE. Le commentaire decrit un lecteur qui
 *        n'existe pas.
 *      Cote file : `LaunchCampaignJob` pousse 1 `LaunchZoneScrapingJob` par
 *      (zone x source) avec un decalage de `ceil(60/rpm)` s chacun — a 20 rpm
 *      et 2 zones x 2 sources, le dernier part 9 s plus tard, mais rien
 *      n'empeche un decalage de plusieurs minutes sur une grosse campagne.
 *      Aucun de ces jobs ne demandait a la campagne si elle tournait encore :
 *      « annuler » n'ecrivait qu'un statut, la collecte continuait.
 *
 *  C18-007 (S1) « le quota max_companies ne freine rien ».
 *      `MonitorCampaignProgressJob:76` recompte
 *          COUNT(DISTINCT company_id) FILTER (WHERE company_id IS NOT NULL)
 *      sur `scraper_runs`, puis `:94` ECRASE `companies_created` avec ce
 *      resultat. Or `LaunchZoneScrapingJob:55-69` cree son run SANS
 *      `company_id` (colonne nullable, verifie sur `\d scraper_runs`) : le
 *      recompte vaut 0 pour toute campagne de decouverte. Le moniteur se
 *      re-dispatche toutes les 60 s → le compteur de quota est remis a zero
 *      chaque minute, et `shouldAutoPause()` ne declenche jamais.
 *      C'est la MEME maladie que celle deja documentee dans la requete du
 *      moniteur a propos du `GREATEST(0, …)` sur les durees : « un compteur de
 *      quota qui decroit, donc un plafond qui se relache tout seul ».
 *
 * TEMOINS : chaque garde est doublee d'un temoin qui prouve qu'elle ne passe
 * pas au vert sur du vide — la meme mecanique SANS l'arret / SANS le plafond
 * doit produire les entreprises attendues.
 */

use App\Contracts\InseeClient;
use App\Data\Sources\InseeCompanyData;
use App\Jobs\LaunchZoneScrapingJob;
use App\Jobs\MonitorCampaignProgressJob;
use App\Models\Company;
use App\Models\ScraperRun;
use App\Models\ScrapingCampaign;
use App\Models\User;
use App\Models\Workspace;
use App\Services\FranceTravail\FranceTravailDiscoveryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Fabriques
// ---------------------------------------------------------------------------

function arretMakeUser(string $slug = 'arr'): array
{
    $workspace = Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => $slug . '-' . Str::random(6),
        'name' => 'WS ' . $slug,
    ]);
    $user = User::create([
        'id'                       => (string) Str::uuid(),
        'email'                    => $slug . Str::random(4) . '@test.local',
        'name'                     => 'User ' . $slug,
        'password_hash'            => Hash::make('SomePass!1234'),
        'current_workspace_id'     => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    return [$user, $workspace];
}

function arretMakeCampagne(User $u, Workspace $w, array $extra = []): ScrapingCampaign
{
    // ⚠️ On renseigne TOUTES les entrees lues par `shouldAutoPause()` :
    // `ScrapingCampaign::create()` ne renvoie que les attributs fournis, et
    // `0 >= null` vaut `true` en PHP (piege deja documente dans CampaignsTest).
    return ScrapingCampaign::create(array_merge([
        'workspace_id'          => $w->id,
        'created_by'            => $u->id,
        'name'                  => 'Campagne arret',
        'status'                => 'running',
        'sources'               => ['insee'],
        'zones'                 => [['type' => 'department', 'code' => '75']],
        'max_companies'         => 1000,
        'companies_created'     => 0,
        'max_duration_minutes'  => 180,
        'runs_total'            => 4,
        'runs_completed'        => 0,
        'started_at'            => now(),
    ], $extra));
}

/**
 * Faux client INSEE : rend exactement $nb entreprises distinctes.
 *
 * `MockInseeClient::searchByCriteria()` rend `[]` — un faux qui rend du vide
 * ferait passer au vert n'importe quelle garde « rien n'a ete cree ». D'ou ce
 * faux-ci, qui rend de la matiere, et le temoin qui verifie qu'elle arrive
 * bien jusqu'en base quand rien ne l'arrete.
 *
 * $avantRendu : crochet joue AVANT de rendre la liste — sert a simuler le
 * clic « arreter » PENDANT que le run est en vol (le `ScraperRun` est deja
 * cree a ce moment-la, cf. LaunchZoneScrapingJob:55 puis :77).
 */
function arretFauxInsee(int $nb, ?callable $avantRendu = null): InseeClient
{
    return new class($nb, $avantRendu) implements InseeClient
    {
        public function __construct(private int $nb, private $avantRendu) {}

        public function fetchBySiren(string $siren): ?InseeCompanyData
        {
            return null;
        }

        public function searchByCriteria(array $criteria): array
        {
            if ($this->avantRendu !== null) {
                ($this->avantRendu)();
            }
            $out = [];
            for ($i = 1; $i <= $this->nb; $i++) {
                $out[] = new InseeCompanyData(
                    siren: str_pad((string) (100000000 + $i), 9, '0', STR_PAD_LEFT),
                    denomination: 'Entreprise ' . $i,
                    naf: '6201Z',
                    legalForm: 'SAS',
                    effectifRange: '11',
                );
            }

            return $out;
        }

        public function iterateByCriteria(array $criteria): \Generator
        {
            yield from $this->searchByCriteria($criteria);
        }
    };
}

function arretJoueZone(Workspace $w, ?int $campaignId, InseeClient $insee, int $limit = 100): LaunchZoneScrapingJob
{
    $job = new LaunchZoneScrapingJob(
        workspaceId: (string) $w->id,
        department: '75',
        naf: null,
        sizeCategory: null,
        limit: $limit,
        campaignId: $campaignId,
        source: 'insee',
        enrich: false,
    );
    // `FranceTravailDiscoveryClient` n'est jamais appele avec source=insee.
    $job->handle($insee, Mockery::mock(FranceTravailDiscoveryClient::class));

    return $job;
}

// ===========================================================================
// C18-007 — le compteur de quota ne doit JAMAIS redescendre
// ===========================================================================

test("C18-007 — le moniteur ne remet PAS companies_created a zero", function () {
    Queue::fake(); // le moniteur se re-dispatche : en driver `sync` ce serait infini.
    [$u, $w] = arretMakeUser();

    // Etat fidele a la production : 42 entreprises deja portees au compteur par
    // LaunchZoneScrapingJob:121-128, et des runs de campagne SANS company_id
    // (c'est ainsi que LaunchZoneScrapingJob:55-69 les cree).
    $c = arretMakeCampagne($u, $w, ['max_companies' => 100, 'companies_created' => 42]);
    ScraperRun::create([
        'workspace_id' => $w->id,
        'campaign_id'  => $c->id,
        'company_id'   => null,
        'source'       => 'insee',
        'status'       => 'success',
        'started_at'   => now()->subMinute(),
        'finished_at'  => now(),
    ]);

    (new MonitorCampaignProgressJob($c->id))->handle();

    // ROUGE attendu avant correctif : 0 (le recompte ecrase les 42).
    expect($c->fresh()->companies_created)->toBe(42);
});

test("C18-007 TEMOIN — le moniteur remonte bien le compteur quand les runs portent des company_id", function () {
    Queue::fake();
    [$u, $w] = arretMakeUser();
    $c = arretMakeCampagne($u, $w, ['max_companies' => 100, 'companies_created' => 0]);

    // 3 entreprises reelles, 3 runs qui les designent : le recompte doit valoir 3.
    // Ce temoin interdit le « correctif » paresseux qui consisterait a retirer
    // purement et simplement companies_created des agregats — la garde
    // precedente passerait au vert et le compteur ne bougerait plus jamais.
    foreach ([1, 2, 3] as $i) {
        $company = Company::create([
            'workspace_id' => $w->id,
            'siren'        => str_pad((string) (200000000 + $i), 9, '0', STR_PAD_LEFT),
            'denomination' => 'Temoin ' . $i,
        ]);
        ScraperRun::create([
            'workspace_id' => $w->id,
            'campaign_id'  => $c->id,
            'company_id'   => $company->id,
            'source'       => 'insee',
            'status'       => 'success',
            'started_at'   => now()->subMinute(),
            'finished_at'  => now(),
        ]);
    }

    (new MonitorCampaignProgressJob($c->id))->handle();

    expect($c->fresh()->companies_created)->toBe(3);
});

test("C18-007 — une campagne plafonnee a 3 s'arrete a 3 entreprises", function () {
    Queue::fake();
    [$u, $w] = arretMakeUser();
    $c = arretMakeCampagne($u, $w, ['max_companies' => 3, 'companies_created' => 0]);

    // La source rend 10 entreprises ; le plafond est a 3.
    arretJoueZone($w, $c->id, arretFauxInsee(10));

    // ROUGE attendu avant correctif : 10 entreprises en base, compteur a 10.
    expect(Company::where('workspace_id', $w->id)->count())->toBe(3);
    expect($c->fresh()->companies_created)->toBe(3);
});

test("C18-007 TEMOIN — sans plafond atteint, les 10 entreprises sont bien creees", function () {
    Queue::fake();
    [$u, $w] = arretMakeUser();
    $c = arretMakeCampagne($u, $w, ['max_companies' => 1000, 'companies_created' => 0]);

    arretJoueZone($w, $c->id, arretFauxInsee(10));

    // Sans ce temoin, la garde precedente serait verte meme si le faux client
    // ne rendait rien du tout, ou si le job refusait de tourner.
    expect(Company::where('workspace_id', $w->id)->count())->toBe(10);
    expect($c->fresh()->companies_created)->toBe(10);
});

// ===========================================================================
// C18-008 — l'arret doit etre LU par le consommateur
// ===========================================================================

test("C18-008 — apres annulation de la campagne, un job deja en file ne collecte plus", function () {
    Queue::fake();
    [$u, $w] = arretMakeUser();
    $c = arretMakeCampagne($u, $w);

    // L'exploitant clique « arreter » : on passe par le VRAI endpoint, pour que
    // la garde couvre toute la chaine (statut + runs + drapeau Redis + event).
    $this->actingAs($u)
        ->postJson("/api/v1/campaigns/{$c->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    // Un LaunchZoneScrapingJob etait deja en file (delai `ceil(60/rpm)` s) : la
    // file n'ayant pas ete purgee, il finit par s'executer. Il ne doit RIEN
    // collecter.
    arretJoueZone($w, $c->id, arretFauxInsee(10));

    // ROUGE attendu avant correctif : 10 entreprises creees APRES l'arret.
    expect(Company::where('workspace_id', $w->id)->count())->toBe(0);
    expect($c->fresh()->companies_created)->toBe(0);
    // Et aucun run « running » orphelin ne doit rester ouvert derriere.
    expect(ScraperRun::where('campaign_id', $c->id)->where('status', 'running')->count())->toBe(0);
});

test("C18-008 TEMOIN — sans annulation, le meme job collecte bien les 10", function () {
    Queue::fake();
    [$u, $w] = arretMakeUser();
    $c = arretMakeCampagne($u, $w);

    arretJoueZone($w, $c->id, arretFauxInsee(10));

    expect(Company::where('workspace_id', $w->id)->count())->toBe(10);
});

test("C18-008 — un arret en VOL interrompt la boucle du run en cours", function () {
    Queue::fake();
    [$u, $w] = arretMakeUser();
    $c = arretMakeCampagne($u, $w);

    // Le crochet se joue apres la creation du ScraperRun (LaunchZoneScrapingJob:55)
    // et avant la boucle d'upsert (:80) : c'est exactement l'instant ou
    // l'exploitant clique « arreter » sur un run deja en cours.
    $insee = arretFauxInsee(10, function () use ($u, $w) {
        $run = ScraperRun::where('workspace_id', $w->id)->latest('id')->first();
        $this->actingAs($u)
            ->postJson("/api/v1/scraper-runs/{$run->id}/cancel")
            ->assertOk();
    });

    arretJoueZone($w, $c->id, $insee);

    // ROUGE attendu avant correctif : 10 entreprises creees APRES le clic, et le
    // run repasse « success » par-dessus le « cancelled » de l'exploitant.
    expect(Company::where('workspace_id', $w->id)->count())->toBe(0);
    $run = ScraperRun::where('campaign_id', $c->id)->latest('id')->first();
    $this->assertSame('cancelled', $run->status);
});

test("C18-008 — le drapeau Redis `cancelled:scraper-run:{id}` est LU par le consommateur", function () {
    Queue::fake();
    [$u, $w] = arretMakeUser();
    $c = arretMakeCampagne($u, $w);

    // Ici la base dit « running » : SEUL le drapeau Redis porte l'arret. C'est
    // le cas d'un worker Node qui aurait pose le drapeau, ou d'un arret pose
    // pendant que la transaction du run n'est pas encore visible.
    // On observe la cle EXACTE demandee — elle doit etre celle qu'ecrivent
    // ScraperRunsController:99 et ScrapingCampaignsController:315.
    $clesLues = [];
    Redis::shouldReceive('get')
        ->andReturnUsing(function (string $cle) use (&$clesLues) {
            $clesLues[] = $cle;

            return '1';
        });
    Redis::shouldReceive('setex')->andReturnTrue();

    arretJoueZone($w, $c->id, arretFauxInsee(10));

    expect(Company::where('workspace_id', $w->id)->count())->toBe(0);
    $run = ScraperRun::where('campaign_id', $c->id)->latest('id')->first();
    $this->assertNotNull($run, 'Le run doit exister : sans run, la garde serait verte pour la mauvaise raison.');
    $this->assertContains(
        'cancelled:scraper-run:' . $run->id,
        $clesLues,
    );
});

test("C18-008 TEMOIN — drapeau Redis absent : la collecte se fait normalement", function () {
    Queue::fake();
    [$u, $w] = arretMakeUser();
    $c = arretMakeCampagne($u, $w);

    // Meme mise en scene, drapeau a `null` : si la garde precedente etait verte
    // parce que le job ne tourne plus du tout, ce temoin la denoncerait.
    Redis::shouldReceive('get')->andReturnNull();
    Redis::shouldReceive('setex')->andReturnTrue();

    arretJoueZone($w, $c->id, arretFauxInsee(10));

    expect(Company::where('workspace_id', $w->id)->count())->toBe(10);
});
