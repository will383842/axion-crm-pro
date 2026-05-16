# 20 — DÉTECTION NOUVEAUX PROSPECTS + SIGNAUX BUSINESS

## Vue d'ensemble

Axion CRM Pro ne se contente pas d'enrichir des entreprises connues : il **détecte proactivement** chaque jour les nouvelles opportunités via des **jobs nightly** programmés via Laravel Scheduler. Ces jobs scannent INSEE pour découvrir les entreprises nouvellement créées matchant des critères Axion-IA, BODACC pour détecter les changements de dirigeants / levées / redressements sur les SIREN en base, France Travail pour identifier les recrutements C-level (signal d'achat majeur), et un crawler hebdomadaire de news Tech FR (frenchweb.fr, maddyness.com) pour les levées de fonds Tech.

Chaque détection insère une ligne dans `company_business_signals` avec sévérité graduée, et déclenche une notification Slack + Telegram pour les signaux **critical** (levée > 1M€, nouveau DSI/DAF/CEO, redressement).

---

## 1. Laravel Scheduler — config

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Job 1 : Nouveau SIREN INSEE (entreprises créées matchant critères)
    $schedule->job(new PollInseeNewCompaniesJob())
        ->dailyAt('01:30')->withoutOverlapping(60)->onOneServer();

    // Job 2 : BODACC signaux (sur SIREN en base)
    $schedule->job(new PollBodaccSignalsJob())
        ->dailyAt('02:00')->withoutOverlapping(120)->onOneServer();

    // Job 3 : France Travail recrutements C-level
    $schedule->job(new PollFranceTravailClevelJob())
        ->dailyAt('02:30')->withoutOverlapping(120)->onOneServer();

    // Job 4 : Crunchbase levées de fonds Tech (hebdo + risque ban donc prudent)
    $schedule->job(new PollCrunchbaseFundraisingJob())
        ->weekly()->mondays()->at('03:00')->withoutOverlapping(180)->onOneServer();

    // Job 5 : Scraping news Tech FR (frenchweb + maddyness)
    $schedule->job(new ScrapeFrTechNewsJob())
        ->weekly()->mondays()->at('04:00')->withoutOverlapping(60)->onOneServer();

    // Job 6 : Recalcul priority_score (après injection de signaux)
    $schedule->job(new RecalculatePriorityScoresJob())
        ->dailyAt('05:00')->onOneServer();

    // Job 7 : Refresh materialized view coverage_matrix_cells (full)
    $schedule->command('axion:refresh-coverage --concurrently')
        ->dailyAt('05:30')->onOneServer();

    // Job 8 : Refresh coverage incremental (hourly)
    $schedule->command('axion:refresh-coverage --concurrently')
        ->hourly()->onOneServer();

    // Job 9 : Health checks proxies (batch toutes les 15 min)
    $schedule->job(new BatchProxyHealthCheckJob())
        ->everyFifteenMinutes()->onOneServer();

    // Job 10 : Anomaly detection (nightly)
    $schedule->job(new DetectAnomaliesJob())
        ->dailyAt('06:00')->onOneServer();

    // Job 11 : LinkedIn accounts health check (hourly)
    $schedule->job(new LinkedInAccountHealthJob())
        ->hourly()->onOneServer();

    // Job 12 : Reset LinkedIn daily usage (00:05)
    $schedule->job(new ResetLinkedInDailyUsageJob())
        ->dailyAt('00:05')->onOneServer();

    // Job 13 : Backup PG (cron OS, mais ping audit log)
    $schedule->command('axion:audit-backup-ok')
        ->dailyAt('02:30')->onOneServer();

    // Job 14 : Refresh disposable email domains list
    $schedule->job(new RefreshDisposableListJob())
        ->monthly()->onOneServer();

    // Job 15 : Vérification intégrité hash chain audit_logs
    $schedule->job(new VerifyAuditLogIntegrityJob())
        ->dailyAt('03:30')->onOneServer();

    // Job 16 : Purge email_verifications expirées
    $schedule->job(new PurgeExpiredEmailVerificationsJob())
        ->dailyAt('04:00')->onOneServer();
}
```

---

## 2. Job `PollInseeNewCompaniesJob`

```php
namespace App\Modules\Signals\Jobs;

use App\Modules\Sources\Plugins\InseeSirenePlugin;
use App\Modules\Scraping\Models\Company;
use App\Modules\Geo\Models\Region;
use App\Modules\Coverage\Models\TargetZone;

final class PollInseeNewCompaniesJob implements ShouldQueue
{
    public int $timeout = 1800;       // 30 min max

    public function handle(InseeSirenePlugin $insee): void
    {
        $workspaces = Workspace::query()->where('status','active')->get();
        foreach ($workspaces as $ws) {
            $this->processWorkspace($ws, $insee);
        }
    }

    private function processWorkspace(Workspace $ws, InseeSirenePlugin $insee): void
    {
        // Critères de filtrage Axion-IA pour entreprises nouvelles
        $axionPriorityNaf = NafSubclass::where('is_axion_priority', true)->pluck('code')->all();
        $yesterday = now()->subDay()->format('Y-m-d');

        // Construction query INSEE : entreprises créées hier, NAF prioritaire, effectif >= 5 employés
        $criteria = sprintf(
            'dateCreationEtablissement:%s AND activitePrincipaleEtablissement:(%s) AND trancheEffectifsEtablissement:[02 TO 53]',
            $yesterday,
            implode(' OR ', array_map(fn ($c) => "\"{$c}\"", $axionPriorityNaf))
        );

        $page = 0;
        $created = 0;
        while (true) {
            $req = new ScrapeRequest($ws->id, 'insee', null, ['siren_query' => $criteria], new PaginationMeta($page));
            $r = $insee->execute($req);
            if ($r->status !== 'ok') break;
            $newSiren = $r->payload['siren_count'] ?? 0;
            $created += $newSiren;
            if (!$r->pagination?->hasMore) break;
            $page++;
        }

        Log::info('INSEE poll OK', ['workspace' => $ws->slug, 'new_companies' => $created]);

        // Pour chaque nouvelle entreprise insérée, déclencher waterfall enrichment
        $newCompanies = Company::query()
            ->where('workspace_id', $ws->id)
            ->where('created_at', '>=', now()->subHours(2))
            ->whereNull('last_enriched_at')
            ->limit(500)
            ->get();
        foreach ($newCompanies as $c) {
            app(\App\Modules\Scraping\Orchestrator\WaterfallOrchestrator::class)->launch($c);
        }
    }
}
```

---

## 3. Job `PollBodaccSignalsJob`

```php
final class PollBodaccSignalsJob implements ShouldQueue
{
    public int $timeout = 3600;

    public function handle(BodaccPlugin $bodacc, BusinessSignalCreator $creator): void
    {
        $workspaces = Workspace::query()->where('status','active')->get();
        foreach ($workspaces as $ws) {
            $this->processWorkspace($ws, $bodacc, $creator);
        }
    }

    private function processWorkspace(Workspace $ws, BodaccPlugin $bodacc, BusinessSignalCreator $creator): void
    {
        // Itère par batch sur les SIREN actifs
        Company::query()
            ->where('workspace_id', $ws->id)
            ->whereNotNull('siren')
            ->whereNull('deleted_at')
            ->whereIn('priority_score', ['prioritaire','moyenne','faible'])   // skip non-cible
            ->orderBy('id')
            ->chunkById(5000, function ($companies) use ($ws, $bodacc, $creator) {
                $sirens = $companies->pluck('siren')->all();
                $events = $bodacc->fetchEventsForSirens($sirens, days: 7);

                foreach ($events as $event) {
                    $company = $companies->firstWhere('siren', $event['registre']);
                    if (!$company) continue;
                    $signal = $this->classify($event);

                    $creator->create([
                        'workspace_id' => $ws->id,
                        'company_id' => $company->id,
                        'signal_type' => $signal['type'],
                        'signal_severity' => $signal['severity'],
                        'source' => 'bodacc',
                        'source_ref' => $event['url'] ?? null,
                        'occurred_at' => $event['dateparution'],
                        'expires_at' => now()->addDays(365),
                        'payload' => $event,
                    ]);

                    if ($signal['severity'] === 'critical') {
                        $this->notifyCriticalSignal($company, $signal, $event);
                    }
                }
            });
    }

    /** Classification simple. LLM use_case 'business_signal_detection' pour les cas ambigus. */
    private function classify(array $event): array
    {
        $description = $event['nature_annonce'] ?? '';
        if (str_contains(strtolower($description), 'redressement')) {
            return ['type' => 'redressement', 'severity' => 'high'];
        }
        if (str_contains(strtolower($description), 'liquidation') || str_contains(strtolower($description), 'radiation')) {
            return ['type' => 'radiation', 'severity' => 'medium'];
        }
        if (preg_match('/(levée|levee).*?(\d+(?:[,.]\d+)?)\s*M€?/i', $description, $m)) {
            $amount = (float) str_replace(',', '.', $m[2]);
            return ['type' => 'leve_fonds', 'severity' => $amount >= 1 ? 'critical' : 'high', 'amount' => $amount];
        }
        if (str_contains(strtolower($description), 'changement') && (str_contains($description, 'dirigeant') || str_contains($description, 'gérant'))) {
            return ['type' => 'change_dirigeant', 'severity' => 'high'];
        }
        if (str_contains(strtolower($description), 'création')) {
            return ['type' => 'create', 'severity' => 'low'];
        }
        return ['type' => 'autre', 'severity' => 'low'];
    }

    private function notifyCriticalSignal(Company $c, array $signal, array $event): void
    {
        $msg = sprintf(
            "🔴 *Signal critical* sur %s (SIREN %s)\n%s\n→ %s",
            $c->legal_name, $c->siren, $event['nature_annonce'],
            "https://crm.axion-ia.com/companies/{$c->uuid}",
        );
        SlackNotifier::send('#axion-crm-alerts', $msg);
        TelegramNotifier::send($msg);
    }
}
```

---

## 4. Job `PollFranceTravailClevelJob`

```php
final class PollFranceTravailClevelJob implements ShouldQueue
{
    public int $timeout = 3600;

    private const CLEVEL_TITLES = [
        'Directeur des systèmes d\'information', 'DSI',
        'Directeur Administratif et Financier', 'DAF',
        'Directeur des Ressources Humaines', 'DRH',
        'Chief Information Officer', 'CIO',
        'Chief Financial Officer', 'CFO',
        'Chief Marketing Officer', 'CMO',
        'Chief Data Officer', 'CDO',
        'Chief Technology Officer', 'CTO',
    ];

    public function handle(FranceTravailPlugin $ft, BusinessSignalCreator $creator): void
    {
        $workspaces = Workspace::query()->where('status','active')->get();
        foreach ($workspaces as $ws) {
            foreach (self::CLEVEL_TITLES as $title) {
                $offers = $ft->searchOffers([
                    'motsCles' => $title,
                    'datePublication' => 'PUBLIE_DEPUIS_24H',
                    'limit' => 500,
                ]);
                foreach ($offers as $o) {
                    if (!isset($o['entreprise']['siret'])) continue;
                    $siret = $o['entreprise']['siret'];
                    $siren = substr($siret, 0, 9);
                    $company = Company::query()->where('workspace_id', $ws->id)->where('siren', $siren)->first();
                    if (!$company) continue;
                    $creator->create([
                        'workspace_id' => $ws->id,
                        'company_id' => $company->id,
                        'signal_type' => 'recrut_clevel',
                        'signal_severity' => 'high',
                        'source' => 'france_travail',
                        'source_ref' => $o['id'] ?? null,
                        'occurred_at' => $o['datePublication'] ?? now(),
                        'expires_at' => now()->addDays(180),    // 6 mois validité signal
                        'payload' => $o,
                    ]);
                }
            }
        }
    }
}
```

---

## 5. Job `ScrapeFrTechNewsJob`

Crawler hebdo Playwright sur frenchweb.fr + maddyness.com pour matchs avec mots-clés "levée", "fonds", "tour de table", "M€", "Series A/B/C".

```php
final class ScrapeFrTechNewsJob implements ShouldQueue
{
    public int $timeout = 1800;

    public function handle(FrTechNewsCrawler $crawler, LlmRouter $llm, BusinessSignalCreator $creator): void
    {
        $articles = $crawler->fetchRecentArticles([
            'sources' => ['frenchweb.fr', 'maddyness.com'],
            'keywords' => ['levée', 'levee', 'fonds', 'tour de table', 'series a', 'series b', 'series c'],
            'days' => 7,
        ]);

        foreach ($articles as $a) {
            // Extraction via LLM : entreprise + montant
            $extraction = $llm->generate('business_signal_detection', [
                'article_title' => $a['title'],
                'article_body' => $a['excerpt'],
            ], workspaceId: null);

            if (empty($extraction->structured['company_name']) || empty($extraction->structured['amount_eur'])) continue;

            // Match SIREN approximatif
            $company = Company::query()
                ->whereRaw("similarity(LOWER(unaccent(legal_name)), LOWER(unaccent(?))) >= 0.85", [$extraction->structured['company_name']])
                ->first();
            if (!$company) continue;
            $amount = (float) $extraction->structured['amount_eur'];
            $creator->create([
                'workspace_id' => $company->workspace_id,
                'company_id' => $company->id,
                'signal_type' => 'leve_fonds',
                'signal_severity' => $amount >= 1_000_000 ? 'critical' : 'high',
                'source' => 'news_fr',
                'source_ref' => $a['url'],
                'occurred_at' => $a['published_at'],
                'expires_at' => now()->addDays(365),
                'payload' => array_merge($a, ['llm_extraction' => $extraction->structured]),
            ]);
        }
    }
}
```

---

## 6. Service `BusinessSignalCreator`

```php
final class BusinessSignalCreator
{
    public function __construct(
        private CompanyBusinessSignalRepository $repo,
        private PriorityRecalculator $recalc,
    ) {}

    public function create(array $data): ?CompanyBusinessSignal
    {
        // Anti-doublon : même signal_type + même source + même occurred_at = déjà existant
        $existing = CompanyBusinessSignal::query()
            ->where('workspace_id', $data['workspace_id'])
            ->where('company_id', $data['company_id'])
            ->where('signal_type', $data['signal_type'])
            ->where('source', $data['source'])
            ->whereDate('occurred_at', $data['occurred_at']->toDateString())
            ->first();
        if ($existing) return $existing;

        $signal = CompanyBusinessSignal::create($data);

        // Recalculer priority_score + contact_priority de l'entreprise
        $this->recalc->recompute($signal->company_id);

        return $signal;
    }
}
```

---

## 7. Recalcul `priority_score` + `contact_priority`

```php
final class PriorityRecalculator
{
    public function recompute(int $companyId): void
    {
        $company = Company::find($companyId);
        if (!$company) return;
        $signals = CompanyBusinessSignal::where('company_id', $companyId)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
        $priority = (new \App\Modules\Classification\Services\PriorityCalculator)
            ->computePriorityScore($company, $signals);
        $contactPriority = (new \App\Modules\Classification\Services\PriorityCalculator)
            ->computeContactPriority($company, $signals);

        // Préserver override manuel
        if (!$company->priority_override) {
            $company->priority_score = $priority;
        }
        $company->contact_priority = $contactPriority;
        $company->save();
    }
}
```

---

## 8. Notifications Slack + Telegram pour signaux critical

```php
final class SlackNotifier
{
    public static function send(string $channel, string $message): void
    {
        Http::post(config('services.slack.webhook_url'), [
            'channel' => $channel,
            'text' => $message,
        ]);
    }
}

final class TelegramNotifier
{
    public static function send(string $message): void
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
```

Channels :
- Slack `#axion-crm-signals` pour all signals (high + critical)
- Slack `#axion-crm-alerts` pour critical only
- Telegram bot personnel Will pour critical only (3e canal)

---

## 9. Vue admin "Signaux récents"

Page sur `/companies?view=signals` listant les entreprises avec signaux actifs détectés < 30 jours, triés par severity + date. Filtres : type, severity, source.

Sidebar entreprise (page 5 fichier 13) affiche les 5 derniers signaux actifs avec couleurs (rouge / orange / jaune / gris).

---

## 10. Critères de done (S6 + S10)

- [ ] 16 jobs Scheduler programmés et tournent sans overlap
- [ ] PollInseeNewCompaniesJob détecte ≥ 50 nouvelles entreprises/jour matchant critères (validation manuelle 7 jours)
- [ ] PollBodaccSignalsJob insère < 1 % de doublons (anti-doublon strict)
- [ ] PollFranceTravailClevelJob détecte ≥ 30 recrutements C-level/semaine
- [ ] ScrapeFrTechNewsJob génère ≥ 10 signaux `leve_fonds` valides/semaine
- [ ] Notification Slack + Telegram reçue en < 5 min sur signal critical simulé
- [ ] `priority_score` recalculé automatiquement après chaque nouveau signal

---

## 11. Anti-patterns interdits

- ❌ Skip de l'anti-doublon (= flood signaux identiques)
- ❌ Recalcul `priority_score` sans préserver `priority_override`
- ❌ Job nightly sans `withoutOverlapping` (= 2 instances simultanées peuvent dupliquer)
- ❌ Notification Slack/Telegram pour signaux `low` ou `medium` (spam)
- ❌ Pas de TTL `expires_at` sur signaux (signaux "à vie" qui faussent priority_score)
- ❌ Crunchbase quotidien (anti-bot très agressif — hebdo max)

---

## Prochaine étape

→ Lire `21_couts_roadmap.md` pour les coûts mensuels détaillés et roadmap 12 semaines.
