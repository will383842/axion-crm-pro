<?php

namespace App\Jobs;

use App\Services\Http\SsrfGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Job laravel-side qui dépose un payload sur une liste Redis simple lue côté Node.
 *
 * Convention queue name : `axion:scrape:<source>` (cf. workers/src/bridge/queues.ts).
 * Côté Node : `BRPOP axion:scrape:<source>` (cf. workers/src/scrapers/base.ts).
 *
 * NOTE : on n'utilise pas le format binaire BullMQ natif (hashes + sets) car la
 * passerelle PHP→Node serait fragile. Schéma simple JSON + listes Redis = robuste,
 * inspectable via `redis-cli`, supporte n'importe quel langage côté consumer.
 *
 * ⚠️ C18-011 — CE COMMENTAIRE DÉCRIVAIT UN PONT QUI N'AVAIT JAMAIS MARCHÉ.
 * Jusqu'au 2026-08-21, ce job poussait par `Redis::connection('queue')`, une
 * connexion qui hérite de `database.redis.options.prefix`. La clef réellement
 * écrite sur le fil était `axion_crm_pro_database_axion:scrape:<source>`, alors
 * que le `BRPOP` du worker porte la clef NUE. Personne ne lisait, et rien ne
 * rougissait. On passe désormais par `scrape_bridge`, connexion déclarée SANS
 * préfixe dans `config/database.php` — et rien d'autre du cache, des sessions
 * ou des files Laravel ne bouge.
 * La garde qui le prouve SUR LE FIL (pas dans la configuration) :
 * `tests/Unit/Scraping/PontRedisPrefixeTest.php`.
 */
class DispatchScrapeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Connexion Redis du pont vers les workers Node. Elle DOIT rester sans
     * préfixe : c'est tout ce qui la distingue de `queue`.
     */
    public const CONNEXION_REDIS = 'scrape_bridge';

    /** Préfixe des noms de file, partagé avec `workers/src/bridge/queues.ts`. */
    public const PREFIXE_FILE = 'axion:scrape:';

    public int $tries = 1; // ce job ne fait que push ; le worker Node gère ses propres retries

    /** Nom de la file Redis écoutée par le worker Node de cette source. */
    public static function file(string $source): string
    {
        return self::PREFIXE_FILE . $source;
    }

    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly int $companyId,
        public readonly string $source,
        public readonly array $context = [],
        public readonly ?string $targetUrl = null,
    ) {}

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * C19-001 — SITE JUMEAU NON PORTE. Mesure du 2026-08-20 :
     *     grep -n "SsrfGuard" app/Jobs/DispatchScrapeJob.php  ->  0 resultat
     * ═══════════════════════════════════════════════════════════════════════
     *
     * `target_url` n'est pas une URL quelconque : `WaterfallOrchestrator`
     * (ligne 418) la remplit avec `$company->website`, c'est-a-dire de la
     * DONNEE — ecrite par l'import, par le DomainFinder, par Google Places ou a
     * la main. Elle est deposee ici sur `axion:scrape:<source>` et ouverte par
     * le navigateur Playwright du worker Node.
     *
     * ⚠️ CE CORRECTIF EST DE LA DEFENSE EN PROFONDEUR, ET IL FAUT LE DIRE.
     * `workers/src/scrapers/website.playwright.ts` ligne 23 appelle deja
     * `ensureSsrf(req.target_url)` : la cible interne est refusee cote Node.
     * Ce qui est ferme ici, c'est la dependance a CE fichier-la, dans un AUTRE
     * langage. Mesure : `grep -rn ensureSsrf workers/src` -> 3 appels, dont
     * un seul concerne `target_url`. `pages-jaunes.playwright.ts` et
     * `google-search.playwright.ts` n'appellent pas la garde ; ils ne lisent
     * pas `target_url` aujourd'hui — mais `WaterfallOrchestrator` la leur
     * envoie deja (la meme boucle dispatche les trois sources). Le jour ou
     * l'un d'eux la lira, ce controle-ci sera le seul en travers du chemin.
     *
     * Pourquoi journaliser et sortir plutot que lever : ce job tourne par
     * millions (une salve par entreprise enrichie, trois sources chacune).
     * Une exception remplirait `failed_jobs` d'un bruit qui masquerait les
     * vraies pannes, pour un evenement qui n'est pas une erreur technique mais
     * une donnee refusee. On ne met pas en file, et on le dit.
     */
    public function handle(): void
    {
        if ($this->targetUrl !== null && $this->targetUrl !== '') {
            $verdict = SsrfGuard::check($this->targetUrl);
            if (! $verdict['ok']) {
                Log::warning('DispatchScrapeJob: target_url refusee par la garde SSRF', [
                    'company_id' => $this->companyId,
                    'source' => $this->source,
                    'target_url' => $this->targetUrl,
                    'motif' => $verdict['reason'],
                ]);

                return;
            }
        }

        $payload = [
            'run_id' => bin2hex(random_bytes(8)),
            'source' => $this->source,
            'company_id' => $this->companyId,
            'target_url' => $this->targetUrl,
            'context' => $this->context,
            'enqueued_at' => now()->toIso8601String(),
            'attempts' => 0,
            'max_attempts' => 3,
        ];

        Redis::connection(self::CONNEXION_REDIS)->lpush(
            self::file($this->source),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
