# RAPPORT DE CLÔTURE — plan CRM du 2026-08-13 (site axion-ia.com ↔ Axion CRM Pro)

> Rédigé le **2026-08-17**, à l'issue de l'**E2E n°2 « regard neuf »**.
>
> Le plan `_PLANS/2026-08-13_ORDRE-MISSION-AUTOPILOT-CRM.md` fixe son propre
> critère de fin (§ VÉRIFICATIONS FINALES) : « les deux E2E 100 % verts,
> journaux à l'appui, captures archivées → rapport final **PRODUCTION READY** ».
>
> Ce document est ce rapport. Il ne dit pas PRODUCTION READY.

---

## VERDICT EN UNE PAGE

**Le dispositif fonctionne en production. Le plan ne peut pas être clos selon
ses propres termes.** Ce sont deux affirmations distinctes, toutes deux exactes.

Ce qui est vrai :

- les 14 étapes du runbook sont soldées (13 exécutées, 5 bis et 13 reportées
  avec motif) ;
- la boucle site → CRM est vivante, signée, idempotente, et mesurée comme telle
  en production ;
- l'E2E n°1 a été mené en profondeur, avec preuve par la rougeur sur chaque
  garde critique.

Ce qui ne l'est pas :

1. **La séquence prescrite a été jouée à l'envers.** Le §1.3 du runbook écrit :
   « 🔴 **Sans ces deux feux verts, ne pas commencer l'étape 1.** » L'activation
   (étapes 1 → 14) s'est déroulée les 14 et 15 août ; l'E2E n°2 a été
   explicitement reporté le 14 août à 23:xx (« à faire par Will ou en session
   dédiée ») et n'a été joué que **le 17 août — aujourd'hui**. Le contrôle qui
   devait autoriser la mise en service a eu lieu trois jours après elle.

2. **L'E2E n°2 a trouvé deux défauts réels du produit**, dont un défaut RGPD
   bloquant qui rendait l'anti-réinsertion inopérante (§3). Ils sont corrigés
   (PR #142, #143), mais leur existence signifie que le feu vert n°2 n'était pas
   acquis d'avance.

3. **Le §E ne peut pas être coché intégralement**, parce que le montage décrit
   au §A n'est **pas exécutable tel qu'il est écrit** : la moitié « site » de
   l'environnement est inatteignable (base de données inexistante, résolution
   DNS impossible sans élévation administrateur) et la console n'est pas
   authentifiable en local (§4, défauts D-07, D-11, D-13). Ce n'est pas un
   renoncement de l'opérateur : c'est le résultat que l'E2E n°2 est fait pour
   produire — **mesurer si la documentation suffit. Elle ne suffit pas.**

4. **Un piège majeur connu depuis le 14 août n'a jamais été reporté dans le
   document d'exécution** : `docker compose restart` ne relit pas `env_file`.
   Le journal de l'E2E n°1 le consigne noir sur blanc ; le runbook prescrit
   toujours `restart`, et présente comme « le seul contrôle qui ne ment pas »
   une vérification qui, jouée après un `restart`, revient vide (§4, D-05).

---

## 1. INVENTAIRE DE CE QUI A ÉTÉ CONSTRUIT

### 1.1 Dépôt CRM (`will383842/axion-crm-pro`)

| Lot | Contenu | PR |
|---|---|---|
| Gate 0 | CI réellement bloquante, vérifications post-déploiement, extinction d'un flake Postgres | #53, #54, #55 |
| L0 | Durcissement RLS, rôle applicatif `axion_app` (`NOSUPERUSER NOBYPASSRLS`), contexte workspace | #56 |
| L1 | Socle taxonomie, table `candidates`, tags gouvernés, index | #57 |
| L2 | Ingestion `POST /api/internal/site-sync` (HMAC, idempotence, classement) | #58 |
| L3 | Funnel de collecte unique + registre `scraping_sources` | #59 |
| L4 | RGPD bi-système + purges | #60 |
| L5 | Mini-outbox CRM → site (`crm_outbound_events`) | #61 |
| L6 | Console v2 (`/console/contacts`, `/console/vivier`, `/console/arbitrage`, fiche 360°) | #62 |
| Suites | Écrans, permissions, listes de suppression, translittération, build | #66, #67, #80 → #84 |
| **E2E n°2** | **Tests des trois chemins aveugles de `AudienceBuilderService`** | **#142** |
| **E2E n°2** | **Correctif RGPD : scope de l'opposition vivier** | **#143** |

### 1.2 Dépôt SITE (`will383842/axion-ia`)

| Lot | Contenu | PR |
|---|---|---|
| L2 | Outbox `crm_sync_outbox` + 14 points de capture | #598 |
| L4 | Consentements v2, registre `consent_events`, vivier, RGPD → CRM | fusionné |
| L5 | Observabilité, webhook entrant, `CRM_SYNC_ALERT`, réconciliation | #601, #602, #604 |

### 1.3 État d'activation

Les **9 drapeaux CRM** et les **2 drapeaux site** (web **et** worker) sont à
`true`. Étape **5 bis** reportée (verrou de 2,5 à 5 min mesuré sur CPX22, non
nécessaire tant que les leads sans SIREN partent en arbitrage). Étape **13**
(`VIVIER_STOCK_ENABLED`, envoi aux 71 candidats du stock) laissée à **OFF** :
c'est un envoi d'e-mails réels à des personnes, décision de Will.

Backfill des tags `src:` : **4 294 895 / 4 294 895**, 0 erreur.

---

## 2. LES DEUX E2E

### 2.1 E2E n°1 — profondeur (2026-08-14 → 2026-08-15)

Journal : `_SESSIONS/2026-08-13_AUTOPILOT-CRM-journal.md` (1 945 lignes,
append-only).

Dix garanties prouvées **en production, par la mesure** :

1. RLS opérante : 4 294 898 fiches avec contexte workspace, **0 sans** ;
2. ingestion : `created`, puis `noop_idempotent` au rejeu ;
3. drain réel par le worker BullMQ : `pending` → `sent` en 6,5 min ;
4. classement automatique juste (devis → opportunité, base légale
   précontractuelle, tag de provenance) ;
5. funnel de collecte : backfill-only, n'écrase aucun champ déclaré ;
6. garde CNIL : candidature v1 → **422**, v2 → vivier **uniquement** ;
7. canal retour CRM → site : signé, `duplicate` au rejeu ;
8. purges RGPD : dry-runs à 0 sous mode strict ;
9. intégrité : quatre comptages identiques avant/après ;
10. données `ZZ TEST` purgées des deux bases.

Trois défauts d'écran et un défaut de build ont été trouvés le 15 août lors
d'une vérification visuelle **en production** (PR #66, #67) : `placeholderData`
rendant `isLoading` menteur, 6 383 ms pour 51 lignes faute d'index d'ordre,
et « Console non activée » affiché pendant que la question était encore posée.

⚠️ Cette vérification du 15 août est parfois désignée « E2E n°2 » dans le
journal. **Elle n'en est pas un** au sens du §E : elle a été menée en
production (le §A impose l'environnement intégré **local**), par le même fil de
session (le §E exige « un agent **DIFFÉRENT** »), et elle n'a exécuté ni la
checklist B.1 → B.12, ni les croisements du §E.3, ni les 26 captures du §E.4.

### 2.2 E2E n°2 — regard neuf (2026-08-17, cette session)

Journal : `_REPORTS/e2e2-preuves/journal-session-E2E2.md` — copie versionnée de
`Axion-IA/_SESSIONS/2026-08-17_E2E-2-REGARD-NEUF-CRM.md`, dont le répertoire
d'origine **n'est pas un dépôt Git** (le journal n'existait donc que sur un
disque). Preuves : `_REPORTS/e2e2-preuves/`.

**Contrainte cardinale respectée** : le journal de l'E2E n°1 n'a été ouvert
qu'**après** l'achèvement du §E. Le montage n'a suivi que le §A, le runbook et
les README.

**Chronomètre du montage** (§E.1.2 — au-delà d'une heure, la documentation est
insuffisante) :

| Jalon | Écoulé |
|---|---|
| Pile CRM complète (8 conteneurs) | 4 min 41 s |
| Base recréée, 53 migrations, 3 seeders | ~9 min |
| CRM pleinement configuré, 8 drapeaux vérifiés dans le conteneur | ~14 min |
| **Moitié « site » de l'environnement** | **jamais atteinte** |

La partie CRM tient largement dans l'heure. La partie site n'est pas une
question de durée : elle est **bloquée** (D-07, D-13).

#### Checklist §E.2 — parcours B.1 → B.12

Le §B.0 impose le **geste utilisateur réel dans le navigateur**, « jamais un
appel direct à une fonction interne ». Ce geste étant impossible (le site ne
démarre pas), les scénarios ont été joués **par l'endpoint d'ingestion signé**
(Geste E du runbook), qui vérifie le **contrat CRM** mais **pas** la chaîne
d'émission du site. La distinction est portée dans chaque ligne.

| # | Scénario | Contrat CRM | Geste site | Détail |
|---|---|---|---|---|
| B.1 | Formulaire unifié, 12 types | 🟢 | 🔴 non joué | **13 des 14 valeurs de `CRM_FORM_TYPES` acceptées (200)** ; `recrutement` → 422 consentement, ce qui **confirme** la bascule vers l'univers vivier annoncée comme contre-intuitive. Type inconnu → **422 `unknown_form_type`**. Rejeu → `noop_idempotent`, 0 doublon. Avec SIREN → `created`, tag `src:site-formulaire-devis` posé. |
| B.2 | Podcast | 🟢 | 🔴 | `podcast` accepté, `relation_type` reste `prospect` (aucune valeur « podcast »). |
| B.3 | Simulateur de gains | 🟢 | 🔴 | **`simulateur_roi` accepté (200)** — la non-régression du défaut qui perdait tous les leads du simulateur tient. |
| B.4 | Lettre d'information | 🟡 | 🔴 | `newsletter_optin` / `optout` acceptés, opposition `scope = business` ✔, `email_hash` posé ✔ — **mais l'adresse est aussi stockée EN CLAIR** (§3.3). Double opt-in non vérifiable sans le site. |
| B.5 | Avis client | 🟡 | 🔴 | `review_posted` accepté ; `relationType()` rend bien `client`, seul événement entrant à porter cette qualité. La garantie « le contenu de l'avis reste sur le site » est une propriété de **l'émission**, non vérifiable ici. |
| B.6 | Calendly ×4 | 🟡 | 🔴 | Les 4 `kind` distincts sont créés pour une même personne. La garde « vrai changement de statut » est côté site : non jouée. |
| B.7 | Candidature offre | 🟢 | 🔴 | **a** : `created`, workspace `vivier-candidats` **exclusivement**, `relation_type = candidat_commercial`, `consent_version = careers-v2-2026-08-13`, `consent_vivier_at` **renseigné**, tags `src:site-candidature-offre` + `cand-offre:<slug>`, `cv_ref` = une **référence**. **b** : mêmes gestes sans la case → `consent_vivier_at` **NULL**. **Le contraste a/b est prouvé.** **c** : consentement v1 → **422 `candidate_consent_v2_required`**. **d** : clé `workspace` forgée → **422 `unknown_field`**. |
| B.8 | Tunnel Mémo | 🔴 | 🔴 | Wizard 10 écrans : impossible sans le site. |
| B.9 | Chatbot | 🔴 | 🔴 | L'émetteur transactionnel est une propriété du site. |
| B.10 | Opposition vivier | 🔴 **DÉFAUT** | 🔴 | **Rouge, deux fois** : opposition inscrite en `business` au lieu de `vivier`, anti-réinsertion inopérante. Voir §3.1. Corrigé (PR #143). |
| B.11 | RGPD art. 15 | 🔴 | 🔴 | Le parcours self-service part du site. |
| B.12 | RGPD art. 17 | 🔴 | 🔴 | Idem. |

#### Croisements §E.3

| Croisement | État | Détail |
|---|---|---|
| Même personne, trois chemins (§C.1) | 🔴 | Exige le funnel de collecte **et** le site. |
| Doublon SIREN multi-sources (§C.2) | 🟢 | Même SIREN par deux formulaires → **une** fiche, enrichie (`website`, `size_category` ajoutés), `field_origins` porte `declared` pour chacun des 5 champs. Tags de provenance **cumulés**, non écrasés. |
| Opposition puis re-scrape | 🔴 → 🟢 | Rouge avant correctif, vert après (PR #143). |
| Contact reclassé deux fois (§C.3) | 🔴 | Exige la console. |
| Volumes 100 000+ (§C.5) | 🔴 | Non joué. |
| Cohérence des compteurs (§C.6) | 🟢 (portée réduite) | API `/crm/contacts-hub/counts` → `total = 1`, `prospect = 1` ; `COUNT` SQL de contrôle → 1 et 1. **Écart 0**, sur une volumétrie de 1. |
| Pannes simulées §D | 🟡 3 sur 7 | **D.2** : `CRM_INGEST_ENABLED=false` → **503 `ingest_disabled`** ; candidats fermés → **503 `candidates_ingest_disabled`**. **D.3** : type inconnu → **422**. **D.4** : signature fausse → **401 `bad_signature`** ; horodatage à −600 s → **401 `stale_signature`** ; en-têtes `X-Crm-*` croisés → **401 `bad_signature`** ; préfixe `sha256=` toléré → **200**. Les quatre attendus du §D.4 sont exacts. **D.1, D.5, D.6, D.7 exigent l'outbox du site : non joués.** |
| Mobile < 1024 px | 🟡 | 9 captures produites (voir §5). |

⚠️ **Le comportement que le §D.2 désigne comme « le plus important de tout le
dispositif » — `attempts` INCHANGÉ sur un 503 — n'a PAS été vérifié.** Le code
de réponse du CRM est juste ; le compteur qu'il ne faut pas incrémenter vit
dans l'outbox du **site**.

#### Captures §E.4

Voir §5. **7 des 13 écrans** ont été atteints, en desktop 1440 **et** en mobile
390 px. Les 4 écrans de console v2 rendent une **coquille correcte mais un
contenu qui reste indéfiniment en squelette de chargement** — cause établie
au §4 (D-11/D-12), et **locale**, non imputable au produit.

---

## 3. DÉFAUTS DU PRODUIT TROUVÉS PAR L'E2E n°2

### 3.1 🔴 BLOQUANT — l'opposition d'un candidat au vivier atterrissait dans la liste COMMERCIALE

**PR #143** — `fix/opposition-vivier-scope`.

`SiteSyncClassifier::universe()` ne rend `vivier` que pour une
`application_submitted` ou un `form_submission` de type `recrutement`. Un
`opt_out` n'est **ni l'un ni l'autre** : il retombait donc **toujours** en
`business`, et `recordOpposition()` dérivant son scope de cet univers,
l'opposition d'un candidat était enregistrée en `scope = business`.

**Conséquence 1 — l'anti-réinsertion ne mordait pas.** Mesuré :

```
POST /api/internal/site-sync  (opt_out, payload.scope=vivier)       → 200
POST /api/internal/site-sync  (application_submitted, même e-mail)  → 200
  {"status":"updated","subject_type":"candidate","subject_id":1}
```

Le §B.10 contre-test 1 exige qu'elle **ne réapparaisse PAS**.

**Conséquence 2 — l'étanchéité jouait à l'envers.** La personne se retrouvait
désinscrite des communications **commerciales** qu'elle n'avait jamais demandé
à quitter, tout en **restant au vivier** qu'elle avait demandé à quitter.

| | attendu par le §B.10 | obtenu |
|---|---|---|
| `opt_out` scope `business` | 1 | **2** |
| `opt_out` scope `vivier` | 1 | **0** |

**Le site le disait pourtant déjà.** `syncVivierOppositionToCrm()`
(`src/server/crm-sync/index.ts`) pose `payload.scope = "vivier"` avec ce
commentaire, mot pour mot :

> « le dit une seconde fois, dans le corps même du message, **pour que le CRM
> n'ait pas à le déduire de l'univers** »

Le CRM le déduisait quand même. **Aucun compilateur ne relie les deux dépôts** —
c'est exactement le mode de panne de `simulateur_roi`.

**Pourquoi personne ne l'avait vu** : le test qui vérifie l'étanchéité entre
univers (`l'opposition d'un univers ne ferme pas l'autre`) **pré-insère
lui-même** la ligne `scope = vivier` dont il a besoin. Aucun test ne demandait à
l'ingestion de la **produire**.

**Correctif** : `oppositionScope()` exige **deux signaux concordants** — la
valeur déclarée (liste fermée `business|vivier`) **et** un `subject_ref` de
candidature. Le payload ne choisit pas sa destination, il confirme une
déduction du CRM : la règle du §B.7.d est préservée, et un test la verrouille.
`universe()` n'est pas touché — le faire répondre `vivier` pour un `opt_out`
ferait passer l'événement par `upsertCandidate()`, c'est-à-dire **créer une
fiche par l'acte même qui demande à être oublié**.

### 3.2 🔴 `AudienceBuilderService` — le composant qui décide à qui part un e-mail

**PR #142** — `test/audiences-not-tags-batch`. Les trois chemins signalés non
testés le 16/08 l'étaient toujours le 17/08 (fichier de test présent, 0
occurrence des trois). 15 tests ajoutés, **écrits et vus rouges avant tout
correctif**.

| Chemin | Tests | Verdict initial |
|---|---|---|
| combinateur `not` | 6 | **3 rouges — défaut réel** |
| `tags` / `contains_any` | 6 | **2 rouges — défaut réel** |
| `Bus::batch` (> 5 000 fiches) | 3 | **verts d'emblée** — non cassé, seulement non vérifié |

**Défaut A — `not` perdait les fiches dont le champ est NULL.**
`whereNot($positive)` produit `NOT (colonne = ?)` ; sur une colonne NULL la
comparaison vaut UNKNOWN, et `NOT UNKNOWN` reste UNKNOWN : la ligne est
éliminée. Une audience « tout sauf le 75 » perdait **en silence** toutes les
fiches sans département renseigné — l'essentiel d'une base collectée.
`evalCondition()`, qui évalue les **mêmes** critères en mémoire (waterfall
step12), répondait l'inverse. Deux évaluateurs, deux audiences, un seul critère
— alors que `buildPositive()` porte en commentaire l'engagement qu'ils restent
« STRICTEMENT alignés ».

**Défaut B — une condition `tags` inexploitable visait TOUT LE MONDE.** Liste de
slugs vide, ou opérateur autre que `contains_any` → la condition était
**retirée de la requête**. Une audience « porte un de ces tags » dont la liste a
été vidée ne désignait plus personne : elle désignait le **workspace entier**.
Désormais elle ne vise personne. L'échec est **fermé**, pas ouvert.

**Non corrigé, nommé ici** : sous les combinateurs `all` / `any`, les
opérateurs `neq` et `not_in` divergent encore entre SQL (qui élimine les NULL)
et l'évaluateur en mémoire (qui les garde). Corriger cela **élargirait** les
audiences ; sur le composant qui choisit des destinataires, ce n'est pas une
décision à prendre en passant. **Arbitrage Will.**

### 3.3 🟡 L'adresse est stockée en clair dans la liste d'opposition

Le §B.4 et le §B.10 exigent tous deux « **email jamais en clair** ». Mesuré :

```
 id |  scope   |       email_en_clair        |        hash
----+----------+-----------------------------+---------------------
  1 | business | zz-news@example.invalid     | 933bb26cf8150450...
  2 | business | zz-cand-v2a@example.invalid | 8529187ba1465c40...
```

`recordOpposition()` insère `'email' => $event->email()` **à côté** de
`email_hash` : le hachage ne protège donc rien. Nuance à peser avant de
corriger — la table `opt_out` est **antérieure** au lot L4 (les colonnes
`email` et `phone` préexistent ; `scope` et `email_hash` ont été ajoutées par la
migration L4) et elle est partagée avec le mécanisme d'opposition du funnel de
collecte. Retirer la colonne du chemin site-sync est simple ; décider du sort
des lignes existantes ne l'est pas. **Non corrigé — arbitrage Will.**

**En production, cette table est vide** (`opt_out = 0`) : aucune donnée réelle
n'est aujourd'hui exposée.

---

## 4. DÉFAUTS DE DOCUMENTATION ET D'HYGIÈNE (15)

Le §E.1.4 pose la règle : « toute divergence entre le runbook et la réalité est
un défaut, même mineure ». Détail intégral dans
`_SESSIONS/2026-08-17_E2E-2-REGARD-NEUF-CRM.md`. Résumé :

| # | Défaut | Gravité |
|---|---|---|
| D-01 | §A.2 nomme `axionia-wt-crm-e2e` ; le répertoire réel est `axionia-wt-e2e-crm` (segments inversés) — et ce n'est **plus un worktree Git** | mineur |
| D-02 | La commande de migration du §A.1 **échoue** sur une base locale déjà peuplée (`pg_partman` / `audit_logs`) ; aucun geste de remise à zéro n'est documenté | bloquant au montage |
| D-03 | Toute commande artisan crache ~25 lignes de pile d'appel (`telescope_entries` inexistante) — **après un succès** | trompeur |
| D-04 | Le seeder prescrit au §A.1 **ne pose rien** : `OwnerUserSeeder` (qui crée le workspace business) n'est mentionné nulle part → **0 tag `src:site-formulaire-*`**, et tout le §B.1 rougirait pour une raison de montage | 🔴 bloquant |
| **D-05** | **`docker compose restart` ne recharge pas `env_file`** — prouvé des deux côtés. Le même geste est prescrit par le **Geste A du runbook de production**, dont l'étape 5 est présentée comme « le seul contrôle qui ne ment pas » : jouée après un `restart`, elle revient **vide**. **Piège déjà consigné le 14/08 dans le journal, jamais reporté dans le runbook.** | 🔴 **critique** |
| D-06 | Deux fichiers `.env` pour un seul service (racine via `env_file`, `backend/.env` via Laravel) | moyen |
| D-07 | Node ne résout **pas** `api.localhost` (`ENOTFOUND`) ; le remède prescrit exige une **élévation administrateur**, et le §G.1 écarte explicitement l'alternative → **§A inexécutable** sans droits admin | 🔴 bloquant |
| D-08 | L'attendu du §B.7.c est **périmé** : une 3ᵉ version de consentement (`vivier-stock-2026-08-14`) a été ajoutée sans mise à jour du scénario | mineur |
| **D-09** | **La suite de tests, lancée comme le Makefile la documente, vise la base de DÉVELOPPEMENT et tente de la vider.** `phpunit.xml` pose `DB_DATABASE=axion_crm_test` **sans `force="true"`** ; la variable du conteneur gagne. Seul le drapeau `CRM_DB_APP_ROLE_ENABLED=true` a empêché le `DROP`. Avec la valeur par défaut (`false`), la connexion est **SUPERUSER** et le `DROP` réussit. Invisible en CI. | 🔴 **critique** |
| D-10 | Aucun identifiant de console documenté ; le mot de passe est **généré au seed** dans `storage/app/private/seeders/owner-initial-password.txt` | bloquant pour §E.4 |
| D-11 | **La console ne peut pas s'authentifier en local** : `Domain=.localhost` est refusé par les navigateurs ; en cookie *host-only*, le SPA ne peut plus **lire** `XSRF-TOKEN` inter-hôtes → 419. **Les deux configurations échouent.** | 🔴 bloquant pour §E.4 |
| D-12 | La **double authentification est obligatoire** au premier accès (`first_login_required`) ; le §A ne le mentionne pas | bloquant pour §E.4 |
| D-13 | **La base de données du site n'existe pas** (`role "axion_ia" does not exist`) et le §A.2 ne la mentionne jamais. Tranche au passage le §G.2 : le fichier est `.env.local`, et il ne porte **aucune** des 5 variables demandées | 🔴 bloquant |
| D-14 | Divergences mineures : `app` sert un **build de production** (Caddy) et non Vite ; le tableau §1.1 du runbook déclare non fusionnées des branches qui le sont ; le `event_id` d'exemple n'est pas un UUID valide et est pourtant accepté | mineur |
| **D-15** | **Le mot de passe administrateur en clair n'était ni suivi ni ignoré.** `OwnerUserSeeder` dépose `backend/storage/app/private/seeders/owner-initial-password.txt` ; les motifs `/storage/…` du `.gitignore` sont ancrés à la **racine** et ne couvrent pas `backend/storage/…`. Le fichier était donc **proposé au premier `git add -A`**. Le `.gitignore` porte déjà, quelques lignes plus haut, un commentaire décrivant exactement ce piège — il n'avait été refermé que pour `bootstrap/cache`. **Corrigé dans cette PR.** | 🔴 sécurité |

**Trois corrections à porter en priorité** — les deux premières mordent **hors
du poste de travail**, la troisième est un risque de fuite de secret :

- **D-05** — remplacer `docker compose restart …` par
  `docker compose up -d …` au §A.1 **et** dans le Geste A du runbook. Sans
  cela, la séquence d'activation en 14 étapes ne pose, littéralement, aucun
  drapeau.
- **D-09** — ajouter `force="true"` à la ligne 33 de `backend/phpunit.xml`.
  Une ligne. Elle sépare « je lance les tests » de « je vide ma base de
  développement ».
- **D-15** — ancrer les motifs `storage/` sous `backend/` dans le `.gitignore`.
  **Fait dans la PR qui porte ce rapport.**

---

## 5. CAPTURES ARCHIVÉES

`_REPORTS/e2e2-preuves/captures/` — 18 images PNG + `journal-captures.json`.
Chaque écran en **desktop 1440 px** et en **mobile 390 px**, produites au
Playwright avec `ignoreHTTPSErrors` (§A.3, piège 6). **Toutes ont été
regardées** (§E.1.3).

| # | Écran §E.4 | Desktop | Mobile | Ce qui a été vu |
|---|---|---|---|---|
| 1 | Console — espace Business | ✔ | ✔ | Coquille complète (nav 9 sections, fil d'Ariane, recherche ⌘K). **Contenu en squelette permanent** |
| 2 | Console — espace Vivier | ✔ | ✔ | Idem. L'API répond **403 « Accès refusé à l'univers vivier candidats »** : l'étanchéité du §C.4 **mord** — elle échoue, elle ne rend pas une liste vide |
| 3 | Base froide | ✔ | ✔ | **Rendu complet** : compteurs (TOTAL, ENRICHIES, TOP TAILLE, TOP NAF), 4 onglets, 13 filtres |
| 4 | Fiche 360° | ✔ | ✔ | Coquille + fil d'Ariane portant le `person_key`. Contenu en squelette |
| 5 | File d'arbitrage | ✔ | ✔ | Coquille. **L'API répond 200 et liste bien les `pending_match`** (vérifié hors navigateur) |
| 6 | Actions de masse | ✔ | ✔ | Non atteignable sans contenu |
| 7 | Recherche globale | ✔ | ✔ | **Rendu complet** : filtres de statut e-mail (valide, catch-all, inconnu, invalide, générique, jetable) |
| 8–9 | Console SITE — carte « Synchro CRM », lignes en erreur | ✖ | ✖ | Le site ne démarre pas (D-13) |
| 10–11 | Site — candidature, wizard Mémo | ✖ | ✖ | Idem |
| 12 | Courriel `vivier-information` | ✖ | ✖ | Idem |
| 13 | Confirmation d'opposition vivier | ✖ | ✖ | Idem |

**7 écrans sur 13**, tous en double viewport. Les 6 manquants dépendent tous du
même blocage.

Autres preuves archivées dans `_REPORTS/e2e2-preuves/` :

- `pest-audience-AVANT-correctif.txt` / `-APRES-` : la rougeur, puis la verdeur ;
- `pest-optout-AVANT.txt` / `-APRES.txt` : idem pour le correctif RGPD.

---

## 6. IDENTIFIANTS DE TEST CRÉÉS ET PURGE

**Aucune donnée n'a été écrite en production pendant cette session.** Tous les
appels ont visé `https://api.localhost` (pile Docker locale). Les seules
requêtes de production ont été des **lectures** (santé, comptages, outbox).

Données créées dans la base **locale** `axion_crm` (jetable, recréée en début
de session, `pg_dump` de l'état antérieur conservé dans le scratchpad) :

| Objet | Identifiants |
|---|---|
| `activities` | 28 lignes, `external_ref = site:event:00000000-0000-4000-8000-*` |
| `companies` | 1 fiche, SIREN `123456782`, « ZZ TEST SARL (2e source) » |
| `candidates` | 2 fiches, `ZZ CAND V2A` / `ZZ CAND V2B` |
| `opt_out` | 2 lignes, adresses `@example.invalid` |
| adresses | toutes en `zz-*@example.invalid` (§B.0) |

**Aucune purge de production n'est requise.** La purge des `ZZ TEST` de
l'activation du 14 août avait déjà été faite et vérifiée à 0 (E2E n°1).

---

## 7. MÉTRIQUES DE SYNCHRO (production, 2026-08-17)

CRM `main = b4c5000`, 7 conteneurs en marche, 11 clés de configuration posées.

| Mesure | Valeur |
|---|---|
| `companies` | **4 295 349** |
| `contacts` | **1 319 567** |
| `candidates` | **0** |
| `activities` issues du site (`site:event:%`) | **1** |
| `opt_out` | **0** |
| `crm_outbound_events` | **0** |
| `rgpd_requests` | **0** |
| `crm_sync_outbox` (site), par statut | **`sent` = 1** — et rien d'autre |

**Lecture de ces chiffres.** La chaîne est **cohérente de bout en bout** :
1 événement émis, 1 délivré, 1 activité créée. **Zéro** ligne `failed`,
`pending` ou `gave_up` — le critère §F.4 (« aucune ligne `gave_up` non
expliquée ») est **satisfait**.

Mais elle est aussi **quasi inerte** : en trois jours d'activation, le site a
produit **un seul** événement. Le canal est prouvé, il n'est pas *éprouvé*. La
largeur du contrat — 14 types de formulaire, candidatures, Calendly, lettre
d'information, RGPD — n'a **jamais** été traversée par du trafic réel. C'est
précisément ce que l'E2E n°2 devait couvrir, et précisément pourquoi son report
compte.

Deux lectures possibles, que ces chiffres seuls ne départagent pas : volume de
formulaires réellement très faible, ou point de capture qui n'émet pas. **La
mesure qui trancherait** : comparer, sur la même fenêtre, le nombre de
soumissions enregistrées côté site (`submissions`, `job_applications`,
`newsletter_subscribers`) au nombre de lignes d'outbox. Si l'écart est nul, le
silence est celui du trafic ; sinon, un point de capture est muet. **À faire.**

---

## 8. LES ÉCARTS QUI RESTENT

### 8.1 Bloquants pour clore le §F

| Critère §F | État |
|---|---|
| 1. E2E n°1 : §B, §C, §D verts | 🟡 mené en profondeur, mais §C et §D partiellement, et en production |
| 2. **E2E n°2 : toutes les cases §E cochées, captures archivées** | 🔴 **7 écrans sur 13 ; B.8 à B.12 non jouées ; §D.1, D.5, D.6, D.7 non jouées** |
| 3. Preuve par la rougeur sur chaque garde critique | 🟡 faite pour consentement v2, idempotence, anti-réinsertion (aujourd'hui), audiences ; **pas** pour l'étanchéité par les humains ni « le froid ne se réchauffe pas » sous forme de test qui rougit |
| 4. Aucune ligne `gave_up` non expliquée | 🟢 **satisfait** (production : 1 `sent`, rien d'autre) |
| 5. Compteurs : écart 0 | 🟢 satisfait sur la portée mesurée |
| 6. Liste 100 000+ fluide, p95 < 500 ms | 🔴 **non mesuré** (l'index correctif de la PR #66 a été posé, mais la mesure de sortie n'a pas été refaite) |
| 7. Journal portant les deux campagnes | 🟢 satisfait |

### 8.2 Ce qu'il faut faire pour pouvoir écrire « PRODUCTION READY »

1. **Corriger D-05 et D-09** (deux lignes, l'une dans le runbook, l'autre dans
   `phpunit.xml`). Ce sont les deux qui mordent hors du poste de travail.
2. **Rendre le §A exécutable** : ajouter `OwnerUserSeeder`, le geste de remise à
   zéro de la base, la création de la base du site, l'identifiant de console,
   l'étape TOTP, et **trancher la question `app.localhost` / `api.localhost`**
   (le plus simple : servir le SPA et l'API sous un seul hôte en local, ce qui
   supprime d'un coup D-07, D-11 et le besoin de `NODE_TLS_REJECT_UNAUTHORIZED`).
3. **Rejouer le §E** une fois le montage réparé — en particulier B.8 à B.12,
   les 7 pannes du §D, et les 6 écrans manquants.
4. **Mesurer le §F.6** (100 000+ lignes, p95 < 500 ms).
5. **Trancher les deux arbitrages nommés** : divergence `neq`/`not_in` sur NULL
   (§3.2), et adresse en clair dans `opt_out` (§3.3).
6. **Fusionner #142 et #143** — CI verte, 17 contrôles au vert, 0 échec.
   ⚠️ Fusionner déclenche le déploiement en production ; ces deux PR n'ont pas
   été fusionnées, le §F du plan plaçant l'activation *après* les feux verts,
   pas pendant.

### 8.3 Hors périmètre, rappelé pour mémoire

- **L7 / campagnes / envoi d'e-mails** : interdit explicitement par le plan.
- **Étape 13** (`VIVIER_STOCK_ENABLED`, 71 candidats) : mécanique prête, envoi
  réel = décision de Will.
- **Étape 5 bis** (`contacts.company_id` nullable) : reportée avec motif.
- **Sauvegardes** et **décalage +2 h** : chantiers clos, non rejoués.

---

## 9. CONCLUSION

Le plan du 13 août a produit un dispositif qui fonctionne : la boucle est
vivante, signée, idempotente, et la production le montre. Ce rapport ne remet
pas cela en cause.

Il constate autre chose : **le contrôle qui devait autoriser la mise en service
a eu lieu trois jours après elle, et il n'était pas vert.** Il a trouvé un
défaut RGPD qui rendait inopérante une garantie annoncée comme acquise — le
droit d'opposition d'un candidat — et deux défauts sur le composant qui décide
à qui part un e-mail. Aucun des trois n'était visible depuis les tests
existants, parce que chacun se trouvait exactement là où les tests
s'arrêtaient : celui de l'étanchéité **pré-insérait** la ligne qu'il aurait dû
faire produire ; ceux des audiences ne descendaient pas jusqu'aux trois chemins
signalés.

Il constate aussi que le document de montage n'est pas exécutable tel qu'il est
écrit, et qu'un piège identifié le 14 août est resté dans un journal au lieu
d'être reporté dans le runbook — au point que la séquence d'activation, prise à
la lettre, ne pose aucun drapeau.

**Verdict : NON CLOS.** Les correctifs sont écrits, testés et prêts ; la liste
de ce qui manque est finie et courte. Mais le plan exige un rapport
« PRODUCTION READY », et l'écrire aujourd'hui reviendrait à taire six écrans
non vus, cinq scénarios non joués, quatre pannes non simulées et un critère de
performance non mesuré. **Un rapport de clôture qui tait un manque est pire
qu'une absence de rapport.**
