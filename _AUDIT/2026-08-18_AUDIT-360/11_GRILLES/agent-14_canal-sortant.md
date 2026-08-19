# AGENT 14 — Auditeur du canal sortant (CRM → site Axion-IA)

> Périmètre : `crm_outbound_events` et sa migration, `App\Crm\Outbound\*`,
> `crm:flush-outbound` et son ordonnancement, et — côté site —
> `axionia/src/server/crm-sync/**`, `crm-sync-worker.ts`, `api/internal/crm-webhook/**`.

## 0. Référence — mesurée, pas recopiée

Le dossier commun annonce `main = c0c453d`. **C'est faux au moment de cet audit.**

```
$ git log --oneline -1
1145473 docs(rgpd): registre des violations, notification non retenue (#188)
```

`c0c453d` est trois commits en arrière (`c0c453d` → `b53338c` → `17ba4f1` → `bb60473` → `1145473`).
Les quatre commits intermédiaires sont exclusivement `docs(cnil)/docs(rgpd)` : **aucun ne
touche le canal sortant** (vérifié : `git log --oneline c0c453d..HEAD` ne contient que des
`docs/`). Mes constats sont donc valables pour les deux références, mais **je les date de
`main = 1145473`**, qui est ce que j'ai réellement lu.

**Atelier.** La base locale partagée `axion_crm` a été remise à neuf (`migrate:fresh`) par
d'autres agents **pendant** mes mesures — mon premier geste est mort sur
`relation "journalists" does not exist`. J'ai donc créé et migré une base isolée
**`axion_crm_agent14`** (114 tables) et j'y ai joué tous mes gestes. C'est aussi ce qu'ont
fait `agent17`, `audit13`, `a06`, `a08`, `a11`, `a16`, `rgpd15`. **Aucune écriture en
production ; aucun événement réel émis vers le site ; aucun fichier produit modifié.**

---

## 1. Le tableau de grille

Une ligne par objet du périmètre, une colonne par point de la mission.
`—` = sans objet. Une case « non vérifié » porte sa raison.

| # | Objet | 1. Événements émis | 2. Émission | 3. Réessais | 4. File morte | 5. Alerte | 6. Absence d'événement | 7. Cloisonnement | 8. Ne traverse pas | 9. Contrat §22 |
|---|---|---|---|---|---|---|---|---|---|---|
| A | `crm_outbound_events` (migration `2026_08_14_000007`) | CHECK à **3** valeurs : `consent_optout`, `consent_optin`, `erasure` | Table globale, `origin` contraint à `'crm'` (anti-boucle structurel **vérifié**) | colonnes `attempts`, `next_attempt_at`, `last_error` | statut terminal `gave_up` **en base**, aucune vue, aucun rejeu | — | — | **pas de `workspace_id`, pas de RLS** (`relrowsecurity = f`, mesuré) | — | §22.1 « jamais perdu » : la table le permet, la chaîne ne le tient pas (B14-005) |
| B | `ConsentOutboundRecorder` | déclare 3 types (`EVENT_TYPES`) | écrit la ligne, **hors transaction** de l'action métier | — | — | — | — | n'a **aucune** notion d'espace de travail | `$personKey` accepté mais **jamais renseigné** par les 2 appelants (mesuré : `person_key = NULL` sur les 2 lignes produites) | §22.1 « clé commune » : l'identifiant de fiche CRM ne traverse jamais (B14-012) |
| C | `OutboundRejection` | 4 refus : `origin_not_crm`, `unknown_event_type`, `unknown_scope`, `missing_email_hash` | échec **bruyant** (exception), correct | — | — | les 2 appelants **attrapent** l'exception et se contentent d'un `Log::error` | — | — | un `record()` refusé est perdu ; le « batch de réconciliation quotidien » promis en commentaire **n'existe pas** (B14-009) | — |
| D | `CrmFlushOutbound` (`crm:flush-outbound`) | POSTe 7 champs ; **ni `payload` ni `schema_version`** | double verrou `CRM_OUTBOUND_ENABLED` + destination + secret — les 3 refus **joués** | 8 essais, backoff `1,2,4,8,16,32,60,60` min (plafond 1 h) — **joués** | `gave_up`, `Log::warning` en stderr, **rien d'autre** | **aucune** | rien ne sait qu'elle ne tourne plus (B14-006) | `DB::table()` → **hors** `WorkspaceScope` (Eloquent-only) ; émet pour **tous** les espaces vers **une seule** URL | 503 « n'use pas d'essai » : **branche morte**, le site ne renvoie jamais 503 (B14-005) | §22.1 « contrat versionné » non tenu (B14-008) |
| E | `routes/console.php:166-170` | — | — | — | — | — | `everyFiveMinutes()` + `withoutOverlapping()` **sans expiration** = verrou 24 h par défaut | `onOneServer()`, cache Redis | — | §22.1 « alerte au-delà d'une heure » : absente |
| F | Producteurs (`JournalistsController:155`, `RgpdRequestsController:135`) | **2** producteurs, **2** types sur 3 | `consent_optout` : route API sans **aucun** bouton dans la console (mesuré). `erasure` : seul geste humain réel | — | — | `Log::error('crm.outbound.record_failed')` | — | `scope` **codé en dur** à `'business'` dans les deux | `DELETE /journalists/{id}` (« droit à l'effacement ») n'émet **rien** (B14-010) | §22.2 famille « Consentement » : 1/3 réellement jouable |
| G | `ObservabilityController::outboundBacklog()` | — | — | — | calcule `{pending, gave_up}` — **le seul endroit où la file morte se voit** | — | — | compteur **global** assumé | le frontend ne déclare pas le champ et ne l'affiche pas (B14-003) | §22.7 « tableau de bord du canal » : **absent** du CRM |
| H | Frontend `ObservabilityPage.tsx` | — | — | — | **n'affiche ni `outbound` ni `site_sync`** (type TS incomplet, mesuré) | — | — | — | aucun rejeu en un clic | §22.7 non tenu |
| I | Site — `api/internal/crm-webhook/route.ts` | accepte les **3** mêmes types | HMAC `<ts>.<corps>`, en-têtes `x-crm-*`, fenêtre 300 s — **conforme** à l'émetteur | renvoie 200/401/422/500, **jamais 503** | journalise tout dans `crm_inbound_events` | — | — | — | **`erasure` n'efface rien** : même branche que `consent_optout` (B14-002) | §22.2 « effacement propagé » : non tenu |
| J | Site — `crm-sync/inbound.ts` | `consent_optin` → `"ignored"` **inconditionnel** | `scope !== 'business'` → `"ignored"` | — | — | — | — | le vivier est un trou noir | `person_key` reçu puis **jeté** | §22.2 « réinscription » : morte des deux côtés |
| K | Site — `crm-sync/alerts.ts` + `notifications/routing.ts` | — | — | — | — | `CRM_SYNC_ALERT` **existe**, Telegram + Sentry, 5 motifs | ne surveille **que l'outbox du site** | — | — | l'abandon d'un événement **du CRM** n'a aucune alerte (B14-004) |
| L | Site — `crm-sync-worker.ts` / `reconcile.ts` | 10 types site → CRM (dont `calendly_canceled`) | — | 8 essais, mêmes constantes | — | `gave_up`, `backlog>50`, `reconcile_gap` | balayage `*/10`, réconciliation `30 4 * * *` | — | la réconciliation compare **l'outbox du site à ses propres sources** : elle est structurellement aveugle au canal CRM → site | §22.1 « test des deux listes » : n'existe dans **aucun** des deux dépôts |
| M | Production | — | `CRM_OUTBOUND_ENABLED` **absent** ; `CRM_INGEST_ENABLED=true` | — | — | — | — | — | le canal est **ouvert dans un sens, fermé dans l'autre** (B14-013) | — |

---

## 2. Réponses aux neuf points

### 1. Combien d'événements, exactement ?

Le prompt d'audit annonce « 3 seuls événements ». **Le chiffre 3 est le vocabulaire
déclaré, pas le nombre d'événements émis.** Recompté dans le code :

| Compte | Valeur | Preuve |
|---|---|---|
| Types **déclarés** (const PHP + CHECK Postgres, identiques) | **3** | `ConsentOutboundRecorder.php:33` ; migration l. 59 ; `\d crm_outbound_events` |
| Types ayant un **producteur** dans le code produit | **2** | `rg ConsentOutboundRecorder backend/app` → 2 appels seulement |
| Types **atteignables par un humain** dans la console | **1** | le frontend n'appelle jamais `POST /journalists/{id}/opt-out` |
| Types ayant un **effet** côté site | **1** (`consent_optout`) | `inbound.ts:246` : `consent_optin` → `"ignored"` ; `erasure` ne fait que désabonner |
| Familles exigées par le §22.2 « du CRM vers la console » | **6** | cahier des charges §22.2 |
| Familles implémentées | **1** (Consentement), partiellement | — |

`consent_optin` — la « réinscription » du §22.2 — **n'a aucun producteur** dans le CRM et
est **explicitement ignorée** par le site. Elle n'existe que dans deux contraintes.

### 2. Émission : quand, par qui, et la ligne produite

Deux endroits, deux seulement (`rg ConsentOutboundRecorder backend/app`) :

- `JournalistsController::optOut()` l. 155 → `consent_optout`, `scope` codé en dur `'business'`
- `RgpdRequestsController::process()` l. 135 (via `queueOutboundErasure`) → `erasure`, `scope` codé en dur `'business'`

**Gestes joués** sur `axion_crm_agent14` — les deux lignes créées :

```
{"id":1,"event_id":"2bdc1c8d-…","event_type":"consent_optout","person_key":null,
 "email_hash":"85331a510bc1a61e00678a485a1394a9dcf8ad4d56affcfea71dbe34c49d0a0e",
 "scope":"business","origin":"crm","payload":"{\"surface\": \"console:journalists\", \"journalist_id\": 1}",
 "status":"pending","attempts":0,"next_attempt_at":"2026-08-19 12:15:38+00","sent_at":null}
{"id":2,"event_id":"846ebf64-…","event_type":"erasure","person_key":null,
 "email_hash":"e921eeee15f794e9614bb8188d567113fde0cf9477bad6b39b6040c2faa9da36",
 "scope":"business","origin":"crm","payload":"{\"surface\": \"console:rgpd_requests\", \"rgpd_request_id\": 1}",
 "status":"pending","attempts":0,"next_attempt_at":"2026-08-19 12:16:36+00","sent_at":null}
```

Trois observations que le code seul ne donne pas :

1. **`person_key` est `NULL` sur les deux.** Le paramètre existe, aucun appelant ne le
   renseigne — et le CRM ne *peut* pas le renseigner : `person_key` est un sha256 **salé
   côté site** (`SiteSyncEvent.php:192`). Le §22.1 « une clé commune … et, quand il est
   connu, un identifiant de fiche CRM » n'est donc pas tenable en l'état.
2. **`payload` n'est jamais émis.** `dispatchOne()` construit un corps à 7 champs
   (`CrmFlushOutbound.php:120-128`) qui n'inclut pas `payload`. Le contexte (« surface »,
   `journalist_id`, `rgpd_request_id`) reste local.
3. **L'écriture est hors transaction.** Le commentaire de la migration promet « la console
   écrit une LIGNE dans la même transaction que l'opposition » ; dans les faits
   `JournalistsController::optOut()` fait `$journalist->update()` puis `record()` dans un
   `try/catch` séparé. Un crash entre les deux perd l'événement — sans rattrapage (B14-009).

### 3. Réessais — joué, pas déduit

Voir §3 « Panne jouée » ci-dessous.

### 4. File morte

`status = 'gave_up'`, `next_attempt_at = NULL`. **Elle reste dans `crm_outbound_events` et
personne ne la regarde.**

- Le backend l'expose : `ObservabilityController::outboundBacklog()` → `data.outbound.gave_up`.
- **Le frontend ne l'affiche pas** : l'interface `ObservabilitySummary`
  (`ObservabilityPage.tsx:6-20`) ne déclare **ni `outbound` ni `site_sync`**, et la page ne
  les rend nulle part. `rg "outbound" frontend/src` → **aucun résultat** (témoin négatif :
  le même `rg "observability" frontend/src` rend 4 lignes).
- **Aucune commande de rejeu.** `rg "signature = 'crm:" backend/app/Console/Commands/` →
  une seule commande, `crm:flush-outbound`. Rien pour requalifier un `gave_up`.
- Un `gave_up` produit un `Log::warning('crm.outbound.gave_up')` sur `stderr`. Pas de
  Sentry (`report()` n'est appelé que sur l'échec du *producteur*, pas de l'émetteur).

→ **Un événement perdu l'est en silence. Constat S1 (B14-003), aggravé par B14-005.**

### 5. `CRM_SYNC_ALERT` — existe-t-elle ici ?

**Non. Pas une seule occurrence dans le code de ce dépôt.**

```
$ rg -n "CRM_SYNC_ALERT" backend/app backend/config backend/routes backend/database backend/tests frontend/src infra
[code 1]   # aucun résultat
```

Témoin négatif : la même commande trouve bien `crm.outbound.gave_up` (2 occurrences) et
`crm.outbound.record_failed` (2 occurrences) — le contrôle sait chercher. Et
`rg -l CRM_SYNC_ALERT` sur tout le dépôt ne rend que **5 fichiers, tous des documents**
(`_PROMPTS/`, `_REPORTS/`, `11_GRILLES/agent-05`).

**Elle existe côté site**, pour de vrai :

- définie `axionia/src/server/notifications/types.ts:439`, 5 motifs :
  `gave_up | backlog | reconcile_gap | reconcile_failed | scan_capped` ;
- routée `routing.ts:99` → `channels: ["telegram","sentry"], severity: "error", rateLimitPerHour: 12` ;
- destinataire : groupe Telegram `TELEGRAM_CHAT_ID_CRM_SYNC`, **vide dans `.env.example`**,
  repli `TELEGRAM_CHAT_ID_SYSTEM` puis `TELEGRAM_CHAT_ID`. **Ni e-mail, ni Slack.**

**A-t-elle sonné ?** Rien ne permet de le dire *et c'est le problème* : les notifications ne
sont persistées nulle part (dédoublonnage Redis à TTL 1 h, aucune table dans `schema.prisma`).
Quatre des cinq motifs sont derrière `isCrmSyncEnabled()`, et `CRM_SYNC_ENABLED=false` dans
`.env.example` — absent de tous les autres `.env*` et de `deploy/`. **Faute de trace
persistée, « elle n'a jamais sonné » et « elle a sonné dans le vide » sont
indiscernables.** Je le déclare non vérifiable ici (§5 des non-vérifiés).

**Et surtout : elle ne surveille que l'outbox DU SITE.** Aucun de ses cinq motifs ne peut
se déclencher sur un `gave_up` de `crm_outbound_events`. Le sens CRM → site n'a **aucune**
alerte, ni ici ni là-bas (B14-004).

### 6. L'absence d'événement est-elle un événement ?

**Non.** Si `crm:flush-outbound` cesse de tourner, rien ne le sait :

| Dispositif attendu | État mesuré |
|---|---|
| Healthcheck du conteneur `scheduler` | **désactivé explicitement** — `docker-compose.prod.yml:90-91` : `healthcheck: disable: true`, avec le commentaire « il n'y a pas de commande fiable pour interroger son état, donc on RETIRE le contrôle » |
| Alerte Prometheus | **aucune** — 8 alertes déclarées (`ScrapingFailureRateHigh`, `LLMCostNearCap`, `EmailValidationInvalidRateHigh`, `ApiDown`, `PostgresDown`, `RedisDown`, `ApiLatencyP95High`, `HorizonQueueBacklog`) ; `rg -in "outbound\|consent\|scheduler" alerts.yml` → rien. *Et la pile d'observabilité vit dans `docker-compose.observability.yml`, hors de la liste `api app horizon scheduler` que le déploiement recrée (piège 18 du dossier).* |
| Horodatage du dernier passage | **aucune colonne, aucune clé de cache** |
| Écran console | **aucun** (§4 ci-dessus) |
| Alerte « en échec depuis > 1 h » exigée au §22.1 | **absente** |

Pire : `->withoutOverlapping()` est appelé **sans argument**, donc l'expiration du verrou
est la valeur par défaut de Laravel, **1440 minutes = 24 h**. Un passage tué à chaud
(redéploiement, OOM) laisse le mutex en place et fait **sauter jusqu'à 288 passages
consécutifs, en silence, avec un scheduler parfaitement vert**. C'est exactement le
scénario « cron vert qui ne purge rien » que `config/crm.php:44-51` désigne comme « le pire
des échecs » — le garde-fou a été posé pour L0 et pas pour L5. **B14-006.**

### 7. Cloisonnement

`crm:flush-outbound` tourne **sans aucun contexte d'espace de travail**, et c'est structurel :

- La table n'a **pas** de `workspace_id` (choix documenté : « infrastructure de synchronisation »).
- Elle n'a **pas** de RLS — mesuré :
  `SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname='crm_outbound_events'` → `f | f`.
- `WorkspaceScope` (`app/Models/Scopes/WorkspaceScope.php:24`) `implements Scope` et opère
  sur un `Eloquent\Builder`. Le recorder **et** l'émetteur utilisent `DB::table()` : ils
  **passent à côté** du scope, et donc aussi de l'échec bruyant
  `MissingWorkspaceContextException` que `CRM_STRICT_WORKSPACE_SCOPE` est censé garantir aux
  commandes artisan.

**Elle émet donc pour tous les espaces, indistinctement, vers une unique destination.**
`SITE_CRM_WEBHOOK_URL` est une variable globale ; `scope` est codé en dur à `'business'`
dans les deux producteurs (il ne dérive pas de l'espace de la fiche). Un second espace de
travail — le cas que le §22.5 prévoit noir sur blanc (« pour un espace de travail auquel
aucune console n'est reliée … tout ce qui est reflet est simplement absent ») — verrait ses
oppositions partir vers la console d'Axion-IA. **B14-007.**

### 8. 🔴 Ce qui DEVRAIT traverser et ne traverse pas

Voir la liste exhaustive au §4.

### 9. Le contrat §22, famille par famille

Le §22.2 « Du CRM vers la console » exige **6 familles**. Ce qui est en **gras** dans le
cahier des charges est annoncé comme *déjà existant*.

| Famille §22.2 | Événements exigés | Émis ? | Mesure |
|---|---|---|---|
| **Consentement** | **désinscription, réinscription, effacement** | **1 sur 3 vraiment** | `consent_optout` : code présent, **aucun bouton** dans la console. `consent_optin` : **aucun producteur**, et le site l'ignore. `erasure` : émis, mais **n'efface rien** côté site |
| **Identité** | **fiche créée ou rapprochée**, coordonnée corrigée, fiches fusionnées | **0 sur 3** | Aucun émetteur. `POST /crm/arbitrage/{id}/attach` (le rapprochement) n'émet rien. `PUT /contacts/{id}` répond **501**. Aucune fusion exposée. Le CRM renvoie pourtant `subject_id` dans la réponse *synchrone* de `/site-sync` (`IngestOutcome::toArray()`) — **le site ne le lit pas** (`emit.ts` ne conserve que `result.status`) |
| Commercial | opportunité créée / gagnée / perdue, demande de devis | **0 sur 4** | Aucun modèle d'opportunité dans `app/Models/` (20 modèles listés) |
| Réclamation | réclamation qualifiée | **0 sur 1** | Aucun émetteur |
| Rendez-vous | réservé sur page interne, reporté, annulé, honoré, absent | **0 sur 5** | Aucun émetteur. **Le symétrique existe bien dans l'autre sens** : le site émet `calendly_booked / completed / canceled / no_show` (`enrich.ts:235-248`, `admin-calendly/actions.ts:86-113`) — le CRM ne renvoie rien |
| Interaction | compte rendu validé, entretien réalisé, décision d'embauche | **0 sur 3** | Aucun émetteur |

**Bilan : 19 événements exigés, 2 émis, 1 avec un effet réel côté site.**

Règles du §22.1, une par une :

| Règle §22.1 | Verdict | Mesure |
|---|---|---|
| Automatique | ✅ pour ce qui est branché | `everyFiveMinutes()` |
| Fiable — « jamais perdu si l'autre outil est indisponible une nuit » | ❌ | 8 essais consommés en **3 h 02** (backoff cumulé 182 min) puis `gave_up` définitif. **Une nuit de panne perd l'événement.** B14-005 |
| Fiable — « clé d'unicité, rejouer ne crée jamais de doublon » | ✅ | `event_id` UUID ; idempotence P2002 côté site |
| Signé, anti-rejeu | ✅ | HMAC `<ts>.<corps>`, fenêtre 300 s des deux côtés — **vérifié des deux côtés** |
| Sans boucle | ✅ **et bien fait** | garde applicatif + CHECK Postgres ; les deux prouvés par les tests du dépôt |
| Une clé commune (empreinte e-mail **et** identifiant de fiche CRM) | ⚠️ moitié | `email_hash` oui ; `person_key` toujours `NULL` et jeté par le site |
| **Contrat strict et versionné**, document commun, test des deux listes | ❌ | `schema_version` est **exigé et validé à l'entrée** (`SiteSyncEvent.php:118-122`, rejette une version non supportée) et **absent du corps sortant**. Le site ne le réclame pas. Aucun document de contrat dans `spec/`. Aucun test croisé des vocabulaires dans l'un ou l'autre dépôt. B14-008 |
| **Observable** — file, échecs, abandons et rejeu visibles **dans les deux consoles** | ❌ côté CRM | le site a `/admin/synchro-crm` (émis/reçus/24 h/dernier reçu). Le CRM n'a **rien** |
| **Alerte au-delà d'une heure en échec** | ❌ | aucune |
| §22.7 « rejeu en un clic » | ❌ | aucune commande, aucun écran |

---

## 3. Panne jouée — réessais et file morte

Base isolée `axion_crm_agent14`, destination `http://127.0.0.1:59999/api/internal/crm-webhook`
(rien n'écoute : c'est une **panne réelle**, pas un `Http::fake`). Sortie brute :
`04_PREUVES/agent-14/04_panne-jouee.txt`.

**A — drapeau à OFF, c'est-à-dire l'état par défaut du produit.**

```
$ docker exec -e DB_DATABASE=axion_crm_agent14 axion-crm-api php artisan crm:flush-outbound
CRM_OUTBOUND_ENABLED n'est pas à true — outbox construite mais inerte
(activation à la bascule finale). Aucun événement n'a été émis.
code de sortie = 1
```

Le double verrou fonctionne : la commande refuse même lancée à la main. **C'est bien la
seule chose que le drapeau garde** — le producteur, lui, n'est pas gaté, et les deux
lignes ci-dessus ont bien été écrites avec le drapeau fermé.

**C — le site est injoignable.** Un passage, puis l'état en base :

```
 id |   event_type   | status | attempts |    next_attempt_at     | erreur
----+----------------+--------+----------+------------------------+---------------------------------------------
  1 | consent_optout | failed |        1 | 2026-08-19 12:29:45+00 | connexion : cURL error 7: Failed to connect
                                                                  |   to 127.0.0.1:59999 after 8 ms
  2 | erasure        | failed |        1 | 2026-08-19 12:29:46+00 | connexion : cURL error 7: … after 0 ms
```

Le passage a eu lieu à **12:27:45**. `next_attempt_at = 12:29:45` : **+2 minutes**, ce qui
est exactement `min(60, 2^1)` — la formule `backoffMinutes()` (`CrmFlushOutbound.php:227`).

**Une panne réseau CONSOMME donc un essai** (`ConnectionException` → `consumeAttempt()`,
l. 141-143). C'est le point qui compte pour B14-005 : la clémence du 503 ne s'applique
qu'à un 503 *reçu*, pas à un site qui ne répond pas du tout.

**D — passage suivant, une fois l'échéance passée :** `attempts` 1 → 2, nouveau backoff.
La progression est bien celle qu'annonce le code.

**Le barème complet, calculé par la formule du produit :**

| après l'essai n° | attente programmée |
|---|---|
| 1 | 2 min |
| 2 | 4 min |
| 3 | 8 min |
| 4 | 16 min |
| 5 | 32 min |
| 6 | 60 min |
| 7 | 60 min |
| **cumul** | **182 min = 3 h 02**, puis `gave_up` définitif |

**E — le plafond, joué.** `attempts` positionné à 7, un passage de plus :

```
Outbox CRM → site : 0 envoyés, 0 différés (site indisponible), 0 en échec rejouable, 2 abandonnés.

id=1 type=consent_optout status=gave_up  attempts=8 next=NULL sent=NULL err=connexion : cURL error 7…
id=2 type=erasure        status=gave_up  attempts=8 next=NULL sent=NULL err=connexion : cURL error 7…
```

Au 8ᵉ essai (`CRM_OUTBOUND_MAX_ATTEMPTS=8`), la ligne bascule en `gave_up`,
`next_attempt_at = NULL`, `sent_at = NULL`. **Un droit d'opposition et un droit à
l'effacement viennent d'être abandonnés définitivement.**

**F — et l'abandon est vraiment terminal.** J'ai remis de force `next_attempt_at` dans le
passé pour voir si quelque chose reprenait la ligne :

```
Outbox CRM → site : rien à émettre.

id=1 … status=gave_up attempts=8 next=2026-08-19 12:39:52+00 …
id=2 … status=gave_up attempts=8 next=2026-08-19 12:39:52+00 …
```

**« Rien à émettre »** : la requête de la commande filtre
`whereIn('status', ['pending', 'failed'])` — un `gave_up` n'est plus jamais regardé, même
redevenu « dû ». Il n'existe aucune commande pour l'en sortir.

**G — qui le voit ?** Le miroir d'observabilité, et lui seul :

```
ce que ObservabilityController::outboundBacklog() renverrait :
{"pending":0,"gave_up":2}
```

Le calcul est juste. **Il n'arrive sur aucun écran** (B14-003).

**H — et personne n'est prévenu.** Une table `notifications` existe bien en base, mais
`GET /v1/notifications` renvoie **une liste vide écrite en dur**
(`NotificationsController.php:15`) et `markRead`/`markAllRead` répondent **501** : il n'y a
aucun canal de notification utilisable dans le CRM. Le seul code qui touche cette table est
`GdprErasureService`, pour en **supprimer** des lignes.

**Bilan de la panne jouée.** Un site injoignable pendant 3 h 02 transforme deux droits RGPD
en deux lignes mortes, dans une table qu'aucun écran ne montre, qu'aucune commande ne
reprend, et dont aucune alerte ne parle. La chaîne complète a été jouée, du geste console
jusqu'à l'état terminal.

---

## 4. 🔴 LA LISTE — ce qui devrait traverser et ne traverse pas

Chaque ligne : l'action du CRM, le fichier qui la porte, ce que le site en attend, et la mesure.

### 4.1 Ce qui est décidé dans la console CRM et n'émet rien

| # | Action du CRM | Emplacement | Ce que le site perd | Mesure |
|---|---|---|---|---|
| 1 | **Effacement d'un journaliste** (`DELETE /journalists/{id}`), documenté « Droit à l'effacement RGPD » | `JournalistsController.php:177-182` | l'effacement art. 17 | le **même** contrôleur émet pour l'opposition (l. 155) et **pas** pour l'effacement. **B14-010** |
| 2 | **Réinscription / retrait d'opposition** | n'existe pas | `consent_optin` | aucune route `opt-in` (`rg` sur `routes/api.php`) ; type déclaré, **zéro producteur** |
| 3 | **Opposition d'un journaliste depuis l'écran** | `JournalistsController.php:142` | `consent_optout` | la route existe, **le frontend ne l'appelle jamais** (`rg "opt-out" frontend/src` → 2 libellés d'affichage). Le seul geste humain qui produise un événement est le traitement d'une demande RGPD |
| 4 | **Opposition d'un candidat (vivier)** | `Crm/CandidatesController.php:221` (lecture seule) | rien | `opt_out` est exposé en lecture ; aucune mutation. Et même émis, le site le jetterait (`scope !== 'business'` → `ignored`) |
| 5 | **Effacement d'un candidat (vivier)** | `GdprErasureService` | rien | l'erasure console est émise avec `scope: 'business'` **codé en dur** (`RgpdRequestsController.php:142`). Le vivier n'a **aucun** canal sortant |
| 6 | **Correction d'une coordonnée** (§22.2 Identité) | `ContactsController::update()` | e-mail / téléphone corrigé | **répond 501** — on ne peut pas modifier un contact dans le CRM |
| 7 | **Suppression d'un contact** | `ContactsController::destroy()` | — | **répond 501** |
| 8 | **Rapprochement d'une fiche** (§22.2 « fiche créée ou rapprochée », annoncé *existant*) | `Crm/ArbitrageController::attach()` | l'identifiant CRM à porter sur ses objets | la route existe et mute, **n'émet rien** |
| 9 | **Fusion de fiches** | `Services/Dedup/DeduplicationService.php` | fiches fusionnées | aucun émetteur |
| 10 | **Actions de masse** (`POST /crm/bulk` : étape de cycle de vie, étiquettes, suppression l. 139) | `Crm/BulkController.php` | reflet | aucun émetteur |
| 11 | **Suppression d'une entreprise** | `CompaniesController.php:393` | — | aucun émetteur |
| 12 | **Purges automatiques RGPD** (`rgpd:purge-vivier`, `rgpd:purge-business-prospects`, mensuelles) | `routes/console.php:154` | des personnes disparaissent du CRM sans que le site le sache | ces commandes **n'appellent pas** le recorder (`rg ConsentOutboundRecorder` → 2 appels, aucun dans `Console/Commands/`) |
| 13 | Opportunité / devis / réclamation / rendez-vous interne / compte rendu / décision d'embauche | — | 15 événements du §22.2 | **aucun modèle correspondant** dans `app/Models/` |

### 4.2 Le symétrique Calendly, vérifié

Le site **émet bien** `calendly_canceled` (et `booked`, `completed`, `no_show`) vers le CRM :
`enrich.ts:235-248` (sur transition d'état uniquement) et `admin-calendly/actions.ts:86-113`.
**Le CRM ne renvoie rien** : le §22.2 exige « rendez-vous réservé sur la page interne,
reporté, annulé, honoré, absent » dans le sens CRM → console, et le §22.6 promet « Voir tous
les rendez-vous Calendly, marquer honoré / absent → **CRM** — le statut redescend vers la
console tout seul ». **Ce retour n'existe pas** : `EVENT_TYPES` du canal sortant est fermé à
trois valeurs de consentement, il n'a aucune place pour un rendez-vous.

### 4.3 Ce qui traverse mais n'a pas l'effet annoncé

| # | Événement | Ce qui est promis | Ce qui se passe |
|---|---|---|---|
| 14 | `erasure` | « effacement propagé » (§22.2) | `inbound.ts:243-261` : pas de branche `erasure` ; il tombe dans **la même branche** que `consent_optout`, et son **seul** effet est `newsletterSubscriber.status = 'unsubscribed'`. Soumissions de formulaires, candidatures, registre de consentement : **intacts**. Le site répond `200 {"outcome":"applied"}`, le CRM marque `sent`. **B14-002** |
| 15 | `consent_optout` pour quelqu'un qui n'est pas abonné **confirmé** | opposition appliquée | `where: { status: "confirmed" }` → `no_match` + **HTTP 200**. Du point de vue du CRM, indiscernable d'un succès |
| 16 | `consent_optin` | « réinscription » | `inbound.ts:246` : `return "ignored"`, inconditionnel |
| 17 | Tout `scope: "vivier"` | — | `inbound.ts:250` : `ignored` |
| 18 | `person_key` | « la console apprend quel identifiant porter » (§22.2) | envoyé `NULL` par le CRM ; et même renseigné, le site le journalise puis le jette |

---

## 5. Constats

### [B14-001] Le canal sortant déclare trois événements, en produit deux, et un seul est atteignable par un humain
- Sévérité      : S1
- Domaine       : canal
- Référence     : main 1145473 (le dossier annonce c0c453d ; écart relevé au §0)
- Emplacement   : `backend/app/Crm/Outbound/ConsentOutboundRecorder.php:33` ; `backend/app/Http/Controllers/Api/JournalistsController.php:155` ; `backend/app/Http/Controllers/Api/RgpdRequestsController.php:135` ; `frontend/src/features/media/JournalistsListPage.tsx`
- Constat       : `EVENT_TYPES` et la contrainte CHECK déclarent `consent_optout`, `consent_optin`, `erasure` ; `consent_optin` n'a aucun producteur, et `consent_optout` n'est appelé par aucun écran de la console.
- Preuve        : `rg -n "ConsentOutboundRecorder" backend/app` → 2 appels seulement (`04_PREUVES/agent-14/01_inventaire.txt`). `rg -n "opt-out" frontend/src` → 2 occurrences, toutes deux des libellés d'affichage (`02_temoins-negatifs.txt`).
- Témoin négatif: le même `rg` sur `frontend/src` trouve bien les cinq appels `api.get`/`api.post` vers `/rgpd/requests*`, dont `POST /rgpd/requests/{id}/process` — le contrôle sait trouver un appel d'API quand il y en a un.
- Impact        : la « synchro bidirectionnelle des consentements » ne l'est que pour un tiers de son vocabulaire. Un utilisateur qui coche l'opposition d'un journaliste dans la console… ne le peut pas ; et une réinscription n'existe nulle part.
- Reproduction  : ouvrir `/media/journalists`, chercher une action d'opposition — il n'y en a pas ; puis `rg "opt-out" frontend/src`.
- Correctif     : soit brancher un bouton d'opposition et une route d'opt-in (≈ 1 j), soit retirer `consent_optin` du vocabulaire et de la contrainte CHECK pour que le déclaré corresponde au réel (≈ 2 h). Le pire est de laisser un vocabulaire qui promet trois choses et en fait une.
- Statut        : ouvert

### [B14-002] `erasure` traverse, le site répond « appliqué », et rien n'est effacé
- Sévérité      : S0
- Domaine       : conformité
- Référence     : main 1145473 (CRM) ; dépôt site `Axion-IA/axionia`
- Emplacement   : `Axion-IA/axionia/src/server/crm-sync/inbound.ts:243-261` ; côté CRM `backend/app/Http/Controllers/Api/RgpdRequestsController.php:106-113`
- Constat       : le gestionnaire d'entrée du site n'a pas de branche `erasure` ; l'événement tombe dans la branche `consent_optout` dont le seul effet est `newsletterSubscriber.update({ status: "unsubscribed" })`.
- Preuve        : `rg -n "applyEffect|event_type ===|scope !==|unsubscribed" src/server/crm-sync/inbound.ts` → `l.246 consent_optin → ignored`, `l.250 scope !== "business" → ignored`, `l.253 !subscriberId → no_match`, `l.257 data: { status: "unsubscribed" }`. Aucune autre écriture, aucune suppression. Le geste CRM producteur a été joué : ligne `id=2, event_type=erasure, status=pending` (`03_gestes-emission.txt`).
- Témoin négatif: le même `rg` trouve bien la branche `consent_optin` et la garde de `scope` — il verrait une branche `erasure` si elle existait. Et `rg "delete|deleteMany" src/server/crm-sync/inbound.ts` ne rend rien.
- Impact        : une demande d'effacement art. 17 traitée dans la console CRM est marquée `sent`/`applied` alors que les soumissions de formulaires, candidatures et registre de consentement du site conservent les données. Le CRM et le site affichent tous deux un succès. **Non-conformité RGPD masquée par un compteur vert.**
- Reproduction  : `POST /rgpd/requests` type `erasure` → `POST /rgpd/requests/{id}/process` → une ligne `erasure` en file ; côté site, POSTer ce corps signé sur `/api/internal/crm-webhook` → `200 {"ok":true,"outcome":"applied"}` sans aucune suppression.
- Correctif     : ajouter une branche `erasure` explicite côté site (anonymisation/suppression des objets porteurs de l'empreinte) ; à défaut immédiat, **renvoyer 422** pour `erasure` afin que le CRM cesse de croire l'effacement propagé (≈ 30 min pour le 422 honnête, ≈ 2-3 j pour l'effacement réel). Décision produit + juridique : STOP & ASK.
- Statut        : ouvert

### [B14-003] La file morte du canal sortant n'est visible sur aucun écran du CRM
- Sévérité      : S1
- Domaine       : canal
- Référence     : main 1145473
- Emplacement   : `frontend/src/features/observability/ObservabilityPage.tsx:6-20` ; `backend/app/Http/Controllers/Api/ObservabilityController.php:35,85-104`
- Constat       : le backend renvoie `data.outbound.{pending,gave_up}` ; l'interface TypeScript `ObservabilitySummary` ne déclare pas le champ et la page ne le rend nulle part.
- Preuve        : `rg -n "outbound" frontend/src` → code de sortie 1, aucun résultat (`02_temoins-negatifs.txt`) ; l'interface listée l. 6-20 s'arrête à `recent_events`. Panne jouée : deux droits RGPD portés à `gave_up`, le backend calcule bien `{"pending":0,"gave_up":2}` et l'écran n'en montre rien (`04_panne-jouee.txt`).
- Témoin négatif: `rg -n "observability" frontend/src` rend 4 lignes — le contrôle sait chercher dans ce répertoire.
- Impact        : un événement de consentement abandonné (`gave_up`) n'apparaît sur aucun écran, n'a aucune commande de rejeu (`rg "signature = 'crm:"` → une seule commande) et ne laisse qu'un `Log::warning` en `stderr`. Le §22.7 du cahier des charges exige « événements émis / reçus sur 24 h, en attente, en échec, abandonnés ; rejeu en un clic » dans **chaque** console — le site l'a (`/admin/synchro-crm`), le CRM ne l'a pas.
- Reproduction  : ouvrir `/admin/observability` avec des lignes `gave_up` en base : rien ne s'affiche.
- Correctif     : ajouter `outbound` et `site_sync` au type et deux `KpiCard` (≈ 2 h) ; un écran de canal avec rejeu (≈ 2-3 j).
- Statut        : ouvert

### [B14-004] Le sens CRM → site n'a aucune alerte, dans aucun des deux dépôts
- Sévérité      : S1
- Domaine       : canal
- Référence     : main 1145473 (CRM) ; dépôt site
- Emplacement   : `backend/app/Console/Commands/CrmFlushOutbound.php:171,208` ; `infra/monitoring/prometheus/alerts.yml` ; `Axion-IA/axionia/src/server/crm-sync/alerts.ts:36,71-88`
- Constat       : `CRM_SYNC_ALERT` n'existe pas dans le dépôt CRM ; côté site elle existe mais ses cinq motifs portent exclusivement sur l'outbox **du site**.
- Preuve        : `rg -n "CRM_SYNC_ALERT" backend/app backend/config backend/routes backend/database backend/tests frontend/src infra` → code 1, aucun résultat. `rg -l "CRM_SYNC_ALERT"` sur tout le dépôt → 5 fichiers, tous des documents. `rg -n "alert:" infra/monitoring/prometheus/alerts.yml` → 8 alertes, aucune sur l'outbox (`rg -in "outbound|consent|scheduler"` → code 1). Côté site : `routing.ts:99` `channels: ["telegram","sentry"]`, motifs `gave_up|backlog|reconcile_gap|reconcile_failed|scan_capped`, tous alimentés par `crm-sync-worker.ts` qui ne lit que `CrmSyncOutbox`.
- Témoin négatif: la même commande trouve `crm.outbound.gave_up` (2 occurrences) et `crm.outbound.record_failed` (2 occurrences) dans `backend/app`. Et une table `notifications` existe bien en base — elle serait trouvée : mesuré pendant la panne jouée (`04_panne-jouee.txt`). Mais `GET /v1/notifications` renvoie **une liste vide écrite en dur** (`NotificationsController.php:15`) et `markRead`/`markAllRead` répondent 501 : le CRM n'a **aucun canal de notification utilisable** — même défaut que A-002 sur `/saved-views`.
- Impact        : le §22.1 exige « un événement en échec depuis plus d'une heure déclenche une alerte ». Un abandon définitif d'opposition RGPD ne réveille personne. Mesuré dans la suite rejouée : les **deux** chemins d'abandon s'exécutent et ne produisent qu'une ligne de journal — `crm.outbound.gave_up {"status":422}` et `crm.outbound.gave_up {"attempts":8}` (`05_tests-depot-rejoues.txt`). Rien ne s'ensuit : pas de `report()`, donc pas de Sentry. Destinataire côté site : un groupe Telegram dont l'identifiant est **vide** dans `.env.example`, avec repli sur `TELEGRAM_CHAT_ID_SYSTEM`.
- Reproduction  : les deux commandes `rg` ci-dessus.
- Correctif     : une commande `crm:outbound-health` planifiée à l'heure, qui compte `gave_up` et `failed` de plus d'une heure et notifie (Sentry via `report()` suffit pour commencer) — ≈ 0,5 j.
- Statut        : ouvert

### [B14-005] Le site ne renvoie jamais 503 : la garde « ne consomme pas d'essai » est une branche morte, et une panne de trois heures perd définitivement l'opposition
- Sévérité      : S1
- Domaine       : canal
- Référence     : main 1145473 (CRM) ; dépôt site
- Emplacement   : `backend/app/Console/Commands/CrmFlushOutbound.php:176-186,194-228` ; `Axion-IA/axionia/src/app/api/internal/crm-webhook/route.ts:53-84`
- Constat       : le CRM traite 503 comme « indisponibilité temporaire, essai non consommé » ; le gestionnaire du site ne renvoie que 200, 401, 422 et **500** — jamais 503.
- Preuve        : `rg -n "status: [0-9]|503" src/app/api/internal/crm-webhook/route.ts` → `500 server_misconfigured`, `401 unauthorized`, `422 invalid_json`, `422 contract_violation`, `200 ok`, `500 processing_failed`. Aucun 503. Côté CRM, `backoffMinutes()` : `min(60, 2**min(n,6))` → 1,2,4,8,16,32,60,60 ; somme des sept attentes = **182 min = 3 h 02**, après quoi `consumeAttempt()` bascule en `gave_up`.
- Témoin négatif: le même `rg` trouve bien les six réponses effectivement présentes — il aurait vu un 503.
- 🔴 Piège 19  : j'ai rejoué `php artisan test --filter=CrmOutbound` → **15 passés**, dont « un 503 fait ATTENDRE sans consommer de tentative ». **Cette garde est verte et mesure le mauvais objet** : elle exerce, via `Http::fake`, une réponse que le gestionnaire du site n'émet jamais. Une revue qui écrirait « le cas indisponibilité est couvert par le test » raisonnerait sur une fausse sécurité.
- Impact        : le cas de loin le plus probable — secret absent ou mal propagé côté site — donne **500**, essai consommé. Le §22.1 promet « jamais perdu si l'autre outil est indisponible une nuit » ; en pratique **3 h 02 d'indisponibilité suffisent à perdre définitivement une opposition RGPD**, sans alerte (B14-004) et sans écran (B14-003). La branche 503, soigneusement écrite et testée, ne s'exécutera jamais en production.
- Reproduction  : voir §3. Panne jouée de bout en bout : site injoignable → `cURL error 7` en 8 ms → `status` `pending`→`failed`, `attempts` 0→1, `next_attempt_at` +2 min ; puis, au plafond, `gave_up | attempts=8 | next_attempt_at=NULL` ; puis « rien à émettre » même en rendant la ligne due de force (`04_panne-jouee.txt`).
- Correctif     : deux gestes indépendants — (a) côté site, renvoyer 503 quand `SITE_SYNC_HMAC_SECRET` est absent ou que `isCrmSyncEnabled()` est faux, au lieu de 500 (≈ 1 h) ; (b) côté CRM, ne pas consommer d'essai sur 5xx (le distinguer d'un 4xx), et donner à `gave_up` une porte de sortie (rejeu manuel) plutôt qu'un état terminal muet (≈ 0,5 j).
- Statut        : ouvert

### [B14-006] Rien ne détecte l'arrêt de `crm:flush-outbound`, et son verrou anti-chevauchement peut le taire 24 h
- Sévérité      : S2
- Domaine       : canal
- Référence     : main 1145473
- Emplacement   : `backend/routes/console.php:166-170` ; `docker-compose.prod.yml:80-91` ; `infra/monitoring/prometheus/alerts.yml`
- Constat       : la commande est planifiée toutes les 5 minutes avec `->withoutOverlapping()` **sans argument** (expiration par défaut de Laravel : 1440 min) ; le conteneur `scheduler` a `healthcheck: disable: true` ; aucune alerte Prometheus ne porte sur l'ordonnanceur ni sur l'outbox ; aucun horodatage de dernier passage n'est conservé.
- Preuve        : `sed -n '158,171p' backend/routes/console.php` (`01_inventaire.txt`) ; `sed -n '80,92p' docker-compose.prod.yml` → `healthcheck: disable: true` avec le commentaire « on RETIRE le contrôle plutôt que d'en garder un qui ment » ; `rg -n "alert:" alerts.yml` → 8 alertes ; `rg -in "outbound|consent|scheduler" alerts.yml` → code 1.
- Témoin négatif: `rg -in "horizon" alerts.yml` trouve bien `HorizonQueueBacklog` — le fichier contient des alertes de file, et le contrôle sait les trouver.
- Impact        : un passage tué à chaud (redéploiement, OOM) laisse le mutex Redis en place jusqu'à 24 h ; 288 passages sont sautés, `schedule:work` reste vert, la file grossit, et personne ne l'apprend puisque la file n'est affichée nulle part (B14-003). C'est le scénario que `config/crm.php:44-51` désigne lui-même comme « le pire des échecs ».
- Reproduction  : `rg` ci-dessus ; le défaut de 1440 min est celui de `Illuminate\Console\Scheduling\Event::withoutOverlapping($expiresAt = 1440)`.
- Correctif     : `->withoutOverlapping(10)` (le passage dure quelques secondes) + une commande de santé horaire (cf. B14-004). ≈ 0,5 j au total.
- Statut        : ouvert

### [B14-007] Le canal sortant est mono-destination alors que le CRM est multi-espaces, et il échappe à toute garde de cloisonnement
- Sévérité      : S2
- Domaine       : sécurité
- Référence     : main 1145473
- Emplacement   : `backend/database/migrations/2026_08_14_000007_crm_outbound_events.php:38-44` ; `backend/app/Models/Scopes/WorkspaceScope.php:24-40` ; `backend/app/Http/Controllers/Api/JournalistsController.php:158` ; `backend/app/Http/Controllers/Api/RgpdRequestsController.php:142` ; `backend/config/crm.php:148`
- Constat       : la table n'a ni `workspace_id` ni RLS ; le recorder et l'émetteur utilisent `DB::table()`, qui échappe au `WorkspaceScope` (donc aussi à `MissingWorkspaceContextException`) ; `scope` est codé en dur à `'business'` dans les deux producteurs ; la destination est une unique variable globale `SITE_CRM_WEBHOOK_URL`.
- Preuve        : `SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname='crm_outbound_events'` → `crm_outbound_events|f|f`. Témoin : la même requête sur `contacts`/`companies`/`activities` (voir `02_temoins-negatifs.txt`). `WorkspaceScope implements Scope` + `apply(Builder $builder, …)` : Eloquent uniquement.
- Témoin négatif: la même requête `pg_class` rend bien `t` pour les tables workspace-scopées — elle verrait la RLS si elle existait sur `crm_outbound_events`.
- Impact        : dès qu'un second espace de travail existe (cas explicitement prévu au §22.5 : « un espace auquel aucune console n'est reliée »), ses oppositions partent vers la console d'Axion-IA. Ce n'est pas de la PII en clair (empreinte sha256), mais c'est une fuite inter-locataires et une contradiction directe avec « tout ce qui est reflet est simplement absent ». Aujourd'hui l'impact est théorique — deux espaces existent en local (`axion-ia`, `vivier-candidats`) et le drapeau est fermé.
- Reproduction  : requête `pg_class` ci-dessus ; lecture de `JournalistsController.php:158` (`'business'` littéral).
- Correctif     : porter la destination par espace (colonne `workspace_id` + résolution de l'URL par espace) et dériver `scope` de l'espace de la fiche. ≈ 1-2 j. À trancher **avant** la bascule finale, pas après.
- Statut        : ouvert

### [B14-008] Le contrat est versionné à l'entrée et pas à la sortie, et aucun test ne compare les deux vocabulaires
- Sévérité      : S2
- Domaine       : canal
- Référence     : main 1145473 (CRM) ; dépôt site
- Emplacement   : `backend/app/Crm/Ingest/SiteSyncEvent.php:118-122` ; `backend/app/Console/Commands/CrmFlushOutbound.php:120-128` ; `Axion-IA/axionia/src/server/crm-sync/inbound.ts:133-160`
- Constat       : le CRM exige et valide `schema_version` sur ce qu'il reçoit (et rejette une version non supportée) ; le corps qu'il **émet** ne porte aucun `schema_version`, et le parseur du site ne le réclame pas.
- Preuve        : `rg -n "schema_version" backend` → présent dans `SiteSyncEvent.php` et `ScrapedRecord.php`, **absent** de `CrmFlushOutbound.php` ; le corps émis est un objet à 7 clés (`event_id`, `event_type`, `person_key`, `email_hash`, `scope`, `origin`, `occurred_at`). Côté site, `parseInboundPayload` ignore les clés inconnues et ne cherche pas de version.
- Témoin négatif: le même `rg` trouve bien les deux usages existants de `schema_version` dans `backend/` — il en trouverait un troisième s'il y en avait un.
- Impact        : le §22.1 exige « un contrat strict et versionné … un document commun aux deux dépôts, avec un numéro de version, et un test de chaque côté qui vérifie que les deux listes sont identiques ». Aucun de ces trois éléments n'existe pour le sens CRM → site. Une divergence de vocabulaire ne rougirait nulle part : elle se manifesterait par un `422 contract_violation` → `gave_up` **immédiat** (`CrmFlushOutbound.php:160-174`), donc par la perte définitive et silencieuse de tous les événements de ce type.
- Reproduction  : `rg -n "schema_version" backend/app/Console/Commands/CrmFlushOutbound.php` → aucun résultat.
- Correctif     : ajouter `schema_version: 1` au corps émis, l'exiger côté site, et écrire de chaque côté un test qui compare la liste des types à une constante partagée. ≈ 0,5 j.
- Statut        : ouvert

### [B14-009] Le filet de rattrapage invoqué par le code n'existe pas
- Sévérité      : S1
- Domaine       : canal
- Référence     : main 1145473
- Emplacement   : `backend/app/Http/Controllers/Api/RgpdRequestsController.php:126-153` ; `backend/app/Http/Controllers/Api/JournalistsController.php:161-171`
- Constat       : les deux producteurs enveloppent `record()` dans un `try/catch` et justifient l'avalement par « le batch de réconciliation quotidien (plan §2.9) rattrape la divergence » ; ce batch n'existe pas dans le dépôt CRM.
- Preuve        : `rg -in "reconcil" backend/app backend/routes` → code 1, aucun résultat. `rg -n "signature = 'crm:" backend/app/Console/Commands/` → une seule commande, `crm:flush-outbound`.
- Témoin négatif: le même motif `reconcil` trouve bien 4 fichiers côté site (`crm-sync/reconcile.ts`, `health.ts`, `index.ts`, tests) — et cette réconciliation-là compare **l'outbox du site à ses propres sources** : elle est structurellement incapable de voir un événement que le CRM n'a jamais mis en file.
- Impact        : une exception dans `record()` (base indisponible, contrainte violée) fait perdre l'événement pour toujours, avec pour seule trace un `Log::error` — alors que le commentaire du code affirme le contraire à qui vient le lire. C'est une **fausse sécurité inscrite dans le produit**, du même genre que celle que `AGENTS.md` dénonce pour les gates de budget.
- Reproduction  : les deux `rg` ci-dessus.
- Correctif     : soit écrire la commande de réconciliation (comparer `journalists.opt_out = true` et `rgpd_requests.status = 'done'` aux lignes `sent` de l'outbox) — ≈ 1 j ; soit, a minima, corriger les deux commentaires pour qu'ils cessent de promettre un filet absent — ≈ 15 min. **Le commentaire doit être corrigé même si la commande est écrite plus tard.**
- Statut        : ouvert

### [B14-010] L'effacement d'un journaliste n'émet rien, dans le contrôleur même qui émet pour l'opposition
- Sévérité      : S1
- Domaine       : conformité
- Référence     : main 1145473
- Emplacement   : `backend/app/Http/Controllers/Api/JournalistsController.php:176-182`
- Constat       : `destroy()`, documenté « Droit à l'effacement RGPD : soft-delete », exécute `$journalist->delete()` et retourne 204 sans mettre aucun événement en file, alors que `optOut()` (l. 142-174) du même fichier le fait.
- Preuve        : lecture des lignes 138-183 ; `rg -n "ConsentOutboundRecorder" backend/app/Http/Controllers/Api/JournalistsController.php` → une seule occurrence d'appel, l. 155.
- Témoin négatif: le contrôle trouve bien l'appel de `optOut()` dans **ce fichier précis** — l'absence dans `destroy()` est une absence réelle, pas un angle mort du contrôle.
- Impact        : une personne effacée du CRM continue d'être adressée par le site. Le §22.2 exige « effacement propagé ». Deux gestes voisins dans la même console, l'un propagé, l'autre non — l'utilisateur n'a aucun moyen de deviner la différence.
- Reproduction  : `DELETE /api/v1/journalists/{id}` puis `SELECT count(*) FROM crm_outbound_events` → inchangé.
- Correctif     : appeler `recordForEmail('erasure', …)` avant le `delete()`, sur le modèle exact de `optOut()`. ≈ 30 min. **Sans B14-002, la propagation restera sans effet côté site** — les deux corrections vont ensemble.
- Statut        : ouvert

### [B14-011] Cinq des six familles d'événements exigées par le §22.2 n'ont aucun émetteur
- Sévérité      : S2
- Domaine       : canal
- Référence     : main 1145473 ; cahier des charges v2 §22.2
- Emplacement   : `backend/app/Crm/Outbound/ConsentOutboundRecorder.php:33` ; `backend/routes/api.php:263-281` ; `backend/app/Http/Controllers/Api/ContactsController.php:138-154`
- Constat       : le §22.2 « Du CRM vers la console » exige 6 familles (Consentement, Identité, Commercial, Réclamation, Rendez-vous, Interaction) ; seule Consentement a un émetteur, et partiellement.
- Preuve        : `EVENT_TYPES` est fermé à 3 valeurs de consentement ; la contrainte CHECK Postgres l'est aussi (donc un événement d'une autre famille est **structurellement impossible** sans migration). `ContactsController::update()`/`destroy()` répondent 501 ; `POST /crm/arbitrage/{id}/attach` mute sans émettre ; `ls backend/app/Models/` → 20 modèles, aucun d'opportunité, de devis, de réclamation ni de rendez-vous.
- Témoin négatif: `rg "ConsentOutboundRecorder" backend/app` trouve bien les deux appels existants — le contrôle sait repérer un émetteur.
- Impact        : le §22 est décrit comme « nouveau et central », avec pour exigence « tout ce qui arrive ou se passe dans l'une doit être connu de l'autre, automatiquement ». Sur 19 événements exigés dans ce sens, 2 sont émis. Notamment, le §22.6 promet « marquer honoré / absent → le statut redescend vers la console tout seul » : ce retour n'existe pas, alors que **le sens inverse fonctionne** (le site émet `calendly_canceled` / `no_show` depuis `enrich.ts:235-248`).
- Reproduction  : lecture croisée du §22.2 et de `EVENT_TYPES`.
- Correctif     : chantier, pas correctif — c'est le contenu de l'étape « canal §22 » du chantier cible. Ce constat sert de référence de départ : **la table et le mécanisme de réessai sont bons et réutilisables**, seul le vocabulaire et les points d'appel manquent.
- Statut        : ouvert

### [B14-012] `person_key` est prévu, jamais renseigné, et de toute façon jeté par le site
- Sévérité      : S2
- Domaine       : canal
- Référence     : main 1145473 ; dépôt site
- Emplacement   : `backend/app/Crm/Outbound/ConsentOutboundRecorder.php:52` ; `backend/app/Crm/Ingest/SiteSyncEvent.php:192,304-309` ; `Axion-IA/axionia/src/server/crm-sync/inbound.ts:243-261`
- Constat       : `record()` accepte `$personKey`, les deux producteurs ne le passent pas, et `applyEffect()` côté site n'utilise que `email_hash`.
- Preuve        : les deux lignes produites par les gestes joués portent `"person_key":null` (`03_gestes-emission.txt`). Côté site, `findSubscriberIdByHash` ne prend que l'empreinte ; `person_key` n'apparaît dans aucune requête.
- Témoin négatif: le test du dépôt `CrmOutboundTest.php:200` passe explicitement `personKey: 'pk-42'` et vérifie qu'il traverse — le mécanisme fonctionne, ce sont les **appelants réels** qui ne s'en servent pas.
- Impact        : le §22.1 fait de la paire (empreinte e-mail, identifiant de fiche CRM) la « clé commune », et le §22.2 précise que c'est « ainsi que la console apprend quel identifiant porter ». Aujourd'hui le rapprochement repose entièrement sur l'empreinte, ce qui rend `no_match` indiscernable d'un succès (le site répond 200 dans les deux cas). À noter : le CRM renvoie bien `subject_id` dans la réponse *synchrone* de `/site-sync` (`IngestOutcome::toArray()`), mais `emit.ts` côté site ne conserve que `result.status` — l'information existe et se perd au bord.
- Reproduction  : `SELECT person_key FROM crm_outbound_events` après les deux gestes → `NULL, NULL`.
- Correctif     : côté site, persister `subject_id` reçu dans la réponse de `/site-sync` ; côté CRM, l'inclure dans le corps sortant. ≈ 0,5 j de chaque côté. Prérequis du §22.4 (liens croisés « Voir la fiche »).
- Statut        : ouvert

### [B14-013] En production, le canal est ouvert dans le sens site → CRM et fermé dans le sens CRM → site
- Sévérité      : S1
- Domaine       : conformité
- Référence     : main 1145473 ; production `api.axion-crm-pro.com` (lecture seule)
- Emplacement   : `backend/config/crm.php:79,145,148` ; environnement de production
- Constat       : `CRM_INGEST_ENABLED=true` et `SITE_SYNC_HMAC_SECRET` sont présents dans le conteneur `api` de production ; `CRM_OUTBOUND_ENABLED` et `SITE_CRM_WEBHOOK_URL` sont absents.
- Preuve        : relevé par l'agent 8 (`docker inspect`, lecture seule) — `04_PREUVES/agent-08/04_prod-env-isolation-tz.txt` : `CRM_INGEST_ENABLED=true`, `CRM_INGEST_CANDIDATES_ENABLED=true`, `SITE_SYNC_HMAC_SECRET=***MASQUE***`, aucun `CRM_OUTBOUND_*`. Je **n'ai pas** rejoué cette mesure moi-même (pas d'accès SSH à la production depuis ce poste) — c'est une preuve de pair, nommée comme telle, cf. §6.
- Témoin négatif: le même relevé contient bien 8 variables dont plusieurs `CRM_*` — il aurait montré `CRM_OUTBOUND_ENABLED` si elle était définie. En local, l'agent 17 mesure indépendamment `crm.outbound_enabled = false` / `env CRM_OUTBOUND_ENABLED = NULL` (`04_PREUVES/agent-17/skip-flags.txt`).
- Impact        : c'est exactement la divergence que le code annonce et prétend prévenir. `JournalistsController.php:148-150` écrit : « sans cela le site continuerait d'adresser une personne que le CRM a opposée, et la prochaine synchro site → CRM la rouvrirait ». **C'est l'état de la production aujourd'hui**, et il l'est de façon asymétrique : le site pousse ses oppositions vers le CRM, le CRM ne repousse rien. Toute opposition ou tout effacement décidé dans la console CRM reste local.
- Reproduction  : `docker inspect` du conteneur `api` de production (lecture seule).
- Correctif     : ce n'est pas un défaut de code mais une bascule à décider. Ce qui doit être corrigé avant la bascule : B14-002 (sinon l'effacement mentira), B14-005 (sinon la première panne perdra des événements) et B14-003/B14-004 (sinon personne ne le saura). **Ne pas activer `CRM_OUTBOUND_ENABLED` avant ces trois-là.**
- Statut        : ouvert

### [B14-014] L'anti-doublon du producteur peut écraser une décision plus récente
- Sévérité      : S3
- Domaine       : canal
- Référence     : main 1145473
- Emplacement   : `backend/app/Crm/Outbound/ConsentOutboundRecorder.php:80-89`
- Constat       : la déduplication cherche une ligne `pending|failed` de même `(event_type, email_hash, scope)` et **retourne son `event_id`** sans comparer ni mettre à jour le `payload`.
- Preuve        : lecture des lignes 76-89 ; aucune écriture dans la branche de retour anticipé.
- Témoin négatif: le test `CrmOutboundTest.php:320-328` prouve que la branche est bien atteinte (deux appels, une seule ligne) — elle est vivante, ce qu'elle ignore est donc réellement ignoré.
- Impact        : deux journalistes distincts partageant une adresse de rédaction produisent un seul événement, dont le `payload` porte le `journalist_id` du premier. Comme le `payload` n'est de toute façon jamais émis (§2.2), l'impact sur le site est nul aujourd'hui ; il deviendra réel dès que le contexte traversera.
- Reproduction  : deux `recordForEmail('consent_optout', 'redaction@…', 'business', payload: [...])` avec des `payload` différents → un seul `event_id`, le premier `payload`.
- Correctif     : fusionner les `payload` ou horodater la dernière décision. ≈ 1 h. À traiter en même temps que l'émission du `payload`.
- Statut        : ouvert

---

## 6. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **L'environnement de production, de mes propres mains.** Je n'ai pas d'accès SSH au VPS depuis ce poste. B14-013 s'appuie sur le relevé `docker inspect` archivé par l'**agent 8** (`04_PREUVES/agent-08/04_prod-env-isolation-tz.txt`) et sur la mesure locale indépendante de l'**agent 17**. Conformément à la règle 7 du dossier, je ne vérifie pas ma propre pièce : je nomme la sienne. **À contre-vérifier par quelqu'un qui a l'accès.**
2. **Un POST réel vers le site.** Interdit par ma consigne (« n'émets aucun événement réel vers le site de production ») et impossible sans le secret partagé. J'ai joué la panne contre une destination injoignable, ce qui exerce le chemin `ConnectionException` mais **pas** les chemins 422 / 503 / 200 contre le vrai gestionnaire du site. Les sémantiques 422/503/2xx sont vérifiées par le test du dépôt (`CrmOutboundTest.php`) avec `Http::fake`, et le comportement réel du site est établi par **lecture** de `route.ts` — pas par un aller-retour réel. **La preuve manquante est un aller-retour signé de bout en bout entre les deux dépôts, en local.** C'est le contrôle qui manque le plus à ce canal.
3. **`CRM_SYNC_ALERT` a-t-elle déjà sonné ?** Indécidable en l'état : le site ne persiste aucune notification (dédoublonnage Redis à TTL 1 h, aucune table dans `schema.prisma`), et je n'ai pas accès à l'historique du groupe Telegram ni au projet Sentry. « Jamais sonné » et « sonné dans le vide » sont indiscernables — **et c'est en soi un défaut d'observabilité**, que je signale sans le chiffrer.
4. **L'écran `/admin/observability` ouvert pour de vrai.** J'ai établi par lecture que le champ `outbound` n'est ni typé ni rendu (B14-003), avec témoin négatif. Je n'ai pas ouvert l'écran dans un navigateur : l'API locale était saturée par une dizaine d'agents concurrents, et j'ai préféré une mesure statique honnête à une capture d'écran obtenue dans le bruit. **À confirmer d'un coup d'œil par l'agent chargé des écrans.**
5. **Le 422 et le 503 contre le *vrai* gestionnaire du site.** ~~Non rejoué~~ — **résolu pendant la rédaction** : `php artisan test --filter=CrmOutbound` a fini et rend **15 tests passés, 52 assertions** (`04_PREUVES/agent-14/05_tests-depot-rejoues.txt`). Les sémantiques 2xx / 422 / 503 / 500 sont donc vérifiées **dans une exécution que j'ai lancée**, et non plus seulement par lecture. **Ce qui reste non vérifié est plus étroit et plus important** : ces tests utilisent `Http::fake`, donc ils prouvent ce que fait le CRM face à un 422/503/2xx **supposé** — jamais l'aller-retour réel avec le gestionnaire du site. Or c'est précisément là qu'est B14-005 : le site ne renvoie *jamais* 503, donc le test « un 503 fait ATTENDRE sans consommer de tentative » passe au vert en exerçant **une branche que la production n'atteindra pas**. C'est le piège n° 19 du dossier — une garde irréprochable qui mesure le mauvais objet. **La preuve manquante reste un aller-retour signé de bout en bout entre les deux dépôts, en local** (cf. point 2).
6. **Le comportement de `withoutOverlapping()` à 24 h, joué.** La valeur par défaut est **mesurée** dans le Laravel effectivement installé (`grep -rn 'expiresAt = 1440' vendor/laravel/framework/.../Scheduling/` → `ManagesAttributes.php:70,145`, `CallbackEvent.php:138`, `PendingEventAttributes.php:35`), mais je n'ai pas **provoqué** un mutex orphelin. Provoquer un mutex orphelin demanderait de tuer un passage à chaud — hors de ce que je m'autorise sur un atelier partagé par une dizaine d'agents.
7. **La fusion de fiches (`DeduplicationService`).** Repérée comme non-émettrice par recherche de références, mais je n'ai pas déroulé son code : elle appartient au périmètre d'un autre agent. **La ligne 9 de ma liste est un signalement, pas une mesure complète.**
8. **`GdprErasureService` n'énumère pas les candidats.** Le geste joué rend `deleted: {contacts, email_validations, rgpd_requests, notifications, magic_links, journalists, media_email, health_practitioners}` — **pas de `candidates`**. C'est hors de mon périmètre (canal), je le passe à l'agent RGPD sans le chiffrer.

9. **Un fichier `backend/nul` traîne dans la copie de travail** (non suivi, pas de moi — je n'ai créé aucun fichier dans le produit). C'est l'artefact classique d'un `… > nul` lancé depuis `cmd.exe`. **Il a fait sortir un de mes contrôles en code 2 au lieu de 1** (`rg` : `backend/nul: Fonction incorrecte (os error 1)`), ce qui aurait pu me faire lire un « aucun résultat » là où la recherche avait en fait échoué. J'ai relancé la recherche sans lui (§5, point 3 du rapport). **Signalé à qui de droit : sur ce dépôt, un `rg` récursif depuis la racine de `backend/` n'est pas fiable tant que ce fichier est là.** À rapprocher du piège n° 1 du dossier (Windows).
