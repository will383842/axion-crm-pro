<?php

namespace App\Jobs;

use App\Contracts\InseeClient;
use App\Data\Sources\InseeCompanyData;
use App\Jobs\Concerns\RunsInWorkspace;
use App\Models\Company;
use App\Models\ScraperRun;
use App\Services\FranceTravail\FranceTravailDiscoveryClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Lance le scraping de découverte pour une zone géo via une source donnée.
 *
 * Sources supportées (paramètre `$source`) :
 *  - 'insee'           : InseeClient->searchByCriteria(department, naf, limit) (default)
 *  - 'france_travail'  : FranceTravailDiscoveryClient->searchEntreprisesByDept(dept, limit)
 *  - 'google_maps'     : skip silencieux si MOCK_SCRAPERS=true, sinon DispatchScrapeJob Node BullMQ
 *  - 'pages_jaunes'    : idem
 *
 * Flow :
 *  1. Crée un ScraperRun (status=running) avec source dynamique
 *  2. Discovery selon $source → liste DTO entreprises
 *  3. Upsert companies + dispatch EnrichCompanyJob pour chacune
 *  4. Met à jour ScraperRun (status=success/failed) + compteurs campagne
 *
 * Backward-compat : $source='insee' par défaut, anciens callers /coverage/launch
 * continuent de fonctionner sans changement.
 */
class LaunchZoneScrapingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    /**
     * 🔴 C18-008. Prefixe de la cle Redis d'annulation. C'est LA cle qu'ecrivent
     * `ScraperRunsController::cancel()` et `ScrapingCampaignsController::cancel()`
     * depuis le Sprint 18, et que PERSONNE ne lisait :
     *     grep -rn "cancelled:scraper-run" backend/ frontend/
     *     → 2 ecritures, 1 commentaire (« les workers verifient egalement le
     *       flag Redis », ScraperRunCancelled.php:15), 0 LECTURE.
     * Le lecteur decrit par ce commentaire n'existait pas. Le voici.
     */
    public const CLE_ANNULATION = 'cancelled:scraper-run:';

    /**
     * 🔴 C18-008. Cadence de relecture des canaux d'arret DISTANTS (Redis +
     * base). Un controle a chaque entreprise triplerait le nombre de requetes
     * de la boucle (l'upsert en fait deja 2) ; un controle trop rare laisse la
     * collecte deborder apres le clic « arreter ».
     * 10 : au pire 9 entreprises collectees apres l'arret, pour +20 % de
     * requetes. Le plafond `max_companies`, lui, est verifie a CHAQUE tour —
     * il se calcule en memoire et ne coute rien (cf. `motifArretLocal()`).
     */
    private const CADENCE_CONTROLE_ARRET = 10;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $department,
        public readonly ?string $naf,
        public readonly ?string $sizeCategory,
        public readonly int $limit,
        public readonly ?int $campaignId = null,
        public readonly string $source = 'insee',
        public readonly bool $enrich = true,
    ) {}

    /**
     * L'espace de CE job-ci est deja dans son constructeur : il n'a jamais eu
     * besoin de l'amorcage. Ce qui lui manquait, c'est de le POSER.
     */
    protected function espaceDuJob(): ?string
    {
        return $this->espaceCible ?? $this->workspaceId;
    }

    /**
     * CONSTAT B11-002 / B17-010.
     *
     * Ce job portait `$this->workspaceId` depuis toujours et s'en servait comme
     * d'une VALEUR (`'workspace_id' => $this->workspaceId` a chaque ecriture) —
     * jamais comme d'un CONTEXTE. La difference se paie sous policy stricte :
     * le `ScraperRun::create()` plus bas porte le bon `workspace_id`, mais le
     * `WITH CHECK` le compare a `current_setting('app.current_workspace_id')`,
     * que `Queue::looping` vient d'effacer. L'INSERT est refuse
     * (SQLSTATE 42501), le job meurt, et le `companies_created` de la campagne
     * ne bouge plus.
     *
     * Le corps entier passe donc sous `inWorkspace()` : la boucle de
     * decouverte, les `updateOrCreate` sur `companies`, les increments de
     * `scraping_campaigns` et les `DispatchScrapeJob` enfants.
     */
    public function handle(InseeClient $insee, FranceTravailDiscoveryClient $ftDiscovery): void
    {
        $this->inWorkspace(
            $this->espaceDuJob(),
            fn () => $this->collecter($insee, $ftDiscovery),
        );
    }

    private function collecter(InseeClient $insee, FranceTravailDiscoveryClient $ftDiscovery): void
    {
        // 🔴 C18-008. PREMIER point de lecture de l'arret : avant meme de creer
        // un run. `LaunchCampaignJob` pousse un job par (zone x source) avec un
        // decalage de `ceil(60 / max_requests_per_minute)` s chacun — la file
        // porte donc, a tout instant, des jobs qui n'ont pas encore demarre.
        // Aucun d'eux ne demandait a la campagne si elle tournait encore :
        // « annuler » n'ecrivait qu'un statut, et la collecte continuait.
        // Laravel ne sait pas retirer un job precis d'une file ; le seul arret
        // qui tienne est celui-ci, a l'execution.
        if ($this->campaignId !== null) {
            $motif = $this->motifArretCampagne();
            if ($motif !== null) {
                Log::info('LaunchZoneScrapingJob: arret lu avant demarrage, aucun run cree', [
                    'campaign_id' => $this->campaignId,
                    'department' => $this->department,
                    'source' => $this->source,
                    'motif' => $motif,
                ]);

                return;
            }
        }

        $run = ScraperRun::create([
            'workspace_id' => $this->workspaceId,
            'campaign_id' => $this->campaignId,
            'source' => $this->source,
            'status' => 'running',
            'started_at' => now(),
            'request_payload' => [
                'type' => $this->campaignId ? 'campaign' : 'coverage_launch',
                'campaign_id' => $this->campaignId,
                'department' => $this->department,
                'naf' => $this->naf,
                'limit' => $this->limit,
                'source' => $this->source,
            ],
        ]);

        $companiesCreated = 0;
        $companiesFound = 0;
        $companiesNew = 0;
        $companiesRefreshed = 0;

        // 🔴 C18-007. Ce qui reste a collecter avant de toucher `max_companies`,
        // lu UNE fois au demarrage. Le report au compteur de campagne ne se fait
        // qu'a la fin du job (plus bas) : relire la base a chaque tour rendrait
        // la meme valeur. La relecture periodique de `motifArretCampagne()`
        // couvre le cas de plusieurs jobs concurrents sur la meme campagne.
        $plafondRestant = $this->plafondRestantAuDemarrage();

        // Motif d'interruption de la boucle, null si elle est allee au bout.
        $motifArret = null;

        try {
            $results = $this->discoverEntreprises($insee, $ftDiscovery, $run);
            $companiesFound = count($results);

            foreach ($results as $rang => $data) {
                // 🔴 C18-007. Le plafond se verifie a CHAQUE tour : c'est ce qui
                // fait qu'une campagne plafonnee a N s'arrete a N, et non a la
                // taille du lot rendu par la source. Mesure du 2026-08-20 : sans
                // ce controle, une campagne a `max_companies=3` face a une source
                // rendant 10 resultats en creait 10.
                if ($plafondRestant !== null && ($companiesNew + $companiesRefreshed) >= $plafondRestant) {
                    $motifArret = 'quota_companies';
                    break;
                }

                // 🔴 C18-008. Canaux d'arret distants (drapeau Redis + statuts en
                // base), relus a la cadence `CADENCE_CONTROLE_ARRET`. Le tour 0
                // est toujours controle : un arret pose pendant la phase de
                // decouverte (qui peut durer des minutes) est vu avant le
                // premier upsert.
                if ($rang % self::CADENCE_CONTROLE_ARRET === 0) {
                    $motifArret = $this->motifArretDistant($run);
                    if ($motifArret !== null) {
                        break;
                    }
                }

                $company = Company::query()->updateOrCreate(
                    ['workspace_id' => $this->workspaceId, 'siren' => $data->siren],
                    [
                        'denomination' => $data->denomination,
                        'naf' => $data->naf ?? $this->naf,
                        'legal_form' => $data->legalForm,
                        'effectif_range' => $data->effectifRange,
                        'size_category' => $this->sizeCategory,
                        'discovery_source' => $this->source,
                        // Tampon du département dès la découverte (la zone est connue)
                        // → permet « Enrichir par département » sans attendre la
                        // classification. La classification confirmera la même valeur.
                        'department_code' => $this->department,
                    ],
                );
                if ($company->wasRecentlyCreated) {
                    $companiesNew++;
                } else {
                    $companiesRefreshed++;
                }
                // Enrichissement chaîné seulement si demandé (bouton « Récupérer »
                // seul → enrich=false ; « Enrichir » séparé via /coverage/enrich).
                if ($this->enrich) {
                    dispatch((new EnrichCompanyJob($company->id))->pourEspace($this->workspaceId));
                }
            }
            $companiesCreated = $companiesNew + $companiesRefreshed;

            // 🔴 C18-008. Un run interrompu ne doit PAS repasser « success » :
            // avant ce correctif, un run que l'exploitant venait de passer
            // « cancelled » etait reecrit en « success » par la fin du job — le
            // bouton « arreter » s'effacait de l'historique. `partial` sur un
            // arret par plafond (le job a fait une partie de son travail),
            // `cancelled` sur un arret demande.
            $run->update([
                'status' => $motifArret === null
                    ? 'success'
                    : ($motifArret === 'quota_companies' ? 'partial' : 'cancelled'),
                'finished_at' => now(),
                'error' => $motifArret === null
                    ? $run->error
                    : 'Collecte interrompue : ' . $motifArret,
                'response_payload' => [
                    'companies_found' => $companiesFound,
                    'companies_processed' => $companiesCreated,
                    'companies_new' => $companiesNew,
                    'companies_refreshed' => $companiesRefreshed,
                    'source' => $this->source,
                    'motif_arret' => $motifArret,
                ],
            ]);

            if ($this->campaignId !== null && $companiesCreated > 0) {
                DB::table('scraping_campaigns')
                    ->where('id', $this->campaignId)
                    ->update([
                        'companies_created' => DB::raw("companies_created + {$companiesCreated}"),
                        'runs_completed' => DB::raw('runs_completed + 1'),
                        'updated_at' => now(),
                    ]);
            } elseif ($this->campaignId !== null) {
                DB::table('scraping_campaigns')
                    ->where('id', $this->campaignId)
                    ->update([
                        'runs_completed' => DB::raw('runs_completed + 1'),
                        'updated_at' => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::error('LaunchZoneScrapingJob failed', [
                'workspace_id' => $this->workspaceId,
                'campaign_id' => $this->campaignId,
                'department' => $this->department,
                'source' => $this->source,
                'error' => $e->getMessage(),
            ]);
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
            if ($this->campaignId !== null) {
                DB::table('scraping_campaigns')
                    ->where('id', $this->campaignId)
                    ->update([
                        'runs_completed' => DB::raw('runs_completed + 1'),
                        'updated_at' => now(),
                    ]);
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // 🔴 C18-008 / C18-007 — lecture de l'arret
    // ------------------------------------------------------------------

    /**
     * Motif d'arret porte par la CAMPAGNE, ou null s'il faut continuer.
     *
     * Trois causes, toutes lues en base — la seule source qui soit toujours
     * disponible, y compris quand Redis est tombe :
     *   - la campagne a disparu (ou a ete supprimee en douceur) ;
     *   - elle n'est plus `running` (cancelled / paused / completed) : c'est
     *     ce que posent `ScrapingCampaignsController::cancel()` et `::pause()` ;
     *   - son plafond `max_companies` est deja atteint.
     *
     * On interroge la table directement plutot que le modele : `ScrapingCampaign`
     * porte `SoftDeletes`, dont le scope global masquerait une campagne
     * supprimee et ferait repondre « campagne absente » au lieu du vrai motif.
     */
    private function motifArretCampagne(int $collecteesNonReportees = 0): ?string
    {
        if ($this->campaignId === null) {
            return null;
        }

        $campagne = DB::table('scraping_campaigns')
            ->where('id', $this->campaignId)
            ->first(['status', 'max_companies', 'companies_created', 'deleted_at']);

        if ($campagne === null) {
            return 'campagne_absente';
        }
        if ($campagne->deleted_at !== null) {
            return 'campagne_supprimee';
        }
        if ($campagne->status !== 'running') {
            return 'campagne_' . $campagne->status;
        }

        $plafond = (int) $campagne->max_companies;
        $deja = (int) $campagne->companies_created + $collecteesNonReportees;
        if ($plafond > 0 && $deja >= $plafond) {
            return 'quota_companies';
        }

        return null;
    }

    /**
     * Ce qu'il reste a collecter avant le plafond, ou null si aucune campagne
     * (mode `/coverage/launch` legacy) ou plafond non renseigne.
     */
    private function plafondRestantAuDemarrage(): ?int
    {
        if ($this->campaignId === null) {
            return null;
        }

        $campagne = DB::table('scraping_campaigns')
            ->where('id', $this->campaignId)
            ->first(['max_companies', 'companies_created']);

        if ($campagne === null || (int) $campagne->max_companies <= 0) {
            return null;
        }

        return max(0, (int) $campagne->max_companies - (int) $campagne->companies_created);
    }

    /**
     * Motif d'arret porte par un canal DISTANT (Redis ou base), ou null.
     *
     * L'ordre compte : le drapeau Redis est le canal le plus rapide (pose par
     * les deux controleurs au moment du clic, TTL 3600 s), la base est le canal
     * le plus sur. On lit les deux ; il suffit qu'UN SEUL dise stop.
     */
    private function motifArretDistant(ScraperRun $run): ?string
    {
        // a) Le drapeau que personne ne lisait.
        try {
            $drapeau = Redis::connection(DispatchScrapeJob::CONNEXION_REDIS)
                ->get(self::CLE_ANNULATION . $run->id);
            // Selon le client (predis rend null, phpredis rend false), l'absence
            // ne se teste pas de la meme facon : on n'accepte que du contenu.
            if ($drapeau !== null && $drapeau !== false && (string) $drapeau !== '') {
                return 'run_annule_redis';
            }
        } catch (\Throwable $e) {
            // Redis indisponible n'est PAS une autorisation de continuer : on
            // se rabat sur la base ci-dessous, qui porte la meme information.
            Log::warning('LaunchZoneScrapingJob: lecture du drapeau Redis impossible', [
                'run_id' => $run->id,
                'exception' => $e->getMessage(),
            ]);
        }

        // b) Statut du run en base : c'est ce qu'ecrivent
        //    `ScraperRunsController::cancel()` et `ScrapingCampaignsController::cancel()`.
        $statut = DB::table('scraper_runs')->where('id', $run->id)->value('status');
        if ($statut === 'cancelled') {
            return 'run_annule';
        }

        // c) Campagne (annulee, mise en pause, ou plafond atteint entre-temps
        //    par un job concurrent).
        return $this->motifArretCampagne();
    }

    /**
     * Dispatch vers le bon client selon $this->source.
     *
     * @return array<int, InseeCompanyData>
     */
    private function discoverEntreprises(InseeClient $insee, FranceTravailDiscoveryClient $ftDiscovery, ScraperRun $run): array
    {
        return match ($this->source) {
            'insee' => $insee->searchByCriteria([
                'department' => $this->department,
                'naf' => $this->naf,
                'limit' => $this->limit,
            ]),
            'france_travail' => $ftDiscovery->searchEntreprisesByDept($this->department, $this->limit),
            'google_maps', 'pages_jaunes' => $this->dispatchNodeWorker($run),
            default => throw new \RuntimeException("Unknown discovery source: {$this->source}"),
        };
    }

    /**
     * Pour les sources Node (Playwright) : si MOCK_SCRAPERS=true on retourne []
     * silencieusement (run = success vide). Sinon on enqueue le scrape Node mais
     * dans tous les cas LaunchZoneScrapingJob ne reçoit pas les résultats Node
     * (asynchrone via /internal/scraper-result).
     *
     * @return array<int, InseeCompanyData>
     */
    private function dispatchNodeWorker(ScraperRun $run): array
    {
        if ((bool) env('MOCK_SCRAPERS', true)) {
            Log::info('LaunchZoneScrapingJob: MOCK_SCRAPERS=true, skipping Node worker', [
                'source' => $this->source, 'department' => $this->department,
            ]);

            return [];
        }
        // Phase B (production) : enqueue via DispatchScrapeJob — résultats arriveront
        // de manière asynchrone côté /internal/scraper-result et créeront leurs propres
        // Company + ScraperRun. On ne bloque pas ici.
        //
        // 🔴 C18-008, SITE JUMEAU. `scraper_run_id` n'est pas decoratif : c'est
        // le SEUL numero qui permette au worker Node de composer la cle
        // `cancelled:scraper-run:{id}` posee par les deux controleurs au clic
        // « arreter ». Le `run_id` que `DispatchScrapeJob` fabrique par
        // `bin2hex(random_bytes(8))` ne designe aucune ligne `scraper_runs` :
        // sans cette entree, le worker n'avait rien a mettre derriere le
        // prefixe, et les onze files `axion:scrape:*` etaient integralement
        // insensibles a l'arret d'urgence (mesure du 2026-08-21 :
        // `grep -rni "cancel" workers/src` -> aucun fichier).
        // Le lecteur est `workers/src/scrapers/base.ts` (`PREFIXE_ANNULATION`).
        // ⚠️ Si tu renommes cette entree, renomme-la LA-BAS AUSSI.
        DispatchScrapeJob::dispatch(
            companyId: 0,  // synthétique : pas de company source pour une zone discovery
            source: str_replace('_', '-', $this->source),
            context: [
                'discovery_zone' => $this->department,
                'limit' => $this->limit,
                'campaign_id' => $this->campaignId,
                'workspace_id' => $this->workspaceId,
                'scraper_run_id' => (int) $run->id,
            ],
            targetUrl: null,
        );

        return [];
    }
}
