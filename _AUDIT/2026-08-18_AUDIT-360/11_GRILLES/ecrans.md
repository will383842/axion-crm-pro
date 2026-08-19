# Grille des écrans — AGENT 22 « Cartographe des écrans »

- **Référence** : `main = e8924b8` (relue par `git log` au début **et** à la fin de la mission).
- **Base locale** : `axion_crm`, `select count(*) from migrations` = **58 avant** et **58 après** toutes les mesures (preuve `04_PREUVES/agent-22/00-migrations-avant.txt`). Aucune mesure n'est à rejouer de ce chef.
- **Périmètre** : **37 écrans** (pas 39 — `/crm` et `/analytics` n'existent plus dans `routeTree.tsx`). **Aucun écran omis.**
- **Écrans réellement ouverts dans un vrai navigateur** : **37 / 37**.
- **Artefact mesuré** : voir `04_PREUVES/agent-22/02-bundle-servi.txt`. Conformité **D-011** établie par équivalence de témoins (§0 ci-dessous).

---

## 0. Avertissement de méthode — lire avant la grille

### 0.1 Décision D-011 : sur quel bundle ces mesures portent-elles ?

L'atelier servait au lancement de l'agent un bundle **périmé de 34 h** (`index-DPQz8SpC.js`, construit le **2026-08-17 07:12 UTC**, alors que le commit de refonte de la barre latérale `da97826` date du **2026-08-18 17:39**). Je l'ai constaté indépendamment — c'est le constat **D23-001 (S1)** de l'agent 23, **je ne le rouvre pas**.

**Le balayage des 37 écrans n'a PAS été fait sur ce bundle périmé.** Il a été fait sur `frontend/dist/assets/index-C8i6k4WZ.js`, construit sur le poste le **2026-08-19 à 12:03** à partir de `main`. Équivalence avec le bundle officiel reconstruit par le chef de chantier (`index-BVK1vh1a.js`), sur **tous** les témoins que j'ai mesurés :

| témoin | officiel `BVK1vh1a` | balayé `C8i6k4WZ` |
|---|---|---|
| `Journaux de collecte` | 3 | 3 |
| `Collectes` | 5 | 5 |
| `Runs de scraping` | 0 | 0 |
| `PHASE 2` | 0 | 0 |
| `console/personnes` | 4 | 4 |
| `Vivier candidats` | 3 | 3 |
| `Console non activée` | 1 | 1 |
| `Aujourd'hui` | 2 | 2 |
| `arbitrage` | 7 | 7 |

Les deux artefacts sont **fonctionnellement identiques sur tout l'objet de cette grille**. En outre, le bundle **officiel** `index-BVK1vh1a.js` a été **rechargé en direct dans le navigateur** après reconstruction, et la barre latérale à six sections ainsi que le comportement de `/console/*` y ont été **re-confirmés**. La grille est donc valide au titre de D-011.

### 0.2 Comment les écrans ont été ouverts — et ce que cela ne mesure pas

**Chrome refuse `https://app.localhost`.** Mesuré :

```
curl https://app.localhost/     -> exit 60 (SSL certificate problem)
openssl s_client                -> Verify return code: 20 (unable to get local issuer certificate)
issuer = CN=Caddy Local Authority - ECC Intermediate
Get-ChildItem Cert:\CurrentUser\Root, Cert:\LocalMachine\Root | ? Subject -like "*Caddy*"  ->  AUCUN
```

L'extension navigateur **ne peut pas s'attacher à une page d'erreur** de Chrome : ni capture, ni texte, ni clic. Installer une autorité racine dans le magasin de confiance de la machine est une **modification de sécurité du poste** : **je ne l'ai pas faite**, c'est un geste qui revient à Will.

Contournement employé, sans toucher au produit ni à sa configuration — conteneur temporaire, lecture seule, **retiré à la fin** :

```
docker run -d --name a22-proxy --network axion-crm -p 58122:80 \
       caddy:2-alpine caddy reverse-proxy --from :80 --to app:5173
```

puis navigation réelle sur `http://app.localhost:58122/...` : **même hôte, même bundle, même origine de cookie ; seul le TLS est retiré.**

**Ce que cela implique, et que je ne masque pas** : le SPA porte une base d'API **absolue** compilée dans le bundle (`frontend/src/lib/api.ts:3` → `https://app.localhost`). Depuis cette origine, **tous** les appels d'API échouent :

```js
fetch('https://app.localhost/api/v1/companies')  ->  "Failed to fetch"
```

Les 37 écrans ont donc été ouverts **non connecté et API injoignable**. C'est une mesure **partielle mais honnête**, et elle est **exactement** ce qu'il faut pour la colonne « états gérés (vide / erreur) » : elle montre ce que chaque écran raconte quand il n'a pas pu obtenir ses données. **Elle ne mesure pas** l'état nominal avec données ; la colonne « verdict » le dit écran par écran.

### 0.3 Pourquoi l'état nominal n'a pas pu être mesuré

Deux causes mesurées, **indépendantes**, toutes deux déjà ouvertes au dossier :

1. **A-009** — l'atelier sert l'API par `php -S`, **un seul processus**. Pendant ma mission, avec les autres agents dans le même conteneur : **133 connexions en attente sur `:80` qui ne se vidangeaient pas**, `/up` sans réponse à **90 s**, et `php artisan --version` en **8 min 47 s** (`user 0m7.4s`, `sys 0m17.5s` → famine d'E/S, pas de calcul). J'ai contourné en lançant un **second serveur PHP dans le conteneur** (`127.0.0.1:9980`, sans gêner personne) : c'est par lui que la preuve de connexion §1 a fini par passer.
2. **A07-001** — l'enrôlement 2FA écrit des colonnes inexistantes : **même l'API en marche, la première connexion ne peut pas aboutir** (§1).

---

## 1. VERDICT DE CONNEXION — la console **n'est pas** utilisable

> **On peut se connecter. On ne peut rien faire ensuite. Et l'interface n'offre aucune sortie.**

### 1.1 Le SPA s'ouvre-t-il sur l'écran de connexion ? — **OUI**

Ouvert dans un vrai navigateur : `/login` affiche « Connexion / Connecte-toi à ton workspace Axion CRM Pro », les champs *Adresse e-mail* et *Mot de passe*, la case *Se souvenir de moi*, le bouton *Se connecter*, et les deux échappatoires *Recevoir un lien magique* et *Mot de passe oublié ?*. **Pas d'erreur, pas d'écran blanc.**

### 1.2 La connexion fonctionne-t-elle ? — **OUI pour l'authentification, NON pour l'usage**

Compte créé localement avec le seeder du dépôt (règle 8 : rien de réinventé) — `OwnerUserSeeder`, précédé de `PermissionsAndRolesSeeder` (la base était fraîche : **0 utilisateur, 0 rôle**) :

```
docker exec -e OWNER_INITIAL_EMAIL=audit22@axion.local \
            -e OWNER_INITIAL_PASSWORD=... axion-crm-api \
            php artisan db:seed --class=OwnerUserSeeder --force
-> users: audit22@axion.local | rôle owner | workspace b235b23a-…
```

Flux SPA complet rejoué (preuve intégrale : `04_PREUVES/agent-22/01-connexion-reelle.txt`) :

```
1) GET  /sanctum/csrf-cookie                       -> 204   (XSRF-TOKEN, 340 car.)
2) POST /api/v1/auth/login   (bon mot de passe)    -> 200
   {"user":{"email":"audit22@axion.local",
            "first_login_completed_at":null,…},"requires_2fa":false}
3) GET  /api/v1/auth/me                            -> 200   {"roles":["owner"]}
4) GET  /api/v1/dashboard/stats   ← 1er écran réel -> 403
   {"error":"first_login_required",
    "message":"Vous devez activer la double authentification avant utilisation.",
    "next_step":"/auth/2fa/setup"}
```

**Témoin négatif** (le contrôle sait rougir) :

```
POST /api/v1/auth/login  (mauvais mot de passe)  -> 422  {"message":"auth.failed"}
```

**La porte de sortie est murée des DEUX côtés** :

```
POST /api/v1/auth/2fa/setup   -> 500
  SQLSTATE[42703]: Undefined column: 7 ERROR:
  column "two_factor_secret" of relation "users" does not exist
```

Colonnes réellement présentes dans `users` : `totp_secret`, `totp_enabled_at`, `totp_recovery_codes` — **pas** `two_factor_secret`. C'est le constat **A07-001 (S0)** déjà ouvert, que je **confirme** par une mesure indépendante.

**Et la moitié « interface » de ce mur n'était pas encore décrite — c'est mon constat D22-001** : `/auth/2fa/setup` et `/auth/2fa/confirm` ne sont appelés **par aucun fichier du frontend**. Même avec les trois colonnes corrigées, **il n'existe aucun écran pour enrôler la 2FA**.

### 1.3 Sur A-001 — je ne le rouvre pas, je le confirme abaissé

Mesuré sur la même route, même serveur :

```
GET /api/v1/companies  -H "Accept: application/json"  -> 401   ← le SPA est ici
GET /api/v1/companies  (sans en-tête Accept)          -> pas de réponse / 500
```

**Le SPA pose `Accept: application/json` (`frontend/src/lib/api.ts:8`) : il n'est pas touché.** Conforme à A-001 **S2**. Je n'écris pas « le produit est inutilisable » pour cette raison-là — il l'est pour la raison du §1.2.

---

## 2. La grille des 37 écrans

Légende **atteignable** : **NAV** = entrée dans la barre latérale · **PROF** = seulement par rebond depuis un autre écran · **URL** = seulement en tapant l'adresse.
Légende **états** : ⏳ chargement · ∅ vide · ⚠ erreur · ⛔ refus · ◐ partiel. Un état **barré** est prévu dans le code mais **jamais atteint**.

### 2.1 Authentification (hors coquille — pas de barre latérale)

| route | composant | données consommées | actions offertes | permissions | états gérés | atteignable | verdict |
|---|---|---|---|---|---|---|---|
| `/login` | `features/auth/LoginPage.tsx` | `POST /auth/login` | se connecter · se souvenir de moi · aller au lien magique · aller au mot de passe oublié | aucune (écran public) | ⏳ (bouton) ⚠ (toast) — **∅ et ⛔ sans objet** | URL / racine | **OK** — ouvert, rendu conforme, connexion prouvée (§1.2) |
| `/2fa` | `features/auth/TwoFactorPage.tsx` | `POST /auth/2fa/verify` | saisir le code à 6 chiffres · vérifier · retour connexion | aucune | ⏳ ⚠ (toast « Code invalide ») | URL / après login si `requires_2fa` | **DÉFAUT** — **vérifie** un code, n'**enrôle** jamais (D22-001) |
| `/magic-link` | `features/auth/MagicLinkPage.tsx` | `POST /auth/magic-link` | demander un lien par courriel | aucune | ⏳ ⚠ | URL / depuis `/login` | **INOPÉRANT en fait** — `MAIL_MAILER` non défini → aucun courriel ne part (**A-012**, déjà ouvert) |
| `/password-reset` | `features/auth/PasswordResetPage.tsx` | `POST /auth/password/forgot` | demander un lien de réinitialisation | aucune | ⏳ ⚠ | URL / depuis `/login` | **INOPÉRANT en fait** — même cause (**A-012**) |

### 2.2 Aujourd'hui · Contacts

| route | composant | données consommées | actions offertes | permissions | états gérés | atteignable | verdict |
|---|---|---|---|---|---|---|---|
| `/` | `features/dashboard/DashboardPage.tsx` | `GET /auth/me` · `GET /dashboard/stats` | changer la fenêtre 7j/30j/90j · actualiser · « Démarrer sur /coverage » | **aucune garde** | ⏳ ∅ — **⚠ et ⛔ absents** | **NAV** « Tableau de bord » | **MENT DEUX FOIS** : (a) `/dashboard/stats` est un **bouchon codé en dur** qui rend des zéros (`routes/api.php:86-99`) ; (b) API injoignable → affiche « Aucune entreprise collectée » au lieu d'une erreur (**D22-002**) |
| `/contacts` | `features/contacts/ContactsListPage.tsx` | `GET /contacts?…` | filtrer statut e-mail / pays / prospection · rechercher par nom · paginer | **aucune garde** | ⏳ ∅ ⚠ | **NAV seulement si console v2 FERMÉE** — sinon **URL** | **ORPHELIN conditionnel** (**D22-005**) : console v2 ouverte → plus aucune entrée de menu, mais la route reste vivante et double `/console/contacts` |
| `/companies` | `features/companies/CompaniesListPage.tsx` | `GET /companies?…` · `GET /referentiels/geo` · `GET /companies/export` · `POST` action de masse | importer · exporter CSV · lancer scraping · poser/retirer un tag en masse · sélectionner toute la page · 12 filtres (taille, effectif, NAF, secteur, dates, tag, statut) · paginer | **aucune garde** | ⏳ ∅ ⚠ ◐ | **NAV** « Entreprises » | **DÉFAUT** — écran le plus riche (811 l.), mais affirme « 0 entreprises actives / TOTAL 0 » quand l'API ne répond pas (**D22-002**) ; export sans plafond ni trace (voir B12-010) |
| `/companies/$companyId` | `features/companies/CompanyDetailPage.tsx` | `GET /companies/{id}` · `POST /companies/{id}/enrich` | enrichir maintenant · marquer obsolète · exporter · « Plus d'actions » | **aucune garde** | ⏳ ⚠ (« Entreprise introuvable ») | **PROF** depuis `/companies` | **DÉFAUT** — reste bloqué sur « Chargement de la fiche entreprise… » indéfiniment quand l'API est muette (**D22-003**) |
| `/journalists` | `features/media/JournalistsListPage.tsx` | `GET /journalists?…` · `GET /journalists/export` | rechercher par nom · exporter CSV · paginer | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Journalistes » | **DÉFAUT** — affiche simultanément « **0 journalistes** » (compteurs) **et** « Chargement… » (tableau) : deux affirmations contradictoires au même instant (**D22-002**) |
| `/media` | `features/media/MediaListPage.tsx` | `GET /media?…` · `GET /media/export` | rechercher · filtrer famille / type / département · exporter CSV · paginer | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Médias (presse) » | **DÉFAUT** — « TOTAL 0 » affirmé API muette (**D22-002**) |
| `/media/$mediaId` | `features/media/MediaDetailPage.tsx` | `GET /media/{id}` | retour à la liste | **aucune garde** | ⏳ ⚠ (« Média introuvable ») | **PROF** depuis `/media` | 🔴 **ÉCRAN BLANC** — `<main>` **entièrement vide** à l'ouverture (mesuré) : ni titre, ni message, ni retour (**D22-003**) |

### 2.3 Console CRM v2 (`CRM_CONSOLE_V2_ENABLED=true` en local — vérifié dans le conteneur)

| route | composant | données consommées | actions offertes | permissions | états gérés | atteignable | verdict |
|---|---|---|---|---|---|---|---|
| `/console/contacts` | `features/crm-console/ContactsHubPage.tsx` | `GET /config/features` · `GET /crm/contacts-hub/counts` · `GET /crm/contacts-hub?…` | onglets Prospects / Clients / Opportunités / Dormants · filtrer température / pays / prospection · rechercher (nom, SIREN, personne) · ouvrir une fiche | `ConsoleGate` — **drapeau, pas permission** | ⏳ ∅ ⛔(« Console non activée ») — **⚠ absent** | **NAV** « Contacts » si console ouverte | 🔴 **AFFIRME LE FAUX** — dit « **La console CRM v2 n'est pas ouverte sur ce serveur** » alors que `CRM_CONSOLE_V2_ENABLED=true` : c'est l'échec de la requête qui est présenté comme une décision de configuration (**D22-004**) |
| `/console/vivier` | `features/crm-console/CandidatesPage.tsx` | `GET /config/features` · `GET /crm/candidates/counts` · `GET /crm/candidates?…` | onglets À qualifier / Présélection / Entretien / Conservés · rechercher · marquer opposition | `ConsoleGate requiresVivier` | ⏳ ∅ ⛔ — **⚠ absent** | **NAV** seulement si `universes.vivier` | 🔴 même défaut (**D22-004**) ; l'écran de refus « univers non accessible » est en revanche **exemplaire** (il dit quoi faire) |
| `/console/arbitrage` | `features/crm-console/ArbitragePage.tsx` | `GET /crm/arbitrage?per_page=50` · `POST /crm/arbitrage/{id}/attach` · `POST /crm/arbitrage/{id}/dismiss` | rattacher un rapprochement à une entreprise · écarter avec motif | `ConsoleGate` | ⏳ ∅ ⚠ ⛔ | **NAV** « À arbitrer » | 🔴 même défaut (**D22-004**) — écran **critique** (100 % des leads y stationnent, cf. B13-001) rendu invisible par une erreur réseau |
| `/console/personnes/$personKey` | `features/crm-console/PersonTimelinePage.tsx` | `GET /crm/persons/{key}/timeline` | basculer Business / Vivier candidats | `ConsoleGate` | ⏳ ∅ ⛔ (« Fiche introuvable ») | **PROF** uniquement | 🔴 **la fiche 360°** — et **A05-001** mesure que **0 contact sur 1 319 567** porte une `person_key` : **inatteignable en pratique**, quel que soit l'état de l'interface |

### 2.4 Collecte

| route | composant | données consommées | actions offertes | permissions | états gérés | atteignable | verdict |
|---|---|---|---|---|---|---|---|
| `/coverage` | `features/coverage/CoveragePage.tsx` | `GET /coverage` · `POST /coverage/launch` · `POST /coverage/enrich` | choisir un département sur la carte · basculer Visualisation / Recherche / Action · lancer une collecte ciblée · lancer un enrichissement · désélectionner | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Couverture France » | **DÉFAUT** — « COUVERTURE 0 % · 0 / 96 dépts » affirmé API muette ; c'est l'écran que le tableau de bord vide désigne comme point de départ (**D22-002**) |
| `/campaigns` | `features/campaigns/CampaignsListPage.tsx` | `GET /campaigns` · `POST /campaigns/{id}/{pause,resume,cancel}` | créer une campagne · mettre en pause · reprendre · annuler · rechercher · filtrer par statut · réinitialiser | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Collectes » | **DÉFAUT** — actions destructrices (annuler) offertes sans aucune vérification de rôle (**D22-006**) |
| `/campaigns/new` | `features/campaigns/CampaignWizardPage.tsx` | `GET /coverage` · `POST /campaigns` · `POST /campaigns/{id}/start` | assistant 4 étapes : identité · zones · sources · budget & anti-blacklist ; créer en brouillon · lancer maintenant · planifier | **aucune garde** | ⏳ ⚠ ◐ | **PROF** (« Nouvelle campagne ») | **OK** — le meilleur écran du produit : progression explicite, garde-fous budgétaires nommés, rendu complet même API muette |
| `/campaigns/$campaignId` | `features/campaigns/CampaignDetailPage.tsx` | `GET /campaigns/{id}` · `GET /campaigns/{id}/stats` · `POST …/{start,pause,resume,cancel}` | lancer · mettre en pause · reprendre · annuler · dupliquer · consulter les runs | **aucune garde** | ⏳ ∅ ⚠ | **PROF** depuis `/campaigns` | 🔴 **ÉCRAN BLANC** — `<main>` **vide** (mesuré), alors que l'écran porte 4 actions destructrices (**D22-003**) |
| `/scraper-runs` | `features/scraping/ScraperRunsPage.tsx` | `GET /scraper-runs?per_page=50` · `POST /scraper-runs/{id}/cancel` · `POST /scraper-runs/{id}/retry` | annuler un run · relancer un run · exporter en CSV · filtrer (tous / en cours / en attente / terminés / échec / annulés) · ouvrir le détail | **aucune garde** | ⏳ ∅ ⚠ ◐ | **NAV** « Journaux de collecte » | **DÉFAUT** — « TAUX DE SUCCÈS 0 % · 0 OK / 0 clos » affirmé API muette (**D22-002**) |
| `/international/roumanie` | `features/international/RoumaniePage.tsx` | `GET /companies?…` (filtré) | filtrer par nature d'entité (7 valeurs) · filtrer par statut · paginer · voir une fiche | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Roumanie » | **OK sur le fond** — mais c'est une **vue filtrée de `/companies`** promue au rang d'entrée de menu : un pays a son entrée, les autres non |

### 2.5 Pilotage

| route | composant | données consommées | actions offertes | permissions | états gérés | atteignable | verdict |
|---|---|---|---|---|---|---|---|
| `/audiences` | `features/audiences/AudiencesListPage.tsx` | `GET /audiences` · `POST /audiences/{id}/refresh` · `DELETE /audiences/{id}` | créer une audience · rafraîchir un segment · supprimer · ouvrir | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Audiences (segments) » | **DÉFAUT** — suppression offerte sans garde de rôle (**D22-006**) |
| `/audiences/new` | `features/audiences/AudienceBuilderPage.tsx` | `GET /audiences/preview` · `POST /audiences` | composer un segment : départements · régions · secteurs · tailles · statuts de prospection · tags · « a au moins un contact avec e-mail » ; prévisualiser en direct · créer | **aucune garde** | ⏳ ⚠ ◐ | **PROF** (« Nouvelle audience ») | **OK** — rendu complet, prévisualisation continue ; second meilleur écran |
| `/audiences/$audienceId` | `features/audiences/AudienceDetailPage.tsx` | `GET /audiences/{id}` · `GET /audiences/{id}/members` · `POST /audiences/{id}/refresh` | modifier · rafraîchir · supprimer · parcourir les membres · basculer l'auto-refresh | **aucune garde** | ⏳ ∅ ⚠ | **PROF** depuis `/audiences` | 🔴 **ÉCRAN BLANC** — `<main>` **vide** (mesuré) ; **3ᵉ** écran de détail dans ce cas (**D22-003**) |
| `/admin/observability` | `features/observability/ObservabilityPage.tsx` | `GET /observability/summary` | consulter quotas Hunter / Google Places · échecs de rafraîchissement d'audience · erreurs waterfall · archivages par raison · 50 derniers business events | **aucune garde** | ⏳ ∅ — **⚠ absent** | **NAV** « Observabilité » | **DÉFAUT** — reste sur « Chargement de l'observabilité… » sans jamais basculer en erreur ; l'écran censé dire si le système va mal **ne sait pas dire qu'il ne sait pas** |

### 2.6 Conformité

| route | composant | données consommées | actions offertes | permissions | états gérés | atteignable | verdict |
|---|---|---|---|---|---|---|---|
| `/rgpd/requests` | `features/rgpd/RgpdRequestsPage.tsx` | `GET /rgpd/requests` · `POST /rgpd/requests/{id}/process` | créer une requête (e-mail du sujet) · filtrer par article (15/16/17/20/21) · traiter · marquer comme traitée · noter en interne | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Requêtes RGPD » | 🔴 **GRAVE** — écran à obligation légale, **ouvert à tout compte authentifié sans vérification de rôle** (**D22-006**) ; et **B10-004 / B14-002** montrent que le traitement lui-même ment |
| `/rgpd/ai-act` | `features/rgpd/AiActRegisterPage.tsx` | `GET /ai-act/register` | consulter les systèmes IA · classification de risque · ouvrir le détail | **aucune garde** | ⏳ ∅ — **⚠ absent** | **NAV** « Registre AI Act » | 🔴 **registre réglementaire qui rend un corps figé** (A-002 / B10-013) ; affiche « TOTAL REGISTRES 0 » avec la même assurance qu'une vraie mesure |
| `/audit-logs` | `features/rgpd/AuditLogsPage.tsx` | `GET /audit-logs` · `GET /audit-logs/verify-chain` | vérifier la chaîne d'empreintes · filtrer par sévérité · rechercher (événement, chemin, IP, acteur) · ouvrir le détail + charge brute | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Journaux d'audit » | 🔴 **GRAVE** — **B16-004** : cet écran rend **le journal de tous les espaces à tout compte authentifié** ; aucune garde côté interface ne l'atténue (**D22-006**) |

### 2.7 Réglages

| route | composant | données consommées | actions offertes | permissions | états gérés | atteignable | verdict |
|---|---|---|---|---|---|---|---|
| `/users` | `features/users/UsersPage.tsx` | `GET /users` · `POST /users/invite` | inviter un utilisateur · consulter rôle / dernière connexion / état de première connexion | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Utilisateurs » | 🔴 **GRAVE** — l'écran qui **distribue les droits** ne vérifie **aucun droit** (**D22-006**) ; affiche « Aucun utilisateur · Invite ton premier collaborateur » alors qu'un utilisateur **existe** en base et que seule la requête a échoué (**D22-002**) |
| `/settings` | `features/settings/SettingsPage.tsx` | `GET /workspace` · `PUT /workspace` | 4 onglets : Workspace (identité, limites) · Intégrations · Observabilité · Apparence ; enregistrer · configurer · basculer le thème sombre · révéler/masquer les clés | **aucune garde** | ⏳ ⚠ — **∅ et ⛔ absents** | **NAV** « Paramètres » | 🔴 **GRAVE** — plafond de coût et clés d'intégration modifiables **sans vérification de rôle** (**D22-006**) ; reste sur « Chargement… » API muette |
| `/tags` | `features/tags/TagsManagerPage.tsx` | `GET /tags` · `POST /tags` · `PUT /tags/{id}` · `DELETE /tags/{id}` | créer un tag (nom, catégorie, description) · modifier · supprimer | **aucune garde** | ⏳ ∅ ⚠ ⛔ | **NAV** « Tags » | **DÉFAUT** — référentiel éditable, suppression sans garde (**D22-006**) |
| `/llm/router` | `features/llm/LlmRouterPage.tsx` | `GET /llm/use-cases` · `GET /llm/usage/summary` | 4 onglets : cas d'usage · fournisseurs · prompts · usage 30 j | **aucune garde** | ⏳ ∅ — **⚠ absent** | **NAV** « LLM Router » | **DÉFAUT** — écran de **coût** (« Coût total 30j ») sans état d'erreur : un 0 € faute de réponse est indiscernable d'un 0 € réel (**D22-002**) |
| `/llm/proxy-providers` | `features/llm/ProxyProvidersPage.tsx` | `GET /proxy-providers` · `POST /proxy-providers/{id}/test` | tester un fournisseur · voir la documentation | **aucune garde** | ⏳ ∅ ⚠ | **NAV** « Proxies » | **DÉFAUT** — même famille |
| `/llm/rotations` | `features/llm/RotationsPage.tsx` | `GET /rotations` | consulter les 5 dimensions de rotation | **aucune garde** | ⏳ ∅ — **⚠ absent** | **NAV** « Rotations » | **DÉFAUT** — **lecture seule** : l'écran annonce « Configure des rotations » mais **n'offre aucun moyen de le faire** |

### 2.8 Phase 2 et hors-menu

| route | composant | données consommées | actions offertes | permissions | états gérés | atteignable | verdict |
|---|---|---|---|---|---|---|---|
| `/cold-email` | `features/phase2-scaffold/ColdEmailStub.tsx` (13 l.) | **aucune** | **aucune** | aucune | *sans objet* | **URL seulement** | **A-005 (S3, déjà ouvert)** — « Phase 2 — bientôt disponible ». Honnête, mais joignable par adresse |
| `/linkedin` | `features/phase2-scaffold/LinkedInStub.tsx` (13 l.) | **aucune** | **aucune** | aucune | *sans objet* | **URL seulement** | **A-005 (S3, déjà ouvert)** — idem |
| `/*` (NotFound) | `features/misc/NotFoundPage.tsx` | **aucune** | « Retour au tableau de bord » | aucune | *sans objet* | *filet* | 🔴 **NE S'AFFICHE JAMAIS** — mesuré : `/une-route-qui-nexiste-pas` et `/crm` rendent un « **Not Found** » **nu**, sans coquille ni lien, alors que « Page introuvable » et « Retour au tableau de bord » **sont bien dans le bundle** (**D22-007**) |

---

## 3. Constats

### [D22-001] Aucun écran du produit n'expose l'enrôlement de la double authentification, que le serveur exige avant tout usage
- Sévérité      : **S0** bloquant
- Domaine       : interface / navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/auth/TwoFactorPage.tsx` (seul écran 2FA, ne fait que *vérifier*) · `backend/app/Http/Middleware/EnforceFirstLoginSetup.php:31` · `backend/routes/api.php:60-61` · `backend/app/Services/Auth/TwoFactorService.php:68`
- Constat       : `EnforceFirstLoginSetup` répond **403 `first_login_required`** sur **toutes** les routes protégées tant que `first_login_completed_at` est nul ; cette colonne n'est écrite que par `2fa/confirm` ; et **`/auth/2fa/setup` comme `/auth/2fa/confirm` ne sont appelés par aucun fichier du frontend**.
- Preuve        : `grep -rn "2fa/setup\|2fa/confirm" frontend/src` → **aucune occurrence** (seul `first_login_completed_at` apparaît, en lecture, dans `UsersPage.tsx:25`). Flux complet joué : `login` **200**, `auth/me` **200** `roles:["owner"]`, puis `dashboard/stats` **403 first_login_required next_step:/auth/2fa/setup`. Sortie intégrale : `04_PREUVES/agent-22/01-connexion-reelle.txt`.
- Témoin négatif: le même `grep` trouve bien `'/auth/2fa/verify'` dans `TwoFactorPage.tsx` — le contrôle sait donc repérer un appel 2FA quand il existe. Et `POST /auth/login` avec un mauvais mot de passe rend **422** : la chaîne d'authentification mesurée réagit correctement.
- Impact        : **tout compte neuf est enfermé**. Il se connecte, l'interface le laisse entrer, et chaque écran est refusé — sans message, car l'intercepteur du SPA ne redirige que sur **401** (`frontend/src/lib/api.ts:29`), jamais sur **403**. L'utilisateur voit un tableau de bord à zéros et ne peut rien en conclure. C'est la moitié « interface » de **A-012** et de **A07-001** : même les trois colonnes réparées, **il n'y aurait toujours pas d'écran pour enrôler**.
- Reproduction  : créer un utilisateur (`OwnerUserSeeder`), se connecter par le SPA, ouvrir n'importe quel écran.
- Correctif     : (1) écran d'enrôlement 2FA — QR + secret + confirmation par code — présenté d'office quand `first_login_completed_at` est nul ; (2) traiter le **403 `first_login_required`** dans l'intercepteur axios et rediriger vers cet écran. Coût estimé **1 à 1,5 j**, à faire **après** A07-001 (sinon l'écran appellera une route qui explose).
- Statut        : ouvert

### [D22-002] Quand la donnée n'a pas pu être obtenue, les écrans affirment « 0 » et « aucun » au lieu de dire qu'ils n'ont pas pu demander
- Sévérité      : **S1** grave
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `features/dashboard/DashboardPage.tsx` · `companies/CompaniesListPage.tsx` · `media/MediaListPage.tsx` · `media/JournalistsListPage.tsx` · `users/UsersPage.tsx` · `scraping/ScraperRunsPage.tsx` · `coverage/CoveragePage.tsx` · `llm/*` · `rgpd/AiActRegisterPage.tsx` · `observability/ObservabilityPage.tsx`
- Constat       : sur **12 écrans ouverts** avec l'API injoignable, l'état « vide » est rendu comme un **fait mesuré** — jamais comme une absence de réponse.
- Preuve        : navigation réelle, bundle `index-BVK1vh1a.js` (officiel) puis `index-C8i6k4WZ.js` (équivalent, §0.1). Relevés : `/` → « **Aucune entreprise collectée pour l'instant** » ; `/companies` → « **0 entreprises actives · TOTAL 0 · ENRICHIES 0 %** » ; `/users` → « **Aucun utilisateur · Invite ton premier collaborateur** » **alors qu'un utilisateur existe en base** ; `/scraper-runs` → « **TAUX DE SUCCÈS 0 % · 0 OK / 0 clos** » ; `/coverage` → « **COUVERTURE 0 % · 0 / 96 dépts** » ; `/journalists` → « **0 journalistes** » **et** « Chargement… » **simultanément**. État réseau mesuré au même instant, depuis la page : `fetch('https://app.localhost/api/v1/companies')` → **« Failed to fetch »**.
- Témoin négatif: le contrôle sait distinguer les deux cas — sur les **mêmes** écrans, `/settings`, `/admin/observability` et `/companies/$id` restent, eux, sur « **Chargement…** » : le rendu dépend donc bien de l'état de la requête, et l'affirmation « 0 » n'est pas une fatalité technique. `grep -c "isError"` vaut **0** sur `DashboardPage`, `ObservabilityPage`, `AiActRegisterPage`, `RotationsPage`, `LlmRouterPage`, `ContactsHubPage`, `CandidatesPage`.
- Impact        : pour un CRM, c'est le mensonge le plus coûteux. Un dirigeant qui ouvre `/companies` après une coupure lit « **0 entreprises** » et peut conclure à une **perte de données**. Sur `/llm/router`, un coût affiché **0 €** faute de réponse est indiscernable d'un coût réellement nul — l'écran de surveillance des dépenses rassure au moment exact où il devrait alerter.
- Reproduction  : rendre l'API injoignable (ou couper le réseau), ouvrir `/`, `/companies`, `/users`.
- Correctif     : distinguer `isError` de « données vides » dans chaque écran, et rendre un état d'erreur explicite avec bouton *Réessayer*. Un composant partagé `<ÉtatDeChargement query={…}>` factoriserait les 12 cas. Coût **2 à 3 j**.
- Statut        : ouvert

### [D22-003] Trois écrans de détail rendent une page entièrement blanche quand la donnée manque
- Sévérité      : **S2** défaut
- Domaine       : interface / navigation
- Référence     : main e8924b8
- Emplacement   : `features/media/MediaDetailPage.tsx` · `features/campaigns/CampaignDetailPage.tsx` · `features/audiences/AudienceDetailPage.tsx`
- Constat       : à l'ouverture avec l'API muette, `document.querySelector('main').innerText` est **vide** — pas de titre, pas de message, pas de lien de retour.
- Preuve        : navigation réelle. `/media/22222222-…` → `*** MAIN VIDE ***` ; `/campaigns/33333333-…` → `*** MAIN VIDE ***` ; `/audiences/44444444-…` → `*** MAIN VIDE ***`.
- Témoin négatif: le **même** relevé, au **même** instant, rend du texte sur les autres écrans (`/companies/11111111-…` → « Chargement de la fiche entreprise… », `/console/personnes/abc123` → « Console non activée ») : la sonde sait donc lire un `<main>` non vide.
- Impact        : l'utilisateur croit l'application plantée. Sur `/campaigns/$id`, l'écran blanc masque **quatre actions destructrices** (lancer, pause, reprendre, annuler) : l'utilisateur recharge, et peut relancer une collecte déjà lancée.
- Reproduction  : ouvrir l'une des trois routes avec un identifiant quelconque, API injoignable.
- Correctif     : rendre systématiquement l'état ⏳ puis l'état ⚠ ; `CompanyDetailPage.tsx` contient déjà le patron (« Entreprise introuvable ») — **on étend, on ne réinvente pas**. Coût **0,5 j**.
- Statut        : ouvert

### [D22-004] La console v2 annonce « non activée sur ce serveur » alors qu'elle est activée : une panne réseau est présentée comme une décision de configuration
- Sévérité      : **S2** défaut
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/crm-console/useConsoleFeatures.ts:56` (`return data ?? CONSOLE_FEATURES_CLOSED`) · `frontend/src/features/crm-console/ConsoleGate.tsx:41-48`
- Constat       : `useConsoleFeaturesQuery` est déclaré `retry: false` ; toute réponse non aboutie laisse `data` indéfini, et `ConsoleGate` rend alors « **Console non activée — La console CRM v2 n'est pas ouverte sur ce serveur** ». `ConsoleGate` ne distingue que `isPending`, **jamais `isError`**.
- Preuve        : `docker exec axion-crm-api env | grep CRM_CONSOLE` → **`CRM_CONSOLE_V2_ENABLED=true`**. Au même moment, dans le navigateur, sur le bundle **officiel** `index-BVK1vh1a.js` : `/console/contacts`, `/console/vivier`, `/console/arbitrage`, `/console/personnes/abc123` → tous « **Console non activée** ».
- Témoin négatif: le fichier **décrit lui-même** la faute qu'il commet. `ConsoleGate.tsx:26-29` : « *« Console non activée » pendant qu'on interroge encore le serveur est une affirmation fausse* […] *Fermé par défaut, muet tant qu'on ne sait pas* ». Le cas `isPending` a bien été traité ; le cas `isError` — le même mensonge, déplacé — ne l'a pas été. Le correctif visait donc **le mauvais objet** (piège 19 / **A-011**).
- Impact        : les **quatre** écrans du chantier CRM cible, dont `/console/arbitrage` où stationnent **100 %** des leads (B13-001) et la fiche 360°, disparaissent sur une simple erreur réseau — et l'utilisateur est explicitement induit à croire que **l'administrateur ne les a pas ouverts**. Effet de bord mesuré : la barre latérale se rabat en silence sur l'ancienne entrée `/contacts` et **perd 3 entrées** sans aucun signe.
- Reproduction  : `CRM_CONSOLE_V2_ENABLED=true`, rendre `/config/features` injoignable, ouvrir `/console/contacts`.
- Correctif     : dans `ConsoleGate`, traiter `isError` séparément — « impossible de joindre le serveur » + *Réessayer* — et réserver « non activée » au cas où l'API a **répondu** `console_v2:false`. Coût **0,5 j**.
- Statut        : ouvert

### [D22-005] `/contacts` devient un écran orphelin dès que la console v2 est ouverte, tout en restant vivant
- Sévérité      : **S2** défaut
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/layout/Sidebar.tsx` (fonction `sectionContacts`) · `frontend/src/app/routeTree.tsx` (`contactsRoute`)
- Constat       : la barre latérale rend **soit** `/console/contacts` **soit** `/contacts`, jamais les deux — mais la route `/contacts` reste enregistrée et servie dans les deux cas.
- Preuve        : `sectionContacts(features)` — `features.console_v2 ? [/console/contacts, …] : [{ to: '/contacts' }]`. Mesuré en direct : console fermée → barre latérale « Contacts | **Contacts** | Entreprises | Journalistes | Médias (presse) » et `/contacts` rend bien sa liste (filtres statut e-mail / pays / prospection). Avec `CRM_CONSOLE_V2_ENABLED=true` côté API, cette entrée disparaît **sans que la route soit retirée**.
- Témoin négatif: la même lecture montre que `/crm` et `/analytics`, eux, ont bien été **retirés du routeur** (mesuré : `/crm` → « Not Found ») : le contrôle sait donc distinguer une route supprimée d'une route conservée.
- Impact        : deux écrans « Contacts » coexistent avec des données et des filtres différents ; un lien partagé, un signet ou un historique mène à une liste que le menu ne propose plus, et qui ne dit pas qu'elle est l'ancienne. **Une confusion de navigation qui fait perdre l'utilisateur est au minimum S2.**
- Reproduction  : ouvrir la console v2, puis saisir `/contacts` dans la barre d'adresse.
- Correctif     : au choix — rediriger `/contacts` vers `/console/contacts` quand le drapeau est ouvert, ou bannir l'écran ancien. Coût **0,25 j**. À trancher par le chantier CRM cible, qui décidera aussi du sort de `/console/*`.
- Statut        : ouvert

### [D22-006] L'interface ne consulte jamais les permissions : 33 écrans sur 37 offrent leurs actions à tout compte authentifié
- Sévérité      : **S2** défaut
- Domaine       : sécurité / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/**` — l'ensemble
- Constat       : hors `ConsoleGate` (qui teste un **drapeau de fonctionnalité et un univers**, pas un droit), **aucun écran** n'interroge le rôle ou la permission de l'utilisateur avant d'afficher ses actions.
- Preuve        : `grep -rnE "usePermission|hasPermission|\bcan\(|useCan|roles?\.(includes|some)|isOwner|isAdmin|RequireRole|Gate" frontend/src/features` → **les seules correspondances sont les 8 lignes d'import et d'usage de `ConsoleGate`** (+ 1 faux positif : un commentaire « Gate 0 CI » dans `AiActRegisterPage.tsx:60`). Le serveur, lui, connaît **4 rôles** (`owner`, `admin`, `operator`, `viewer`) et **16 permissions** (mesuré en base après `PermissionsAndRolesSeeder`), et `GET /auth/me` **renvoie déjà** `roles:["owner"]` — l'information est disponible et **n'est pas utilisée**.
- Témoin négatif: le même `grep` **trouve bien** les gardes `ConsoleGate` là où elles existent, sur 4 écrans : il sait donc repérer une garde d'écran quand il y en a une.
- Impact        : un `viewer` voit — et clique — « Inviter un utilisateur » (`/users`), « Enregistrer » le plafond de coût et les clés d'intégration (`/settings`), « Supprimer » un tag ou une audience, « Annuler » une campagne, « Marquer comme traitée » une requête RGPD. Il découvre son absence de droit **au clic**, par une erreur — ou, dans les cas mesurés par **B12-003**, **ne la découvre pas du tout puisqu'aucune policy n'est appelée côté serveur**. La navigation ne dit pas ce que l'utilisateur a le droit de faire, ce que la conception §2.2 exige pourtant explicitement (« l'étanchéité se **lit** dans la navigation, elle ne se découvre pas au clic ») — principe que `Sidebar.tsx` applique pour l'univers *vivier* et **pour rien d'autre**.
- Reproduction  : se connecter avec un compte `viewer`, ouvrir `/users`, `/settings`, `/tags`.
- Correctif     : exposer `roles`/`permissions` depuis `GET /auth/me` (déjà fait pour `roles`) dans un contexte React, puis un composant `<SiPermis permission="…">` autour des actions et des entrées de menu. **Ne remplace pas** la garde serveur (B12-003), qui reste prioritaire. Coût **2 à 3 j**.
- Statut        : ouvert

### [D22-007] L'écran « Page introuvable » existe, est livré dans le bundle, et ne s'affiche jamais
- Sévérité      : **S2** défaut
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/app/routeTree.tsx:107` — `createRoute({ getParentRoute: () => rootRoute, path: '/*', component: NotFoundPage })` · `frontend/src/main.tsx:28` (`createRouter`, **sans** `defaultNotFoundComponent`)
- Constat       : avec `@tanstack/react-router` **^1.170.27**, `path: '/*'` n'est pas le mécanisme de route introuvable ; le routeur rend son texte interne « **Not Found** », brut, hors coquille.
- Preuve        : navigation réelle sur le bundle officiel — `/une-route-qui-nexiste-pas` → `<body>` = « **Not Found** », **pas de `<main>`**, pas de barre latérale, pas de lien. Idem pour `/crm`. Or `NotFoundPage.tsx` rend bien « 404 / Page introuvable / Retour au tableau de bord », et ces chaînes **sont présentes dans le bundle servi** : `grep -o "Page introuvable" index-BVK1vh1a.js | wc -l` → **1**, `grep -o "Retour au tableau de bord" …` → **1**.
- Témoin négatif: le même relevé rend, sur toutes les routes **valides**, la coquille complète (barre latérale + `<main>`) : la sonde sait donc reconnaître un écran correctement monté. Et les deux chaînes cherchées **sont trouvées dans le bundle** — le contrôle prouve que le composant est bien livré, et que seul son **branchement** est en cause.
- Impact        : toute adresse erronée — signet périmé, lien partagé, faute de frappe, et notamment `/crm` et `/analytics` que le mandat et les anciens menus citent encore — mène à une page nue, sans identité ni retour possible. Le commentaire de `routeTree.tsx:105` affirme d'ailleurs que `/crm` et `/analytics` « tombent désormais sur `notFoundRoute` » : **c'est faux**, ils tombent sur le texte interne du routeur.
- Reproduction  : ouvrir `https://app.localhost/nimportequoi`.
- Correctif     : passer `defaultNotFoundComponent: NotFoundPage` à `createRouter`, ou déclarer `notFoundComponent` sur la route racine, et retirer la route `'/*'`. Coût **0,25 j**. **Garde à poser** : un test de navigation qui ouvre une adresse inexistante et exige la présence de « Retour au tableau de bord » — la garde actuelle, s'il en existe une, ne rougit sur rien.
- Statut        : ouvert

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

Ce n'est pas un aveu : c'est la partie du travail qu'un autre agent, ou Will, doit reprendre.

1. **L'état nominal des 37 écrans, avec de vraies données et une session ouverte.** Deux causes indépendantes, toutes deux mesurées : la connexion aboutit mais **tout écran est refusé** (**D22-001** / A07-001 / A-012), et l'atelier local était saturé (**A-009** : `/up` sans réponse à 90 s, `artisan --version` en **8 min 47 s**, 133 connexions en attente). **Toute la colonne « états gérés » ne porte donc que sur les états dégradés.** Les états ⏳ et ∅/⚠ sont mesurés ; les états **nominaux** ne le sont pas.
2. **Les états de refus (⛔) réels.** Je n'ai pas pu ouvrir de session `viewer` face à un écran `owner` : **D22-006** est établi par lecture exhaustive du code (grep avec témoin négatif), **pas** par un clic refusé à l'écran.
3. **Les écrans dans un vrai navigateur sur `https://app.localhost`.** Bloqué par le certificat de l'autorité locale Caddy, absent du magasin Windows (§0.2). J'ai mesuré via un proxy HTTP temporaire, même hôte et même bundle. **Je n'ai pas installé d'autorité racine** : c'est une modification de sécurité du poste, elle revient à Will.
   → **Geste pour Will, une fois** : `docker cp axion-crm-caddy:/data/caddy/pki/authorities/local/root.crt %TEMP%\caddy-local.crt` puis importer ce certificat dans *Autorités de certification racines de confiance*. Sans lui, **aucun agent de cet audit ne peut exécuter le §11 sur `https://app.localhost`.**
4. **Le rendu mobile / la barre latérale en tiroir** (`Drawer`) et la **recherche globale** (`GlobalSearch`, qui appelle `/search` — un bouchon rendant des tableaux vides, cf. A-002 / B10-013). Non ouverts faute de session.
5. **Les compteurs de la barre latérale** exigés par la cible §23.3 : absents (déjà couvert par **A-006**, je ne le rouvre pas).
6. **`/console/personnes/$personKey` avec une vraie personne.** **A05-001** mesure que **0 contact sur 1 319 567** porte une `person_key` : l'écran est inatteignable avec de vraies données, en local comme en production.

---

## 5. Ménage effectué

Le conteneur temporaire `a22-proxy` / `a22-spa` (Caddy, lecture seule, jamais sur le réseau public) a été **retiré**. Le fichier `backend/public/a22ping.php`, écrit une fois pour distinguer une panne PHP d'une panne Laravel, a été **supprimé** — `git status` le confirme. **Aucun fichier du produit n'a été modifié.** Le worktree `crmpro-wt-etape1a` n'a jamais été lu ni touché. Aucune écriture en production.
