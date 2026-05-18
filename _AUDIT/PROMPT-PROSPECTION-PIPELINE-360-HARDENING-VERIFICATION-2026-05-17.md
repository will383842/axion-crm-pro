# Vérification + Auto-Fix Sprint Prospection Pipeline 360° HARDENING

> Audit profond bout-en-bout des **16 commits livrés** par le sprint Hardening
> du 2026-05-17, avant push origin/main + deploy prod.
> Mode **autopilot total avec auto-fix** des défauts P0 + P1.
> STOP & ASK uniquement sur lignes rouges explicitement listées §6.
> Créé : 2026-05-17 — à exécuter dans une **nouvelle conversation fraîche**.

---

## TL;DR

Tu es Claude (Opus 4.7 préféré, Sonnet 4.6 minimum), mode autopilot total.
**Mission** : vérifier que les 16 commits Hardening livrés sur la branche locale
sont production-ready (corrects, testés, sans régression, déployables) ET appliquer
les patches nécessaires pour atteindre l'état GO. Output final = verdict + patches
commités + push origin/main + deploy + smoke. Durée estimée 4-8h.

Le sprint Hardening corrige 6 angles morts critiques post-Pipeline 360° initial :
anti-bot Brave + Webshare, SMTP via Hunter.io, filtre INSEE actif partout,
observabilité Sentry+audit+Playwright+dashboard, scaling Bus::batch, et la
commande Artisan rescrape-archives.

## Contexte projet (à connaître impérativement)

- **Repo** : `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`
- **GitHub** : `will383842/axion-crm-pro` (public)
- **Stack** : Laravel 12 + PHP 8.3 + Postgres 16 + Redis + Horizon + React 19 + Vite 6
  + Tailwind v4 + TanStack Router/Query + MapLibre + lucide-react
- **Prod** : Hetzner CPX42 Helsinki, `https://app.axion-crm-pro.com`
- **DB prod** : user=`axion`, db=`axion_crm`
- **Owner** : Williams Jullin (`williamsjullin@gmail.com`, workspace UUID `1db106f5-c8a4-47b0-bf86-930f1ccc9f4a`)
- **Sentry DSN** : configuré côté backend (`SENTRY_LARAVEL_DSN`) + frontend (`VITE_SENTRY_DSN`)
- **Coolify** : autopilot pull main → build → migrate → reload
- **Branche** : `main` locale (16 commits **en avance** sur `origin/main`)

## Périmètre exact du sprint à auditer

**Range git** : `origin/main..HEAD` = 16 commits (du SHA `75112ac` au SHA `a3de1b3`).
Le SHA `origin/main` = `627c109` (commit prompts source). Tous les commits préfixés
par mention Sprint H1/H2/H3/H4/H5/H6 + Co-Authored-By Claude.

Sources de vérité :
- Prompt source du sprint : `_AUDIT/PROMPT-PROSPECTION-PIPELINE-360-HARDENING-2026-05-17.md`
- Prompt source initial : `_AUDIT/PROMPT-PROSPECTION-PIPELINE-360-2026-05-17.md`
- État pré-sprint référence : SHA `627c109` (origin/main)

## Mission — 4 phases obligatoires

### Phase 1 — Reality check (lecture seule, ~30min)

Avant toute analyse, **vérifie l'état réel du code** :

1. `git status` (assure-toi d'être bien sur main, working tree propre sauf WIP
   pré-existants : `docker-compose.observability.yml` modif + 2 fichiers non-trackés
   `setup_pg_partman_audit_logs.php` + `frontend/package-lock.json`)
2. `git log --oneline origin/main..HEAD --reverse` — confirme 16 commits Hardening
3. `git diff --stat origin/main..HEAD` — volume changements
4. Lis intégralement les **16 commit messages** : ils documentent l'intent + les choix.
5. Lis les fichiers **NOUVEAUX** du sprint (création) :
   - `backend/app/Services/Http/ProxiedHttpClient.php`
   - `backend/app/Services/Email/HunterEmailVerifier.php`
   - `backend/app/Services/Smtp/HunterSmtpProber.php`
   - `backend/app/Support/WaterfallSentry.php`
   - `backend/app/Support/AuditLogger.php`
   - `backend/app/Jobs/RefreshAudienceChunkJob.php`
   - `backend/app/Console/Commands/RescrapeArchivesCommand.php`
   - `backend/app/Http/Controllers/Api/ObservabilityController.php`
   - `backend/config/services.php`
   - `backend/database/migrations/2026_05_19_000001_create_email_verification_logs.php`
   - `backend/database/migrations/2026_05_19_000002_create_business_events.php`
   - `backend/database/scripts/backfill_archived_entreprises_radiees.sql`
   - `frontend/src/features/observability/ObservabilityPage.tsx`
   - `frontend/tests/e2e/{campaigns-wizard,audiences-builder,tags-manager}.spec.ts`
   - `load-tests/{audience-refresh.yml,LOAD-TEST-RUNBOOK.md}`
   - `_AUDIT/COST-ESTIMATION-1M-COMPANIES.md`
6. Lis les fichiers **MODIFIÉS** :
   - `backend/app/Services/Domain/DomainFinderService.php`
   - `backend/app/Services/Legal/MentionsLegalesScraperService.php`
   - `backend/app/Services/Email/EmailFinderService.php`
   - `backend/app/Services/FranceTravail/FranceTravailDiscoveryClient.php`
   - `backend/app/Services/Insee/HttpInseeClient.php`
   - `backend/app/Services/Audiences/AudienceBuilderService.php`
   - `backend/app/Services/Tags/AutoTaggerService.php`
   - `backend/app/Services/Triage/TriageAutoService.php`
   - `backend/app/Services/Waterfall/WaterfallOrchestrator.php`
   - `backend/app/Providers/MockServicesProvider.php`
   - `backend/app/Data/Sources/InseeCompanyData.php`
   - `backend/config/horizon.php`
   - `backend/routes/api.php`
   - `backend/tests/Unit/Domain/DomainFinderServiceTest.php`
   - `backend/tests/Unit/FranceTravail/FranceTravailDiscoveryClientTest.php`
   - `backend/tests/Unit/Email/EmailFinderServiceTest.php`
   - `frontend/src/app/routeTree.tsx`
   - `.env.example`

### Phase 2 — Audit ciblé (6 sprints + 6 transverses, ~2h)

Lance **12 sub-agents en parallèle** (1 par axe) pour couvrir tout le périmètre.

#### Axes par sprint hardening

**AGENT H1** — Anti-bot Brave + Webshare
- Vérifie que `DomainFinderService` ne contient plus AUCUNE référence à `searchDuckDuckGo`,
  `html.duckduckgo.com`, ni `decodeDuckDuckGoRedirect`
- Brave Search API : header `X-Subscription-Token`, retry sur ConnectionException
  uniquement, blacklist hosts (incluant `brave.com` lui-même)
- Pages Jaunes : appelé UNIQUEMENT si `config('services.scrapers.mock') === false`
- `ProxiedHttpClient::request()` appliqué (vérifier que `DomainFinderService::searchPagesJaunes`
  l'utilise au lieu de `Http::`)
- `MentionsLegalesScraperService::USER_AGENTS` = pool de 4+ UAs réalistes,
  `array_rand()` à chaque fetch
- `retry(2, 1000, ConnectionException)` actif
- `usleep(random_int(200_000, 800_000))` uniquement en `production`/`staging`
- Tests Pest existants (`DomainFinderServiceTest`) restent verts (les 6 tests)
- Score /50

**AGENT H2** — Hunter.io SMTP refactor
- `HunterEmailVerifier::verify()` : cache 30j, retry 2, Sentry capture sur 4xx/5xx
- Skip silencieux si `HUNTER_API_KEY` absent (`status='unknown'`, `reason='no_api_key'`)
- `email_verification_logs` upsert idempotent (`uniqueBy: workspace_id+email+provider`)
- `EmailFinderService::verifyEmail()` :
  - skip catchall providers en premier (gmail, outlook, etc.)
  - graceful unknown si HunterEmailVerifier non injecté
  - retourne valid|invalid|catchall|unknown|skipped_catchall_provider|invalid
- `EmailFinderService` ne contient PLUS `canProbeDomain`, ni `Redis::incr`, ni
  `smtp_probe_rate:` (vérif grep complet)
- `MockServicesProvider` : `SmtpProber::class` bind `HunterSmtpProber::class` en MOCK_SMTP=false
  (PAS RealSmtpProber)
- `RealSmtpProber` toujours présent en classe (rétro-compat) mais plus wired
- Migration `email_verification_logs` : RLS activée, 2 index, FK CASCADE
- Tests Hunter (4 tests Pest) couvrent : no_api_key, deliverable, http_error, cache 30j
- Score /50

**AGENT H3** — Filtre INSEE etatAdministratif='A'
- `InseeCompanyData` : champ `?string $etatAdministratif` nullable, default null
- `HttpInseeClient::fetchBySiren` propage etatAdministratif depuis
  `periodesUniteLegale[0].etatAdministratifUniteLegale` (fallback `uniteLegale.*`)
- `HttpInseeClient::searchByCriteria` propage etatAdministratif dans les 2 branches
  (/siret et /siren)
- `FranceTravailDiscoveryClient::filterActiveByInsee` :
  - `app(InseeClient::class)` (interface, pas implementation directe → testable mock)
  - skip si `$data === null` (siren inconnu INSEE)
  - skip si `etatAdministratif !== null && !== 'A'`
  - graceful : si InseeClient throw → candidat conservé (pas de blocage par INSEE down)
  - aucun enrichissement supplémentaire du candidat FT (juste filter)
- `WaterfallOrchestrator::enrich` court-circuite TOUS les steps si step1 retourne 'archived'
- `WaterfallOrchestrator::step1_insee` :
  - retourne string 'ok'|'archived'
  - `forceFill` avec `prospection_status='archived_no_email'` + `archive_reason='entreprise_radiee'`
  - constraint companies_archive_reason_check inclut bien 'entreprise_radiee' (vérifier migration
    `2026_05_18_000006_add_prospection_fields_to_companies` ligne 61)
- Script SQL backfill : ROLLBACK par défaut, UPDATE commenté (sécurité)
- Tests Pest FT (`FranceTravailDiscoveryClientTest`) : 2 nouveaux tests Sprint H3 +
  4 tests existants restent verts (MockInseeClient retourne etatAdministratif=null
  → pass-through correct)
- Score /50

**AGENT H4** — Observabilité Sentry + audit + Playwright + dashboard
- `WaterfallSentry::capture` : no-op si `\Sentry\State\Hub` absent (tolère CI sans Sentry SDK)
- `WaterfallOrchestrator` : 8 catches enrichis avec WaterfallSentry (verify grep)
- `AuditLogger::log` :
  - skip silencieux si `workspace_id` manquant (RLS impossible)
  - auto-resolve actor via Auth::user()
  - fail-open : Log::warning sur DB error, pas de propagation
- Migration `business_events` : RLS, 2 index, FK CASCADE, idempotent (IF NOT EXISTS)
- Intégration AuditLogger dans :
  - `AudienceBuilderService::refresh` → 'audience.refreshed'
  - `AutoTaggerService::syncTags` → 'company.tags_synced' (seulement si delta > 0)
  - `TriageAutoService::applyStatus` → 'company.archived' (seulement transition vers archived)
  - `HunterEmailVerifier::doVerify` → 'email.verified' (seulement si workspace_id fourni)
- Playwright specs (3 fichiers) : mocks `page.route('**/api/v1/...')`, expect URL correcte,
  pas de dépendance à un backend up
- `ObservabilityController::summary` : 5 KPIs + 50 recent events,
  fail-open `try/catch` sur business_events / email_verification_logs (peut être absent)
- `ObservabilityPage.tsx` : utilise KpiCard du design system (pas roll-your-own),
  refetchInterval 30s, tone amber > 80% quota
- Route `/admin/observability` ajoutée dans routeTree.tsx (vérifier la triple modif :
  import, createRoute, addChildren)
- Score /50

**AGENT H5** — Scaling Bus::batch + load test + cost doc
- `AudienceBuilderService::refresh` :
  - count total avant chunk → bascule Bus::batch si > 5000
  - sinon fast path inline chunkById(500) préservé
- `refreshViaBatch` :
  - delete + reset member_count à 0 (idempotent)
  - Bus::batch().allowFailures().finally() avec audit log selon hasFailures
  - queue 'audiences-refresh' explicite
- `buildPublicQuery` : juste wrapper public sur buildQuery private (zero duplication)
- `RefreshAudienceChunkJob` :
  - Batchable + ShouldQueue
  - skip si `batch()?->cancelled()`
  - skip si audience supprimée entre dispatch et run
  - DB::insertOrIgnore (pas Eloquent createMany)
  - WaterfallSentry sur exception + re-throw pour Horizon retry
- `horizon.php` :
  - supervisor 'supervisor-audiences-refresh' ajouté en defaults + production + local
  - queue 'audiences-refresh', tries 2, timeout 600, maxProcesses 4 (defaults), 10 (prod)
- `load-tests/audience-refresh.yml` : 3 scenarios (60/30/10%), 2 phases warmup+sustained,
  `ensure: p95 < 800` + `maxErrorRate 1`
- `load-tests/LOAD-TEST-RUNBOOK.md` : baselines p50/p95/p99, antipatterns, workflow
- `_AUDIT/COST-ESTIMATION-1M-COMPANIES.md` : 3 scénarios A/B/C honnêtes, leviers d'optim
  quantifiés, source links pour pricing
- Score /50

**AGENT H6** — RescrapeArchivesCommand
- Signature complète : `--limit`, `--workspace`, `--reason`, `--age-days`, `--dry-run`
- Validation `--limit` (1-5000) + `--reason` whitelist → exit code INVALID
- Throttle 2s entre dispatches (delay cumulatif)
- 4 tests Feature Pest : age filter, workspace filter, dry-run, invalid params
- Schedule `routes/console.php` reste inchangé (skip() s'auto-désactive quand commande existe)
- Vérifier que `Schedule::command('companies:rescrape-archives --limit=200')` est bien
  encore présent et fonctionnel
- Score /50

#### Axes transverses (qualité code + sécurité + déploiement)

**AGENT T1** — Gates statiques
- `php -l` sur 100% des .php modifiés/créés → 0 erreur
- `npx eslint` sur 100% des .ts/.tsx créés → 0 warning
- `pnpm typecheck` → si erreurs : isole celles introduites par le sprint vs pré-existantes
  (les 3 erreurs origin/main `AudienceBuilderPage` / `CampaignWizardPage` / `vitest.config`
  sont pré-existantes et acceptables)
- `composer audit` + `pnpm audit` → CVE H/C bloquantes ?
- Score /50

**AGENT T2** — RLS + multi-tenancy
- Toutes les nouvelles tables (`email_verification_logs`, `business_events`) :
  - `ALTER TABLE ... ENABLE ROW LEVEL SECURITY`
  - `CREATE POLICY ... FOR ALL USING (workspace_id::TEXT = COALESCE(NULLIF(current_setting('app.current_workspace_id', true), ''), workspace_id::TEXT))`
- ObservabilityController : queries `where('workspace_id', $workspaceId)` explicites
  (en plus de RLS) → defense in depth
- AuditLogger : skip si workspace_id manquant (impossible de scoper RLS)
- Score /30

**AGENT T3** — Tests Pest verts (CI compat)
- Lance `vendor/bin/pest --parallel` localement (ou docker compose exec api)
- Identifier les tests cassés introduits par le sprint
- Auto-fix les tests cassés (assertion désactualisée, mock à updater)
- Compter : N nouveaux tests / N régressions / N flaky
- Le total Pest historique = 206+ tests verts. Cible post-sprint : 220+ verts, 0 régression
- Score /50

**AGENT T4** — Schema migrations cohérence
- `php artisan migrate --pretend` doit afficher les 2 nouvelles migrations dans l'ordre
- Pas de FK manquante (workspaces.id, users.id référencés)
- Pas de doublon avec migration existante
- IF NOT EXISTS partout (idempotent re-run safe)
- down() reverse correctement
- Constraint `companies_archive_reason_check` accepte 'entreprise_radiee'
  (déjà OK dans `2026_05_18_000006` ligne 61, juste à vérifier)
- Score /30

**AGENT T5** — Conventions repo + naming
- Commits Conventional (feat/fix/docs/test/chore/perf/refactor) → 100% conformes
- Pas de `--no-verify`, pas de force-push
- i18n FR direct pour UI nouveau
- Imports `@/components/ui` exclusivement (pas relatif `../../`)
- lucide-react icons (pas emojis)
- Backend : FormRequest, Resource, Service, Job patterns respectés
- Score /20

**AGENT T6** — Anti-régression & dépendances
- Vérifier qu'aucun fichier sprint Pipeline 360° initial n'est cassé
- `DomainFinderService::find()` : signature inchangée (Company → ?string)
- `EmailFinderService` : constructeur 3 args (3e optionnel) → injection rétro-compat
- `HunterSmtpProber` retourne `SmtpProbeResult` valide (tous les champs Data setés)
- WaterfallOrchestrator : flow général inchangé (juste short-circuit step1 archived)
- Pas de nouveau composer/npm package requis (vérifier composer.json + package.json)
- Score /50

**Total scoring** : /500. Verdict :
- 🟢 GO : ≥ 450 ET 0 P0 ouvert
- 🟡 CONDITIONAL : 380-449 OU 1-2 P0 corrigeables en autopilot
- 🟠 SPRINT CORRECTIF : 300-379 OU 3+ P0 nécessitant Will
- 🔴 NO-GO : < 300 OU régression majeure prod

### Phase 3 — Auto-fix en autopilot (~2h)

Pour CHAQUE défaut P0 et P1 trouvé en Phase 2 :

1. Catégorise sévérité (P0 bloquant prod / P1 important / P2 V1.5 / P3 nice-to-have)
2. Pour P0+P1 : applique le patch en autopilot via Edit/Write
3. Ré-run le gate cassé pour confirmer fix
4. Commit Conventional dédié (`fix(domain): ...` etc.) avec ref au défaut
5. Ne JAMAIS modifier les 16 commits existants (créer nouveaux commits seulement)
6. Mode autopilot total — applique les fixes sans demander.

**STOP & ASK seulement si** :
- Le fix nécessite décision produit non triviale (cf. liste §6)
- Le fix risque de casser plus que de réparer (rollback préférable)
- Tu identifies un bug bloquant dans le sprint initial Pipeline 360° (hors scope hardening,
  Will doit décider)

Garde un changelog inline `_AUDIT/HARDENING-VERIFICATION-FIXES-2026-05-17.md` avec :
| Défaut | Sévérité | Fichier | SHA fix | Validation |

### Phase 4 — Push + deploy + smoke + rapport (~1h)

Après tous les fix appliqués + re-audit verdict 🟢/🟡 :

1. `git status` propre (sauf WIP pré-existants)
2. `git push origin main`. Si le harness bloque (cf. soft-block "push to default branch") :
   - **Option A préférée** : push direct (Will l'a explicitement autorisé)
   - **Option B fallback** : `git checkout -b feat/pipeline-360-hardening && git push -u origin HEAD`
     puis `gh pr create --title "Sprint Pipeline 360° Hardening" --body "..."`
     puis `gh pr merge --auto --squash` (si Will l'a autorisé) sinon laisser Will mergé manuellement
3. Si push main réussi → Coolify autopilot pull → wait deploy (5-10min)
4. **Smoke prod** (via SSH ou via le bloc "Commandes serveur de déploiement" du prompt source) :
   - `https://app.axion-crm-pro.com/up` → 200
   - `docker compose exec -T api php artisan horizon:list | grep audience` → supervisor up
   - `docker compose exec -T api php artisan companies:rescrape-archives --dry-run --limit=5` → 0 jobs
   - `docker compose exec -T postgres psql -U axion -d axion_crm -c "\dt email_verification_logs business_events"` → 2 tables
   - 1 vraie requête Brave + 1 vraie verify Hunter via tinker (seulement si les API keys
     ont été ajoutées en env vars Coolify par Will d'abord — sinon skip avec note)
5. **Rapport final** ≤ 600 mots écrit dans `_AUDIT/HARDENING-VERIFICATION-RAPPORT-2026-05-17.md` :
   - Verdict global /500 + statut 🟢/🟡/🟠/🔴
   - Top 5 défauts trouvés + fix appliqués (avec SHA commits fix)
   - Tests : N total / N nouveaux / N régressions corrigées
   - Smoke prod : OK / KO par check
   - Actions humaines restantes pour Will (API keys, Webshare activation, etc.)
   - Recommandations sprint suivant

## Méthodologie sub-agents

- **12 sub-agents Phase 2** lancés en parallèle (1 message, 12 Agent calls)
- Chaque sub-agent : lecture seule, scoring + liste défauts P0/P1/P2/P3,
  retour ≤ 500 mots structuré
- Phase 3 fix : 1 sub-agent par cluster de défauts cohérent (anti-bot, sentry, RLS, etc.)
- Sub-agents Phase 3 ont permission Write/Edit + commit autorisé

## Pré-requis avant lancer

```bash
# Branche main, 16 commits ahead expected
cd C:\Users\willi\Documents\Projets\Axion-CRM-Pro
git status
git log --oneline origin/main..HEAD | wc -l  # doit = 16

# Outils CLI nécessaires
php --version             # 8.3+
composer --version
node --version            # 22+
pnpm --version            # 9+
docker compose version    # optionnel si Pest local sans docker
gh --version              # pour PR si push main bloqué
```

Si l'un des 16 commits manque ou si origin/main ne pointe pas sur `627c109` → **STOP & ASK Will**.

## STOP & ASK explicites (lignes rouges)

1. **Migration risque de corrompre data prod** : ne PAS appliquer en prod sans confirmation
   (le `migrate --force` post-deploy est OK pour les 2 nouvelles migrations strictement additives,
   mais STOP si tu identifies un risque non documenté)
2. **Sentry capture de secrets en clair** : si tu vois un payload qui pourrait fuite
   API keys / passwords / PII non hashée → STOP, propose patch scrubbing
3. **Quota Hunter / Brave déjà épuisé** : si headers `X-RateLimit-Remaining` montrent 0
   → STOP, demande à Will avant smoke tests réels
4. **Régression silencieuse sprint initial Pipeline 360°** : si un test Pest existant
   casse à cause de mes modifs hardening (pas un changement d'API attendu) → STOP, analyse
5. **Branche divergente** : si origin/main a avancé pendant l'audit (pas attendu mais possible)
   → STOP, demande à Will comment résoudre (rebase ? merge ?)
6. **Push direct main refusé** : si même via PR le merge nécessite review humaine → STOP,
   transmet à Will la liste des SHA + suggère prochaine étape

Sinon : autopilot total, applique la décision la plus simple + documente dans commit message.

## Conventions repo (rappel)

- **Commits Conventional** : `feat`, `fix`, `docs`, `test`, `chore`, `perf`, `refactor`
- **Jamais** `--no-verify`, `--no-gpg-sign`, force-push main
- **Tests** : Pest backend + Vitest frontend + Playwright. Cible 220+ Pest verts post-fix
- **i18n** : tout texte UI nouveau en FR direct (pas i18next key avant Phase 2)
- **Design system** : imports `@/components/ui` exclusifs
- **Lucide-react** icons (pas emojis)
- **Backend** : FormRequest, Resource, Service, Job patterns
- **Migrations idempotentes** : `IF NOT EXISTS`, `Schema::hasTable()` guards
- **RLS** : policy workspace_isolation systématique sur toute nouvelle table

## Anti-régression critique (à vérifier explicitement)

- ⚠️ Sprint Pipeline 360° initial (21+ commits) doit rester fonctionnel
- ⚠️ Les 5 services nouveaux du sprint initial (AutoClassifier, AutoTagger, TriageAuto,
  AudienceBuilder, FranceTravailDiscovery) sont enrichis, pas remplacés
- ⚠️ `EmailFinderService::probeSmtp()` retiré → aucun appelant restant ne doit casser
- ⚠️ `EmailFinderService` constructeur a un 3e arg optionnel → instanciations existantes OK
- ⚠️ Fallback `HUNTER_API_KEY` absent → degraded `status='unknown'`, pas de crash
- ⚠️ Fallback `BRAVE_SEARCH_API_KEY` absent → degraded `null`, pas de crash
- ⚠️ Tests Pest historiques (206+) restent verts
- ⚠️ Playwright E2E ne tourne qu'en CI sur PR (pas chaque push) — vérifier `.github/workflows/`
- ⚠️ Audience refresh nouveau via Bus::batch — anciennes audiences refresh manuel marchent
- ⚠️ `RefreshAudienceChunkJob` idempotent : chunk re-run sans corrompre

## Output attendu

À la fin de l'audit, **dans la conversation Will** :

1. Verdict /500 + statut couleur
2. Lien vers `_AUDIT/HARDENING-VERIFICATION-RAPPORT-2026-05-17.md`
3. Lien vers `_AUDIT/HARDENING-VERIFICATION-FIXES-2026-05-17.md`
4. Liste des SHA des commits fix appliqués
5. URL prod déployée (si deploy réussi)
6. Liste des actions humaines restantes Will

**GO. Lance l'audit + fix + push + deploy + smoke + rapport. Autopilot total, STOP & ASK
uniquement sur lignes rouges §6.**
