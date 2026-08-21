<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 19.6 — broadcast event lorsqu'un scraper run est annulé via API.
 * Émis par App\Http\Controllers\Api\ScraperRunsController::cancel.
 *
 * ⚠️ CE COMMENTAIRE A ÉTÉ FAUX DU SPRINT 19.6 AU 2026-08-21. Il affirmait
 * « les workers vérifient également le flag Redis `cancelled:scraper-run:{id}`
 * (TTL 1h) pour interrompre les tâches déjà en cours ». Mesure du 2026-08-20 :
 *     grep -rn "cancelled:scraper-run" backend/ frontend/ workers/
 * → 2 ÉCRITURES (les deux contrôleurs `cancel()`), ce commentaire-ci, et
 *   ZÉRO lecture. Le lecteur décrit ici n'existait nulle part, et l'arrêt
 *   d'urgence n'arrêtait donc rien (constat C18-008).
 *
 * Ce qui lit réellement ce drapeau, aujourd'hui, et rien d'autre :
 *  - `App\Jobs\LaunchZoneScrapingJob::motifArretDistant()` — sources PHP
 *    (`insee`, `france_travail`), relu toutes les 10 entreprises ;
 *  - `workers/src/scrapers/base.ts` (`PREFIXE_ANNULATION`) — files
 *    `axion:scrape:*`, lu avant chaque scrape, et SEULEMENT si le job porte
 *    un `context.scraper_run_id`.
 *
 * Reste inarrêtable : les scrapes poussés par `WaterfallOrchestrator`, qui ne
 * nomment aucune ligne `scraper_runs` — elle n'est créée qu'à l'ingestion du
 * résultat. Résidu compté par `tests/Feature/ArretCollecteCoteNodeTest.php`.
 *
 * Ce broadcast, lui, ne sert QU'À rafraîchir l'écran : il ne porte aucun arrêt.
 */
class ScraperRunCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $workspaceId,
        public readonly int $scraperRunId,
        public readonly ?string $reason = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.' . $this->workspaceId)];
    }

    public function broadcastAs(): string
    {
        return 'scrape-job.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'scraper_run_id' => $this->scraperRunId,
            'reason'         => $this->reason,
            'occurred_at'    => now()->toIso8601String(),
        ];
    }
}
