# AGENT 49 — Auditeur du contrat d'échange §22

> Périmètre : le §22 complet du cahier des charges v2 (l. 661-741), confronté aux deux
> extrémités déjà mesurées du canal. **Travail de synthèse et de recoupement** : je ne
> re-mesure pas ce que les agents 13, 14 et 32 ont établi ; je le confronte au contrat, je
> le vérifie sur **ma** référence, et je tranche ce qu'aucun d'eux ne pouvait voir depuis
> son bout.

## 0. Références — relues moi-même (règle 6, règle 14 du dossier)

| Objet | Référence mesurée |
|---|---|
| Dépôt **CRM** | `main` **`e8924b8`** — `fix(rgpd+acces): rectification du registre + acces CRM rendu (#189)` |
| Dépôt **site** | `main` **`eb754332`** — `fix(qualiopi): l'espace stagiaire livrait la pièce d'un tiers… (#739)` |
| CDC | `C:\Users\willi\Downloads\axion-ia-crm-cahier-des-charges-fonctionnel-v2.md` — §22 l. 661-741, §29 l. 953-981 |

Le dossier commun annonce `main = c0c453d`. **Le dépôt a avancé de 4 commits depuis**
(`c0c453d → 17ba4f1 → b53338c → bb60473 → 1145473 → 9d273cd → e8924b8`). Vérifié :

```
$ git diff --stat 1145473 e8924b8 -- frontend/src backend/app/Crm \
      backend/app/Http/Controllers/Api/ObservabilityController.php
(sortie vide)
```

**Mon périmètre est donc octet pour octet identique à celui qu'ont lu les agents 13 et 14.**
Leurs mesures se reportent sans réserve sur `e8924b8`, et je les ai rejouées sur ma
référence pour les deux points dont dépendent mes verdicts (vocabulaire sortant, §22.7 côté
CRM — §7 ci-dessous).

> ⚠️ **Un fait à noter pour la suite de l'audit** : le commit `504737f`
> (`feat(etape1a-p2): les cinq activités et les onze motifs d'échange (§2.3)`) **est déjà
> ancêtre de `main`**. `app/Crm/ActivitesEtMotifs.php` existe : 5 activités, 11 motifs, deux
> tables semées par migration. Le **substrat** de la famille « Interaction » du §22.2 est
> donc là depuis ce matin — **son émetteur, non** (§1.2). Je le signale parce que plusieurs
> documents de l'audit décrivent encore un CRM où « activité » et « motif » n'existent nulle
> part.

**Atelier local du CRM : non utilisé** (consigne du mandat, A-009). Toutes mes mesures sont
statiques, jouées sur le code des deux dépôts. **Aucune écriture en production, aucun
événement réel émis, aucun accès au worktree `crmpro-wt-etape1a`.**

Preuves brutes : `04_PREUVES/agent-49/` — `01_references-et-vocabulaires.txt` ·
`02_para-22-3-ce-qui-ne-traverse-pas.txt` · `03_para-22-4-et-22-5.txt` ·
`04_para-22-6-22-7-criteres.txt` · `05_decompte-22-2.txt`.

---

## 1. LE LIVRABLE — le tableau complet des familles du §22.2, dans les deux sens

Méthode de décompte archivée dans `05_decompte-22-2.txt`. Un événement = un item de la
colonne « Événements » du §22.2. Le **gras** du CDC marque ce qui y est annoncé
**déjà existant**.

⚠️ **Une correction au mandat, d'abord.** Mon ordre de mission demande « le tableau des
**6 familles** × 2 sens ». Le §22.2 en compte **6 dans le sens CRM → console** et **8 dans le
sens site/console → CRM** (Capture, Rendez-vous, Devis, Facturation, Livraison, Réclamation,
Messages sortants, Chatbot). **14 familles au total**, pas 6 × 2. Je rends les 14.

### 1.1 Sens site et console axionia → CRM — 8 familles, 48 événements exigés

| Famille | Événements exigés | Émis ? | Reçus ? | Avec quel contenu | Écart |
|---|---|---|---|---|---|
| **Capture** (gras = existant) | **6** — formulaire soumis (14 finalités), candidature déposée, inscription newsletter, désinscription newsletter, avis publié, opposition | **6/6** — `form_submission`, `application_submitted`, `newsletter_optin`, `newsletter_optout`, `review_posted`, `opt_out` | **✅ 200**, signature HMAC solide, rejeu fermé, cloisonnement exemplaire (agent 13) | `person`(5 champs) + `company`(7) + `consent`(4) + `candidate`(5) + `tags` + `payload`. **Jamais le corps du message** (E32-002 §1) | **0 sur le vocabulaire, total sur l'effet** : **100 % des leads business en `pending_match`, `subject_id = null`, 0 fiche créée** (B13-001). Les 14 `FORM_TYPES` sont un miroir exact des 14 `CRM_FORM_TYPES` du site — le seul contrat des deux dépôts réellement aligné. Un `src:` hors référentiel est **perdu en silence avec un 200** (B13-005). SIREN présent + nom absent → **200, adresse détruite** (B13-002) |
| **Rendez-vous** (4 gras) | **5** — réservé, honoré, annulé, absent ; *reporté* | **4/5** — `calendly_booked`, `_completed`, `_canceled`, `_no_show`. **« reporté » n'a aucune valeur de contrat** | **✅ 200** | `kind` + `occurred_at` + `external_ref`. **Ni `startTime`, ni `endTime`, ni `timezone`, ni `location`, ni les URL d'annulation/report** (E32-002 §8-9) | **1 événement manquant, et l'heure du rendez-vous ne traverse pas.** Le §22.6 promet « voir mes rendez-vous du jour dans le CRM » : le CRM sait qu'un rendez-vous a eu lieu, **il ne sait pas quand ni où** |
| **Devis** | **6** — envoyé, consulté, révisé, accepté, refusé, expiré | **0/6** | — | — | **6 sur 6.** La donnée existe côté site (`server/actions/qualiopi/devis.ts`) ; il n'existe ni `event_type`, ni émetteur, ni table de destination |
| **Facturation** (1 gras) | **5** — **numéro client attribué** ; facture émise, payée, en retard, avoir émis | **0/5** — **y compris celui que le CDC annonce existant** | — | — | **5 sur 5.** Le webhook Stripe du site (`app/api/stripe/webhook/route.ts`, `Invoice.payerSiret`) n'émet rien (agent 13 §3.2 #3) — et `payerSiret` est **précisément le SIREN qui débloquerait B13-001** |
| **Livraison, par activité** | **22** — formation 5 · 1:1 3 · audit 3 · implémentation 5 · site web/SaaS 6 | **0/22** | — | — | **22 sur 22 — la plus grosse famille du §22, et la plus vide.** Le site détient tout : `Trainee`/`Enrollment` avec **consentement déjà horodaté et versionné** (agent 13 §3.2 #1), `CoachingSession`, sessions Qualiopi, conventions, attestations, abonnements. Joué par l'agent 13 : `enrollment_created` → **422** |
| **Réclamation** | **2** — ouverte, close | **0/2** | — | — | **2 sur 2.** `Reclamation.reclamantEmail` existe côté site (agent 13 §3.2 #9) |
| **Messages sortants** | **1** — e-mail transactionnel envoyé par la console | **0/1** | — | — | **1 sur 1.** `SubmissionReply` et son statut de remise (`failed`/`bounced`) existent côté site ; **aucun `CrmEventType` ne les couvre** (E32-002 §2) |
| **Chatbot** (gras) | **1** — lead capturé | **1/1**, *par une branche sur deux* | ✅ | idem Capture | **Asymétrie mesurée** : `capturerLead` émet (`capturer-lead.ts:156`), `escalader-question` **n'émet pas** (agent 13 §3.2 #5). Même outil, même formulaire, un lead sur deux. Chatbot éteint en production — le CDC le dit lui-même |

**Sous-total entrant : 48 exigés, 11 émis (23 %).**

### 1.2 Sens CRM → console axionia — 6 familles, 19 événements exigés

| Famille | Événements exigés | Émis ? | Reçus ? | Avec quel contenu | Écart |
|---|---|---|---|---|---|
| **Consentement** (3 gras) | **3** — désinscription, réinscription, effacement | **2/3 déclarés et produits ; 1 seul atteignable par un humain** | **✅ 200 les trois fois** — et c'est le problème | Corps à **7 clés** : `event_id`, `event_type`, `person_key`, `email_hash`, `scope`, `origin`, `occurred_at`. **Ni `payload`, ni `schema_version`** (B14-008). `person_key` toujours `NULL` (B14-012) | `consent_optout` : code présent, **aucun écran ne l'appelle**. `consent_optin` : **zéro producteur**, et le site le renvoie `"ignored"` inconditionnellement. `erasure` : émis, le site répond **`200 applied`**, et **rien n'est effacé** (B14-002, **S0**) |
| **Identité** (1 gras) | **3** — **fiche créée ou rapprochée**, coordonnée corrigée, fiches fusionnées | **0/3** — **y compris celui que le CDC annonce existant** | — | — | `POST /crm/arbitrage/{id}/attach` mute et n'émet rien ; `PUT /contacts/{id}` répond **501** ; aucune fusion exposée. Le CRM renvoie pourtant `subject_id` dans la réponse **synchrone** de `/site-sync` — **`emit.ts` ne le conserve pas** (B14-012). L'information existe et se perd au bord |
| **Commercial** | **4** — opportunité créée / gagnée / perdue, demande de création de devis | **0/4** | — | — | **Aucun modèle d'opportunité** dans `app/Models/` (20 modèles, recompté à `e8924b8`) |
| **Réclamation** | **1** — réclamation qualifiée sur une interaction | **0/1** | — | — | Le substrat existe depuis `504737f` : le motif `support ou réclamation client` est l'un des 11 `ActivitesEtMotifs::MOTIFS`. **L'émetteur, non** |
| **Rendez-vous** | **5** — réservé sur la page interne, reporté, annulé, honoré, absent | **0/5** | — | — | **Le symétrique fonctionne dans l'autre sens** : le site émet `calendly_booked/completed/canceled/no_show`. Le §22.6 promet « marquer honoré / absent → **le statut redescend vers la console tout seul** » : ce retour **n'existe pas**, et il est **structurellement impossible** — le CHECK Postgres de `crm_outbound_events` est fermé à 3 valeurs de consentement |
| **Interaction** | **3** — compte rendu validé avec motif(s) et actions ; entretien réalisé avec candidat retenu ; décision d'embauche | **0/3** | — | — | Depuis `504737f`, les **5 activités et 11 motifs existent en base**. Le compte rendu, l'entretien et la décision d'embauche n'ont **ni table, ni route, ni émetteur** |

**Sous-total sortant : 19 exigés, 2 émis (11 %).** Chiffre obtenu indépendamment de
l'agent 14, et **identique au sien**.

### 1.3 Le total, et la phrase qui le résume

| | Exigés | Émis | Reçus | Avec un effet conforme au contrat |
|---|---|---|---|---|
| Site/console → CRM | 48 | **11** | 11 | **0** — 100 % `pending_match`, aucune fiche business créée (B13-001) |
| CRM → console | 19 | **2** | 2 | **1** — `consent_optout`, qu'aucun écran n'appelle |
| **Total §22.2** | **67** | **13 (19 %)** | 13 | **1** |

> **Le seul événement sortant qu'un être humain peut déclencher aujourd'hui dans la console
> du CRM est `erasure` — et c'est celui qui n'a aucun effet.** L'autre, `consent_optout`,
> fonctionne côté site mais n'est appelé par aucun écran (B14-001). Les deux moitiés du
> canal sortant sont donc disjointes : celle qui marche est inatteignable, celle qui est
> atteignable ne marche pas.

---

## 2. Grille du §22, point de contrat par point de contrat

Une ligne par exigence du §22. `—` = sans objet. Une case « non vérifié » porte son motif.

| § | Exigence | État | Mesure / recoupement | Qui l'a mesuré |
|---|---|---|---|---|
| 22.1 | **Automatique**, aucun flux manuel | ✅ pour ce qui est branché | côté site : émission immédiate + balayage `*/10` ; côté CRM : `everyFiveMinutes()` | 13, 14, moi (§8) |
| 22.1 | **Fiable** — mise en file avant émission | ✅ des deux côtés | `CrmSyncOutbox` (site) et `crm_outbound_events` (CRM) | 13, 14 |
| 22.1 | **Fiable** — réémis avec délais croissants | ✅ | 8 essais, `1,2,4,8,16,32,60,60` min, **joués** | 14 |
| 22.1 | **Fiable** — « jamais perdu si l'autre outil est indisponible **une nuit** » | ❌ | **3 h 02** de panne suffisent à perdre définitivement l'événement (B14-005). Et le site ne renvoie **jamais** 503 : la branche clémente est morte | 14 |
| 22.1 | **Fiable** — clé d'unicité, rejeu sans doublon | ✅ **les deux sens** | entrant : `activities.external_ref` + index UNIQUE réel, rejeu octet pour octet → `noop_idempotent`. Sortant : `event_id` UUID + P2002 côté site | 13, 14 |
| 22.1 | **Signé**, anti-rejeu, **les deux sens** | ✅ | HMAC-SHA256 sur `<ts>.<corps brut>`, `hash_equals`/`timingSafeEqual`, fenêtre ±300 s **symétrique**. 1 témoin positif, 4 négatifs joués | 13, 14 |
| 22.1 | **Sans boucle** | ✅ **et bien fait** | garde applicative + CHECK Postgres `origin='crm'` côté CRM ; `raw.origin !== "crm" → null` côté site. Les deux prouvés | 13, 14, moi |
| 22.1 | **Une clé commune** (empreinte e-mail **et** identifiant de fiche CRM) | ⚠️ **moitié** | `email_hash` / `person_key` traversent dans le sens entrant. Dans le sens sortant `person_key` est **toujours `NULL`**, et le site le journalise puis le jette | 14 |
| 22.1 | **Contrat strict et versionné**, document commun, **test des deux listes** | ⚠️ **asymétrique** | Entrant : `schema_version` **exigé et validé**, schéma **strict** (clé inconnue = 422), et les 14 `FORM_TYPES` sont un miroir exact. Sortant : **aucun `schema_version`** émis, et le parseur du site **ne refuse aucune clé inconnue**. **Aucun document de contrat commun** ; **aucun test croisé** dans l'un ou l'autre dépôt | 14, moi (§3) |
| 22.1 | **Observable** — file, échecs, abandons, rejeu **dans les deux consoles** | ❌ **1 sur 2** | site : `/synchro-crm` complet. CRM : **rien** (§7) | 14, moi |
| 22.1 | **Alerte** au-delà d'une heure en échec | ❌ **aucune, nulle part** | `CRM_SYNC_ALERT` n'existe pas dans le dépôt CRM ; côté site ses 5 motifs portent **exclusivement** sur l'outbox du site | 14 |
| 22.2 | 67 événements, 14 familles, 2 sens | ❌ **13 sur 67 (19 %)** | §1 ci-dessus | moi |
| 22.3 | Preuves de consentement restent côté site | ✅ **décision, appliquée** | §3 | moi |
| 22.3 | Pièces jointes (CV) restent côté site | ✅ **décision, appliquée** | §3 | moi |
| 22.3 | Enregistrements / transcriptions restent dans le CRM | ⚪ **sans objet** | §3 — il n'en existe aucun | moi |
| 22.3 | Contenu des ressentis ne quitte pas le CRM | ⚪ **sans objet** | §3 — la notion n'existe nulle part | moi |
| 22.3 | Aucun outil ne pousse une donnée dont il n'est pas la source | ⚠️ **appliqué dans un sens seulement** | §3 | moi |
| 22.4 | 5 liens depuis la fiche CRM vers la console | ❌ **0/5** | le CRM ne porte **aucun** lien vers la console axionia | moi |
| 22.4 | « Voir la fiche » depuis devis/facture/session/réservation/soumission, + « Lancer la visio » | ❌ **0/6** | l'unique lien de la console pointe la **racine** | 32, moi |
| 22.4 | Boîte de réception : soumission ouverte avec la fiche à côté | ❌ **0/1** | le CRM **n'a aucune boîte de réception** ; celle du site n'a aucun lien CRM | 32 |
| 22.5 | **Connexion unique**, passage sans ressaisie | ❌ **0** | deux magasins d'identité disjoints, aucun OIDC/SAML/SSO (§5) | moi |
| 22.5 | Rôles définis dans chaque outil | ✅ **par accident** | ils sont séparés parce que rien ne les relie | moi |
| 22.5 | Identité visuelle partagée | non vérifié — **hors périmètre**, voir agent 27 | — | 27 |
| 22.5 | **Lien direct et visible entre les deux dans la navigation** | ❌ **1 sur 2, et mal libellé** | console → CRM : un `<a>` codé en dur, libellé « Prospection », vers la racine. CRM → console : **rien** | 32, moi |
| 22.5 | Le CRM reste **détachable** | ✅ **trivialement** | il l'est parce que rien ne le raccorde | moi |
| 22.5 | **Fonctionnement sans console raccordée** | ❌ **0** | aucune notion de raccordement par espace ; destination **globale unique** `SITE_CRM_WEBHOOK_URL`, `scope` codé en dur à `'business'` → un second espace verrait ses oppositions partir vers la console d'Axion-IA (B14-007), **l'exact inverse de la clause** | 14, moi |
| 22.6 | La carte « où je vais pour quoi » | ❌ **elle n'est qu'un tableau du CDC** | §6 | moi |
| 22.6 | Ses 5 règles d'application | ❌ **0 sur 5** | §6 | 32, moi |
| 22.7 | Tableau de bord du canal **dans chaque console** | ⚠️ **1 sur 2** | §7 | 14, 32, moi |
| 22.7 | « Si l'un des deux n'a rien reçu de l'autre depuis un délai anormal, **il le dit** » | ❌ **dans aucune des deux** | §7 — `inbound.lastAt` est calculé et affiché, **jamais comparé à un seuil** | moi |
| §29-5 | Tout événement traverse en < 60 s, 0 doublon | ❌ **non mesurable aujourd'hui** | §8 | moi |
| §29-18 | Aucun point de capture n'est muet | ❌ **non mesurable aujourd'hui** | §8 | 32, moi |

---

## 3. §22.3 — « ce qui ne traverse jamais » : décision ou oubli ?

**C'est la question que mon ordre de mission désigne comme « tout le sujet », et elle a
cinq réponses différentes.** Preuve : `04_PREUVES/agent-49/02_para-22-3-ce-qui-ne-traverse-pas.txt`.

| # | Item du §22.3 | Le contrat l'interdit-il ? | Est-ce **appliqué** ? | Verdict |
|---|---|---|---|---|
| 1 | Les **preuves de consentement** (textes, jetons, horodatages détaillés) restent côté site ; le CRM reçoit le **statut** | **OUI, écrit noir sur blanc** dans l'en-tête du contrat côté site : « *de jeton du site (désinscription, RGPD) : ils restent côté site, qui est la source de vérité du consentement* » | **OUI, structurellement** : `CONSENT_KEYS = ['version', 'at', 'text_ref', 'vivier_at']` — une version, un instant, une **référence**, jamais le texte. Toute clé hors liste = **422** (`assertOnlyKeys`, l. 116/262/282) | 🟢 **DÉCISION, tenue par un mécanisme** |
| 2 | Les **pièces jointes** (CV) restent sur le site ; le CRM porte une **référence** | **OUI, écrit** : « *de pièce jointe : le CV reste sur le disque du site, `cv_ref` n'est qu'une référence (surface RGPD minimale)* » | **OUI** : `CANDIDATE_KEYS = ['family','offer_slug','attributes','experiences','cv_ref']` — aucun champ binaire, aucune URL de fichier ; même garde du 422 | 🟢 **DÉCISION, tenue** |
| 3 | Les **enregistrements et transcriptions** restent dans le CRM ; la console n'y accède pas | **NON — rien ne l'interdit** | **SANS OBJET** : `grep -rniE "recording\|transcri" app/Models/ database/migrations/` → **aucun résultat**. Il n'existe **aucun enregistrement, aucune transcription** dans le CRM. Témoin négatif : le même grep trouve bien les notions qui existent (`ActivitesEtMotifs`, les 20 modèles) | ⚪ **NI DÉCISION NI DÉFAUT — l'exigence est sans objet** |
| 4 | Le **contenu des ressentis** ne quitte pas le CRM | **NON** | **SANS OBJET** : `grep -rniE "ressenti\|sentiment" app/ database/migrations/` → **aucun résultat**. La notion n'existe nulle part | ⚪ **SANS OBJET** |
| 5 | **Aucun outil ne pousse vers l'autre une donnée dont il n'est pas la source** | **OUI en principe**, et **appliqué dans un seul sens** | **Sens site → CRM : appliqué, et bien.** Schéma **strict** (clé inconnue = 422) ; et le site **ne décide ni l'univers ni le type de relation** — le CRM classe, `TOP_LEVEL_KEYS` ne nomme jamais le workspace (agent 13 §2.8). **Sens CRM → site : rien.** `parseInboundPayload` ne contient **aucun** équivalent d'`assertOnlyKeys` — il lit 7 clés attendues et **ignore silencieusement tout le reste**. Témoin négatif : le même fichier porte bien 9 refus explicites (`return null`) — le contrôle sait refuser, il ne refuse simplement pas les clés inconnues | 🟠 **ASYMÉTRIQUE : décision d'un côté, indifférence de l'autre** |

### Le tri, en une phrase

**Sur cinq interdits, deux sont une décision de conception réellement appliquée par un
mécanisme (1 et 2), deux sont sans objet parce qu'il n'existe rien à protéger (3 et 4), et
le cinquième n'est appliqué que dans le sens site → CRM.**

### Et le retournement que seul le recoupement fait apparaître

Le §22.3 a été écrit pour un produit où **le CRM est le système riche** : c'est de lui qu'il
faut empêcher les enregistrements, les transcriptions et les ressentis de sortir. Le
recoupement avec E32-002 dit **l'inverse de ce que le §22.3 suppose** : le CRM déclare
lui-même que « *la timeline est un INDEX des touchpoints, jamais une copie de leur
contenu* », et **il ne détient aucun des quatre objets que le §22.3 protège**. Aujourd'hui
c'est **la console qui détient le contenu** — 13 catégories d'information (E32-002) — et le
CRM qui n'en a aucun.

**Conséquence pour l'arbitrage** : les items 3 et 4 du §22.3 ne sont pas des garanties, ce
sont des **promesses non encore engagées**. Le jour où le CRM portera des enregistrements et
des transcriptions (§17.2, §20), **rien dans le code ne les empêchera de traverser** — le
seul mécanisme de discipline du canal, le schéma strict, existe uniquement dans la direction
qui n'en a pas besoin. **C'est I49-002.**

---

## 4. §22.4 — les liens croisés : confirmation, et une aggravation

**Je confirme l'agent 32, et je mesure le bout qu'il n'a pas mesuré : le côté CRM.**
Preuve : `03_para-22-4-et-22-5.txt`.

```
$ cd Axion-CRM-Pro/frontend
$ grep -rniE "axion-ia\.(fr|com)|axionia|SITE_ADMIN_URL|VITE_SITE" src/
(aucun résultat)

$ grep -rniE "cr[ée]er un devis|voir le devis|voir la facture|voir la session|voir la r[ée]clamation|lancer la visio" src/
(aucun résultat)
```

**Témoin négatif** : le même `grep` sur le même répertoire trouve bien les **9 liens
externes qui existent** dans ce frontend (`target="_blank"` : site web d'entreprise, LinkedIn,
un lien GitHub vers `_docs/PROSPECTION-PIPELINE.md`, un lien de réglages). Le contrôle sait
trouver un `<a>` ; il n'y en a **aucun** vers la console axionia.

| Exigence du §22.4 | Liens exigés | Existants | Mesure |
|---|---|---|---|
| Depuis la fiche CRM : « Créer un devis », « Voir le devis », « Voir la facture », « Voir la session », « Voir la réclamation » — **sur l'objet exact** | 5 | **0** | aucun de ces cinq libellés n'existe dans `frontend/src` |
| Depuis un devis, une facture, une session, une réservation ou une soumission de la console : « Voir la fiche » | 5 | **0** | l'unique lien de la console est un `<a href="https://app.axion-crm-pro.com">` codé en dur hors du SSOT de navigation, libellé « **Prospection** », vers la **racine** (E32-006) |
| Depuis une réservation : « **Lancer la visio** » | 1 | **0** | aucune notion de salle de visio dans le CRM |
| Boîte de réception : la soumission s'ouvre avec la fiche à côté | 1 | **0** | **le CRM n'a aucune boîte de réception** (agent 32, ligne 1 de son tableau) |
| Nouvel onglet ou aperçu latéral, au choix | — | — | sans objet |
| **Total** | **12** | **0** | |

**Verdict : CONFIRMÉ, et pire que ce que l'agent 32 pouvait dire depuis son bout.** Il avait
mesuré « 0 lien profond dans les deux sens, et un unique lien libellé Prospection vers la
racine ». Je complète : **côté CRM il n'y a pas même ce lien-là.** L'asymétrie est totale —
la console sait que le CRM existe, le CRM ignore que la console existe.

**Ce qui n'est pas l'obstacle** : la clé du lien profond est **déjà calculée des deux
côtés**. `hashEmailForLookup(email)` du site = `Submission.contactEmailHash` = la `person_key`
du CRM, et la route `/console/personnes/$personKey` existe. Le blocage n'est ni technique ni
conceptuel — c'est **A05-001** : **0 contact sur 1 319 567 ne porte de `person_key`**, donc
tout lien profond ouvrirait une fiche vide.

---

## 5. §22.5 — « deux consoles, une seule identité »

### 5.1 Y a-t-il une identification unique ? Non, et il n'y a même pas de mécanisme.

```
$ cd Axion-CRM-Pro/backend
$ grep -rniE "\boidc\b|openid|\bsaml\b|single.sign|\bsso\b|socialite|keycloak" app/ config/ routes/ composer.json
(aucun résultat)
```

**Témoin négatif** : le même `grep` trouve bien les mécanismes d'authentification qui
**existent** — `config/sanctum.php`, `MagicLinkController`, `TwoFactorController`,
`config/auth.php` guard `web` driver `session` provider `users`. Le contrôle n'est pas
aveugle à l'authentification ; il constate qu'aucune fédération n'existe.

Et côté site, le seul endroit où la chaîne « SSO » apparaît dans `src/` est **du texte
commercial** sur les pages ville (`montrouge.ts` : « *authentification forte (SSO/MFA)* »).
Témoin négatif idéal : le produit **vend** le SSO et ne l'implémente pas pour lui-même.

**Deux magasins d'identité entièrement disjoints :**

| | Console axionia | Console CRM |
|---|---|---|
| Modèle | `AdminUser` (Prisma) | `users` (Eloquent) |
| Mot de passe | argon2id | bcrypt Laravel |
| Session | `next-auth` **5.0.0-beta.32** | Sanctum, cookie SPA stateful |
| 2FA | TOTP otplib, colonnes `two_factor_*` sur `AdminUser` | enrôlement 2FA propre — **qui écrit trois colonnes inexistantes** (A07-001) |
| Rôles | `AdminRole` | rôles CRM |

Aucun identifiant partagé, aucun jeton d'échange, aucun annuaire commun. **§22.5 « connexion
unique » : 0.** Le seul demi-point tenu — « les rôles restent définis dans chaque outil » —
l'est **par accident** : ils sont séparés parce que rien ne les relie.

### 5.2 ⚠️ Le croisement avec A-012 — et c'est le point que je tranche

**A-012 : personne ne s'est jamais connecté au CRM en production. 1 compte, 0 session, 0
jeton, depuis le 2026-05-17.** Trois causes cumulées : mot de passe initial annoncé une seule
fois à la console, `MAIL_MAILER` défini nulle part → aucun courriel ne part → ni lien magique
ni réinitialisation, et A07-001 qui bloquerait de toute façon la première connexion.

**Conséquence que ni l'agent 13, ni le 14, ni le 32 ne pouvaient formuler :**

> **L'exigence §22.5 n'a jamais pu être éprouvée, et le critère 23 du §29 n'est pas
> seulement à 0 % — il est *injouable*.**

Son protocole est : « *une personne extérieure reçoit dix actions de la table §22.6 ; elle
ouvre le bon outil dix fois sur dix* ». **Le protocole exige d'ouvrir les deux consoles, et
l'une des deux ne s'ouvre pas.** Un audit qui rapporterait « critère 23 : 0/10 » décrirait un
défaut d'ergonomie ; la mesure réelle est que **le test ne peut pas commencer**.

Et le corollaire va plus loin : **la dernière règle d'application du §22.6 — « la console
axionia n'est jamais l'écran d'accueil de la journée » — est violée 100 % des jours depuis le
2026-05-17**, non par un défaut de navigation mais parce que la console axionia est **le seul
écran ouvrable**. **C'est I49-004.**

### 5.3 Détachable, et « sans console raccordée »

- **« Le CRM reste détachable »** : ✅ **trivialement tenu**. Rien ne le soude à la console —
  parce que rien ne l'y relie du tout (§4).
- **« Fonctionnement sans console raccordée »** : ❌ **0**. Aucune notion de raccordement par
  espace de travail n'existe dans le code
  (`grep -rniE "raccord|outil d'engagement|detachable" backend/app frontend/src` → une seule
  occurrence, sans rapport, dans `EligibiliteCampagne.php`). Et B14-007 mesure **l'inverse
  exact de la clause** : destination globale unique `SITE_CRM_WEBHOOK_URL`, `scope` codé en
  dur à `'business'` dans les deux producteurs, table sans `workspace_id` et **sans RLS**
  (`relrowsecurity = f`) — un second espace de travail verrait **ses** oppositions partir vers
  la console d'Axion-IA, là où le §22.5 promet que « *tout ce qui est reflet est simplement
  absent* ».

---

## 6. §22.6 — la carte « où je vais pour quoi » : implémentée, ou tableau du CDC ?

**Verdict : elle n'est qu'un tableau du cahier des charges. Elle n'existe nulle part, dans
aucun des deux dépôts, sous aucune forme.** Preuve : `04_para-22-6-22-7-criteres.txt`.

```
$ cd Axion-CRM-Pro/frontend && grep -rniE "o[uù] je vais|quel outil|console axionia" src/
(aucun résultat)
$ cd axionia && grep -rniE "o[uù] je vais|ouvrir le CRM|console axionia" src/lib src/components/admin
(aucun résultat)
```

> **Portée de cette mesure, dite honnêtement.** Les deux `grep` ci-dessus sont **ciblés** :
> côté site sur `src/lib` et `src/components/admin`, c'est-à-dire les deux seuls endroits où
> vit la navigation de la console. Une recherche **récursive sur tout `axionia/src/`** a été
> lancée et **tuée à 120 s** (dépôt trop volumineux). Sa sortie partielle est conservée dans
> `04_para-22-6-22-7-criteres.txt` et **va dans le même sens** : les seuls résultats du motif
> « quel outil » sont du **contenu éditorial** (page `stack-ia`, catalogues de formation),
> aucun n'est un dispositif d'orientation entre les deux consoles. **Je présente donc le
> verdict comme concluant sur la navigation, et partiel sur le reste du dépôt site.**

Le seul dispositif d'orientation qui existe dans l'un des deux produits est
`frontend/src/components/OnboardingTour.tsx` — **6 étapes** (bienvenue, barre latérale,
recherche globale ⌘K, entreprises, tableau de bord, mode sombre). **Aucune ne nomme la
console axionia, aucune ne dit ce qui se fait où.** C'est une visite de la maison, pas une
carte du quartier. Et elle est gardée derrière `GET /auth/me` : **A-012 dit que personne ne
l'a jamais vue.**

**Les cinq règles d'application du §22.6, une par une :**

| Règle | État | Mesure |
|---|---|---|
| Le groupe « Contacts » de la console axionia n'existe plus (une entrée « ouvrir le CRM ») | ❌ | **12 entrées** dans le groupe, **17 écrans vivants**, et 8 des 12 lisent la même table `Submission` (agent 32) |
| Les rendez-vous n'ont plus d'écran dans la console | ❌ **à moitié, et trompeusement** | `/contacts/rendez-vous` et `/contacts/rendez-vous/calendrier` **sont bien devenus des redirections** le 2026-07-29 — mais elles redirigent vers `/contacts/appels`, **qui est l'écran vivant** avec sa vue calendrier. Le nom a été retiré, pas la fonction |
| Deux liens permanents : « Console axionia » dans le CRM, « CRM » en tête de la console | ❌ **1 sur 2, et mal libellé** | CRM → console : **aucun** (§4). Console → CRM : un seul, libellé « **Prospection** », hors SSOT, vers la racine (E32-006) |
| Sur chaque devis, facture, session, réservation de la console : « Voir la fiche » | ❌ **0** | §4 |
| La console axionia n'est jamais l'écran d'accueil de la journée | ❌ **violée 100 % du temps** | **par A-012**, pas par un défaut de navigation : le CRM ne s'ouvre pas |

**0 sur 5.** Et l'écart mesuré par l'agent 32 sur le critère 23 — **17/17** — se lit
maintenant autrement : ce n'est pas un retard d'exécution, c'est que **la carte n'a jamais
quitté le document**. Personne n'a jamais eu à s'orienter entre deux consoles, puisqu'une
seule est ouverte.

---

## 7. §22.7 — le tableau de bord du canal : la tranche

**L'agent 14 dit « absent côté CRM ». L'agent 32 décrit l'écran `synchro-crm` côté site.
Les deux ont raison, et aucun des deux n'a la conclusion.**

### 7.1 Côté CRM — absent. Rejoué sur *ma* référence.

```
$ git diff --stat 1145473 e8924b8 -- frontend/src ...ObservabilityController.php   → (vide)
$ grep -rn "outbound" frontend/src                                                  → (aucun résultat)
$ grep -n "outboundBacklog|outbound" backend/.../ObservabilityController.php
  35:  'outbound' => $this->outboundBacklog(),
  85:  private function outboundBacklog(): array
  88:      $rows = DB::table('crm_outbound_events')
```

**Témoin négatif** : le même `grep -rn "observability" frontend/src` rend 5 lignes (route,
sidebar, page). Le contrôle sait chercher dans ce répertoire.

Le backend **calcule** `{pending, gave_up}` ; **aucun écran ne l'affiche**, l'interface
TypeScript ne déclare pas le champ, et il n'existe **aucune commande de rejeu**
(`gave_up` est un état terminal dont même une remise en échéance forcée ne fait pas sortir —
panne jouée par l'agent 14). **B14-003 confirmé à `e8924b8`.**

### 7.2 Côté site — présent, et à 6 exigences sur 7

`src/app/[locale]/(admin)/[adminPrefix]/synchro-crm/page.tsx` (320 l.) + `_components/RejouerBouton.tsx`.

| Exigence du §22.7 | Site | CRM |
|---|---|---|
| Événements **émis** sur 24 h | ✅ `sent24h` | ❌ |
| Événements **reçus** sur 24 h | ✅ `inbound.last24h` | ❌ |
| **En attente** | ✅ `counts.pending` + `backlog` + seuil affiché | ❌ (calculé, jamais rendu) |
| **En échec** | ✅ `counts.failed` + `failures24h` + **20 lignes détaillées avec le corps exact** | ❌ |
| **Abandonnés** | ✅ `counts.gave_up`, ton `destructive` si > 0 | ❌ (calculé, jamais rendu) |
| **Rejeu en un clic** | ✅ `RejouerBouton` par ligne → `rejouerLigneCrmSyncAction` | ❌ **aucune commande, aucun écran** |
| **Dernier événement reçu de l'autre outil, avec son horodatage** | ✅ « Dernier reçu : {date} » | ❌ |
| **« Si l'un des deux n'a rien reçu de l'autre depuis un délai anormal, il le dit »** | ❌ | ❌ |

### 7.3 La tranche — et le défaut que ni l'un ni l'autre n'a vu

**Verdict : le §22.7 est tenu dans une console sur deux — et la dernière phrase du §22.7
n'est tenue dans aucune des deux.**

```
$ grep -rnE "inbound" src/server/crm-sync/health.ts src/server/crm-sync/alerts.ts
  health.ts:63   inbound: { total: number; last24h: number; lastAt: Date | null };
  health.ts:74   ... inboundTotal, inbound24h, lastInbound ...
  health.ts:147  inbound: { total, last24h, lastAt }
```

`inbound.lastAt` est **calculé, transporté et affiché — jamais comparé à quoi que ce soit.**
Aucun des cinq motifs de `CRM_SYNC_ALERT` (`gave_up | backlog | reconcile_gap |
reconcile_failed | scan_capped`) ne porte sur le silence de l'entrant : ils portent tous sur
l'outbox **du site**.

**Témoin négatif** : le seuil existe pour l'autre sens —
`CRM_SYNC_BACKLOG_THRESHOLD = 50` (`alerts.ts:36`), importé et affiché par `health.ts:16,139`.
**Le dépôt sait poser un seuil et l'afficher ; il ne l'a pas fait sur l'entrant.**

**Pourquoi c'est le défaut qui mord aujourd'hui, et pas un détail d'ergonomie** : B14-013
mesure qu'en production `CRM_OUTBOUND_ENABLED` et `SITE_CRM_WEBHOOK_URL` sont **absents** — le
CRM n'a **jamais pu** émettre. Le seul tableau de bord de canal qui existe affiche donc
« Dernier reçu : — », mois après mois, **sans jamais le signaler**. Le §22.7 a été écrit
exactement pour cette situation, et il ne la couvre pas. **C'est I49-006.**

---

## 8. Critères 5 et 18 du §29 — mesurables aujourd'hui, et avec quel instrument ?

### 8.1 Critère 5 — « Tout événement traverse en moins d'une minute ; 20 événements de chaque famille du §22.2 émis ; 100 % reçus sous 60 s ; 0 doublon après rejeu forcé »

**Verdict : NON mesurable aujourd'hui.** Trois clauses, trois réponses.

**(a) « 20 événements de chaque famille » — impossible.**
Sur **14 familles**, **4 seulement ont un émetteur** : Capture, Rendez-vous, Chatbot (entrant),
Consentement (sortant). **10 familles ne peuvent produire aucun événement** — pour cinq
d'entre elles, le CHECK Postgres de `crm_outbound_events` ou la liste fermée
`SiteSyncEvent::EVENT_TYPES` rendent l'événement **structurellement impossible sans
migration**. Le protocole du critère 5 ne peut pas être joué en entier ; il ne peut être joué
que sur 29 % des familles.

**(b) « 100 % reçus sous 60 s » — tenu par aucun des deux chemins qui *garantissent* la livraison.**
Les deux sens reposent sur un balayage planifié, et **les deux balayages sont hors budget** : `*/10 * * * *`
côté site (10 min), `everyFiveMinutes()` côté CRM (5 min). Le seul chemin qui tient les 60 s est
l'émission immédiate du site — que le produit qualifie lui-même de **« confort »**, par opposition au
balayage qui est **« la garantie de livraison »**. **Un critère d'acceptation ne peut pas s'appuyer sur
le chemin que le produit désigne comme non garanti.**

| Sens | Cadence | Instrument | Verdict |
|---|---|---|---|
| **Site → CRM** | **deux chemins, et ce n'est pas le rapide qui garantit.** Chemin de fraîcheur : émission immédiate post-commit (`enqueue.ts` écrit l'outbox puis pousse dans `crmSyncQueue`). Chemin de garantie : **balayage `*/10 * * * *`, soit 10 minutes** (`src/server/queue/queues.ts:1029-1045`). Le produit le dit lui-même : « *l'émission immédiate est un **confort**, ce passage est la **garantie de livraison*** » | **Il en existe un, et un seul** : `activities.created_at − activities.occurred_at`. Les deux colonnes existent (`created_at TIMESTAMPTZ NOT NULL DEFAULT now()`, migration `2026_05_16_000007` ; `occurred_at TIMESTAMPTZ`, migration `2026_08_14_000004`) | ⚠️ **le budget n'est tenu que sur le chemin qui ne garantit rien.** Un premier essai réussi tient les 60 s ; **dès qu'il échoue, le rattrapage est à 10 min — dix fois le budget.** Et l'instrument est fragile : B13-006, `occurred_at` décalé de **+7 200 s** dans tout environnement sans `DB_TIMEZONE`, ce qui rendrait une latence **négative de 2 h**. La variable **est** posée en production (A05-008) et **absente de tous les `docker-compose*.yml`**. **Mesurable en production seulement, et à condition de vérifier `SHOW TimeZone` avant.** |
| **CRM → site** | `Schedule::command('crm:flush-outbound')->everyFiveMinutes()` | aucun | ❌ **hors budget par construction** : attente médiane 2 min 30, pire cas 5 min **avant le premier essai**. Et `->skip()` saute tous les passages tant que `CRM_OUTBOUND_ENABLED ≠ true` — absent en production (B14-013) : la latence y est **infinie** |

**(c) « 0 doublon après rejeu forcé » — mesurable, et déjà vert.**
Entrant : agent 13 §2.2 a rejoué la requête **octet pour octet** → `noop_idempotent`, une
seule activité, adossé à un index UNIQUE réellement présent en base. Sortant : `event_id`
UUID + idempotence P2002 côté site. **C'est la seule des trois clauses du critère 5 qui soit
mesurable *et* verte aujourd'hui.**

> **Ce qui manque pour rendre le critère 5 mesurable** : (i) un émetteur pour 10 familles sur
> 14 ; (ii) le passage de `everyFiveMinutes()` à une émission poussée, ou l'abaissement
> explicite du budget dans ce sens ; (iii) une horloge fiable — poser `DB_TIMEZONE` dans les
> fichiers de composition, pas seulement dans l'environnement de production. Et **l'aller-retour
> signé de bout en bout entre les deux dépôts en local n'a jamais été joué** (agent 14, §6
> point 2 et 5) — c'est le contrôle qui manque le plus à ce canal, et le harnais existe
> pourtant : `axionia/scripts/e2e-crm-sync/mock-crm.ts`.

### 8.2 Critère 18 — « Aucun point de capture n'est muet »

> « *Sur une même semaine, le nombre de réservations, soumissions, candidatures et
> inscriptions vus par la console est égal au nombre d'événements correspondants **reçus par
> le CRM** ; tout écart est expliqué ligne à ligne.* »

**Verdict : NON mesurable aujourd'hui — et il le resterait après la correction de E32-003.**

L'unique instrument existant est `collectReconciliation()` (`axionia/src/server/crm-sync/reconcile.ts`).
Il ne mesure pas l'objet du critère, pour **deux raisons distinctes** :

1. **Il ne filtre pas le statut.** Confirmé ligne à ligne à `reconcile.ts:311-317` sur
   `eb754332` : `prisma.crmSyncOutbox.findMany({ where: { subjectRef: { in: refs } } })`, puis
   `emitted = refs.length − missing.length`. **Un `gave_up` compte comme émis.** C'est
   E32-003, que je confirme par relecture indépendante.
2. **Il n'interroge jamais le CRM.** Il compare des **sources du site** à l'**outbox du
   site**. Le critère dit « **reçus par le CRM** ». **Aucun compteur n'est lu chez le CRM,
   dans aucun des deux dépôts.** *C'est la faute la plus lourde des deux, et elle survit à la
   correction de la première* : filtrer sur `status = "sent"` mesurerait « ce que le site
   croit avoir livré », pas « ce que le CRM a enregistré ».

**Et deux angles morts que le recoupement seul fait apparaître :**

3. **Une famille émettrice sur six n'est pas surveillée.** Le type
   `CrmSyncFamily = "submission" | "job_application" | "calendly_event" |
   "newsletter_subscriber" | "customer_review"` compte **5 familles** ; `PodcastRequest`
   **émet et n'y figure pas** (agent 13 §3.2 #11, confirmé par lecture du type). Un podcast
   perdu dans la fenêtre post-commit n'est jamais rattrapé, et jamais compté.
4. **Le critère nomme un point de capture qui n'a aucun émetteur.** « inscriptions ».
   Lu comme *inscription newsletter*, il est couvert (`newsletter_optin`). Lu comme
   *inscription à une session* — `Trainee` / `Enrollment`, la donnée la plus qualifiée du
   site, dont le consentement est **déjà horodaté et versionné** — **il n'existe ni
   `event_type`, ni `form_type`, ni émetteur** : joué par l'agent 13, `enrollment_created`
   → **422**. **Le CDC ne lève pas l'ambiguïté ; je la signale plutôt que de trancher à la
   place du dirigeant.**

**Enfin, le point que seul un recoupement des deux bouts permet de voir :**

> **Même un instrument parfait rendrait « écart zéro » aujourd'hui, alors que rien n'arrive.**
> B13-001 : **100 % des leads business atterrissent en `pending_match`, `subject_id = null`,
> zéro fiche créée**. Le CRM **reçoit** (HTTP 200) et ne **retient** rien d'exploitable. Le
> critère 18 est rédigé en **nombre d'événements reçus** ; il serait donc **vert sur un CRM
> qui n'a créé aucune fiche**. **Tel qu'il est écrit, le critère 18 ne peut pas faire rougir
> B13-001** — et c'est le défaut le plus grave du canal entrant. **C'est I49-007.**

**L'instrument qu'il faudrait** (et il n'existe pas) : un compteur lu **chez le CRM**,
ventilé par `IngestOutcome::status` — `created | updated | noop_idempotent | pending_match |
opted_out` — comparé famille par famille aux sources du site. Les cinq statuts existent déjà
et sont déjà renvoyés au site dans la réponse synchrone ; **le site n'en conserve que
`result.status`** (B14-012). L'information existe, elle est jetée au bord, et personne ne la
compte.

---

## 9. Constats

### [I49-001] Sur les 67 événements exigés par le §22.2, 13 sont émis, et le seul événement sortant qu'un humain peut déclencher est celui qui n'a aucun effet
- Sévérité      : S1 grave
- Domaine       : canal
- Référence     : CRM `main e8924b8` · site `main eb754332` · CDC v2 §22.2 (l. 672-706)
- Emplacement   : `backend/app/Crm/Ingest/SiteSyncEvent.php:38-49` (10 `EVENT_TYPES`) et `:66-70` (14 `FORM_TYPES`) · `backend/app/Crm/Outbound/ConsentOutboundRecorder.php:33` (3 `EVENT_TYPES`) · `backend/app/Http/Controllers/Api/JournalistsController.php:155` · `backend/app/Http/Controllers/Api/RgpdRequestsController.php:135`
- Constat       : le §22.2 exige 67 événements répartis en 14 familles (48 entrants, 19 sortants) ; 13 sont émis (11 entrants, 2 sortants), et des deux événements sortants produits, `consent_optout` n'est appelé par aucun écran de la console tandis qu'`erasure` — le seul geste humain réel — n'a aucun effet côté site.
- Preuve        : décompte complet et auditable dans `04_PREUVES/agent-49/05_decompte-22-2.txt` ; vocabulaires relevés dans `01_references-et-vocabulaires.txt` (`grep -n "EVENT_TYPES = " app/Crm/Outbound/ConsentOutboundRecorder.php` → `['consent_optout','consent_optin','erasure']` ; `grep -rn "ConsentOutboundRecorder" app/ | grep -v app/Crm/Outbound/` → **2 appelants**, l. 155 et l. 135). Le sous-total sortant de 19 est obtenu **indépendamment** de l'agent 14 et lui est identique.
- Témoin négatif: le même `grep -rn` sur `SiteSyncIngestService` trouve bien ses appelants hors de son répertoire (`SiteSyncController.php:6,37`) — le contrôle sait repérer un service injecté ; il constate qu'il n'y a que deux producteurs sortants.
- Impact        : le §22 s'ouvre sur « **tout ce qui arrive ou se passe dans l'une doit être connu de l'autre, automatiquement** ». 81 % de ce contrat n'a aucun tuyau. Les deux familles les plus lourdes — Livraison (22 événements) et Devis+Facturation (11) — sont à zéro alors que **le site détient toute la donnée** (sessions Qualiopi, conventions, attestations, `Invoice.payerSiret`, `Client.siren`). Et la moitié sortante est disjointe : ce qui marche est inatteignable, ce qui est atteignable ne marche pas.
- Reproduction  : lire le §22.2 du CDC ; comparer aux deux listes `EVENT_TYPES` ; compter les appelants du recorder.
- Correctif     : chantier, pas correctif. Ce constat est la **carte de départ** de l'étape « canal §22 ». Deux choses sont bonnes et réutilisables et doivent être dites comme telles : le **mécanisme** (signature, fenêtre, idempotence sur index UNIQUE réel, réessais, anti-boucle) et le **contrat strict entrant**. Ce qui manque est le vocabulaire et les points d'appel. Priorité mesurée par le rapport donnée-détenue / tuyau-absent : Livraison (22), Devis (6), Facturation (5).
- Statut        : ouvert

### [I49-002] §22.3 : deux interdits sur cinq sont une décision appliquée, deux sont sans objet, et le cinquième n'est appliqué que dans un sens
- Sévérité      : S2 défaut
- Domaine       : canal / conformité
- Référence     : CRM `main e8924b8` · site `main eb754332` · CDC v2 §22.3 (l. 708-713)
- Emplacement   : `axionia/src/server/crm-sync/types.ts:1-17` (l'interdiction écrite) · `backend/app/Crm/Ingest/SiteSyncEvent.php:82,84,116,262,282` (l'application) · `axionia/src/server/crm-sync/inbound.ts:143-172` (l'absence d'application dans l'autre sens)
- Constat       : les items 1 (preuves de consentement) et 2 (pièces jointes) sont écrits dans l'en-tête du contrat et appliqués par un schéma strict qui rejette toute clé inconnue en 422 ; les items 3 (enregistrements et transcriptions) et 4 (contenu des ressentis) portent sur des objets qui n'existent nulle part dans le CRM ; l'item 5 n'est appliqué que dans le sens site → CRM, le parseur du site ne refusant aucune clé inconnue.
- Preuve        : `04_PREUVES/agent-49/02_para-22-3-ce-qui-ne-traverse-pas.txt`. (A) l'en-tête de `types.ts` porte les deux interdictions mot pour mot. (B) `grep -n "assertOnlyKeys" app/Crm/Ingest/SiteSyncEvent.php` → l. 116, 262, 282 ; `CONSENT_KEYS = ['version','at','text_ref','vivier_at']`, `CANDIDATE_KEYS` contient `cv_ref` et aucun champ binaire. (C) `grep -nE "assertOnlyKeys|Object.keys|unknown_field|strict" src/server/crm-sync/inbound.ts` → **aucun résultat**. (D) `grep -rniE "recording|transcri" app/Models/ database/migrations/` → **aucun résultat** ; `grep -rniE "ressenti|sentiment" app/ database/migrations/` → **aucun résultat**.
- Témoin négatif: trois, un par affirmation. (C) le même fichier `inbound.ts` porte **9 refus explicites** (`return null` l. 144-167, 301) : le contrôle sait refuser, il ne refuse simplement pas l'inconnu. (D) le même `grep` trouve bien les notions **qui** existent — `ActivitesEtMotifs.php`, les 20 modèles d'`app/Models/`. (B) l'agent 13 a **joué** le refus : champ racine inconnu `session_id` → **422 `unknown_field`**.
- Impact        : le §22.3 est présenté comme une garantie ; il n'en est une que pour deux de ses cinq items. Pour les items 3 et 4, la garantie est **vide** : le CRM ne porte ni enregistrement ni transcription ni ressenti, et le jour où il en portera (§17.2, §20), **aucun mécanisme ne les empêchera de traverser** — le seul dispositif de discipline du canal, le schéma strict, n'existe que dans la direction qui n'en a pas besoin. Retournement de fond, à croiser avec E32-002 : le §22.3 a été écrit pour un produit où le CRM est le système riche ; aujourd'hui **c'est la console qui détient le contenu** (13 catégories) et le CRM qui n'en a aucun.
- Reproduction  : les quatre `grep` ci-dessus, dans l'ordre.
- Correctif     : (a) écrire côté site l'équivalent d'`assertOnlyKeys` — refuser en 422 toute clé hors des 7 attendues, exactement comme le CRM le fait à l'entrée. ≈ 1 h, et c'est le prérequis de tout élargissement du canal sortant. (b) Corriger le §22.3 du CDC pour distinguer ce qui est **interdit et appliqué** de ce qui est **prévu pour plus tard** — sinon une revue future lira une garantie là où il n'y a qu'une intention. ≈ 30 min.
- Statut        : ouvert

### [I49-003] §22.4 : zéro lien croisé sur douze, et le CRM ne porte aucun lien vers la console axionia — pas même le lien permanent qu'exige le §22.5
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : CRM `main e8924b8` · site `main eb754332` · CDC v2 §22.4 (l. 715-719) et §22.5
- Emplacement   : `frontend/src/` (dépôt CRM, aucune occurrence) · `axionia/src/components/admin/ui/AdminSidebarNav.tsx:771-793`
- Constat       : aucun des cinq liens exigés depuis la fiche CRM n'existe, aucun des six liens exigés depuis la console n'existe, et l'unique lien de tout le dispositif est un `<a>` codé en dur dans la console vers la racine du CRM.
- Preuve        : `04_PREUVES/agent-49/03_para-22-4-et-22-5.txt` — `grep -rniE "axion-ia\.(fr|com)|axionia|SITE_ADMIN_URL|VITE_SITE" src/` dans `Axion-CRM-Pro/frontend` → **aucun résultat** ; `grep -rniE "cr[ée]er un devis|voir le devis|voir la facture|voir la session|voir la r[ée]clamation|lancer la visio" src/` → **aucun résultat**. Côté site, `grep -rn "axion-crm-pro" src/lib src/components/admin` → **une seule ligne**, `AdminSidebarNav.tsx:773`.
- Témoin négatif: le même `grep` sur le même `frontend/src` trouve les **9 liens externes qui existent** (`target="_blank"` : sites d'entreprise, LinkedIn, un lien GitHub vers `_docs/PROSPECTION-PIPELINE.md`, un lien de réglages). Le contrôle sait trouver un `<a>` externe dans ce dépôt.
- Impact        : **confirme et aggrave E32-006.** L'agent 32 avait mesuré son bout — un lien vers la racine, libellé « Prospection ». Je mesure l'autre : **le CRM ignore que la console existe.** Le §22.5 exige « un lien direct et visible entre les deux dans la navigation » : il est absent du côté qui est censé devenir l'écran d'accueil de la journée. Ce qui **n'est pas** l'obstacle : la clé du lien profond est déjà calculée des deux côtés (`hashEmailForLookup(email)` = `Submission.contactEmailHash` = `person_key`) et la route `/console/personnes/$personKey` existe ; le blocage est **A05-001** — 0 contact sur 1 319 567 ne porte de `person_key`, donc tout lien profond ouvrirait une fiche vide.
- Reproduction  : les deux `grep` ci-dessus.
- Correctif     : par ordre de dépendance — (1) rapatrier le lien de la console dans `admin-nav.ts` et le renommer (E32-006, ≈ 0,5 j) ; (2) ajouter le lien permanent symétrique dans la barre du CRM (≈ 1 h) ; (3) les 11 liens profonds ne sont ouvrables qu'après A05-001 et après que les objets visés (devis, facture, session, réclamation) existent d'un côté ou de l'autre. **Ne pas chiffrer (3) avant l'arbitrage E32-002.**
- Statut        : ouvert

### [I49-004] §22.5 : deux magasins d'identité disjoints, aucun mécanisme de fédération — et l'exigence n'a jamais pu être éprouvée
- Sévérité      : S1 grave
- Domaine       : navigation / sécurité
- Référence     : CRM `main e8924b8` · site `main eb754332` · CDC v2 §22.5 (l. 721-725) et §29 critère 23
- Emplacement   : `backend/config/auth.php` (guard `web`, driver `session`, provider `users`) · `backend/config/sanctum.php` · `axionia/prisma/schema.prisma:1810-1822` (`model AdminUser`) · `axionia/package.json:157` (`"next-auth": "5.0.0-beta.32"`)
- Constat       : aucune fédération d'identité n'existe entre les deux consoles — ni OIDC, ni SAML, ni jeton d'échange, ni annuaire commun — et les deux produits maintiennent chacun leur propre table d'utilisateurs, leur propre hachage de mot de passe et leur propre 2FA.
- Preuve        : `04_PREUVES/agent-49/03_para-22-4-et-22-5.txt` — `grep -rniE "\boidc\b|openid|\bsaml\b|single.sign|\bsso\b|socialite|keycloak" app/ config/ routes/ composer.json` dans `backend/` → **aucun résultat**. `AdminUser` : `passwordHash` argon2id, `twoFactorSecret` otplib, `AdminRole`. CRM : Sanctum cookie SPA + `MagicLinkController` + `TwoFactorController`.
- Témoin négatif: deux. (a) Le même `grep` trouve bien les mécanismes d'authentification **qui existent** dans le CRM (`config/sanctum.php`, les deux contrôleurs, le guard `web`) — il n'est pas aveugle à l'authentification. (b) Côté site, la chaîne « SSO » **est** trouvée par `grep` : uniquement dans du **texte commercial** de pages ville (`src/content/villes/copy/montrouge.ts:63,109,115`). Le produit vend le SSO et ne l'implémente pas pour lui-même — le contrôle est prouvé capable de trouver la chaîne.
- Impact        : le §22.5 exige « une identification donne accès aux deux, avec passage de l'une à l'autre sans ressaisie ». Il n'y a pas de commencement d'implémentation. **Et le point qui décide** : croisé avec **A-012** (1 compte, 0 session, 0 jeton depuis le 2026-05-17), l'exigence **n'a jamais pu être éprouvée**, et le **critère 23 du §29 n'est pas seulement à 0 % — il est injouable** : son protocole demande à une personne extérieure d'ouvrir le bon outil dix fois sur dix, et **l'un des deux outils ne s'ouvre pas**. Corollaire : la règle « la console axionia n'est jamais l'écran d'accueil de la journée » (§22.6) est violée **100 % des jours** depuis le 2026-05-17, non par un défaut de navigation mais parce que la console axionia est le seul écran ouvrable.
- Reproduction  : les deux `grep` ci-dessus ; puis lire A-012.
- Correctif     : **ne rien chiffrer avant A-012.** Toute conception de connexion unique faite avant qu'un être humain ait ouvert le CRM une première fois serait de la conception à l'aveugle. L'ordre est : (1) A-012 + A07-001 (rendre le CRM ouvrable) ; (2) rejouer le critère 23 pour savoir si l'orientation est un vrai problème ou un problème supposé ; (3) alors seulement décider entre une connexion unique et deux connexions assumées avec un lien permanent — le §22.5 dit lui-même que le CRM doit rester **détachable**, ce qu'une connexion unique rendrait plus difficile.
- Statut        : ouvert

### [I49-005] §22.6 : la carte « où je vais pour quoi » n'existe que dans le cahier des charges, et ses cinq règles d'application sont à zéro
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : CRM `main e8924b8` · site `main eb754332` · CDC v2 §22.6 (l. 727-741)
- Emplacement   : `frontend/src/components/OnboardingTour.tsx:15-60` (le seul dispositif d'orientation existant) · `axionia/src/lib/admin-nav.ts` · `axionia/src/components/admin/ui/AdminSidebarNav.tsx:771-793`
- Constat       : aucune surface des deux produits ne porte la carte du §22.6 ni aucun équivalent ; le seul dispositif d'orientation, la visite guidée du CRM, compte six étapes qui portent toutes sur les écrans du CRM lui-même et ne nomment jamais la console axionia.
- Preuve        : `04_PREUVES/agent-49/04_para-22-6-22-7-criteres.txt` — `grep -rniE "o[uù] je vais|quel outil|console axionia" src/` dans `Axion-CRM-Pro/frontend` → **aucun résultat** ; le même motif sur `axionia/src/lib` et `axionia/src/components/admin` → **aucun résultat**. Les six étapes de `OnboardingTour.tsx` relevées une par une : bienvenue, barre latérale, recherche ⌘K, entreprises, tableau de bord, mode sombre.
- Témoin négatif: le même `grep -rn "axion-crm-pro" src/lib src/components/admin` côté site **trouve** l'unique lien existant (`AdminSidebarNav.tsx:773`) : le contrôle n'est pas aveugle aux références croisées entre les deux produits, il constate qu'il n'y en a qu'une, et qu'elle n'est pas une carte.
- Impact        : les cinq règles d'application du §22.6 sont à **0 sur 5** — le groupe « Contacts » de la console compte toujours 12 entrées et 17 écrans vivants ; les rendez-vous ont toujours un écran (`/contacts/appels` avec sa vue calendrier : seul le **nom** `rendez-vous` a été retiré le 2026-07-29, pas la fonction) ; il y a 1 lien permanent sur 2, mal libellé ; aucun « Voir la fiche » ; et la console axionia est l'écran d'accueil de fait. L'écart de **17/17** mesuré par l'agent 32 sur le critère 23 se relit donc ainsi : **ce n'est pas un retard d'exécution, c'est que la carte n'a jamais quitté le document.**
- Reproduction  : les trois `grep` ci-dessus ; lire les six étapes d'`OnboardingTour.tsx`.
- Correctif     : la carte n'a pas à être une page — elle doit être **incarnée** par la navigation, ce qui est précisément le contenu des paliers 2 et 3 de l'agent 32. À coût faible et à valeur immédiate : ajouter le lien permanent manquant côté CRM (I49-003) et renommer celui de la console. **Ne pas écrire une page d'aide** : une carte qu'il faut aller lire est l'aveu que la navigation ne se comprend pas seule (critère 24).
- Statut        : ouvert

### [I49-006] §22.7 : le seul tableau de bord du canal qui existe n'annonce pas le silence de l'autre sens, et affiche aujourd'hui « aucun événement reçu du CRM » sans le signaler
- Sévérité      : S1 grave
- Domaine       : canal
- Référence     : site `main eb754332` · CRM `main e8924b8` · CDC v2 §22.7 (l. 740-741)
- Emplacement   : `axionia/src/server/crm-sync/health.ts:63,147-150` · `axionia/src/server/crm-sync/alerts.ts:36` · `axionia/src/app/[locale]/(admin)/[adminPrefix]/synchro-crm/page.tsx:227-235` · côté CRM `frontend/src/features/observability/ObservabilityPage.tsx` et `backend/app/Http/Controllers/Api/ObservabilityController.php:35,85-88`
- Constat       : `inbound.lastAt` — la date du dernier événement reçu du CRM — est calculée, transportée et affichée, mais n'est comparée à aucun seuil, et aucun des cinq motifs de `CRM_SYNC_ALERT` ne porte sur le silence de l'entrant ; côté CRM il n'existe aucun tableau de bord du canal du tout.
- Preuve        : `04_PREUVES/agent-49/04_para-22-6-22-7-criteres.txt` — `grep -rnE "inbound" src/server/crm-sync/health.ts src/server/crm-sync/alerts.ts` → 5 lignes, toutes dans `health.ts`, **aucune dans `alerts.ts`**. Côté CRM, rejoué sur ma référence : `git diff --stat 1145473 e8924b8 -- frontend/src ...ObservabilityController.php` → **vide** ; `grep -rn "outbound" frontend/src` → **aucun résultat**, alors que `ObservabilityController.php:35` renvoie bien `'outbound' => $this->outboundBacklog()`.
- Témoin négatif: deux. (a) Le seuil **existe pour l'autre sens** : `CRM_SYNC_BACKLOG_THRESHOLD = 50` (`alerts.ts:36`), importé et affiché (`health.ts:16,139`) — le dépôt sait poser un seuil, l'exposer et l'afficher ; il ne l'a pas fait sur l'entrant. (b) Côté CRM, `grep -rn "observability" frontend/src` rend 5 lignes (route, sidebar, page) : le contrôle sait chercher dans ce répertoire.
- Impact        : le §22.7 exige le tableau de bord « dans **chaque** console » ; il existe dans **une sur deux**, et sa dernière phrase — « **si l'un des deux n'a rien reçu de l'autre depuis un délai anormal, il le dit** » — n'est tenue dans **aucune des deux**. Ce n'est pas théorique : B14-013 mesure qu'en production `CRM_OUTBOUND_ENABLED` et `SITE_CRM_WEBHOOK_URL` sont **absents**, donc le CRM n'a jamais pu émettre. Le seul écran de canal du dispositif affiche donc « Dernier reçu : — » mois après mois **sans jamais le signaler**. Le §22.7 a été écrit exactement pour cette situation ; il ne la couvre pas. À rapprocher de B14-004 (aucune alerte dans le sens CRM → site) et de B14-006 (rien ne détecte l'arrêt de `crm:flush-outbound`) : **les trois dispositifs de surveillance du canal ont chacun un angle mort, et les trois angles morts se recouvrent exactement sur le sens CRM → site.**
- Reproduction  : les trois `grep` ci-dessus.
- Correctif     : (a) côté site, une constante `CRM_INBOUND_SILENCE_HOURS` comparée à `inbound.lastAt`, un ton d'alerte sur la carte « Consentements reçus du CRM », et un sixième motif `inbound_silence` dans `CRM_SYNC_ALERT` — ≈ 2 h, tout le mécanisme existe déjà pour l'outbox. (b) Côté CRM, B14-003 (ajouter `outbound` et `site_sync` au type et deux `KpiCard`) ≈ 2 h, puis un écran de canal avec rejeu ≈ 2-3 j. **(a) est à faire même si le CRM n'émet toujours pas — c'est précisément ce silence-là qu'il faut rendre visible.**
- Statut        : ouvert

### [I49-007] Critère 18 : l'instrument de parité ne lit jamais le CRM, ne couvre que 5 des 6 familles émettrices, et resterait vert alors qu'aucune fiche n'est créée
- Sévérité      : S1 grave
- Domaine       : canal
- Référence     : site `main eb754332` · CRM `main e8924b8` · CDC v2 §29 critère 18 (l. 971)
- Emplacement   : `axionia/src/server/crm-sync/reconcile.ts:72-73` (les 5 familles) et `:311-317` (la requête) · `backend/app/Crm/Ingest/IngestOutcome.php` (les 5 statuts jamais comptés)
- Constat       : `collectReconciliation()` compare les enregistrements source du site à l'outbox du site sans filtrer le statut, n'interroge à aucun moment le CRM, et son périmètre de cinq familles omet `PodcastRequest`, qui émet.
- Preuve        : `04_PREUVES/agent-49/04_para-22-6-22-7-criteres.txt` — `sed -n '311,318p' src/server/crm-sync/reconcile.ts` → `prisma.crmSyncOutbox.findMany({ where: { subjectRef: { in: refs } } })`, puis `emitted = refs.length − missing.length` : **aucun filtre de statut, aucun appel au CRM**. `sed -n '72,73p'` → `export type CrmSyncFamily = "submission" | "job_application" | "calendly_event" | "newsletter_subscriber" | "customer_review"` — **5 valeurs**, pas de podcast.
- Témoin négatif: le module **sait** distinguer les statuts — `emit.ts` renvoie `sent | failed | gave_up | skipped` et `health.ts` les expose et les affiche. L'information existe, la réconciliation ne l'utilise pas. Et l'en-tête du type `CrmSyncFamily` documente lui-même qu'il a **déjà été étendu une fois** (« Calendly, newsletter et avis rejoignent la comparaison ») : le fichier sait qu'il peut manquer une famille, et il en manque encore une.
- Impact        : **approfondit E32-003 sur deux points que l'agent 32 ne pouvait pas voir depuis le site seul.** (1) Corriger le filtre de statut **ne suffirait pas** : on mesurerait alors « ce que le site croit avoir livré », pas « ce que le CRM a **reçu** », qui est le mot du critère. (2) Surtout — croisé avec **B13-001** — **même un instrument parfait rendrait « écart zéro » aujourd'hui alors que rien n'arrive** : 100 % des leads business atterrissent en `pending_match`, `subject_id = null`, **zéro fiche créée**. Le critère 18 est rédigé en nombre d'**événements reçus** ; il serait donc **vert sur un CRM qui n'a créé aucune fiche**. **Tel qu'il est écrit, le critère 18 ne peut pas faire rougir le défaut le plus grave du canal entrant.** À quoi s'ajoute une ambiguïté du CDC : le critère nomme « inscriptions » ; lu comme *inscription à une session* (`Trainee`/`Enrollment`), le point de capture **n'a aucun émetteur** — `enrollment_created` → **422**, joué par l'agent 13.
- Reproduction  : `sed -n '305,320p' src/server/crm-sync/reconcile.ts` ; `sed -n '72,73p'` pour le type ; comparer au libellé du critère 18.
- Correctif     : (a) filtrer sur `status = "sent"` **et** `crmResult ∈ {created, updated, noop_idempotent}` — E32-003, ≈ 0,5 j ; (b) **ajouter un compteur lu chez le CRM**, ventilé par les 5 valeurs d'`IngestOutcome` — le CRM les renvoie déjà dans sa réponse synchrone, c'est `emit.ts` qui n'en conserve que `result.status` (B14-012) : ≈ 0,5 j de chaque côté ; (c) ajouter `podcast_request` au type `CrmSyncFamily` ≈ 1 h ; (d) **STOP & ASK Will** sur la rédaction du critère 18 : « reçus » doit-il vouloir dire « acquittés » ou « ayant produit une fiche » ? Tant que ce mot n'est pas tranché, le critère est vert sur un produit vide.
- Statut        : ouvert

### [I49-008] Critère 5 : aucun des deux sens ne tient les 60 s sur son chemin GARANTI — 10 min côté site, 5 min côté CRM — et le seul instrument de latence est faussé de +7 200 s hors production
- Sévérité      : S2 défaut
- Domaine       : canal / performance
- Référence     : CRM `main e8924b8` · site `main eb754332` · CDC v2 §29 critère 5 (l. 958)
- Emplacement   : `backend/routes/console.php:166-170` (`everyFiveMinutes()`) · `axionia/src/server/crm-sync/enqueue.ts:9-18` (émission immédiate) · `axionia/src/server/queue/queues.ts:1029-1045` (balayage `*/10`, le chemin qui garantit) · `backend/database/migrations/2026_05_16_000007_create_phase2_scaffold_schema.php:138` (`created_at`) et `2026_08_14_000004_crm_socle_tags_optout_timeline.php:109` (`occurred_at`)
- Constat       : les deux sens reposent sur un balayage planifié pour garantir la livraison — `*/10 * * * *` côté site, `everyFiveMinutes()` côté CRM — dont aucun ne tient le budget de 60 s ; l'émission immédiate du site, seule à le tenir, est qualifiée de « confort » par le produit lui-même ; et le seul instrument capable de mesurer la latence entrante, `activities.created_at − activities.occurred_at`, est décalé de +7 200 s dans tout environnement où `DB_TIMEZONE` n'est pas posée.
- Preuve        : `04_PREUVES/agent-49/04_para-22-6-22-7-criteres.txt` — `sed -n '160,172p' backend/routes/console.php` → `Schedule::command('crm:flush-outbound')->everyFiveMinutes()->withoutOverlapping()->onOneServer()->skip(...)` ; `sed -n '9,18p' src/server/crm-sync/enqueue.ts` → « *l'événement est d'abord POSÉ en base (statut `pending`), et l'émission n'est qu'une optimisation de fraîcheur* », avec `crmSyncQueue` poussée dans la foulée. **Cadence du rattrapage côté site, mesurée par moi et non reprise d'un pair** : `sed -n '1029,1045p' src/server/queue/queues.ts` → `repeat: { pattern: "*/10 * * * *" }`, avec le commentaire du produit « *l'émission immédiate est un **confort**, ce passage est la **garantie de livraison*** » ; la réconciliation est à `"30 4 * * *"`. Les deux colonnes d'horodatage sont relevées à leur migration. Le décalage de +7 200 s est **mesuré par l'agent 13** (B13-006), qui l'a joué sur `occurred_at` **et** sur `consent_at`.
- Témoin négatif: deux. (a) L'agent 13 a joint à sa mesure une sonde de contrôle sur deux instants égaux → **0,000000 s** d'écart : l'instrument sait rendre zéro quand il n'y a rien, et rend bien +7 200 s quand il y a le défaut. (b) Sur la cadence, le même `sed` sur le même fichier trouve bien les **autres** cadences déclarées (`"30 4 * * *"` pour la réconciliation, `vivierCronsQueue` à 05:00 UTC) — le contrôle sait lire une cadence BullMQ, et il n'en trouve aucune sous les 10 minutes.
- Impact        : le critère 5 exige « 20 événements de **chaque famille** du §22.2 » — or **4 familles sur 14 seulement ont un émetteur** (I49-001), le protocole ne peut donc pas être joué en entier. Sur les 60 s : **aucun des deux sens ne les tient sur son chemin garanti** — 10 min côté site, et **structurellement hors budget dans le sens sortant** (attente médiane 2 min 30, pire cas 5 min avant le premier essai ; et `->skip()` saute tous les passages tant que `CRM_OUTBOUND_ENABLED ≠ true`, absent en production → latence **infinie**, B14-013). Seule la troisième clause — « 0 doublon après rejeu forcé » — est mesurable **et verte** aujourd'hui, jouée par l'agent 13 (rejeu octet pour octet → `noop_idempotent`, index UNIQUE réel) et par l'agent 14 (`event_id` + P2002).
- Reproduction  : lire les deux ordonnancements ; comparer au libellé du critère 5.
- Correctif     : (a) poser `DB_TIMEZONE` **dans les fichiers de composition**, pas seulement dans l'environnement de production — sans quoi tout chronométrage joué en préproduction est faux (≈ 30 min, et c'est le prérequis de toute mesure) ; (b) décider explicitement, par ADR, si les **deux** chemins garantis doivent passer sous les 60 s ou si leur budget est différent — la question se pose à l'identique pour le balayage `*/10` du site et pour l'`everyFiveMinutes()` du CRM — le §22.1 dit « automatique », il ne dit pas « en moins d'une minute », c'est le §29 qui l'impose (≈ 0,5 j pour l'émission poussée) ; (c) **jouer enfin l'aller-retour signé de bout en bout entre les deux dépôts en local** — le harnais existe (`axionia/scripts/e2e-crm-sync/mock-crm.ts`) et c'est le contrôle qui manque le plus à ce canal (agent 14 §6, points 2 et 5).
- Statut        : ouvert

### [I49-009] Le §22.2 annonce en gras comme « existants » trois éléments qui n'existent pas
- Sévérité      : S2 défaut
- Domaine       : conformité (exactitude du mandat)
- Référence     : CDC v2 §22.2 (l. 672-706) · CRM `main e8924b8`
- Emplacement   : CDC §22.2, tableau 1 ligne « Facturation » et tableau 2 lignes « Consentement » et « Identité » · `backend/app/Crm/Ingest/SiteSyncEvent.php:38-49` · `backend/app/Crm/Outbound/ConsentOutboundRecorder.php:33`
- Constat       : le §22.2 met en gras ce qui est « existant » ; trois de ces mentions sont fausses — « **numéro client attribué** » n'a aucune valeur de contrat ni aucun émetteur, « **réinscription** » (`consent_optin`) n'a aucun producteur et est explicitement ignorée par le site, et « **fiche créée ou rapprochée** » n'a aucun émetteur alors que la route de rapprochement existe et mute.
- Preuve        : `01_references-et-vocabulaires.txt` — `SiteSyncEvent::EVENT_TYPES` (10 valeurs) ne contient rien qui corresponde à l'attribution d'un numéro client ; `ConsentOutboundRecorder::EVENT_TYPES` contient bien `consent_optin`, et `grep -rn "ConsentOutboundRecorder" app/ | grep -v app/Crm/Outbound/` rend **2 appelants**, aucun pour `consent_optin` ; `POST /crm/arbitrage/{id}/attach` mute sans appeler le recorder.
- Témoin négatif: le même relevé confirme que les **autres** mentions en gras sont **vraies** — les 6 événements en gras de la famille Capture existent tous, les 4 de Rendez-vous aussi, et « lead capturé » du Chatbot existe (pour une branche sur deux). Le contrôle ne conclut donc pas que « le gras du CDC ment » : il isole trois lignes précises.
- Impact        : le gras du §22.2 est la seule indication dont dispose le dirigeant pour distinguer « à construire » de « déjà là ». Trois lignes fausses gonflent l'existant de trois événements et faussent tout chiffrage du chantier canal. « **fiche créée ou rapprochée** » est la plus coûteuse des trois : le §22.2 en fait le mécanisme par lequel « la console apprend quel identifiant porter », donc le **prérequis du §22.4** (liens croisés) et du critère 4 ; le croire existant, c'est croire le §22.4 à portée de main.
- Reproduction  : lire le §22.2 ; comparer aux deux listes `EVENT_TYPES`.
- Correctif     : dégraisser les trois mentions dans le CDC. ≈ 15 min. **À faire avant tout chiffrage de l'étape canal.**
- Statut        : ouvert

---

## 10. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Aucun écran ouvert pour de vrai** — ni le `/synchro-crm` du site, ni l'`/admin/observability`
   du CRM. La consigne interdit l'atelier local du CRM (A-009), la console axionia de
   production demande une authentification que je n'ai pas, et **A-012 dit que la console du
   CRM ne s'ouvre pas**. Tout mon §7 est une lecture de code avec témoins négatifs, pas une
   observation. La règle 4 du dossier (« le geste réel avant l'instrumentation ») n'est donc
   **pas** tenue par ce rapport, et je le déclare plutôt que de le masquer.
2. **L'état d'exécution en production**, dans les deux sens. `CRM_OUTBOUND_ENABLED` absent :
   preuve de pair (agent 8, `docker inspect`), reprise par l'agent 14 (B14-013), **que je n'ai
   pas rejouée** — pas d'accès SSH depuis ce poste. `CRM_SYNC_ENABLED` côté site : aucune
   surface publique ne l'expose (agent 32 §7.1). Mes verdicts sur le canal portent donc sur
   **le code**, pas sur son exécution.
3. **Le contenu réel de `crm_inbound_events` en production côté site.** Je n'ai pas interrogé
   la base. Quand j'écris que l'écran afficherait « Dernier reçu : — », c'est une **déduction**
   de B14-013 (le CRM n'a jamais pu émettre), pas une lecture. **I49-006 ne dépend pas de
   cette déduction** : son constat — l'absence de seuil de silence — est mesuré dans le code.
4. **Aucun événement émis, aucun aller-retour joué.** Interdit par ma consigne, et de toute
   façon impossible sans le secret partagé. **Personne dans cet audit n'a joué l'aller-retour
   signé de bout en bout entre les deux dépôts** — ni l'agent 13 (qui a joué l'entrée du CRM
   contre un harnais local), ni l'agent 14 (qui a joué une panne contre une destination
   injoignable), ni moi. **C'est le contrôle qui manque le plus à ce canal**, et le harnais
   existe : `axionia/scripts/e2e-crm-sync/mock-crm.ts`.
5. **L'identité visuelle partagée du §22.5.** Hors de mon périmètre — voir l'agent 27
   (`agent-27_design-system.md`). Je laisse la case vide plutôt que de l'inventer.
6. **L'ambiguïté du mot « inscriptions » au critère 18.** Je l'ai **signalée**, je ne l'ai pas
   tranchée : c'est une décision du dirigeant sur la rédaction du CDC, pas une mesure. Idem
   pour le mot « reçus » du même critère (acquitté, ou ayant produit une fiche ?) — je pose la
   question dans le correctif de I49-007, je ne réponds pas à sa place.
7. **La recherche du §22.6 sur la totalité du dépôt site.** Ma recherche récursive sur tout
   `axionia/src/` a été **tuée à 120 s**. Les deux `grep` ciblés sur `src/lib` et
   `src/components/admin` — où vit la navigation — ont abouti et rendent zéro ; la sortie
   partielle de la recherche large va dans le même sens (archivée). **Le verdict de I49-005 est
   donc concluant sur la navigation de la console, et partiel sur le reste du dépôt site.**
8. **Les volumes.** Je ne dis nulle part **combien** d'événements sont concernés par chaque
   famille : il aurait fallu la base de production. Je dis quelles **catégories** le sont.
9. **Le §22.1 « document commun aux deux dépôts avec un numéro de version ».** J'ai vérifié
   son absence dans `spec/` côté CRM (agent 14) et je constate que les deux fichiers de contrat
   (`SiteSyncEvent.php` et `types.ts`) se déclarent mutuellement « miroir exact » et portent
   tous deux `SCHEMA_VERSION = 1`. **Je n'ai pas cherché ce document ailleurs que dans les deux
   dépôts** — piège 14 du dossier : une pièce peut vivre hors du dépôt.
