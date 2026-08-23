# PROMPT — AUDIT 360° DE BOUT EN BOUT D'AXION CRM PRO, EN AUTOPILOTE, PAR 50 AGENTS HIÉRARCHISÉS

> **Version 2.1 — révisée le 2026-08-19.** À coller tel quel dans une session Claude Code neuve ouverte sur `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`.
>
> 🔴 **Ce qu'apporte la révision 2.1, et pourquoi elle était nécessaire.** La version 2.0 décrivait un dépôt qui n'existe plus : `main` a avancé deux fois, les deux PR d'étape 0 sont fusionnées, les 20 PR Dependabot ont disparu, et la **règle 6 de la doctrine affirmait une chose fausse** (« l'étape 0 n'est pas sur `main` »). Lancer 50 agents sur cette carte, c'était payer trois passes pour auditer un état révolu et récolter des constats faux. Corrections : **règle 6 réécrite**, **§3 remesuré**, **§3 bis** (les sept changements du 19/08), **§3 ter** (ne pas lancer en parallèle d'une session de construction), **§10** (nouveaux pièges mesurés). Tout y est mesuré, pas déduit.
>
> ⚠️ **Ce fichier n'est PAS versionné** (`??` dans `git status` au 19/08). Il pilote 50 agents et vit hors du dépôt : une mauvaise manipulation l'efface sans historique, et personne ne peut dire, après coup, quelle version a été jouée. **À committer.**
> **Objectif de sortie** : CRM Pro **parfait, complet, sans oubli, parfaitement organisé et simple à prendre en main**, prêt à recevoir le chantier du cahier des charges v2.7 (« CRM Pro, visio et suivi des contacts »). Tant que ce n'est pas vrai **et prouvé**, le travail n'est pas fini.
>
> **Ce document nomme tout ce qui doit être audité, un par un.** Les listes des §4 sont des **listes de travail**, pas des exemples : un élément listé qui n'apparaît pas dans le rapport final est un manquement de l'audit, pas un oubli acceptable. Elles ne sont pas non plus réputées exhaustives : le §4.0 impose de les **recompter dans le code** et d'auditer tout ce qui s'y ajoute.

---

## SOMMAIRE

1. Mandat, autorisation, règle d'arrêt
2. Doctrine — les dix règles qui priment sur tout
3. Contexte à charger avant de commencer
4. **L'inventaire — tout ce qui doit être audité, nommé**
5. **Les grilles d'audit obligatoires** (écran, route, table, automatisme, fonctionnalité, parcours)
6. **L'organisation de la console — le chapitre qui empêche le bordel**
7. L'organisation — 50 agents hiérarchisés
8. Déroulé — sept phases, trois vérifications
9. Livrables et format des constats
10. Pièges connus de ces dépôts
11. Ce qui doit être joué à la main dans un vrai navigateur
12. Définition de fini

---

## 1. MANDAT, AUTORISATION, ET RÈGLE D'ARRÊT

Tu es le **chef de chantier** d'une organisation de **50 agents d'IA spécialisés**. Tu conduis un **audit total, de bout en bout, du code réel** d'Axion CRM Pro et de tout ce qui s'y raccorde — puis tu **corriges** ce que l'audit trouve, puis tu **vérifies**, puis tu **contre-vérifies de façon adversariale**, puis tu **re-vérifies une troisième fois d'un œil neuf**.

**Autorisation explicite du dirigeant (Will), donnée le 2026-08-18 :**

- Tu travailles **en autopilote intégral**. Tu **ne poses aucune question**. Tu ne t'arrêtes pas pour demander un arbitrage.
- **Tu prends les décisions toi-même, selon tes propres recommandations**, et tu les consignes dans le registre de décisions avec leur justification.
- Tu peux **lire, mesurer, tester, corriger, refactorer, supprimer du code mort, écrire des tests, ouvrir des branches et des PR, fusionner** sur les deux dépôts.
- **Tu ne t'arrêtes pas tant que tout n'est pas terminé, corrigé, vérifié trois fois, et prouvé.**

**Les six seules choses que tu ne fais jamais** — elles ne t'arrêtent pas : tu les inscris dans `06_RESTE-WILL.md` avec ta recommandation, et tu continues tout le reste :

1. Supprimer ou muter des **données de production** (base CRM, base du site). Lecture et mesure autorisées, écriture non.
2. **Envoyer un e-mail, un SMS ou un WhatsApp réel à une personne réelle** (en particulier : l'étape 13, les 71 candidats du stock).
3. **Allumer un drapeau de production** qui change ce que voit un utilisateur ou un prospect (chatbot, `VIVIER_STOCK_ENABLED`, envoi de masse).
4. **Engager une dépense récurrente** ou souscrire un service payant.
5. **Valider** un document juridique ou de conformité (AIPD, mentions, CGU). Tu le **rédiges**, tu ne le valides pas.
6. Toucher aux **secrets de production** autrement qu'en vérifiant leur présence, leur portée et leur rotation.

Tout le reste est autorisé.

---

## 2. DOCTRINE — LES DIX RÈGLES QUI PRIMENT SUR TOUT

Un agent qui les viole rend un travail nul, à refaire.

1. **Le code fait foi. Les documents sont des hypothèses.** Ce prompt, les plans, les rapports, le CDC, la mémoire, les commentaires, `TODO.md`, `CHANGELOG.md` : **rien ne vaut preuve**. Toute affirmation — surtout « c'est déjà fait », « c'est couvert », « c'est corrigé » — est re-vérifiée **dans le code, par une exécution, ou par une mesure**. Un constat sans commande jouée n'existe pas.
2. **Une garde ne vaut que si on l'a vue rougir.** Pour tout test, contrainte, drapeau, validation, permission : casse la condition, **observe le rouge**, archive la sortie, répare. Un test jamais vu rouge ne garde rien.
3. **Témoin négatif obligatoire.** Un « rien trouvé » ne vaut que si tu as prouvé que le contrôle **aurait** trouvé un problème s'il existait. Un `grep` à 0 résultat doit d'abord être prouvé capable d'en rendre 1.
4. **Le geste réel avant l'instrumentation.** Chaque écran, chaque bouton, chaque parcours : joué **à la main dans un vrai navigateur** avant d'être figé en test. Un écran jamais ouvert n'est pas audité.
5. **Mesure, jamais supposition.** Performance, volume, parité, couverture, conformité : protocole, chiffres, sortie brute archivée. Interdit d'écrire « rapide », « couvert », « conforme », « fluide » sans nombre.
6. **Vérifier sur la bonne référence.** ⚠️ **Cette règle a été RÉÉCRITE le 2026-08-19 : sa version d'origine est devenue fausse.** Elle affirmait « l'étape 0 n'est pas sur `main` » et donnait `main = 7a0bfb2` — l'étape 0 **est sur `main`** depuis la fusion de la PR #174, et `main` a avancé deux fois depuis. **Ne raisonne sur AUCUN SHA écrit dans ce document : relis-le toi-même** (`git -C <dépôt> log --oneline -5`, `gh pr list --state open`) et note la référence réelle dans `01_INVENTAIRE.md` avant de lancer le moindre agent. Chaque constat dit sur quelle référence il tient. La CI évalue le commit de fusion, pas la branche. *La leçon est dans la règle 1 : ce document est une hypothèse, y compris quand il parle de lui-même.*
7. **Celui qui réalise ne vérifie jamais sa propre pièce.**
8. **On étend, on ne réinvente pas.** Avant d'écrire : chercher le composant, service, table, canal, test qui existe déjà. Réinventer est un défaut au même titre qu'un bug.
9. **Un désaccord ne se tranche pas en silence** : le chef de chantier tranche **par une mesure**, et l'archive.
10. **Rien n'est « fini » sans preuve archivée** dans `04_PREUVES/`.

---

## 3. CONTEXTE À CHARGER AVANT DE COMMENCER

Ne lance **aucun** agent avant d'avoir publié `01_INVENTAIRE.md`.

**Documents de référence (à lire intégralement) :**

- `C:\Users\willi\Downloads\axion-ia-crm-cahier-des-charges-fonctionnel-v2.md` — **CDC v2.7**, 983 lignes. La cible. Retenir en priorité : **§A** (l'existant), **§A.1** (15 fragilités préalables), **§0** (10 principes directeurs), **§1.5** (anatomie de la fiche), **§1.6** (complétude et modification), **§18** (recherche, vues, tableaux de bord), **§19** (console du CRM : 5 groupes + 8 sous-groupes), **§22** (contrat d'échange entre les deux consoles), **§23.1-23.5** (langage visuel, règles générales, **barre latérale figée**, 13 parcours mesurables, prise en main), **§25** (migration, réversibilité), **§27** (ordre de construction), **§28** (mode de réalisation), **§29** (25 critères mesurables).
- `Axion-IA/_PLANS/2026-08-18_PREALABLES-AVANT-CHANTIER-CRM-CIBLE.md` — plan d'étape 0, 16 lignes, critères de sortie. **Fait foi pour l'étape 0.**
- `Axion-IA/_SESSIONS/2026-08-18_PREALABLES-CRM-ETAPE-0.md` — journal d'exécution, §4 (ligne par ligne) et §8 (reprise).
- `Axion-CRM-Pro/_REPORTS/2026-08-17_CLOTURE-PLAN-CRM-E2E2.md` — 738 lignes, verdict **NON CLOS**, défauts D-01 → D-13.
- `Axion-IA/_PLANS/` : `2026-08-13_PLAN-CRM-contacts-candidats.md`, `2026-08-13_ORDRE-MISSION-AUTOPILOT-CRM.md`, `2026-08-13_CONCEPTION-console-crm-ux.md`, `2026-08-13_AUDIT-emails-site-vs-crm.md`, `2026-08-14_RUNBOOK-ACTIVATION-CRM.md`, `2026-08-14_SCENARIOS-E2E-CRM.md`, `2026-08-16_PLAN-MIGRATION-DECALAGE-2H-CRM.md`.
- `Axion-CRM-Pro/spec/` — 24 specs (`00_INDEX.md` → `24_frontend_design_system.md`) + `AUDIT_v1.md`. **`13_ui_admin_phase1.md` et `24_frontend_design_system.md` sont la référence de l'interface existante.**
- `Axion-CRM-Pro/_AUDIT/` (18 documents), `_REPORTS/{PROGRESS,VALIDATION_PLAN,DPIA_2026-05-17}.md`, `TODO.md`, `ARCHITECTURE.md`, `MOCKS-STRATEGY.md`, `CHANGELOG.md`.
- Mémoire de travail : `C:\Users\willi\.claude\projects\C--Users-willi-Documents-Projets-Axion-IA\memory\MEMORY.md` + les fiches indexées.

**Références de code :**

| Dépôt | Chemin | État |
|---|---|---|
| CRM | `C:\Users\willi\Documents\Projets\Axion-CRM-Pro` | distant `will383842/axion-crm-pro` — ⚠️ **le SHA écrit ici a été faux deux fois : relis-le** |
| Site | `C:\Users\willi\Documents\Projets\Axion-IA\axionia` | `will383842/axion-ia` |

🔴 **CE TABLEAU ÉTAIT FAUX SUR CINQ POINTS. Corrigé le 2026-08-19, mesuré, pas déduit.** Ce qu'il disait, et ce qui est vrai :

| Ce que ce document affirmait (18/08) | La réalité mesurée le 19/08 |
|---|---|
| `main = 7a0bfb2` | `main` a avancé deux fois : `e577828` (étape 0), puis `65e39a6` |
| PR CRM **#174 OUVERTE, non fusionnée** | **MERGED** — l'étape 0 est sur `main` |
| PR site **#735 OUVERTE** | **MERGED** |
| **20 PR Dependabot ouvertes** (#145 → #164) | **0** — plus aucune PR Dependabot ouverte |
| worktree `crmpro-wt-etape0` « occupé » | étape 0 close ; le worktree actif est `crmpro-wt-etape1a` |

⚠️ **L'ancienne « conséquence majeure » est morte, elle aussi** : elle disait que `frontend/src` ne contient aucun test et que les 40+ fichiers vivent sur la branche non fusionnée. **Mesuré le 19/08 sur `main` : `frontend/tests` existe et porte 37 fichiers de test.** Ne pas rapporter « le frontend n'est pas testé » sans avoir recompté.

**Ce qui reste vrai et qui mérite l'audit** : GitHub annonce **57 vulnérabilités** sur la branche par défaut (4 critiques, 18 hautes, 31 moyennes, 4 basses) — affiché à chaque `git push` du 19/08. Les PR Dependabot ont disparu ; **les alertes, non**. C'est un sujet entier pour le bloc F.

### 3 bis. 🔴 CE QUI A CHANGÉ DEPUIS LA RÉDACTION DE CE PROMPT — à lire avant tout

Entre le 18/08 au soir et le 19/08 au matin, sept choses ont bougé. Les ignorer ferait produire des constats déjà traités, ou pire, contredirait des décisions prises **sur mesure**.

1. **L'étape 0 est fusionnée et déployée.** Journal : `Axion-IA/_SESSIONS/2026-08-18_PREALABLES-CRM-ETAPE-0.md`.
2. **Le chantier du CDC a COMMENCÉ.** L'étape 1a (§27) est en cours, deux pièces livrées. **Journal de référence, source de vérité de l'avancement : `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md`.** Le lire AVANT d'auditer quoi que ce soit qui touche aux contacts, aux compteurs, aux activités ou aux motifs.
3. **Une faille critique de production a été trouvée, mesurée et fermée** le 19/08 : Postgres et Redis étaient publiés sur `0.0.0.0` (ports 55432/56379), joignables depuis internet, avec un mot de passe en clair dans un dépôt public — connexion superutilisateur à 4,29 M de fiches, **prouvée depuis l'extérieur**. Voir §5 du journal. **Ne pas re-rapporter comme « à faire » ; en revanche, les gestes ⑤ (rotation des secrets, à traiter comme COMPROMIS) et ⑥ (notification CNIL art. 33) sont OUVERTS et reviennent à Will.**
4. **🔴 Un défaut systémique du déploiement, à intégrer à toute analyse d'infrastructure** : `deploy-direct-ssh.yml` ne recrée que `api app horizon scheduler`, avec `--no-deps`. **Toute modification de `docker-compose*.yml` portant sur `postgres`, `redis` ou `reverb` est donc INAPPLICABLE par le déploiement** — ces conteneurs gardent la configuration de leur création, indéfiniment. C'est ce qui a fait qu'un correctif de sécurité fusionné et déployé avec succès n'a rien fermé du tout. Garde posée : `infra/scripts/verifier-ports-publies.sh`, qui mesure **les conteneurs**, pas le fichier. **Cherche les autres occurrences de ce patron** : quel autre réglage de ces trois services est écrit dans un fichier et absent du réel ?
5. **Une leçon de méthode qui doit guider le bloc H** : la garde CI `config-prod` est irréprochable — vue rouge dans deux modes de défaillance, témoin positif — et elle **mesure le mauvais objet** (le fichier, pas l'état qui tourne). Étendre la règle 2 de la doctrine : *une garde ne vaut que si elle rougit **sur l'objet qui casse***. Applique ce critère à chaque garde que tu inventories.
6. **Un inventaire de l'existant a déjà été produit** pour l'étape 1a : `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md`. Il contient trois résultats à ne pas redécouvrir, mais **à contre-vérifier** (règle 1 : il ne vaut pas preuve) :
   - **six tables mortes** — `crm_tasks`, `crm_notes`, `crm_pipelines`, `pipeline_stages`, `deals`, `deal_history`, `saved_views` n'ont **ni modèle, ni contrôleur, ni route, ni écran** ; seuls un contrôle de pentest, une aide de test et une garde de routage les nomment. Le bloc B doit trancher : réveiller ou retirer ?
   - **trois contradictions entre le CDC et le code** sur le type de contact : il est porté par `companies`/`candidates`, pas par la personne ; `Taxonomy::BUSINESS_RELATION_PRIORITY` impose un type unique « le plus engageant », à l'opposé du multi-types du §2.2 ; et deux valeurs (`conference`, `newsletter`) n'existent pas comme types dans le CDC, où « conférence » est un **motif**. Décision de Will du 19/08 : **réaligner en deux temps**, l'additif d'abord, le reclassement des 4,29 M de fiches ensuite.
   - **aucun hook Git n'est configuré dans ce dépôt** (`core.hooksPath` vide, `.git/hooks` sans hook actif) : la CI est le **seul** filet. Rien ne rattrape un format ou un lint avant le push.
7. **Telescope tourne en production sans sa table.** Chaque requête sur `/up` déclenche un `insert into telescope_entries` qui échoue — mesuré à **6 erreurs par minute, en continu**. Rien de fonctionnel n'est cassé, mais les journaux de production sont noyés, et une vraie erreur y passerait inaperçue. Défaut réel, déjà connu, **non corrigé**.

### 3 ter. ⚠️ NE PAS LANCER CET AUDIT EN MÊME TEMPS QU'UNE SESSION DE CONSTRUCTION

Ce prompt s'autorise à **fusionner et refactorer sur les deux dépôts** (§1). Une session de construction tourne sur `feat/etape-1a-*`. Deux sessions qui ouvrent, fusionnent et rebasent en parallèle se volent leurs branches — c'est arrivé le 18/08 et ça a coûté une heure. **Vérifie `gh pr list --state open` et l'état des worktrees `crmpro-wt-*` avant de commencer**, et ne touche pas à une branche que tu n'as pas ouverte.

---

## 4. L'INVENTAIRE — TOUT CE QUI DOIT ÊTRE AUDITÉ, NOMMÉ

### 4.0 Règle d'exhaustivité

Avant d'auditer, le gardien du contexte **recompte chaque liste ci-dessous dans le code** et publie l'écart. Les listes datent du 2026-08-18 sur `main = 7a0bfb2`. **Tout élément présent dans le code et absent d'ici doit être ajouté au périmètre.** Chaque liste devient un **tableau de suivi** dans `01_INVENTAIRE.md` avec une colonne « audité par / date / verdict / preuve » : **aucune ligne ne reste vide à la fin.**

### 4.1 Backend — les 21 modèles

`AudienceMember` · `AuditLog` · `Candidate` · `Company` · `Concerns` · `Contact` · `EmailAudience` · `HealthPractitioner` · `Journalist` · `LlmUseCase` · `Media` · `PersonalAccessToken` · `ProxyProvider` · `RgpdRequest` · `ScraperRun` · `ScrapingCampaign` · `Scopes` · `Tag` · `User` · `Workspace` (+ tout modèle ajouté depuis).

Pour chacun : grille **MODÈLE/TABLE** du §5.3.

### 4.2 Backend — les 44 contrôleurs

**Auth (4)** : `Api/Auth/AuthController`, `MagicLinkController`, `PasswordResetController`, `TwoFactorController`.
**Cœur (10)** : `ApiController`, `CompaniesController`, `ContactsController`, `CompanyTagsBulkController`, `TagsController`, `UsersController`, `WorkspaceController`, `GlobalSearchController`, `SavedViewsController`, `FeaturesController`.
**CRM console v2 (6)** : `Api/Crm/ContactsHubController`, `CandidatesController`, `ArbitrageController`, `PersonTimelineController`, `ConsoleController`, `BulkController`.
**Médias & couverture (4)** : `MediaController`, `JournalistsController`, `CoverageController`, `ReferentielsGeoController`.
**Collecte & IA (6)** : `ScraperRunsController`, `ScrapingCampaignsController`, `LlmUseCasesController`, `LlmUsageController`, `ProxyProvidersController`, `RotationsController`.
**Audiences & notifications (3)** : `AudiencesController`, `NotificationsController`, `ObservabilityController`.
**Conformité (3)** : `RgpdRequestsController`, `AiActRegisterController`, `AuditLogsController`.
**Phase 2 — stubs (5)** : `Api/Phase2/AnalyticsController`, `CampaignsController`, `ColdEmailController`, `CrmController`, `LinkedInController`. ⚠️ **Ce sont les routes 501 sous des noms que le CDC utilise (F7) : à retirer ou à réaliser.**
**Internes (3)** : `Internal/SiteSyncController`, `Internal/SiteGdprController`, `Internal/ScraperResultController`.

### 4.3 Backend — les ~110 points d'API (`routes/api.php`, 311 l.)

Chaque point subit la grille **ROUTE** du §5.2.

- **Auth** : `POST /auth/login` · `POST /auth/logout` · `GET /auth/me` · `POST /auth/onboarding/complete` · `POST /auth/2fa/{verify,setup,confirm}` · `POST /auth/magic-link` · `POST /auth/magic-link/verify` · `POST /auth/password/{forgot,reset}`
- **Public / token** : `GET /rgpd/export/{token}`
- **Pilotage** : `GET /dashboard/stats` · `GET /search` (déclaré **deux fois** — à vérifier) · `GET /config/features` · `GET /referentiels/geo` · `GET /observability/summary`
- **Espace & utilisateurs** : `GET|PUT /workspace` · `GET|POST /users` · `PUT|DELETE /users/{user}`
- **Entreprises** : `GET /companies` · `GET /companies/export` · `POST /companies` · `GET|PUT|DELETE /companies/{company}` · `POST /companies/{company}/enrich` · `POST /companies/bulk-enrich` · `POST /companies/{company}/recompute-score` · `POST /companies/tags/bulk`
- **Contacts** : `GET /contacts` · `GET|PUT|DELETE /contacts/{contact}`
- **Médias** : `GET /media` · `GET /media/export` · `GET /media/{media}` · `GET /journalists` · `GET /journalists/export` · `GET /journalists/{journalist}` · `POST /journalists/{journalist}/opt-out` · `DELETE /journalists/{journalist}`
- **Couverture** : `GET /coverage` · `GET /coverage/next-zone` · `POST /coverage/launch` · `POST /coverage/enrich` · `GET /coverage/cells/{cell}`
- **Collecte** : `GET /scraper-runs` · `GET /scraper-runs/{run}` · `POST /scraper-runs/{run}/cancel` · `POST /scraper-runs/{run}/retry` · `GET|POST /campaigns` · `GET|PUT|DELETE /campaigns/{campaign}` · `POST /campaigns/{campaign}/{start,pause,resume,cancel}` · `GET /campaigns/{campaign}/stats`
- **IA / infra de collecte** : `GET|PUT /llm/use-cases[/{useCase}]` · `GET /llm/use-cases/{useCase}/prompts` · `PUT /llm/use-cases/{useCase}/prompts/{v}` · `GET /llm/usage` · `GET /llm/usage/summary` · `GET /proxy-providers` · `PUT /proxy-providers/{p}` · `POST /proxy-providers/{p}/test` · `GET /rotations` · `PUT /rotations/{rotation}`
- **Tags & vues** : `GET|POST /tags` · `PUT|DELETE /tags/{tag}` · `apiResource /saved-views`
- **Audiences** : `GET|POST /audiences` · `POST /audiences/preview` · `GET|PUT|DELETE /audiences/{audience}` · `POST /audiences/{audience}/refresh` · `GET /audiences/{audience}/members`
- **Notifications** : `GET /notifications` · `POST /notifications/{n}/read` · `POST /notifications/read-all`
- **Conformité** : `GET|POST /rgpd/requests` · `POST /rgpd/requests/{req}/process` · `GET|POST /ai-act/register` · `GET /audit-logs` · `GET /audit-logs/verify-chain`
- **CRM console v2** : `GET /contacts-hub` · `GET /contacts-hub/counts` · `GET /candidates` · `GET /candidates/counts` · `GET /persons/{personKey}/timeline` · `GET /arbitrage` · `POST /arbitrage/{activityId}/attach` · `POST /arbitrage/{activityId}/dismiss` · `POST /bulk`
- **Internes (signés)** : `POST /site-sync` · `POST /site-sync/gdpr` · `POST /scraper-result`
- **Canaux temps réel** : `routes/channels.php` (55 l.) — autorisation de chaque canal, fuite inter-espaces.

### 4.4 Backend — les 68 services

**Auth (4)** : `AuthService`, `TwoFactorService`, `MagicLinkService`, `HibpChecker`.
**CRM / ingestion (`app/Crm`, 17 fichiers)** : `Ingest/{ContactUpserter, IngestOutcome, SiteSyncClassifier, SiteSyncEvent, SiteSyncIngestService, SiteSyncRejection}` · `Outbound/{ConsentOutboundRecorder, OutboundRejection}` · `Rgpd/SiteGdprService` · `Scraping/{DryRunRollback, EmailMxValidator, ScrapedRecord, ScrapedRecordIngestService, ScrapeIngestOutcome, ScrapeIngestRejection}` · `Console/ConsoleAccess` · `Taxonomy`.
**Audiences** : `Audiences/AudienceBuilderService` ⚠️ *(arbitrage `neq`/`not_in` sur NULL — décide à qui part un e-mail)*.
**Conformité** : `Rgpd/GdprErasureService`, `Rgpd/GdprPortabilityService`, `Audit/AuditHashChain`.
**Classification & tags** : `Classification/{AutoClassifierService, AutoTagApplier, ClassifierService}`, `Tags/AutoTaggerService`, `Triage/TriageAutoService`, `Prospection/SectorClassifier`, `Dedup/DeduplicationService`.
**Sources externes** : `Insee/*`, `AnnuaireEntreprises/*`, `Bodacc/*`, `Ban/*`, `FranceTravail/*` (+ leurs mocks).
**E-mail** : `Email/{EmailConfidenceService, EmailFinderService, HunterEmailVerifier, MxEmailValidator}`, `Smtp/{HunterSmtpProber, RealSmtpProber, Mocks/MockSmtpProber}`, `Domain/DomainFinderService`.
**LLM** : `LLM/{LLMRouterService, ProviderFactory}`, `LLM/Providers/{Anthropic, Groq, Mistral, OpenAI, Together}Provider`, `LLM/Mocks/MockLLMClient`.
**Réseau & collecte** : `Http/{ProxiedHttpClient, SsrfGuard}`, `Proxies/{IPRoyalProvider, WebshareProvider, Mocks}`, `Captcha/{TwoCaptchaSolver, Mocks}`, `Rotations/{SearchEngineRotator, WeightedRoundRobin, ZoneRotator}` ⚠️ *(piège du tirage pondéré en fractions)*, `Scraping/{GooglePlacesClient, Playwright{DirectionFinder, GoogleMapsScraper, PagesJaunesScraper, SearchEngine, WebsiteScraper}, Mocks}`, `Legal/MentionsLegalesScraperService`, `Waterfall/WaterfallOrchestrator`.

**Support (9)** : `AuditLogger`, `CompanyQueryFilters`, `ContactQueryFilters`, `EligibiliteCampagne`, `HmacSignature`, `ListeSuppression`, `MasquageCoordonnees`, `WaterfallSentry`, `WorkspaceContext`.
**Middleware (4)** : `SetCurrentWorkspace`, `EnsureCrmConsoleV2`, `EnforceFirstLoginSetup`, `AuditHashChainLogger`.
**Policies (11)** : `Base`, `AuditLog`, `Company`, `Contact`, `LlmUseCase`, `ProxyProvider`, `RgpdRequest`, `ScraperRun`, `Tag`, `User`, `Workspace`.
**Contracts (14)** · **Data (13)** · **Events (4)** : `CompanyEnriched`, `NotificationCreated`, `ScrapeJobCompleted`, `ScraperRunCancelled` · **Rules** : `NotPwnedPassword` · **Mail** : `MagicLinkMail` · **Providers (6)** dont `MockServicesProvider`, `TelescopeServiceProvider`, `HorizonServiceProvider`.
**Requests (4 seulement)** ⚠️ **44 contrôleurs pour 4 FormRequest : où est la validation du reste ? C'est un axe d'audit à part entière.**

### 4.5 Les automatismes — tout ce qui tourne sans qu'on le demande

**Tâches planifiées (`routes/console.php`)** — grille **AUTOMATISME** du §5.4 pour chacune :

| Tâche | Cadence | À vérifier en plus |
|---|---|---|
| `coverage:refresh-matrix` | horaire | durée, verrou, charge |
| `blacklists:check` | horaire | source, échec silencieux |
| `audit:verify-chain` | 03:00 | que fait-il si la chaîne est rompue ? alerte ? |
| `retention:purge` | 04:00 | **suppression de données** : périmètre, journalisation, réversibilité |
| `rgpd:anonymize-ips` | 04:30 | idem |
| `anomaly:detect` | /15 min | à qui parle-t-il ? |
| `signals:nightly-scan` | 02:00 | coût, volume |
| `campaigns:start-scheduled` | /min, `withoutOverlapping` | empilement, reprise après panne |
| `audiences:full-refresh` | 04:00, `onOneServer` | durée au volume réel |
| `companies:rescrape-archives` | mensuel, **auto-skip si la commande n'existe pas** | ⚠️ **une tâche qui s'auto-saute est un vert qui ne témoigne de rien** — la commande existe-t-elle ? |

**Jobs (7)** : `DispatchScrapeJob`, `EnrichCompanyJob`, `LaunchCampaignJob`, `LaunchZoneScrapingJob`, `MonitorCampaignProgressJob`, `RefreshAudienceChunkJob`, `Concerns/RunsInWorkspace` ⚠️ *(le contexte d'espace traverse-t-il la file ? un job sans espace lit-il tout ?)*.

**Les 49 commandes Artisan** — chacune est un automatisme ou un outil d'exploitation ; chacune doit être classée (utilisée / morte / dangereuse) et testée si elle mute des données. En particulier les **destructives** : `RetentionPurge`, `RgpdPurgeVivier`, `RgpdPurgeBusinessProspects`, `ProspectionPurgeNonCommercial`, `ProspectionPurgeNonDiffusible`, `AnonymizeOldIps`, `PruneScraperRuns`, `MediaCleanEmails`, `CorrigerHorodatages`. Et les **imports de masse** : `ImportNaf`, `ImportRpps`, `ImportIgnAdminExpress`, `ImportMedia*` (6), `ScrapingIngestFile`, `ScrapingBackfillSrcTags`.

**Canaux et webhooks** : `POST /site-sync` (entrant, HMAC), `POST /site-sync/gdpr`, `POST /scraper-result`, `crm_outbound_events` (sortant, 3 événements), file de rejeu côté site, `CRM_SYNC_ALERT`, alertes Telegram, `surveillance-sauvegarde.yml`.

### 4.6 Les workers de collecte (34 fichiers, `workers/src`)

`main.ts` · `healthcheck.ts` · `healthcheck-server.ts` · `bridge/{queues,redis,result-sender}.ts` · `browser/launcher.ts` · `utils/{extract,ssrf-guard}.ts`
**13 scrapers** : `google-maps` · `google-search` · `pages-jaunes` · `website` · `direction-finder` (chacun en `.worker.ts` + `.playwright.ts`) · `crunchbase` · `france-travail` · `infogreffe` · `mesri` · `societe-com` · `social-light` · `http-source` · `base` · `_stub`
**6 mocks** + `MOCKS-STRATEGY.md` ⚠️ *(un mock qui fuit en production est un défaut de sévérité S0 : vérifier que `MockServicesProvider` ne peut pas s'activer en prod).*

### 4.7 Frontend — les 30 écrans, les 33 composants

**Écrans (`frontend/src/features`)** — grille **ÉCRAN** du §5.1 pour **chacun**, sans exception :

| # | Route | Écran | Domaine |
|---|---|---|---|
| 1 | `/login` | `LoginPage` | auth |
| 2 | `/2fa` | `TwoFactorPage` | auth |
| 3 | `/magic-link` | `MagicLinkPage` | auth |
| 4 | `/password-reset` | `PasswordResetPage` | auth |
| 5 | `/` | `DashboardPage` (+ `ActivityFeed`, `NextActions`, `QualityDistributionBar`, `SizeDistributionChart`, `TopDeptsCard`) | pilotage |
| 6 | `/coverage` | `CoveragePage` + `FranceCoverageMap` | pilotage |
| 7 | `/campaigns` | `CampaignsListPage` | collecte |
| 8 | `/campaigns/new` | `CampaignWizardPage` **(assistant multi-étapes — parcours entier à jouer)** | collecte |
| 9 | `/campaigns/{id}` | `CampaignDetailPage` | collecte |
| 10 | `/scraper-runs` | `ScraperRunsPage` | collecte |
| 11 | `/companies` | `CompaniesListPage` (+ `CompanyRow`, `Pagination`) | données |
| 12 | `/companies/{id}` | `CompanyDetailPage` (+ `ContactsCard`, `EnrichmentTimeline`, `QualityScoreCard`) | données |
| 13 | `/contacts` | `ContactsListPage` ⚠️ **doublon de `/console/contacts`** | données |
| 14 | `/tags` | `TagsManagerPage` | données |
| 15 | `/international/roumanie` | `RoumaniePage` | international |
| 16 | `/media` | `MediaListPage` | médias |
| 17 | `/media/{id}` | `MediaDetailPage` | médias |
| 18 | `/journalists` | `JournalistsListPage` | médias |
| 19 | `/audiences` | `AudiencesListPage` | communication |
| 20 | `/audiences/new` | `AudienceBuilderPage` ⚠️ *(le constructeur, ses 3 chemins aveugles)* | communication |
| 21 | `/audiences/{id}` | `AudienceDetailPage` | communication |
| 22 | `/llm/router` | `LlmRouterPage` | IA |
| 23 | `/llm/proxy-providers` | `ProxyProvidersPage` | IA |
| 24 | `/llm/rotations` | `RotationsPage` | IA |
| 25 | `/rgpd/requests` | `RgpdRequestsPage` | conformité |
| 26 | `/rgpd/ai-act` | `AiActRegisterPage` | conformité |
| 27 | `/audit-logs` | `AuditLogsPage` | conformité |
| 28 | `/users` | `UsersPage` | admin |
| 29 | `/settings` | `SettingsPage` **(chaque onglet, chaque champ)** | admin |
| 30 | `/admin/observability` | `ObservabilityPage` | admin |
| 31 | `/console/contacts` | `ContactsHubPage` (+ `ConsoleGate`, `useConsoleFeatures`) | console v2 |
| 32 | `/console/vivier` | `CandidatesPage` | console v2 |
| 33 | `/console/arbitrage` | `ArbitragePage` | console v2 |
| 34 | `/persons/{key}` | `PersonTimelinePage` — **la fiche 360°, l'écran le plus consulté** | console v2 |
| 35 | `*` | `NotFoundPage` | — |
| 36-39 | `/cold-email`, `/linkedin`, `/crm`, `/analytics` | `ColdEmailStub`, `LinkedInStub`, `CrmStub`, `AnalyticsStub` ⚠️ **4 écrans factices** | phase 2 |

**Composants transverses (33)** — audités **une fois chacun**, puis vérifiés **partout où ils sont employés** (et surtout : là où ils **auraient dû** l'être et où quelqu'un a recopié du balisage à la main) :
`layout/{Sidebar, Header, UserMenu, WorkspaceSelector, AutoBreadcrumbs}` · `OnboardingTour` · `ui/{Avatar, Breadcrumbs, Button, Card, cn, DarkModeToggle, DropdownMenu, EmptyState, ErrorBoundary, FormField, GlobalSearch, IconButton, Input, KpiCard, Modal, PageHeader, PageShell, QualityBadge, SegmentedControl, SizeCategoryBadge, Skeleton, Spinner, Stat, StatusPill, Tabs, Toolbar, Tooltip}`.

**Socle** : `lib/{api, echo, i18n, prospection-referentiels, sentry}.ts` · `locales/{fr,en}.json` · `styles/` · `app/{RootLayout, routeTree}.tsx` · `eslint-suppressions.json` · `vite.config.ts` · `vitest.config.ts` · `playwright.config.ts` · `Caddyfile.app`.

### 4.8 La barre latérale réelle, telle qu'elle est aujourd'hui

**10 sections** (`components/layout/Sidebar.tsx`), une seule ouverte à la fois :

| Section | Entrées |
|---|---|
| **Console CRM** *(runtime, si drapeau)* | Contacts (`/console/contacts`) · À arbitrer · Vivier candidats *(conditionnel)* |
| **Pilotage** | Tableau de bord · Couverture France · **Campagnes** *(= campagnes de **collecte**)* · Runs de scraping |
| **Data** | Entreprises · **Contacts** *(`/contacts` — **2ᵉ entrée « Contacts »**)* · Tags |
| **International** | Roumanie |
| **Médias & Presse** | Médias · Journalistes |
| **Communication** | Audiences · 🔒 Templates email · 🔒 Envois email |
| **IA** | LLM Router · Proxies · Rotations |
| **Conformité** | Requêtes RGPD · Registre AI Act · Journaux d'audit |
| **Admin** | Utilisateurs · Paramètres |
| **Phase 2** | 🔒 E-mails à froid · 🔒 Prospection LinkedIn · 🔒 Pipeline CRM · 🔒 Analytique |

**Six entrées verrouillées** (cadenas + `Tooltip` « bientôt ») vers **quatre routes 501**. La **visite guidée** (`OnboardingTour` / `dataTour`) est câblée sur `nav-dashboard`, `nav-campaigns`, `nav-companies`, `nav-settings`. Le §6 de ce prompt traite ce sujet en entier.

### 4.9 Base de données — 54 migrations

Les dernières (socle CRM, août) sont les plus sensibles : `harden_workspace_isolation`, `crm_socle_taxonomie_business`, `crm_socle_vivier_candidats`, `crm_socle_tags_optout_timeline`, `crm_socle_index_concurrents`, `crm_scraping_sources`, `crm_outbound_events`, `email_suppressions`, `permission_contacts_view_pii`, `companies_entites_sans_siren`, `companies_foreign_id_unique_index`, `audit_logs_prev_hash_default`, `fixer_search_path_des_fonctions`, `companies_hub_tous_index`, `companies_hub_temperature_index`, `contacts_liste_console_index`, `companies_created_at_index`. **Chacune passe la grille §5.3** — y compris : « la migration est-elle rejouable ? réversible ? testée ? »

### 4.10 Infrastructure, CI/CD, exploitation

**17 workflows** : `ci.yml` · `a11y.yml` · `security.yml` · `deploy-staging.yml` · `deploy-direct-ssh.yml` · `build-postgres-image.yml` · `surveillance-sauvegarde.yml` · `release-tracking.yml` · `diag-website-status.yml` · `prospection-{collect, collect-paris, collect-region, enrich, find-websites, find-websites-distributed, reclassify, stats}.yml`.
Pour chacun : **qu'exécute-t-il réellement ? est-il bloquant ou décoratif (`continue-on-error`, `|| true`, `if:` toujours faux) ? a-t-il déjà tourné ? sur quelle branche ?**

**Infra** : `docker-compose.{yml, local, prod, test, observability}.yml` · `Dockerfile.{frontend, laravel, worker, postgres}` · `infra/{caddy, nginx, docker, php, postgres, monitoring, runbooks, scripts, terraform, loadtest}` · `Makefile` · `load-tests/`.

### 4.11 Côté site (Axion-IA) — ce qui touche au CRM

`src/server/crm-sync/**` (+ ses 2 suites de test) · `src/server/actions/crm-sync/**` · `src/server/queue/workers/crm-sync-worker.ts` · `src/app/api/internal/crm-webhook/**` · `src/app/[locale]/(admin)/[adminPrefix]/synchro-crm/**` · `src/server/qualiopi/crm/**` · les **14 points de capture** · `capturer-lead.ts` (PII en clair) · `admin-submissions/actions.ts` (plafond d'export) · `admin-calendly/actions.ts` (statuts) · les **12 écrans « Contacts »** de la console axionia (Tout, Appels réservés, Messages, Clients, Presse, Partenariats, Investisseurs, Conférences, Recrutement, Podcast, Autres, Candidatures) et le module `calendrier` mort.

> **Rectification du 2026-08-22 — constat E32-001.** Cette liste se trompait sur
> 2 items sur 12 : elle citait « Rendez-vous + calendrier », qui n'est **aucune**
> entrée de navigation, et omettait « Tout ». Mesure, sur
> `axionia/src/lib/admin-nav.ts` : `grep -c 'group: "contacts"'` rend **12**
> (lignes 435, 441, 451, 461, 468, 475, 482, 489, 496, 507, 514, 522), dont
> l'entrée `label: "Tout"` vers `${base}/contacts` (l. 432-435) ; et
> `grep -in "calendrier\|rendez-vous"` ne rend que des **commentaires**
> (l. 32, 334, 360, 406, 415, 422). Le total reste 12 : un item retiré, un
> ajouté. Une liste d'écrans sert d'assiette à des décisions de retrait — s'y
> tromper, c'est auditer un écran qui n'existe pas et en oublier un qui existe.

---

## 5. LES GRILLES D'AUDIT OBLIGATOIRES

Chaque agent applique la grille de son objet, **point par point**, et rend un tableau où **aucune case n'est vide**. Une case « non vérifié » est acceptable et honnête ; une case absente ne l'est pas.

### 5.1 Grille ÉCRAN — 25 points, appliquée aux 39 écrans du §4.7

**Existence et intégrité**
1. L'écran s'ouvre-t-il réellement (geste réel, navigateur) ? Capture archivée.
2. Le titre dit-il ce qu'on fait ici, en français courant, sans terme technique ?
3. Fil d'ariane correct ? Retour arrière fiable (état, filtres, position de liste conservés) ?
4. Une seule **action principale** identifiable ?

**Données**
5. Chaque donnée affichée vient-elle d'où elle prétend ? (recouper avec l'API)
6. Formats : dates (fuseau !), nombres, montants, téléphones, noms propres, casse.
7. Pagination, tri, filtres : fonctionnent-ils réellement ? persistent-ils ? sont-ils dans l'URL (partageable, rechargeable) ?
8. Que se passe-t-il à 0, 1, 100, 10 000, 100 000 lignes ?

**Les cinq états obligatoires**
9. **Chargement** : squelette ou spinner, jamais un écran blanc ni un saut de mise en page.
10. **Vide** : dessiné, avec ce qu'il faut faire ensuite — jamais un tableau vide muet.
11. **Erreur** : message en langage courant, cause, action possible, pas de trace technique.
12. **Permission refusée** : explicite, sans cul-de-sac.
13. **Partiel / hors ligne** : ce qui manque est signalé.

**Actions**
14. Chaque bouton, lien, menu : fait-il ce qu'il annonce ? Testé un par un.
15. Un bouton est-il désactivé quand il doit l'être, et **sait-on pourquoi** ?
16. Retour immédiat sur action ; échec expliqué ; **annulation possible après action destructrice** ; pas de confirmation inutile.
17. Actions de masse : sélection, décompte exact, aperçu, réversibilité.

**Saisie**
18. Validation : messages utiles, au bon endroit, au bon moment. **Aucun refus silencieux** (piège connu).
19. Perte de saisie : rechargement forcé et coupure réseau pendant la frappe → reprise à l'identique.

**Forme**
20. Composants du design system réutilisés (§4.7) ou balisage recopié à la main ?
21. Mode sombre complet ; contrastes ; densité ; alignement des chiffres.
22. **Responsive 375 px** : lisible, utilisable, aucun débordement horizontal, cibles tactiles suffisantes.
23. **Clavier seul** : tout atteignable, focus visible, ordre logique, pièges de focus (modales) ; ARIA correct ; libellés de formulaire associés.
24. i18n : aucune chaîne en dur, `fr.json`/`en.json` cohérents, aucune clé manquante.

**Verdict**
25. **Un utilisateur non formé comprend-il cet écran ?** Ce qu'il ne comprendrait pas, nommé. Nombre de clics pour l'action principale.

### 5.2 Grille ROUTE API — 18 points, appliquée aux ~110 points du §4.3

1. Authentification exigée ? 2. Autorisation (policy) vérifiée **et testée** ? 3. Contexte d'espace appliqué ? 4. Un utilisateur d'un autre espace obtient-il 0 ligne (test qui rougit) ? 5. Validation des entrées (FormRequest ou inline — **44 contrôleurs, 4 FormRequest**) ? 6. Types, bornes, valeurs par défaut ? 7. Injection SQL / tri et filtres arbitraires ? 8. Pagination obligatoire sur toute liste ? 9. N+1 ? 10. Index derrière la requête (`EXPLAIN`) ? 11. Codes d'erreur et forme de la réponse cohérents avec le reste de l'API ? 12. Idempotence des `POST` qui créent ? 13. Journal d'audit pour toute écriture sensible ? 14. Données personnelles dans la réponse : nécessaires ? masquées ? (`MasquageCoordonnees`, permission `contacts.view_pii`) 15. Limitation de débit ? 16. Signature vérifiée pour les routes internes ? 17. Test automatisé existant, et **vu rouge** ? 18. Route morte, factice (501), ou dupliquée (`GET /search` déclaré deux fois) ?

### 5.3 Grille MODÈLE / TABLE — 14 points, appliquée aux 21 modèles et 54 migrations

1. Colonne d'espace présente ? RLS active ? `FORCE ROW LEVEL SECURITY` ? 2. Test d'étanchéité **par table** qui rougit sans contexte ? 3. Contraintes (non nul, unicité, clés étrangères, `CHECK`) réellement en base ? 4. Index couvrant les requêtes réelles des écrans ? 5. Types justes (dates en UTC, montants, énumérations) ? 6. Suppression : douce ou dure ? propagation ? orphelins ? 7. `casts`, accesseurs, `hidden` : une donnée personnelle peut-elle fuir par une sérialisation ? 8. Portées globales (`Scopes`) — que cachent-elles, et à qui ? 9. Migration rejouable, réversible, testée, non destructive ? 10. Volume réel et croissance. 11. Rétention : la donnée a-t-elle une durée de vie, et une purge qui l'applique ? 12. La table est-elle atteignable par l'export RGPD et par l'effacement ? 13. Partitionnement : `migrate:fresh` passe-t-il de zéro ? 14. Le modèle est-il utilisé, ou mort ?

### 5.4 Grille AUTOMATISME — 12 points, appliquée aux 10 tâches, 7 jobs, 49 commandes, workers, webhooks

1. Que fait-il exactement, en une phrase ? 2. Déclencheur et cadence réels (pas déclarés) — a-t-il **déjà tourné** ? 3. Que se passe-t-il s'il échoue : silence, alerte, réessai, empilement ? 4. Est-il idempotent ? réentrant ? verrouillé ? 5. Contexte d'espace : traverse-t-il la file, ou lit-il tout ? 6. Durée et coût au volume réel. 7. S'il **supprime ou modifie** : périmètre exact, journalisation, réversibilité, test. 8. S'il **s'auto-saute** (`skip`) : quand, et est-ce visible ? 9. Alerte en cas de non-exécution (« l'absence d'événement est un événement ») ? 10. Ses effets sont-ils observables quelque part par un humain ? 11. Est-il testé ? le test l'a-t-il été vu rouge ? 12. Est-il mort ?

### 5.5 Grille FONCTIONNALITÉ et **matrice de raccordement**

Pour chaque fonctionnalité (contacts, entreprises, vivier, arbitrage, timeline, tags, audiences, collecte, campagnes, médias, journalistes, couverture, RGPD, AI Act, audit, utilisateurs, réglages, observabilité, recherche globale, notifications, vues enregistrées, canal entrant, canal sortant) :

1. Ce qu'elle promet · 2. Ce qu'elle fait réellement · 3. L'écart · 4. Ses entrées (d'où viennent ses données) · 5. Ses sorties (qui les consomme) · 6. Ses états de bord · 7. Sa réversibilité · 8. Sa trace (journal) · 9. Son coût · 10. Ses tests · 11. **Son vocabulaire** (le mot employé ici est-il le même partout ailleurs ?) · 12. **Est-elle atteignable depuis la navigation ?** (une fonctionnalité qui existe mais qu'on ne trouve pas n'existe pas).

Puis **la matrice de raccordement** — le cœur de « l'harmonie » : un tableau fonctionnalité × fonctionnalité où chaque case dit ce qui circule, et où l'audit cherche :
- une donnée qui existe **des deux côtés** sans source unique (duplication) ;
- une action ici qui **devrait** mettre à jour là et ne le fait pas ;
- deux noms différents pour la même notion (`Contact` / `Candidate` / `Person` / `personKey` — que désignent-ils exactement ?) ;
- un même nom pour deux notions (« Campagnes » = collecte, mais bientôt e-mails ; « Contacts » = deux écrans différents) ;
- une fonctionnalité **orpheline** : rien n'y mène, rien n'en sort.

### 5.6 Grille PARCOURS — les 13 engagements du CDC §23.4

Chaque parcours est joué à la main, **clics comptés**, sur ordinateur **et** en 375 px, et comparé au budget du CDC : répondre à un message entrant (2 clics) · créer un contact complet (1 clic + saisie, aucun champ bloquant) · consigner un appel (1 clic) · lancer une visio (1 clic) · retrouver ce qui a été dit (< 10 s) · valider un compte rendu (1 écran) · envoyer le devis après un rendez-vous (1 clic vers la console) · traiter un appel support · prendre un appel de motif inconnu · déplacer un candidat d'étape · programmer un rappel · modifier un questionnaire · voir depuis la console qui est ce client (1 clic).

**Beaucoup n'existent pas encore** : c'est précisément le résultat attendu. Pour chacun : **existe / partiel / absent**, avec ce qui manque, et le budget mesuré quand il existe. C'est ce tableau qui dit si le CDC peut s'ouvrir.

---

## 6. L'ORGANISATION DE LA CONSOLE — LE CHAPITRE QUI EMPÊCHE LE BORDEL

**C'est un axe d'audit à part entière, pas un sous-produit du frontend.** Une console qui contient tout mais qu'on ne sait pas parcourir est un échec produit. Agents 22, 23, 24, 25 et 50, sous l'autorité du chef de chantier.

### 6.1 La cible — figée par le CDC §23.3

Cinq groupes, dans l'ordre de la journée, **jamais plus de sept entrées par groupe** :

```
AUJOURD'HUI   Tableau de bord du jour · Boîte de réception (n) · Mes rendez-vous (n) · Mes tâches (n)
CONTACTS      Tous les contacts · vues épinglées par type (Prospects · Clients · Presse · Partenaires ·
              Investisseurs · Fournisseurs) · Organisations · Vivier candidats (teinte différente) ·
              Prospection (masse froide, jamais mélangée) · À arbitrer (n)
ÉCHANGES      Tous les échanges · Comptes rendus à valider (n) · Enregistrements · Dossiers ouverts
PILOTAGE      Tableaux de bord · Canal avec la console · Coûts
RÉGLAGES      (console du CRM, §19 — 8 sous-groupes : Personnes et types · Entretiens ·
              Rendez-vous et rappels · Messages et modèles · Équipe et sécurité ·
              Données et conformité · Apparence · Intégrations)
──────────
↗ Console axionia · Rechercher (⌘K) · Fiches récentes
```

Règles du CDC : l'ordre et les libellés **ne changent jamais** ; **un seul mot par notion, partout** ; **un compteur seulement s'il appelle une action** (jamais un total décoratif) ; les types sont des **vues épinglées du même écran**, pas des pages différentes ; barre repliable aux mêmes positions ; sur téléphone, barre basse à cinq entrées ; l'espace de travail change la **teinte de toute la barre**.

### 6.2 L'existant — 10 sections (§4.8), et ce qu'on en sait déjà

Défauts **déjà identifiés** (à re-vérifier dans le code, pas à recopier) :
1. **Deux entrées « Contacts »** — `/contacts` (ancienne liste) et `/console/contacts` (hub cible).
2. **« Campagnes » = campagnes de collecte** — collision de nom frontale avec les campagnes d'e-mail à venir.
3. **Six entrées verrouillées** vers quatre routes 501 — une navigation qui mène à des cadenas.
4. **Une section « Phase 2 »** entière qui n'est que des promesses.
5. **Outillage de collecte au premier niveau** — « IA → LLM Router / Proxies / Rotations », « International → Roumanie ».
6. **Un groupe nommé « Data »** — mot technique, et qui contient les contacts.
7. **Dix sections** là où la cible en compte cinq.
8. **La visite guidée est câblée sur la barre actuelle** — elle devra être refaite **une fois**, sur la barre cible.
9. « Runs de scraping » — vocabulaire technique et anglicisme.
10. Le hub CRM v2 est derrière un **drapeau runtime** (`EnsureCrmConsoleV2`, `ConsoleGate`) : que voit un utilisateur si le drapeau est éteint ?

### 6.3 La méthode exigée — ce qu'il faut produire, pas seulement constater

1. **Inventaire des intentions.** Lister tout ce qu'un utilisateur vient faire dans cet outil (30 à 50 intentions en langage courant : « voir qui m'a écrit », « lancer une collecte sur l'Isère », « retrouver une personne », « régler qui reçoit quoi », « vérifier qu'un événement est bien arrivé »…). **Sortir cette liste du code**, écran par écran, action par action — c'est la seule façon de ne rien oublier.
2. **Rattacher chaque intention à un emplacement actuel** — et marquer : trouvable / trouvable avec effort / introuvable / impossible.
3. **Tri par carte inversé** : pour chaque entrée de menu actuelle, écrire ce qu'un nouvel utilisateur croirait y trouver. Tout écart entre la croyance et le contenu est un constat.
4. **Test des dix intentions** (critère 24 du CDC) : dix intentions, jouées **sans connaissance préalable**, chronométrées, avec le nombre de clics et les hésitations. Le seuil du CDC est **10/10 en moins de 5 s**. Si tu ne peux pas mobiliser trois personnes extérieures, la substitution acceptable est : **trois agents distincts, sans accès au code ni à la carte de navigation, qui reçoivent uniquement des captures d'écran** — et la substitution est déclarée comme telle dans le rapport, jamais présentée comme un test utilisateur.
5. **Règle du mot unique** : produire le **glossaire réel** de l'interface — chaque notion, tous les mots employés pour elle (dans la barre, les titres, les boutons, les colonnes, les messages, `fr.json`, et **dans la console axionia**). Tout doublon de sens et tout nom partagé par deux sens est un constat. Livrable : un tableau `notion → mot retenu → mots à éliminer → occurrences à corriger`.
6. **Règle des compteurs** : chaque compteur affiché doit appeler une action. Lister les compteurs décoratifs.
7. **Profondeur et largeur** : aucune fonction courante à plus de **deux niveaux** ; aucun groupe à plus de **sept entrées** ; **trois clics maximum** vers toute action courante (§23.2).
8. **Plan de navigation cible** : le tableau de correspondance **entrée actuelle → entrée cible** pour **les 10 sections et toutes leurs entrées**, avec, pour chacune : conservée / renommée / déplacée / fusionnée / supprimée / redirigée, et la redirection à écrire pour ne casser aucun lien existant.
9. **Ce qui disparaît ne se perd pas** : toute entrée retirée devient une redirection ou une vue épinglée. Aucun 404, aucun signet cassé, aucune fonctionnalité rendue introuvable.
10. **Refaire la visite guidée une seule fois**, sur la barre cible.
11. **Le même exercice pour la console axionia** : ses 12 écrans « Contacts » et leur trajectoire de retrait par paliers réversibles (§25.1 du CDC), **derrière un drapeau**, sans que formulaires, réservations, messages et alertes Telegram cessent d'arriver (critère 25).

### 6.4 Ce que l'audit doit trancher, écran par écran

Pour chacun des 39 écrans : **le garde-t-on tel quel, le fusionne-t-on, le renomme-t-on, le range-t-on ailleurs, ou le retire-t-on ?** Décision prise, justifiée, appliquée. Un écran qu'on garde « au cas où » sans savoir qui s'en sert est un constat, pas une décision.

### 6.5 Livrables propres à ce chapitre

`10_NAVIGATION-CIBLE.md` : intentions → emplacements · plan de navigation cible · tableau de correspondance complet · glossaire (notion → mot unique) · compteurs conservés/supprimés · redirections à écrire · résultat du test des dix intentions (avant / après) · captures avant / après de chaque section.

---

## 7. L'ORGANISATION — 50 AGENTS HIÉRARCHISÉS

**Règles :** le chef de chantier ne réalise pas ; un spécialiste ne vérifie jamais sa propre pièce ; un contradicteur reçoit **la pièce, le CDC et le code — jamais le raisonnement du réalisateur** ; un désaccord remonte et se tranche par une mesure.

**Rotation** (elle rend les 50 rôles réutilisables sur trois passes sans qu'un agent se juge lui-même) : constat de l'agent **N** → **vérifié** par **((N+17) mod 50)+1** → **réfuté** par **((N+29) mod 50)+1** → passe 3 : agents **neufs**, sans accès aux rapports.

**Chaque agent reçoit** : son périmètre **nominatif** (les listes du §4), sa grille (§5), la doctrine (§2), les pièges (§10), et l'interdiction de conclure sans commande jouée. **Chaque agent rend** : son tableau de grille complet, ses preuves brutes, ses constats au format §9, et la liste de ce qu'il n'a **pas** pu vérifier et pourquoi.

### Direction (4)

| # | Rôle | Mission | Livrable |
|---|---|---|---|
| 1 | **Chef de chantier** | Découpe, affecte, ordonnance les vagues, arbitre par la mesure, décide « livré / pas livré ». Ne réalise rien. | `05_DECISIONS.md` |
| 2 | **Gardien du contexte** | §3 et §4.0 : recompte chaque liste dans le code, publie les écarts, fournit à chaque agent son dossier (ce qui existe, ce qui est décidé, meilleures pratiques, pièges). Empêche toute réinvention. | `01_INVENTAIRE.md` |
| 3 | **Greffier** | Journal append-only horodaté ; matrice exigence → test → preuve. | `00_JOURNAL.md`, `03_MATRICE-EXIGENCES.md` |
| 4 | **Registrateur des constats** | Registre unique dédoublonné, identifiants stables, sévérité, référence, statut, verdicts des trois passes. | `02_CONSTATS.md` |

### Bloc A — Conformité au plan et aux documents (5)

| # | Rôle | Périmètre |
|---|---|---|
| 5 | Auditeur du plan du 13 août | L0 → L6 + lots site : chaque « livré » re-prouvé dans le code (migration, fichier, test, exécution CI, PR fusionnée) |
| 6 | Auditeur de l'étape 0 | Les 16 lignes du plan du 18 août, critère de sortie **mesuré** ; distingue `main` / branche / PR #174 / PR #735 / fusion |
| 7 | Auditeur des fragilités F1 → F19 | Chacune : encore vraie ? levée ? partielle ? sur quelle référence ? |
| 8 | Auditeur des non-régressions | La liste « ne doit pas régresser » du §A.1 : sauvegardes restaurables, horodatage UTC, CI réelle, isolation, formulaire du site — **rejouée, pas relue** |
| 9 | Auditeur des écarts document ↔ code | Toute affirmation des rapports, specs, `TODO.md`, `CHANGELOG`, commentaires, contredite par le code. Produit la liste des **affirmations fausses à corriger dans les documents** |

### Bloc B — Backend, données, canal (8)

| # | Rôle | Périmètre (§4) |
|---|---|---|
| 10 | Architecte du modèle de données | Les **21 modèles** et **54 migrations**, grille §5.3 ; `migrate:fresh` de zéro joué **deux fois** ; partitionnement ; `fixer_search_path_des_fonctions` |
| 11 | Auditeur du cloisonnement par espace | **Table par table** : sans contexte → 0 ligne ; contexte A → A seulement ; test qui **rougit** si on retire `FORCE RLS` ; `Scopes`, `SetCurrentWorkspace`, `WorkspaceContext`, `Concerns/RunsInWorkspace` ; le commentaire « permissive si non défini » de `health_practitioners` |
| 12 | Auditeur des routes API | Les **~110 points** du §4.3, grille §5.2 ; les **4 stubs 501** ; le `GET /search` déclaré deux fois ; **44 contrôleurs pour 4 FormRequest** |
| 13 | Auditeur du canal entrant | `SiteSyncIngestService`, `SiteSyncClassifier`, `ContactUpserter`, `HmacSignature` : signature, rejeu, idempotence, classement, horodatage UTC, rejets, journal |
| 14 | Auditeur du canal sortant | `crm_outbound_events`, `ConsentOutboundRecorder`, `CrmFlushOutbound` : émission, réessais, file morte, alertes, les 3 seuls événements, **ce qui devrait traverser et ne traverse pas** |
| 15 | Auditeur RGPD moteur | `GdprErasureService`, `GdprPortabilityService`, `SiteGdprService`, `ListeSuppression`, `MasquageCoordonnees`, `email_suppressions`, purges (`retention:purge`, `rgpd:*`), anti-réinsertion, adresse en clair dans `opt_out`, permission `contacts.view_pii` |
| 16 | Auditeur AI Act / audit / traçabilité | `AuditHashChain`, `AuditLogger`, `AuditHashChainLogger`, `audit:verify-chain` : complétude, immuabilité, **ce qui n'est pas journalisé et devrait l'être** ; registre AI Act |
| 17 | Auditeur des automatismes serveur | Les **10 tâches planifiées**, **7 jobs**, **49 commandes** — grille §5.4 ; en priorité les 9 destructives et la tâche qui s'auto-saute |

### Bloc C — Collecte et workers (4)

| # | Rôle | Périmètre |
|---|---|---|
| 18 | Auditeur du pipeline de collecte | `WaterfallOrchestrator`, `DeduplicationService`, `ScrapedRecordIngestService`, `EligibiliteCampagne`, `DryRunRollback`, campagnes, runs, registre des sources, arrêt d'urgence, reprise ; les **13 scrapers** du §4.6 |
| 19 | Auditeur sécurité et légalité de la collecte | `SsrfGuard` (backend **et** `workers/utils/ssrf-guard.ts`), `ProxiedHttpClient`, robots, quotas, en-têtes, captcha, base légale, tempo, `ProspectionPurgeNonDiffusible` |
| 20 | Auditeur enrichissement / classification / LLM | `LLMRouterService`, `ProviderFactory`, les 5 fournisseurs, `LlmUsage`, plafonds et coûts, `WeightedRoundRobin` / `ZoneRotator` / `SearchEngineRotator` ⚠️ **tirage pondéré en fractions → toujours la première clé, en silence** ; `AutoClassifierService`, `SectorClassifier`, `AutoTagApplier`, `TriageAutoService` |
| 21 | Auditeur de la qualité des données | Sur 4,3 M de lignes : doublons, tags (`Taxonomy`, backfill `src:`), translittération, complétude, `QualityScore`, encodage, **casse** (⚠ prod en locale `C`, `lower()` n'y a pas la même sémantique qu'en `en_US.utf8`), `companies_entites_sans_siren` |

### Bloc D — Interface, expérience, organisation (9)

| # | Rôle | Périmètre |
|---|---|---|
| 22 | Cartographe des écrans | Les **39 écrans** du §4.7 : route, composant, données, actions, permissions, états. Produit l'**inventaire des intentions** (§6.3-1). Aucun écran omis |
| 23 | **Architecte de la navigation** | Tout le §6 : sections, entrées, doublons, collisions, cadenas, profondeur, largeur, compteurs, glossaire, plan cible, correspondances, redirections, visite guidée |
| 24 | Auditeur des parcours | Grille §5.6 (13 parcours du CDC) + les parcours réels de l'outil existant : clics comptés, chemins, retours arrière, culs-de-sac, liens morts, état perdu |
| 25 | Auditeur des états d'écran | Les **cinq états** (§5.1 pts 9-13) sur les 39 écrans ; listes à 0 / 1 / 100 / 10 000 / 100 000 lignes ; tri, filtres, URL partageable |
| 26 | Auditeur des formulaires et de la saisie | Validation, messages, **refus silencieux**, sauvegarde, rechargement forcé, coupure réseau ; `FormField`, `Input`, `CampaignWizardPage` (assistant complet), `AudienceBuilderPage`, `SettingsPage` |
| 27 | Auditeur du design system | Les **33 composants** : réutilisés ou recopiés ? `PageShell`/`PageHeader`/`Toolbar` employés partout ? mode sombre complet ? `spec/24_frontend_design_system.md` respecté ? incohérences relevées écran par écran |
| 28 | Auditeur d'accessibilité | Clavier, focus, pièges de focus, ordre de tabulation, contrastes, ARIA, libellés, `ErrorBoundary`, `a11y.yml` **réellement exécuté** |
| 29 | Auditeur i18n et temps | `fr.json` / `en.json` : clés manquantes, chaînes en dur, pluriels ; formats ; **fuseaux et bascules d'heure** (le décalage de 2 h a déjà mordu) |
| 30 | Auditeur mobile et responsive | Les 39 écrans en **375 px** ; barre latérale repliée ; cibles tactiles ; tableaux larges ; la barre basse à cinq entrées du CDC |

### Bloc E — Console axionia (site) et raccordement (4)

| # | Rôle | Périmètre (§4.11) |
|---|---|---|
| 31 | Auditeur du côté site du canal | Les **14 points de capture**, `crm_sync_outbox`, `crm-sync-worker`, `crm-webhook`, réconciliation (5 familles), `CRM_SYNC_ALERT`, écran `synchro-crm` |
| 32 | Auditeur des 12 écrans « Contacts » de la console axionia | Doublon avec le CRM, module `calendrier` mort, frontière `booking`/`planning`, trajectoire de retrait §25.1 **derrière un drapeau réversible** |
| 33 | Auditeur des entrées de contacts | Calendly (réservé / honoré / annulé / absent, **`calendly_canceled` émis ou non**), formulaires (14 finalités), candidatures, newsletter, avis, chatbot (`capturer-lead.ts`, PII en clair), export CSV et **son plafond réel** |
| 34 | Auditeur de non-régression de la console | Devis, factures, échéanciers, sessions, Qualiopi, banque d'images, contenus : rien de cassé par les correctifs CRM |

### Bloc F — Sécurité et exploitation (6)

| # | Rôle | Périmètre |
|---|---|---|
| 35 | Auditeur d'authentification | `AuthService`, `TwoFactorService`, `MagicLinkService`, `PasswordResetController`, `HibpChecker`, `NotPwnedPassword`, `EnforceFirstLoginSetup`, `PersonalAccessToken` : sessions, expiration, révocation, verrouillage, énumération de comptes, rejeu de lien magique |
| 36 | Auditeur des permissions | Les **11 policies** × les ~110 routes × les rôles : créer un utilisateur restreint, **se connecter avec lui**, tenter d'atteindre ce qu'il ne doit pas. Test qui rougit. Permission `contacts.view_pii` |
| 37 | Auditeur des secrets et des échanges | Secrets, `HmacSignature`, en-têtes, CORS, CSP, cookies, TLS, `.env` versionnés, fuites dans les journaux, **`MockServicesProvider` activable en prod ?**, Telescope/Horizon exposés ? |
| 38 | Auditeur CI/CD | Les **17 workflows** du §4.10 : ce qu'ils exécutent **réellement**, `continue-on-error`, `|| true`, jobs jamais déclenchés, tests jamais exécutés (piège connu : `navigation.spec.ts` rouge en silence), protection de branche, file de fusion, préproduction |
| 39 | Auditeur sauvegardes et observabilité | `surveillance-sauvegarde.yml`, `infra/monitoring`, `infra/runbooks`, `ObservabilityController`, Sentry, alertes, seuils, **une sauvegarde restaurée pour de vrai** |
| 40 | Auditeur infrastructure et exposition | Les 5 `docker-compose`, 4 `Dockerfile`, `caddy`, `nginx`, `postgres`, `terraform`, `scripts` ; ports ouverts, `ufw`, `fail2ban`, versions ; ⚠ `docker compose restart` ne relit pas `env_file` (D-05) |

### Bloc G — Performance et charge (3)

| # | Rôle | Périmètre |
|---|---|---|
| 41 | Auditeur des requêtes | `EXPLAIN (ANALYZE, BUFFERS)` sur les requêtes de **chaque** écran de liste ; les **compteurs du hub en Seq Scan complet** ; export CSV linéaire ; N+1 ; les index d'août |
| 42 | Auditeur de la performance d'interface | Taille des paquets, découpage, rendu, listes longues, re-rendus, chargement perçu, recherche à la frappe, `FranceCoverageMap` |
| 43 | Auditeur de charge et de concurrence | Volume de référence (50 000 fiches, 5 ans), `load-tests/`, 10 sessions simultanées, édition concurrente, verrous, dégradation < 20 % ; critères 1, 9, 17 du CDC |

### Bloc H — Tests, qualité, dépendances (4)

| # | Rôle | Périmètre |
|---|---|---|
| 44 | Auditeur du harnais de tests | Ce qui existe, ce qui **s'exécute réellement**, ce qui n'est lancé nulle part, ce qui est exclu en silence ; Pest/PHPUnit (`phpunit.xml` vs `phpunit-ci.xml`), Vitest frontend (**sur la branche seulement**), Playwright (⚠ `localhost`, jamais `127.0.0.1`), Vitest workers, `MOCKS-STRATEGY.md` |
| 45 | Auditeur de la valeur des tests | Chaque test critique **vu rougir**. Tests qui pré-insèrent ce qu'ils doivent produire, assertions vides, mocks qui testent le mock, tests statiques qui trouvent leurs propres commentaires |
| 46 | Auditeur d'analyse statique | `phpstan-baseline.neon` (337 messages, 2 045 l.), **`reportUnmatchedIgnoredErrors: false`**, niveau réel, `pint.json`, `eslint.config.mjs`, `eslint-suppressions.json`, types `any`, règle « la baseline ne grossit plus » |
| 47 | Auditeur des dépendances | Les **20 PR Dependabot une par une** (jamais en lot), CVE, `composer.lock`, `pnpm-lock.yaml` (frontend **et** workers), **unicité des clés du lockfile après fusion**, versions figées par décision écrite |

### Bloc I — Aptitude au chantier cible (3)

| # | Rôle | Périmètre |
|---|---|---|
| 48 | Auditeur d'aptitude au CDC | Pour **chacun des 26 chapitres** du CDC : ce qui existe déjà, ce qui manque, ce qui devra être défait. Verdict « peut-on ouvrir l'étape 1 du §27 ? » |
| 49 | Auditeur du contrat §22 | Familles d'événements dans les deux sens, ce qui ne traverse jamais, liens croisés, identité unique, tableau de bord du canal ; **critère 18 : aucun point de capture muet** |
| 50 | **Critique de complétude** | À la fin de chaque passe : « qu'est-ce qui manque — élément du §4 non audité, case de grille vide, écran non ouvert, exigence non couverte, fonctionnalité non raccordée, meilleure pratique ignorée, brique réinventée ? » Ce qu'il trouve **devient du travail** |

---

## 8. DÉROULÉ — SEPT PHASES, TROIS VÉRIFICATIONS

### P0 — Amorçage
1. Créer `Axion-CRM-Pro/_AUDIT/2026-08-18_AUDIT-360/` et ses fichiers (§9).
2. Reconstruire l'état **depuis les dépôts** : `git fetch --all`, branches, PR ouvertes, dernières exécutions CI, drapeaux, migrations appliquées.
3. **Rendre le terrain praticable** — chaque échec ici est lui-même un constat de sévérité élevée :
   - la console CRM **tourne en local**, sous une seule origine, connexion + 2FA, sans contournement TLS ;
   - `migrate:fresh` reconstruit une base neuve **en une commande, joué deux fois de suite** ;
   - les suites tournent : Pest/PHPUnit, Vitest frontend, Vitest workers, Playwright, PHPStan, Pint, ESLint, `pnpm build` ;
   - un jeu de données au **volume de référence** (50 000 fiches, 5 ans d'historique) est généré.
4. **Recompter les listes du §4** dans le code et publier les écarts.
5. Publier `01_INVENTAIRE.md` + le tableau d'affectation des 50 agents + les tableaux de suivi (une ligne par élément du §4).

### P1 — Fan-out d'audit (les 46 spécialistes, en vagues)
Vagues : **(a)** Blocs A + B + H (le socle) · **(b)** Blocs C + E + F · **(c)** Bloc D — **après** que la console tourne en local · **(d)** Bloc G — après le jeu de données · **(e)** Bloc I.
Le bloc D **joue chaque écran à la main** avant d'écrire quoi que ce soit. Captures archivées, avant/après.

### P2 — Consolidation et arbitrage
Dédoublonnage, identifiants stables, sévérité, référence, dépendances entre constats. Le chef de chantier tranche les contradictions **par une mesure**. Ordonnancement : ce qui débloque le reste d'abord.

### P3 — Correction (réalisateurs ≠ auditeurs)
- Un lot = une branche = une PR **petite et lisible**, un sujet.
- **Chaque correctif arrive avec son test, vu rouge avant d'être vert** — sortie rouge archivée.
- Ordre : S0 → S1 → S2 → S3. Aucun S0/S1 ne reste ouvert.
- PR #174 et #735 : auditées, corrigées si besoin, **fusionnées**. On juge le **résultat de la fusion**.
- Les 20 Dependabot : **une par une**, CI verte + build + fumée ; ou politique de gel écrite et appliquée. Décision prise par toi, consignée.
- Après chaque fusion : refetch `main`, revérifier. **Jamais réparer un rouge sans avoir refetché.**
- La refonte de navigation (§6) est un lot à part entière, avec redirections et visite guidée refaite.

### P4 — Vérification n° 1 (rotation +17)
Chaque constat corrigé est rejoué **par un autre agent** : il reproduit le défaut d'origine (il doit revenir), applique le correctif, confirme la disparition. Chaque garde ajoutée est vue rougir par cet autre agent. Les critères du §29 concernés sont **mesurés**.

### P5 — Deuxième passe : contre-vérification adversariale, de bout en bout
**Ce n'est pas une relecture des correctifs : c'est un second audit complet du même périmètre, mené pour réfuter le premier.**
Consigne à chaque contradicteur (rotation +29) : *« Démontre que la passe 1 s'est trompée. Trouve : une garde qui ne rougit pas, un test qui teste autre chose que ce qu'il prétend, un écran jamais ouvert, une case de grille remplie sans preuve, une mesure prise sur le mauvais jeu de données, un événement qui ne traverse pas, une affirmation "déjà fait" fausse, une fonctionnalité qui marche seule mais casse quand on la raccorde aux autres, une entrée de menu qui ne mène nulle part. »*
Il n'a **pas** accès au raisonnement de la passe 1. Il joue en priorité : les **raccordements** entre fonctionnalités, les cas limites, les pannes, la concurrence, le volume, et **tout ce que la passe 1 a déclaré vert sans mesure**. Tout ce qu'il trouve repart en P3 puis P4. Boucler jusqu'à ce qu'une passe adversariale complète ne trouve plus rien de sévérité ≥ S2.

### P6 — Troisième passe : regard neuf et complétude
Agents **neufs**, sans accès aux rapports des passes 1 et 2 ni à `02_CONSTATS.md`. On leur donne : le CDC, le code, ce prompt. Ils refont l'audit **de zéro** sur le périmètre des §4 et §5.
Puis **comparaison des trois passes** : tout écart est **en soi un défaut de méthode** à expliquer ligne à ligne (« pourquoi la passe 1 ne l'a-t-elle pas vu ? »). L'agent 50 rend le verdict de complétude final.

### P7 — Clôture
`07_RAPPORT-FINAL.md` (verdict en une page, puis le détail, preuves à l'appui) · matrice exigence → test → preuve complète · exercice de restauration rejoué · `06_RESTE-WILL.md` (une page, sans redite, chaque ligne avec recommandation) · **verdict de sortie en toutes lettres : « CRM Pro est prêt / n'est pas prêt à recevoir l'étape 1 du §27 du CDC v2.7 »**, avec la raison.

---

## 9. LIVRABLES ET FORMAT DES CONSTATS

Dossier : `Axion-CRM-Pro/_AUDIT/2026-08-18_AUDIT-360/`

| Fichier | Contenu |
|---|---|
| `00_JOURNAL.md` | Append-only, horodaté. La seule source de vérité de l'avancement. |
| `01_INVENTAIRE.md` | L'existant réel + les **tableaux de suivi** du §4 (une ligne par élément, aucune vide à la fin). |
| `02_CONSTATS.md` | Registre unique dédoublonné. |
| `03_MATRICE-EXIGENCES.md` | Exigence (§ du CDC, ligne du plan, fragilité F*) → test → preuve. |
| `04_PREUVES/` | Sorties brutes, `EXPLAIN`, mesures, captures, journaux, **sorties rouges des gardes**. |
| `05_DECISIONS.md` | Chaque décision d'autopilote : question, options, décision, raison, date. |
| `06_RESTE-WILL.md` | Ce qui exige le dirigeant, avec recommandation. Sans redite. |
| `07_RAPPORT-FINAL.md` | Le rapport. |
| `08_PASSE-2-ADVERSARIALE.md` | Ce que la contre-vérification a réfuté ou confirmé. |
| `09_PASSE-3-REGARD-NEUF.md` | L'audit indépendant + la comparaison des trois passes. |
| `10_NAVIGATION-CIBLE.md` | Le livrable du §6.5. |
| `11_GRILLES/` | Un fichier par grille remplie : `ecrans.md`, `routes.md`, `tables.md`, `automatismes.md`, `fonctionnalites.md`, `parcours.md`, `raccordement.md`. |

**Schéma d'un constat — obligatoire, sans exception :**

```
### [A-042] Titre en une ligne, factuel
- Sévérité      : S0 bloquant | S1 grave | S2 défaut | S3 finition
- Domaine       : backend / interface / navigation / canal / sécurité / performance / tests / conformité / UX
- Référence     : main 7a0bfb2 | chore/etape-0-prealables | PR #174 | PR #735 | résultat de fusion
- Emplacement   : chemin/fichier.ts:123
- Constat       : ce qui est, en une phrase, sans jugement
- Preuve        : la commande jouée + sa sortie (fichier dans 04_PREUVES/)
- Témoin négatif: la preuve que le contrôle aurait vu le problème s'il existait
- Impact        : ce qui casse, pour qui, dans quel cas
- Reproduction  : les gestes exacts
- Correctif     : ce qui est proposé, et son coût
- Statut        : ouvert / corrigé / refusé (avec raison) / reste Will
- Vérifié par   : agent N+17 — verdict + preuve
- Réfuté par    : agent N+29 — verdict + preuve
- Passe 3       : retrouvé indépendamment : oui / non (si non : pourquoi)
```

**Sévérités.** **S0** : perte de données, faille, non-conformité RGPD, indisponibilité, ou blocage du chantier cible. **S1** : fonctionnalité qui ment ou ne marche pas dans un cas courant. **S2** : défaut réel sans contournement coûteux. **S3** : finition, cohérence, confort.
⚠️ **Une confusion de navigation qui fait perdre l'utilisateur est au minimum S2** — l'ergonomie n'est pas un « nice to have » dans cet audit.

---

## 10. PIÈGES CONNUS DE CES DÉPÔTS

1. **Windows / fins de ligne** : tout test statique cherchant un `\n` littéral est aveugle sous Windows (rouge en local, vert en CI Linux).
2. **`git stash` est global au dépôt** : deux `lint-staged` simultanés se volent leur sauvegarde. Ne jamais éditer un fichier pendant qu'un hook de commit tourne.
3. **Worktrees** : plusieurs sessions en parallèle. Relire `HEAD` **juste avant** d'écrire ; ne jamais supprimer un worktree sans l'avoir vérifié libre. ⚠️ *Corrigé le 19/08 : `crmpro-wt-etape0` n'est plus le worktree actif — l'étape 0 est close. Le worktree vivant est `crmpro-wt-etape1a`. **Recompte-les toi-même** (`git worktree list`) plutôt que de croire cette ligne : elle a déjà été périmée une fois.*
4. **Relire `gh pr list` juste avant d'ouvrir une PR.**
5. **La CI évalue le commit de fusion**, pas la branche. Vérifier notamment **l'unicité des clés du lockfile fusionné**.
6. **Ne jamais grouper des montées de dépendances.** Une par une.
7. **Une gate qui meurt à l'installation n'a exécuté aucun test** : un vert peut être un silence.
8. **`docker compose restart` ne relit pas `env_file`** — `up -d` après un changement d'environnement (D-05).
9. **Playwright + serveur de dev : `localhost`, jamais `127.0.0.1`.**
10. **Locale : CI en `en_US.utf8`, prod en `C`.** Devant un rouge inexplicable sur un harnais neuf, comparer `datctype` avant d'accuser le code.
11. **`git log -S` ignore les commits de fusion** — comparer un merge à son premier parent.
12. **Un test qui pré-insère ce qu'il doit faire produire ne teste rien.**
13. **Ne pas répéter les constats déjà réfutés par la mesure** : « frontend 0 test » (14 fichiers existaient ; le vrai trou : 1 écran de route couvert sur ~37), « 337 PHPStan sur les modules du chantier » (0 sur `Crm/**` ; le vrai défaut : `reportUnmatchedIgnoredErrors: false`), « export CSV plafonné à 100 » (code mort ; plafond réel 5 000, silencieux), « statuts Calendly manuels » (`canceled` est automatique, mais **aucun `calendly_canceled` n'est émis vers le CRM**). **Re-vérifie-les dans le code — sans recopier l'ancienne conclusion.**
14. **Un montant ou une valeur commerciale que les fichiers déclarent inexistants ne se supprime pas** : sa source peut vivre hors du dépôt.
15. **Une constante dupliquée avec la meilleure intention ne signale jamais qu'elle a divergé** : chercher les valeurs métier en double.
16. **`gh pr merge --auto` fusionne immédiatement** si les conditions sont déjà réunies.
17. **Les montants en français utilisent une espace fine insécable (U+202F)** : un `grep` à l'espace normale rend 0 et fait croire à un défaut.

**Ajoutés le 2026-08-19 — tous mesurés le jour même, pas déduits :**

18. 🔴 **Le déploiement ne recrée que quatre services.** `deploy-direct-ssh.yml` fait `up -d --build --force-recreate --no-deps api app horizon scheduler`. `postgres`, `redis` et `reverb` **ne sont jamais recréés** : leur configuration Compose ne s'applique donc **jamais** au réel. Un correctif de sécurité fusionné et déployé avec succès n'a rien fermé du tout pour cette raison. Devant tout constat portant sur ces trois services, **compare le fichier au conteneur** (`docker ps`, `docker inspect`), jamais le fichier tout seul.
19. 🔴 **Une garde peut être irréprochable et mesurer le mauvais objet.** La garde `config-prod` a été vue rouge dans deux modes de défaillance, elle porte un témoin positif — et elle valide **le fichier**, pas l'état qui tourne. Complète la règle 2 de la doctrine : *une garde ne vaut que si elle rougit **sur l'objet qui casse***. Passe chaque garde du dépôt à ce crible.
20. **Aucun hook Git n'est configuré dans ce dépôt.** `core.hooksPath` est vide et `.git/hooks` ne contient aucun hook actif : rien ne rattrape un format, un lint ou un secret avant le push. La CI est le **seul** filet — un constat « les hooks empêchent X » serait faux.
21. **`docker exec` n'a pas d'option `-T`** (c'est `docker compose exec` qui l'a). Une commande de diagnostic qui échoue avec `unknown shorthand flag: 'T'` n'a **rien mesuré** — ne pas lire son silence comme un résultat.
22. **Le semis d'un référentiel éditable depuis la console ne doit jamais être un `upsert`.** Les seeders sont appelés par les migrations, qui tournent à chaque déploiement : un `upsert` remet les libellés à leur valeur d'usine et détruit les personnalisations une fois par mise en production, en silence. Vérifie ce point sur **chaque** seeder appelé depuis une migration.

---

## 11. CE QUI DOIT ÊTRE JOUÉ À LA MAIN DANS UN VRAI NAVIGATEUR

**Les 39 écrans du §4.7, un par un, sans exception**, avec la grille §5.1 et une capture par état. Plus, au minimum, ces parcours complets :

1. Connexion → 2FA → première configuration (`EnforceFirstLoginSetup`) → déconnexion → session expirée → lien magique → mot de passe oublié.
2. Tableau de bord : chaque compteur cliqué, chaque graphe, chaque lien.
3. Hub contacts : recherche, filtres, tri, pagination, sélection multiple, actions de masse (`POST /bulk`), export.
4. **Fiche 360°** (`PersonTimelinePage`) : timeline complète, tous canaux, pagination, liens croisés, modification, note, tâche, tag — comparée à **l'anatomie exigée au §1.5 du CDC** (bandeau, colonne centrale, blocs latéraux, précédent/suivant).
5. Vivier et arbitrage : les deux univers, **l'étanchéité vue à l'écran**, attacher / écarter.
6. Entreprises : liste → fiche → contacts rattachés → enrichir → recalculer le score.
7. Médias, journalistes, opposition d'un journaliste, exports.
8. Couverture France : la carte, une zone, lancer, enrichir.
9. **Assistant de campagne de collecte du début à la fin**, puis démarrer / mettre en pause / reprendre / annuler, et lire les runs et les journaux.
10. Audiences : construire avec `neq` **et** `not_in` sur un champ vide, comparer l'aperçu au décompte réel, rafraîchir, lister les membres.
11. Tags : créer, renommer, fusionner, supprimer, gouvernance, application en masse.
12. RGPD : déposer une demande, la traiter, exporter par jeton, effacer, **vérifier la propagation vers le site** et l'anti-réinsertion.
13. Registre AI Act ; journaux d'audit + **vérification de la chaîne**.
14. Utilisateurs : créer un compte de rôle limité, **se connecter avec**, tenter d'atteindre ce qui est interdit.
15. Réglages : **chaque onglet, chaque champ**, sauvegarde, effet réel, annulation.
16. Observabilité : chaque graphe, chaque alerte, le canal.
17. Recherche globale (`⌘K`) : par nom, e-mail, téléphone, société, avec fautes et accents.
18. Sélecteur d'espace de travail : le changement se voit-il partout ? la teinte ? les données ?
19. La **visite guidée** du début à la fin.
20. Les **six entrées verrouillées** et les **quatre routes 501** : ce que voit l'utilisateur.
21. Le tout **en 375 px**, puis **au clavier seul**, puis **en mode sombre**.

Pour chaque parcours : nombre de clics, temps perçu, points de friction, libellés ambigus, ce qu'un nouvel utilisateur ne comprendrait pas. **La console doit être simple. Toute complication est un constat.**

---

## 12. DÉFINITION DE FINI

Tu n'as pas terminé tant que **tout** ce qui suit n'est pas vrai et prouvé :

1. **Chaque élément nommé au §4 figure dans un tableau de suivi avec son verdict et sa preuve.** Aucune ligne vide.
2. **Chaque grille du §5 est remplie**, case par case, pour chacun de ses objets : 39 écrans, ~110 routes, 21 modèles + 54 migrations, 10 tâches + 7 jobs + 49 commandes, 23 fonctionnalités + la matrice de raccordement, 13 parcours.
3. **Chaque écran a été ouvert à la main**, chaque bouton essayé, captures archivées.
4. **Le §6 a produit `10_NAVIGATION-CIBLE.md`**, la refonte est appliquée, les redirections écrites, la visite guidée refaite, le test des dix intentions rejoué **après** — et il passe.
5. Chaque constat **S0 et S1 est corrigé**, avec un test **vu rouge puis vert**.
6. Les 16 lignes de l'étape 0 sont **closes et mesurées**, ou listées comme bloquées par un accès, avec ce qu'il faut pour les débloquer.
7. Les fragilités **F1 → F19** sont levées ou explicitement arbitrées, **sur `main` fusionné**.
8. ✅ **DÉJÀ VRAI au 2026-08-19** — les PR #174 et #735 sont **fusionnées**, `main` est vert après fusion. Ne pas le re-porter comme un reste à faire ; se contenter de **le re-vérifier** et de passer.
9. ⚠️ **RÉÉCRIT le 2026-08-19** — il n'y a **plus aucune PR Dependabot ouverte**. Le reste à traiter n'est pas les PR, ce sont les **57 alertes de vulnérabilité** que GitHub annonce sur la branche par défaut (4 critiques, 18 hautes, 31 moyennes, 4 basses) : chacune arbitrée, corrigée, ou gelée sous une politique écrite. ⚠️ **Une montée de dépendance ne se fait jamais en lot** ([[fusions-concurrentes-cassent-main]]), et **un rouge de CI ne justifie pas de violer un contrat écrit dans le dépôt** — cas Stripe, où le SDK type `apiVersion` comme littéral unique et pousse à bumper une version dont le dépôt interdit le bump sans ADR.
10. **Aucune route 501, aucune entrée verrouillée, aucun écran factice** ne subsiste sous un nom que le CDC emploie.
11. La matrice **exigence → test → preuve** est complète.
12. Les critères du **§29 du CDC** applicables à l'existant sont **mesurés**, chiffres archivés.
13. Une **sauvegarde a été restaurée pour de vrai**.
14. La passe **adversariale (P5)** puis la passe **à regard neuf (P6)** ont été menées **en entier**, et la dernière ne trouve plus rien de sévérité ≥ S2.
15. Le rapport final porte un verdict net : **CRM Pro est-il prêt pour la suite du chantier CDC v2.7 ?** ⚠️ *Reformulé le 19/08 : l'étape 1a a **commencé** (deux pièces livrées, journal `_SESSIONS/2026-08-19_CRM-ETAPE-1A.md`). La question n'est donc plus « peut-on démarrer ? » mais « **sur quoi le chantier en cours est-il en train de bâtir, et qu'est-ce qui va lui casser dans les mains ?** » — verdict par domaine, pas un feu vert global.*
16. `06_RESTE-WILL.md` tient en une page, sans redite, chaque ligne avec une recommandation.

**Si tu es interrompu**, tu reprends en reconstruisant ton état depuis les dépôts et `00_JOURNAL.md` — jamais depuis ce qu'une conversation croyait.

**Commence maintenant. Ne demande rien. Décide, mesure, prouve, corrige, et va jusqu'au bout.**
