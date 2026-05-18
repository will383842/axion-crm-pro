# Session 2026-05-18 — Sprint Hardening + H7 + H8 + H9 complet

> Récap de la session Will + Claude Opus 4.7 du 2026-05-18 (~08:00 à 09:00 UTC).
> 25 commits sur `origin/main`, du sprint Hardening initial à H9 inclus.

## Sprints exécutés

| Sprint | Objectif | Statut |
|---|---|---|
| **H1-H6** (16 commits) | Hardening initial Pipeline 360° (anti-bot, Hunter, INSEE filter, observability, scaling, rescrape) | ✅ Audité + déployé + smoke vert |
| **OBS-1, MSP-1, PROD-1** | Verification fixes (scope workspace, unused import, index PG IMMUTABLE) | ✅ Déployé |
| **H7** | MxEmailValidator maison (port emailValidator.ts) + politique "0 email spéculatif" | ✅ Déployé + testé en prod (`"role"` confirmé) |
| **H8** | Doctrine élargie : emails contactables = valid/catchall/unknown + email_generic | ✅ Déployé |
| **H9** | Google Places API server-side + runbook activation Pages Jaunes/Webshare | ✅ Code en main, attend API keys |

HEAD origin/main : `a7deab4`.

## Décisions doctrine Will

1. **Pas d'Hunter.io** jamais. Système de vérif email = MxEmailValidator maison (port du backlink-engine).
2. **Pas de patterns spéculatifs** dans EmailFinder. Seuls les emails RÉELS scrapés (mentions-légales) entrent en base. Flag `EMAIL_FINDER_SPECULATIVE_ENABLED=false` par défaut.
3. **Emails contactables élargis** : valid + catchall + unknown + email_generic → tous envoyables.
4. **Google Maps** : remplacé par API Places officielle (H9, légal, $0/mois jusqu'à 12K lookups grâce au crédit $200/mois Maps Platform).
5. **Pages Jaunes** : maintien via worker Node + Webshare proxy ($30/mois) en Phase B, activable plus tard.

## État prod actuel (https://app.axion-crm-pro.com)

### ✅ Actif et opérationnel
- INSEE Sirene (clé `INSEE_API_KEY` posée)
- France Travail OAuth (creds posées)
- Annuaire Entreprises (API publique gratuite)
- BODACC (API publique gratuite)
- BAN géocodage (API publique gratuite)
- LLM Mistral classification (~5€/mois)
- MxEmailValidator H7 (vérifié en tinker prod : `"role"` pour `info@example.com`)
- 3 supervisors Horizon dont `supervisor-audiences-refresh` (H5)
- 2 tables Hardening (`business_events`, `email_verification_logs`)
- Commande `companies:rescrape-archives` (H6, cron mensuel 1er à 2h)
- Filtre INSEE etatAdministratif='A' (H3, archive auto entreprises radiées)
- Sentry waterfall capture (H4, 8 catches enrichis)
- AuditLogger business_events (H4)
- Dashboard `/admin/observability` (H4)
- Bus::batch refresh audiences > 5K companies (H5)

### ⏭️ Code prêt, attend API keys
- Brave Search API (skip silencieux sans `BRAVE_SEARCH_API_KEY`)
- Hunter.io (skip silencieux sans `HUNTER_API_KEY`) — Will refuse de l'utiliser, donc pas concerné
- Google Places API H9 (skip silencieux sans `GOOGLE_PLACES_API_KEY`)
- Webshare proxy (skip silencieux sans `WEBSHARE_ENABLED=true`)
- EmailFinder spéculatif (off par défaut, à n'activer que si vérificateur SMTP réel wired)

### ❌ Mock (Phase B)
- Google Maps scraping Playwright Node worker (remplacé par H9 Places API)
- Pages Jaunes scraping Playwright Node worker (activable avec Webshare)

## Bugs trouvés et fixés pendant la session

| ID | Sévérité | Description | Commit fix |
|---|---|---|---|
| OBS-1 | P2 | ObservabilityController::countWaterfallErrors24h manquait scope workspace_id explicite | `6360e0c` |
| MSP-1 | P3 | MockServicesProvider import inutilisé `RealSmtpProber` | `d9e6f5f` |
| **PROD-1** | **P0** | Migration H2 crashait sur PG strict avec `date_trunc(timestamptz) not IMMUTABLE` | `a28fa74` |

Leçon CI : forcer les tests d'intégration migrations sur container Postgres en CI (et pas SQLite par défaut Pest) pour catcher ce type de divergence.

## Pipeline complet bout-en-bout (12 steps waterfall)

```
1.  INSEE             → SIREN, NAF, effectif, adresse, état admin (filtre H3)
2.  Annuaire Entrep.  → CA, bilans, dirigeants (noms)
3.  BODACC            → signaux : création/cessation/redressement
3b. DomainFinder      → siteweb : signals.legal OU Brave (skip si pas de key)
3c. MentionsLégales   → scrape footer/mentions → emails publics + tél (H7 validé MX)
3d. Google Places H9  → phone/website/address/lat/lon/rating (skip si pas de key)
4.  Workers Node      → pages-jaunes/website/google-search (skip si MOCK_SCRAPERS=true)
7.  EmailFinder       → DÉSACTIVÉ patterns spéculatifs (H7)
8.  BAN géocodage     → lat/long précis
9.  France Travail    → signal "recrute actuellement"
10. LLM Mistral       → classification, priorité, tags
10b. AutoClassifier   → tags geo/taille/secteur denormalisés
10c. AutoTagger       → tags structurés (dept-69, size-pme, sector-it…)
11. TriageAuto H8     → ready_for_outreach OU archived_no_email
12. AutoSegment       → ajout auto aux audiences qui matchent
```

## Antidoublon (4 niveaux)

1. Contrainte DB : `companies.siren` UNIQUE par workspace
2. `DeduplicationService::shouldRunScrape` cache horaire par siren+source
3. France Travail / INSEE dédupliquent par SIREN dans `extractUniqueEntreprises`
4. PG `INSERT ON CONFLICT DO NOTHING` sur contacts, audience_members, email_verification_logs

## Tri taille entreprise (auto via INSEE effectif_range)

| Effectif INSEE | Catégorie | Tag |
|---|---|---|
| 00-02 (0-5 salariés) | micro | `size-micro` |
| 03-12 (6-49 salariés) | tpe | `size-tpe` |
| 21-32 (50-249) | pme | `size-pme` |
| 41-51 (250-4999) | eti | `size-eti` |
| 52-53 (5000+) | grande | `size-grande` |

## Frontend status

| Page | Statut |
|---|---|
| `/campaigns/new` (wizard 4 étapes) | ✅ |
| `/campaigns` + `/campaigns/$id` | ✅ |
| `/companies` (4 tabs prospection_status + filtres taille/dept/secteur) | ✅ |
| `/companies/$id` (détail fiche enrichie) | ✅ |
| `/contacts` | ✅ |
| `/coverage` (carte zones) | ✅ |
| `/scraper-runs` (historique) | ✅ |
| `/audiences` + `/audiences/new` (builder DSL) + `/audiences/$id` | ✅ |
| `/tags` (groupés par catégorie) | ✅ |
| `/admin/observability` (KPI + 50 events) | ✅ |
| **Envoi campagne email** | ❌ Phase 2 ColdEmail stub 501 (sprint à venir) |

## Comportement quand Will coche TOUTES les sources du wizard

| Source cochée | Type | Effet en prod actuelle |
|---|---|---|
| INSEE Sirene | Discovery backend | ✅ Vrai scrape par dept → companies créées |
| France Travail | Discovery backend | ✅ Vrai scrape OAuth → companies créées |
| Google Maps | Discovery Node (mock=true) | ⚠️ ScraperRun cancelled, error="Phase B Webshare non activée". Pas de crash. |
| Pages Jaunes | Discovery Node (mock=true) | ⚠️ Idem ↑ |
| Annuaire | Enrichment only | ℹ️ Skip discovery, appliqué auto à chaque company via waterfall |
| BODACC | Enrichment only | ℹ️ Idem ↑ |
| BAN géocodage | Enrichment only | ℹ️ Idem ↑ |

**Note H9** : `Google Places API` enrichit aussi automatiquement chaque entreprise (peu importe ce qui est coché dans le wizard) — c'est une étape de **post-découverte**, pas de **découverte**. À condition que `GOOGLE_PLACES_API_KEY` soit posée.

## 25 commits de la session

```
75112ac feat(domain): remplace DuckDuckGo scrape par Brave Search API (H1)
a514863 feat(legal): rotation User-Agent + retry + Sentry MentionsLegalesScraper (H1)
1f1d4cc feat(http): ProxiedHttpClient + Pages Jaunes routing via Webshare (H1)
a6c4176 feat(email): HunterEmailVerifier API wrapper (H2)
10512fe feat(email): refactor EmailFinderService → Hunter via HunterSmtpProber (H2)
070eec2 feat(migration): email_verification_logs Hunter audit + quota (H2)
654a08b feat(insee): filtre etatAdministratif='A' actif partout (H3)
5fccb66 chore(db): script SQL backfill manuel entreprises radiées (H3)
6bfd400 feat(observability): Sentry capture standardisé waterfall (H4)
305716c feat(observability): AuditLogger + business_events systématiques (H4)
1c7cdea test(e2e): 3 specs Playwright smoke wizards critiques (H4)
4a7199e feat(ui): dashboard /admin/observability + endpoint summary (H4)
75c67c9 feat(audiences): Bus::batch parallèle pour refresh > 5K companies (H5)
437520c test(load): Artillery scenario + runbook load test API (H5)
b386b87 docs(audit): estimation coûts honnête 1M / 200K / 50K companies/mois (H5)
a3de1b3 feat(commands): RescrapeArchivesCommand code réel (H6)
6360e0c fix(observability): scope workspace_id explicit countWaterfallErrors24h (OBS-1)
d9e6f5f chore(provider): retire import inutilisé RealSmtpProber (MSP-1)
a0f8b7c docs(audit): sprint Hardening verification report + fixes log
8d9248d docs(audit): bilan smoke prod Hardening 2026-05-18
a28fa74 fix(migration): index sans date_trunc(timestamptz) — IMMUTABLE PG (PROD-1)
fdd4d38 feat(email): MxEmailValidator maison sans dépendance externe (H7)
5188f06 feat(triage): emails contactables élargis valid|catchall|unknown (H8)
a7deab4 feat(scraping): Google Places API client server-side + waterfall (H9)
```

## Actions humaines restantes Will

| # | Action | Coût | Priorité |
|---|---|---|---|
| 1 | Créer projet GCP `axion-crm-pro` + activer Places API + créer clé API + poser dans .env | $0 (crédit 12K free/mois) | 🟢 Recommandé |
| 2 | Créer compte Webshare Residential Premium + poser creds | $30/mois | 🟡 Optionnel (Pages Jaunes) |
| 3 | Configurer Sentry alerts (>10 errors/h → email) | $0 | 🟡 Optionnel |
| 4 | Surveiller CI workflows pré-existants cassés (`pnpm-lock.yaml` manquant) — fix chore séparé | — | 🟡 Optionnel |
| 5 | Implémenter envoi campagnes email réel (Mailgun/SendGrid + templates + tracking) | — | 🔴 Sprint futur |

## Verdict global

🟢 **Production stable et complète** pour les phases découverte + enrichissement + audiences.
Reste UNIQUEMENT l'envoi email réel (Phase 2 — sprint dédié à planifier).
