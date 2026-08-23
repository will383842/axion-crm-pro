# SESSION D'EXPLICATION — journal tenu au fur et à mesure

> **À quoi sert ce fichier.** Cette session-ci n'écrit pas de correctif : elle
> **explique à Will les constats de l'audit, thème par thème, en clair**. C'est
> une session de lecture, pas de chantier.
>
> Il est réécrit **après chaque thème traité**, à la demande de Will
> (2026-08-23) : *« sauvegarde au fur et à mesure cette conversation au cas où
> Claude Code fermerait inopinément, pour savoir exactement où on en était et
> pour reprendre sans perdre de temps et sans rien perdre. »*
>
> État du chantier, lui : `OU-ON-EN-EST.md`. Registre : `FILE-DE-TRAVAIL.md`.

---

## 0. REPRENDRE CETTE SESSION EN UNE PHRASE

**Les 15 thèmes S2 ont tous été expliqués une première fois, en survol.
La suite attend le choix de Will : quel thème ouvrir constat par constat,
avec les citations `fichier:ligne`.**

Pour reprendre sans rien relire d'autre :

```bash
cd C:/Users/willi/Documents/Projets/Axion-CRM-Pro/_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-35
python extraire-s2-par-theme.py          # vérifie que le rangement tient encore
cat S2-PAR-THEME.md                      # les 168 constats, rangés, avec fichiers
```

---

## 1. CE QUI A ÉTÉ DEMANDÉ, ET DANS QUEL ORDRE

| # | Demande de Will | État |
|---:|---|---|
| 1 | *« Continue de m'expliquer les constats S2 de l'audit CRM, thème par thème, en clair. »* | ✅ **fait** — survol des 15 thèmes, ci-dessous §3 |
| 2 | *« Sauvegarde au fur et à mesure cette conversation… »* | ✅ **fait** — ce fichier, réécrit à chaque thème |
| 3 | Descendre dans un thème, constat par constat | ⏳ **en attente du choix de Will** |

⚠️ Le mot **« Continue »** de la demande n° 1 indique qu'une session
précédente avait déjà expliqué les S0 et/ou les S1. **Cette conversation-ci n'en
porte aucune trace** et n'a rien reconstruit de mémoire : elle est repartie du
registre. Si un journal d'explication S0/S1 existe ailleurs, il n'a pas été
retrouvé — à demander à Will plutôt qu'à deviner.

---

## 2. CE QUI A ÉTÉ MESURÉ (et non recopié)

Le chiffre de départ n'a pas été pris dans un document : il a été **recompté**
depuis le tableau du §2 de `FILE-DE-TRAVAIL.md`.

| | |
|---|---|
| S2 au total | **256** lignes |
| S2 fermés | **88** (51 « FERME vague 2 » + 36 « VERIFIE FERME » + 1 documentaire) |
| **S2 ouverts** | **168** — le chiffre du tableau §1.2, retrouvé mécaniquement |
| Par dépôt | CRM 128 · INFRA 26 · SITE 21 · DOC 5 *(approx., non regardé de près)* |
| Par nature | `correctif` majoritaire, puis `doc`, `conception`, 2 `resteWill` |

🔴 **Piège rencontré et payé — à ne pas repayer.** Un premier filtre naïf
(*« l'état ne commence pas par FERME »*) donnait **204** ouverts au lieu de 168 :
il ne reconnaissait pas les 36 cellules commençant par **`VERIFIE FERME`**, ni
une cellule préfixée d'un émoji 🔴. Le filtre juste est
`"FERME" not in sans_accents(etat)[:40]`. **Un compte d'ouverts trop élevé est
aussi faux qu'un compte trop bas** — il aurait envoyé 36 agents redécouvrir un
travail fait, exactement le motif du §1.1 bis du registre.

### Le rangement est rejouable, et sa garde rougit

`extraire-s2-par-theme.py` porte le rangement des 168 en 15 thèmes et **sort en 1**
si un constat est rangé deux fois, dans aucun thème, ou si le compte de 168 a bougé.

**Éprouvé pour de vrai le 2026-08-23** : en retirant `E32-010` du thème 15, le
script sort bien en **1** avec `ECHEC : Ouverts mais rangés dans aucun thème :
E32-010`. Ce n'est donc pas une garde décorative — contrairement à `P5-35-001`
et `G42-013`, qui sont dans la file précisément pour cette raison.

---

## 3. LES 15 THÈMES — le survol déjà donné à Will

> Détail complet, avec `fichier:ligne` pour chacun des 168 : `S2-PAR-THEME.md`.
> Ci-dessous, seulement **ce qui a été dit de vive voix** et qui serait perdu.

### Bloc I — ce qui peut faire sortir une donnée

**1. Cloisonnement des espaces (13).** Trois dispositifs censés garantir qu'un
client ne voit pas l'autre — RLS Postgres, ceinture applicative, garde de test —
et **les trois sont troués à un endroit différent**. `B11-007` : onze tables à
données personnelles n'ont ni colonne d'espace ni policy. `B10-006` : la ceinture
est sur 4 modèles sur 15. `A06-004`/`A08-004` : la garde **exclut le journal
d'audit RGPD**, exclusion née d'un scan aveugle et promue en décision.
`B11-010` est le plus vicieux : **sur l'atelier local les deux dispositifs sont
désarmés**, donc tout code non cloisonné y passe au vert — *c'est ce qui explique
pourquoi les autres ne se voyaient pas.*

**2. Sécurité du serveur de production (10).** Défauts d'exploitation, mesurés.
`F37-008` : `laravel.log` à **272 Mo** avec e-mails, cookies de session, IP+UA
via Telescope, fichier en `rwxrwxrwx`, jamais tourné. `F37-009`/`F40-013` :
webroot en **1777**, `storage` en **777**. `G42-006` : **4,17 Mo de source
TypeScript** téléchargeables sans authentification. `F37-010` : mot de passe
Redis de **4 caractères minuscules** (marqué `resteWill` — c'est une rotation).
`F37-007` : l'IP `46.62.248.239:443` sert l'application en contournement de
Cloudflare. `F37-004` : la prod n'émet ni CSP ni COOP/CORP **alors que l'atelier
local, lui, les émet**.

**3. Accès, permissions, 2FA (10).** `D22-006` domine : **l'interface ne consulte
jamais les permissions**, 33 écrans sur 37 offrent leurs actions à tout compte
authentifié. `P5-35-002` : la 2FA reste **contournable par jeton d'API**.
`B12-008` : **88 routes sur 117** sans limitation de débit, limiteur global
attaché à rien. `F36-009` : les 11 policies **sans aucun test** — on peut les
réécrire en refus total, la suite reste verte.

**4. RGPD et journal d'audit (9).** `A05-002` : `first_info_at` (article 14),
**deux colonnes livrées, aucun écrivain, 0 ligne** — l'obligation est modélisée,
jamais tenue. `E33-007` : le lead chatbot part **en clair vers Telegram**.
`E31-010` : une opposition indéchiffrable est **perdue en silence**, et la route
répond « ok ». `B16-011` : l'écran « Journaux d'audit » affiche **cinq colonnes
qui n'existent nulle part**.

**5. Collecte de masse (8).** Plus juridique que technique. `C19-004` : **aucun
collecteur ne lit le `robots.txt`**. `C19-005` : le seul délai de politesse est
dans une **méthode morte**, et la doc affirme le contraire. `C19-006` : les
collecteurs **se déguisent en navigateur**, sans qu'aucune décision écrite ne
l'assume. `C18-018` : les 13 scrapers **ni testés ni déployés**.

### Bloc II — ce qui rend le produit faux

**6. Le pont site ↔ CRM (17).** Le plus fourni, et le plus cohérent :
**l'instrument qui devait prouver que rien ne se perd est lui-même faux.**
`E31-005`/`E33-004` : la réconciliation compare **5 familles quand le site en
émet 6**. `E31-007`/`E33-005` : **faux positifs garantis** sur les candidatures
commerciales. `A06-012` : une livraison abandonnée **compte comme reçue**.
`B14-011` : **cinq familles d'événements sur six n'ont aucun émetteur**.
`I49-008` : le critère des 60 s tenu **dans aucun sens**, et le seul instrument
de latence est faussé de **+7 200 s** hors production.

**7. Qualité des données en base (8).** Mesuré sur la vraie base. `C21-007` :
**64 523** doublons par nom+ville, **162 025** par téléphone. `C21-005` : le
palier « complete » atteint par **aucune** des 4 295 349 fiches, 80,80 % à zéro.
`A05-004` : le cycle de vie **n'a jamais changé d'état**. `A05-006` : le vivier
candidats **vide cinq jours après l'ouverture du flux**, et rien ne le signale.

**8. Navigation et repères (25).** Le plus gros paquet : **la cible des plans et
ce que fait la console ne se recouvrent pas**, et les deux consoles se marchent
dessus. `D23-003` : « Contacts » liste des **entreprises**. `E32-005` : **17
écrans de relation sur 17** restent atteignables côté site. `I49-003` : **zéro
lien croisé sur douze**. `I48-005` : quinze objets du code devront être
**DÉFAITS, pas complétés**. `I48-008` : le seul endroit où le produit **dépasse**
son périmètre (lot L7 explicitement exclu). `E34-008` : piège de renommage —
viser le « Booking » de la console **détruirait les inscriptions aux sessions**.

**9. Comportement des écrans (10).** `D25-009` : `/users` à 10 000 lignes
construit **160 025 nœuds et 18 Mo de HTML**, et **n'aboutit plus à 100 000**.
`D25-005` : un 500 et un 403 s'affichent en « introuvable · 404 » — *on croit la
donnée supprimée alors que le serveur est en panne.* `D24-005`/`D25-007` : rien
n'est dans l'URL. `D26-007` : l'assistant de campagne **n'a aucun `<form>`**.

**10. Design system, sombre, a11y, mobile (17).** `D27-005` : **aucun composant
de tableau n'existe**, le même en-tête de 210 caractères copié dans 8 fichiers.
`D28-011` : le sombre est **le mode le plus contrasté à l'envers** — 76 défauts,
le ⌘K à **1,36:1**. `D30-003` : **461 cibles tactiles sur 473** sous 44 px.
`D29-006` : pluriels par concaténation — **traduction impossible par
construction**.

**11. Performance (8).** `G43-003` : **p95 à 6,2 s** à dix sessions, index posé.
`G42-008` : **0 `React.memo`, 2 `useCallback`** dans tout le produit.
`H45-009` : la recette locale documentée est **~115× plus lente** qu'ailleurs —
*c'est la cause de fond : c'est pourquoi personne ne joue la suite.* Même famille
que le sixième verrou levé le 23/08 (le montage Windows).

### Bloc III — ce qui fait qu'on ne le sait pas

**12. Le filet de tests et la CI (20).** Le thème qui **conditionne tous les
autres**. `A08-005` : **3 des 6 jobs de CI ne bloquent aucune fusion**, dont les
deux gardes nées des deux pires incidents du produit. `F38-006` : le job
Lighthouse **rend `success` depuis toujours sans avoir produit aucun score**.
`F38-013` : `continue-on-error: true` non commenté **dans le fichier même qui
explique pourquoi on les avait retirés**. `H46-001` : la baseline PHPStan gèle
**145 messages**, dont 20 sur des chemins de sécurité. `H44-006` : **6 écrans
sur 37** montés par un test. `H44-004`/`B11-005` : aucune isolation, tout épinglé
sur `axion_crm_test` où `RefreshDatabase` fait `DROP TABLE … CASCADE` — *piège
n° 6 du dossier, déjà payé deux fois.*

**13. Sauvegardes et reprise (4).** Petit thème, exigence n° 13 du mandat, à
zéro. `F39-002` : la surveillance vérifie qu'un fichier existe, est récent et est
gros — **jamais qu'il est restaurable**. `F39-003` : l'exercice n'est **déclenché
par rien**. `F39-012` : et quand il partira, il **rougira à tort** le premier
jour où la prospection tourne.

**14. Planification des tâches (3).** `B17-004` : trois tâches **s'auto-sautent
depuis l'intérieur**, invisibles dans `schedule:list`. `B17-006` : sept sans
verrou, dont un `REFRESH MATERIALIZED VIEW` non concurrent horaire. `D29-009` :
`signals:nightly-scan` **ne tourne jamais le 29 mars et deux fois le 25 octobre**.

**15. Documents qui mentent (6).** `A06-002` : ligne « CLOSE en production »
dont **trois sous-critères sur quatre** sont contredits par le dépôt. `A09-008` :
« RLS sur 30 tables » dans trois documents, **55 en portent**. `A06-010` : le
plan qui « fait foi » **n'est versionné dans aucun dépôt**.

---

## 4. LA SYNTHÈSE DONNÉE À WILL — trois familles, pas quinze

1. **Un dispositif de sécurité posé mais désarmé** (thèmes 1, 3, 12). Le produit
   *ressemble* à un produit cloisonné et audité.
2. **Un instrument de mesure faux** (thèmes 6, 13, 15) — réconciliation,
   surveillance de sauvegarde, journaux d'étape. **C'est le motif le plus
   dangereux, parce qu'il produit de la confiance.**
3. **Une console jamais confrontée à un vrai usage** (thèmes 8, 9, 10, 11) —
   soit ~60 constats, exactement ce que l'ouverture des 39 écrans à la main
   (23/08 au soir) a commencé à toucher du doigt.

**Recommandation d'ordre donnée à Will** — le §8 de `OU-ON-EN-EST.md` met les
47 S1 avant les 168 S2, mais deux thèmes S2 méritent d'être remontés :

- le **thème 12** (le filet de tests), parce que **rien ne se prouve tant qu'il ment** ;
- le **thème 1** (le cloisonnement), parce que c'est **le seul thème S2 qui peut
  faire sortir la donnée d'un client vers un autre**.

*Cette recommandation n'a pas encore reçu de réponse de Will.*

---

## 5. CE QUI N'A PAS ÉTÉ FAIT, ET QU'IL NE FAUT PAS CROIRE FAIT

- ❌ **Aucun constat S2 n'a été rouvert, vérifié ou corrigé.** Cette session
  n'a fait que **lire et ranger**. Les 168 sont toujours ouverts au registre.
- ❌ **Le rangement en 15 thèmes n'est pas un dédoublonnage.** Le registre
  prévient que le dédoublonnage S2/S3 n'a **jamais** été fait : il reste très
  probablement des paires décrivant le même défaut sous deux étiquettes.
  **168 est un plafond.** Un thème à 25 constats en vaut peut-être 21.
- ❌ **Les états n'ont pas été revérifiés dans le code.** Ils sont pris tels
  quels dans la colonne d'état — celle-là même dont le §1.1 bis dit qu'elle
  a menti et peut mentir encore. Avant d'ouvrir un lot :
  `python verifier-etats-file-de-travail.py`.
- ❌ **Aucun fichier du produit n'a été touché.** Seuls trois fichiers de ce
  dossier de preuves ont été créés.

---

## 6. FICHIERS PRODUITS PAR CETTE SESSION

| fichier | rôle |
|---|---|
| `SESSION-EXPLICATION-2026-08-23.md` | **ce fichier** — le journal de la session, réécrit à chaque thème |
| `S2-PAR-THEME.md` | les 168 constats rangés, avec dépôt, nature, symptôme et `fichier:ligne` |
| `extraire-s2-par-theme.py` | le rangement **rejouable**, avec sa garde qui rougit |

---

## 7. CHRONOLOGIE

| quand | quoi |
|---|---|
| 2026-08-23, soir | Will : *« Continue de m'expliquer les constats S2, thème par thème, en clair. »* |
| ″ | Recomptage mécanique des 168 ouverts depuis le registre ; piège du filtre `VERIFIE FERME` payé |
| ″ | Rangement en 15 thèmes, garde éprouvée rouge, `S2-PAR-THEME.md` généré |
| ″ | Survol des 15 thèmes donné à Will + synthèse en 3 familles + recommandation d'ordre |
| ″ | Will : *« Sauvegarde au fur et à mesure… »* → création de ce journal, commité |
| ⏳ | **en attente** : quel thème Will veut ouvrir constat par constat |

---

# PARTIE 2 — PASSAGE EN AUTOPILOTE (2026-08-23, soir)

> **La session change de nature ici.** Elle n'explique plus : elle exécute.
> Consigne de Will, mot pour mot : *« Occupe-toi de la PR en faisant attention
> puis fais tout dans l'ordre en autopilot sans t'arrêter »*, suivie des trois
> chantiers repris ci-dessous.
>
> Le journal reste **un seul fichier**, dans l'ordre chronologique.

## 2.0 L'ORDRE DE MARCHE

| # | chantier | état |
|---:|---|---|
| 0 | **PR #194** — la fusionner, avec précaution | ✅ **fusionnée** `392a7377` — mais **incident de production**, voir §2.2 |
| 1 | **19 routes 501 + 9 écrans à corps figé** (exigence n° 10 du §12) | 🔄 en cours — recensement fait, voir §2.3 |
| 2 | **La refonte de navigation jamais appliquée** — 8 redirections, 0 écrite | ⏳ pas commencé |
| 3 | **Les grilles vides** — 11 policies, 84 services, 44 contrôleurs | ⏳ pas commencé |

---

## 2.1 LA PR #194 — ce qui a été vérifié AVANT de fusionner

Je ne me suis pas contenté du vert de la forge. Ce qui a été lu, dans cet ordre :

| vérification | résultat |
|---|---|
| état de la PR | `MERGEABLE` · `CLEAN` · 321 fichiers, +17 069 / −1 383 |
| les 24 contrôles | **tous `pass`** (3 `skipping` non bloquants) |
| local == distant | ✅ `b94ee76` des deux côtés — la note « 5 commits locaux » du dossier était **périmée** |
| **fichiers de production touchés** | ✅ **aucun** — ni `docker-compose.prod.yml`, ni `Dockerfile.laravel`, ni le `Caddyfile` de prod |
| `docker-compose.yml` (+7 lignes) | ✅ **des commentaires seulement**, lus ligne à ligne |
| **migrations neuves** | **une seule** : `2026_08_22_150000_audit_logs_prev_hash_sans_defaut` |
| cette migration, lue en entier | ✅ **sans danger** — `ALTER … DROP DEFAULT` est une opération de **catalogue** : aucune ligne réécrite, instantané même sur une table énorme ; tolère table simple **ou** partitionnée (boucle vide si pas de partition) ; `prev_hash` est créée par `2026_05_16_000002`, donc présente en production |
| l'exception ajoutée au scanner de secrets | ✅ **vérifiée moi-même**, pas crue sur parole |

🔑 **Sur l'exception de `gitleaks`.** Une liste blanche est exactement le geste qui
peut enterrer une vraie fuite. Le commit `b94ee76` affirmait un faux positif ;
j'ai cherché la valeur `un-mot-de-passe-de-reprise-2026` **dans tout le dépôt** :
elle n'apparaît qu'à deux endroits, la ligne du test unitaire et le commentaire
de l'exception elle-même. L'exception est posée par **empreinte** (commit + fichier
+ règle + ligne), pas par fichier — elle ne couvre donc rien d'autre. ✅ Faux
positif confirmé.

---

## 2.2 🔴 INCIDENT DE PRODUCTION — 502 pendant ~7 minutes, causé par cette fusion

**Il faut l'écrire, parce que c'est moi qui l'ai déclenché.**

| heure (UTC) | événement |
|---|---|
| 11:20:33 | PR #194 fusionnée → `392a7377` sur `main` |
| 11:20:36 | le workflow **Deploy direct SSH Hetzner** part tout seul (déclencheur : `push` sur `main`) |
| ~11:25 | les 6 contrôles de CI passent au vert ; le déploiement démarre |
| 11:26:01 | images construites **sur le VPS**, 4 conteneurs recréés |
| 11:26:11 | `Container axion-crm-horizon Recreated` — **puis plus rien** |
| **11:30:29** | 🔴 `client_loop: send disconnect: Broken pipe` → **exit 255** |
| 11:34 | mesure de l'extérieur : **`api` 502, `app` 502** |
| 11:35 | trois sondes à 10 s d'intervalle : **502, 502, 502** — ce n'était pas le 502 transitoire connu |
| 11:36 | relance du job de déploiement (`gh run rerun --failed`) |
| **11:37:21** | ✅ **`api` 200, `app` 200** — production rétablie |

### Ce que la panne a vraiment été

**Le déploiement n'a pas échoué : il a été COUPÉ EN PLEIN MILIEU.** La construction
a réussi, les conteneurs ont été recréés — puis **quatre minutes de silence**,
puis la rupture du tuyau SSH. Le script n'a donc jamais atteint ses trois
dernières étapes : `migrate --force`, la vérification des conteneurs, et la
sonde HTTP finale.

⚠️ **Le pipeline se croyait durci contre exactement ça.** Son en-tête annonce
que « `migrate --force` est BLOQUANT » et qu'une vérification post-déploiement
refuse tout conteneur `unhealthy`. C'est vrai — **quand le script va au bout**.
Il n'a aucune protection contre le cas où **le transport meurt en cours de
route** : la commande distante continue de tourner côté serveur, le job échoue
côté forge, et personne ne sait dans quel état la machine se trouve. *Un
déploiement « bloquant » qui perd sa connexion n'est pas bloquant : il est
aveugle.*

### Ce qui n'a PAS été touché

- La **migration ne s'est probablement pas jouée** au premier passage. Sans
  conséquence : elle ne fait que retirer un défaut SQL, et le service qui écrit
  le journal fournit toujours `prev_hash` explicitement. La relance la joue.
- **Aucune donnée** n'était en jeu : pas de réécriture de lignes, pas de
  suppression de colonne.

### Ce que j'en retiens, et que je n'invente pas après coup

J'avais vérifié **ce que la fusion emportait** — les fichiers, les migrations,
les compose. Je n'avais pas vérifié **ce que la fusion déclenchait** : que
`push` sur `main` lance un déploiement qui **construit les images sur le VPS
lui-même**. C'est là que ça a cassé. Une vérification de contenu ne remplace pas
une vérification de conséquence.

---

## 2.3 CHANTIER 1 — les routes qui répondent « pas implémenté »

### Le recensement, refait à la main plutôt que repris

Je n'ai pas repris le chiffre « 19 » du dossier : je l'ai remesuré.

```bash
grep -rn "notImplemented(" backend/app/Http/Controllers/ | grep -v ApiController.php
```

**20 sites d'appel**, dans 11 contrôleurs, qui donnent **20 routes** :

| # | route | contrôleur :: méthode | table derrière |
|---:|---|---|---|
| 1 | `PUT /workspace` | `WorkspaceController::update` | `workspaces` |
| 2 | `POST /users` | `UsersController::store` | `users` |
| 3 | `PUT /users/{user}` | `UsersController::update` | `users` |
| 4 | `DELETE /users/{user}` | `UsersController::destroy` | `users` |
| 5 | `PUT /contacts/{contact}` | `ContactsController::update` | `contacts` |
| 6 | `DELETE /contacts/{contact}` | `ContactsController::destroy` | `contacts` |
| 7 | `PUT /llm/use-cases/{u}` | `LlmUseCasesController::update` | `llm_use_cases` |
| 8 | `PUT /llm/use-cases/{u}/prompts/{v}` | `LlmUseCasesController::updatePrompt` | prompts |
| 9 | `PUT /proxy-providers/{p}` | `ProxyProvidersController::update` | `proxy_providers` |
| 10 | `POST /proxy-providers/{p}/test` | `ProxyProvidersController::test` | — (action) |
| 11 | `PUT /rotations/{rotation}` | `RotationsController::update` | `rotations` |
| 12 | `POST /saved-views` | `SavedViewsController::store` | `saved_views` |
| 13 | `GET /saved-views/{id}` | `SavedViewsController::show` | `saved_views` |
| 14 | `PUT /saved-views/{id}` | `SavedViewsController::update` | `saved_views` |
| 15 | `DELETE /saved-views/{id}` | `SavedViewsController::destroy` | `saved_views` |
| 16 | `POST /notifications/{n}/read` | `NotificationsController::markRead` | `notifications` |
| 17 | `POST /notifications/read-all` | `NotificationsController::markAllRead` | `notifications` |
| 18 | `POST /ai-act/register` | `AiActRegisterController::store` | registre AI Act |
| 19 | `ANY /cold-email{any?}` | `Phase2\ColdEmailController` | — **hors périmètre** |
| 20 | `ANY /linkedin{any?}` | `Phase2\LinkedInController` | — **hors périmètre** |

**Ma mesure (20) recoupe celle du dossier (19 + les deux conservés).**

### 🟢 La bonne nouvelle, et elle change le chantier

**Les tables existent déjà, et la vague 2 a réparé toutes les LECTURES.**
`notifications` et `saved_views` interrogent désormais vraiment leur table, avec
double cloisonnement espace + personne. Ce qui reste en 501 est **le côté
écriture**. Le chantier n'est donc pas « construire une fonctionnalité » mais
« finir un demi-travail assumé » — les contrôleurs disent eux-mêmes, en
commentaire, ce qu'ils ne font pas encore.

### 🔴 Ce qui est BLOQUÉ, et qui ne m'appartient pas

Les routes 5 et 6 (`PUT`/`DELETE /contacts`) touchent au constat **`I48-001`**,
dont la fiche d'audit dit explicitement que le correctif **ne peut pas être écrit**
avant que `I48-003` et l'arbitrage « où vit le type » ne soient tranchés :

> *« écrire une création contre `company_id NOT NULL` reviendrait à exiger une
> entreprise pour enregistrer un candidat »*

`contacts.company_id` et `contacts.last_name` sont tous deux `NOT NULL`. C'est
une **décision de conception**, pas un correctif mécanique. **Elle revient à Will.**

---

## 2.4 UN DÉFAUT TROUVÉ EN CHEMIN — le script de banc ne tourne pas sous Git Bash

`infra/scripts/rafraichir-le-banc.sh` — **celui que la mémoire de reprise
prescrit de lancer avant toute suite de tests** — échoue immédiatement :

```
GetFileAttributesEx C:\c\Users\willi\Documents\Projets\crmpro-wt-a35-auth:
The system cannot find the file specified.
```

Cause : `RACINE="$(… && pwd)"` rend `/c/Users/…` sous Git Bash, et `docker cp`
sous Windows lit ce chemin comme `C:\c\Users\…`. Le correctif tient en un mot —
`pwd -W` — mais il faut le poser proprement. **Contourné pour l'instant** (boucle
équivalente jouée à la main, 29 chemins rafraîchis) ; à corriger dans le dépôt.

*Ironie utile, et c'est la deuxième fois pour ce fichier : ce script a déjà fait
rougir la PR #194 parce qu'il n'était pas exécutable. Le script censé rendre le
banc honnête est le fichier le plus fragile du lot.*

---

## 2.5 ✅ CLÔTURE DE L'INCIDENT — la relance a tout remis d'aplomb

| | |
|---|---|
| relance du job | `gh run rerun 32636270302 --failed` |
| verdict | ✅ **`SSH Hetzner + pull + migrate + restart` → success** |
| `migrate --force` | ✅ joué **cette fois**, et `migrate:status` sans aucune `Pending` |
| production | ✅ **`api` 200 · `app` 200**, trois sondes espacées |
| durée totale de l'indisponibilité | **~7 minutes** (11:30 → 11:37) |

**Ce qu'il reste à faire de cet incident**, et qui n'est pas fait :
le pipeline n'a **aucune protection contre la mort du transport SSH**. Un
`ServerAliveInterval`, ou mieux un `nohup`/`setsid` côté serveur avec relevé
d'état séparé, empêcherait qu'une coupure de tuyau laisse la machine dans un
état que personne ne mesure. **C'est un correctif à écrire — il n'est pas
écrit.** Je le consigne plutôt que de le taire, parce que la prochaine fusion
rejouera exactement le même risque.
