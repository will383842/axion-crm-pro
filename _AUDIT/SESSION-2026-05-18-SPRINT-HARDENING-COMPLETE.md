# Session 2026-05-18 — Sprint Hardening H1 → H16 complet

> Récap final de la session Will + Claude Opus 4.7 du 2026-05-18.
> **36 commits** sur `origin/main`, du sprint Hardening initial à H16 inclus.
> HEAD : `0ae9de9`.

## Vue d'ensemble — 16 sprints livrés

| Sprint | Quoi | Statut |
|---|---|---|
| **H1-H6** (16 commits) | Hardening initial Pipeline 360° (anti-bot Brave + Webshare, Hunter ref refactor, INSEE filter, observability, scaling Bus::batch, RescrapeArchivesCommand) | ✅ Audité + déployé + smoke vert |
| **OBS-1, MSP-1, PROD-1** | Verification fixes (scope workspace, unused import, P0 index PG IMMUTABLE découvert en prod) | ✅ Déployé |
| **H7** | MxEmailValidator maison (port emailValidator.ts) + politique "0 email spéculatif" | ✅ Déployé + testé en prod (`"role"` confirmé) |
| **H8** | Doctrine élargie : emails contactables = valid/catchall/unknown + email_generic | ✅ Déployé |
| **H9** | Google Places API server-side + intégration waterfall step3d | ✅ Déployé + **clé API posée par Will** |
| **H10** | Paths scrape mentions-légales 8→18 + banner UX wizard détaillé | ✅ Déployé |
| **H11** | Retire Google Maps + Pages Jaunes du wizard (cases grisées inutiles) | ✅ Déployé |
| **H12** | Garde-fou quota mensuel Google Places (11500/mois) + retraitement différé + commande `companies:retry-google-places` | ✅ Déployé |
| **H13** | Google Places visible dans Settings + KpiCard quota dans /admin/observability | ✅ Déployé |
| **H14** | Smart skip Google Places si données vitales déjà présentes + alerte backlog | ✅ Déployé |
| **H15** | Activation Mistral réel via LLMRouterService + JSON mode + 2 fixes (response_format + recordUsage fail-open) | ✅ **Mistral actif en prod, JSON valide retourné** |
| **H16** | Smart skip Google Places sur email seul (politique Will : email = essentiel, reste = bonus) | ✅ Déployé |

## Décisions doctrine Will

1. **Pas d'Hunter.io** jamais. Vérification email = MxEmailValidator maison (port du backlink-engine).
2. **Pas de patterns spéculatifs** dans EmailFinder. Seuls les emails RÉELS scrapés (mentions-légales) entrent en base. Flag `EMAIL_FINDER_SPECULATIVE_ENABLED=false` par défaut.
3. **Emails contactables élargis** : valid + catchall + unknown + email_generic → tous envoyables.
4. **Google Maps** : remplacé par API Places officielle (H9, légal, $0/mois jusqu'à 12K lookups grâce au crédit $200/mois Maps Platform).
5. **Smart skip Google Places sur EMAIL SEUL** (H16) : skip dès qu'on a un email exploitable, peu importe phone/website. Économise drastiquement le quota.
6. **Pages Jaunes** : maintien via worker Node + Webshare proxy ($30/mois) en Phase B, activable plus tard.
7. **LLM Mistral primary** (H15) : pas d'Anthropic (key non posée, plus cher). Mistral small-latest sur tous les use cases JSON.
8. **Garde-fou quota Google** (H12) : jamais dépasser le crédit gratuit, retraitement différé automatique le mois suivant via cron.

## État prod (https://app.axion-crm-pro.com)

### ✅ Actif et opérationnel
- INSEE Sirene (clé `INSEE_API_KEY` posée)
- France Travail OAuth (creds posées)
- Annuaire Entreprises (API publique gratuite)
- BODACC (API publique gratuite)
- BAN géocodage (API publique gratuite)
- **Google Places API** (clé `GOOGLE_PLACES_API_KEY` posée, projet GCP `axion-crm-pro`)
- **Mistral LLM** (`MISTRAL_API_KEY` posée, JSON mode actif sur 7 use cases)
- MxEmailValidator H7 (DNS MX maison, vérifié en tinker prod)
- 3 supervisors Horizon dont `supervisor-audiences-refresh` (H5)
- 2 tables Hardening (`business_events`, `email_verification_logs`)
- Commande `companies:rescrape-archives` (H6, cron mensuel 1er à 2h)
- **Commande `companies:retry-google-places`** (H12, cron mensuel 1er à 3h)
- Filtre INSEE etatAdministratif='A' (H3, archive auto entreprises radiées)
- Sentry waterfall capture (H4, 8 catches enrichis)
- AuditLogger business_events (H4)
- Dashboard `/admin/observability` (H4 + H13 KpiCard Google Places quota)
- Bus::batch refresh audiences > 5K companies (H5)
- Smart skip Google Places (H14 + H16)

### 🟡 Configuré mais en mode dégradé (volontaire)
- Brave Search : `BRAVE_SEARCH_API_KEY` vide → DomainFinder stratégie 2 skip silencieux
- Hunter.io : refusé par Will → MxEmailValidator maison utilisé à la place
- Pages Jaunes : MOCK_SCRAPERS=true → Phase B (Webshare requis)
- Anthropic : `ANTHROPIC_API_KEY` vide → Mistral primary suffit

### ⚠️ Connu mais hors scope
- **Compte facturation Google = SOS-Expat.com** (mélange business — à séparer)
- **Clé Google Places brûlée dans transcript** — à régénérer
- **CI workflows GitHub Actions cassés** (pré-existant — `pnpm-lock.yaml` manquant)
- **Envoi email réel** : Phase 2 ColdEmailController stub 501 (sprint H17 à faire)

## Bugs trouvés et fixés pendant la session (6 P0/P1)

| ID | Sévérité | Description | Commit fix |
|---|---|---|---|
| OBS-1 | P2 | ObservabilityController::countWaterfallErrors24h manquait scope workspace_id explicite | `6360e0c` |
| MSP-1 | P3 | MockServicesProvider import inutilisé `RealSmtpProber` | `d9e6f5f` |
| **PROD-1** | **P0** | Migration H2 crashait sur PG strict avec `date_trunc(timestamptz) not IMMUTABLE` (raté par Pest local SQLite) | `a28fa74` |
| H15-1 | P0 | Bind LLMClient → MockLLMClient hardcodé même avec MOCK_LLM=false → aucun appel Mistral en prod | `9bae0fb` |
| H15-2 | P0 | MistralProvider envoyait response_format=null → API Mistral rejette 422 | `2ac5f3e` |
| H15-3 | P0 | recordUsage llm_usage workspace_id NULL violation → catch fait croire au router que provider failed | `b0079ca` |

Leçon CI : forcer les tests d'intégration migrations sur container Postgres en CI (et pas SQLite par défaut Pest) pour catcher ce type de divergence.

## Pipeline complet bout-en-bout (12 steps waterfall)

```
1.  INSEE             → SIREN, NAF, effectif, adresse, état admin (filtre H3)
2.  Annuaire Entrep.  → CA, bilans, dirigeants (noms)
3.  BODACC            → signaux : création/cessation/redressement
3b. DomainFinder      → siteweb : signals.legal OU Brave (skip si pas de key)
3c. MentionsLégales   → scrape 18 paths (H10) → emails publics + tél validés MX (H7)
3d. Google Places H9  → phone/website/address/horaires/note (smart skip H14+H16 si email présent)
4.  Workers Node      → pages-jaunes/website/google-search (skip si MOCK_SCRAPERS=true)
7.  EmailFinder       → DÉSACTIVÉ patterns spéculatifs (H7)
8.  BAN géocodage     → lat/long précis
9.  France Travail    → signal "recrute actuellement"
10. LLM Mistral       → JSON {ia_maturity, axion_offer_match, priority} (H15 actif)
10b. AutoClassifier   → tags geo/taille/secteur denormalisés
10c. AutoTagger       → tags structurés (dept-69, size-pme, sector-it…)
11. TriageAuto H8     → ready_for_outreach OU archived_no_email
12. AutoSegment       → ajout auto aux audiences qui matchent
```

## Tests live confirmés en prod

### Google Places (H9 — vérifié 10:30 UTC)
```
Query : "Boulangerie Dupont Paris"
Result:
  name    : Boulangerie Dupont
  phone   : +33 1 34 72 59 85
  website : http://www.facebook.com/BoulangerieDupont
  rating  : 4.1
  quota   : 1 / 11500
```

### Mistral LLM (H15 — vérifié 10:50 UTC)
```
Use case : classify_company_axion
Variables : Boulangerie Test, NAF 1071C, effectif 11
Provider : mistral (mistral-small-latest)
Cost     : 0.0001404 EUR (~0,014 centime)
Tokens   : 81 in / 207 out
Latency  : 1708 ms
Response : JSON valide {ia_maturity, axion_offer_match, priority: "moyenne"}
```

## Coûts opérationnels mesurés

| Volume scrapé/mois | Coût Mistral mesuré | Coût Google Places | Total opérationnel |
|---|---|---|---|
| 1 000 | ~0,14 € | 0 € (free tier) | ~0,14 €/mois |
| 10 000 | ~1,40 € | 0 € (smart skip H16 économise) | ~1,40 €/mois |
| 50 000 | ~7 € | 0 € (smart skip large) | ~7 €/mois |
| 100 000 | ~14 € | 0-50 € (selon ratio smart skip) | ~14-64 €/mois |

**Infra fixe** : 16 €/mois (Hetzner CPX22 + Storage Box backup).
**Total Phase A complète** : ~20-30 €/mois pour 50K entreprises B2B FR.

## 36 commits de la session (chronologique)

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
97c04eb docs(audit): session complete 2026-05-18 — Hardening + H7 + H8 + H9 récap
c5a851b feat(scraping+ux): paths mentions-légales 8→18 + banner wizard détaillé (H10)
1a1ebad feat(ux): retire Google Maps + Pages Jaunes du wizard step 3 (H11)
5caef11 feat(scraping): Google Places quota mensuel + retraitement différé (H12)
1191903 feat(ux): Google Places visible dans Settings + KpiCard quota /admin/observability (H13)
1c78df2 feat(scraping): smart skip Google Places si données vitales déjà présentes (H14)
9bae0fb feat(llm): activation Mistral réel via LLMRouterService (H15)
2ac5f3e fix(llm): Mistral response_format=null bug + JSON mode (H15 fix)
b0079ca fix(llm): recordUsage fail-open si workspace_id null (H15 fix #2)
8a1f502 docs(env): ajout 6 vars Phase A optimisée dans .env.example
0ae9de9 feat(scraping): smart skip Google Places sur email seul (H16)
```

(35 commits actifs + 1 commit final de mise à jour de cette doc)

## Voir aussi

- `_AUDIT/TODO-AXION-CRM-PRO.md` (créé en parallèle) — liste exhaustive de ce qu'il reste à implémenter
- `_AUDIT/SPRINT-H9-GOOGLE-PLACES-PAGES-JAUNES-ACTIVATION.md` — runbook activation Google Places + Pages Jaunes
- `_AUDIT/HARDENING-VERIFICATION-RAPPORT-2026-05-17.md` — verdict audit sprint Hardening initial
