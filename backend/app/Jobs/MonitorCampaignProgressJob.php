<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInWorkspace;
use App\Models\ScrapingCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19.7 — MonitorCampaignProgressJob.
 *
 * Re-dispatched toutes les 60s tant que la campagne est running.
 *
 *  1) Recompute companies_created via count(distinct companies.id) joined sur runs.campaign_id
 *     (best-effort : si la table companies n'a pas de campaign_id direct on count via runs.company_id).
 *  2) Recompute duration_seconds_used = sum(GREATEST(0, EXTRACT(EPOCH FROM (finished_at - started_at)))) sur les runs
 *     de la campagne — la borne à zéro n'est pas cosmétique, voir le commentaire dans la requête.
 *  3) Recompute runs_completed = runs ayant status ∈ (success|completed|failed|cancelled).
 *  4) Si shouldAutoPause() ≠ null → pause auto (status=paused, paused_reason=…).
 *  5) Si runs_completed === runs_total et runs_total > 0 → status=completed.
 *  6) Sinon re-dispatch dans 60s.
 */
class MonitorCampaignProgressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * 🔴 CONSTAT B17-011. Combien de relances consecutives le battement s'autorise
     * apres un echec, avant de se taire pour de bon.
     *
     * Il en faut une borne : sur une panne PERMANENTE (colonne disparue, espace
     * introuvable) un `failed()` qui re-dispatche sans compter fabrique une file
     * infinie de jobs qui echouent — on remplacerait un suivi mort par une boucle
     * qui inonde Horizon. Cinq relances = cinq minutes de tolerance a une panne
     * passagere (bascule Postgres, redemarrage Redis), puis un `critical` qui
     * nomme la campagne restee `running`.
     */
    private const RELANCES_MAX_APRES_ECHEC = 5;

    /**
     * Echecs CONSECUTIFS deja encaisses par ce battement.
     *
     * ⚠️ Propriete DECLAREE et non parametre promu : une charge serialisee AVANT
     * ce lot et encore en file au deploiement n'a pas cette cle, et un parametre
     * promu type leverait « must not be accessed before initialization » a la
     * deserialisation (mesure du 2026-08-21 consignee dans RunsInWorkspace).
     *
     * Le compteur se remet a zero tout seul : le re-dispatch de fin de `suivre()`
     * construit un `new self(...)` neuf, donc a zero. Seul le chemin d'echec le
     * fait monter.
     */
    public int $echecsConsecutifs = 0;

    public function __construct(public readonly int $campaignId) {}

    /**
     * 🔴 CONSTAT B17-011 (S2) — « une seule exception fige la campagne en running
     * pour toujours ».
     *
     * MESURE DU 2026-08-22, AVANT CORRECTIF : `tries = 1`, le re-dispatch qui
     * entretient le suivi est la DERNIERE instruction de `suivre()`, et le seul
     * `try/catch` du corps ne couvre que le recompte des agregats. Tout ce qui
     * suit — `$campaign->update($aggregates)`, `shouldAutoPause()`, les deux
     * `update()` de pause et de fin — peut lever hors de ce filet. Aucune methode
     * `failed()` n'existait dans aucun job (`grep -rn 'public function failed'
     * app/Jobs/*.php` ne rendait rien), et `routes/console.php` ne planifie aucun
     * guetteur de campagnes bloquees. Une exception unique tuait donc la chaine :
     * la campagne restait `running`, sans plus jamais etre auto-pausee sur quota
     * ni marquee terminee.
     *
     * Ce que ce `failed()` retablit : le BATTEMENT, pas le travail. Il ne rejoue
     * pas le tour perdu, il reprogramme le suivant. Si la campagne n'est plus
     * `running`, ce tour suivant sort par les `return` du haut de `suivre()` sans
     * rien re-dispatcher : la chaine s'eteint d'elle-meme, comme avant.
     */
    public function failed(\Throwable $e): void
    {
        $relance = $this->echecsConsecutifs + 1;

        if ($relance > self::RELANCES_MAX_APRES_ECHEC) {
            Log::critical(
                'MonitorCampaignProgressJob : suivi ABANDONNE apres '
                . self::RELANCES_MAX_APRES_ECHEC . ' echecs consecutifs (constat B17-011). '
                . 'La campagne peut rester bloquee en « running » sans auto-pause ni fin — '
                . 'geste : corriger la cause ci-dessous, puis re-dispatcher le suivi '
                . '(MonitorCampaignProgressJob) sur cette campagne.',
                [
                    'campaign_id' => $this->campaignId,
                    'exception' => $e->getMessage(),
                ],
            );

            return;
        }

        Log::warning('MonitorCampaignProgressJob : battement relance apres echec (constat B17-011)', [
            'campaign_id' => $this->campaignId,
            'relance' => $relance,
            'plafond' => self::RELANCES_MAX_APRES_ECHEC,
            'exception' => $e->getMessage(),
        ]);

        $suivant = new self($this->campaignId);
        $suivant->echecsConsecutifs = $relance;

        // L'espace de la charge peut manquer (job mis en file avant B11-002) ;
        // l'amorcage par la ligne peut lui-meme lever sous RLS stricte, et une
        // exception ici ferait perdre le battement qu'on est justement en train
        // de sauver.
        $espace = $this->espaceDuJob();
        if ($espace === null) {
            try {
                $espace = $this->espaceDepuisLaLigne('scraping_campaigns', $this->campaignId);
            } catch (\Throwable) {
                $espace = null;
            }
        }

        dispatch($suivant->pourEspace($espace))->delay(now()->addSeconds(60));
    }

    /**
     * 🔴 CONSTAT B11-002 / B17-010. Ce moniteur tourne toutes les 60 s en se
     * re-dispatchant lui-meme, et il ECRIT dans `scraping_campaigns`
     * (compteurs de quota, auto-pause). `Queue::looping` efface le contexte
     * entre deux jobs : il n'en avait aucun. Sous policy stricte, son
     * `ScrapingCampaign::find()` rend `null` et le `return` de la ligne
     * suivante est SILENCIEUX — une campagne ne se mettrait plus jamais en
     * pause sur quota. Le corps s'execute desormais sous l'espace de sa
     * campagne.
     */
    public function handle(): void
    {
        $espace = $this->espaceDuJob() ?? $this->espaceDepuisLaLigne('scraping_campaigns', $this->campaignId);

        if ($espace === null) {
            Log::warning('MonitorCampaignProgressJob: aucun espace de travail — suivi abandonne (constat B11-002)', [
                'campaign_id' => $this->campaignId,
            ]);

            return;
        }

        $this->inWorkspace($espace, fn () => $this->suivre());
    }

    private function suivre(): void
    {
        /** @var ScrapingCampaign|null $campaign */
        $campaign = ScrapingCampaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }
        if (! in_array($campaign->status, ['running'], true)) {
            return;
        }

        // 1+3) Aggrégats côté runs.
        //
        // 🔴 C18-007 (second chemin). Ces valeurs de depart etaient a 0, et elles
        // sont ecrites telles quelles par le `update()` plus bas des que le
        // recompte echoue — table `scraper_runs` absente, ou exception attrapee
        // par le `catch` ci-dessous. Autrement dit : une base momentanement
        // indisponible remettait le compteur de quota a zero, en silence, toutes
        // les 60 s. On part donc de l'etat courant : a defaut de savoir
        // recompter, le moniteur ne DEFAIT rien.
        $aggregates = [
            'runs_completed' => (int) $campaign->runs_completed,
            'companies_created' => (int) $campaign->companies_created,
            'duration_seconds_used' => (int) $campaign->duration_seconds_used,
        ];

        try {
            if (Schema::hasTable('scraper_runs')) {
                $row = DB::selectOne(
                    "SELECT
                        COUNT(*) FILTER (WHERE status IN ('success','completed','failed','cancelled'))::INTEGER AS runs_completed,
                        -- GREATEST(0, …) : `started_at` et `finished_at` ne sont
                        -- PAS toujours les deux bornes d'une même durée.
                        -- `ScrapedRecordIngestService` écrit
                        -- `started_at = fetchedAt` (heure de collecte CHEZ LE
                        -- PRODUCTEUR) et `finished_at = now()` (heure
                        -- d'ingestion par le CRM) : leur ordre n'est pas garanti.
                        -- Mesuré en production le 2026-08-16 : 646 lignes de la
                        -- collecte `implantations-fr-etranger` portent un
                        -- `fetchedAt` constant (10:00) postérieur à leur
                        -- ingestion (06:51), soit −11 320 s chacune.
                        -- Sans cette borne, un import RETRANCHE de la durée
                        -- consommée d'une campagne — un compteur de quota qui
                        -- décroît, donc un plafond qui se relâche tout seul.
                        COALESCE(SUM(
                            CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL
                                 THEN GREATEST(0, EXTRACT(EPOCH FROM (finished_at - started_at)))
                                 ELSE 0 END
                        ),0)::INTEGER AS duration_seconds_used,
                        COUNT(DISTINCT company_id) FILTER (WHERE company_id IS NOT NULL)::INTEGER AS companies_created
                     FROM scraper_runs
                     WHERE campaign_id = ?",
                    [$campaign->id],
                );
                if ($row) {
                    $aggregates['runs_completed'] = (int) ($row->runs_completed ?? 0);
                    $aggregates['duration_seconds_used'] = (int) ($row->duration_seconds_used ?? 0);

                    // 🔴 CONSTAT C18-007. Ce recompte VALAIT TOUJOURS 0 pour une
                    // campagne de decouverte, et il ECRASAIT le compteur.
                    // `LaunchZoneScrapingJob` cree son run SANS `company_id`
                    // (colonne nullable, cf. `\d scraper_runs`) puis incremente
                    // `companies_created` par `UPDATE … companies_created + N`.
                    // Le moniteur, re-dispatche toutes les 60 s, remettait donc
                    // ce compteur a zero chaque minute : `shouldAutoPause()` ne
                    // voyait jamais `companies_created >= max_companies`, et le
                    // plafond `max_companies` ne freinait rien.
                    //
                    // `max(…)` et pas le recompte seul : un compteur de quota ne
                    // doit JAMAIS redescendre — c'est exactement la maladie deja
                    // nommee quelques lignes plus haut a propos du `GREATEST(0, …)`
                    // sur les durees, « un plafond qui se relache tout seul ».
                    // Le recompte reste utile : il RATTRAPE les entreprises
                    // ingerees par un autre canal (runs Node portant un
                    // `company_id`, cf. `/internal/scraper-result`), qui
                    // n'incrementent pas le compteur eux-memes.
                    $aggregates['companies_created'] = max(
                        (int) $campaign->companies_created,
                        (int) ($row->companies_created ?? 0),
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MonitorCampaignProgressJob: aggregates failed', [
                'campaign_id' => $campaign->id,
                'exception' => $e->getMessage(),
            ]);
        }

        $campaign->update($aggregates);
        $campaign->refresh();

        // 4) Auto-pause ?
        $reason = $campaign->shouldAutoPause();
        if ($reason !== null) {
            $campaign->update([
                'status' => 'paused',
                'paused_at' => now(),
                'paused_reason' => $reason,
            ]);
            Log::info('Campaign auto-paused', [
                'campaign_id' => $campaign->id,
                'reason' => $reason,
            ]);

            return;
        }

        // 5) Tous les runs terminés ?
        if ($campaign->runs_total > 0 && $campaign->runs_completed >= $campaign->runs_total) {
            $campaign->update([
                'status' => 'completed',
                'finished_at' => now(),
            ]);

            return;
        }

        // 6) Sinon, re-self-dispatch
        dispatch((new self($campaign->id))
            ->pourEspace((string) $campaign->workspace_id))
            ->delay(now()->addSeconds(60));
    }
}
