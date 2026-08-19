# Grille — Automatismes serveur (AGENT 17)

> Périmètre : les **35 tâches planifiées** de `backend/routes/console.php`, les **6 jobs** de
> `backend/app/Jobs/` + le trait `Concerns/RunsInWorkspace`, les **49 commandes Artisan** de
> `backend/app/Console/Commands/`.

## 0. Référence, atelier, et ce qui a réellement été joué

- **Référence** : `main = c0c453d` au démarrage. `HEAD` a avancé pendant l'audit (`1145473`, puis
  `e8924b8`), **mais mon périmètre est identique** :
  `git diff --stat c0c453d..HEAD -- backend/routes/console.php backend/app/Console/ backend/app/Jobs/`
  → **sortie vide**. Tous les constats valent donc pour `c0c453d` **et** pour `e8924b8`.
- **Décompte fait moi-même**, pas lu dans un document :
  `grep -c "Schedule::command" backend/routes/console.php` → **35**. Le prompt d'audit en nomme 10.
  `ls -1 backend/app/Console/Commands/*.php | wc -l` → **49**. `ls backend/app/Jobs/` → **6** + `Concerns/RunsInWorkspace.php`.
- **Atelier** : `axion-crm-scheduler` tourne (`Up`, `command: php artisan schedule:work`),
  `axion-crm-api`, `axion-crm-horizon`, `axion-crm-postgres`, `axion-crm-redis` idem.
- ⚠️ **La base locale `axion_crm` est VIDE** : `companies=0, media=0, scraper_runs=0, audit_logs=0,
  notifications=0, contacts=0, candidates=0, journalists=0, scraping_campaigns=0, coverage_zones=0`,
  `workspaces=1`. **Aucune trace d'exécution n'est donc lisible localement** : toute case « a-t-il
  déjà tourné ? » qui dépend d'un volume de données est marquée `non vérifié — base locale vide`.
- **Base jetable** créée pour l'occasion : `axion_crm_agent17` (mesure B17-001). Aucune écriture sur
  `axion_crm`, aucune sur la production.
- **Production CRM inaccessible en SSH depuis ce poste** : le seul alias `~/.ssh/config` est
  `axion-prod → 178.105.55.15`, qui est le VPS **Axion-IA / Coolify** (`docker ps` joué en lecture
  seule : aucun conteneur `axion-crm-*`). `api.axion-crm-pro.com` résout derrière Cloudflare
  (188.114.97.6) sur un autre VPS. **Je n'ai donc PAS pu vérifier que le planificateur de production
  tourne** — c'est le point 1 de ma liste §5.

Preuves brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-17/`.

---

## 1. Grille des 35 tâches planifiées

Légende des colonnes (les 12 points, dans l'ordre) :
**G1** rôle · **G2** cadence réelle + a-t-il tourné · **G3** en cas d'échec · **G4** idempotence /
verrou · **G5** contexte d'espace · **G6** durée/coût · **G7** périmètre destructif · **G8** auto-skip ·
**G9** alerte si NON-exécution · **G10** observable par un humain · **G11** testé · **G12** mort ?

Trois colonnes ont la **même réponse pour les 35 tâches**, mesurée une fois :

- **G3 — en cas d'échec : SILENCE TOTAL.**
  `grep -c "onFailure\|emailOutputOnFailure\|pingOnFailure\|sendOutputTo\|appendOutputTo\|onSuccess" backend/routes/console.php` → **0**.
  Le planificateur redirige toute sortie vers `/dev/null` (vérifié dans les journaux :
  `'/usr/local/bin/php' 'artisan' campaigns:start-scheduled > '/dev/null' 2>&1`). Une commande qui
  rend `FAILURE` est **indistinguable** d'une qui rend `SUCCESS`. Pas de réessai planifié : le run
  suivant reprend à la cadence normale. Pas d'empilement pour les 30 tâches qui portent
  `withoutOverlapping()` ; **empilement possible pour les 7 qui ne le portent pas** (cf. B17-006).
- **G9 — alerte en cas de NON-exécution : AUCUNE, pour les 35.** Il n'existe ni table de suivi
  (`\dt` sur `axion_crm` : 101 tables, **aucune** table de type `schedule_monitor`/`scheduled_task_logs`),
  ni ping externe (dead-man switch), ni healthcheck sur le conteneur : `docker-compose.prod.yml:80-88`
  pose explicitement `healthcheck: disable: true` sur `scheduler`. Si `schedule:work` meurt ou se fige,
  **rien ne le dit**. L'absence d'événement n'est pas un événement ici.
- **G10 — observable par un humain : NON, pour les 35**, sauf par l'effet indirect sur les données
  (compteurs de la console). Aucune tâche n'écrit un journal d'exécution consultable ; les seules à
  écrire une trace *durable* sont `rgpd:purge-vivier` et `rgpd:purge-business-prospects`
  (`AuditHashChain::record`), et elles sont **inertes** (G8).

| # | Tâche (`Schedule::command`) | G1 rôle | G2 cadence + a-t-il tourné | G4 verrou / idempotence | G5 contexte d'espace | G6 durée / coût | G7 périmètre destructif | G8 auto-skip | G11 testé | G12 mort ? |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `coverage:refresh-matrix` | rafraîchit la vue matérialisée `coverage_matrix_cells` | horaire ; **oui** — invoquée aussi par 3 workflows prod (`prospection-*.yml`), runs `success` du 2026-07-04 | **aucun verrou** (ni `withoutOverlapping` ni `onOneServer`) ; idempotent | **aucun** — la vue est globale, tous espaces confondus | non vérifié (vue vide localement) ; `REFRESH MATERIALIZED VIEW` **non-CONCURRENTLY** → verrou ACCESS EXCLUSIVE (cf. B17-006) | ne supprime pas | non | **0 test** | non |
| 2 | `blacklists:check` | censée interroger les DNSBL sur les IP sortantes | horaire ; **oui, mais ne fait rien** | aucun verrou ; sans objet | sans objet | ~0 | ne supprime pas | **oui, de fait** : `if (env('MOCK_MODE', true)) return SUCCESS` (`BlacklistsCheck.php:22-25`) — `.env:7 MOCK_MODE=true` | **0 test** | **coquille vide** (cf. B17-004) |
| 3 | `audit:verify-chain` | vérifie l'intégrité de la chaîne de hachage des `audit_logs` | 03:00 ; non vérifié — `audit_logs=0` localement | aucun verrou ; lecture seule | **aucun** — vérifie toute la table | non vérifié | lecture seule | non | **0 test** | non — mais son alerte est perdue (cf. B17-003) |
| 4 | `retention:purge` | applique la rétention RGPD (validations, notifications, charges scraper) | 04:00 ; non vérifié — tables vides | aucun verrou ; idempotent en résultat | **aucun** — `DELETE` bruts sans `workspace_id` : purge **tous** les espaces | non vérifié | `DELETE FROM email_validations WHERE expires_at < now()-7d` ; `DELETE FROM notifications WHERE created_at < now()-90d` ; `UPDATE scraper_runs SET response_payload=NULL, payload_path=NULL WHERE created_at < now()-90d` — **aucune journalisation, aucune réversibilité** | non | **0 test** | non — **et son `--dry-run` écrit (B17-001)** |
| 5 | `rgpd:anonymize-ips` | tronque les IP > 30 j dans `audit_logs` + `sessions` | 04:30 ; non vérifié — tables vides | aucun verrou ; **idempotent en résultat mais PAS en coût** : la clause reste vraie après passage → réécrit les mêmes lignes chaque nuit (B17-005) | **aucun** — global | non vérifié ; croît linéairement avec l'historique | `UPDATE audit_logs SET ip = network(ip/24 ou /48) WHERE created_at < J-30 AND ip IS NOT NULL` (irréversible) ; `UPDATE sessions SET ip_address=NULL WHERE last_activity < J-30` | non | **0 test** | non |
| 6 | `anomaly:detect` | détecte taux d'échec scraping et coût LLM proche du plafond | toutes les 15 min ; **oui** — vu dans les journaux du planificateur local (`2026-08-19 11:45:13 Running ['artisan' anomaly:detect] 14 s DONE`) | aucun verrou ; lecture seule | lit `llm_usage` groupé par `workspace_id` (correct) ; `scraper_runs` **sans** filtre d'espace | 14 s mesurées sur base **vide** | lecture seule | non | **0 test** | **à moitié** : l'envoi d'alerte est un commentaire (`// Sprint 11 : send TelegramAlert::dispatch`) — cf. B17-003 |
| 7 | `signals:nightly-scan` | censée détecter les signaux business (levées, recrutements) | 02:00 ; **oui, mais ne fait rien** | aucun verrou ; sans objet | sans objet | ~0 | ne supprime pas | **oui, de fait** : `if (env('MOCK_MODE', true)) return SUCCESS` (`SignalsNightlyScan.php:22-25`) | **0 test** | **coquille vide** (B17-004) |
| 8 | `campaigns:start-scheduled` | passe les campagnes `scheduled` échues en `running` + dispatch `LaunchCampaignJob` | chaque minute ; **oui** — 20 exécutions relevées dans les journaux locaux du 2026-08-19 | `withoutOverlapping()` (mutex Redis, **TTL 86 366 s ≈ 24 h** mesuré) ; idempotent (filtre `status='scheduled'`) | **aucun** — `ScrapingCampaign::query()` sans `WorkspaceContext` ni `workspace_id` | **13 s à 1 min 43 s** mesurées sur base **vide** (0 campagne) | ne supprime pas | non | **0 test** | non — **mais son verrou est un piège (B17-002)** |
| 9 | `audiences:full-refresh` | recalcule `audience_members` des audiences actives auto-refresh | 04:00 ; non vérifié — 0 audience | `withoutOverlapping()` + `onOneServer()` ; idempotent (`insertOrIgnore` dans le job) | filtre `workspace_id` par audience, **pas** de `WorkspaceContext::run` | non vérifié | ne supprime pas directement | non | **0 test** | non |
| 10 | `companies:rescrape-archives --limit=200` | re-dispatch `EnrichCompanyJob` sur les companies archivées sans email | 1er du mois 02:00 ; non vérifié | `withoutOverlapping()` + `onOneServer()` ; idempotent | **aucun contexte posé** ; option `--workspace` non passée par le planificateur → « across all workspaces » | borné (`--limit=200`, 2 s d'espacement) | ne supprime pas | **le `skip()` existe mais NE SKIPPE PLUS** : `Artisan::all()` contient bien `companies:rescrape-archives` (mesuré, `skip-flags.txt`). **Le commentaire de `console.php:29-32` est périmé** (B17-007) | 1 fichier de test | non |
| 11 | `companies:retry-google-places --limit=500` | relance Google Places sur les companies en attente de quota | 1er du mois 03:00 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | idem #10 | borné | ne supprime pas | **`skip()` inopérant** : la commande existe (mesuré) | **0 test** | non |
| 12 | `media:extract-from-companies` | extrait de nouveaux médias (par NAF) depuis `companies` | 05:00 ; non vérifié — `media=0` | `withoutOverlapping()` + `onOneServer()` ; `DB::affectingStatement` avec `ON CONFLICT` (idempotent) | filtre `workspace_id` | non vérifié | ne supprime pas | non | **0 test** | non |
| 13 | `media:sync-from-companies` | fait hériter site/email/tél de l'entreprise vers ses médias | 05:15 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | **RIEN** — `UPDATE` de masse sans filtre `workspace_id` (`MediaSyncFromCompanies.php:30,46`) | non vérifié | `UPDATE media` de masse, écrase site/email/tél ; **irréversible, non journalisé** | non | **0 test** | non |
| 14 | `media:sync-emissions-from-parent` | fait hériter site/email/tél de la chaîne vers ses émissions | 05:20 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | **RIEN** (`MediaSyncEmissionsFromParent.php:50,66,84`) | non vérifié | 3 `UPDATE` de masse ; irréversible | non | 1 fichier de test | non |
| 15 | `media:link-to-companies` | rattache les médias autonomes à leur entreprise (SIREN / nom exact) | dimanche 04:00 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | filtre `workspace_id` (`MediaLinkToCompanies.php:51,67`) | « opération ensembliste sur ~4,3 M companies » (commentaire, **non mesuré**) | `UPDATE media SET company_id=…` de masse | non | 1 fichier de test | non |
| 16 | `media:tag-emissions-status --limit=20000` | marque les émissions Wikidata actuelles/disparues (P582) | dimanche 04:15 ; non vérifié | `withoutOverlapping()` + `runInBackground()` — **pas** `onOneServer()` | option `--workspace` disponible, **non passée** par le planificateur | borné `--limit=20000`, reprenable | `UPDATE media` | non | 1 fichier de test | non |
| 17 | `media:find-websites --limit=20000` | cherche les sites web manquants des médias | toutes les 30 min ; non vérifié | `withoutOverlapping()` + `runInBackground()` + `onOneServer()` | **RIEN** | borné (le commentaire cite une « fuite du DomainFinderService », **non mesurée ici**) | `UPDATE media SET website=…` | non | **0 test** | non |
| 18 | `media:import-opendatasoft cppap` | importe le registre CPPAP | lundi 02:15 ; non vérifié | `withoutOverlapping()` + `onOneServer()` ; « full-refresh idempotent » | filtre `workspace_id` | non vérifié | 🔴 **`DELETE FROM media WHERE source='cppap' AND workspace_id=… ` puis ré-insertion** (`ImportMediaFromOpendatasoft.php:132-139`) — **détruit chaque semaine tout l'enrichissement** de ces médias (B17-008) | non | **0 test** | non |
| 19 | `media:import-opendatasoft spel` | idem, registre SPEL | lundi 02:30 | idem | idem | idem | idem | 🔴 idem B17-008 | non | **0 test** | non |
| 20 | `media:import-opendatasoft agences` | idem, agences | lundi 02:45 | idem | idem | idem | idem | 🔴 idem B17-008 | non | **0 test** | non |
| 21 | `media:import-emissions-wikidata` | importe émissions TV/radio FR + présentateurs (SPARQL) | dimanche 03:00 ; non vérifié | `withoutOverlapping()` + `runInBackground()` — pas `onOneServer()` | `--workspace` non passé | dépend d'un service externe (Wikidata) ; non mesuré | non vérifié | non | 1 fichier de test | non |
| 22 | `media:import-arcom` | importe radios FM + chaînes TV autorisées ARCOM | dimanche 03:30 ; non vérifié | `withoutOverlapping()` + `runInBackground()` | filtre `workspace_id` | non vérifié | 🔴 **`DELETE FROM media WHERE source='arcom' AND workspace_id=…` puis ré-insertion** (`ImportMediaFromArcom.php:171-179`) — B17-008 | non | **0 test** | non |
| 23 | `media:generate-redaction-emails --limit=20000` | fabrique `redaction@`/`contact@` validés MX | toutes les 2 h ; non vérifié | `withoutOverlapping()` + `runInBackground()` | `--workspace` non passé | borné, reprenable | `UPDATE media SET email=…` | non | 1 fichier de test | non |
| 24 | `journalists:scrape-ours --editorial --limit=300` | extrait les journalistes des pages « ours » (LLM Mistral) | 05:40 ; **tourne et refuse** | `withoutOverlapping()` + `runInBackground()` | filtre `workspace_id` | ~0 (refus immédiat) | ne supprime pas | **oui, de fait** : `if (! config('services.media.journalists_enabled', false))` → `MEDIA_JOURNALISTS_ENABLED` **par défaut `false`** (`config/services.php:128`) — refus quotidien silencieux (B17-004) | 1 fichier de test | **inerte** |
| 25 | `media:score-confidence` | note A/B/C la confiance email des médias | 05:25 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | **RIEN** | non vérifié | `UPDATE media SET email_confidence=…` | non | **0 test** | non |
| 26 | `media:link-emissions-to-channels` | rattache les émissions orphelines à leur chaîne (nom normalisé) | dimanche 04:30 ; non vérifié | `withoutOverlapping()` + `runInBackground()` | `--workspace` non passé | borné (`--limit`) | `UPDATE media SET parent_media_id=…` — **détruit 22 h plus tard par #18-20/#22** (B17-008) | non | **0 test** | non |
| 27 | `media:backfill-periodicity` | remplit la périodicité des médias CPPAP/SPEL | lundi 03:15 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | **RIEN** | borné (chunk 1000) | `UPDATE media SET periodicity=…` ; le commentaire de `console.php:125` annonce « no-op tant qu'aucune source fiable n'est branchée » — **le code, lui, écrit** | non | **0 test** | non |
| 28 | `media:import-blogs` | importe les blogs curés depuis `database/data/blogs/blogs.json` | lundi 03:30 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | `--workspace` disponible, non passé | fichier de **619 octets** (mesuré : `ls -la database/data/blogs/`) | insertion | non | **0 test** | quasi — un fichier de 619 o rejoué chaque semaine |
| 29 | `prospection:score-email-confidence` | note A/B/C la confiance email des contacts | 04:45 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | **RIEN** | non vérifié | `UPDATE contacts` | non | **0 test** | non |
| 30 | `retention:prune-scraper-runs --days=90` | supprime les `scraper_runs` > 90 j par lots | 04:20 ; non vérifié — `scraper_runs=0` | `withoutOverlapping()` + `onOneServer()` ; boucle bornée par lots de 50 000 | **RIEN** — supprime dans **tous** les espaces | commentaire : « ≈ 7,6 M lignes constatées en prod » (**non re-mesuré ici**) | `DELETE FROM scraper_runs WHERE created_at < now()-90d` — **non journalisé, irréversible** | non | **0 test** | non |
| 31 | `media:enrich --limit=5000` | scrape le site des médias → emails/tél | toutes les 3 h ; non vérifié | `withoutOverlapping()` + `runInBackground()` | **RIEN** | borné | `UPDATE media` | non | 1 fichier de test | non |
| 32 | `media:clean-emails --threshold=10` | annule les emails médias parasites / sur-partagés grand public | 05:05 ; non vérifié | `withoutOverlapping()` + `onOneServer()` | **RIEN** — nettoie tous les espaces | **charge non bornée** : `DB::table('media')->whereNotNull('email')->get()` charge **tous** les emails en mémoire (`MediaCleanEmails.php:63-72`) | `UPDATE media SET email=NULL, enrich_status='pending' WHERE email IN (…)` + réécriture de `socials` fiche par fiche — **irréversible, non journalisé** | non | **0 test** | non |
| 33 | `rgpd:purge-vivier` | purge le vivier (2 ans / refusés J+90, doctrine CNIL CVthèque) | 2 du mois 03:30 ; **ne tourne jamais** | `withoutOverlapping()` + `onOneServer()` ; transaction | ✅ **`WorkspaceContext::run($vivierId, …)`** — le seul groupe correct | ~0 | `DELETE FROM candidates WHERE COALESCE(derniere_interaction_at, created_at) < J-2ans` **OR** `lifecycle_stage='refuse' AND updated_at < J-90` + `DELETE FROM activities` des mêmes `person_key` ; ✅ **journalisé** (`AuditHashChain`), `--dry-run` disponible | **oui** : `skip(fn () => ! config('crm.purges_enabled'))` — mesuré **`false`** (B17-009) | 1 fichier de test | **inerte** |
| 34 | `rgpd:purge-business-prospects` | purge les fiches personnes froides > 3 ans (CNIL prospection) | 2 du mois 04:15 ; **ne tourne jamais** | `withoutOverlapping()` + `onOneServer()` | ✅ **`WorkspaceContext::run`** par espace, boucle sur tous les workspaces non-vivier | ~0 | `DELETE FROM contacts WHERE (legal_basis='legitimate_interest_b2b' OR legal_basis IS NULL) AND created_at < J-3ans AND NOT EXISTS(activity)` ; ✅ journalisé, `--dry-run` | **oui** : même `skip()`, `crm.purges_enabled=false` (B17-009) | 1 fichier de test | **inerte** |
| 35 | `crm:flush-outbound` | pousse les oppositions nées dans la console vers le site | toutes les 5 min ; **ne tourne jamais** | `withoutOverlapping()` + `onOneServer()` | **RIEN** (pas de `WorkspaceContext`) | ~0 | ne supprime pas | **oui** : `skip(fn () => ! config('crm.outbound_enabled'))` — mesuré **`false`** (B17-009) | 1 fichier de test | **inerte** |

### Récapitulatif des 35

- **Tâches qui s'auto-sautent (G8)** : **9**.
  - Par `skip()` déclaré et **actif** : `rgpd:purge-vivier`, `rgpd:purge-business-prospects`,
    `crm:flush-outbound` (drapeaux mesurés à `false`).
  - Par `skip()` déclaré mais **devenu inopérant** : `companies:rescrape-archives`,
    `companies:retry-google-places` (les deux commandes existent ; le commentaire de `console.php`
    ment).
  - Par **auto-saut caché dans le corps de la commande**, invisible depuis `console.php` et depuis
    `schedule:list` : `blacklists:check`, `signals:nightly-scan` (`MOCK_MODE=true`),
    `journalists:scrape-ours` (`MEDIA_JOURNALISTS_ENABLED=false`). ← ce sont les plus dangereux :
    `schedule:list` les affiche « Next Due », le planificateur les exécute, elles rendent `SUCCESS`,
    **et elles ne font rien**.
  - Cas limite : `media:backfill-periodicity` est annoncée « no-op » par le commentaire de
    `console.php:125` alors que le code écrit — l'inverse du problème.
- **Tâches sans aucun verrou** (`withoutOverlapping` **et** `onOneServer` absents) : **7** — les
  n° 1 à 7, dont `coverage:refresh-matrix` (horaire, verrou ACCESS EXCLUSIVE) et `retention:purge`
  (destructive).
- **Tâches destructives ou modifiant en masse** : **17** sur 35.
- **Tâches sans aucun test** : **24** sur 35.
- **Tâches qui posent un contexte d'espace de travail** (`WorkspaceContext::run`) : **2** sur 35
  (`rgpd:purge-vivier`, `rgpd:purge-business-prospects`) — et ce sont les deux qui ne tournent jamais.
  13 filtrent au moins sur `workspace_id` ; **20 ne posent rien du tout**. Comme
  `CRM_STRICT_WORKSPACE_SCOPE` vaut `false` (`backend/config/crm.php`), aucun garde-fou ne rattrape
  cette absence : les requêtes traversent tous les espaces en silence.

---

## 2. Grille des 6 jobs + le trait `RunsInWorkspace`

| Objet | G1 rôle | G2 déclencheur réel | G3 échec | G4 idempotence / verrou | G5 contexte d'espace | G6 coût | G7 destructif | G8 skip | G9 alerte si absent | G10 observable | G11 testé | G12 mort ? |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `Concerns/RunsInWorkspace` (trait) | impose `WorkspaceContext::run` à tout job qui touche des données scopées ; lève `MissingWorkspaceContextException` si `$workspaceId` vide | — | — | — | c'est **le** garde-fou | — | — | — | — | — | **0 test** | 🔴 **oui, en pratique** : un seul job l'utilise, et **aucun des 5 points de dispatch ne lui passe le `workspaceId`** → la branche gardée n'est jamais empruntée (B17-010) |
| `EnrichCompanyJob` | enrichit une company via le `WaterfallOrchestrator` | dispatché depuis 5 endroits (2 commandes, 2 contrôleurs, `LaunchZoneScrapingJob`) | `tries=3`, backoff 60 s / 5 min / 30 min, puis `failed_jobs` (Horizon) | pas de verrou ; ré-entrant | `use RunsInWorkspace` **mais** `$workspaceId` est optionnel et **vaut `null` aux 5 dispatchs** (mesuré) → branche `enrich()` **sans contexte** | `timeout=600` | non | non | non (Horizon signale l'échec, pas l'absence) | Horizon | 2 fichiers | non |
| `LaunchCampaignJob` | crée N `scraper_runs` pour une campagne (zone × source) | `campaigns:start-scheduled` | `tries=1` — **une seule tentative**, échec = campagne bloquée en `running` | pas de verrou ; **non idempotent** (relancer double les runs) | **RIEN** — lit/écrit `scraping_campaigns`, `scraper_runs` sans `WorkspaceContext` | `timeout=600` | non | `MOCK_SCRAPERS=true` (défaut) → crée des runs `cancelled` au lieu de scraper | non | non | table `scraping_campaigns` | 1 fichier | non |
| `LaunchZoneScrapingJob` | découvre les entreprises d'une zone (INSEE / France Travail / Node) | `LaunchCampaignJob`, `/coverage/launch` | `tries=2` ; `Log::error` + `run.status='failed'` puis `throw` | pas de verrou ; `updateOrCreate` sur `(workspace_id, siren)` → **idempotent** | reçoit `$workspaceId` en paramètre et filtre dessus, **mais n'utilise PAS `RunsInWorkspace`** | `timeout=1800` | non | `MOCK_SCRAPERS=true` → `dispatchNodeWorker()` rend `[]` et le run sort `success` **vide** | non | Horizon + `scraper_runs` | `scraper_runs` | 2 fichiers | non |
| `MonitorCampaignProgressJob` | recalcule l'avancement d'une campagne et se ré-auto-dispatche toutes les 60 s | `LaunchCampaignJob` (+30 s) | `tries=1` : **une exception rompt la chaîne d'auto-dispatch définitivement** → la campagne reste `running` pour toujours, sans alerte (B17-011) | pas de verrou ; idempotent (recalcul complet) | **RIEN** — `SELECT … FROM scraper_runs WHERE campaign_id=?` sans filtre d'espace | `timeout=60`, 1 requête/min/campagne | non | non | **non** — c'est précisément le cas « l'absence d'événement est un événement » | statut de la campagne | **0 test** | non |
| `RefreshAudienceChunkJob` | insère un lot de `audience_members` pour une audience | `AudienceBuilderService::refresh()` via `Bus::batch()` (donc `audiences:full-refresh`) | `tries=2` ; `WaterfallSentry::capture` puis `throw` — **le seul job qui remonte à Sentry** | `insertOrIgnore` → **idempotent** ; file dédiée `audiences-refresh` | lit `$audience->workspace_id` et le recopie, **sans `WorkspaceContext`** | `timeout=600` | non | `$this->batch()?->cancelled()` → sortie propre | non | Horizon + Sentry | `audience_members` | 1 fichier | non |
| `DispatchScrapeJob` | dépose un payload JSON sur une liste Redis lue par les workers Node | `LaunchZoneScrapingJob` quand `MOCK_SCRAPERS=false` | `tries=1`, aucun try/catch — l'exception va dans `failed_jobs` | pas de verrou ; **non idempotent** (chaque appel empile un item) | **RIEN** — le `workspace_id` voyage dans `context`, non appliqué | négligeable | non | non | non | `redis-cli LRANGE axion:scrape:*` | **0 test** | **probablement** : `MOCK_SCRAPERS=true` par défaut, donc jamais atteint hors production |

---

## 3. Classement des 49 commandes : utilisée / morte / dangereuse

Méthode : (a) présence dans les 35 tâches planifiées ; (b) invocation depuis un workflow GitHub
(`grep -rn "php artisan" .github/workflows`) ; (c) présence d'un `DELETE`/`UPDATE` de masse
(`grep -n "->delete()\|DELETE FROM\|affectingStatement\|DB::statement"`). **Aucune** commande n'est
appelée depuis une migration ou un seeder (`grep -rn "Artisan::call" backend/database/` → vide) :
le **piège 22** du dossier ne se déclenche pas ici.

**DANGEREUSES — 14** (suppriment ou écrasent en masse) :

| Commande | Pourquoi dangereuse | Contexte d'espace | Test ? |
|---|---|---|---|
| `prospection:purge-non-commercial` | `DELETE FROM companies WHERE legal_form IS NULL OR left(legal_form,1) <> '5'` — **supprime aussi toute company dont la forme juridique n'a jamais été renseignée** ; pas de `--dry-run`, pas de confirmation, pas de journal | **RIEN** — tous les espaces | ❌ **0** |
| `prospection:purge-non-diffusible` | `DELETE FROM companies WHERE denomination='[ND]'` ; idem | **RIEN** | ❌ **0** |
| `retention:purge` | 2 `DELETE` + 1 `UPDATE` de masse, sans journal ni réversibilité ; **et son `--dry-run` écrit** (B17-001) | **RIEN** | ❌ **0** |
| `retention:prune-scraper-runs` | `DELETE FROM scraper_runs WHERE created_at < J-90` (≈ 7,6 M lignes annoncées en prod) | **RIEN** | ❌ **0** |
| `rgpd:anonymize-ips` | `UPDATE audit_logs SET ip=…` irréversible, rejoué intégralement chaque nuit (B17-005) | **RIEN** | ❌ **0** |
| `media:clean-emails` | annule `media.email` en masse + réécrit `socials`, sans journal ; charge tous les emails en mémoire | **RIEN** | ❌ **0** |
| `media:import-opendatasoft` | `DELETE` + ré-insertion complète par source (B17-008) | `workspace_id` | ❌ **0** |
| `media:import-arcom` | `DELETE` + ré-insertion complète (B17-008) | `workspace_id` | ❌ **0** |
| `media:sync-from-companies` | `UPDATE media` de masse **sans filtre d'espace**, écrase site/email/tél | **RIEN** | ❌ **0** |
| `rgpd:purge-vivier` | `DELETE FROM candidates` + `activities` — **mais** contexte d'espace posé, `--dry-run`, journal d'audit, double verrou | ✅ `WorkspaceContext` | ✅ 1 |
| `rgpd:purge-business-prospects` | `DELETE FROM contacts` — mêmes garanties | ✅ `WorkspaceContext` | ✅ 1 |
| `prospection:reclassify-size --all` | `UPDATE companies SET size_category = CASE … END` **`WHERE 1=1`** — une seule requête, table entière, non bornée (B17-014) | **RIEN** | ❌ **0** |
| `prospection:reclassify-sector --all` | `UPDATE companies SET sector_main = CASE … END WHERE naf IS NOT NULL` — idem (B17-014) | **RIEN** | ❌ **0** |
| `prospection:find-websites` | `UPDATE companies … FROM (VALUES …)` par lots, `--limit=0` par défaut = toute la base ; aucun contexte d'espace | **RIEN** | ❌ **0** |
| `horodatages:corriger` | recale les `timestamptz` de **toutes** les tables `public`, `WHERE 1=1`, avec `ALTER TABLE … DISABLE TRIGGER USER` — **mais simulation par défaut** (`--appliquer` requis), refus si la session n'est pas en UTC, journal en base `horodatages_reprise`, `finally` qui rétablit les déclencheurs | **RIEN** (par nature : opération de schéma) | ✅ 1 |

`rgpd:purge-vivier`, `rgpd:purge-business-prospects` et `horodatages:corriger` sont **les trois seules
opérations destructives correctement construites du dépôt** (contexte d'espace ou justification,
simulation par défaut ou `--dry-run`, journal, test). Les onze autres n'ont ni contexte d'espace,
ni journal, ni test.

**MORTES ou INERTES — 7 avérées + 4 candidates** :

| Commande | Constat |
|---|---|
| `blacklists:check` | corps entier = `if (env('MOCK_MODE', true)) return SUCCESS;` + un `warn` « prévu Sprint 8 ». Planifiée **toutes les heures** depuis. |
| `signals:nightly-scan` | idem, « prévu Sprint 7 ». Planifiée **chaque nuit**. |
| `journalists:scrape-ours` | refuse tant que `MEDIA_JOURNALISTS_ENABLED` (défaut `false`) — planifiée quotidiennement. |
| `crm:flush-outbound` | `skip()` + refus interne, `CRM_OUTBOUND_ENABLED` absent → jamais exécutée (planifiée toutes les 5 min). |
| `rgpd:purge-vivier` / `rgpd:purge-business-prospects` | inertes par construction assumée (`CRM_PURGE_ENABLED` absent) — **mais l'inertie a un coût RGPD**, cf. B17-009. |
| `media:import-press-kit` | 🔴 **MORTE — zéro référence** dans tout le dépôt hors sa propre classe : ni planifiée, ni dans un workflow, ni dans le `Makefile`, ni dans la documentation. 449 lignes de code que rien n'appelle. |

**Candidates mortes — 4** (citées uniquement dans de la documentation, des commentaires ou des
variables d'environnement ; aucun appelant exécutable) : `naf:import` (3 fichiers, tous
`CHANGELOG.md` / `PROGRESS.md` / un commentaire de seeder), `rpps:import` (5 fichiers, tous
`.env*` / `config/services.php` / journal), `scraping:backfill-src-tags` (2 fichiers, commentaires
de drapeau), `horodatages:corriger` (2 fichiers, commentaires — opération ponctuelle déjà soldée).
Je ne les déclare pas mortes : un opérateur peut les jouer à la main, et pour trois d'entre elles
c'est exactement l'usage prévu.

**UTILISÉES — 44 des 49** (le classement « morte/inerte » ci-dessus est **orthogonal** : six des sept
inertes sont planifiées, donc comptées ici aussi — elles sont appelées, elles ne font simplement rien) :

- les **33 signatures planifiées** (35 tâches moins les 2 doublons de `media:import-opendatasoft`) ;
- **8 commandes supplémentaires invoquées par les workflows GitHub `workflow_dispatch` contre la
  production** (`coverage:refresh-matrix` l'est aussi, mais est déjà comptée ci-dessus) :
  `prospection:collect`, `prospection:enrich`, `prospection:find-websites`,
  `prospection:reclassify-size`, `prospection:reclassify-sector`, `prospection:stats`,
  `prospection:purge-non-diffusible`, `prospection:purge-non-commercial`, `coverage:refresh-matrix`.
  Historique vérifié : `gh run list --workflow=prospection-reclassify.yml` → **5 exécutions
  `success` le 2026-07-04**, chacune ayant joué **les deux purges destructives et les deux
  `reclassify --all`** en production ;
- **2 commandes vivantes par le `Makefile`** : `ign:import-admin-express`, `app:pentest-self-check` ;
- **1 commande vivante par procédure manuelle documentée** : `scraping:ingest-file`
  (`_IMPORTS/2026-08-15-implantations-roumanie/README.md`).

Total : 33 + 8 + 2 + 1 = **44 utilisées**, **1 morte** (`media:import-press-kit`), **4 candidates
mortes** → 49. Le compte est bouclé.

---

## 4. Constats

### [B17-001] `retention:purge --dry-run` exécute réellement l'`UPDATE` qu'il prétend seulement compter
- Sévérité      : S1 grave
- Domaine       : backend / conformité
- Référence     : main c0c453d (périmètre identique à e8924b8)
- Emplacement   : `backend/app/Console/Commands/RetentionPurge.php:38-42`
- Constat       : en mode `--dry-run`, la réécriture d'une requête `UPDATE` multi-lignes en `SELECT COUNT(*)` échoue silencieusement, et la requête d'origine est passée telle quelle à `DB::selectOne()`, qui l'exécute.
- Preuve        : `04_PREUVES/agent-17/retention-purge-dryrun-regex.txt` — la double `preg_replace` rend `bool(false)` (SQL inchangé), parce que `/^UPDATE (\w+) SET .* WHERE/` n'a pas le modificateur `/s` et que le SQL de `RetentionPurge.php:32-34` porte un saut de ligne entre `payload_path = NULL` et `WHERE`.
  `04_PREUVES/agent-17/retention-purge-dryrun-execute.txt` — reproduction sur la base **jetable** `axion_crm_agent17` créée pour l'occasion : avant `id=1 → ('PAYLOAD','path/a')`, après le passage du seul bloc `--dry-run` → `id=1 → (NULL, NULL)`, `id=2` (récent) intact. **Reproduit trois fois.**
  Le compte rendu à l'opérateur est `0` : `DB::selectOne()` d'un `UPDATE` rend `null`, et `null->c ?? 0` vaut `0` sans avertissement.
- Témoin négatif: `04_PREUVES/agent-17/temoin-negatif-regex.txt` — le **même** code appliqué au `DELETE FROM notifications …` (mono-ligne) rend `bool(true)` et produit bien `SELECT COUNT(*) AS c FROM notifications WHERE …`. Le mécanisme sait donc transformer ; il échoue précisément sur l'`UPDATE` multi-lignes.
- Impact        : l'opérateur qui veut *estimer* l'effet de la purge de rétention détruit en réalité les `response_payload` et `payload_path` de tous les `scraper_runs` de plus de 90 jours, dans tous les espaces, et lit « 0 lignes seraient affectées ». Destruction irréversible, silencieuse, présentée comme une simulation.
- Reproduction  : `docker exec axion-crm-api php artisan retention:purge --dry-run` sur une base contenant des `scraper_runs` de plus de 90 jours ; comparer `SELECT count(*) FROM scraper_runs WHERE response_payload IS NOT NULL` avant/après.
- Correctif     : ajouter le modificateur `/s` **et** ancrer sur la vraie forme (`/^UPDATE\s+(\w+)\s+SET\s+.*?\sWHERE/s`), ou — plus sûr — remplacer les trois SQL bruts par des `Builder` et faire porter le `--dry-run` par `->count()` sur le même builder que le `->delete()`/`->update()`. Coût : ~1 h + 1 test qui pré-remplit une ligne éligible et vérifie qu'elle est **intacte** après `--dry-run`.
- Statut        : ouvert

### [B17-002] Le verrou `withoutOverlapping()` a un TTL de 24 h et le déploiement tue le planificateur en pleine tâche
- Sévérité      : S1 grave
- Domaine       : backend / performance
- Référence     : main c0c453d
- Emplacement   : `backend/routes/console.php` (30 tâches sur 35) ; `docker-compose.prod.yml:80-88` ; `.github/workflows/deploy-direct-ssh.yml:200`
- Constat       : le mutex Redis posé par `withoutOverlapping()` porte un TTL mesuré de 86 366 s (≈ 24 h) ; le déploiement recrée le conteneur `scheduler` à chaque poussée sur `main`, avec le délai d'arrêt Docker par défaut (10 s), alors que les tâches mesurées durent jusqu'à 1 min 43 s.
- Preuve        : `04_PREUVES/agent-17/mutex-redis.txt` — `redis-cli --scan --pattern "*framework/schedule*"` rend une clé vivante, `TTL` → **86366**.
  `04_PREUVES/agent-17/scheduler-logs.txt` — durées réelles de `campaigns:start-scheduled` sur une base **vide** : 13 s, 14 s (×6), 15 s (×3), 18 s (×2), 20 s, 26 s (×2), 41 s, **1 min 43 s**.
  `grep -rn "stop_grace_period" docker-compose*.yml` → **aucune occurrence** (donc 10 s, défaut Docker).
  `.github/workflows/deploy-direct-ssh.yml:200` : `docker compose up -d --build --force-recreate --no-deps api app horizon scheduler`.
- Témoin négatif: la même commande `redis-cli --scan` sur un motif inexistant (`*schedule-monitor*`) ne rend rien : le balayage sait distinguer présence et absence.
- Impact        : un déploiement qui tombe pendant l'une des 30 tâches verrouillées laisse un mutex orphelin dans Redis. La tâche **ne s'exécute plus du tout** pendant 24 h — sans erreur, sans journal, sans alerte. Pour `campaigns:start-scheduled` (chaque minute) et `crm:flush-outbound` (5 min), c'est une journée entière d'inaction invisible.
- Reproduction  : lancer `campaigns:start-scheduled`, tuer le conteneur `scheduler` pendant le run (`docker kill`), puis `redis-cli TTL <clé framework/schedule-*>` → ≈ 86400 ; `schedule:list` continue d'annoncer « Next Due » et le planificateur ne l'exécutera pas.
- Correctif     : (a) borner explicitement chaque verrou (`withoutOverlapping(10)` ou une durée proche de la durée réelle de la tâche) ; (b) poser `stop_grace_period: 120s` sur le service `scheduler` dans `docker-compose.prod.yml`. Coût : ~1 h, sans risque.
- Statut        : ouvert

### [B17-003] Les 35 tâches n'ont aucun canal d'échec : la sortie part dans `/dev/null` et aucune n'a de `onFailure`
- Sévérité      : S1 grave
- Domaine       : backend / sécurité
- Référence     : main c0c453d
- Emplacement   : `backend/routes/console.php` (fichier entier, 170 l.) ; `backend/app/Console/Commands/AnomalyDetect.php:66` ; `backend/app/Console/Commands/AuditVerifyChain.php:24-26`
- Constat       : aucune des 35 tâches ne déclare `onFailure`, `emailOutputOnFailure`, `pingOnFailure`, `sendOutputTo` ou `appendOutputTo`, et le planificateur redirige toute sortie vers `/dev/null`.
- Preuve        : `grep -c "onFailure\|emailOutputOnFailure\|pingOnFailure\|sendOutputTo\|appendOutputTo\|onSuccess" backend/routes/console.php` → **0** (`04_PREUVES/agent-17/reference-et-comptes.txt`).
  `04_PREUVES/agent-17/scheduler-logs.txt` : chaque ligne exécutée est `'/usr/local/bin/php' 'artisan' <cmd> > '/dev/null' 2>&1`.
  Deux conséquences concrètes, dans le code :
  `AuditVerifyChain.php:24-26` — en cas de falsification détectée, la commande écrit `Audit hash chain INVALIDE` sur stdout et rend `FAILURE` ; le commentaire dit « En prod : envoi Slack/Telegram » — **il n'y a pas de code**. La sortie va dans `/dev/null`, le code de retour n'est lu par personne.
  `AnomalyDetect.php:66` — `// Sprint 11 : send TelegramAlert::dispatch($anomalies);` est un **commentaire**. Les anomalies détectées (taux d'échec scraping > 15 %, coût LLM proche du plafond) sont écrites sur stdout, donc perdues, toutes les 15 minutes.
- Témoin négatif: le même `grep` sur `Schedule::command` rend **35** : le motif de recherche fonctionne sur ce fichier ; le `0` est bien une absence, pas un échec de grep.
- Impact        : une falsification de la chaîne d'audit (le mécanisme même censé prouver l'intégrité RGPD) est détectée chaque nuit à 03:00 et **jamais rapportée**. Un dépassement de plafond de coût LLM est détecté toutes les 15 min et jamais rapporté. Le détecteur d'anomalies est un détecteur sans destinataire.
- Reproduction  : `docker exec axion-crm-api php artisan audit:verify-chain; echo $?` sur une chaîne corrompue → `1` ; puis constater qu'aucun canal (Sentry, Telegram, Slack, table) n'a reçu quoi que ce soit.
- Correctif     : (a) `->onFailure(fn () => …)` avec un envoi Sentry sur les 6 tâches critiques (`audit:verify-chain`, les 3 purges, `retention:prune-scraper-runs`, `anomaly:detect`) ; (b) remplacer les deux commentaires par du code d'envoi réel ; (c) `->appendOutputTo(storage_path('logs/schedule.log'))` pour toutes. Coût : ~4 h.
- Statut        : ouvert

### [B17-004] Trois tâches planifiées s'auto-sautent **depuis l'intérieur de la commande** — invisibles dans `schedule:list`
- Sévérité      : S2 défaut
- Domaine       : backend / tests
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/BlacklistsCheck.php:22-25` ; `SignalsNightlyScan.php:22-25` ; `JournalistsScrapeOurs.php:58-61`
- Constat       : `blacklists:check` (horaire), `signals:nightly-scan` (nocturne) et `journalists:scrape-ours` (quotidienne) sortent en `SUCCESS` (ou en refus silencieux) dès la première ligne de `handle()`, sans que ni `console.php` ni `schedule:list` ne le laissent voir.
- Preuve        : `04_PREUVES/agent-17/schedule-list.txt` — les trois apparaissent avec un « Next Due » ordinaire, indistinguables des 32 autres.
  Corps mesuré : `BlacklistsCheck.php:22` `if (env('MOCK_MODE', true)) { … return self::SUCCESS; }` ; `SignalsNightlyScan.php:22` idem ; `JournalistsScrapeOurs.php:58` `if (! config('services.media.journalists_enabled', false)) { … }`.
  Valeurs mesurées : `backend/.env:7 MOCK_MODE=true` ; `backend/config/services.php:128` `'journalists_enabled' => filter_var(env('MEDIA_JOURNALISTS_ENABLED', false), …)`.
  Les deux premières portent en outre un `warn` « implémentation réelle prévue Sprint 7 / Sprint 8 » — les sprints sont passés.
- Témoin négatif: `04_PREUVES/agent-17/skip-flags.txt` — la même méthode de lecture (`config()` dans le conteneur `api`) rend bien `false` pour `crm.purges_enabled` **et** confirme la présence de deux commandes dans `Artisan::all()` : elle distingue donc vrai et faux.
- Impact        : trois lignes vertes par heure/par nuit qui ne témoignent de rien. Personne ne peut savoir, depuis `schedule:list` ni depuis les journaux, que la surveillance des listes noires DNSBL et la détection de signaux business n'existent pas.
- Reproduction  : `docker exec axion-crm-api php artisan blacklists:check; echo $?` → texte « MOCK_MODE — blacklists check skipped » et code `0`.
- Correctif     : déplacer la condition dans le `skip()` de `console.php` (elle devient visible dans `schedule:list`), ou faire rendre `FAILURE` aux coquilles non implémentées. Coût : ~2 h.
- Statut        : ouvert

### [B17-005] `rgpd:anonymize-ips` réécrit chaque nuit l'intégralité de l'historique `audit_logs`
- Sévérité      : S2 défaut
- Domaine       : performance
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/AnonymizeOldIps.php:23-31`
- Constat       : la clause de sélection est `WHERE created_at < J-30 AND ip IS NOT NULL` ; la troncature laisse `ip` non nulle (`192.168.42.123` → `192.168.42.0`), donc les mêmes lignes retombent dans la clause à chaque exécution.
- Preuve        : lecture du SQL (`AnonymizeOldIps.php:25-29`) — l'`UPDATE` pose `ip = host(network(ip::cidr / 24|48))::inet`, jamais `NULL`. Aucun marqueur (`anonymized_at`, drapeau) n'existe : `\d audit_logs` ne porte aucune colonne de ce type. Contraste mesuré dans la même commande : le second bloc, sur `sessions`, pose `ip_address = NULL` et **converge**, lui.
  Pas de traitement par lots : un seul `UPDATE` sur une table partitionnée.
- Témoin négatif: non applicable — c'est une lecture de code, pas un test. Je n'ai **pas** pu mesurer le volume : `audit_logs = 0` localement (base vide). Le mécanisme est certain, l'ampleur ne l'est pas.
- Impact        : le coût de la tâche croît linéairement avec l'âge du système, sans plafond. À 04:30, un `UPDATE` non batché sur toute la table partitionnée `audit_logs` produit une réécriture complète (bloat, WAL, vacuum) chaque nuit. Effet secondaire : `updated_at` n'existe pas sur `audit_logs`, donc la réécriture est indétectable.
- Reproduction  : sur une base de volume (`axion_crm_perf4m`), jouer la commande deux nuits de suite et comparer `pg_stat_user_tables.n_tup_upd` — la seconde exécution doit rendre 0 si la tâche converge ; elle rendra le même nombre.
- Correctif     : ajouter `AND ip <> host(network(ip::cidr / …))::inet` à la clause (la ligne déjà tronquée est exclue), et traiter par lots. Coût : ~2 h + 1 test de convergence.
- Statut        : ouvert

### [B17-006] Sept tâches planifiées n'ont aucun verrou, dont un `REFRESH MATERIALIZED VIEW` non concurrent toutes les heures
- Sévérité      : S2 défaut
- Domaine       : performance
- Référence     : main c0c453d
- Emplacement   : `backend/routes/console.php:12-18` ; `backend/app/Console/Commands/CoverageRefreshMatrix.php:22-25`
- Constat       : les sept premières tâches (`coverage:refresh-matrix`, `blacklists:check`, `audit:verify-chain`, `retention:purge`, `rgpd:anonymize-ips`, `anomaly:detect`, `signals:nightly-scan`) sont déclarées sans `withoutOverlapping()` ni `onOneServer()`, contrairement aux 28 suivantes ; et `coverage:refresh-matrix` exécute par défaut `REFRESH MATERIALIZED VIEW` **sans** `CONCURRENTLY`.
- Preuve        : `backend/routes/console.php:12-18` — sept `Schedule::command(...)->hourly()/->dailyAt()/->everyFifteenMinutes()` nus, à comparer aux lignes 21+ qui portent toutes au moins `withoutOverlapping()`.
  `CoverageRefreshMatrix.php:22-25` : l'option `--concurrent` existe, **le planificateur ne la passe pas** (`04_PREUVES/agent-17/schedule-list.txt` : `php artisan coverage:refresh-matrix`, sans option).
- Témoin négatif: le comptage inverse le prouve : sur les 35 lignes, `grep -c withoutOverlapping backend/routes/console.php` rend 28 — le motif sait donc trouver ; les 7 sont bien des absences.
- Impact        : `REFRESH MATERIALIZED VIEW` sans `CONCURRENTLY` prend un verrou **ACCESS EXCLUSIVE** sur `coverage_matrix_cells` : toute lecture de la matrice de couverture depuis la console est bloquée pendant le rafraîchissement, chaque heure. Sans `withoutOverlapping()`, un rafraîchissement qui dépasse une heure fait la queue derrière lui-même. `retention:purge` (destructive) et `rgpd:anonymize-ips` sont dans le même lot non verrouillé.
- Reproduction  : sur une base de volume, `\timing` puis `REFRESH MATERIALIZED VIEW coverage_matrix_cells;` dans une session, et `SELECT * FROM coverage_matrix_cells LIMIT 1;` dans une autre → la seconde attend.
- Correctif     : ajouter `->withoutOverlapping()->onOneServer()` aux sept, et passer `--concurrent` dans le planificateur (nécessite un index unique sur la vue — à vérifier). Coût : ~1 h + vérification de l'index.
- Statut        : ouvert

### [B17-007] Le commentaire de `console.php` affirme que `companies:rescrape-archives` s'auto-saute ; la commande existe et la tâche s'exécute
- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main c0c453d
- Emplacement   : `backend/routes/console.php:29-40` et `:42-50`
- Constat       : le commentaire dit « la commande est codée dans le Sprint Hardening (H6) ; en attendant, le schedule est posé mais s'auto-skip si la commande n'existe pas » ; les deux commandes existent, le `skip()` rend donc `false` et les deux tâches s'exécutent réellement.
- Preuve        : `04_PREUVES/agent-17/skip-flags.txt` — `array_key_exists('companies:rescrape-archives', Artisan::all())` → **`true`** ; idem pour `companies:retry-google-places`. Fichiers présents : `backend/app/Console/Commands/RescrapeArchivesCommand.php` (99 l.) et `RetryGooglePlacesCommand.php` (108 l.).
- Témoin négatif: la même sonde rend `false` sur `config('crm.purges_enabled')` : elle sait distinguer présent et absent.
- Impact        : le commentaire est le seul endroit qui décrit le comportement de ces deux tâches ; il décrit l'inverse. Un lecteur qui cherche pourquoi le 1er du mois une vague de 700 `EnrichCompanyJob` part vers INSEE/Brave lira « s'auto-skip » et cherchera ailleurs. Le `skip()` lui-même est devenu du code mort qu'il faut évaluer 12 fois par an.
- Reproduction  : `docker exec axion-crm-api php artisan schedule:list | grep rescrape` → la tâche est listée « Next Due » ; la commande répond à `php artisan companies:rescrape-archives --dry-run`.
- Correctif     : retirer les deux `skip()` et les commentaires périmés. Coût : 15 min.
- Statut        : ouvert

### [B17-008] Les trois imports hebdomadaires de médias suppriment puis réinsèrent leur source — l'enrichissement de la semaine est détruit, et les journalistes sont détachés
- Sévérité      : S1 grave
- Domaine       : backend
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/ImportMediaFromOpendatasoft.php:132-139` ; `backend/app/Console/Commands/ImportMediaFromArcom.php:171-179`
- Constat       : chaque import hebdomadaire ouvre une transaction qui fait `DELETE FROM media WHERE source = <tag> AND workspace_id = <ws>` puis réinsère les lignes fraîches de la source, avec `enrich_status = 'pending'` et sans les champs enrichis.
- Preuve        : lecture du code (les deux blocs sont identiques, commentés « Full-refresh idempotent »). Les lignes réinsérées sont construites en amont (`ImportMediaFromOpendatasoft.php:118-127`) avec `'website_status' => $website ? 'found' : 'pending'`, `'enrich_status' => 'pending'` — **aucune reprise** de `email`, `phone`, `socials`, `email_confidence`, `parent_media_id`, `company_id`.
  `04_PREUVES/agent-17/fk-media.txt` — `pg_constraint` : `journalists_media_id_fkey` et `media_parent_media_id_fkey` portent tous deux `confdeltype = 'n'` (**SET NULL**). La suppression détache donc les journalistes et casse les liens émission→chaîne.
  Calendrier mesuré (`04_PREUVES/agent-17/schedule-list.txt`) : `media:link-emissions-to-channels` (qui construit `parent_media_id`) tourne **dimanche 04:30** ; `media:import-opendatasoft` tourne **lundi 02:15/02:30/02:45** et `media:import-arcom` **dimanche 03:30**. Le travail de rattachement est effacé ~22 h après avoir été fait.
- Témoin négatif: la requête `pg_constraint` rend deux lignes ; la même requête sur une table sans référence entrante (`SELECT … WHERE confrelid='naf_sections'::regclass`) rend zéro : elle sait distinguer.
- Impact        : les tâches d'enrichissement qui tournent toutes les 30 min (`media:find-websites`), toutes les 2 h (`media:generate-redaction-emails`), toutes les 3 h (`media:enrich`) et chaque jour (`media:score-confidence`, `media:sync-*`) travaillent une semaine sur les médias CPPAP/SPEL/agences/ARCOM ; le lundi matin tout est remis à `pending`. Les journalistes acquis (donnée personnelle, coûteuse en LLM) perdent leur `media_id`. Le cycle se répète indéfiniment et consomme des appels externes payants pour un résultat détruit.
- Reproduction  : sur une base de volume, `SELECT count(*) FROM media WHERE source='cppap' AND email IS NOT NULL` ; jouer `media:import-opendatasoft cppap` ; rejouer le compte → 0. Puis `SELECT count(*) FROM journalists WHERE media_id IS NULL` avant/après.
- Correctif     : remplacer le `DELETE`+`INSERT` par un `upsert` sur la clé naturelle (`(workspace_id, cppap_number)` a déjà un index unique — `media_workspace_cppap_uidx`), en ne mettant à jour que les colonnes issues de la source ; marquer les lignes disparues (`deleted_at`) au lieu de les supprimer. Coût : ~1 j + test qui pré-enrichit une ligne et vérifie qu'elle survit à l'import.
- Statut        : ouvert

### [B17-009] Les deux seules purges RGPD correctement construites ne s'exécutent jamais : l'échéance CNIL n'est tenue par aucun automatisme
- Sévérité      : S1 grave (S0 si des données dépassent déjà les durées annoncées en production)
- Domaine       : conformité
- Référence     : main c0c453d
- Emplacement   : `backend/routes/console.php:148-158` ; `backend/config/crm.php:121`
- Constat       : `rgpd:purge-vivier` et `rgpd:purge-business-prospects` sont gardées par `skip(fn () => ! config('crm.purges_enabled'))`, et `crm.purges_enabled` vaut `false` — le drapeau `CRM_PURGE_ENABLED` n'est déclaré dans aucun fichier d'environnement du dépôt.
- Preuve        : `04_PREUVES/agent-17/skip-flags.txt` — `config("crm.purges_enabled")` → **`false`**, `env("CRM_PURGE_ENABLED")` → **`NULL`**.
  `backend/config/crm.php:121` : `'purges_enabled' => env('CRM_PURGE_ENABLED', false)`.
  `grep -rn "CRM_PURGE_ENABLED" --include=".env*" --include="*.yml"` sur le dépôt → aucune déclaration.
  Double verrou confirmé : la commande elle-même refuse (`RgpdPurgeVivier.php:36-40` rend `FAILURE`).
  Idem pour `crm:flush-outbound` : `config("crm.outbound_enabled")` → `false`, `env("CRM_OUTBOUND_ENABLED")` → `NULL`, tâche planifiée toutes les 5 minutes qui n'a jamais tourné.
- Témoin négatif: la même sonde rend `true` pour l'existence des commandes `companies:rescrape-archives` / `companies:retry-google-places` : elle n'est pas systématiquement négative.
- Impact        : le consentement v2 promet une conservation « pendant 2 ans » ; l'automatisme qui tient cette promesse est construit, testé, journalisé — et désarmé. La durée réelle de conservation est donc **illimitée**. La purge business (standard CNIL prospection, 3 ans) est dans le même état. Ce sont, ironiquement, les deux seules purges du dépôt qui posent un contexte d'espace, écrivent dans la chaîne d'audit et offrent un `--dry-run` : la qualité est là, l'exécution n'y est pas.
- Reproduction  : `docker exec axion-crm-api php artisan rgpd:purge-vivier --dry-run` → « CRM_PURGE_ENABLED n'est pas à true — purge construite mais inerte », code de retour 1.
- Correctif     : décision du dirigeant. Techniquement : poser `CRM_PURGE_ENABLED=true` en production après un `--dry-run` dont on lit le compte. Coût : 10 min + la lecture du dry-run. **Ne pas** activer sans avoir joué le dry-run : la clause `COALESCE(derniere_interaction_at, created_at) < J-2ans` supprimera d'un coup tout le stock importé il y a plus de deux ans.
- Statut        : ouvert

### [B17-010] Le trait `RunsInWorkspace` n'est jamais emprunté : les 5 points de dispatch d'`EnrichCompanyJob` omettent le `workspaceId`
- Sévérité      : S1 grave
- Domaine       : sécurité / backend
- Référence     : main c0c453d
- Emplacement   : `backend/app/Jobs/Concerns/RunsInWorkspace.php` ; `backend/app/Jobs/EnrichCompanyJob.php:85-105` ; les 5 appelants
- Constat       : `EnrichCompanyJob` est le seul job qui utilise `RunsInWorkspace`, son `$workspaceId` est optionnel, et **aucun des cinq points de dispatch ne le fournit** — la branche gardée n'est donc jamais exécutée.
- Preuve        : `grep -rn "EnrichCompanyJob::dispatch" backend/app backend/routes` rend exactement cinq lignes, toutes avec un seul argument :
  `app/Console/Commands/RescrapeArchivesCommand.php:85` `EnrichCompanyJob::dispatch($company->id)` — alors que la requête a chargé `workspace_id` (`:71`) sans l'utiliser ;
  `app/Console/Commands/RetryGooglePlacesCommand.php:94` ; `app/Http/Controllers/Api/CompaniesController.php:437` `EnrichCompanyJob::dispatch((int) $id)` ; `app/Http/Controllers/Api/CoverageController.php:302` ; `app/Jobs/LaunchZoneScrapingJob.php:104`.
  `EnrichCompanyJob.php:98-102` : `if ($this->workspaceId === null) { $this->enrich($waterfall); return; }` — la garde est court-circuitée avant d'être atteinte.
  Les 5 autres jobs (`LaunchCampaignJob`, `LaunchZoneScrapingJob`, `MonitorCampaignProgressJob`, `RefreshAudienceChunkJob`, `DispatchScrapeJob`) **n'utilisent pas le trait du tout**, alors qu'ils lisent et écrivent `companies`, `scraper_runs`, `scraping_campaigns`, `audience_members`.
- Témoin négatif: le même `grep` sur `WorkspaceContext::run` rend 25 occurrences réelles dans `app/` (contrôleurs CRM, services d'ingestion, 3 commandes) : le motif sait trouver. L'absence dans les jobs est donc mesurée, pas déduite.
- Impact        : c'est exactement le défaut que le trait dit prévenir — son propre commentaire annonce « sans ce garde-fou, un job de purge verrait zéro ligne sous RLS stricte et sortirait en succès sans avoir rien purgé ». Aujourd'hui `CRM_DB_APP_ROLE_ENABLED` et `CRM_STRICT_WORKSPACE_SCOPE` valent `false`, donc rien ne casse : la garde est **irréprochable et ne mesure aucun objet**. Le jour où l'un des deux drapeaux passe à `true`, les six jobs de la file échouent ou tournent à vide, et rien ne le signalera (cf. B17-003).
- Reproduction  : poser `CRM_STRICT_WORKSPACE_SCOPE=true`, dispatcher `EnrichCompanyJob` depuis `/companies/{id}/enrich`, observer dans Horizon soit une `MissingWorkspaceContextException`, soit — pire — un succès sans effet.
- Correctif     : (a) rendre `$workspaceId` obligatoire dans `EnrichCompanyJob` et le passer aux 5 appelants ; (b) faire porter `RunsInWorkspace` aux 5 autres jobs ; (c) un test qui dispatche chaque job sous `CRM_STRICT_WORKSPACE_SCOPE=true` et **le voit rougir**. Coût : ~1 j.
- Statut        : ouvert

### [B17-011] `MonitorCampaignProgressJob` a `tries=1` : une seule exception fige la campagne en `running` pour toujours
- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main c0c453d
- Emplacement   : `backend/app/Jobs/MonitorCampaignProgressJob.php:546,637`
- Constat       : le job se ré-auto-dispatche à 60 s à la **dernière ligne** de `handle()` ; toute exception levée avant (le `$campaign->update()` de la ligne 607 n'est pas dans le `try`) interrompt définitivement la chaîne, et `tries=1` interdit le réessai.
- Preuve        : lecture du code — `public int $tries = 1;` (`:546`) ; le `try/catch` ne couvre que le bloc d'agrégats (`:566-605`) et son `catch` se contente d'un `Log::warning` ; `$campaign->update($aggregates)` (`:607`), `shouldAutoPause()` (`:611`) et le `self::dispatch(...)->delay(60)` (`:637`) sont hors protection.
  Aucune tâche planifiée ne rattrape les campagnes orphelines : les 35 lignes de `console.php` ne contiennent aucune commande de type « campaigns:reconcile ». `grep -rl MonitorCampaignProgressJob backend/tests/` → **0 fichier**.
- Témoin négatif: le même `grep -rl` sur `RefreshAudienceChunkJob` rend 1 fichier : la recherche dans `tests/` fonctionne.
- Impact        : une campagne dont le moniteur meurt une fois reste `running` indéfiniment dans la console. Elle n'atteindra jamais `completed`, ne s'auto-mettra jamais en pause sur dépassement de quota (`shouldAutoPause`), et son compteur de durée consommée gèle. Aucune alerte : c'est la forme pure de « l'absence d'événement est un événement ».
- Reproduction  : lancer une campagne, provoquer une exception dans `handle()` (couper Postgres 2 s), constater dans Horizon un `failed_job` unique puis plus aucun `MonitorCampaignProgressJob` ; la campagne reste `running`.
- Correctif     : `tries=3` + `backoff`, envelopper la totalité de `handle()` et ré-auto-dispatcher dans un `finally` ; ajouter une tâche planifiée de réconciliation (campagnes `running` sans moniteur depuis > 5 min). Coût : ~4 h + 1 test.
- Statut        : ouvert

### [B17-012] Deux purges destructives de `companies` sans contexte d'espace, sans `--dry-run`, sans journal, sans test — et déclenchables en un clic contre la production
- Sévérité      : S1 grave
- Domaine       : backend / conformité
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/ProspectionPurgeNonCommercial.php:22-25` ; `ProspectionPurgeNonDiffusible.php:20-23` ; `.github/workflows/prospection-reclassify.yml:38-39` ; `.github/workflows/prospection-collect-region.yml:102-103`
- Constat       : `prospection:purge-non-commercial` exécute `DELETE FROM companies WHERE legal_form IS NULL OR left(legal_form,1) <> '5'` et `prospection:purge-non-diffusible` `DELETE FROM companies WHERE denomination = '[ND]'` ; ni l'une ni l'autre ne pose de filtre `workspace_id`, n'offre `--dry-run`, ne demande confirmation, ni n'écrit dans la chaîne d'audit ; les deux sont invoquées par deux workflows GitHub qui s'exécutent sur le serveur de production.
- Preuve        : code cité ci-dessus (28 l. et 26 l. au total).
  `grep -rn "php artisan" .github/workflows` → `prospection-reclassify.yml:38-39` et `prospection-collect-region.yml:102-103` lancent les deux purges via `docker compose exec -u root -T api php artisan …` sur le serveur prod.
  `gh run list --workflow=prospection-reclassify.yml --limit 5` → **5 exécutions `completed / success`** le 2026-07-04 (28720405493, 28720234206, 28718424225, 28715467700, 28713453697). **Ces purges ont donc déjà supprimé des `companies` en production.**
  `grep -rl "prospection:purge-non-commercial" backend/tests/` → **0**.
  `04_PREUVES/agent-17/` : classement complet des contextes d'espace, ces deux commandes sont dans la colonne « RIEN ».
- Témoin négatif: le même recensement rend `WorkspaceContext` pour `RgpdPurgeVivier`, `RgpdPurgeBusinessProspects`, `ProspectionEnrich` et `ScrapingBackfillSrcTags` : la méthode sait détecter un contexte quand il existe.
- Impact        : la clause `legal_form IS NULL` supprime **toute entreprise dont la forme juridique n'a jamais été renseignée** — c'est le cas par construction des companies créées par les sources qui ne fournissent pas ce champ (`google_maps`, `pages_jaunes`, l'ingestion `/internal/scraper-result`, les extractions médias). Le déclencheur est un bouton « Run workflow » dans l'interface GitHub, sans confirmation, sans estimation préalable, et l'effet porte sur tous les espaces de travail à la fois. Aucune réversibilité (pas de `deleted_at` : c'est un `DELETE` dur), aucune trace de ce qui a été supprimé.
- Reproduction  : sur une base jetable, insérer une company avec `legal_form = NULL`, jouer `php artisan prospection:purge-non-commercial`, constater sa disparition.
- Correctif     : (a) ajouter `--dry-run` (obligatoire par défaut) et `--workspace` requis ; (b) restreindre la clause à `legal_form IS NOT NULL AND left(legal_form,1) <> '5'` — ne jamais supprimer sur une absence de donnée ; (c) écrire le décompte dans `AuditHashChain` ; (d) deux tests qui pré-insèrent une company `legal_form=NULL` et vérifient qu'elle **survit**. Coût : ~4 h.
- Statut        : ouvert

### [B17-013] 24 des 35 tâches planifiées n'ont aucun test, dont 11 des 14 commandes destructives
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main c0c453d
- Emplacement   : `backend/tests/` ; `backend/routes/console.php`
- Constat       : sur les 33 signatures distinctes planifiées, 22 ne sont citées par aucun fichier de `backend/tests/` ; parmi les 14 commandes que j'ai classées « dangereuses », 11 n'ont aucun test.
- Preuve        : recherche par signature dans `backend/tests/` (`grep -rl "<signature>" tests/`), résultat par commande. **Zéro** fichier pour : `coverage:refresh-matrix`, `blacklists:check`, `audit:verify-chain`, `retention:purge`, `rgpd:anonymize-ips`, `anomaly:detect`, `signals:nightly-scan`, `campaigns:start-scheduled`, `audiences:full-refresh`, `companies:retry-google-places`, `media:extract-from-companies`, `media:find-websites`, `media:import-opendatasoft`, `media:import-arcom`, `media:score-confidence`, `media:link-emissions-to-channels`, `media:backfill-periodicity`, `media:import-blogs`, `prospection:score-email-confidence`, `retention:prune-scraper-runs`, `media:clean-emails`, `naf:import`, `rpps:import`, `ign:import-admin-express`, `scraping:ingest-file`, `scraping:backfill-src-tags`, `prospection:purge-non-commercial`, `prospection:purge-non-diffusible`, `app:pentest-self-check`.
  Destructives sans test (11) : `retention:purge`, `retention:prune-scraper-runs`, `rgpd:anonymize-ips`, `media:clean-emails`, `media:import-opendatasoft`, `media:import-arcom`, `media:sync-from-companies`, `prospection:purge-non-commercial`, `prospection:purge-non-diffusible`, `prospection:reclassify-size`, `prospection:reclassify-sector` (+ `prospection:find-websites`, `UPDATE` de masse non borné, également sans test). Seules `rgpd:purge-vivier`, `rgpd:purge-business-prospects` et `horodatages:corriger` en ont un.
  Jobs : `MonitorCampaignProgressJob` et `DispatchScrapeJob` → 0 fichier ; le trait `RunsInWorkspace` → **0 fichier**.
- Témoin négatif: la même recherche rend 1 fichier pour `rgpd:purge-vivier`, `crm:flush-outbound`, `media:enrich`, `journalists:scrape-ours`, etc., et 2 pour `EnrichCompanyJob` : elle sait trouver.
- Impact        : aucun de ces tests n'a donc jamais été « vu rouge » — il n'existe pas. B17-001 (un `--dry-run` qui écrit) aurait été attrapé par un test de trois lignes sur `retention:purge`.
- Reproduction  : rejouer les recherches ci-dessus.
- Correctif     : prioriser les 9 destructives — un test par commande qui pré-insère une ligne **qui ne doit pas être touchée** et une qui doit l'être. Coût : ~2 j pour les 9.
- Statut        : ouvert

### [B17-014] `prospection:reclassify-size --all` et `--sector --all` réécrivent toute la table `companies` en une seule requête, sans contexte d'espace, depuis un bouton GitHub
- Sévérité      : S2 défaut (S1 sur la disponibilité si le volume annoncé de 4,3 M lignes est exact)
- Domaine       : performance / backend
- Référence     : main c0c453d
- Emplacement   : `backend/app/Console/Commands/ProspectionReclassifySize.php:21-41` ; `ProspectionReclassifySector.php:35-37` ; `.github/workflows/prospection-reclassify.yml:40-41` ; `.github/workflows/prospection-collect-region.yml:104-105`
- Constat       : avec l'option `--all`, la clause de `prospection:reclassify-size` se réduit à `WHERE 1=1` et celle de `prospection:reclassify-sector` à `WHERE naf IS NOT NULL` ; l'`UPDATE` n'est ni découpé en lots, ni borné, ni filtré par espace de travail, et les deux workflows de production le passent systématiquement.
- Preuve        : `ProspectionReclassifySize.php:21-23` construit la clause, `:25-41` exécute un unique `UPDATE companies SET size_category = CASE … END WHERE <clause>`. `ProspectionReclassifySector.php:35` idem, `:37` exécute `UPDATE companies SET sector_main = CASE LEFT(naf,2) … END WHERE naf IS NOT NULL`.
  Aucun `chunk`, aucun `limit`, aucun `workspace_id` : recensement complet des contextes d'espace des 49 commandes → les deux sont dans la colonne « RIEN ».
  `.github/workflows/prospection-reclassify.yml:40-41` et `prospection-collect-region.yml:104-105` : `docker compose exec -u root -T api php artisan prospection:reclassify-size --all` puis `… --sector --all` sur le serveur de production.
  `gh run list --workflow=prospection-reclassify.yml --limit 5` → 5 exécutions `success` le 2026-07-04, durées 21 s à 59 s.
- Témoin négatif: le même recensement rend `WorkspaceContext` pour `ProspectionEnrich` (`:42`) et `ScrapingBackfillSrcTags` (`:98`), et `--limit`/`chunk` pour `ProspectionFindWebsites` et `ScoreEmailConfidence` : la méthode sait détecter contexte et bornage quand ils existent.
- Impact        : un `UPDATE` non batché sur la totalité de `companies` prend un verrou ROW EXCLUSIVE ligne à ligne pendant toute sa durée, gonfle le WAL d'autant de nouvelles versions de lignes, et s'exécute dans une seule transaction — impossible à interrompre proprement, impossible à reprendre. Il touche indistinctement tous les espaces de travail. Les 21-59 s mesurées le 2026-07-04 laissent penser que le volume réel était alors très inférieur aux 4,3 M annoncés ailleurs ; le coût futur n'est borné par rien.
- Reproduction  : sur `axion_crm_perf4m`, `EXPLAIN (ANALYZE, BUFFERS) UPDATE companies SET size_category = … WHERE 1=1;` — et observer `pg_stat_activity` depuis une seconde session pendant l'exécution.
- Correctif     : découper par curseur `id` en lots de 50 000 (le patron existe déjà dans `ScoreEmailConfidence.php:91-92` et `PruneScraperRuns.php:30-41`), et rendre `--workspace` obligatoire ou au moins disponible. Coût : ~3 h + 1 test.
- Statut        : ouvert

---

## 5. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Le planificateur de production tourne-t-il réellement ?** — Non vérifié. Le seul accès SSH
   configuré sur ce poste (`~/.ssh/config` → `axion-prod`, 178.105.55.15) mène au VPS **Axion-IA /
   Coolify** : `docker ps` joué en lecture seule y montre `coolify-*`, `plausible-*`, et **aucun
   conteneur `axion-crm-*`**. Le CRM de production répond derrière Cloudflare (188.114.97.6) sur un
   hôte pour lequel je n'ai ni alias ni clé. Le fait apporté par le prompt — « `deploy-direct-ssh.yml`
   recrée bien `scheduler` » — est confirmé par lecture (`:200`), mais **la présence et la vivacité du
   processus en production restent non mesurées**. Geste demandé à qui a l'accès :
   `ssh <hôte-crm> 'docker ps --filter name=axion-crm-scheduler --format "{{.Status}}"'` et
   `docker logs --tail 50 axion-crm-scheduler`.
2. **Les traces d'exécution réelles** — Non vérifiées. La base locale `axion_crm` est **vide**
   (0 ligne dans les 11 tables consultées). Aucune tâche ne pouvait donc laisser de trace mesurable,
   et il n'existe **aucune table de suivi d'exécution** dans le schéma (101 tables, aucune de ce type).
   Les seules exécutions observées sont celles des journaux du conteneur local
   (`campaigns:start-scheduled`, `anomaly:detect`), qui prouvent que le planificateur fonctionne,
   pas que les tâches produisent quelque chose.
3. **Les durées et coûts au volume réel (point 6 de la grille)** — Non mesurés pour 33 des 35 tâches.
   Il aurait fallu les jouer contre `axion_crm_perf4m` (2,8 M fiches) ; la plupart écrivent, et je
   n'ai pas voulu polluer une base de référence dont d'autres agents de cet audit se servent
   peut-être en parallèle. Les deux durées citées (13 s – 1 min 43 s pour `campaigns:start-scheduled`,
   14 s pour `anomaly:detect`) sont mesurées **sur base vide** et ne représentent que le coût
   d'amorçage.
4. **L'affirmation « ≈ 7,6 M lignes de `scraper_runs` en prod »** (commentaire de `PruneScraperRuns.php`)
   et **« opération ensembliste sur ~4,3 M companies »** (`console.php:75`) — non re-mesurées, faute
   d'accès à la base de production. Ce sont des documents, donc des hypothèses.
5. **L'effet réel de B17-008 sur des données existantes** — le mécanisme est établi par lecture du
   code et par les contraintes `pg_constraint` mesurées, mais je n'ai pas pu compter combien de
   médias enrichis et combien de journalistes sont détruits chaque semaine : `media = 0` et
   `journalists = 0` localement.
6. **Le détail ligne à ligne des 22 commandes médias/imports** — la limite de sous-agents concurrents
   de cet audit m'a empêché de déléguer cette lecture ; je l'ai faite par recherches ciblées
   (`grep` sur les suppressions et modifications de masse, sur le contexte d'espace, sur les gardes
   d'environnement, sur les fichiers de test), pas par lecture intégrale des 22 fichiers. Les
   colonnes **G1** (rôle exact) et **G6** (durée/coût) de ces lignes de grille sont donc plus pauvres
   que les autres : je le signale plutôt que de les remplir au jugé. Les colonnes G4, G5, G7, G8,
   G11 de ces mêmes lignes, elles, reposent sur des recherches jouées.
7. **Le comportement réel de `media:import-press-kit`** — classée morte parce qu'aucune référence
   n'existe hors sa propre classe, mais je n'ai pas lu ses 449 lignes : je ne sais pas ce qu'elle
   ferait si on la jouait.
8. **Aucune commande destructive n'a été jouée contre `axion_crm`, `axion_crm_perf`, `axion_crm_perf4m`
   ni la production.** La seule écriture faite par cet audit l'a été sur `axion_crm_agent17`, base
   créée pour l'occasion, contenant une unique table `agent17_probe` de 2 lignes. Elle peut être
   supprimée : `DROP DATABASE axion_crm_agent17;`.
