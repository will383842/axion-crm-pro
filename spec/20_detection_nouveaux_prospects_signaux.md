# 20 — Détection nouveaux prospects + signaux

> **Jobs nightly + hebdo** qui alimentent en continu la base d'entreprises ET détectent les signaux d'achat.
> **Notifications Slack + Telegram** pour signaux haute valeur (levée fonds, recrutement massif, nomination C-level).

---

## §1 — Job `discover:insee-new-companies`

### Fréquence

Nightly 02:00 UTC.

### Logique

```php
// app/Console/Commands/Discover/InseeNewCompaniesCommand.php
class InseeNewCompaniesCommand extends Command
{
    protected $signature = 'discover:insee-new-companies {--workspace=}';

    public function handle(InseeSirenScraper $insee, ZoneRotator $rotator): int
    {
        foreach (Workspace::active()->get() as $ws) {
            if ($this->option('workspace') && $this->option('workspace') !== $ws->slug) continue;
            $this->discoverForWorkspace($ws, $insee, $rotator);
        }
        return self::SUCCESS;
    }

    private function discoverForWorkspace(Workspace $ws, InseeSirenScraper $insee, ZoneRotator $rotator): void
    {
        // Discover créations dernières 24h sur AxionOfferTargets matching zones
        $targets = AxionOfferTarget::where('workspace_id', $ws->id)->where('is_active', true)->get();

        foreach ($targets as $target) {
            $query = new InseeQueryData(
                naf: $target->naf_subclasses_in[0] ?? null,
                department: null,                          // tout territoire
                effectif: implode(' OR ', $this->effectifsForOffer($target)),
                dateMin: now()->subDay()->toDateString(),  // créations nouvelles 24h
            );

            $results = $insee->searchByZoneAndSize($query);

            foreach ($results as $companyData) {
                $existing = Company::where('workspace_id', $ws->id)->where('siren', $companyData['siren'])->first();
                if ($existing) continue;   // dedup niveau 1

                $company = Company::create(array_merge($companyData, [
                    'workspace_id' => $ws->id,
                    'discovery_source' => 'insee_batch',
                    'prospection_status' => 'discovered',
                ]));

                // Auto-dispatch enrichment 1h après (politesse APIs)
                EnrichCompanyJob::dispatch($company->id)->delay(now()->addHour());
            }

            sleep(2);  // politesse INSEE
        }

        $this->info("Workspace {$ws->slug} : discovered {$ws->companies()->whereDate('first_seen_at', today())->count()} new today");
    }
}
```

### Volume attendu

~200-500 nouvelles entreprises/jour (cible Axion-IA, France métropole).

---

## §2 — Job `discover:bodacc-signals`

### Fréquence

Nightly 02:30 UTC.

### Logique

Pour chaque SIREN en base, fetch les annonces BODACC < 7j et détecte signaux.

```php
class BodaccSignalsCommand extends Command
{
    protected $signature = 'discover:bodacc-signals';

    public function handle(BodaccScraper $bodacc): int
    {
        $sirens = Company::where('deleted_at', null)
            ->whereNotNull('siren')
            ->where(function($q) {
                $q->whereNull('last_enriched_at')
                  ->orWhere('last_enriched_at', '<', now()->subDays(30));
            })
            ->pluck('siren', 'id');

        $sirens->chunk(100)->each(function ($chunk) use ($bodacc) {
            foreach ($chunk as $companyId => $siren) {
                $signals = $bodacc->fetchSignalsForSiren($siren);
                foreach ($signals as $signal) {
                    if ($signal->signal_score >= 70) {
                        event(new HighValueSignalDetected($signal));
                    }
                }
                usleep(100_000);   // 10 req/sec max BODACC API
            }
        });

        return self::SUCCESS;
    }
}
```

### Listener notifications

```php
class HighValueSignalListener
{
    public function handle(HighValueSignalDetected $event): void
    {
        $signal = $event->signal;
        $company = $signal->company;

        Notification::route('slack', config('axion.slack_signals_webhook'))
            ->route('telegram', config('axion.telegram_signals_chat'))
            ->notify(new HighValueSignalNotification($signal, $company));
    }
}

class HighValueSignalNotification extends Notification
{
    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text("📈 Signal {$this->signal->signal_type} sur {$this->company->legal_name}")
            ->attachment(fn($a) => $a
                ->title($this->company->legal_name)
                ->fields([
                    'Type'     => $this->signal->signal_type,
                    'Score'    => $this->signal->signal_score . '/100',
                    'Source'   => $this->signal->source,
                    'Date'     => $this->signal->detected_at->format('Y-m-d'),
                ])
                ->action('Voir fiche', config('app.url') . "/companies/{$this->company->id}"));
    }
}
```

---

## §3 — Job `discover:france-travail-hiring`

### Fréquence

Nightly 03:00 UTC.

### Logique

Cf. `05_scrapers_14_sources.md` § 10. Détecte les SIREN avec ≥ 5 offres cadres dernières 7j.

```php
class FranceTravailHiringCommand extends Command
{
    protected $signature = 'discover:france-travail-hiring';

    public function handle(FranceTravailScraper $ft): int
    {
        // 1. Découverte de nouveaux SIRENs avec recrutements massifs
        $offers = $ft->fetchAllRecentCadreOffers(daysBack: 7);

        // 2. Group by SIRET → SIREN
        $bySiren = collect($offers)->groupBy(fn($o) => substr($o['entreprise']['siret'] ?? '', 0, 9))
            ->reject(fn($_, $siren) => !$siren);

        // 3. Pour chaque SIREN avec ≥ 5 offres
        foreach ($bySiren as $siren => $offersGroup) {
            if ($offersGroup->count() < 5) continue;

            // Trouve OU crée company
            $company = Company::firstOrCreate(
                ['workspace_id' => $this->resolveWorkspace($siren), 'siren' => $siren],
                ['legal_name' => $offersGroup->first()['entreprise']['nom'], 'discovery_source' => 'france_travail_hiring']
            );

            // Crée le signal
            CompanyBusinessSignal::firstOrCreate([
                'company_id'  => $company->id,
                'signal_type' => 'hiring_surge',
                'detected_at' => today(),
                'source'      => 'france_travail',
            ], [
                'signal_score' => min(100, $offersGroup->count() * 10),
                'metadata'     => [
                    'offers_count' => $offersGroup->count(),
                    'titles' => $offersGroup->pluck('intitule')->take(5)->toArray(),
                    'departments' => $offersGroup->pluck('lieuTravail.libelle')->unique()->toArray(),
                ],
            ]);

            // Notification si fort signal
            if ($offersGroup->count() >= 15) {
                event(new HighValueSignalDetected(...$signal));
            }
        }

        return self::SUCCESS;
    }
}
```

---

## §4 — Job `discover:crunchbase-fundraising`

### Fréquence

Nightly 03:30 UTC.

### Logique

Pour chaque company en base classée NAF section J (Tech), check Crunchbase pour levées récentes.

```php
class CrunchbaseFundraisingCommand extends Command
{
    public function handle(CrunchbaseScraper $cb): int
    {
        $techCompanies = Company::whereRaw("LEFT(naf_subclass_code, 1) = 'J'")
            ->where(fn($q) => $q->whereNull('last_enriched_at')->orWhere('last_enriched_at', '<', now()->subDays(60)))
            ->limit(500)
            ->get();

        foreach ($techCompanies as $company) {
            try {
                $rounds = $cb->fetchRoundsForCompany($company);
                foreach ($rounds as $r) {
                    if ($r->date_announced > now()->subDays(90)) {
                        CompanyBusinessSignal::firstOrCreate([
                            'company_id' => $company->id,
                            'signal_type' => 'fundraising',
                            'detected_at' => $r->date_announced,
                            'source' => 'crunchbase',
                        ], [
                            'signal_score' => min(100, $r->amount_eur / 100000),
                            'source_url' => $r->url,
                            'metadata' => ['amount_eur' => $r->amount_eur, 'round' => $r->round_type, 'investors' => $r->investors],
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('crunchbase fetch failed', ['company' => $company->id, 'error' => $e->getMessage()]);
            }
            sleep(6);   // politesse Crunchbase, ~10 req/min
        }
        return self::SUCCESS;
    }
}
```

---

## §5 — Job `discover:news-french-tech` (hebdo)

### Fréquence

Hebdomadaire, lundi 04:00 UTC.

### Sources

- `https://www.frenchweb.fr/`
- `https://www.maddyness.com/`
- `https://www.usine-digitale.fr/`
- `https://www.usinenouvelle.com/`
- `https://www.lesechos.fr/tech-medias/`

### Logique

Pour chaque source, scrape RSS feeds → parse articles → LLM (use case `business_signal_detection`) → INSERT signals.

```php
class FrenchTechNewsCommand extends Command
{
    private const FEEDS = [
        'https://www.frenchweb.fr/feed',
        'https://www.maddyness.com/feed/',
        'https://feeds.feedburner.com/UsineDigitale',
        // ...
    ];

    public function handle(): int
    {
        foreach (self::FEEDS as $feedUrl) {
            $items = $this->parseRss($feedUrl);   // simplexml_load_file
            foreach ($items as $item) {
                if (Carbon::parse($item->pubDate)->lt(now()->subWeek())) continue;

                $articleHtml = Http::timeout(15)->get((string)$item->link)->body();

                $llmResp = app(LLMClient::class)->complete(new LLMRequestData(
                    useCaseSlug: 'business_signal_detection',
                    variables: [
                        'url'  => (string)$item->link,
                        'html' => Str::limit(strip_tags($articleHtml), 8000),
                    ],
                ));

                $parsed = json_decode($llmResp->text, true);

                // Si fundraising détecté
                if (!empty($parsed['fundraising']) && !empty($parsed['fundraising']['target_company_siren'])) {
                    $this->insertFundraisingSignal($parsed['fundraising'], (string)$item->link);
                }

                // Si nomination détectée
                foreach ($parsed['nominations'] ?? [] as $nom) {
                    $this->insertNominationSignal($nom, (string)$item->link);
                }
            }
        }
        return self::SUCCESS;
    }
}
```

---

## §6 — Re-enrichment jobs (TTL expirés)

### `enrich:re-enrich-stale`

Nightly 05:00 UTC.

```php
class ReEnrichStaleCommand extends Command
{
    public function handle(): int
    {
        $stale = Company::query()
            ->whereNull('deleted_at')
            ->where(fn($q) => $q
                ->whereNull('last_enriched_at')
                ->orWhere('last_enriched_at', '<', now()->subDays(90))
            )
            ->orderBy('last_enriched_at', 'asc nulls first')
            ->limit(5000)         // batch 5k/nuit max
            ->pluck('id');

        foreach ($stale as $companyId) {
            EnrichCompanyJob::dispatch($companyId)->delay(now()->addSeconds(rand(0, 14400)));   // étaler sur 4h
        }
        $this->info("Dispatched {$stale->count()} re-enrichments");
        return self::SUCCESS;
    }
}
```

---

## §7 — Notifications channels

### Slack

```php
// config/services.php
'slack' => [
    'signals_webhook'   => env('SLACK_SIGNALS_WEBHOOK'),
    'critical_webhook'  => env('SLACK_CRITICAL_WEBHOOK'),
    'default_webhook'   => env('SLACK_DEFAULT_WEBHOOK'),
],
```

Canaux :
- `#axion-crm-pro-signals` — signaux haute valeur
- `#axion-crm-pro-prod` — alertes infra
- `#axion-crm-pro-alerts` — alertes warnings

### Telegram

Bot dédié `@AxionCrmProBot`. Chats :
- Will direct → critical only
- Group `Axion CRM Pro Alerts` → all alerts

### Email

Pour rapports hebdo + RGPD requests.

---

## §8 — Rapport hebdomadaire Will (digest)

Job `report:weekly-digest` lundi 09:00 UTC :

```
Subject: 📊 Axion CRM Pro — Récap semaine 20

Hello Will,

Cette semaine sur Axion CRM Pro :

🟢 Nouvelles fiches complètes : 1 247 (+18% vs S19)
🟡 Nouvelles fiches partielles : 856
🔴 Nouvelles fiches basiques : 423

📈 Signaux business détectés : 78
   • 12 levées de fonds (top : Datalab 5M€, ...)
   • 38 recrutements massifs (top : Carrefour +52 offres, ...)
   • 28 nominations C-level

🎯 Top 5 ETI prioritaires découvertes
   1. ABCD Industries (Lyon) — match Mission ETI 92/100
   2. ...

💰 Coût LLM : 12.40€ (budget mensuel 60€, 21%)
🚦 Anomalies traitées : 3 (toutes resolved)

→ Voir dashboard : https://crm.axion-pro.com/dashboard
```

---

## §9 — Inventaire commands artisan

```bash
php artisan discover:insee-new-companies        # nightly
php artisan discover:bodacc-signals             # nightly
php artisan discover:france-travail-hiring      # nightly
php artisan discover:crunchbase-fundraising     # nightly
php artisan discover:news-french-tech           # weekly
php artisan enrich:re-enrich-stale              # nightly
php artisan proxies:health-check                # 2-hourly
php artisan search-engines:health-check         # hourly
php artisan email:update-disposable-list        # monthly
php artisan validators:check-blacklist          # hourly
php artisan rgpd:purge-stale-records            # nightly
php artisan rgpd:anonymize-old-ips              # nightly
php artisan audit:verify-chain                  # nightly
php artisan anomalies:detect                    # every 15 min
php artisan coverage:detect-duplicates-flags    # nightly
php artisan coverage:refresh-mv                 # hourly (extra à pg_cron)
php artisan analytics:snapshot-daily            # nightly (Phase 2 ready)
php artisan report:weekly-digest                # weekly Monday
```

---

## Lecture suivante

→ `21_couts_roadmap.md` (tableau coûts + roadmap 12 semaines).
