# LES 168 CONSTATS S2 OUVERTS, RANGES PAR THEME

> Genere par `extraire-s2-par-theme.py --ecrire`, depuis `FILE-DE-TRAVAIL.md`.
> **Ne pas editer a la main** : editer le rangement dans le script, puis rejouer.
> Le script sort en 1 si un constat est range deux fois, dans aucun theme, ou
> si le compte de 168 a bouge.

> :warning: **Le dedoublonnage n'a jamais ete fait sur les S2.** `02ter` s'arrete
> aux S1 et le dit. Il reste tres probablement des paires decrivant le meme defaut
> sous deux etiquettes. **168 est un plafond, pas un compte exact.**

| # | Theme | Nb |
|---:|---|---:|
| 1 | Cloisonnement des espaces (RLS) | 13 |
| 2 | Securite du serveur de production | 10 |
| 3 | Acces, permissions, 2FA | 10 |
| 4 | RGPD et journal d'audit | 9 |
| 5 | Collecte de masse (scraping) | 8 |
| 6 | Le pont site <-> CRM | 17 |
| 7 | Qualite des donnees en base | 8 |
| 8 | Navigation et reperes | 25 |
| 9 | Comportement des ecrans | 10 |
| 10 | Design system, sombre, accessibilite, mobile | 17 |
| 11 | Performance | 8 |
| 12 | Le filet de tests et la CI | 20 |
| 13 | Sauvegardes et reprise | 4 |
| 14 | Planification des taches | 3 |
| 15 | Documents qui mentent | 6 |
| | **Total** | **168** |

---

## 1. Cloisonnement des espaces (RLS) -- 13 constats

*Ce qui doit empecher la donnee d'un client de passer chez un autre.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `A06-004` | CRM | `correctif` | « Étanchéité par table, pour CHAQUE table à workspace_id » exclut le journal d'audit RGPD, par cécité de scan promue en décision | backend/tests/Support/EtancheiteWorkspace.php:70-89 (ABSENTES_PAR_CONSTRUCTION), :125-151 (DEFAUTS_CONNUS), :192 (filtre relkin… |
| `A08-004` | CRM | `correctif` | Le schéma de production diffère de celui de la CI : audit_logs y est une table ordinaire à workspace_id, sans RLS, que la garde d'étanchéité exclut « par construction » | backend/tests/Support/EtancheiteWorkspace.php:70-82 · backend/database/migrations/2026_05_16_000001_create_extensions_and_helpe… |
| `A08-002` | CRM | `correctif` | L'import de médias est refusé par la RLS en production, même cause, même silence | backend/routes/console.php (tâches media:import-opendatasoft, media:import-blogs) |
| `B10-006` | CRM | `correctif` | La ceinture applicative d'étanchéité est posée sur 4 modèles sur les 15 concernés, et rien ne l'exige | backend/app/Models/Concerns/BelongsToWorkspace.php · backend/app/Models/Scopes/WorkspaceScope.php |
| `B10-007` | CRM | `correctif` | 15 tables portent workspace_id sans clé étrangère : la suppression d'un workspace y laisse des orphelins invisibles | base — activities, analytics_attribution, analytics_cohorts, analytics_daily_rollups, analytics_funnels, analytics_kpis, audien… |
| `B11-007` | CRM | `correctif` | Onze tables portant des données personnelles ou clients n'ont ni colonne d'espace, ni policy | schéma public — crm_outbound_events, deal_history, dnc_entries, email_messages, email_sequences, email_suppressions, email_vali… |
| `B11-010` | CRM | `correctif` | L'atelier local n'arme aucun des deux dispositifs de cloisonnement : tout le code non contextualisé y passe au vert | backend/.env:51-53 (CRM_DB_APP_ROLE_ENABLED=false, CRM_STRICT_WORKSPACE_SCOPE=false) · docker inspect axion-crm-api : aucune de… |
| `B12-013` | CRM | `correctif` | POST /v1/companies/bulk-enrich met en file 500 identifiants sans vérifier qu'ils appartiennent à l'espace de travail | backend/app/Http/Controllers/Api/CompaniesController.php:433-441 |
| `B12-018` | CRM | `correctif` | GET /v1/users liste les comptes par leur pointeur d'affichage, pas par leur appartenance | backend/app/Http/Controllers/Api/UsersController.php:32 |
| `B14-007` | CRM | `correctif` | Le canal sortant est mono-destination alors que le CRM est multi-espaces, et il échappe à toute garde de cloisonnement | backend/database/migrations/2026_08_14_000007_crm_outbound_events.php:38-44 · backend/app/Models/Scopes/WorkspaceScope.php:24-4… |
| `B17-014` | CRM | `correctif` | prospection:reclassify-size --all et --sector --all réécrivent toute la table companies en une seule requête, sans contexte d'espace, depuis un bouton GitHub | backend/app/Console/Commands/ProspectionReclassifySize.php:21-41 · ProspectionReclassifySector.php:35-37 · .github/workflows/pr… |
| `A07-010` | CRM | `correctif` | La garde d'étanchéité par table ne peut pas être rejouée sur l'atelier : 4 tests sur 11 échouent faute d'une base de test à jour | backend/tests/Feature/EtancheiteParTableTest.php + base locale axion_crm_test |
| `A09-008` | DOC | `doc` | Trois documents annoncent « RLS sur 30 tables » ; 55 tables en portent, et 4 tables workspace-scoped n'en ont pas — dont audit_logs | ARCHITECTURE.md:64 (« RLS : 30+ tables ») · ARCHITECTURE.md:30 (schéma, « RLS 30 tbl ») · CHANGELOG.md:38 · _REPORTS/PROGRESS.m… |

## 2. Securite du serveur de production -- 10 constats

*Defauts d'exploitation mesures sur le VPS, pas defauts de code.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `F37-004` | INFRA | `correctif` | La production n'émet ni CSP, ni Permissions-Policy, ni COOP/CORP — alors que l'atelier local les émet | infra/caddy/Caddyfile:111-117 (app prod), :125-131 (api prod), :182-206 (staging) |
| `F37-007` | CRM | `correctif` | L'origine (46.62.248.239:443) sert directement l'application, en contournement de Cloudflare | VPS Hetzner 46.62.248.239, Caddy exposé 443 |
| `F37-008` | CRM | `correctif` | Le laravel.log de production contient de la PII (e-mails, cookies de session, IP+UA) via les entrées Telescope ; le fichier hôte est en -rwxrwxrwx et non tourné | conteneur /var/www/html/storage/logs/laravel.log (272 Mo) · hôte /opt/axion-crm-pro/backend/storage/logs/laravel.log (1,0 Go, -… |
| `F37-009` | INFRA | `correctif` | Racine du webroot en 1777 et storage/bootstrap/cache en 777 : write-what-you-want dans le conteneur de production (suite de F40-013) | conteneur axion-crm-api : /var/www/html=1777 www-data · /var/www/html/storage=777 · /var/www/html/bootstrap/cache=777 · public/… |
| `F37-010` | CRM | `resteWill` | Le mot de passe Redis de production fait 4 caractères minuscules | .env prod REDIS_PASSWORD (longueur 4, forme minuscules) · Redis requirepass posé |
| `F37-011` | INFRA | `correctif` | Le mécanisme qui rendrait toute rotation du secret Postgres impossible (relecture de F40-007) | docker-compose.yml:17 POSTGRES_PASSWORD: axion_dev_only sous environment: · deploy-direct-ssh.yml recrée api app horizon schedu… |
| `F40-009` | INFRA | `doc` | ufw n'est pas installé sur le serveur de production, alors que le script d'installation et un runbook le supposent présent | infra/scripts/setup-hetzner-cpx22.sh, infra/runbooks/05-rotate-secrets.md, et les commentaires de docker-compose.prod.yml:120-1… |
| `F40-010` | INFRA | `correctif` | Aucun /etc/docker/daemon.json : les journaux des conteneurs sont sans limite de taille | serveur 46.62.248.239, /etc/docker/daemon.json (inexistant) |
| `F40-013` | INFRA | `correctif` | Le système de fichiers du conteneur de production n'est PAS en lecture seule — la protection vient des droits, et la racine du webroot est en 1777 | conteneur axion-crm-api en production — Dockerfile.laravel:114 (USER www-data), :98 (chown -R www-data:www-data storage bootstr… |
| `G42-006` | CRM | `correctif` | Les cartes de source (.map) sont publiées et servies en 200 par la production : 4 174 052 octets de code source TypeScript téléchargeables sans authentification | frontend/vite.config.ts:100 (sourcemap: true) · frontend/Dockerfile.frontend:52 (COPY --from=builder /srv/app/dist /srv/app/dis… |

## 3. Acces, permissions, 2FA -- 10 constats

*Qui a le droit de faire quoi, et ce que l'ecran propose quand meme.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `P5-35-001` | CRM | `correctif` | La garde « GET /users ne sélectionne aucune colonne inexistante » ne peut pas rougir, et le constat qu'elle garde est imprécis | backend/tests/Feature/Auth/GardesAuthentificationAgent35Test.php:159-166 · backend/app/Http/Controllers/Api/UsersController.php… |
| `P5-35-002` | CRM | `correctif` | EnsureTwoFactorPassed ne s'applique pas aux clients par jeton d'API : la 2FA reste contournable hors du SPA | backend/app/Http/Middleware/EnsureTwoFactorPassed.php:46-52,68-73 · backend/bootstrap/app.php:43 |
| `P5-35-003` | CRM | `correctif` | L'énumération par le temps n'est pas supprimée : elle est inversée et doublée, et la garde ne peut pas la voir | backend/app/Services/Auth/AuthService.php:322,334-339 · garde GardesAuthentificationAgent35Test.php:330-395 |
| `P5-35-008` | CRM | `correctif` | Le même bloc de routes laisse /audit-logs/verify-chain et /ai-act/register sans aucune permission | backend/routes/api.php:234,235,239 |
| `D22-006` | CRM | `correctif` | L'interface ne consulte jamais les permissions : 33 écrans sur 37 offrent leurs actions à tout compte authentifié | frontend/src/features/ — l'ensemble |
| `F36-009` | CRM | `correctif` | Les 11 policies n'ont aucune couverture de test : les réécrire en refus total laisse la suite verte | backend/tests/ (21 fichiers) · backend/app/Policies/ |
| `B12-005` | CRM | `correctif` | La même route interne n'a ni limitation de débit, ni fenêtre de rejeu | backend/routes/api.php:307 |
| `B12-008` | CRM | `correctif` | 88 routes sur 117 n'ont aucune limitation de débit, et le limiteur global déclaré n'est attaché à rien | backend/app/Providers/RouteServiceProvider.php:19-24, backend/bootstrap/app.php:25-29 |
| `H45-007` | CRM | `correctif` | L'anti-rejeu du canal se désarme par configuration, et aucun des 780 tests ne le voit | backend/app/Support/HmacSignature.php:49-51 (if ($maxSkewSeconds <= 0) { return true · }) · backend/config/crm.php:85 ((int) en… |
| `B12-015` | CRM | `correctif` | Seize routes lisent une entrée sans jamais la valider, et sept formes de réponse d'erreur coexistent | voir le tableau du §1.2 · formes de réponse : TagsController.php:280,311, AudiencesController.php:113,125, RgpdRequestsControll… |

## 4. RGPD et journal d'audit -- 9 constats

*Obligations modelisees mais jamais tenues, et la chaine d'audit.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `A05-002` | CRM | `correctif` | first_info_at (art. 14 RGPD) : deux colonnes livrées, aucun écrivain dans tout le dépôt, 0 ligne renseignée | backend/database/migrations/2026_08_14_000002_crm_socle_taxonomie_business.php:88 et :109 |
| `A07-005` | DOC | `doc` | L'adresse en clair n'a pas disparu de la liste d'opposition : la colonne et son index subsistent | table opt_out (et email_suppressions) · migration de temps 2 non écrite, cf. _REPORTS/2026-08-18_OPT-OUT-DROP-COLUMN-TEMPS-2.md |
| `E31-009` | SITE | `correctif` | L'opposition d'un candidat au vivier fait naître, côté CRM, une activité pending_match dans le workspace BUSINESS portant son adresse en clair | émission src/server/vivier/opposition.ts:66-70 (bloc person: { email }) · effet CRM SiteSyncIngestService::apply() → upsertBusi… |
| `E31-010` | SITE | `correctif` | Une opposition dont l'adresse ne se déchiffre pas est perdue en silence, et la route répond quand même « ok » | src/server/vivier/opposition.ts:39 (const email = safeDecrypt(application.email)) et :54 (if (email) { … }) · retour { ok: true… |
| `E33-007` | SITE | `correctif` | Le lead chatbot part en clair vers Telegram, y compris ce que le chiffrement vient de protéger | src/server/chatbot/tools/capturer-lead.ts:24-41 (appelé ligne 182) |
| `B16-010` | CRM | `correctif` | user_agent n'entre pas dans le hachage | backend/app/Services/Audit/AuditHashChain.php:105-119 |
| `B16-011` | CRM | `correctif` | L'écran « Journaux d'audit » affiche cinq colonnes qui n'existent ni en base ni dans l'API | frontend/src/features/rgpd/AuditLogsPage.tsx:25-33 et :157,:225,:229 · backend/app/Models/AuditLog.php:13-16 |
| `B16-012` | CRM | `correctif` | Les commandes destructives planifiées n'écrivent rien au journal, sauf deux | backend/app/Console/Commands/{RetentionPurge,PruneScraperRuns,AnonymizeOldIps,MediaCleanEmails,ProspectionPurgeNonCommercial,Pr… |
| `C21-002` | CRM | `correctif` | Le repli de casse de PostgreSQL et celui de PHP divergent sur les accents ; le mécanisme est prouvé, son exposition actuelle est nulle | backend/app/Crm/Rgpd/SiteGdprService.php:42 et :108, backend/app/Crm/Scraping/ScrapedRecordIngestService.php:401 — $email = mb_… |

## 5. Collecte de masse (scraping) -- 8 constats

*Moins technique que juridique : ce que les collecteurs s'autorisent.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `C19-002` | CRM | `correctif` | Les deux gardes ne bloquent aucune adresse IPv6 par la règle : elles ferment par accident de parsing, et divergent l'une de l'autre | backend/app/Services/Http/SsrfGuard.php:25-37 et :100-127 · workers/src/utils/ssrf-guard.ts:25-58 |
| `C19-004` | CRM | `correctif` | Aucun collecteur ne lit le robots.txt des sites qu'il moissonne | backend/app/Services/, workers/src/ (absence) |
| `C19-005` | CRM | `doc` | Aucune limitation de débit par domaine cible ; le seul délai de politesse du code est dans une méthode morte, et la documentation affirme le contraire | backend/app/Services/Legal/MentionsLegalesScraperService.php:239-259 (chemin vivant) et :295-321 (méthode morte) · backend/app/… |
| `C19-006` | CRM | `correctif` | Les collecteurs de masse se déguisent en navigateur et masquent leur automatisation, sans qu'aucune décision écrite ne l'assume | backend/app/Services/Legal/MentionsLegalesScraperService.php:71-79 · backend/app/Services/Domain/DomainFinderService.php:42 · w… |
| `C18-010` | CRM | `correctif` | Un run Node est déclaré « success » sans avoir rien collecté, et son identifiant n'a aucun rapport avec le run envoyé au worker | backend/app/Jobs/LaunchZoneScrapingJob.php:109-119 et :189-212 · backend/app/Jobs/DispatchScrapeJob.php:39 |
| `C18-012` | CRM | `correctif` | Un run interrompu reste « running » pour toujours et bloque la fin de sa campagne | backend/app/Jobs/LaunchZoneScrapingJob.php:55-69 · backend/app/Jobs/MonitorCampaignProgressJob.php:57 et :114 |
| `C18-013` | CRM | `correctif` | EligibiliteCampagne ne dit jamais *pourquoi* une fiche est écartée, et une fiche opposée n'apparaît dans aucune des deux listes | backend/app/Support/EligibiliteCampagne.php:73-83 et :248-251 |
| `C18-018` | INFRA | `correctif` | Aucun des 13 scrapers n'est couvert par un test, et aucun n'est déployé | workers/src/scrapers/ (13 implémentations) · workers/tests/ (6 fichiers) · docker-compose.yml:182-187 |

## 6. Le pont site <-> CRM -- 17 constats

*L'instrument qui devait prouver que rien ne se perd est lui-meme faux.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `E31-005` | SITE | `correctif` | La réconciliation ignore la 6ᵉ famille source : les demandes podcast ne sont jamais comparées | src/server/crm-sync/reconcile.ts:73 (CrmSyncFamily, 5 valeurs) vs src/features/podcast-request/actions.ts:111 (subjectRef: site… |
| `E31-006` | SITE | `correctif` | La réconciliation est aveugle aux 6 événements de changement d'état, qui réutilisent le subject_ref de la création | src/server/crm-sync/reconcile.ts:293-320 (compareFamily) · émetteurs calendly/enrich.ts:239, admin-calendly/actions.ts:100, new… |
| `E31-007` | SITE | `correctif` | La famille submission produit des faux positifs garantis : les candidatures du tunnel commercial sont comptées « manquantes » quand le flux candidats est fermé | src/server/crm-sync/reconcile.ts:160-179 (famille submission, comparée sans condition) vs src/features/commercial-application/a… |
| `E31-008` | SITE | `correctif` | Le contrat entrant n'accepte que 3 types : le §22.6 (« le statut du RDV redescend vers la console tout seul ») est inapplicable par construction | src/server/crm-sync/inbound.ts:133 (EVENT_TYPES = new Set(["consent_optout","consent_optin","erasure"])) et :158 (parseInboundP… |
| `E31-011` | SITE | `correctif` | Une rafale d'abandons produit un seul message qui, sur le chemin d'émission immédiate, ne nomme aucun lead | src/server/crm-sync/alerts.ts:52 (crmSyncAlertDedupKey = kind + seau horaire UTC) · src/server/queue/workers/crm-sync-worker.ts… |
| `E33-004` | SITE | `correctif` | La réconciliation compare 5 familles alors que le site en émet 6 : les demandes podcast ne sont jamais vérifiées | src/server/crm-sync/reconcile.ts:74-75 et :161-260 · src/features/podcast-request/actions.ts:111 |
| `E33-005` | SITE | `correctif` | La réconciliation produit un faux « manquant » permanent sur chaque candidature commerciale | src/server/crm-sync/reconcile.ts:161-179 · src/features/commercial-application/actions.ts:202 et :273-277 |
| `E33-008` | SITE | `correctif` | Le formulaire unifié, qui porte 12 des 14 finalités, n'émet aucun source_slug | src/features/unified-contact/actions.ts:250-270 · src/features/roi-report/actions.ts:168 · src/features/newsletter/actions.ts:1… |
| `A06-012` | SITE | `correctif` | La parité de capture s'arrête à l'outbox du site : une livraison abandonnée compte comme reçue par le CRM | C:\Users\willi\Documents\Projets\Axion-IA\axionia\src\server\crm-sync\reconcile.ts:292-317 · .../crm-sync/health.ts |
| `B13-004` | CRM | `correctif` | Un événement refusé ne laisse aucune trace exploitable : ni file morte, ni motif persisté, ni alerte | backend/app/Http/Controllers/Internal/SiteSyncController.php:49-91 · backend/app/Crm/Ingest/SiteSyncRejection.php · backend/app… |
| `B14-006` | CRM | `correctif` | Rien ne détecte l'arrêt de crm:flush-outbound, et son verrou anti-chevauchement peut le taire 24 h | backend/routes/console.php:166-170 · docker-compose.prod.yml:80-91 · infra/monitoring/prometheus/alerts.yml |
| `B14-011` | CRM | `correctif` | Cinq des six familles d'événements exigées par le §22.2 n'ont aucun émetteur | backend/app/Crm/Outbound/ConsentOutboundRecorder.php:33 · backend/routes/api.php:263-281 · backend/app/Http/Controllers/Api/Con… |
| `B14-012` | CRM | `correctif` | person_key est prévu, jamais renseigné, et de toute façon jeté par le site | backend/app/Crm/Outbound/ConsentOutboundRecorder.php:52 · backend/app/Crm/Ingest/SiteSyncEvent.php:192,304-309 · Axion-IA/axion… |
| `I49-002` | CRM | `correctif` | §22.3 : deux interdits sur cinq sont une décision appliquée, deux sont sans objet, et le cinquième n'est appliqué que dans un sens | axionia/src/server/crm-sync/types.ts:1-17 (l'interdiction écrite) · backend/app/Crm/Ingest/SiteSyncEvent.php:82,84,116,262,282… |
| `I49-008` | CRM | `correctif` | Critère 5 : aucun des deux sens ne tient les 60 s sur son chemin GARANTI — 10 min côté site, 5 min côté CRM — et le seul instrument de latence est faussé de +7 200 s hors production | backend/routes/console.php:166-170 (everyFiveMinutes()) · axionia/src/server/crm-sync/enqueue.ts:9-18 (émission immédiate) · ax… |
| `E34-002` | SITE | `conception` | Dix-huit des vingt et un fichiers qui appellent le canal CRM n'ont aucun test qui connaisse le canal, et rien ne relie les deux | src/server/crm-sync/index.ts (les appelants) · garde absente |
| `A07-011` | SITE | `correctif` | Le statut Calendly « honoré » reste manuel : l'exigence F14 n'est remplie que pour 2 statuts sur 3 | axionia/src/server/calendly/enrich.ts:33-37,71-73 · axionia/src/app/api/calendly/webhook/route.ts:131 |

## 7. Qualite des donnees en base -- 8 constats

*Mesure sur la vraie base, pas deduit.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `C18-004` | CRM | `correctif` | DeduplicationService::computeContactHash() diverge de la colonne générée sur les particules et les espaces | backend/app/Services/Dedup/DeduplicationService.php:64-72 et 306-314 |
| `C21-005` | CRM | `correctif` | Le palier « complete » du badge qualité n'est atteint par aucune des 4 295 349 fiches, et 80,80 % du stock est à zéro | colonne générée companies.quality_badge — CASE WHEN quality_score >= 90 THEN 'complete' WHEN >= 50 THEN 'partielle' ELSE 'basiq… |
| `C21-007` | CRM | `correctif` | Doublons d'entreprise : 64 523 fiches surnuméraires par nom + ville, 162 025 par téléphone | table public.companies — unicité posée sur (workspace_id, siren) uniquement |
| `A05-004` | CRM | `conception` | Le cycle de vie business n'a jamais changé d'état : 4 295 349 fiches à nouveau, et la règle batch du plan n'existe pas | _PLANS/2026-08-13_PLAN-CRM-contacts-candidats.md §2.2b (table des règles de passage) · backend/routes/console.php |
| `A05-006` | CRM | `correctif` | Le vivier candidats — objet central du plan — est vide cinq jours après l'ouverture du flux, et rien ne signale ce silence | backend/database/migrations/2026_08_14_000003_crm_socle_vivier_candidats.php · production axion_crm |
| `A07-007` | CRM | `correctif` | GovernedTagsSeeder n'est appelé par aucun seeder ni aucune migration : une base neuve ne porte aucun tag gouverné | backend/database/seeders/DatabaseSeeder.php:11-27 (liste des 15 seeders appelés) · backend/database/seeders/GovernedTagsSeeder.php |
| `B10-009` | CRM | `correctif` | permissions porte UNIQUE(name) seul là où le code suppose (name, guard_name) — et deux sources de vérité écrivent la même permission | base, contrainte permissions_name_key · backend/database/seeders/PermissionsAndRolesSeeder.php:39 · backend/database/migrations… |
| `B10-011` | CRM | `correctif` | ScrapingSourcesSeeder fait un upsert depuis DEUX migrations et réécrit six colonnes du référentiel à chaque déploiement | backend/database/seeders/ScrapingSourcesSeeder.php:155-168 · appelé par 2026_08_14_000006_crm_scraping_sources.php:57 et 2026_0… |

## 8. Navigation et reperes -- 25 constats

*La cible des plans et ce que la console fait ne se recouvrent pas.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `A-006` | CRM | `conception` | Le §4.8 et le §6.2 du mandat d'audit décrivent une barre latérale qui n'existe plus | frontend/src/components/layout/Sidebar.tsx:58-172 vs _PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md §4.8 et §6.2 |
| `A05-005` | CRM | `correctif` | La conception console UX v2, référentiel n°4 de l'ordre de mission, décrit une navigation qui n'a jamais existé | _PLANS/2026-08-13_CONCEPTION-console-crm-ux.md §2.2, §2.3, §2.4 et la table des URLs (l. 277-281) · frontend/src/app/routeTree.… |
| `D23-002` | CRM | `correctif` | L'écran d'accueil montre quatre totaux décoratifs et ne dit rien de la journée | frontend/src/features/dashboard/DashboardPage.tsx:111-207 |
| `D23-003` | CRM | `correctif` | L'entrée « Contacts » liste des entreprises, et deux entrées voisines listent la même chose | backend/app/Http/Controllers/Api/Crm/ContactsHubController.php:23 · frontend/src/features/crm-console/ContactsHubPage.tsx:190-2… |
| `D23-004` | CRM | `correctif` | Le renommage de l'étape 0 s'est arrêté à la barre : « campagne » et « scraping » vivent toujours dans les écrans | features/campaigns/*.tsx (25 chaînes) · features/scraping/ScraperRunsPage.tsx:330 · features/dashboard/DashboardPage.tsx:140… |
| `D23-005` | CRM | `correctif` | La recherche globale trouve une personne et ouvre la liste au lieu de sa fiche | frontend/src/components/ui/GlobalSearch.tsx:117 |
| `D23-007` | CRM | `correctif` | La barre ne porte aucun compteur : les six que la cible exige manquent, dont un sur une entrée déjà livrée | frontend/src/components/layout/Sidebar.tsx:44-52 (le type NavItem n'a pas de champ de compteur) |
| `D23-008` | CRM | `conception` | Le groupe « Réglages » ne peut pas devenir les huit sous-groupes du §19 sans dépasser la règle des sept | frontend/src/components/layout/Sidebar.tsx:109-121 · frontend/src/features/settings/SettingsPage.tsx:118 |
| `D23-009` | CRM | `correctif` | Les deux routes retirées à l'étape 0 rendent un 404 sans barre latérale, sans redirection | frontend/src/app/routeTree.tsx:104-107 · frontend/src/features/misc/NotFoundPage.tsx |
| `D23-011` | CRM | `conception` | Le lien permanent CRM → console axionia n'existe pas, et celui de l'autre sens s'appelle « Prospection » | frontend/src/components/layout/Sidebar.tsx (pied de barre) · Axion-IA/axionia/src/components/admin/ui/AdminSidebarNav.tsx:773 |
| `D23-012` | CRM | `conception` | Trois notions portent le même mot dans les deux consoles — « Boîte de réception », « Clients », « Couverture » | axionia/src/lib/admin-nav.ts (ADMIN_NAV_GROUP_LABELS, groupes contacts, content_gen) · frontend/src/features/crm-console/types.… |
| `D24-006` | CRM | `correctif` | Dix-huit écrans sur vingt-six n'offrent aucun lien sortant, dont la liste des personnes et la fiche 360° | frontend/src/features/ — relevé exhaustif dans 04_PREUVES/agent-24/recon-ecrans.json |
| `I48-005` | DOC | `conception` | Quinze objets du code contredisent la cible : ils devront être DÉFAITS, pas complétés | — |
| `I48-007` | CRM | `correctif` | Le mot « console » désigne déjà autre chose que le §19 : le critère 24 est perdu avant le premier écran de réglage | frontend/src/app/routeTree.tsx:97-100 · backend/routes/api.php:263 (prefix('crm'), middleware crm-console) · frontend/src/featu… |
| `I48-008` | CRM | `correctif` | Le seul endroit où le produit DÉPASSE le périmètre : /cold-email, /linkedin et le constructeur d'audiences sont le lot L7, explicitement exclu | frontend/src/app/routeTree.tsx:102-103 · backend/routes/api.php:322-323 · frontend/src/features/audiences/* · backend/routes/ap… |
| `I49-003` | CRM | `correctif` | §22.4 : zéro lien croisé sur douze, et le CRM ne porte aucun lien vers la console axionia — pas même le lien permanent qu'exige le §22.5 | frontend/src/ (dépôt CRM, aucune occurrence) · axionia/src/components/admin/ui/AdminSidebarNav.tsx:771-793 |
| `I49-005` | CRM | `correctif` | §22.6 : la carte « où je vais pour quoi » n'existe que dans le cahier des charges, et ses cinq règles d'application sont à zéro | frontend/src/components/OnboardingTour.tsx:15-60 (le seul dispositif d'orientation existant) · axionia/src/lib/admin-nav.ts · a… |
| `E32-004` | SITE | `conception` | Le drapeau de retrait exigé par le critère 25 n'existe pas, et la forme évidente (variable d'environnement) ne peut pas tenir la minute | src/env.ts:73-78 (les seuls drapeaux CRM du site) · src/server/crm-sync/config.ts:16-28 |
| `E32-005` | SITE | `correctif` | Critère 23 : 17 écrans de relation sur 17 restent atteignables sans redirection vers le CRM, par 4 chemins | src/lib/admin-nav.ts:431-524 · src/app/[locale]/(admin)/[adminPrefix]/contacts/ · AdminCommandPalette.tsx:100-110 · src/server/… |
| `E32-006` | SITE | `correctif` | L'unique lien de la console vers le CRM le présente comme un outil de « Prospection », pointe la racine, et vit hors du SSOT de navigation | src/components/admin/ui/AdminSidebarNav.tsx:771-793 |
| `E32-007` | SITE | `correctif` | Une candidature commerciale s'affiche dans 4 écrans de la console, avec 2 fiches de détail et 2 vocabulaires de statut | src/features/admin-job-applications/actions.ts:184-188 · src/app/[locale]/(admin)/[adminPrefix]/contacts/commercial/page.tsx:14… |
| `E32-009` | CRM | `correctif` | Huit entrées de navigation pour une seule table, là où le CRM en prévoit une avec des onglets | src/lib/admin-nav.ts:447-515 · Axion-CRM-Pro/frontend/src/features/crm-console/ContactsHubPage.tsx:1-11 |
| `E34-004` | SITE | `correctif` | Cinq entrées de navigation du périmètre finances mènent à un 404 quand une variable d'environnement est absente, et la garde de navigation ne peut pas le voir | src/lib/admin-nav.ts:1033-1060 (les 5 entrées) · src/server/qualiopi/config/flag.ts:71 · scripts/check-admin-nav-routes.ts:1-14 |
| `E34-008` | SITE | `correctif` | Le module « Booking » de la console n'est pas le « booking » du CDC : viser le premier détruirait les inscriptions aux sessions | src/app/[locale]/(admin)/[adminPrefix]/{reservations,devis,factures,echeanciers,paiements,options,calendrier}/page.tsx |
| `D29-002` | CRM | `correctif` | Aucun moyen de choisir sa langue : la bascule est subie, jamais offerte | frontend/src/lib/i18n.ts:16 · frontend/index.html:2 |

## 9. Comportement des ecrans -- 10 constats

*Ce qui se passe quand on s'en sert : listes, formulaires, retour arriere.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `D24-005` | CRM | `correctif` | Les filtres et la page ne sont pas dans l'adresse : le retour arrière renvoie l'utilisateur à la page 1, à chaque fiche ouverte | frontend/src/features/companies/CompaniesListPage.tsx (état local useState, aucun search de route) · même patron sur /contacts… |
| `D25-005` | CRM | `correctif` | Un 500 et un 403 sont présentés comme une fiche supprimée : trois écrans de détail affichent « introuvable · 404 » sur une panne | frontend/src/features/companies/CompanyDetailPage.tsx:88-95 · frontend/src/features/media/MediaDetailPage.tsx:51-56 · frontend/… |
| `D25-007` | CRM | `doc` | Aucun état d'écran n'est dans l'URL : filtres, tri, page et onglet ne se partagent pas, ne se rechargent pas, et ne survivent pas au retour arrière | frontend/src/app/routeTree.tsx (aucune route ne déclare validateSearch) · frontend/src/main.tsx:28-32 (createRouter sans scroll… |
| `D25-009` | CRM | `correctif` | Neuf écrans de liste ne demandent aucune limite au serveur et rendent tout ce qu'il envoie : /users à 10 000 lignes construit 160 025 nœuds et 18 Mo de HTML, et n'aboutit plus à 100 000 | frontend/src/features/companies/CompaniesListPage.tsx:297 (seul useVirtualizer du dépôt) · users/UsersPage.tsx:62-64 · rgpd/Aud… |
| `D26-006` | CRM | `correctif` | Douze catch jettent le message du serveur ; sur /2fa, un 500 est présenté comme « Code invalide » | 12 sites, dont LoginPage.tsx:74, MagicLinkPage.tsx:22, PasswordResetPage.tsx:20, TwoFactorPage.tsx:22 |
| `D26-007` | CRM | `correctif` | CampaignWizardPage n'a aucun <form> : ses bornes HTML ne sont jamais appliquées et la touche Entrée est inerte | frontend/src/features/campaigns/CampaignWizardPage.tsx — 8 champs, :769-781, :785-797, :431-437 |
| `D26-009` | CRM | `correctif` | Le bouton Précédent du navigateur ne recule pas d'une étape : il quitte l'assistant et jette la saisie | frontend/src/app/routeTree.tsx:87 · frontend/src/features/campaigns/CampaignWizardPage.tsx:103 (useState<Step>(1)), :290-301 |
| `D26-013` | CRM | `correctif` | L'intercepteur de session détruit le formulaire par une navigation dure, et le jeton CSRF n'est jamais renouvelé | frontend/src/lib/api.ts:12-24 (ensureCsrf), :27-35 (intercepteur de réponse) |
| `D29-007` | CRM | `correctif` | La fiche 360° affiche la chaîne brute de Postgres : elle montre le fuseau du serveur, jamais celui de l'utilisateur | frontend/src/features/crm-console/PersonTimelinePage.tsx:114 · frontend/src/features/crm-console/ArbitragePage.tsx:132 · backen… |
| `H46-004` | CRM | `correctif` | Trois écrans de la console exportent en CSV à travers un type perdu : une colonne renommée produit une colonne vide, silencieusement | backend/app/Http/Controllers/Api/CompaniesController.php (14 entrées), MediaController.php (16), JournalistsController.php (9) |

## 10. Design system, sombre, accessibilite, mobile -- 17 constats

*Le systeme existe et n'est pas employe ; le sombre est contraste a l'envers.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `D27-003` | CRM | `correctif` | /coverage n'importe aucun composant du design system et redéfinit localement quatre de ses noms | frontend/src/features/coverage/CoveragePage.tsx — SegOption:176, SegmentedControl:178, KpiCard:228, Stat:343 |
| `D27-004` | CRM | `correctif` | 23 écrans sur 37 recopient à la main du balisage qu'un composant du système fournit déjà | frontend/src/features/ — tableau complet au §2 de ce rapport, 61 emplacements avec numéros de ligne dans 04_PREUVES/agent-27/06… |
| `D27-005` | CRM | `correctif` | Aucun composant de tableau n'existe : trois idiomes coexistent sur 16 écrans et le même en-tête de 210 caractères est copié à l'identique dans 8 fichiers | frontend/src/components/ui/ (absence) — copies : CompaniesListPage.tsx:684, ContactsListPage.tsx:191, LlmRouterPage.tsx:105, Pr… |
| `D27-006` | CRM | `correctif` | 92 couleurs claires sans variante sombre, dont 11 dans deux composants du système qui n'ont aucun mode sombre | 17 fichiers, tableau complet au §3 — components/ui/SizeCategoryBadge.tsx:4-9, components/ui/QualityBadge.tsx:4-6, features/cove… |
| `D27-008` | CRM | `correctif` | Input et FormField existent, et 30 champs de saisie sont écrits à la main en 19 variantes de classes | frontend/src/components/ui/Input.tsx (10 écrans) et ui/FormField.tsx (1 écran) — champs bruts dans 13 écrans, dont CompaniesLis… |
| `D27-013` | CRM | `correctif` | PageShell n'est employé que par trois écrans, dont deux stubs morts, et le troisième recopie l'en-tête au lieu de s'en servir | frontend/src/components/ui/PageShell.tsx — consommateurs : features/phase2-scaffold/ColdEmailStub.tsx:7, features/phase2-scaffo… |
| `D28-006` | CRM | `doc` | Les 37 écrans partagent le même titre de document | frontend/index.html (le seul <title>) — aucun écran ne le modifie |
| `D28-008` | CRM | `correctif` | role="menu" et role="tab" sont annoncés sans le clavier qu'ils promettent, et la fermeture du menu perd le focus sur <body> | frontend/src/components/ui/DropdownMenu.tsx:58,71 · components/ui/Tabs.tsx:21,25,52,56 · components/ui/SegmentedControl.tsx:23,36 |
| `D28-009` | CRM | `correctif` | Cent treize tailles de police en pixels absolus : le réglage « taille de police » du navigateur ne les atteint pas | 41 fichiers de frontend/src — text-[11px] ×75, text-[10px] ×38 · entre autres components/ui/StatusPill.tsx:64, ui/KpiCard.tsx… |
| `D28-010` | CRM | `correctif` | Le mode sombre n'est pas appliqué sur les cinq écrans hors coquille, dont la page de connexion | frontend/src/components/ui/DarkModeToggle.tsx:14-28 — le thème n'est appliqué que par l'effet de ce composant, monté uniquement… |
| `D28-011` | CRM | `correctif` | Le mode sombre est le mode le plus contrasté à l'envers : 76 défauts de contraste sur 31 écrans, dont le raccourci ⌘K de l'en-tête à 1,36:1 | frontend/src/components/ui/GlobalSearch.tsx:50 et :55 (déclencheur + <kbd>, présents dans l'en-tête des 32 écrans de la coquill… |
| `D30-003` | DOC | `correctif` | 461 cibles tactiles sur 473 mesurent moins de 44 × 44 px, dont 82 moins de 24 × 24 ; la coquille de navigation elle-même n'en a aucune conforme | components/layout/Header.tsx:27-77 (les 8 cibles de coquille présentes sur les 32 écrans à coquille) · components/ui/Button.tsx… |
| `D30-004` | CRM | `conception` | La barre basse à cinq entrées exigée par le §23.3 n'existe pas, et rien n'en tient lieu : la seule navigation sur téléphone est un hamburger de 28 × 28 px | absence — frontend/src/app/RootLayout.tsx (aucun élément de bas d'écran) · frontend/src/components/layout/Header.tsx:27 (le ham… |
| `D30-006` | CRM | `correctif` | La barre repliée ne se replie pas « aux mêmes positions » : 66 à 78 px d'écart, et jusqu'à 19 des 20 entrées n'existent pas dans l'autre mode | frontend/src/components/layout/Sidebar.tsx:270-272 (const deplie = collapsed // ouverte) · :184-186 (une seule section ouverte… |
| `D30-007` | CRM | `correctif` | Sur téléphone, le fil d'Ariane est le seul repère de position, et il est écrasé à 94 px sur les 32 écrans qui en ont un | frontend/src/components/layout/Header.tsx:39 (<div className="min-w-0 flex-1 truncate">) |
| `D29-004` | CRM | `correctif` | Les 63 formatages de date et de nombre sont figés sur fr-FR, et aucun ne fixe de fuseau | 27 fichiers de frontend/src/features (liste complète dans le dossier de preuves) |
| `D29-006` | CRM | `correctif` | Les pluriels sont fabriqués par concaténation de morphèmes isolés : la traduction est impossible par construction | features/campaigns/CampaignWizardPage.tsx:492 et :516 · features/companies/CompaniesListPage.tsx:599-600 et :644 · features/int… |

## 11. Performance -- 8 constats

*Mesure au volume de production, pas en atelier vide.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `G41-009` | CRM | `correctif` | La file d'arbitrage balaye toute la table activities deux fois par affichage | backend/app/Http/Controllers/Api/Crm/ArbitrageController.php:51-59 |
| `G41-011` | CRM | `correctif` | La liste des tags recompte tous les rattachements à chaque affichage, par balayage séquentiel | backend/app/Http/Controllers/Api/TagsController.php:44-51 |
| `G41-013` | CRM | `correctif` | La carte de couverture repose sur une vue matérialisée non peuplée, et son niveau « ville » joint sur une expression non indexable | backend/app/Http/Controllers/Api/CoverageController.php:105-130 · backend/app/Console/Commands/CoverageRefreshMatrix.php:24-25… |
| `G42-002` | CRM | `correctif` | Ce qu'il y a dans le chunk de 1 046 364 octets : 68 % de dépendances, dont 229 403 octets pour trois fonctions qu'un écran sur trente-sept utilise | frontend/package.json:14-42 · frontend/dist/assets/index-*.js |
| `G42-008` | CRM | `correctif` | Aucune mémoïsation dans tout le produit : 0 React.memo, 2 useCallback — chaque frappe re-rend l'écran entier, lignes comprises | tout frontend/src/ |
| `G43-003` | CRM | `correctif` | Les compteurs du hub au volume de production : p95 6,2 s à dix sessions, index posé | backend/app/Crm/Console/CompteursHub.php:148-153 · base axion_crm_perf4m (2 800 000 fiches) · index idx_companies_ws_counts (mi… |
| `G43-008` | CRM | `correctif` | Aucune garde du dépôt n'éprouve la concurrence ; les deux verrous pessimistes du produit ne sont couverts que séquentiellement | backend/tests/Feature/Crm/ConsoleV2Test.php:510-517 · backend/app/Http/Controllers/Api/Crm/ArbitrageController.php:248-264 · ba… |
| `H45-009` | INFRA | `correctif` | La recette locale documentée pour jouer la suite est ~115 fois plus lente que la même suite ailleurs — c'est ce qui fait qu'on ne la joue pas | infra/scripts/worktree/pest-worktree.sh:38-52 (montage bind du code depuis C:\) · Makefile (test-backend) |

## 12. Le filet de tests et la CI -- 20 constats

*Le theme qui conditionne les autres : pourquoi les 148 precedents ont pu vivre.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `A08-005` | INFRA | `correctif` | Trois des six jobs de la CI ne bloquent aucune fusion — dont les deux gardes nées des deux incidents les plus graves du produit | protection de branche GitHub (hors dépôt) vs .github/workflows/ci.yml |
| `A06-007` | CRM | `doc` | La seule preuve de la ligne 1 n'est exécutée par aucun workflow, et son runbook démarre depuis un worktree résiduel | frontend/tests/e2e/console-locale.spec.ts · _REPORTS/RUNBOOK-CONSOLE-LOCALE.md:41,331,344,575 · .github/workflows/a11y.yml:48,58 |
| `B10-015` | INFRA | `conception` | La garde db-rebuild-check n'est jouée par aucun job de CI, et make n'existe pas sur le poste | Makefile:93-109 · .github/workflows/ |
| `B11-005` | CRM | `correctif` | La suite de tests vise une base unique codée en dur : deux exécutions concurrentes se détruisent mutuellement, et la garde d'étanchéité en est sortie ROUGE | backend/phpunit.xml:43-44 (DB_DATABASE=axion_crm_test, force="true", aucune isolation par processus) |
| `B12-011` | CRM | `conception` | 42 routes sur 117 ne sont citées par aucun test, dont tout le parcours d'authentification secondaire et les huit routes d'audiences | backend/tests/ (100 fichiers) |
| `B17-013` | CRM | `conception` | 24 des 35 tâches planifiées n'ont aucun test, dont 11 des 14 commandes destructives | backend/tests/ · backend/routes/console.php |
| `E34-005` | SITE | `correctif` | La suite du site est verte, mais 54 % de ses tests ne s'exécutent pas en intégration continue | src/content/villes/copy/__tests__/quality.test.ts:26 |
| `E34-006` | SITE | `correctif` | Le verrou LF du 2026-08-18 n'a pas été appliqué à la copie de travail : 4 877 fichiers portent encore des CR, et le test que ce verrou devait sauver rougit toujours | src/components/admin/ui/useConfirmation.tsx · src/features/admin-qualiopi/confirmations.spec.ts:115 · .gitattributes (activé pa… |
| `F38-006` | INFRA | `correctif` | Le job Lighthouse rend success depuis toujours en n'ayant produit aucun score : lhci sort en 1 sous continue-on-error | .github/workflows/a11y.yml:65-75 |
| `F38-009` | INFRA | `doc` | Aucun workflow ne mesure la couverture de tests, alors que CONTRIBUTING.md l'annonce comme une quality gate | .github/workflows/ci.yml:245 (coverage: none) |
| `F38-013` | INFRA | `correctif` | Le SAST Semgrep porte un continue-on-error: true non commenté, dans le fichier même qui explique pourquoi on les a retirés | .github/workflows/security.yml:41 |
| `G42-013` | INFRA | `correctif` | La seule garde de performance du dépôt est inerte trois fois : elle n'assure rien, elle ne bloque rien, et elle mesure la préproduction déjà déployée au lieu de la modification en revue | .github/workflows/a11y.yml:65-75 · .github/workflows/ci.yml:435-464 |
| `H44-002` | INFRA | `correctif` | Le seul job qui exécute des tests Playwright n'est pas une vérification requise : il peut rougir sans bloquer ni la fusion ni le déploiement | protection de branche main · .github/workflows/a11y.yml:12 (job axe-playwright) · .github/workflows/deploy-direct-ssh.yml:64-74 |
| `H44-004` | CRM | `correctif` | Le harnais de tests local n'a aucune isolation : toute exécution est épinglée sur l'unique base axion_crm_test, où RefreshDatabase émet DROP TABLE … CASCADE | backend/tests/bootstrap.php:27 (const TEST_DATABASE_NAME = 'axion_crm_test' · ) · backend/tests/TestCase.php:31 (la garde valid… |
| `H44-006` | CRM | `correctif` | 6 écrans de route sur 37 sont montés par un test ; 27 sur 37 ne sont touchés par rien qui s'exécute | frontend/src/app/routeTree.tsx (37 routes) · frontend/tests/screens/ (6 fichiers) · frontend/tests/e2e/a11y.spec.ts, navigation… |
| `H44-011` | CRM | `correctif` | La CI est épinglée sur l'ordre d'exécution qui passe, et aucune porte ne mesure plus le couplage entre tests | backend/phpunit-ci.xml:27 (executionOrder="default") vs backend/phpunit.xml:6 (executionOrder="random") · .github/workflows/ci.… |
| `H45-010` | CRM | `conception` | 25 des 35 tâches planifiées ne sont citées par aucun test — dont quatre destructives — et 8 des 19 --dry-run non plus | backend/routes/console.php · backend/app/Console/Commands/ |
| `H46-001` | CRM | `correctif` | La baseline PHPStan gèle 145 messages de comportement, dont 20 sur des chemins de sécurité, de conformité ou d'export | backend/phpstan-baseline.neon (211 entrées, 248 erreurs) |
| `H46-002` | INFRA | `correctif` | L'entrypoint de production exécute config:cache, alors que 31 fichiers lisent leurs secrets et leurs drapeaux par env() — 45 entrées gelées dans la baseline | infra/docker/entrypoint-prod.sh:44 · Dockerfile.laravel:86-95 · 31 fichiers listés dans baseline-composition.txt (identifiant l… |
| `H46-009` | CRM | `correctif` | 14 promesses non attendues sont gelées, dont 5 invalidations de cache placées juste après un message de succès | frontend/eslint-suppressions.json (règle @typescript-eslint/no-floating-promises, 14 occurrences dans 11 fichiers) — notamment… |

## 13. Sauvegardes et reprise -- 4 constats

*Exigence n. 13 du mandat, toujours a zero.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `F39-002` | INFRA | `correctif` | La surveillance des sauvegardes vérifie qu'un fichier existe, qu'il est récent et qu'il est gros — jamais qu'il est restaurable | infra/scripts/verifier-sauvegarde.sh:79-153, .github/workflows/surveillance-sauvegarde.yml:67-117 |
| `F39-003` | INFRA | `doc` | L'exercice de restauration n'est déclenché par rien : ni CI, ni cron, ni tâche planifiée | infra/scripts/dr-drill.sh · Makefile:140 · infra/runbooks/04-restore-dr.md (dernière ligne : « Test trimestriel obligatoire ») |
| `F39-004` | INFRA | `correctif` | Les trois scripts de sauvegarde de la copie de travail sont syntaxiquement invalides sous Linux — mais ceux qui tournent en production ne le sont pas | infra/scripts/{backup-postgres.sh,dr-drill.sh,verifier-sauvegarde.sh,setup-backup.sh}, infra/docker/entrypoint-prod.sh |
| `F39-012` | INFRA | `correctif` | L'exercice de restauration compare un dump de 03:00 aux comptages VIVANTS de la production : il rougira à tort le premier jour où la prospection tourne | infra/scripts/dr-drill.sh:131-138 (relevé de la référence) et :168-183 (comparaison) |

## 14. Planification des taches -- 3 constats

*Des taches qu'on croit vivantes.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `B17-004` | CRM | `correctif` | Trois tâches planifiées s'auto-sautent depuis l'intérieur de la commande — invisibles dans schedule:list | backend/app/Console/Commands/BlacklistsCheck.php:22-25 · SignalsNightlyScan.php:22-25 · JournalistsScrapeOurs.php:58-61 |
| `B17-006` | CRM | `correctif` | Sept tâches planifiées n'ont aucun verrou, dont un REFRESH MATERIALIZED VIEW non concurrent toutes les heures | backend/routes/console.php:12-18 · backend/app/Console/Commands/CoverageRefreshMatrix.php:22-25 |
| `D29-009` | CRM | `correctif` | signals:nightly-scan ne tourne jamais le 29 mars et tourne deux fois le 25 octobre | backend/routes/console.php:18 |

## 15. Documents qui mentent -- 6 constats

*Des etats declares que le depot contredit.*

| Identifiant | Depot | Nature | Symptome | Fichiers |
|---|---|---|---|---|
| `A06-002` | CRM | `correctif` | Ligne 3 ter déclarée « CLOSE en production » alors que trois de ses quatre sous-critères sont contredits par le dépôt | docker-compose.staging.yml:130,156,177 · backend/config/mail.php:4 · journal _SESSIONS/2026-08-18_PREALABLES-CRM-ETAPE-0.md §8.7 |
| `A06-008` | SITE | `doc` | Le journal §4 annonce 15 lignes closes sur 16 ; sept le sont au sens de leur propre critère | C:\Users\willi\Documents\Projets\Axion-IA\_SESSIONS\2026-08-18_PREALABLES-CRM-ETAPE-0.md §4 · _PLANS/2026-08-18_PREALABLES-AVAN… |
| `A06-010` | SITE | `resteWill` | Le plan qui « fait foi » pour l'étape 0 n'est versionné dans aucun dépôt | C:\Users\willi\Documents\Projets\Axion-IA\_PLANS\2026-08-18_PREALABLES-AVANT-CHANTIER-CRM-CIBLE.md (28 200 octets, 2026-08-18 1… |
| `A07-008` | CRM | `doc` | Trois numérotations concurrentes des fragilités préalables coexistent dans les documents et le code | — |
| `A09-007` | DOC | `doc` | Le runbook de la console locale fait dépendre le démarrage d'un worktree résiduel, alors que le fichier est versionné à la racine | _REPORTS/RUNBOOK-CONSOLE-LOCALE.md:41, :331, :344, :575 |
| `E32-010` | CRM | `doc` | L'état du drapeau CRM_CONSOLE_V2_ENABLED en production n'est pas mesurable de l'extérieur : la route qui l'annonce répond 500 | backend/routes/api.php:257 (dans le groupe auth:sanctum ouvert l. 83) · backend/config/crm.php:168 (défaut false) · backend/app… |

