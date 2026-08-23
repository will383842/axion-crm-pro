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
