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
