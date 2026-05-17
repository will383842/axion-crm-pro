# Prospection Pipeline 360° — Documentation opérationnelle

> Sprint livré le 2026-05-18. Pipeline complet de découverte → enrichissement →
> classification → audiences, prêt pour campagnes email (envoi codé sprint ultérieur).

---

## Vue d'ensemble

```
┌────────────────────────────────────────────────────────────────┐
│  ENTRÉE — Will crée une campagne dans /campaigns/new           │
│  Choisit 1+ source DE DÉCOUVERTE :                              │
│    🟢 INSEE             (gratuit, actif)                        │
│    🟢 France Travail    (gratuit, actif — signal intent)        │
│    🔒 Google Maps       (Phase B — Webshare requis)             │
│    🔒 Pages Jaunes      (Phase B — Webshare requis)             │
└───────────────────────────┬────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│  DÉCOUVERTE multi-source (par zone × source)                   │
│  Pour chaque (department × source) → LaunchZoneScrapingJob     │
│    │ INSEE: InseeClient->searchByCriteria(dept, naf, limit)    │
│    │ France Travail: FranceTravailDiscoveryClient->...ByDept   │
│    │ Google Maps / PJ: DispatchScrapeJob → Node BullMQ Phase B │
│    │                   (MOCK_SCRAPERS=true → run skipped)      │
│  → upsert Company avec discovery_source                         │
│  → dispatch EnrichCompanyJob pour chaque                        │
└───────────────────────────┬────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│  WATERFALL ENRICHISSEMENT (toujours actif, 16 étapes)          │
│  ├─ 1.  INSEE refresh (denom, naf, effectif)                   │
│  ├─ 2.  Annuaire-Entreprises → dirigeants + CA + bilans        │
│  ├─ 3.  BODACC → annonces légales                              │
│  ├─ 3b. ▶ DomainFinder → website (cascade signals/DDG/PJ)      │
│  ├─ 3c. ▶ MentionsLegales → email_generic + phone + contacts   │
│  ├─ 4.  Node workers Phase B (GM/PJ/website/google-search)     │
│  ├─ 7.  Email finder + SMTP cascade (50/h/domain rate limit)   │
│  ├─ 8.  Géocodage BAN + signals.ban backfill                   │
│  ├─ 9.  France Travail signaux RH                              │
│  ├─ 10. Classification LLM Mistral                              │
│  ├─ 10b. ▶ AutoClassifier → denormalize geo+size+sector        │
│  ├─ 10c. ▶ AutoTagger → tags structurés auto+llm                │
│  ├─ 11. ▶ TriageAuto → prospection_status final                 │
│  └─ 12. ▶ AutoSegment → ajout aux audiences matchantes          │
└───────────────────────────┬────────────────────────────────────┘
                            ▼
┌────────────────────────────────────────────────────────────────┐
│  AUDIENCES ready pour campagnes email (Sprint futur)            │
│  /audiences/new (builder visuel live preview)                  │
│  → criteria JSONB → members synchronisés auto                   │
│  → cron daily refresh à 04:00 UTC                               │
└────────────────────────────────────────────────────────────────┘
```

---

## Comment Will crée sa 1ère campagne multi-source

1. Aller sur `/campaigns/new`
2. **Étape 1 — Identité** : nommer la campagne, choisir maintenant ou planifier
3. **Étape 2 — Zones** : sélectionner 1+ département (ex: 75 Paris)
4. **Étape 3 — Sources** : cocher INSEE + France Travail (les 2 activables).
   Note : Google Maps + Pages Jaunes sont grisées tant que Webshare pas configuré.
   Note 2 : Annuaire/BODACC/BAN s'appliquent automatiquement, pas besoin de les cocher.
5. **Étape 4 — Budget** : max 200 entreprises, max 30 min, max 20 req/min (anti-blacklist)
6. **Lancer** → redirection vers `/campaigns/{id}` avec live monitoring

Backend : `LaunchCampaignJob` crée N runs (1 par zone × source de découverte) →
chaque run dispatch `LaunchZoneScrapingJob` → chaque company découverte est
enrichie via `EnrichCompanyJob` qui orchestre le Waterfall 16 étapes.

---

## Comment Will crée une audience custom

1. Aller sur `/audiences/new` (sidebar section Communication)
2. **Builder visuel** (colonne gauche) :
   - Géographie : multi-select dept + region
   - Taille / Secteur : multi-select size_category + sector_main + tags slugs
   - Qualité : prospection_status + quality_score min slider + has_email toggle
3. **Live preview** (colonne droite sticky) :
   - Compte temps réel "X entreprises éligibles, Y contacts" (debounce 500ms)
4. **Créer** → l'audience est persistée + first refresh inline (rapide)
5. Voir détail sur `/audiences/{id}` → tabs Membres / Critères / Préparation campagne

À chaque company enrichie ensuite, `WaterfallOrchestrator.step12_auto_segment`
appelle `AudienceBuilderService.evaluateForCompany()` → ajout instantané aux
audiences matchantes.

---

## KPIs à surveiller

| Métrique | Où | Bon |
|---|---|---|
| `prospection_status = ready_for_outreach` | UI Companies tab "Prospectables" | ≥ 60% des enrichies |
| `archive_reason = no_email` ratio | DB query | < 30% (sinon source en échec) |
| Email Finder rate limit hits | Logs `EmailFinder rate limit reached` | < 5/jour |
| France Travail OAuth failures | Logs `FranceTravailDiscovery OAuth failed` | 0 |
| Audience refresh duration | `member_count` vs `refreshed_at` delta | < 30s pour < 10k companies |
| Waterfall completion time | enriched_at - created_at moyenne | < 5 min par company |

---

## Procédures de re-scrape

### Re-scrape une audience entière

```bash
docker compose exec -T api php artisan audiences:full-refresh --audience=42
```

### Re-scrape toutes les audiences (manuel, force)

```bash
docker compose exec -T api php artisan audiences:full-refresh
```

### Re-scrape les companies archivées sans email

```bash
# La commande companies:rescrape-archives sera livrée dans le Sprint Hardening H6.
# Workaround temporaire :
docker compose exec -T api php artisan tinker --execute='
\App\Models\Company::where("prospection_status", "archived_no_email")
    ->where("archive_reason", "no_email")
    ->where("updated_at", "<", now()->subDays(30))
    ->orderBy("updated_at")
    ->limit(200)
    ->get()
    ->each(function ($c) {
        \App\Jobs\EnrichCompanyJob::dispatch($c->id);
        sleep(2);
    });
'
```

---

## Cron schedulés (Sprint Pipeline 360°)

```php
// routes/console.php
Schedule::command('audiences:full-refresh')
    ->dailyAt('04:00')->withoutOverlapping()->onOneServer();

Schedule::command('companies:rescrape-archives --limit=200')
    ->monthlyOn(1, '02:00')->withoutOverlapping()->onOneServer()
    ->skip(/* auto-skip si commande pas encore codée */);
```

Vérifier sur prod :
```bash
docker compose exec -T api php artisan schedule:list | grep -E "audiences|rescrape"
```

---

## DSL `criteria` pour audiences

Format JSON :
```json
{
  "all": [
    { "field": "prospection_status", "op": "in", "value": ["ready_for_outreach"] },
    { "field": "department_code", "op": "in", "value": ["75","92","93","94"] },
    { "field": "size_category", "op": "in", "value": ["pme","eti"] },
    { "field": "tags", "op": "contains_any", "value": ["sector-it-saas"] },
    { "field": "has_email", "op": "eq", "value": true }
  ],
  "any": [],
  "not": []
}
```

**Whitelist fields** : `prospection_status, department_code, region_code,
commune_code, size_category, sector_main, priority, quality_score, tags,
has_email, enriched_at`

**Whitelist ops** : `eq, neq, in, not_in, gt, lt, gte, lte, contains_any,
is_null, is_not_null`

Tout `{field, op}` hors whitelist est silently skipped (anti-SQL injection).

---

## Activation prod step-by-step

### Phase déjà active (mockée) — par défaut

```
MOCK_INSEE=false        # INSEE Sirene activable sans risque
MOCK_FRANCE_TRAVAIL=false  # France Travail OAuth (clés déjà en .env prod)
MOCK_ANNUAIRE_ENTREPRISES=false
MOCK_BODACC=false
MOCK_BAN=false
MOCK_LLM=false          # Mistral API
MOCK_SCRAPERS=true      # Google Maps / PJ Node BullMQ → mock
MOCK_SMTP=true          # Email finder probe → mock
```

### Activation SMTP Phase C (post-merge stabilité)

Voir `_AUDIT/PROD-ACTIVATION-RUNBOOK.md` section "Phase C (SMTP probing)".

### Activation Phase B Webshare proxies (futur)

Voir `_AUDIT/PROD-ACTIVATION-RUNBOOK.md` section "Phase B".

---

## Smoke test post-deploy

```bash
ssh root@<HETZNER_IP>
cd /opt/axion-crm-pro

# Vérifier migrations OK
docker compose exec -T api php artisan migrate --force

# Vérifier scheduler
docker compose exec -T api php artisan schedule:list | grep -E "audiences|rescrape"

# Vérifier endpoints API (avec session cookie auth)
curl https://app.axion-crm-pro.com/api/v1/audiences  # → 200 ou 401 (auth manquante)
curl https://app.axion-crm-pro.com/api/v1/tags        # → 200 ou 401

# Vérifier UI (navigateur)
# - /tags : nouvelle page (section Data sidebar)
# - /audiences : nouvelle page (section Communication sidebar)
# - /audiences/new : builder visuel
# - /companies : 4 tabs prospection + 4 filtres nouveaux
# - /campaigns/new étape 3 : seulement 4 sources (annuaire/bodacc/ban retirés)

# Seeder audiences démo (optionnel)
docker compose exec -T api php artisan db:seed --class=DemoAudiencesSeeder --force
```

---

## Sprint Hardening (H1-H6) — suivant

Le sprint Hardening (`_AUDIT/PROMPT-PROSPECTION-PIPELINE-360-HARDENING-2026-05-17.md`)
durcit ce pipeline pour le passage à l'échelle :
- H1 : Brave Search API (remplace DDG scraping)
- H2 : Hunter.io email verifier (remplace SMTP probe direct)
- H3 : Filtre INSEE etatAdministratif='A' systématique
- H4 : Sentry sur tous nouveaux services + audit_logs + Playwright E2E
- H5 : Scaling 1M companies + load test Artillery
- H6 : Commande `companies:rescrape-archives` réellement codée
