# LES 39 ÉCRANS, OUVERTS À LA MAIN — journal de la passe navigateur

> **Exigence n° 3 du §12 du mandat**, la seule jamais entamée avant ce jour.
> Ouverts dans Chrome, sur `http://verif.localhost:8080`, pile `crmverif` bâtie
> depuis `crmpro-wt-a35-auth` — donc sur le code **d'après** la vague 2.
>
> Compte : `audit360@verif.localhost`, rôle `owner`, 2FA enrôlée.

⚠️ **Aucun mot de passe n'a été saisi dans le navigateur.** La session a été
établie par l'API puis transférée par cookie, et l'enrôlement 2FA confirmé par
l'API. Les écrans d'authentification ont été audités par leur **validation** et
leurs **états d'erreur**, ce qui ne demande aucun identifiant valide — et c'est
d'ailleurs la partie qui révèle les défauts.

---

## Écran 1 — `/login` · `LoginPage`

**Ouvert. Rien à signaler de grave.** Deux vérifications faites *pour ne pas
rapporter de faux constats*, toutes deux négatives :

- Les `<input>` n'ont ni `id` ni `name`, ce qui **semblait** casser les libellés.
  Mesure : les `<label>` **enveloppent** les champs, l'association est implicite
  et `input.labels.length === 1` pour les trois. **Accessible. Pas un défaut.**
- La validation à vide et sur adresse malformée est prise en charge nativement,
  messages en français (« Veuillez renseigner ce champ. »). **Correcte.**

Reste, mineur : les champs portent **à la fois** un `<label>` enveloppant **et**
un `aria-label` de même texte. `aria-label` l'emporte ; ici les deux coïncident,
donc sans effet — mais c'est une redondance qui divergera un jour.

---

## Écran 2 — `/2fa` · `TwoFactorPage`

### 🔴 `X39-001` — l'enrôlement n'affiche AUCUN QR code (S2)

Le backend **produit** le QR : `TwoFactorService::startEnrolment()` rend
`['secret' => ..., 'qr_url' => ...]` (`backend/app/Services/Auth/TwoFactorService.php:58-64`),
et la documentation OpenAPI de la route l'annonce noir sur blanc —
*« Initie l'enrolment TOTP (QR code + secret) »*
(`backend/app/Http/Controllers/Api/Auth/TwoFactorController.php:17`).

L'écran, lui, n'en affiche pas. Il demande : *« Dans votre application, ajoutez
un compte par saisie manuelle et collez cette clé »*, puis montre
`MZLY4F7DB0QSBCE4`. Le `qr_url` n'est employé que comme lien profond mobile
(« ajouter le compte »), **inutilisable sur un poste fixe** — c'est-à-dire là où
un CRM se travaille.

*Toutes les applications d'authentification courantes enrôlent à la caméra. On
demande ici de recopier 16 caractères base32 à la main, alors que la donnée qui
l'éviterait est déjà calculée et déjà transmise.*

### 🔴 `X39-002` — le champ n'est pas marqué invalide après un refus (S3)

Garde **vue rouge** : code `000000` soumis → *« Code refusé. Vérifiez l'heure de
votre téléphone, puis réessayez. »* Le message est bon (le décalage d'horloge est
la première cause d'échec TOTP).

Mais l'`<input>` ne porte **ni `aria-invalid`, ni `aria-describedby`** vers ce
message. Rien ne relie programmatiquement l'erreur au champ (WCAG 3.3.1).

✅ *Vérifié, et ce n'est PAS un défaut* : le bandeau d'erreur est bien dans une
région `aria-live="polite"` — il est donc annoncé. Seule la liaison au champ
manque. (`polite` plutôt qu'`assertive` pour un échec bloquant reste discutable.)

### `X39-003` — le code refusé reste dans le champ (S3)

Après refus, les six chiffres fautifs demeurent. L'utilisateur doit les effacer
un à un avant de réessayer, sur un écran où le code change toutes les 30 s.

---

## Écran 5 — `/` · `DashboardPage`

### 🔴 `X39-004` — l'espace de travail s'affiche par un FRAGMENT D'UUID (S2)

La barre latérale — **figée sur tous les écrans** (CDC §23.3) — affiche
**« Workspace e43437 »**.

Ce n'est pas une donnée manquante. Mesuré, dans cet ordre :

| | mesure |
|---|---|
| en base | `workspaces` porte `e43437b9-… \| axion-ia \| **Axion-IA**` |
| l'endpoint | `GET /api/v1/workspace` → **200**, `{"name":"Axion-IA", …}` |
| ce qu'affiche le composant | `` `Workspace ${data.user.current_workspace_id.slice(0, 6)}` `` |

Citation : `frontend/src/components/layout/WorkspaceSelector.tsx:28`.

*La donnée existe, la route existe, elle répond 200 — et le composant lit à la
place les six premiers caractères d'un identifiant technique.* Il interroge
`/auth/me`, qui ne rend que `current_workspace_id` et **jamais** le nom.

Conséquence : **aucun utilisateur ne voit jamais le nom de son organisation**, en
permanence, sur l'élément de navigation le plus stable de la console.

⚠️ **Et cela touche le parcours 18 du §11** (« le changement d'espace se voit-il
partout ? »). Il n'existe **aucune** route `/api/v1/workspaces` au pluriel : le
sélecteur n'a pas de quoi proposer une liste. *À vérifier au moment d'auditer ce
parcours : le sélecteur est-il seulement fonctionnel ?*

### 🔴 `X39-005` — la visite guidée est EN ANGLAIS (S2)

Le tout premier écran que voit un nouvel utilisateur : une fenêtre « Bienvenue
dans Axion CRM Pro 👋 », avec deux boutons — « Passer », et
**« Next (Step 1 of 7) »**.

Trois mots anglais (`Next`, `Step`, `of`) dans une console entièrement française,
sur la première impression, et répétés à chacune des **7 étapes**.

### `X39-006` — tutoiement et vouvoiement se mélangent DANS LE MÊME PARCOURS (S3)

Relevé sur trois écrans consécutifs de la connexion, et jusque dans un seul écran :

| écran | texte | registre |
|---|---|---|
| `/login` | « **Connecte-toi** à **ton** workspace » | tutoiement |
| `/2fa` enrôlement | « **Comptez** une minute », « **Saisissez** le code » | vouvoiement |
| `/2fa` vérification | « **Saisis** le code généré par **ton** authenticator » | tutoiement |
| `/` visite guidée | « On va **vous** montrer » | vouvoiement |
| `/` corps | « **Lance ton** premier scrape » | tutoiement |

Le vocabulaire suit le même désordre : « application d'authentification » puis
« **authenticator** » ; « espace de travail » puis « **workspace** » ; « scrape ».

---

## ✅ Ce qui est bon, et mérite d'être dit

- **Lien d'évitement présent** — « Aller au contenu » en premier élément focusable.
- **La barre latérale épouse le CDC §19** : cinq groupes (Aujourd'hui, Contacts,
  Collecte, Pilotage, Conformité, Réglages), libellés français et explicites
  (« Audiences (segments) », « Médias (presse) »).
- **Palette de recherche annoncée** avec son raccourci (`⌘K`).
- **Bascule de thème** présente (clair / système / sombre).
- `lang="fr"` sur `<html>`.

---

# DEUXIÈME PASSE — le balayage des écrans de la console

> 22 écrans supplémentaires ouverts. Ce qui suit ne contient que ce qui a été
> **vu à l'écran**, puis **recoupé dans le code ou en base**.

## 🔴 `X39-004` (suite) — la preuve est maintenant accablante

Le constat ne dit plus « le nom n'est pas affiché ». Il dit : **le même écran
affiche l'espace de travail de TROIS façons à la fois.**

Capture : `captures/X39-004_settings_workspace_trois_affichages.jpg`, écran
`/settings`, un seul instant :

| où | ce qui s'affiche |
|---|---|
| barre latérale, en haut à gauche | **« Workspace e43437 »** |
| bandeau, en haut à droite | « Workspace : **Axion-IA** » |
| champ « Nom » | **Axion-IA** |

*Deux composants de la même page savent aller chercher le nom. Le troisième —
celui qui est visible sur les 39 écrans, dans une barre latérale que le CDC §23.3
veut figée — lit six caractères d'UUID.* `WorkspaceSelector.tsx:28`.

## 🔴 `X39-007` — la console est écrite pour ses développeurs, pas pour ses utilisateurs (S2)

Ce n'est pas une maladresse isolée : c'est le **registre par défaut** des textes
d'en-tête. Relevé écran par écran, tel qu'affiché :

| écran | ce que lit l'utilisateur |
|---|---|
| `/users` | « 4 rôles RBAC : owner / admin / operator / viewer **(Spatie Permission teams)** » |
| `/llm/router` | titre **« LLM Router »**, puis « 9 **use cases** × 5 **providers** + **fallback chain** + **cost tracking** + **idempotency cache** 24h. » |
| `/llm/rotations` | « 5 dimensions de rotation : proxies + **user-agents** + **targets** + moteurs de recherche + **LLM providers**. » |
| `/cold-email`, `/linkedin` | « Ce module est **scaffoldé** : **DB** + **UI** prêtes, logique métier reportée à la Phase 2. » |
| `/settings` | « **Kill-switch** automatique LLM quand atteint », « **Slug** (URL) » |
| `/tags` | « géographie, secteur, taille, **intent**, **custom** » |
| `/audit-logs` | « Journal **append-only** avec chaîne cryptographique SHA-256 » |
| `/` (état vide) | « Démarrer sur **`/coverage`** → » — *un chemin de route brut* |
| `/` (état vide) | « Lance ton premier **scrape** » |

*« Spatie Permission teams » est le nom d'une bibliothèque PHP. Il est affiché à
un commercial qui cherche à inviter un collègue.* Le mandat demande une console
« simple à prendre en main » ; **toute complication est un constat** (§11).

## 🔴 `X39-008` — le fil d'Ariane et le titre de la page se contredisent, en deux langues (S2)

Capture : `captures/X39-008_cold-email_fil_ariane_vs_titre.jpg`.

| | `/cold-email` | `/linkedin` |
|---|---|---|
| fil d'Ariane | **E-mails à froid** | **Prospection LinkedIn** |
| titre `h1`, juste dessous | **Cold email** | **LinkedIn outreach** |

Citation du bon libellé : `frontend/src/components/layout/AutoBreadcrumbs.tsx:52-53`.
*La traduction existe et elle est bonne — elle n'est simplement pas employée par
l'écran lui-même. Deux noms pour la même chose, à trois centimètres l'un de
l'autre.*

## 🔴 `X39-009` — le bandeau « temps réel » est en anglais sur un écran, en français sur l'autre (S3)

Même composant, même fonction, deux langues :

| écran | bandeau |
|---|---|
| `/coverage` | **« Live · refresh 60s »** |
| `/campaigns` | « **En direct** · **actualisé toutes les** 10s » |
| `/audiences` | « exécutées au **refresh** auto » |
| `/admin/observability` | « échecs audience **refresh** » |

## `X39-010` — aucun écran n'a de titre de page propre (S3)

`document.title` vaut **« Axion CRM Pro »** sur les 22 écrans balayés, sans
exception. Conséquences concrètes : les onglets d'un navigateur sont
indiscernables, l'historique ne dit rien, et un lecteur d'écran annonce la même
chose à chaque navigation. `/settings`, `/users`, `/audit-logs` : même titre.

## `X39-011` — `/console/vivier` n'a AUCUN `h1` (S3)

L'écran de refus d'accès (« Univers vivier candidats non accessible ») ne porte
pas de `h1`. C'est le seul des 22 écrans balayés dans ce cas.

✅ *Le refus lui-même est CORRECT et c'est une bonne nouvelle* : mon compte est
membre de `axion-ia`, pas de `vivier-candidats`, et l'accès est refusé avec un
message qui explique quoi faire. **C'est l'étanchéité que le parcours 5 du §11
demande de vérifier — elle tient.**

---

## 📌 CORRECTION À L'INVENTAIRE DU MANDAT LUI-MÊME (§4.7)

Le mandat annonce **« 4 écrans factices »** : `/cold-email`, `/linkedin`, `/crm`,
`/analytics`. **Deux des quatre n'existent pas.**

| route | ce que dit le mandat | ce qui est mesuré |
|---|---|---|
| `/cold-email` | `ColdEmailStub` | ✅ écran « Phase 2 » |
| `/linkedin` | `LinkedInStub` | ✅ écran « Phase 2 » |
| `/crm` | `CrmStub` | ❌ **404 « Page introuvable »** |
| `/analytics` | `AnalyticsStub` | ❌ **404 « Page introuvable »** |

Recoupé dans le code, pas seulement à l'écran : `frontend/src/app/routeTree.tsx`
ne déclare que les lignes **140** (`/cold-email`) et **141** (`/linkedin`).
Aucune route `/crm` ni `/analytics` n'y figure.

*Doctrine règle 1 : le code fait foi, les documents sont des hypothèses — y
compris le mandat, y compris quand il énumère.* L'exigence n° 10 du §12
(« aucun écran factice sous un nom que le CDC emploie ») porte donc sur **deux**
écrans, pas quatre.

Autre correction, moindre : le mandat marque `/contacts` « ⚠️ doublon de
`/console/contacts` ». Mesuré : `/contacts` **redirige** vers
`/console/contacts`. Ce n'est pas un doublon, c'est une redirection.

---

## ⚠️ PIÈGE DE MESURE PAYÉ — à ne pas repayer

**Aucune mesure de TEMPS prise depuis ce gréement n'est exploitable.**

`/contacts` a semblé mettre **24 553 ms** à s'afficher. J'allais l'inscrire comme
un défaut de performance majeur sur une base vide. Vérification avant écriture :

```
document.visibilityState === "hidden"   ·   hasFocus() === false
un setTimeout de 1000 ms en a duré 1451
```

Chrome **bride les minuteurs des onglets non visibles**. Et la mesure côté
serveur, elle, n'est pas bridée :

| endpoint | code | temps |
|---|---|---|
| `/api/v1/crm/contacts-hub/counts` | 200 | **0,060 s** |
| `/api/v1/crm/contacts-hub` | 200 | **0,051 s** |
| `/api/v1/contacts` | 200 | **0,084 s** |
| `/api/v1/companies` | 200 | **0,043 s** |
| `/api/v1/audiences` | 200 | **0,045 s** |

*Le produit n'a rien à se reprocher ici. C'est l'instrument qui mentait.*
Pour mesurer un rendu pour de vrai : onglet au premier plan, ou Lighthouse.

---

## ⚠️ DEUXIÈME PIÈGE DE MESURE PAYÉ — le gréement de balayage abîme ce qu'il mesure

Après une vingtaine d'écrans enchaînés par `history.pushState()` **sans jamais
recharger**, le moteur de rendu de Chrome s'est **figé** : plus de capture, plus
d'exécution de script, expiration à 30 et 45 s.

L'onglet était alors sur `/scraper-runs`. La conclusion tentante — *« cet écran
gèle le navigateur »* — aurait été un constat S0 spectaculaire. Trois
vérifications l'ont démontée, dans cet ordre :

1. **Les conteneurs sont au repos** : `docker stats` rend 0,00 % à 0,32 % de
   processeur sur les cinq. Le produit ne calcule rien.
2. **Une autre route, `/companies`, refuse aussi de s'afficher** — alors qu'elle
   fonctionnait quinze minutes plus tôt. Ce n'est donc pas propre à la route.
3. **Onglet fermé, onglet neuf, chargement à froid de `/scraper-runs` :
   l'écran s'affiche parfaitement**, en entier, avec ses compteurs.

*C'est le gréement qui s'était dégradé, pas le produit.*

**Règle à tenir pour la suite** : recharger l'onglet toutes les quelques pages, et
**ne jamais conclure au gel d'un écran sans l'avoir rechargé à froid dans un
onglet neuf**.

⚠️ Corollaire déjà rencontré : pendant que le moteur était figé, les mesures
`curl` elles-mêmes sont devenues bruitées (`/api/v1/scraper-runs` a rendu 4,21 s
une fois, puis 0,32 à 1,90 s ; **mais le témoin `/api/v1/campaigns` variait de
0,18 à 1,04 s dans le même temps**). **Aucun constat de performance n'a été
inscrit sur cette base** — le témoin bougeait autant que le sujet.

---

## Écran — `/scraper-runs` · `ScraperRunsPage` (relu à froid)

Rendu correct et complet. Fil d'Ariane et titre **concordent** (« Journaux de
collecte »), contrairement à `/cold-email`. Bandeau « **En direct** · actualisé
toutes les 10s » — **en français**, ce qui confirme que `/coverage` (« Live ·
refresh 60s ») est l'exception, pas la règle (`X39-009`).

Nouvelles occurrences de `X39-007` : « **Monitoring** des **jobs** de
**scraping** en temps réel », « Lance ton premier **scrape** », « **Lancer un
scrape** ».

---

# TROISIÈME PASSE — les écrans restants. **L'INVENTAIRE EST BOUCLÉ.**

> Reprise du 2026-08-23 après-midi, session neuve. Pile `crmverif` relevée
> (Docker était éteint) : `/up` en **0,051 s**, la SPA en **0,035 s**.

## 📌 L'exigence n° 3 du §12 est ATTEINTE — et le compte n'était pas le bon

**Les 36 écrans du produit ont été ouverts à la main.** Pas 39, pas 37 : **36**.

| source | compte | ce qui cloche |
|---|---|---|
| le mandat, §4.7 | 39 | `/crm` et `/analytics` n'existent pas (déjà corrigé en 2ᵉ passe) |
| `01_INVENTAIRE.md:41` | 37 routes | compte `layoutRoute` |
| mesuré ici | **36 écrans** | `layoutRoute` porte `id: 'layout'` et **aucun `path`** : c'est la coquille de mise en page, pas un écran |

Commande jouée : `grep -oP "^const \K\w+(?= = createRoute)" frontend/src/app/routeTree.tsx`
→ 37 déclarations, dont `layoutRoute`. *Le §4.7 porte donc sur 36 écrans.*

**16 écrans ouverts dans cette passe**, qui complètent les 20 des passes 1 et 2 :
`/magic-link`, `/password-reset`, `/international/roumanie`, `/media`,
`/journalists`, `/llm/proxy-providers`, `/rgpd/requests`, `/rgpd/ai-act`,
`/campaigns/new`, `/audiences/new`, `/console/arbitrage`, `/companies/{id}`,
`/media/{id}`, `/campaigns/{id}`, `/audiences/{id}`, `/console/personnes/{clé}`.

## Le gréement a changé, et c'est ce qui rend la passe fiable

L'hypothèse laissée en suspens ce matin — *« chaque écran installe un sondage
périodique, les changements de route les accumulent, le moteur finit par se
figer »* — a été traitée **à la racine plutôt que testée** : le nouveau gréement
(Playwright, Chromium 1223) ouvre **une page NEUVE, chargée À FROID, pour chaque
écran**, et la ferme ensuite. Aucun `history.pushState` n'enchaîne, aucune page
n'est réutilisée. La règle du journal (« recharger toutes les quelques pages »)
n'est plus une discipline à tenir : elle est **structurelle**.

**Résultat : aucun gel, sur aucun des 16 écrans.** Tous rendus entre **1,16 s et
2,67 s**, tous en 200, avec `document.visibilityState === "visible"` et l'onglet
au premier plan — donc **sans le bridage des minuteurs** qui avait faussé la
mesure de `/contacts` en 2ᵉ passe.

*L'hypothèse du sondage accumulé reste à tester sur son propre terrain (navigation
SPA prolongée), mais elle ne peut plus contaminer l'ouverture des écrans.*

### ⚠️ QUATRIÈME FAUX CONSTAT ÉVITÉ — le « chargement infini » n'existe pas

`/companies/999999` (fiche inexistante) affichait **« Chargement… /
Chargement de la fiche entreprise… »** à mon premier relevé. Le constat tentant
était : *« une fiche introuvable laisse l'utilisateur sur un sablier perpétuel »*.

Relevé sur 40 s, avec témoin (`/companies/1`, qui existe) :

| instant | ce qu'affiche `/companies/999999` |
|---|---|
| 2 s | Chargement… |
| **5 s** | **« Entreprise introuvable — 404 — Cette entreprise n'existe pas ou a été supprimée. »** |
| 10 → 40 s | idem, stable |

**L'écran d'erreur est correct, et il arrive.** Mon premier relevé l'avait
simplement pris trop tôt. *Troisième fois que ce dépôt punit une mesure prise à
l'instant qui arrange — après le gel et après `/contacts`.*

Ce qui reste, mesuré et réduit à sa taille réelle : **`X39-022` (S3)** — le
squelette dure **2 à 5 s** alors que l'API a répondu **404 en ~50 ms**. Cause
lue dans le code, pas devinée : `frontend/src/main.tsx:30-33` n'exclut du réessai
que **401 et 403**. Un **404 — réponse définitive — est donc retenté deux fois**,
avec attente croissante. *On fait patienter l'utilisateur sur une réponse qu'on
avait déjà.*

---

## 🔴 `X39-015` — une carte intitulée **« DEBUG »** sur chaque fiche entreprise (S2)

Capture : `captures/X39-015_fiche-entreprise_section-DEBUG.png`.

L'écran `/companies/{id}`, tel qu'il s'affiche à un `owner`, porte une carte :

> **DEBUG**
> Données brutes (signals)

Recoupé dans le code — et c'est là que ça se durcit :
`frontend/src/features/companies/CompanyDetailPage.tsx:234`,
`<CardEyebrow>Debug</CardEyebrow>`, **sans aucune garde** : ni
`import.meta.env.DEV`, ni contrôle de rôle, ni drapeau de fonctionnalité. La
carte est rendue **inconditionnellement, pour tout le monde, en production**.

*Le mot « DEBUG » n'a pas de sens pour un commercial. Et ce qu'il ouvre — les
signaux bruts de collecte — n'a pas été pensé pour être lu par lui.*

## 🔴 `X39-014` — l'écran de détail des médias affiche la valeur BRUTE de la base (S2)

Capture : `captures/X39-014_media-detail_presse_hebdo-brut.png`.

Le même objet, sur deux écrans, au même instant :

| écran | ce qui s'affiche |
|---|---|
| `/media` (la liste) | filtres soignés : « 📰 Journal », « 📓 Revue / périodique », « 📻 Radio », « 🛰️ Agence de presse » |
| `/media/{id}` (le détail) | **`presse_hebdo`** en sous-titre, **`Type presse_hebdo`** dans la fiche, **`enrichissement : pending`** |

*La table de correspondance existe, elle est employée par la liste, et l'écran de
détail ne s'en sert pas.* C'est exactement le motif de `X39-008` : la traduction
est écrite, elle n'est pas appelée.

## 🔴 `X39-016` — une clé de personne malformée rend un écran ENTIÈREMENT VIDE (S2)

Capture : `captures/X39-016_personne_cle-malformee_ecran-vide.png`.

`/console/personnes/pas-une-cle` :

- l'API répond **404** (`GET /api/v1/crm/persons/pas-une-cle/timeline`) — correct,
  `PersonTimelineController.php:40` exige `/^[0-9a-f]{64}$/`
- l'écran, lui, ne rend **rien** : **aucun `h1`**, `<main>` **vide**
- seul subsiste le fil d'Ariane — qui affiche **« Pas une cle »**, la chaîne
  fautive passée en capitale initiale comme si c'était un nom de personne

*Un lien abîmé, un copier-coller tronqué, et l'utilisateur reçoit une page
blanche sans un mot d'explication.* Comparer avec `/companies/999999`, qui
**sait** dire « Entreprise introuvable » : le produit possède le geste, cet
écran-ci ne l'a pas.

⚠️ **Et le contraste avec la clé bien formée est instructif** :
`/console/personnes/{sha256 valide mais inconnu}` s'affiche, lui, **correctement**
(« Personne · Aucune fiche dans les univers auxquels vous avez accès »). Le trou
n'est donc pas « personne inconnue » — c'est **« clé illisible »**.

## 🔴 `X39-017` — toute erreur de validation du serveur sort en CLÉ DE TRADUCTION BRUTE (S2)

Mesuré, en franchissant un formulaire par l'API depuis l'écran lui-même :

```
POST /api/v1/audiences   {"description":"sans nom"}
→ 422 {"message":"validation.required (and 1 more error)",
       "errors":{"name":["validation.required"],"criteria":["validation.required"]}}
```

Ce n'est pas un accident de cette route. **Cause lue à la source** :

| | mesure |
|---|---|
| `backend/config/app.php:73-74` | `'locale' => env('APP_LOCALE', 'fr')`, `'fallback_locale' => 'fr'` |
| répertoire `backend/lang/` | **n'existe pas** |
| répertoire `backend/resources/lang/` | **n'existe pas** |

*Laravel ne trouve aucun fichier de traduction, donc il rend la clé telle quelle.*
Toute réponse 422 de **toute** route du produit sort donc en `validation.required`,
`validation.max`, etc. — et le fragment anglais **« (and 1 more error) »** avec.

⚠️ **CE QUI RESTE À PROUVER, ET QUI N'EST PAS ÉCRIT ICI** : je n'ai **pas** encore
vu cette chaîne s'afficher **à l'écran**. Le formulaire `/audiences/new` que j'ai
soumis pour de vrai a **réussi** (l'écran fournit `criteria` par défaut). Tant que
le relais n'est pas mesuré, ce constat vaut **pour l'API**, pas pour l'écran.
*À finir : trouver un formulaire dont le geste réel produit un 422, et lire ce que
l'utilisateur en voit.*

## `X39-012` — connecté, `/login` affiche quand même le formulaire de connexion (S3)

Capture : `captures/X39-012_login-affiche-a-un-utilisateur-deja-connecte.png`.

Session valide en main, `/login` rend **« Connexion — Connecte-toi à ton
workspace »**, et `/2fa` rend **« Code à 6 chiffres »**. Aucune redirection.

Recoupé dans le code : les quatre écrans d'authentification sont enfants de
`rootRoute`, **pas** de `layoutRoute` (`routeTree.tsx:99-102`) — ils échappent donc
à la garde portée par `RootLayout`. Et `LoginPage.tsx` ne navigue **qu'après** une
soumission réussie (lignes 78 et 80) : rien n'y consulte la session existante.

*Le chemin est concret : depuis `/magic-link` ou `/password-reset`, le seul lien de
sortie est « Retour à la connexion ». Un utilisateur connecté qui le suit atterrit
sur un formulaire de connexion — et croit sa session perdue.*

## `X39-013` — les écrans de détail titrent par l'IDENTIFIANT TECHNIQUE (S3)

Le fil d'Ariane, sur les cinq écrans de détail ouverts, alors que le **nom est
affiché juste en dessous** :

| écran | dernier maillon du fil d'Ariane | `h1`, trois centimètres plus bas |
|---|---|---|
| `/companies/1` | **`#1`** | Verif Audit 360 SAS |
| `/media/2` | **`#2`** | Gazette de verification |
| `/campaigns/1` | **`#1`** | Collecte de verification |
| `/audiences/1` | **`#1`** | Audience de vérification |
| `/console/personnes/{clé}` | **les 64 signes du SHA-256** | Personne |

*Même geste que `X39-004` : la donnée lisible est là, chargée, affichée à côté — et
l'élément de navigation choisit l'identifiant de la base.*

## `X39-018` — les deux boutons d'action de l'audience sont en anglais (S3)

Capture : `captures/X39-018_audience_Refresh-Edit-en-anglais.png`.
`/audiences/{id}` : boutons **« Refresh »** et **« Edit »**, à côté de
« Supprimer ». Et dans les compteurs : « DERNIÈRE **REFRESH** », « AUTO-**REFRESH**
OFF », « Lance un **refresh** pour matérialiser le segment ». Famille `X39-009`.

## `X39-019` — `/campaigns/new` : le fil d'Ariane et le titre nomment deux choses différentes (S3)

Capture : `captures/X39-019_campaigns-new_ariane-collecte-vs-titre-campagne.png`.

| | texte |
|---|---|
| fil d'Ariane | Accueil / **Collectes** / **Nouvelle collecte** |
| `h1`, juste dessous | **Nouvelle campagne** |
| corps | « Configure ta **campagne** en 4 étapes » |

Motif de `X39-008`, mais **en français des deux côtés** : ce n'est plus une
traduction oubliée, ce sont **deux mots pour un seul objet**. Confirmé sur
`/campaigns/{id}` (fil : « Collectes / #1 ») et sur `/scraper-runs`
(« Rechercher une **collecte** »).

## `X39-020` — un statut anglais parmi des statuts français (S3)

`/international/roumanie`, la barre de filtres, telle qu'affichée :
« Tous · Prospectables · Partiels · **Pending** · Archivés ».

## `X39-021` — trois écrans demandent à l'utilisateur de lancer des COMMANDES SERVEUR (S2)

Extension de `X39-007`, mais d'une autre nature : ici l'écran ne se contente pas
d'employer le vocabulaire des développeurs, il **donne une consigne que son
lecteur ne peut pas exécuter**.

| écran, à l'état vide | ce qui est demandé |
|---|---|
| `/media` | « Lance l'extraction des médias **(media:extract-from-companies)** pour peupler la base. » |
| `/rgpd/ai-act` | « Le LLM Router devrait apparaître ici après seed initial **(AiActRegisterSeeder)**. » |
| `/llm/proxy-providers` | « Configure **WEBSHARE_API_KEY** ou **IPROYAL_USERNAME** dans **.env serveur** pour activer les proxies. » |

*Une commande Artisan, un nom de classe PHP, deux variables d'environnement.
Aucun des trois n'est atteignable depuis la console.* Et le cas `/rgpd/ai-act` est
le plus lourd : **un écran de conformité AI Act qui annonce lui-même que son
registre obligatoire n'a pas été semé**.

---

## ✅ CE QUI A ÉTÉ VÉRIFIÉ ET QUI N'EST **PAS** UN DÉFAUT

*Doctrine règle 3 : ces témoins négatifs comptent autant que les constats.*

1. **Le verrou 2FA du serveur TIENT.** Session ouverte, 2FA **non** franchie :
   `/api/v1/{contacts,companies,audiences,campaigns,audit-logs,users}` rendent
   **403**, les six. Seul `/auth/me` rend 200 — ce qui est **nécessaire** pour que
   l'écran `/2fa` sache qui il fait entrer. *Le correctif `F35-003` est confirmé au
   geste, pas seulement à la lecture du diff.*
2. **Aucune erreur de console, aucune requête en échec** sur les 16 écrans (hors
   les 404 volontaires des états d'erreur). Zéro `pageerror`.
3. **Accessibilité des formulaires : rien à signaler.** Sur les 16 écrans,
   **0 champ** sans nom accessible (ni `<label>`, ni `aria-label`, ni
   `aria-labelledby`) et **0 image** sans attribut `alt`.
4. **`lang="fr"`** sur les 16 écrans.
5. **L'étanchéité des univers tient toujours** : clé SHA-256 valide mais inconnue →
   « Aucune fiche dans les univers auxquels vous avez accès », les deux univers
   nommés séparément (Business / Vivier candidats).
6. **`/console/arbitrage` dit son propre angle mort**, et c'est assez rare pour
   être relevé : « Cet écran ne voit que ce que l'ingestion y dépose : il ne dit
   rien des événements qui ne sont jamais arrivés jusqu'ici. »

## `X39-010` : confirmé sur la totalité de l'inventaire

`document.title === "Axion CRM Pro"` sur les **36** écrans, sans une exception.

## `X39-006` (tutoiement/vouvoiement) : le désordre est confirmé partout

Nouvelles occurrences : « **Reçois** un lien » (`/magic-link`), « **Saisis**
l'email associé à **ton** compte, on **t'**envoie » (`/password-reset`),
« Configure **ta** campagne » (`/campaigns/new`), « **Lance ton** premier scrape »
(`/scraper-runs`), « **Lance un** refresh » (`/audiences/{id}`).

---

# 🔄 REPRISE IMMÉDIATE — pour une session neuve, en cinq minutes

## Relever la pile (Docker est souvent éteint)

```bash
# 1. Démarrer Docker Desktop, puis :
cd C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth
docker compose -p crmverif -f docker-compose.verif.yml up -d
curl -s -o /dev/null -w '%{http_code} %{time_total}\n' \
  --resolve verif.localhost:8080:127.0.0.1 http://verif.localhost:8080/up   # 200, ~0,05 s
```

⚠️ **L'adresse de travail est `http://verif.localhost:8080` (HTTP, pas TLS).**
`SESSION_SECURE_COOKIE=false` et `SANCTUM_STATEFUL_DOMAINS` déclare
`verif.localhost:8080`. Le `:8443` du §0 bis marche aussi mais impose `-k`.

⚠️ **La pile historique `axion-crm-*` (INTERDITE) se relève seule au démarrage de
Docker Desktop.** Elle n'entre pas en conflit de ports avec `crmverif`
(8080/8443 contre 80/443) : on la laisse tranquille, on ne s'en sert pas.

## Rouvrir une session (aucun mot de passe saisi dans le navigateur)

```bash
J=<jar>/cookies.txt ; B=http://verif.localhost:8080
curl -s -c $J --resolve verif.localhost:8080:127.0.0.1 $B/sanctum/csrf-cookie
X=$(grep XSRF $J | awk '{print $7}' | sed 's/%3D/=/g')
curl -s -b $J -c $J --resolve verif.localhost:8080:127.0.0.1 \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Origin: $B" -H "Referer: $B/login" -H "X-XSRF-TOKEN: $X" \
  -d '{"email":"audit360@verif.localhost","password":"Audit360-Verif!2026"}' \
  $B/api/v1/auth/login
```

Le compte a la **2FA enrôlée** : il faut ensuite poster le code TOTP sur
`/api/v1/auth/2fa/verify`. Le code se fabrique dans le conteneur — script à
recréer, il ne survit pas au redémarrage :

```php
// /tmp/totp.php   (puis : MSYS_NO_PATHCONV=1 docker exec crmverif-api php //tmp/totp.php)
require "/var/www/html/vendor/autoload.php";
$app = require_once "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::where("email","audit360@verif.localhost")->firstOrFail();
echo (new PragmaRX\Google2FA\Google2FA())->getCurrentOtp($u->totp_secret), PHP_EOL;
```

⚠️ Sous Git Bash, `docker exec ... php /tmp/totp.php` est **réécrit** en
`C:/Users/willi/AppData/Local/Temp/totp.php`. D'où `MSYS_NO_PATHCONV=1` **et** le
double slash `//tmp/`.

## Le gréement navigateur

**L'extension Chrome de Claude ne répond pas** (trois tentatives, Chrome relancé
entre-temps). Le gréement qui marche est **Playwright**, déjà installé dans le
worktree : `crmpro-wt-a35-auth/frontend/node_modules/playwright`, Chromium 1223
(`C:\Users\willi\AppData\Local\ms-playwright\chromium-1223\`). Lancer les scripts
depuis `crmpro-wt-a35-auth/frontend` pour que `require('playwright')` résolve.
Option indispensable : `args: ['--host-resolver-rules=MAP verif.localhost 127.0.0.1']`.

⚠️ **PIÈGE PAYÉ, à ne pas repayer** — en lisant le pot de biscuits de `curl`,
**ne pas écarter les lignes qui commencent par `#`** : `curl` préfixe
`#HttpOnly_` au domaine, et c'est exactement la ligne du **cookie de session**.
Le filtre naïf ne transfère que `XSRF-TOKEN`, et toute la passe rend l'écran de
connexion avec des 401 partout — sans que rien n'ait l'air cassé.

## Les données de vérification créées ce jour (base jetable)

`companies` id **1** · `media` id **2** · `scraping_campaigns` id **1** ·
`email_audiences` id **1**. Créées par l'API — sauf `media`, inséré directement en
base : il n'existe **aucune** route `POST /media`, les médias ne naissent que
d'une extraction. Contrainte à connaître : `media_media_type_check` n'accepte que
`presse_quotidien|presse_hebdo|presse_mensuel|presse_journal|presse_revue|
presse_autre|radio|tv|tv_emission|agence_presse|portail_web|blog|
production_audiovisuelle`.

## Ce qui reste, dans l'ordre

1. **Finir `X39-017`** : trouver un formulaire dont le geste réel produit un 422,
   et **lire à l'écran** ce que l'utilisateur en voit. Sans ça, le constat vaut
   pour l'API et pas pour l'écran.
2. **Tester l'hypothèse du sondage accumulé sur son propre terrain** : navigation
   SPA prolongée (liens internes, sans rechargement), en instrumentant
   `setInterval`/`clearInterval` **avant** le démarrage de l'application, et en
   comptant les minuteurs vivants. C'est la seule façon honnête de trancher —
   et le gel a déjà été disculpé deux fois.
3. Reprendre le §8 de `OU-ON-EN-EST.md` à l'étape 5 (vague du dépôt du site).

---

# QUATRIÈME PASSE — l'hypothèse du matin, tranchée. Et deux constats de plus.

## 🟢 L'HYPOTHÈSE DU SONDAGE ACCUMULÉ EST **RÉFUTÉE**

*C'était la question laissée ouverte à la coupure de 10:47 :*
« chaque écran installe un sondage périodique, les changements de route les
**accumulent** sans les arrêter, et le moteur finit par se figer. »

**Elle est fausse.** Et elle n'a pas été jugée sur l'apparence du gel — ce chemin
a déjà produit deux faux constats — mais sur le seul témoin qui ne triche pas :
**le débit d'appels à l'API**, mesuré sur la même fenêtre, sur le **même écran**,
avant et après une longue navigation.

Protocole (`greement-navigateur/sondages-accumules.mjs`) :

1. tableau de bord chargé à froid → **fenêtre de 40 s**, on compte les appels
2. **20 changements de route d'affilée, sans UN SEUL rechargement**
3. retour sur le **même** tableau de bord, toujours sans rechargement →
   **fenêtre de 40 s** identique

| | appels en 40 s | débit | intervalles vivants | posés | coupés |
|---|---|---|---|---|---|
| **avant** (écran à froid) | 1 | 1,5/min | **1** | 5 | 4 |
| **après** (20 routes plus tard) | 1 | **1,5/min** | **1** | 25 | 24 |

**Le débit est identique. Les compteurs se referment exactement : 25 posés,
24 coupés, 1 vivant — le même unique minuteur qu'au départ.** Si les sondages
s'accumulaient, on attendrait un débit d'un ordre de grandeur supérieur et une
vingtaine de minuteurs vivants. Il n'y en a **qu'un**.

Recoupé dans le code : le sondage passe par `refetchInterval` de react-query
(`DashboardPage.tsx:81`, `CoveragePage.tsx:71`, `ObservabilityPage.tsx:34`,
`AudiencesListPage.tsx:68`, `CampaignDetailPage.tsx:106`…), qui est **lié à
l'observateur de la requête** : il est démonté avec l'écran. Et deux écrans vont
plus loin — `CampaignsListPage.tsx:95` et `ScraperRunsPage.tsx:270` posent
`refetchIntervalInBackground: false`.

Le moteur de rendu **répondait encore** à la fin du parcours.

⚠️ **La limite de cette mesure, écrite pour qu'on ne la surestime pas** :
l'instrumentation n'enveloppe que `setInterval`. Si react-query planifie par
`setTimeout`, ces minuteurs-là échappent au compteur — et c'est bien pourquoi
**le débit d'appels est le témoin qui porte**, pas le compte d'intervalles.
Sur 20 écrans enchaînés, un sondage non coupé aurait produit des appels. Il n'y
en a pas eu.

*Le gel du matin était donc bien le gréement, comme le commit `d7ae9ad` l'avait
déjà établi — et non un sondage qui s'empile. **Deuxième disculpation du même
soupçon, cette fois par un chemin indépendant.***

*Note recueillie au passage : `/contacts` **redirige** vers `/console/contacts`
au changement de route — ce qui reconfirme, au geste, la correction déjà écrite
en 2ᵉ passe (ce n'est pas un doublon, c'est une redirection).*

---

## 🔴 `X39-023` — le réglage qui évite un décalage de 2 h n'est dans AUCUN `.env` du dépôt (S2)

**Mesuré d'abord, expliqué ensuite.** Sur la pile de vérification — bâtie *depuis
ce dépôt*, avec sa configuration — une étiquette créée par le produit s'inscrit
en base **deux heures dans le futur**, et c'est **Postgres lui-même** qui le dit :

```
heure hôte (UTC)   : 09:46:11
postgres now()     : 09:46:17          ← l'horloge de la base est juste
created_at (UTC)   : 11:46:14          ← ce que le produit vient d'écrire
                     écart = 01:59:56
```

Et la même fiche est rendue **différemment selon la route qui la sert** :

| | `created_at` |
|---|---|
| réponse `201` de la création | `2026-08-23T11:44:35**+02:00**` |
| réponse `409` du doublon, secondes plus tard | `2026-08-23T11:44:35**+00:00**` |
| `GET /api/v1/tags` | `2026-08-23T11:44:35**+00:00**` |

*Le premier rend l'instant juste (la valeur Carbon encore en mémoire), les deux
autres relisent la base et rendent un instant faux de deux heures.*

### Ce n'est PAS un constat neuf — c'est `A05-008`, reproduit

L'audit connaît ce défaut et **le déclare corrigé** : `11_GRILLES/agent-08_non-régressions.md`,
point 2 du §A.1, **✅ TENU**, mesuré sur données de production. Le correctif retenu
n'est pas « passer à UTC » mais **aligner la session Postgres sur le fuseau
applicatif** : `DB_TIMEZONE = APP_TIMEZONE = Europe/Paris`.

**Le neuf est ailleurs : où ce réglage vit, et où il ne vit pas.**

| endroit | `DB_TIMEZONE` |
|---|---|
| `backend/phpunit.xml:100` | ✅ `Europe/Paris` |
| `backend/phpunit-ci.xml:108` | ✅ `Europe/Paris` |
| l'environnement de **production** | ✅ `Europe/Paris` (`04_PREUVES/agent-08/04_prod-env-isolation-tz.txt:8`) |
| **`.env.example`** | ❌ **absent** (`grep -c` → **0**) |
| **`.env`** et **`backend/.env`** du dépôt | ❌ **absents** (seul `APP_TIMEZONE` y figure) |
| `docker-compose.verif.yml` | ❌ absent |

`backend/config/database.php:54,122` **lit** bien `env('DB_TIMEZONE')` — le code du
correctif est là. **La variable, elle, n'est posée nulle part dans le dépôt.**

*Conséquence, et elle est mesurée, pas déduite : **toute nouvelle installation
montée à partir de ce dépôt reproduit `A05-008` en silence.** Ma pile de
vérification en est la démonstration — elle a été bâtie en suivant la
configuration du dépôt, et elle horodate deux heures dans le futur.*

Et la garde ne peut pas prévenir : `HorodatagesFuseauTest` s'exécute sous
`phpunit.xml`, **qui fournit lui-même `DB_TIMEZONE`**. Elle vérifie que le
comportement est bon **quand le réglage est là** ; rien ne vérifie qu'un
déploiement réel l'ait. *La correction ne tient qu'à la mémoire d'un serveur.*

**Correctif proposé (une ligne, aucun risque)** : ajouter
`DB_TIMEZONE=Europe/Paris` à `.env.example`, à `.env`, à `backend/.env` et à
`docker-compose.verif.yml`.

⚠️ **Ce que je n'ai PAS vérifié, et que je n'affirme donc pas** : l'état actuel de
la production. Le relevé dont je dispose est celui de l'agent 8, daté du 19/08.
*Je n'ai pas touché à la production, et le mandat ne me le demande pas.*

---

## `X39-017` : la moitié qui manquait est mesurée — et elle **disculpe** les écrans

Le constat de la 3ᵉ passe disait : l'API rend `validation.required` en clair, et
dix écrans lisent `response.data.message` pour l'afficher. Il restait à voir si
un utilisateur lit vraiment cette chaîne. **Deux gestes réels, sur `/tags` :**

**Geste 1 — un nom de 200 signes** (la règle serveur est `max:120`,
`TagsController.php:91`). **Aucune requête n'est partie.** Cause lue :
`TagsManagerPage.tsx:78` duplique la règle côté client — `z.string().max(120,
'120 caractères max')` — **et le message est en français**. *L'écran ne laisse
jamais le serveur répondre. C'est le bon comportement, et il faut le dire.*

**Geste 2 — créer deux fois la même étiquette** (règle serveur **sans miroir
client**) :

| | |
|---|---|
| ce que l'API rend | `409 {"error":"**slug already exists**"}` — en anglais |
| ce que l'écran affiche | **« Slug déjà utilisé »** — en français |

Cause lue : `TagsManagerPage.tsx:131-135` branche explicitement le cas 409 sur un
message français **avant** d'atteindre `extractApiMessage`.

### 🔴 CINQUIÈME FAUX CONSTAT ÉVITÉ

*J'avais lu `TagsManagerPage.tsx:137` — `toast.error(extractApiMessage(err) ?? …)` —
et j'en avais déduit que l'écran afficherait « slug already exists ». **La mesure
dit le contraire.** La lecture d'une seule ligne de code ne vaut pas le geste :
la branche qui compte était quatre lignes plus haut.*

**`X39-017` est donc ramené à sa taille exacte** :

- ✅ **Vrai, et prouvé** : le backend n'a **aucun** répertoire `lang/`, donc toute
  réponse 422 sort en clé brute (`validation.required`), avec le fragment anglais
  « (and 1 more error) ». **C'est un défaut de l'API.**
- ⚠️ **NON prouvé à l'écran** : sur les deux chemins réellement empruntés, aucun
  utilisateur n'a lu cette clé. Les écrans testés protègent, soit en dupliquant la
  règle en français, soit en branchant le code de statut.
- ❓ **Ce qui reste ouvert** : `extractApiMessage` est bien la **voie de repli** de
  dix écrans. Un 422 sur une règle serveur **sans miroir client ni branche de
  statut** afficherait la clé brute. *Ce chemin existe dans le code ; je ne l'ai
  pas atteint. Tant qu'il n'est pas atteint, on n'écrit pas qu'il est emprunté.*

**Sévérité ramenée de S2 à S3** tant que le relais n'est pas vu.

---

## ✅ Deux témoins négatifs de plus

7. **`/tags` duplique ses règles serveur côté client, en français.** Longueur du
   nom, longueur du slug, forme du slug, longueur de la description : les quatre
   règles de `TagsController.php:89-95` ont leur miroir dans `tagSchema`
   (`TagsManagerPage.tsx:77-88`). *C'est exactement ce qu'il faut faire.*
8. **Le conflit de doublon est traité, et en français** (« Slug déjà utilisé »),
   alors que l'API répond en anglais.

---

# 🏁 OÙ ON EN EST, À LA FIN DE CETTE SESSION

**Le chantier des écrans est TERMINÉ.** Les 36 écrans sont ouverts, les deux
questions laissées ouvertes en 3ᵉ passe sont traitées :

| ce qui restait à la 3ᵉ passe | état |
|---|---|
| finir `X39-017` — voir le 422 à l'écran | ✅ **fait** — et il **disculpe** les écrans testés ; le constat est ramené à l'API, sévérité S2 → S3 |
| tester l'hypothèse du sondage accumulé | ✅ **fait** — **réfutée**, par le débit d'appels |

## Bilan chiffré de la journée

- **36 écrans** ouverts à la main (20 aux passes 1-2, 16 à la passe 3)
- **23 constats** `X39-001` à `X39-023`
- **5 faux constats évités** — c'est le chiffre dont je suis le plus sûr
- **8 témoins négatifs** inscrits, dont le verrou 2FA du serveur vérifié au geste
- **1 hypothèse réfutée** par une mesure indépendante

## Ce qui reste, et qui n'est PAS des écrans

1. **Un chemin non atteint**, honnêtement laissé ouvert : un 422 sur une règle
   serveur **sans miroir client ni branche de statut** afficherait la clé brute
   `validation.required`. Le code de ce chemin existe (`extractApiMessage`, repli
   de dix écrans). *Je ne l'ai pas atteint, donc je n'écris pas qu'il est emprunté.*
2. **`X39-023` appelle un correctif d'une ligne** : `DB_TIMEZONE=Europe/Paris`
   dans `.env.example`, `.env`, `backend/.env`, `docker-compose.verif.yml`.
   ⚠️ **Non appliqué** — le mandat n'a pas demandé de correctif dans cette passe.
3. Reprendre `OU-ON-EN-EST.md` §8 à l'**étape 5** (vague du dépôt du site).

## ⚠️ En attente de Will, inchangé

**La PR de `fix/gardes-de-plan-et-c19-010` n'est PAS ouverte**, conformément à la
consigne. Rien n'a été poussé sur un dépôt public.

---

## ⚠️ TROISIÈME PIÈGE DE MESURE PAYÉ — l'hôte se remplit pendant la session

En fin de session, `/up` de la pile de vérification est passé de **0,051 s**
(au début) à **0,58 – 1,27 s**. Un facteur 10 à 25. Le constat tentant était
« la pile se dégrade à l'usage ».

**C'est faux, et le témoin est immédiat** : `docker stats` rend **0,01 % de
processeur** sur `crmverif-api`. *Il ne calcule rien.* Ce qui a changé, c'est
l'hôte : Docker Desktop a relevé tout seul, au fil de la session, `axion-ia-postgres`
(**20 %**), `axion-crm-caddy` (**6 %**), `bookforge-postgres`, `bookforge-redis`,
`axion-ia-mailhog` — et 25 processus Chrome traînaient encore.

**Règle à tenir** : avant d'inscrire la moindre mesure de temps, jouer
`docker stats --no-stream`. Si le conteneur mesuré est au repos, le temps observé
appartient à la machine, pas au produit. *Troisième fois que ce dépôt punit une
mesure de durée prise sans témoin — après les minuteurs bridés de l'onglet
d'arrière-plan et après le gréement dégradé.*

---

# LES PARCOURS DU §11 — passe du 2026-08-23

> Exigence n° 3 du §12, seconde moitié : *« chaque écran ouvert à la main,
> **chaque bouton essayé** »*, plus les 21 parcours nommés au §11.
> Les écrans sont faits ; voici les parcours.

## Parcours 10 — Audiences · 🔴 **`X39-024` (S0)**

> *Énoncé du mandat : « construire avec `neq` **et** `not_in` sur un champ vide,
> comparer l'aperçu au décompte réel, rafraîchir, lister les membres ».*

### 🔴 `X39-024` — l'audience enregistrée n'est PAS celle que l'aperçu a montrée

**La valeur de chaque critère est effacée à l'enregistrement.** Silencieusement,
sans erreur, sans avertissement — et l'écran affiche pourtant le bon nombre juste
avant.

#### Le geste réel, joué sur l'écran

`/audiences/new`, un nom, puis un clic sur la puce **« IT / SaaS »**. Ce que
l'écran a envoyé, capté sur le réseau :

```json
POST /api/v1/audiences
{"name":"P10 batie a l ecran","criteria":{"all":[
   {"field":"sector_main","op":"in","value":["it_saas"]},
   {"field":"prospection_status","op":"in","value":["ready_for_outreach"]}]}}
```

Ce que le serveur a **répondu**, dans la même seconde :

```json
{"criteria":{"all":[{"op":"in","field":"sector_main"},
                    {"op":"in","field":"prospection_status"}]}}
```

**Les deux `value` ont disparu.** Ce n'est pas mon `curl` : c'est l'écran du
produit, et c'est la réponse du serveur qui le montre.

#### Aperçu contre réalité, mesuré trois fois

| critère demandé | ce que l'**aperçu** annonce | ce que l'audience **contient** |
|---|---|---|
| `in` · secteur = IT/SaaS | **2 entreprises** | **0 — personne** |
| `neq` · secteur ≠ BTP | **3 entreprises** | **4 — l'espace de travail ENTIER**, BTP compris |
| `eq` · secteur = BTP | **1 entreprise** (la fiche BTP) | **1 — mais c'est la fiche SANS SECTEUR** |

*Le troisième cas est le plus traître : **le compte est juste, la fiche est
fausse**. Rien à l'écran ne peut alerter.*

Membres réellement inscrits, relevés en base :

```
audience « neq btp »  -> 552100554 it_saas · 111111111 it_saas
                         222222222 btp     · 333333333 (NULL)      = les 4
audience « eq btp »   -> 333333333 (NULL)                          = la mauvaise
```

⚠️ **`in` est l'opérateur de CINQ des huit critères de l'écran** — départements,
régions, tailles, secteurs, statuts (`AudienceBuilderPage.tsx:128-132`). *Le cas
le plus courant est donc : l'utilisateur compose un segment, lit « 2 entreprises »,
enregistre, et l'audience est **vide**.*

#### La cause, lue à la source

Deux chemins, deux lectures du même corps de requête :

| | ce qu'il lit | résultat |
|---|---|---|
| `AudiencesController::preview()` **:145-147** | `$request->input('criteria')` — **l'entrée BRUTE** | ✅ correct |
| `AudiencesController::store()` **:55** | `$request->validated()` | ❌ **mutilé** |

`validated()` ne rend **que** les clés couvertes par une règle. Or
`StoreEmailAudienceRequest.php:28-33` déclare `criteria.all.*.field` et
`criteria.all.*.op` — pour les trois blocs — **et jamais `criteria.*.value`.**

*L'aperçu est honnête. C'est l'enregistrement qui ment.*

#### ✅ Le chemin de MODIFICATION, lui, est correct — et c'est le contournement

`AudiencesController::update()` **:100-104** valide `criteria` **en bloc**
(`['sometimes','required','array']`), sans règles imbriquées : `validate()` rend
donc le tableau entier, valeurs comprises. Mesuré sur la même audience :

| geste | critère enregistré | membre obtenu |
|---|---|---|
| création | `{op:"eq", field:"sector_main"}` | Fiche **SANS secteur** ❌ |
| **modification**, même charge utile | `{op:"eq", field:"sector_main", value:"btp"}` | Fiche **secteur BTP** ✅ |

*Deux gestes sur le même objet, deux comportements.* **Contournement immédiat
pour l'utilisateur** : après avoir créé une audience, la rouvrir et l'enregistrer
une seconde fois — la modification répare ce que la création a cassé.

#### Ce constat ÉTEND `D26-001`, il ne le réinvente pas

*Doctrine règle 8.* L'agent 26 avait nommé la cause :
`11_GRILLES/agent-26_formulaires.md:43` — *« `StoreEmailAudienceRequest`
(champ/op) — **`value` jamais validée** »* — et son correctif dit déjà
« Ajouter `criteria.*.value` aux règles ». **Statut : ouvert.**

Ce que la présente mesure ajoute, et qui change la sévérité :

1. `D26-001` décrit un critère **mal formé** qui élargit l'audience. Ici l'entrée
   est **parfaitement bien formée** — c'est le produit qui la mutile.
2. `D26-001` dit que « l'aperçu affiche le compte du workspace entier ». La
   mesure montre l'**inverse** : l'aperçu est **juste**, c'est l'audience
   enregistrée qui est fausse. *Le chiffre sur lequel l'utilisateur décide
   d'envoyer n'est pas celui qu'il obtiendra.*
3. Le mode de défaillance **dépend de l'opérateur** — personne, tout le monde, ou
   les mauvaises fiches. Aucun des trois n'est annoncé.

**Ce service décide à qui part un courriel.** Une audience « BTP » qui contient
tout l'espace de travail est un envoi de masse non voulu ; une audience vide est
une campagne qui ne part pas.

#### Correctif proposé — non appliqué

Trois lignes dans `StoreEmailAudienceRequest`, une par bloc :
`'criteria.all.*.value' => ['sometimes']`, idem `any` et `not`. Une règle
`sometimes` sans contrainte suffit à faire **survivre** la clé à `validated()`.
*Et faire valider `preview` par la même règle que `store`, pour que les deux
chemins ne puissent plus diverger.*

⚠️ **Non appliqué : `AudienceBuilderService` est au cœur des 91 correctifs de la
PR #194, verte et en attente de fusion.** Y toucher maintenant rouvrirait sa CI.
*À poser juste après la fusion.*

---

## ✅ CE QUI A ÉTÉ VÉRIFIÉ ET QUI N'EST **PAS** UN DÉFAUT

Le mandat soupçonnait la sémantique du **champ vide**. Elle est **juste**.

Sur 4 fiches — 2 en `it_saas`, 1 en `btp`, **1 à NULL** — les aperçus rendent :

| critère | rendu | attendu si NULL est bien traité |
|---|---|---|
| `neq` secteur ≠ btp | **3** | 3 ✅ (les 2 IT **plus** la NULL) |
| `not_in` secteur ∉ [btp] | **3** | 3 ✅ |
| `not(neq)` — le complément | **1** | 1 ✅ |

**3 + 1 = 4 = le total.** Le complément partitionne exactement : les deux
évaluateurs s'accordent, et la fiche à NULL n'est perdue par aucun des deux.

Recoupé dans le code : `AudienceBuilderService.php:591` et `:595` posent
explicitement `->orWhereNull($field)` sur `neq` et `not_in`, et la constante
`NULL_SENSITIVE_OPS` (`:472`) les exclut à dessein, avec la démonstration écrite
au-dessus. *Le piège que le mandat nommait a bien été fermé — c'est un autre qui
était ouvert, en amont, dans la validation.*

---

## Parcours 17 — Recherche globale (⌘K) · 🔴 **`X39-025` (S2)**

> *Énoncé : « par nom, e-mail, téléphone, société, avec fautes et accents ».*

### 🔴 Taper les accents que le produit AFFICHE ne trouve rien

Fiche semée : **« Société Générale de Vérification »**, telle qu'elle s'affiche à
l'écran. Mesuré **sur l'API**, pour écarter tout artefact d'interface :

| ce qu'on tape | résultat |
|---|---|
| `Société` | ❌ **aucun résultat** |
| `Générale` | ❌ **aucun résultat** |
| `Vérification` | ❌ **aucun résultat** |
| `Societe` | ✅ trouvé |
| `societe` | ✅ trouvé |
| `SOCIETE` | ✅ trouvé |
| `Societe Generale` | ✅ trouvé |

*La casse est gérée. Les accents ne le sont pas — et c'est un CRM français.*
**L'utilisateur lit « Société » à l'écran, le recopie, et n'obtient rien.**

**Cause lue à la source.** `GlobalSearchController.php:126` compare bien contre
`denomination_normalized` — une colonne calculée où les accents **sont retirés**
(`normalize_name`, avec son index trigramme). Mais le motif, lui, ne l'est pas :

```php
// GlobalSearchController.php:220-225
private function motif(string $terme): string {
    $echappe = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $terme);
    return '%' . $echappe . '%';     //  ← échappe, mais NE NORMALISE JAMAIS
}
```

*Une colonne sans accents comparée à un motif qui en porte : `'%Société%'` ne
peut, par construction, jamais rencontrer `societe generale de verification`.*
Le travail de normalisation a été fait d'un seul côté.

**Correctif** : appliquer `normalize_name()` au terme, comme la colonne le fait
à la donnée — soit `whereRaw('denomination_normalized ILIKE normalize_name(?)')`.

### 🔴 Le TÉLÉPHONE n'est jamais cherché

Contact semé : Jean Dupont, `jean.dupont@exemple.test`, **`0102030405`**.

| ce qu'on tape | résultat |
|---|---|
| `Dupont` (nom) | ✅ trouvé |
| `jean.dupont@exemple.test` (courriel) | ✅ trouvé |
| `0102030405` | ❌ **rien** |
| `01 02 03 04 05` | ❌ rien |
| `+33102030405` | ❌ rien |
| `0102` | ❌ rien |

Ce n'est pas une affaire de format : **`phone` n'apparaît nulle part dans
`GlobalSearchController.php`.** Les contacts sont cherchés sur `last_name`,
`first_name` et `email` (`:155-157`), un point c'est tout.

*Le §11 nomme « téléphone » explicitement. Un commercial qui reçoit un appel et
tape le numéro pour savoir qui l'appelle n'obtient rien.*

✅ **Ce qui marche, et mérite d'être dit** : le nom, le prénom, le courriel, la
dénomination et le **SIREN** (`552100554` → trouvé). Les résultats sont groupés
par famille (ENTREPRISES / CONTACTS / TAGS), la palette annonce ses raccourcis
(`⌘K pour ouvrir / fermer`, `↑↓ naviguer`, `↵ ouvrir`), et elle s'ouvre bien au
clavier.

---

## Parcours 18 — Sélecteur d'espace de travail · 🔴 **`X39-026` (S2)**

> *Énoncé : « le changement se voit-il partout ? la teinte ? les données ? »*

**La question ne peut pas être posée : il n'y a aucun moyen de changer d'espace.**

Le menu s'ouvre, et il contient exactement trois entrées :

```
WE Workspace e43437          ← le seul espace listé, par FRAGMENT D'UUID
Créer un workspace
Gérer les workspaces
```

Chacune des deux actions cliquée, en repartant du menu à chaque fois, avec le
réseau sous surveillance :

| entrée | URL après | dialogue | message | appels API |
|---|---|---|---|---|
| **Créer un workspace** | `/` — inchangée | **aucun** | **aucun** | aucun *(hors le sondage du tableau de bord)* |
| **Gérer les workspaces** | `/` — inchangée | **aucun** | **aucun** | aucun *(idem)* |

**Les deux entrées sont inertes.** Elles ont l'apparence d'actions, elles ne
déclenchent rien — ni écran, ni requête, ni message d'erreur. Un utilisateur
clique, et rien ne se passe : il ne peut même pas savoir que c'est cassé.

Cohérent avec ce qui avait été relevé à l'écran 5 : **il n'existe aucune route
`/api/v1/workspaces` au pluriel**. Le sélecteur n'a rien à proposer, et ses
actions n'ont nulle part où aller.

⚠️ **`X39-004` est confirmé jusque DANS le menu** : l'espace courant y est
nommé « Workspace e43437 », le fragment d'UUID — alors que `GET /api/v1/workspace`
rend `{"name":"Axion-IA"}` en 200.

---

## Parcours 21 — 375 px, clavier seul, mode sombre

### ✅ 375 px — **rien à signaler, et c'est mesuré**

Huit écrans ouverts en 375 × 812, en mode tactile :

| mesure | résultat |
|---|---|
| `documentElement.scrollWidth` | **375 px sur les 8** |
| la page défile-t-elle horizontalement ? | **non, sur aucune** |

Les larges tableaux (`/audit-logs` 1 104 px de contenu, `/users` 1 020 px,
`/companies` 778 px) vivent tous dans un conteneur `div.overflow-x-auto` avec
`overflow-x: auto` **mesuré**, pour 295 px visibles. *C'est exactement le motif
correct : le tableau défile dans sa boîte, le corps de page ne bouge pas.*

*J'ai failli inscrire un constat sur les « 342 éléments qui débordent » de
`/audit-logs`. Vérification faite, ils débordent **à l'intérieur d'une boîte
prévue pour ça**.*

### ✅ Au clavier seul — **rien à signaler non plus**

22 tabulations depuis le tableau de bord :

- **premier élément focusable : « Aller au contenu »** — le lien d'évitement
- **0 élément sur 22 sans contour de focus visible** (contour non nul ou ombre
  portée, mesuré sur le style calculé)

### ⚠️ Mode sombre — **MESURE ÉCARTÉE, mon instrument est faux**

Mon calcul de contraste annonçait 78 textes sous 4,5:1 sur `/companies`. **Il est
inutilisable, et le constat n'est pas inscrit.** Deux défauts de l'instrument :

1. il lit les couleurs par `match(/\d+/g)` — ce qui n'a **aucun sens** sur
   `oklch(0.279 0.041 260.031)` ou `oklab(0.208 -0.0031 -0.0419 / 0.6)`, les
   formats réellement employés par la feuille de style ;
2. il compte comme fautifs les titres dont la couleur est `rgba(0,0,0,0)` — un
   texte **en dégradé** (`background-clip: text`), dont la couleur déclarée n'est
   pas celle qu'on voit. « Tableau de bord », « Entreprises », « Audiences » et
   « Paramètres » tombent tous dans ce cas.

*Sixième piège de mesure de la journée, et le premier que je me suis tendu à
moi-même en écrivant l'outil.* **Ce qu'il faudrait** : un calcul qui convertit
`oklch`/`oklab` en sRGB et qui échantillonne la couleur **rendue** (capture
d'écran) plutôt que la couleur déclarée. À reprendre — le mode sombre reste
**non mesuré**, et il ne faut pas le compter comme vérifié.

---

## Parcours 14 — Utilisateurs et rôles · 🔴 **`X39-027` (S1)**, `X39-028`, `X39-029`

> *Énoncé : « créer un compte de rôle limité, **se connecter avec**, tenter
> d'atteindre ce qui est interdit ».*

### 🔴 `X39-027` — le produit n'a AUCUN moyen d'ajouter un utilisateur (S1)

**Le parcours s'arrête à son premier geste.** Et ce n'est pas un détail
d'ergonomie : c'est un CRM multi-utilisateurs qui ne peut compter qu'un compte.

Le geste, joué à l'écran : `/users` → **« Inviter un utilisateur »**. Le dialogue
qui s'ouvre est **complet et soigné** :

> Inviter un utilisateur — *Un email d'invitation sera envoyé pour rejoindre le
> workspace.* · **Email** · **Rôle** : Lecteur (lecture seule) / Opérateur
> (édition) / Admin (gestion équipe) / Propriétaire (owner) · **Envoyer
> l'invitation**

Rempli, envoyé. Ce que le serveur répond, capté sur le réseau :

```
POST /api/v1/users/invite → 405
« The POST method is not supported for route api/v1/users/invite.
  Supported methods: PUT, DELETE. »
```

*Le 405 vient de ce que `invite` est avalé comme un identifiant par
`/users/{user}`.* Ce que l'utilisateur lit, lui : **« Impossible d'envoyer
l'invitation »**, le dialogue reste ouvert, aucune explication.

**Il n'existe aucun autre chemin.** Mesuré, un par un :

| chemin | résultat |
|---|---|
| `POST /api/v1/users/invite` | ❌ **la route n'existe pas** (`grep` sur `routes/api.php` : rien) |
| `POST /api/v1/users` | ❌ **501** — `{"error":"not_implemented","message":"Endpoint à implémenter en Sprint 3."}` |
| une route d'invitation | ❌ aucune (`invitations` est une table **morte** : seuls les services RGPD la citent, pour l'effacer) |
| une commande Artisan | ❌ aucune |
| un seeder | ❌ seul `OwnerUserSeeder`, qui crée **le** propriétaire |

**Conséquence :** les **4 rôles** et **16 permissions** du modèle RBAC — dont
l'écran se vante en titre (« 4 rôles RBAC : owner / admin / operator / viewer »)
— **ne peuvent jamais être attribués à personne.** Le système de droits est
complet, testé, et inatteignable.

⚠️ **Ce défaut est connu du code mais n'a JAMAIS été inscrit au registre.**
`UsersPage.tsx:76-84` le documente noir sur blanc — *« `grep -rn invite
backend/routes` ne rend RIEN […] Le point d'entrée manquant est un défaut
DISTINCT, hors de ce constat : il n'est pas refermé ici »*. Et
`11_GRILLES/ecrans.md:204` **liste `POST /users/invite`** dans les routes de
l'écran, comme si elle existait. *Un auditeur l'a vu, l'a écrit dans le code, et
personne ne l'a porté au registre.*

*À ne pas confondre avec `I48-001`, qui porte sur les fiches **personnes**.*

---

### Le parcours a quand même été joué — le compte créé EN BASE

Puisque le produit n'offre aucun chemin, le compte `viewer@verif.localhost` a
été inséré **directement dans la base jetable**, avec le rôle `viewer` sur
l'espace de travail. *C'est écrit ici pour qu'on ne croie pas le contraire.*

Ses **trois** permissions, par le seeder : `companies.view`, `llm.view_usage`,
`rgpd.view`. Tout le reste doit être refusé.

### ✅ CE QUI TIENT — et c'est la meilleure nouvelle de la journée

**① Aucune écriture ne passe. Cinq sur cinq.**

| geste tenté par le lecteur | réponse |
|---|---|
| `POST /companies` — créer une fiche | **403** |
| `POST /tags` — créer un tag | **403** |
| `POST /audiences` — créer une audience | **403** |
| `DELETE /companies/2` — supprimer une fiche | **403** |
| `POST /users` — créer un utilisateur | **403** |

**② `audit.view` est exigé, et l'écran gère le refus proprement.**
`GET /audit-logs` → **403**, et l'écran affiche **« Vous n'avez pas… »** au lieu
de planter ou de mentir. *C'est exactement ce qu'on veut voir.*

**③ 🔑 LE MASQUAGE DES COORDONNÉES FONCTIONNE.** Le lecteur n'a pas
`contacts.view_pii`, et il ne voit rien en clair :

```
GET /contacts    →  Jean Dupont | 'j***@exemple.test' | '0102****05'
GET /companies/5 →  email_generic = null | phone = null
```

*Sur un CRM qui porte 1 319 567 personnes physiques, c'est le contrôle qui
compte le plus. Il tient.*

### 🔴 `X39-028` — `GET /users` n'a AUCUNE garde : un lecteur liste tous les comptes (S2)

Les trois autres routes utilisateur portent `->middleware('permission:users.manage')`
(`routes/api.php:114,116,118`). **`GET /users` (ligne 112) n'en porte aucune.**

Mesuré : le lecteur obtient **200** et lit l'écran complet — nom, **adresse
e-mail**, rôles, état de la 2FA, dernière connexion de chaque compte.

⚠️ **Et l'interface est écrite pour un 403 qui ne vient jamais.** Le commentaire
de `UsersPage.tsx` dit : *« `/users` est une route ADMIN : un opérateur sans le
rôle reçoit 403, et c'est le cas NOMINAL, pas l'exception. »* **C'est faux, et
c'est mesurable.** Le code défensif écrit pour ce 403 ne s'exécute jamais.

### `X39-029` — « Lecteur (lecture seule) » lit l'INTÉGRALITÉ du CRM (S2)

Sur ~117 routes, **quatre** exigent une permission de lecture :
`companies.view` (×1), `rgpd.view` (×1), `audit.view` (×2). Tout le reste est
ouvert à n'importe quel compte authentifié.

Le lecteur obtient **200** sur : `/users`, `/contacts`, `/audiences`,
`/campaigns`, `/scraper-runs`, `/tags`, `/media`, `/workspace`,
`/dashboard/stats`, `/crm/contacts-hub`.

*Les trois permissions de lecture déclarées pour ce rôle ne veulent donc rien
dire : il lirait tout sans elles.* Le tableau de bord lui annonce
**« 5 entreprises »**, le hub contacts **« 5 prospects »**.

**Ce n'est peut-être pas un défaut** — « lecture seule » peut vouloir dire « lit
tout, n'écrit rien », et le masquage des coordonnées protège l'essentiel.
*Mais alors les permissions `companies.view` / `llm.view_usage` / `rgpd.view`
sont décoratives, et l'écran `/users` ne devrait pas être ouvert.*
**Arbitrage à Will**, pas correctif mécanique.

### `D22-006` — confirmé à l'écran, avec des exemples

Le constat existant (S2, ouvert) dit : *« 33 écrans sur 37 n'interrogent jamais
rôle ni permission »*. Mesuré, connecté **en tant que lecteur** :

| écran | ce qu'on lui offre alors qu'il n'en a pas le droit |
|---|---|
| `/users` | **« Inviter un utilisateur »** |
| `/companies` | **« Importer »**, **« Exporter »**, **« Lancer scraping → »** — il n'a ni `data.export` ni `scraping.run` |
| `/settings` | **« Enregistrer »** |

✅ *Et deux écrans ne lui offrent RIEN* : `/` et `/console/contacts`. Le constat
n'est donc pas uniforme — c'est une raison de plus de le mesurer écran par écran
plutôt que de le déclarer en bloc.

---

## Parcours 12 — RGPD de bout en bout · ✅ **le produit tient**

> *Énoncé : « déposer une demande, la traiter, exporter par jeton, effacer,
> vérifier la propagation vers le site et l'anti-réinsertion ».*

**Ce parcours rejoue AU GESTE trois fermetures que le registre ne validait que
par lecture de diff** — `B15-003` (l'export ne couvrait que 4 tables sur 31),
`B15-006` (l'effacement laissait adresse et téléphone dans six tables),
`B15-010` (les routes RGPD n'exigeaient aucune permission).

### ✅ La chaîne complète, jouée sur une personne réelle de la base

| étape | geste | résultat |
|---|---|---|
| 1 | `POST /rgpd/requests` type `access` | **201**, `status: pending` |
| 2 | `POST /rgpd/requests/{id}/process` | **200**, `status: done`, jeton émis, `export_expires_at` à **J+7** |
| 3 | `GET /rgpd/export/{jeton}` **sans aucune session** | **200** ✅ |
| 4 | `POST /rgpd/requests` type `erasure` puis `process` | **200**, `deleted: {contacts: 1, …}` |
| 5 | la personne en base | **0 ligne** ✅ |

### 🔑 `B15-003` est VÉRIFIÉ AU GESTE — et il vaut mieux que sa fermeture

L'export livré à la personne concernée porte **21 collections**, pas 4 :

```
contacts · candidates · email_validations · rgpd_requests · magic_links_history
activities · journalists · media_contacts · health_practitioners
email_messages · email_verification_logs · notifications · oppositions
suppressions_techniques · desabonnements · listes_ne_pas_appeler
comptes_crm · invitations_recues · reinitialisations_mot_de_passe
sessions_ouvertes · signaux_envoyes_au_site
```

*Et les noms sont en français, pour une pièce que lit une personne concernée.*

### ✅ Le jeton d'export est bien conçu — vérifié, pas supposé

| | mesure |
|---|---|
| jeton brut remis | **48 signes**, rendu dans `result.token` |
| ce qui est stocké | `sha256(jeton)` — **le brut n'est jamais en base** |
| expiration | **7 jours**, portée par `export_expires_at` |
| l'archive elle-même | chiffrée sur disque (`Crypt::encryptString`) |
| jeton inventé par un tiers | **404** `invalid_or_expired_token` |
| session requise | **aucune** — c'est le point : la personne concernée n'a pas de compte |

### 🔑 L'ANTI-RÉINSERTION EXISTE, ET ELLE COUVRE LES DEUX UNIVERS

L'effacement écrit **deux** lignes d'opposition — une par univers :

```
source=gdpr_erasure  reason=gdpr_art17  scope=business  email_hash=95289698b544…
source=gdpr_erasure  reason=gdpr_art17  scope=vivier    email_hash=95289698b544…
```

**L'adresse n'y figure PAS en clair** — seul le hash. *C'est exactement la bonne
conception : on bloque quelqu'un sans conserver son adresse.*

Et le hash correspond, vérifié à l'octet :

```
sha256('jean.dupont@exemple.test') = 95289698b544db25f9a4a74483189186589ea40df3be3a9e6ab2af18ee8facca
stocké dans opt_out                = 95289698b544db25f9a4a74483189186589ea40df3be3a9e6ab2af18ee8facca
```

Les deux univers sont gardés, chacun par son service :

| univers | garde | scope consulté |
|---|---|---|
| business | `ScrapedRecordIngestService.php:452-460` → `return 'opted_out'` | `'business'` |
| vivier | `SiteSyncIngestService::hasOpposed($event, $scope)` | `'vivier'` |

### ✅ La preuve de conformité SURVIT à l'effacement

L'effacement supprime la ligne `rgpd_requests` de la personne — ce qui est
cohérent (elle porte son adresse). **Mais la trace reste dans le journal
d'audit**, chaînée :

```
POST api/v1/rgpd/requests            201
POST api/v1/rgpd/requests/5/process  200
GET  api/v1/rgpd/export/{token}      200   ← même le TÉLÉCHARGEMENT est tracé
```

*On peut donc démontrer qu'on a honoré la demande, sans conserver la personne.*

### ⚠️ La propagation vers le site n'est PAS testable sur cette pile

`POST /api/internal/site-sync/gdpr` — **et non `/api/v1/…`**, la route vit sous
le préfixe `internal`, sans version.

| geste | réponse |
|---|---|
| sans signature | **401** `bad_signature` |
| avec une signature inventée | **401** `bad_signature` |

`SITE_SYNC_HMAC_SECRET` est **vide** et `CRM_INGEST_ENABLED=false` : la porte est
fermée par configuration, et **elle refuse correctement** — 401, pas 500, pas
une acceptation silencieuse.

*Je n'ai donc pas mesuré la propagation elle-même, et je ne fabrique pas un
secret pour faire semblant. **Ce point du parcours 12 reste ouvert**, et il
demande une pile où le canal est armé.*

---

### ⚠️ TROIS PIÈGES DE MESURE PAYÉS SUR CE SEUL PARCOURS — tous de ma main

*Aucun n'était un défaut du produit. Tous auraient pu être écrits comme tels.*

1. **« L'export par jeton rend 404 »** — je lisais le jeton dans la **colonne**
   `export_token`, qui contient le **hash**. Le jeton brut est dans
   `result.token` de la réponse. *Le produit hache au repos, ce qui est juste ;
   c'est moi qui présentais le hash comme s'il était le jeton.*
2. **« La propagation rend 404 »** — je tapais `/api/v1/site-sync/gdpr`. La
   route vit sous `internal`, **sans `/v1`**. `php artisan route:list` l'a dit
   en une commande, après que j'aie conclu deux fois.
3. **« L'anti-réinsertion ne marche pas »** — j'avais réinséré la personne par
   un `INSERT` SQL direct. **Un INSERT contourne par construction toute garde
   applicative** : la mesure ne valait rien. La vraie garde vit dans le service
   d'ingestion, et elle tient.

> **Règle à tenir** : avant de conclure qu'une route ne répond pas, demander ses
> routes au produit (`route:list`). Et ne jamais tester une garde applicative
> par un geste qui court-circuite l'application.

---

### ✅ Parcours 12, le point qui manquait : LA PROPAGATION VERS LE SITE EST MESURÉE

Le canal a été **armé sur le banc** (`docker-compose.verif.banc.yml`, surcharge
qui n'est jamais déployée) : `SITE_SYNC_HMAC_SECRET` + `CRM_INGEST_ENABLED=true`.

⚠️ **Les variables du CONTENEUR priment sur le `.env`.** Éditer `.env` dans le
conteneur ne suffit pas — Laravel ne remplace pas une variable d'environnement
déjà posée. Il faut **recréer** le service avec la surcharge.

**Le geste complet, signature HMAC valide** :

```
POST /api/internal/site-sync/gdpr
X-Site-Timestamp: <horodatage>   X-Site-Signature: hmac_sha256(secret, "<ts>.<corps>")
{"action":"erase","person_key":"<sha256 du courriel>","email":"…","scope":"both"}

→ 200 {"ok":true,"action":"erase","result":{
     "deleted":{"business":{"contacts":1,…},"vivier":{…}},
     "opt_out_scopes":["business","vivier"]}}
```

| vérification | résultat |
|---|---|
| la personne disparaît du CRM | **0 contact restant** ✅ |
| l'opposition est posée | **2 lignes**, `business` **et** `vivier`, adresse en **hash seul** ✅ |
| signature falsifiée d'un octet | **401** `bad_signature` ✅ |
| horodatage vieux de 4 000 s | **401** ✅ *(fenêtre de 300 s)* |
| action inconnue (`erasure` au lieu de `erase`) | **422**, message explicite ✅ |
| `person_key` absente ou malformée | **422**, message explicite ✅ |

**Le parcours 12 est donc COMPLET.** Toute la chaîne RGPD tient, des deux côtés.

#### `X39-030` (S3) — l'effacement venu du site n'inscrit pas sa base légale

Les deux chemins n'écrivent pas la même chose dans `opt_out` :

| origine | `source` | `reason` |
|---|---|---|
| demande traitée **dans le CRM** | `gdpr_erasure` | **`gdpr_art17`** |
| demande venue **du site** | `gdpr_erasure_bisystem` | **`NULL`** |

*La colonne existe pour dire POURQUOI l'opposition a été posée. Sur le chemin
qui compte le plus — la personne qui exerce son droit depuis le site public —
elle reste vide.* Sans gravité immédiate (le blocage fonctionne), mais c'est la
traçabilité de la base légale qui manque.

### ⚠️ DOUZIÈME PIÈGE DE MESURE — une concaténation SQL qui avale ses lignes

J'ai failli inscrire un **S1** : « l'effacement venu du site ne pose aucune
opposition, la personne revient au prochain balayage ». **Faux.**

Ma requête faisait `'... motif='||reason`. Sur ces lignes, `reason` est **NULL**
— et en SQL, une concaténation avec NULL vaut **NULL** : les deux lignes se sont
affichées **vides**, et j'ai lu « elles n'existent pas ».

`select ... from opt_out` sans concaténation les montre toutes les quatre.

> **Règle** : ne jamais conclure à l'absence d'une ligne à partir d'une requête
> qui **concatène** des colonnes possiblement nulles. Lister d'abord, formater
> ensuite — ou `coalesce()` partout.

---

# LES PARCOURS RESTANTS DU §11 — passe du 2026-08-23, soirée

> Parcours **2, 3, 4, 5, 6, 7, 8, 9, 11, 13, 15, 16, 20**, joués à l'API et à
> l'écran. Le canal site→CRM a été **armé sur le banc** pour rendre mesurable ce
> qui ne l'était pas (`docker-compose.verif.banc.yml`, jamais déployé).

## 🔴 `X39-034` (S1) — les réglages ne peuvent PAS être enregistrés

> *Parcours 15 : « chaque onglet, chaque champ, sauvegarde, **effet réel**, annulation ».*

Geste réel : `/settings`, changer le nom de l'espace de travail, cliquer
**« Enregistrer »**. Ce que l'utilisateur lit :

> **Modifications non enregistrées — Endpoint à implémenter en Sprint 3. — code HTTP 501**

`PUT /api/v1/workspace` → **501** `{"error":"not_implemented"}`. Le nom en base
ne bouge pas.

**Deux défauts dans une seule phrase :**

1. **La sauvegarde n'existe pas.** L'écran de réglages porte quatre onglets
   (Workspace, Intégrations, Observabilité, Apparence) et un bouton
   « Enregistrer » qui ne peut rien enregistrer.
2. **Le message montré est celui du développeur.** « Endpoint à implémenter en
   Sprint 3 » s'adresse à quelqu'un qui lit un plan de sprints, pas au dirigeant
   qui renomme son entreprise. Famille `X39-007`.

✅ **À porter au crédit de l'écran** : il **ne ment pas**. Il dit « Modifications
non enregistrées » et donne le code HTTP — de quoi appeler le support. C'est le
message relayé qui est brut, pas la conduite de l'écran.

## 🔴 `X39-035` (S2) — une chaîne d'audit VIDE se déclare VALIDE

> *Parcours 13 : « journaux d'audit + **vérification de la chaîne** ».*

Mesuré sur une chaîne neuve, secret armé, en trois temps :

| état de la chaîne | `verify-chain` rend |
|---|---|
| **intacte**, 5 lignes | `{"valid":true,"verifiable":true}` ✅ |
| **une ligne du milieu altérée** (`update … set path='/falsifie'`) | `{"valid":false}` ✅ **la falsification est vue** |
| **VIDÉE** (`delete from audit_logs`) | **`{"valid":true,"verifiable":true}`** 🔴 |

**Effacer le journal ENTIER rend un verdict VERT.** Or c'est exactement ce
qu'une chaîne cryptographique existe pour rendre impossible : celui qui efface
tout obtient le même « chaîne valide » que celui qui n'a rien touché.

*Ce constat ÉTEND `B16-002`* (S0, ouvert — « supprimer la dernière ligne du
journal ne rompt pas la chaîne »). Le registre parle d'**une** ligne ; la mesure
montre que **la totalité** passe aussi.

⚠️ Second point, plus discret : quand `valid` vaut `false`, **`raison` vaut
`null`**. La vérification dit « c'est cassé » sans dire **où**. Pour une pièce
qu'on produit à un contrôle, c'est peu.

## 🔴 `X39-031` (S2) — aucun compteur du tableau de bord n'est cliquable

> *Parcours 2 : « chaque compteur **cliqué**, chaque graphe, chaque lien ».*

Cinq compteurs mesurés, **cinq non cliquables** :

```
Total entreprises 5 · Enrichies 24h 5 · Nouvelles 7j 35
Qualité moyenne 0/100 · Couverture Top 5 départements
```

**Un seul lien interne dans tout l'écran** : « 🏢 Explore tes entreprises → »
vers `/companies`.

*Un tableau de bord annonce « 35 nouvelles fiches sur 7 jours » et il n'existe
aucun moyen de les voir.* Le chiffre est un cul-de-sac.

## 🔴 `X39-032` (S2) — le hub contacts n'a ni sélection multiple, ni actions de masse, ni export

> *Parcours 3 : « recherche, filtres, tri, pagination, **sélection multiple**,
> **actions de masse** (`POST /bulk`), **export** ».*

| ce que le §11 demande | mesuré à l'écran |
|---|---|
| recherche | ✅ « Nom d'entreprise, SIREN, personne… » |
| filtres | ✅ pays, statut de prospection, **9 onglets par type** |
| tri par colonne | ❌ aucun en-tête triable |
| pagination | ❌ absente |
| **sélection multiple** | ❌ **0 case à cocher** |
| **actions de masse** | ❌ aucune |
| **export** | ❌ aucun bouton |

Recoupé dans le code : `ContactsHubPage.tsx` ne contient **aucune** occurrence
de `checkbox`, `bulk` ou `Exporter`.

*L'écran où un commercial passe sa journée sait chercher et filtrer, mais ne sait
rien faire de ce qu'il a trouvé.*

## 🔴 `X39-033` (S2) — la fiche 360° est strictement EN LECTURE SEULE

> *Parcours 4 : « timeline, pagination, liens croisés, **modification, note,
> tâche, tag** — comparée à l'anatomie exigée au §1.5 du CDC ».*

Mesuré sur une personne **créée par le produit** (voir le témoin ci-dessous) :

| anatomie §1.5 / parcours 4 | présent ? |
|---|---|
| bandeau d'identité | ✅ nom, courriel, entreprise |
| timeline | ✅ horodatée, typée, sourcée |
| les deux univers | ✅ « Business : Fiche présente » / « Vivier : Aucune fiche » |
| **précédent / suivant** | ❌ **absent** |
| **ajouter une note** | ❌ |
| **créer une tâche** | ❌ |
| **poser un tag** | ❌ |
| **modifier** | ❌ |

Recoupé dans le code : `PersonTimelinePage.tsx` contient **0 `<Button>` et
0 `onClick`**. La pièce centrale du CDC ne permet aucun geste.

---

## ✅ CE QUI TIENT — et il y en a beaucoup

### La machine à états des campagnes (parcours 9)

Une campagne **annulée** refuse les gestes suivants, en 422, avec un message
français explicite et **sans changer de statut** :

```
POST /campaigns/1/start  → 422 « Impossible de démarrer une campagne au statut 'cancelled'. »
POST /campaigns/1/resume → 422 « Impossible de reprendre une campagne au statut 'cancelled'. »
```

Le cycle complet `start → pause → resume → cancel` répond 200 à chaque étape
légitime. *C'est une vraie machine à états, pas une suite de boutons.*

### La chaîne d'audit DÉTECTE la falsification (parcours 13)

Une ligne du milieu modifiée par `UPDATE` direct en base → **`valid:false`**.
*C'est la propriété essentielle, et elle est là.*

### Le cycle de vie des tags (parcours 11)

`créer` (201) → `renommer` (200) → `lister` (200) → `supprimer` (200 `{"ok":true}`).
Le doublon est refusé en 409 avec un message français (déjà mesuré).

### Les exports CSV (parcours 6 et 7)

`/companies/export`, `/media/export`, `/journalists/export` rendent tous **200**
avec un CSV **en-têtes françaises accentuées** :

```
SIREN,Dénomination,Enseigne,NAF,Taille,Département,Ville,Email,"Confiance email",Tél…
Prénom,Nom,Rôle,Rubrique,Email,Téléphone,Média,Opt-out,Source
```

### L'étanchéité du vivier (parcours 5)

« Univers vivier candidats non accessible — **L'accès au vivier suppose d'être
membre de cet univers. Demandez à un administrateur de vous y rattacher.** »
*Un refus qui dit quoi faire ensuite.*

### L'observabilité (parcours 16)

`GET /observability/summary` → 200, avec des mesures réelles : erreurs de
cascade 24 h, quota Hunter (utilisé / plafond souple / pourcentage), Google Places.

### La couverture France (parcours 8)

`POST /coverage/launch` → **200 `{"queued":true}`**, `POST /coverage/enrich` →
200. *(Le champ s'appelle `department`, pas `department_code`.)*

### 🔑 L'ingestion du site remplit `person_key`, et la fiche 360° la retrouve

Un `form_submission` signé HMAC, envoyé sur `/api/internal/site-sync` :

```
→ 200 {"ok":true,"result":{"status":"created","subject_type":"company",
       "subject_id":1,"activity_id":1,"tags":["svc:audit"]}}
```

La personne apparaît en base **avec sa `person_key`**, et
`/console/personnes/{clé}` affiche son nom, son identité, son univers et sa
timeline. *La chaîne site → CRM → fiche 360° fonctionne de bout en bout.*

---

## ⚠️ DEUX PIÈGES DE MESURE PAYÉS SUR CE LOT — encore de ma main

### Le hub contacts « n'a pas de recherche »

Faux. Mon sélecteur cherchait `placeholder*="echerch"` ; le champ dit
**« Nom d'entreprise, SIREN, personne… »**. *Un sélecteur qui suppose le libellé
mesure le libellé, pas la fonction.*

### « La fiche 360° ne retrouve pas une personne qui existe »

**J'allais l'inscrire en S1.** Les contacts que j'avais semés par `INSERT` SQL
n'avaient **pas de `person_key`** — la colonne est ordinaire, remplie par
`ContactUpserter` et `SiteSyncIngestService`, et une commande de rattrapage
existe (`CrmRemplirClePersonne`) pour l'existant.

*Un `INSERT` direct court-circuite l'application : c'est le TROISIÈME constat que
ce geste a failli me faire écrire aujourd'hui.* Créée par le vrai canal, la
personne est retrouvée immédiatement.

> **Règle, désormais tenue** : on ne sème une donnée d'essai par SQL que pour
> mesurer une LECTURE. Dès qu'on veut éprouver une garde, une clé dérivée ou une
> propagation, la donnée doit entrer **par le chemin du produit**.

---

## Parcours 1 — la chaîne d'authentification · ✅ **elle tient**

> *« Connexion → 2FA → première configuration → déconnexion → **session expirée**
> → lien magique → mot de passe oublié ».*

| écran | `h1` | champs nommés | validation à vide | adresse malformée |
|---|---|---|---|---|
| `/login` | Connexion | **3/3** ✅ | « Veuillez renseigner ce champ. » | « Veuillez inclure "@" dans l'adresse e-mail… » |
| `/magic-link` | Recevoir un lien magique | **1/1** ✅ | idem | idem |
| `/password-reset` | Réinitialiser le mot de passe | **1/1** ✅ | idem | idem |

*Messages en français, pris en charge nativement par le navigateur.*

### 🔑 La session expirée est traitée proprement

Cookie de session **fabriqué et mort**, puis `/companies` :

```
→ url = /login   ·   h1 = « Connexion »
```

**Pas d'écran blanc, pas de 500, pas de boucle.** L'utilisateur est ramené là où
il peut agir. *C'est exactement ce que le parcours demande de vérifier, et c'est
tenu.*

---

## Parcours 19 — la visite guidée · 🔴 **`X39-036` (S2)**

> *« La visite guidée du début à la fin. »*

**Elle annonce SEPT étapes et en montre QUATRE.**

Rejouée du début (`onboarding_tour_completed_at` remis à `null`), ce que
l'utilisateur voit défiler :

```
1. « Bienvenue dans Axion CRM Pro 👋 »            Next (Step 1 of 7)
2. « La barre latérale suit votre journée… »      Next (Step 2 of 7)
3. « Recherche globale ultra-rapide… ⌘K »         Next (Step 3 of 7)
4. « Mode clair/sombre… »                         Next (Step 6 of 7)   ← 4 et 5 SAUTÉES
```

**La numérotation saute de 3 à 6, sous les yeux de l'utilisateur.** Le premier
contact d'un nouvel arrivant avec le produit est incomplet, et il le voit.

### La cause, mesurée

Les sept cibles `data-tour` **existent** dans le DOM. Mais deux d'entre elles ont
une **taille nulle** — donc elles sont invisibles, et `react-joyride` passe :

| cible | présente | visible | taille |
|---|---|---|---|
| `sidebar` | ✅ | ✅ | 260 × 950 |
| `global-search` | ✅ | ✅ | 438 × 34 |
| **`nav-companies`** | ✅ | ❌ | **0 × 0** |
| `nav-dashboard` | ✅ | ✅ | 243 × 32 |
| `dark-mode` | ✅ | ✅ | 82 × 30 |
| **`nav-settings`** | ✅ | ❌ | **0 × 0** |

Les deux invisibles vivent dans des **groupes repliés** de la barre latérale
(« Contacts », « Réglages »). Le composant porte pourtant un dépliage exprès pour
ce cas — `OnboardingTour.tsx`, constat `D23-010` : *« AVANT d'afficher l'étape,
on déplie la section qui porte sa cible »* — **et il ne couvre pas ces deux-là.**

⚠️ *Ce que je n'affirme pas* : mon parcours a montré 4 étapes, or seules 2 cibles
sont invisibles. Il devrait donc en rester 5. L'écart d'une étape n'est pas
expliqué — il peut venir de mon enchaînement de clics. **Le fait mesuré et
reproductible est la numérotation qui saute de 3 à 6, et les deux cibles à
taille nulle.**

### `X39-005` confirmé du début à la fin

**« Next (Step 1 of 7) »**, **« Next (Step 2 of 7) »**, **« Next (Step 3 of 7) »**…
Trois mots anglais — `Next`, `Step`, `of` — répétés à **chaque** étape, à côté de
« Passer », « Précédent » et « Fermer » qui sont, eux, en français. Sur le tout
premier écran que voit un nouvel utilisateur.
