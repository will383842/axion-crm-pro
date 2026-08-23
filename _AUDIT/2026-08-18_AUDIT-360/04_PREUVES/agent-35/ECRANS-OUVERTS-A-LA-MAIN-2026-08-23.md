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
