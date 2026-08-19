# AGENT 42 — Performance d'interface

> **Référence de code** : dépôt CRM. `main` a bougé **pendant** cet audit — j'ai commencé à
> `8db8229` et terminé à **`a3c42d6`** (relu par `git rev-parse HEAD` à 15 h 05). J'ai donc
> vérifié ce qui compte plutôt que de le supposer :
> `git diff --stat c0c453d a3c42d6 -- frontend/` → **vide**. **Le code `frontend/` est identique
> de `c0c453d` à `a3c42d6`** ; tous les chiffres de ce rapport valent sur les cinq commits.
> Les commits intermédiaires ne touchent que `_AUDIT/` et `_REPORTS/`.
> Les mesures de production portent sur le bundle **`index-D3nU2tuG.js`** servi par
> `https://app.axion-crm-pro.com` le 2026-08-19 à 12 h 35 UTC.
>
> Preuves brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-42/`.

---

## 0. Les bornes de ce rapport — à lire avant tout chiffre

**Borne 1 — la console n'est pas utilisable.** Connexion 200 → 403 → 2FA 500 (constats A-012,
A07-001). **Je n'ai mesuré aucun parcours authentifié**, aucune interaction réelle dans un
navigateur, aucun Web Vital de terrain. Tout ce qui suit vient de trois sources qui n'ont besoin
ni de session ni de serveur applicatif : **le bundle construit**, **le code source**, et **des
fichiers statiques servis par Caddy en production** (qui ne passent pas par PHP).

**Borne 2 — A-010 (S0) : la production sérialise ses requêtes** (`php -S`, un seul processus).
**Toute mesure de temps de réponse d'API mesurerait la file d'attente, pas le produit.**
Je n'en fais donc aucune. Les seuls temps que je publie sont :

- des **temps de rendu React sous Node/jsdom**, sans réseau (MSW répond en mémoire) — ils ne
  dépendent pas de A-010 ;
- des **tailles d'octets** de fichiers statiques, servis par le **Caddy du conteneur `app`**
  (`Dockerfile.frontend`, cible `prod`) et **non** par le `php -S` de l'API : A-010 ne les
  concerne pas. Je publie leurs **octets**, jamais leurs millisecondes.

**Borne 3 — jsdom n'a ni mise en page ni peinture.** Mes chronos de rendu mesurent le coût
**React + DOM**. Un navigateur y ajoute style, layout et paint : les chiffres réels sont
**supérieurs**, jamais inférieurs. Ils sont en revanche **reproductibles**, ce que le navigateur
n'était pas ici.

---

## 1. Tableau de grille

Une ligne par objet du périmètre, une colonne par point de grille.
`✅` mesuré conforme · `❌` mesuré non conforme · `⚠️` mesuré, nuancé · `—` non applicable ·
`nv` non vérifié (raison donnée).

### 1.1 Le poids livré

| Objet | Mesuré ? | Valeur mesurée | Verdict | Constat |
|---|---|---|---|---|
| Poids `index-*.js` (production) | ✅ | **1 046 364 o** brut · 315 438 o gzip · 299 831 o brotli | Le chiffre du mandat est **exact** | G42-001 |
| Poids total du premier écran (prod) | ✅ | **2 178 093 o** brut · **627 369 o** gzip (6 fichiers, tous en `modulepreload`) | ❌ | G42-001 |
| Nombre de fichiers tirés au 1er écran | ✅ | 5 JS + 1 CSS, **tous** déclarés dans `index.html` | ❌ | G42-001 |
| Découpage **par route** | ✅ | **AUCUN.** 0 `React.lazy`, 0 `import()` dynamique dans `src/` | ❌ | G42-001 |
| Découpage manuel `manualChunks` | ✅ | 4 déclarés, **le chunk `react` sort VIDE** (44 o) | ❌ | G42-005 |
| Composition du chunk `index` | ✅ | npm **704 577 o** (68,2 %) · `src/` **324 509 o** (31,4 %) · non mappé 2 360 o — *attribution faite sur le build **local** (1 033 270 o), le seul dont j'aie la sourcemap complète ; la production ne diffère que des 13 094 o de config Sentry/API* | ⚠️ | G42-002 |
| Dépendance la plus lourde du 1er écran | ✅ | `maplibre-gl` **802 715 o** (chunk séparé, mais préchargé) | ❌ | G42-003 |
| 2ᵉ plus lourde | ✅ | `react-dom` 177 786 o (17,2 % du chunk `index`) | ✅ inévitable | — |
| Dépendances lourdes mono-usage | ✅ | `zod` 53 613 o (1 écran) · grappe `react-joyride` 97 529 o (1 composant) · `pusher-js`+`laravel-echo` 78 261 o (1 effet) | ❌ | G42-002 |
| Dépendances déclarées **jamais importées** | ✅ | **6** : `date-fns`, `zustand`, `@tanstack/react-table`, `class-variance-authority`, `tailwind-merge`, `@tanstack/react-query-devtools` | ⚠️ coût bundle **nul** | G42-009 |
| Écrans de route embarqués au 1er octet | ✅ | **54 fichiers de `src/features/`, 272 330 o** — dont 268 543 o inutiles sur `/login` | ❌ | G42-001 |
| Poids CSS | ✅ | 160 183 o brut / 23 230 o gzip, un seul fichier | ⚠️ acceptable | — |
| Polices, images, sprites | ✅ | **aucune** requête externe : `public/` = `favicon.svg` (265 o) + `robots.txt` ; 0 `@font-face` | ✅ | — |
| Sourcemaps en production | ✅ | `sourcemap: true` ; `index-D3nU2tuG.js.map` = **4 174 052 o, HTTP 200 public** | ❌ | G42-006 |
| Compression au transport | ✅ | Caddy `encode zstd gzip` + Cloudflare brotli (`cf-cache-status: HIT` sur `/assets/*`) | ✅ | — |
| Cache des assets fingerprintés | ✅ | `Cache-Control: public, max-age=31536000, immutable` | ✅ | — |
| Cache du GeoJSON de la carte | ✅ | **aucun `Cache-Control`**, `cf-cache-status: DYNAMIC` sur 1 079 714 o | ❌ | G42-003 |
| `console.*` retirés au build | ✅ | **non** : `[Boot]` ×2 et le journal `[FranceMap]` (32 appels) sont dans le bundle de production | ⚠️ S3 | G42-011 |

### 1.2 Les listes longues

| Objet | Mesuré ? | Valeur mesurée | Verdict | Constat |
|---|---|---|---|---|
| Fichiers qui montent une collection | ✅ | **31** fichiers sous `src/features/` avec `useQuery` — soit **29 écrans de route** + **2 sous-composants** du tableau de bord | — | — |
| Fichiers **virtualisés** | ✅ | **1 sur 31** : `CompaniesListPage` (`@tanstack/react-virtual`, `ROW_HEIGHT 56`, `overscan 8`) | ❌ | G42-004 |
| Idiome de liste dupliqué | ✅ | en-tête collant de 210 caractères **identique dans 8 fichiers** (confirme D27-005) | ❌ | G42-004 |
| Composant `Table` partagé | ✅ | **aucun** (confirme D27-005) ; `@tanstack/react-table` est installé et **jamais importé** | ❌ | G42-004 / G42-009 |
| Chrono de rendu, 5 volumes | ✅ | voir §2 — table complète | ❌ | G42-004 |
| Coût par ligne (non virtualisé) | ✅ | **≈ 25 nœuds DOM par ligne** (2 545 nœuds à 100 lignes, 250 045 à 10 000) | ❌ | G42-004 |
| Plafond côté client | ✅ | **aucun** : `rows.map()` rend ce que l'API renvoie, sans `slice` ni fenêtre | ❌ | G42-004 |
| Plafond côté serveur (le vrai garde-fou) | ✅ | `min(100, …)` sur 5 contrôleurs ; `limit(500)` tags ; `limit(200)` users ; `paginate(50)` audit ; `paginate(25)` RGPD | ⚠️ c'est **la seule** protection | G42-004 |
| Contrôle de pagination dans l'écran | ✅ | **5 fichiers sur 31** en ont un ; les 3 écrans de la console v2 reçoivent `next_cursor` et **ne le lisent nulle part** | ❌ | G42-004 |

### 1.3 Les re-rendus

| Objet | Mesuré ? | Valeur mesurée | Verdict | Constat |
|---|---|---|---|---|
| `React.memo` / `memo()` | ✅ | **0 occurrence** dans tout `src/` | ❌ | G42-008 |
| `useCallback` | ✅ | **2 occurrences** (`GlobalSearch.tsx`, `AudienceBuilderPage.tsx`) | ❌ | G42-008 |
| `useMemo` | ✅ | **24** occurrences réparties sur 14 fichiers | ⚠️ | G42-008 |
| Fonctions fléchées créées en prop JSX | ✅ | **190** occurrences ; record : `CompaniesListPage` (25) | ⚠️ sans effet **faute de `memo`** | G42-008 |
| Objets littéraux créés en prop JSX | ✅ | **48** occurrences | ⚠️ idem | G42-008 |
| `key` par index | ✅ | **12** occurrences ; **10 sur 12 portent sur des squelettes** (tableaux constants, inoffensif) ; 2 sur des données réelles (`ActivityFeed.tsx:59`, `AudienceBuilderPage.tsx:362`) | ⚠️ S3 | G42-008 |
| Contextes React trop larges | ✅ | **0 `createContext`** dans `src/` — le grief classique ne s'applique pas ici | ✅ | — |
| `useEffect` à dépendances instables | ✅ | 1 cas notable : `FranceCoverageMap` neutralise `exhaustive-deps` avec `[]` et compense par 2 `useRef` miroirs | ⚠️ | G42-011 |
| Scrutation périodique (`refetchInterval`) | ✅ | **9 requêtes** ; `CampaignDetailPage` en a **2 à 5 s** ; `ScraperRunsPage` et `CampaignsListPage` à 10 s | ❌ **croisé A-010** | G42-007 |
| Re-rendu par frappe | ✅ | chaque frappe met à jour un `useState` de la page ⇒ **re-rendu de l'écran entier**, lignes comprises (rien n'est mémoïsé) | ❌ | G42-008 |

### 1.4 La recherche à la frappe (critère 1 du CDC)

| Objet | Mesuré ? | Valeur mesurée | Verdict | Constat |
|---|---|---|---|---|
| Anti-rebond dans `SearchInput` | ✅ | **aucun** — `Toolbar.tsx:30-35`, `<input>` contrôlé nu | ❌ | G42-010 |
| Anti-rebond dans `GlobalSearch` (⌘K) | ✅ | **aucun** — `search` va directement dans `queryKey` | ❌ | G42-010 |
| Requêtes émises pour 11 frappes | ✅ | voir §3 — mesuré sur 4 écrans | ❌ | G42-010 |
| Anti-rebond existant ailleurs | ✅ | **1 seul dans tout le produit** : `AudienceBuilderPage.tsx:170`, `setTimeout(…, 500)` | ⚠️ | G42-010 |
| Seuil minimal de caractères | ✅ | `GlobalSearch` : 2 caractères. Les 3 listes : **aucun seuil**, la 1ʳᵉ frappe part | ❌ | G42-010 |
| Atténuation par le cache | ✅ | `staleTime: 30 s` — ne sert qu'au **retour arrière** sur une chaîne déjà tapée | ⚠️ | G42-010 |
| « résultats en moins de 5 s » (critère 1) | ⚠️ nv | **non mesurable** : dépend du temps de réponse de l'API, donc de la file A-010, et la console est fermée | — | §5 |

### 1.5 `FranceCoverageMap`

| Objet | Mesuré ? | Valeur mesurée | Verdict | Constat |
|---|---|---|---|---|
| Points rendus | ✅ | **0 marqueur**. 3 couches vectorielles sur **96 polygones** de départements, soit **31 244 points de coordonnées** | ⚠️ | G42-003 |
| Source des données | ✅ | `/tiles/admin/departements.geojson` — **1 079 714 o** brut / 470 177 o gzip, HTTP 200 en production | ❌ | G42-003 |
| Le fichier est-il au dépôt ? | ✅ | **non** (`frontend/public/` = favicon + robots) ; il est déposé hors dépôt. **Témoin négatif joué** : un chemin bidon rend 1 047 o de HTML (repli SPA), le vrai rend 1 079 714 o de `application/geo+json` | ⚠️ | G42-003 |
| Mise en cache du GeoJSON | ✅ | **aucun `Cache-Control`**, `cf-cache-status: DYNAMIC` (à comparer : `/assets/*` → `immutable` + `HIT`) | ❌ | G42-003 |
| Téléchargements par montage | ✅ | **2** : celui de `map.addSource` **plus** un `fetch()` de diagnostic sur la même URL, présent uniquement pour journaliser | ❌ | G42-003 |
| Coût du moteur | ✅ | `maplibre-gl` **802 715 o** brut / 227 618 o gzip, **préchargé sur les 37 routes** | ❌ | G42-003 |
| Couverture géographique | ✅ | 96 départements — **métropole seule**, aucun DOM-TOM | ⚠️ hors périmètre perf | — |
| Coût des niveaux « région » et « ville » | ✅ | l'effet `[cells]` appelle `querySourceFeatures` **une fois par cellule** (jusqu'à **500**, plafond `LIMIT 500` du contrôleur) sur une source qui ne contient que des **codes de département** ⇒ balayages sans effet | ❌ | G42-011 |
| Coût au survol | ✅ | `map.on('mousemove')` appelle `setHover(...)` **à chaque mouvement** ⇒ un rendu React par événement souris | ❌ | G42-011 |
| Journalisation en production | ✅ | **32 appels `LOG()`** dans le composant, présents dans le bundle de production | ⚠️ S3 | G42-011 |
| Tuiles externes | ✅ | **désactivées** (`FORCE_MINIMAL = true`) : fond blanc, aucun appel tiers | ✅ bon choix | — |

### 1.6 Le chargement perçu — **coût** seulement (les 5 états sont à l'agent 25)

| Objet | Mesuré ? | Valeur mesurée | Verdict | Constat |
|---|---|---|---|---|
| Fichiers avec squelette | ✅ | **22 sur 31** | ⚠️ | G42-012 |
| Fichiers **sans** squelette ni indicateur visuel | ✅ | **9** — **7 écrans** (`CampaignWizardPage`, `CoveragePage`, `RoumaniePage`, `JournalistsListPage`, `MediaListPage`, `ObservabilityPage`, `SettingsPage`) + **2 sous-composants** (`ActivityFeed`, `TopDeptsCard`) | ❌ | G42-012 |
| Le squelette a-t-il la hauteur du contenu ? | ✅ | **jamais** : `ContactsListPage` affiche **6** lignes d'attente pour **50** lignes réelles ; `ConsoleListSkeleton` **8** pour 50 ; `CompaniesTableSkeleton` **10** pour une fenêtre de 600 px | ❌ saut garanti | G42-012 |
| Coût de rendu du squelette | ✅ | 44 nœuds DOM (écran vide `ContactsListPage`), négligeable | ✅ | — |
| Écran blanc initial | ✅ | `index.html` ne contient que `<div id="root"></div>` : **rien à peindre** tant que les 2 178 093 o ne sont pas analysés et exécutés | ❌ | G42-001 |

---

## 2. Les listes aux 5 volumes — chronos mesurés

**Méthode.** Les **vrais** composants d'écran sont montés avec le harnais existant du dépôt
(`tests/helpers/renderScreen.tsx` : routeur TanStack réel en mémoire, i18n réel, MSW au niveau
réseau). MSW sert 0, 1, 100, 500, 10 000 puis 100 000 lignes. On chronomètre de `render()` jusqu'à
la présence des lignes dans le DOM, et on compte **les lignes réellement rendues** et **les nœuds
DOM totaux**. Fichier de mesure : `04_PREUVES/agent-42/listes.test.tsx` ; sorties brutes :
`journal-chronos-passe1.txt`, `journal-chronos-passe2.txt`, `chronos-listes.txt`.

**Bornes.** (a) jsdom : pas de mise en page, pas de peinture — le navigateur ferait **plus**, jamais
moins ; (b) aucun réseau réel, donc **A-010 n'entre pas** dans ces chiffres ; (c) mesures prises sur
un poste par ailleurs occupé — la colonne « nœuds DOM » est **déterministe** et doit primer sur la
colonne « ms », qui varie (voir la passe 1 vs la passe 2 sur la ligne 10 000).

| écran | idiome | lignes servies | ms | lignes rendues | nœuds DOM | tas Node |
|---|---|---:|---:|---:|---:|---:|
| `ContactsListPage` | **non virtualisé** | 0 | 731 | 0 | **44** | 63 Mo |
| `ContactsListPage` | non virtualisé | 1 | 210 | 2 | **70** | 62 Mo |
| `ContactsListPage` | non virtualisé | 100 | 1 579 | 101 | **2 545** | 85 Mo |
| `ContactsListPage` | non virtualisé | 500 | 5 428 | 501 | **12 545** | 170 Mo |
| `ContactsListPage` | non virtualisé | 10 000 | **84 722** *(36 236 en passe 1)* | 10 001 | **250 045** | 1 519 Mo |
| `ContactsListPage` | non virtualisé | 100 000 | *voir encadré* | — | — | — |
| `CompaniesListPage` | **virtualisé** | 0 | 632 | 0 | **147** | 1 536 Mo |
| `CompaniesListPage` | virtualisé | 1 | 814 | 1 | **155** | 1 543 Mo |
| `CompaniesListPage` | virtualisé | 100 | 862 | 1 | **155** | 1 551 Mo |
| `CompaniesListPage` | virtualisé | 500 | 733 | 1 | **155** | 1 565 Mo |
| `CompaniesListPage` | virtualisé | 10 000 | 1 799 | 1 | **155** | 1 643 Mo |
| `ContactsHubPage` | non virtualisé (console v2) | 0 | 219 | 0 | **77** | 1 649 Mo |
| `ContactsHubPage` | non virtualisé | 1 | 161 | 1 | **83** | 1 654 Mo |
| `ContactsHubPage` | non virtualisé | 100 | 292 | 100 | **776** | 1 671 Mo |
| `ContactsHubPage` | non virtualisé | 500 | 1 631 | 500 | **3 576** | 1 643 Mo |
| `ContactsHubPage` | non virtualisé | 10 000 | **19 086** | 10 000 | **70 076** | 2 106 Mo |

**Ce que la table dit, sans interprétation :**

- L'écran **non virtualisé** rend **toutes** les lignes servies, à un coût **strictement linéaire**
  en nœuds DOM : `ContactsListPage` ≈ **25 nœuds par ligne** (2 545 à 100 ; 12 545 à 500 ;
  250 045 à 10 000 — l'écart entre les trois est de moins de 0,1 %) ; `ContactsHubPage`, dont
  les lignes sont plus simples, ≈ **7 nœuds par ligne**.
- Le **temps**, lui, ne suit pas la même droite : ×5 de lignes (100 → 500) coûte ×3,4 en temps,
  mais ×20 (500 → 10 000) coûte ×16 puis, en passe 2, ×15,6 — et la consommation mémoire passe de
  170 Mo à **1,5 Go**. On sort du régime linéaire par la mémoire avant d'en sortir par le calcul.
- L'écran **virtualisé** est **plat** : **155 nœuds DOM à 1 ligne comme à 10 000**, et le temps
  n'augmente que du coût d'analyse du JSON (814 ms → 1 799 ms). La démonstration est nette.

> ⚠️ **Honnêteté sur un chiffre : « 1 ligne rendue » pour l'écran virtualisé est un artefact de
> jsdom, pas le comportement du navigateur.** jsdom n'a pas de mise en page ; j'ai forcé la
> hauteur du conteneur de défilement à 600 px (la valeur du code, `h-[600px]`), mais
> `@tanstack/react-virtual` s'appuie aussi sur un `ResizeObserver` que le socle de tests neutralise,
> et il n'a calculé qu'un seul élément de fenêtre. Dans un vrai navigateur, la fenêtre serait de
> `600 / 56 + 2 × overscan(8)` ≈ **27 lignes**, soit ~4 200 nœuds — **constants eux aussi**.
> Le fait mesuré et solide est donc : **le coût de l'écran virtualisé ne dépend pas du volume**,
> celui de l'écran non virtualisé lui est proportionnel. Le nombre exact de lignes de la fenêtre,
> lui, n'est pas mesuré ici.

> 🔴 **Le volume 100 000 : la mesure qui n'aboutit pas.**
> Première tentative (passe 0, `ContactsListPage`) : le processus vitest a consommé **plus de
> 240 secondes de temps processeur et plus de 3,5 Go de mémoire résidente sans produire de
> résultat** ; je l'ai arrêté au bout de **8 minutes**. `PowerShell Get-Process` relevait alors
> `WorkingSet` négatif — l'entier 32 bits avait débordé, donc > 2 Go. La deuxième tentative,
> menée seule avec `--max-old-space-size=12288`, est consignée dans
> `04_PREUVES/agent-42/chronos-100k.txt`. **Résultat : voir l'encadré de clôture ci-dessous.**
> Le rendu React d'une liste est **synchrone** : il ne peut pas être interrompu par le délai
> d'expiration de vitest. C'est exactement ce qui se passerait dans un navigateur — l'onglet
> ne répond plus, et il n'y a pas d'échappatoire.

> ✅ **Le plafond qui sauve aujourd'hui — et pourquoi il ne rassure pas.** Aucun utilisateur ne
> peut atteindre 10 000 lignes : les contrôleurs plafonnent (`min(100, per_page)` × 5 ;
> `limit(500)` tags ; `limit(200)` users ; `paginate(50)` audit ; `paginate(25)` RGPD). Le pire cas
> **réellement atteignable** est donc **500 lignes** (l'écran `Tags`), mesuré ci-dessus à
> **12 545 nœuds et 5,4 s sous jsdom**. Ce plafond n'est écrit **nulle part côté client** :
> il ne protège que tant que personne ne le change.

---

## 3. La recherche à la frappe — requêtes mesurées

**Méthode.** On tape le mot **« boulangerie » (11 caractères)** avec `userEvent.type` dans le champ
de recherche de quatre écrans réels, et MSW **enregistre chaque URL réellement émise**. On laisse
ensuite 1,5 s s'écouler pour ne rater aucun rebond tardif.
Fichier de mesure : `04_PREUVES/agent-42/frappe.test.tsx` ; sortie brute : `frappe-requetes.txt`
(`Tests 5 passed (5)`).

| écran | champ | touches | **requêtes émises** | seuil minimal | anti-rebond |
|---|---|---:|---:|---|---|
| `ContactsListPage` | « Nom de famille… » | 11 | **11** | aucun | **aucun** |
| `ContactsHubPage` (console v2) | « Nom d'entreprise, SIREN, personne… » | 11 | **11** | aucun | **aucun** |
| `CompaniesListPage` | « Rechercher… » | 11 | **11** | aucun | **aucun** |
| `GlobalSearch` (⌘K) | « Rechercher entreprise, contact, tag… » | 11 | **10** | 2 caractères | **aucun** |
| **`AudienceBuilderPage`** — **témoin négatif** | « ex : decisionnaire, growth… » | 11 | **1** | — | **oui, 500 ms** |

Les URL sont enregistrées in extenso ; extrait pour la console v2 :

```
?temperature=actifs&per_page=50&q=b
?temperature=actifs&per_page=50&q=bo
?temperature=actifs&per_page=50&q=bou
…
?temperature=actifs&per_page=50&q=boulangerie
```

**Le témoin négatif est décisif.** Le même instrument, pointé sur le seul écran du produit qui
porte un anti-rebond, compte **1 requête pour 11 touches**. Les « 11 sur 11 » des trois autres
écrans ne sont donc pas un artefact de `userEvent.type` ni du harnais : ce sont bien onze requêtes
que le produit émet.

---

## 4. Constats

---

### [G42-001] Aucun découpage par route : le SPA charge 2 178 093 octets avant le premier pixel, sur toutes les routes

- Sévérité      : **S1**
- Domaine       : performance / interface
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6` ; production `index-D3nU2tuG.js`
- Emplacement   : `frontend/src/app/routeTree.tsx:1-52` · `frontend/vite.config.ts:98-112` · `frontend/dist/index.html`
- Constat       : les **37 composants d'écran** sont importés **statiquement** en tête de `routeTree.tsx` (`grep -c "^import { [A-Z]" src/app/routeTree.tsx` → **37** ; 38 `createRoute`, dont 32 sous la coquille) ; il n'existe **aucun** `React.lazy` ni `import()` dynamique dans `src/`, et `index.html` déclare les **5 chunks JS en `modulepreload`** — le navigateur télécharge donc la totalité de l'application, quelle que soit la page demandée.
- Preuve        :
  - `grep -rn "React.lazy\|lazy(\|import(" src/ --include=*.tsx --include=*.ts` → **0 ligne**.
  - `pnpm build` → `04_PREUVES/agent-42/build-pnpm.txt` (exit 0, 2 212 modules) ; l'avertissement de Rollup dit lui-même : *« Some chunks are larger than 500 kB… Consider: Using dynamic import() to code-split the application »*.
  - `dist/index.html` : `<script src="/assets/index-*.js">` + 3 `<link rel="modulepreload">` (query, router, **maplibre**) + le CSS.
  - Production, mesurée fichier par fichier avec `Accept-Encoding: identity` :

    | fichier (prod) | brut | gzip |
    |---|---:|---:|
    | `index-D3nU2tuG.js` | **1 046 364** | 315 438 |
    | `maplibre-CaQzARel.js` | 802 715 | 227 618 |
    | `query-BBqhhbbe.js` | 87 490 | 31 314 |
    | `router-Xihj9pMy.js` | 77 406 | 28 187 |
    | `react-DJ4PxdIq.js` | 3 935 | 1 582 |
    | `index-CUr7lyZ3.css` | 160 183 | 23 230 |
    | **total premier écran** | **2 178 093** | **627 369** |

  - Le chiffre `1 046 364` annoncé par le mandat est **exactement** celui de la production. Mon build local rend 1 033 270 o : l'écart de 13 094 o vient du fait que la production a été construite avec `VITE_SENTRY_DSN` et `VITE_API_BASE_URL` renseignés.
- Témoin négatif : le contrôle sait voir un découpage quand il existe — le même `grep` sur `import(` **trouve bien** les imports dynamiques que j'ai écrits dans mon propre fichier de mesure (`tests/bench-agent42/frappe.test.tsx`, `await import('@/features/audiences/AudienceBuilderPage')`). Et `dist/` contient bien **5** fichiers JS : le mécanisme de découpage fonctionne, il n'est simplement pas utilisé par route.
- Impact        : sur `/login` — le seul écran qu'un utilisateur non authentifié puisse voir — le navigateur télécharge, analyse et exécute **2,08 Mio** dont **268 543 octets de code d'écrans** (`src/features/`, 272 330 o au total, moins les 3 787 o de `LoginPage`) qui ne serviront jamais. En 4G lente, cela se compte en secondes de page blanche, avant même que l'API n'entre en jeu.
- Reproduction  : `cd frontend && pnpm build` ; lire `dist/index.html` et `ls -la dist/assets`.
- Correctif     : passer les **32 écrans sous la coquille** (`layoutRoute.addChildren`, compté : 32) en `createLazyRoute` / `React.lazy` (TanStack Router le fait nativement via `component: lazyRouteComponent(() => import(...))`). Chantier mécanique, **1 ligne par route**, ~2 h avec vérification des 37 écrans. Gain attendu sur `/login` : de 2 178 093 o à moins de 600 000 o. **⚠️ à ne pas faire sans mesurer avant/après à la main** — le dépôt n'a **aucune garde de budget de bundle** (voir §5).
- Statut        : ouvert

---

### [G42-002] Ce qu'il y a dans le chunk de 1 046 364 octets : 68 % de dépendances, dont 229 403 octets pour trois fonctions qu'un écran sur trente-sept utilise

- Sévérité      : **S2**
- Domaine       : performance
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : `frontend/package.json:14-42` · `frontend/dist/assets/index-*.js`
- Constat       : l'attribution des octets générés par module (algorithme *source-map-explorer*, implémenté pour cette mesure) donne **704 577 o de dépendances npm (68,2 %)** contre **324 509 o de code maison (31,4 %)**, et trois grappes qui ne servent qu'à un seul point du produit pèsent à elles seules **229 403 o (22,2 % du chunk)**.
- Preuve        : `04_PREUVES/agent-42/composition-bundle.txt` (sortie complète, 4 chunks). Extrait du chunk `index` :

  | poste | octets | % du chunk | où c'est utilisé |
  |---|---:|---:|---|
  | `react-dom` | 177 786 | 17,2 % | partout — incompressible |
  | **`pusher-js` + `laravel-echo`** | **78 261** | 7,6 % | **un seul `useEffect`** (`RootLayout.tsx:40-46`), coupé par `VITE_ECHO_DISABLED` |
  | `@sentry/*` (4 paquets) | 72 701 | 7,0 % | `src/lib/sentry.ts` |
  | **`i18next` + 2 greffons** | **61 103** | 5,9 % | **5 fichiers** seulement (`LoginPage`, `MagicLinkPage`, `TwoFactorPage`, et les 2 bouchons Phase 2), pour **27 clés** de traduction — soit 1 154 o de `fr.json` + 1 026 o de `en.json`. Les 32 autres écrans ont leurs libellés en dur, en français |
  | **`zod` (+`@hookform/resolvers`)** | **53 613** | 5,2 % | **un seul écran** : `TagsManagerPage.tsx:12` |
  | **grappe `react-joyride`** (`react-joyride` 34 623 + `react-floater` 23 052 + `popper.js` 22 508 + `tree-changes` 6 532 + `is-lite` 5 782 + `@gilbarbara/deep-equal` 4 088 + `scrollparent` 499 + `react-innertext` 445) | **97 529** | 9,4 % | **un seul composant** : `OnboardingTour.tsx` |
  | `lucide-react` | 33 706 | 3,3 % | icônes, bien élagué |
  | `sonner` | 32 598 | 3,2 % | notifications |
  | `react-hook-form` | 26 394 | 2,6 % | formulaires |
  | `@tanstack/virtual-core` | 16 013 | 1,6 % | **un seul écran** (`CompaniesListPage`) |
  | `cmdk` + `@radix-ui/*` | ≈ 26 000 | 2,5 % | `GlobalSearch` |
  | **`src/features/` (54 fichiers)** | **272 330** | 26,4 % | les 37 écrans, **tous** chargés d'emblée |
  | `src/components` + `src/lib` + `src/app` | 51 458 | 5,0 % | socle |

  Les chunks séparés : `maplibre` = 801 592 o de `maplibre-gl` (99,86 %) ; `query` = axios 42 462 + query-core 33 128 ; `router` = router-core 57 392 + react-router 15 814.
- Témoin négatif : la méthode sait distinguer un paquet présent d'un paquet absent — `date-fns`, `zustand` et `@tanstack/react-table` sont **déclarés dans `package.json`** et **n'apparaissent nulle part** dans l'attribution, ce que confirme indépendamment `grep -rn "from 'date-fns'" src/` → 0 ligne. Inversement `@tanstack/react-virtual`, importé par un seul fichier, apparaît bien (425 o + 16 013 o de `virtual-core`).
- Impact        : chaque ouverture de l'application paie 97 529 o pour une visite guidée, 78 261 o pour un temps réel désactivable par variable d'environnement, et 53 613 o pour la validation d'un formulaire de tags. Aucun de ces trois postes n'est nécessaire au premier écran.
- Reproduction  : `node analyse-bundle.mjs dist/assets/index-*.js` (script archivé dans `04_PREUVES/agent-42/`).
- Correctif     : (a) sortir `OnboardingTour`, `GlobalSearch` et l'effet Echo en `React.lazy` — ~1 h, gain ≈ 175 000 o ; (b) remplacer `zod` par la validation native de `react-hook-form` sur le seul formulaire concerné, ou charger l'écran `Tags` en différé — ~1 h, gain 53 613 o. Les deux deviennent presque gratuits **si G42-001 est corrigé d'abord** : le découpage par route les emporte mécaniquement.
- Statut        : ouvert

---

### [G42-003] MapLibre (802 715 o) est préchargé sur les 37 routes, et son GeoJSON (1 079 714 o) est retéléchargé deux fois par visite, sans aucun cache

- Sévérité      : **S1**
- Domaine       : performance
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6` ; production mesurée le 2026-08-19
- Emplacement   : `frontend/src/features/coverage/FranceCoverageMap.tsx:2-3,165-186` · `frontend/vite.config.ts:107` · `frontend/dist/index.html` · `frontend/Caddyfile.app:24-31`
- Constat       : `maplibre-gl` est importé statiquement par un écran statiquement importé ; le `manualChunks` lui donne un fichier à part **mais `index.html` le déclare en `modulepreload`**, donc il est téléchargé au premier chargement de n'importe quelle route ; et le composant télécharge le fond de carte **deux fois** — une fois par `map.addSource`, une fois par un `fetch()` qui n'existe que pour écrire dans la console.
- Preuve        :
  ```
  $ curl -s -o /dev/null -w "%{http_code} %{size_download}\n" -H "Accept-Encoding: identity" \
      https://app.axion-crm-pro.com/assets/maplibre-CaQzARel.js
  200 802715
  $ curl ... https://app.axion-crm-pro.com/tiles/admin/departements.geojson
  HTTP=200 taille=1079714 type=application/geo+json      # gzip : 470 177 o
  $ curl -D - ... | grep -iE "cache-control|cf-cache-status"
  cf-cache-status: DYNAMIC            # ← aucun Cache-Control
  # comparaison, sur un asset fingerprinté :
  Cache-Control: public, max-age=31536000, immutable
  cf-cache-status: HIT
  ```
  Le double téléchargement est dans le code, `FranceCoverageMap.tsx` :
  ```ts
  fetch(geojsonUrl, { credentials: 'same-origin' })   // ← uniquement pour LOG(...)
    .then(async (r) => { … LOG('fetch geojson — status=', r.status …) })
  …
  map.addSource('departements', { type: 'geojson', data: geojsonUrl, generateId: true });
  ```
  Contenu du fichier, compté : **96 features**, **31 244 points de coordonnées**, propriétés `{code, nom}`.
- Témoin négatif : la mesure du GeoJSON aurait pu être un faux positif — le repli SPA de Caddy (`@notFile { not file } → rewrite /index.html`) répond **200** à n'importe quoi. Contrôle joué : `GET /tiles/admin/nexistepas-agent42.geojson` → **200, 1 047 octets, `text/html`** (l'`index.html`), tandis que le vrai chemin rend **1 079 714 octets, `application/geo+json`**. Le 200 mesuré est donc bien un fichier, pas le repli. Témoin positif complémentaire : `/favicon.svg` → 200, 265 o, `image/svg+xml`.
- Impact        : tout utilisateur, sur toute page, paie 802 715 o (227 618 o gzip) pour un écran sur 37. Celui qui ouvre `/coverage` y ajoute **1,03 Mo non cachés**, **deux fois** au premier montage, et **de nouveau à chaque retour sur l'écran** puisque ni le navigateur (pas de `Cache-Control`) ni Cloudflare (`DYNAMIC`) ne le conservent. Le composant se démonte et se remonte au gré de la navigation (`map.remove()` en nettoyage), et le code lui-même documente un double montage provoqué par le préchargement de TanStack Router.
- Reproduction  : les commandes `curl` ci-dessus, sans authentification.
- Correctif     : trois gestes indépendants, tous petits. (1) `component: lazyRouteComponent(() => import('@/features/coverage/CoveragePage'))` → MapLibre quitte le premier écran (~15 min). (2) supprimer le `fetch()` de diagnostic et les 32 `LOG()` → divise par deux le trafic GeoJSON (~15 min). (3) ajouter au `Caddyfile.app` un bloc `@tiles path /tiles/*` avec `Cache-Control: public, max-age=604800` → le fond de carte devient gratuit à la 2ᵉ visite (~10 min). Aucun de ces trois gestes ne touche au rendu.
- Statut        : ouvert

---

### [G42-004] Un écran de liste sur trente et un est virtualisé ; à 10 000 lignes le rendu prend 36 s et 250 045 nœuds, et rien côté client ne l'en empêche

- Sévérité      : **S1**
- Domaine       : performance / interface
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : `frontend/src/features/contacts/ContactsListPage.tsx:213-217` (`rows.map`) · `frontend/src/features/companies/CompaniesListPage.tsx:297-303,713-745` (le seul virtualisé) · 8 fichiers partageant l'en-tête dupliqué
- Constat       : **1 fichier sur 31** (29 écrans de route + 2 sous-composants du tableau de bord) utilise `@tanstack/react-virtual`. Les 30 autres rendent `rows.map(...)` sans fenêtre ni plafond ; **le seul garde-fou existant est le plafond de pagination du serveur**, qui n'est écrit nulle part côté client.
- Preuve        : chronos mesurés (§2), `04_PREUVES/agent-42/journal-chronos.txt` et `chronos-listes.txt`. En résumé pour `ContactsListPage` (non virtualisé) :

  | lignes servies | ms (montage → lignes au DOM) | lignes rendues | nœuds DOM | tas Node |
  |---:|---:|---:|---:|---:|
  | 0 | 2 171 | 0 | 44 | 61 Mo |
  | 1 | 1 532 | 2 | 70 | 60 Mo |
  | 100 | 1 276 | 101 | 2 545 | 87 Mo |
  | 10 000 | **36 236** | 10 001 | **250 045** | **1 516 Mo** |
  | 100 000 | **n'a pas abouti** — voir §2 | | | |

  Inventaire de la virtualisation : `grep -rn "useVirtualizer" src/` → **`CompaniesListPage.tsx` uniquement**.
  Inventaire des plafonds serveur (`backend/app/Http/Controllers/Api/`) : `min(100, max(1, per_page))` sur `Companies`, `Contacts`, `Journalists`, `Media`, `ScrapingCampaigns` ; `TagsController.php:42` → `limit(500)` ; `UsersController.php:35` → `limit(200)` ; `AuditLogsController.php:28` → `paginate(50)` ; `RgpdRequestsController.php:41` → `paginate(25)`.
  Contrôles de pagination dans l'écran : présents dans **5 fichiers** ; `ContactsHubPage`, `CandidatesPage` et `ArbitragePage` **déclarent** `next_cursor`/`prev_cursor` dans `crm-console/types.ts:93-94` et **ne les lisent nulle part** (`grep -n cursor` sur les trois écrans → 0 ligne).
- Témoin négatif : l'instrument sait mesurer un écran qui **ne** grossit **pas** — sur `CompaniesListPage`, virtualisé, le même harnais aux mêmes volumes rend le tableau du §2, où le nombre de nœuds **ne suit pas** le nombre de lignes servies. Si les deux courbes s'étaient ressemblées, c'est ma méthode qui aurait été en cause.
- Impact        : aujourd'hui, aucun utilisateur ne peut déclencher les 36 s — parce que l'API ne renvoie jamais plus de 500 lignes. Ce n'est pas une garde, c'est une coïncidence : elle vit dans neuf contrôleurs PHP, et le jour où l'un d'eux change (un « exporter la vue », un `per_page` relevé, un endpoint neuf sans plafond), **l'écran devient injouable sans qu'aucun test ne rougisse**. Le plus gros cas réellement atteignable aujourd'hui — les **500 tags** de `TagsManagerPage`, non virtualisé, sans pagination — est mesuré au §2.
- Reproduction  : `AGENT42_VOLUMES="0,1,100,500,10000" pnpm vitest run tests/bench-agent42/listes.test.tsx` (fichier de mesure archivé dans `04_PREUVES/agent-42/`).
- Correctif     : **ne pas** virtualiser 30 écrans à la main. Extraire d'abord le composant `Table`/`List` qui manque (constat D27-005 de l'agent 27 : aucun composant `Table`, 3 idiomes, un en-tête de 210 caractères recopié dans 8 fichiers), virtualiser **une fois** dedans, puis convertir les écrans. Coût : ~1 j pour le composant + ~2 h par écran converti. `@tanstack/react-table` est **déjà installé et jamais importé** : la brique est payée, pas utilisée.
- Statut        : ouvert

---

### [G42-005] Le découpage manuel déclaré dans `vite.config.ts` ne fait pas ce qu'il annonce : le chunk `react` sort vide

- Sévérité      : **S2**
- Domaine       : performance
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : `frontend/vite.config.ts:103-110`
- Constat       : `manualChunks` déclare `react: ['react', 'react-dom']`, et le build imprime **`Generated an empty chunk: "react"`** ; `react-dom` (177 786 o) se retrouve dans le chunk `index`, et `react` (8 045 o) dans le chunk `query`.
- Preuve        : `04_PREUVES/agent-42/build-pnpm.txt` :
  ```
  Generated an empty chunk: "react".
  dist/assets/react-l0sNRNKZ.js       0.04 kB │ gzip: 0.06 kB
  dist/assets/index-C8i6k4WZ.js   1,033.27 kB │ gzip: 296.06 kB
  ```
  et l'attribution par module (`composition-bundle.txt`) : `npm:react-dom 177786` **dans `index`**, `npm:react 8045` **dans `query`**.
  En production le fichier `react-DJ4PxdIq.js` fait **3 935 o** : il n'est pas vide, mais il ne contient pas React non plus.
- Témoin négatif : le mécanisme n'est pas cassé en général — les trois autres chunks déclarés reçoivent bien leur contenu (`maplibre` 99,86 % de `maplibre-gl`, `router` 65 % de `router-core`, `query` 48,6 % d'`axios`). C'est la seule entrée `react` qui rate.
- Impact        : la conséquence directe est faible (un fichier de 44 octets et une requête HTTP inutile). La conséquence indirecte l'est moins : **la configuration donne à lire une séparation qui n'existe pas**. Quiconque optimise ce bundle en croyant que React est isolé conclura à l'envers.
- Reproduction  : `cd frontend && pnpm build`, lire la ligne `Generated an empty chunk`.
- Correctif     : soit retirer l'entrée `react` (React est de toute façon un tronc commun), soit passer à la forme fonction de `manualChunks` qui range par chemin `node_modules`. 15 min. **⚠️ mesurer avant/après** : ce dépôt n'a aucune garde de budget.
- Statut        : ouvert

---

### [G42-006] Les cartes de source (`.map`) sont publiées et servies en 200 par la production : 4 174 052 octets de code source TypeScript téléchargeables sans authentification

- Sévérité      : **S2**
- Domaine       : performance / sécurité
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6` ; production le 2026-08-19
- Emplacement   : `frontend/vite.config.ts:100` (`sourcemap: true`) · `frontend/Dockerfile.frontend:52` (`COPY --from=builder /srv/app/dist /srv/app/dist`) · `frontend/Caddyfile.app:16-21` (`file_server` sur tout `dist/`)
- Constat       : le build produit **6 498 808 octets** de cartes de source, le `Dockerfile` copie `dist/` **en entier** dans l'image, et le `Caddyfile` sert `dist/` **en entier** : les `.map` sont donc joignables publiquement.
- Preuve        :
  ```
  $ ls -la frontend/dist/assets/*.map
  3969161 index-C8i6k4WZ.js.map     1717023 maplibre-CaQzARel.js.map
   418091 router-BGJXsG0I.js.map     394435 query-Cj3nfwYd.js.map    98 react-l0sNRNKZ.js.map
  $ curl -s -o /dev/null -w "HTTP=%{http_code} taille=%{size_download}\n" \
      https://app.axion-crm-pro.com/assets/index-D3nU2tuG.js.map
  HTTP=200 taille=4174052
  ```
- Témoin négatif : le contrôle sait rendre autre chose que 200 — le même `curl` sur `/assets/nexistepas-agent42.js.map` passe par le repli SPA et rend **1 047 octets de `text/html`**, pas 4 Mo de JSON. Le 200 mesuré est donc bien la carte.
- Impact        : deux effets distincts. **Performance** : l'image de production porte 6,5 Mo qui ne servent à personne (le navigateur ne les charge que si les outils de développement sont ouverts). **Sécurité** : `sourcesContent` contient l'intégralité du TypeScript du CRM — noms de routes internes, drapeaux de fonctionnalités, commentaires de conception — accessible à quiconque connaît l'URL, sans compte. C'est la contrepartie assumée d'un Sentry qui symbolise les piles ; encore faut-il que ce soit un choix, et il n'est écrit nulle part.
- Reproduction  : le `curl` ci-dessus.
- Correctif     : `sourcemap: 'hidden'` dans `vite.config.ts` (les cartes sont produites, le commentaire `//# sourceMappingURL` disparaît du bundle), les téléverser à Sentry via `@sentry/cli` — **déjà dans les `devDependencies`** — puis les supprimer de l'image (`RUN find dist -name '*.map' -delete` après le téléversement). ~1 h. À défaut, une règle Caddy `@maps path *.map` → `respond 404`. ~10 min.
- Statut        : ouvert

---

### [G42-007] Neuf requêtes en scrutation périodique, dont deux toutes les 5 secondes sur le même écran — sur une production qui sérialise, la file ne se vide jamais

- Sévérité      : **S1**
- Domaine       : performance
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : `frontend/src/features/campaigns/CampaignDetailPage.tsx:64,71` · `CampaignsListPage.tsx:65` · `ScraperRunsPage.tsx:215` · `DashboardPage.tsx:81` · `ObservabilityPage.tsx:34` · `AudiencesListPage.tsx:68` · `CoveragePage.tsx:34`
- Constat       : neuf `refetchInterval` sont déclarés ; le plus court vaut **5 000 ms** et il y en a **deux sur le même écran**, soit **24 requêtes/minute par onglet ouvert** sur `/campaigns/$campaignId`.
- Preuve        :
  ```
  $ grep -rn "refetchInterval" src/
  AudiencesListPage.tsx:68:    refetchInterval: 30_000,
  CampaignDetailPage.tsx:64:    refetchInterval: 5_000,
  CampaignDetailPage.tsx:71:    refetchInterval: 5_000,
  CampaignsListPage.tsx:65:    refetchInterval: 10_000,
  CoveragePage.tsx:34:     refetchInterval: 60_000,
  DashboardPage.tsx:81:    refetchInterval: 30_000,
  ObservabilityPage.tsx:34: refetchInterval: 30_000,
  ScraperRunsPage.tsx:215:  refetchInterval: 10_000,
  ```
- Témoin négatif : la recherche n'est pas aveugle aux autres formes de périodicité — le même balayage cherche aussi `setInterval` et rend **0 occurrence** ; il n'y a donc pas de scrutation cachée hors React Query, et le compte de 9 est complet.
- Impact        : **c'est ici que A-010 change la nature du défaut.** La production sert toute l'API par un `php -S` mono-processus : les requêtes sont **sérialisées**, et les compteurs du tableau de bord ont été mesurés à **17,5 s à cache froid** par l'agent chargé de A-010. Un seul onglet `/campaigns/$id` émet une requête toutes les 5 s ; si une seule d'entre elles prend plus de 5 s, la suivante part avant que la précédente ne soit servie, et **la file grandit sans borne** — pour tout le monde, pas seulement pour l'onglet fautif. À un seul utilisateur et à cache chaud, rien ne se voit : c'est exactement la forme de A-010.
  Atténuation réelle à porter au crédit du code : React Query ne scrute pas un onglet en arrière-plan (`refetchIntervalInBackground` reste à son défaut `false`). Le risque est donc borné au nombre d'onglets **au premier plan**.
- Reproduction  : `grep -rn "refetchInterval" frontend/src/`.
- Correctif     : tant que A-010 n'est pas corrigé (php-fpm est **déjà dans l'image** et n'est jamais lancé), porter les intervalles de 5 s à 15 s et de 10 s à 30 s coûte **10 minutes** et divise la charge de fond par trois. Le vrai correctif est A-010 ; celui-ci est un pansement, à retirer après.
- Statut        : ouvert

---

### [G42-008] Aucune mémoïsation dans tout le produit : 0 `React.memo`, 2 `useCallback` — chaque frappe re-rend l'écran entier, lignes comprises

- Sévérité      : **S2**
- Domaine       : performance
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : tout `frontend/src/`
- Constat       : le produit ne contient **aucun** `React.memo`, **2** `useCallback` et **24** `useMemo`. Comme chaque champ de filtre et chaque champ de recherche est un `useState` **de la page**, toute frappe re-rend l'écran de haut en bas — en-tête, barre d'outils, et **toutes les lignes**.
- Preuve        :
  ```
  $ grep -rn "React.memo\|[^e]memo(" src/ | grep -v useMemo | wc -l
  0
  $ grep -rn "useCallback(" src/ --include=*.tsx | wc -l
  2
  $ grep -rn "useMemo(" src/ --include=*.tsx   → 24 occurrences, 14 fichiers
  $ grep -rno "on[A-Z][A-Za-z]*={(\?[^}]*) *=>" src/ --include=*.tsx | wc -l
  190           # fonctions fléchées créées à chaque rendu et passées en prop
  $ grep -rno "[a-zA-Z]*={{" src/ --include=*.tsx | wc -l
  48            # objets littéraux créés à chaque rendu et passés en prop
  $ grep -rn "createContext" src/
  (aucune)
  ```
- Témoin négatif : les deux motifs qu'on cite d'ordinaire comme coupables — **contextes trop larges** et **`key` par index** — ont été cherchés avec le même outillage et **ne tiennent pas ici** : il n'y a **aucun** `createContext`, et sur les **12** `key={i}`, **10 portent sur des tableaux de squelettes constants** (`Array.from({length: rows})`), donc inoffensifs ; il n'en reste que **2 sur des données réelles** (`ActivityFeed.tsx:59`, `AudienceBuilderPage.tsx:362`). Le contrôle sait donc dire non, et son « oui » sur la mémoïsation en vaut la peine.
- Impact        : les 190 fonctions et 48 objets recréés à chaque rendu **ne coûtent rien tant qu'il n'y a pas de `memo`** — c'est le point important, et il est contre-intuitif : ce n'est pas eux le défaut, c'est leur combinaison avec l'absence totale de barrière de re-rendu. Concrètement, taper un caractère dans le filtre de `CompaniesListPage` re-rend les 19 lignes visibles **et** les 15 sélecteurs de filtre **et** les 4 vignettes de KPI. Sur les écrans virtualisés c'est supportable ; sur `TagsManagerPage` (jusqu'à 500 cartes) et `UsersPage` (jusqu'à 200 lignes), c'est le coût mesuré au §2 **à chaque frappe**.
- Reproduction  : les `grep` ci-dessus.
- Correctif     : **ne pas saupoudrer de `memo`.** Deux gestes ciblés suffisent : (1) `memo()` sur `CompanyRow` et sur la future ligne partagée du composant `Table` manquant (cf. G42-004) ; (2) déplacer l'état du champ de recherche **dans** un sous-composant `SearchInput` non contrôlé par la page, ce que l'anti-rebond de G42-010 impose de toute façon. ~3 h les deux, et le second corrige aussi G42-010.
- Statut        : ouvert

---

### [G42-009] Six dépendances déclarées ne sont importées nulle part

- Sévérité      : **S3**
- Domaine       : performance / dépendances
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : `frontend/package.json:14-42,44-70`
- Constat       : `date-fns`, `zustand`, `@tanstack/react-table`, `class-variance-authority`, `tailwind-merge` et `@tanstack/react-query-devtools` sont déclarés et **jamais importés**.
- Preuve        :
  ```
  $ for p in date-fns zustand @tanstack/react-table class-variance-authority \
             tailwind-merge @tanstack/react-query-devtools; do
      grep -rn "from '$p" src/ tests/ ; done
  (aucune sortie)
  ```
  et aucune de ces six n'apparaît dans l'attribution par module (`composition-bundle.txt`).
- Témoin négatif : la même boucle, appliquée à `zod`, `laravel-echo`, `react-joyride`, `i18next` et `@sentry/react`, **trouve** leur import — et ces cinq-là apparaissent bien dans l'attribution. L'absence des six autres n'est donc pas un défaut de la commande.
- Impact        : **nul sur le bundle** — l'élagage les supprime intégralement, et je le vérifie plutôt que de le supposer. L'impact réel est ailleurs : six paquets installés à chaque `pnpm install`, six paquets dans la surface d'audit de sécurité, et deux d'entre eux — `@tanstack/react-table` et `tailwind-merge` — désignent précisément les deux manques constatés par ailleurs (aucun composant `Table`, et une fonction `cn` réécrite à la main dans `components/ui/cn.ts`). **La brique est payée et pas posée.**
- Reproduction  : la boucle ci-dessus.
- Correctif     : soit retirer les six (`pnpm remove`, 10 min), soit — mieux pour deux d'entre elles — **s'en servir** dans le composant `Table` du correctif G42-004. Décision à prendre au moment de ce chantier, pas avant.
- Statut        : ouvert

---

### [G42-010] La recherche à la frappe n'a aucun anti-rebond : une requête par touche, sur quatre écrans — et sur une production sérialisée, c'est un moyen simple de geler l'application pour tout le monde

- Sévérité      : **S1**
- Domaine       : performance / interface
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : `frontend/src/components/ui/Toolbar.tsx:30-35` (`SearchInput`) · `ContactsListPage.tsx:86` · `ContactsHubPage.tsx:68` · `CompaniesListPage.tsx:256` · `components/ui/GlobalSearch.tsx:32-38`
- Constat       : `SearchInput` est un `<input>` contrôlé nu ; sa valeur est poussée telle quelle dans la `queryKey` de React Query. **Une nouvelle clef = une nouvelle requête.** Il n'existe **qu'un seul** anti-rebond dans tout le produit, ailleurs (`AudienceBuilderPage.tsx:170`, 500 ms).
- Preuve        : mesure jouée — on tape le mot « boulangerie » (11 caractères) dans chaque champ et on **compte les requêtes HTTP réellement émises** (MSW enregistre les URL). Résultats au §3. Et dans le code :
  ```ts
  // Toolbar.tsx — aucun délai, aucun seuil
  <input value={value} onChange={(e) => onChange(e.target.value)} … />
  // ContactsHubPage.tsx:68 — la valeur entre directement dans la clef
  queryKey: ['crm','contacts-hub', tab, temperature, search, country, prospection],
  ```
- Témoin négatif : **obligatoire ici**, car « beaucoup de requêtes » pourrait n'être qu'un artefact de `userEvent.type`. Le même instrument est donc pointé sur `AudienceBuilderPage`, qui **porte** un anti-rebond de 500 ms : le compteur y rend **beaucoup moins de requêtes que de touches** (§3). L'instrument sait donc voir un anti-rebond quand il y en a un.
- Impact        : trois effets qui s'additionnent.
  1. **Réseau** : 11 frappes = 10 requêtes de recherche jetables (la 11ᵉ seule compte).
  2. **A-010** : sur une production qui **sérialise**, ces 10 requêtes s'exécutent l'une après l'autre. Une recherche sur 4,29 M de fiches est le type même de requête lente. **Un seul utilisateur qui tape vite dans un champ de recherche suffit à occuper l'unique processus PHP et à faire attendre tous les autres.** C'est le croisement demandé par le mandat, et il tient : le défaut d'interface transforme un défaut d'infrastructure en panne collective.
  3. **CDC critère 1** (« résultats à la frappe, moins de 5 s ») : la réponse affichée est celle de la **dernière** requête arrivée, pas de la dernière frappe. React Query annule les requêtes obsolètes côté client, mais **pas côté serveur** : le travail est fait quand même.
  À décharge : `GlobalSearch` impose 2 caractères minimum et `staleTime: 30 s` évite de refaire la requête si l'utilisateur revient en arrière sur une chaîne déjà tapée. Les trois écrans de liste n'ont **aucun** seuil : la première frappe part.
- Reproduction  : `pnpm vitest run tests/bench-agent42/frappe.test.tsx` (fichier archivé dans `04_PREUVES/agent-42/`).
- Correctif     : un anti-rebond de 300 ms **dans `SearchInput` lui-même** — un seul fichier, `~15 lignes`, et les quatre écrans en profitent sans être touchés ; plus un seuil de 2 caractères aligné sur celui de `GlobalSearch`. **~1 h avec les tests.** C'est le meilleur rapport effort/gain de tout ce rapport, et il vaut d'être fait **avant** A-010, pas après : il réduit la charge sur le processus unique.
- Statut        : ouvert

---

### [G42-011] `FranceCoverageMap` : 32 journalisations en production, un rendu React par mouvement de souris, et jusqu'à 500 balayages de source qui ne colorent rien

- Sévérité      : **S2**
- Domaine       : performance
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : `frontend/src/features/coverage/FranceCoverageMap.tsx:38,79-186,236-252,266-280`
- Constat       : trois coûts distincts dans le même composant.
  1. **32 appels `LOG()`** (`console.log('[FranceMap]', …)`), présents dans le bundle de production — Vite ne retire pas `console.*` et rien ne le configure.
  2. `map.on('mousemove', …)` appelle **`setHover(...)` à chaque mouvement de souris** : un rendu React par événement, plus un `cellsRef.current.find(...)` linéaire.
  3. L'effet `[cells]` boucle sur **chaque cellule** et appelle `map.querySourceFeatures('departements', { filter })` — un balayage complet de la source par cellule.
- Preuve        :
  ```
  $ grep -c "LOG(" src/features/coverage/FranceCoverageMap.tsx
  32
  $ grep -o "\[Boot\]" <bundle de production>   → 2
  $ grep -o "FranceMap" <bundle de production>  → 1   (le préfixe, minifié en un seul littéral)
  ```
  Le plafond du 3ᵉ point est côté serveur : `CoverageController::queryCityCells()` finit par `LIMIT 500`, et le niveau « département » rend ~96 lignes.
- Témoin négatif : la présence des journaux dans le bundle **de production** n'est pas déduite du code source — le bundle `index-D3nU2tuG.js` a été **téléchargé depuis `app.axion-crm-pro.com`** (1 046 364 o) et fouillé : `[Boot]` y apparaît 2 fois, `FranceMap` 1 fois. Contrôle inverse : une chaîne absente du source (`AGENT42-TEMOIN`) n'y apparaît pas.
- Impact        : le 1ᵉʳ point est cosmétique en soi mais bruyant — les gestionnaires `styledata`, `sourcedata` et `dataloading` journalisent pendant tout le chargement de la carte. Le 2ᵉ est le vrai coût d'interaction : survoler la carte déclenche un rendu React à la fréquence de la souris. Le 3ᵉ est borné (500 × ~96 features) mais **inutile aux niveaux « région » et « ville »** : la source ne contient que des **codes de département**, aucun code de région ni d'INSEE n'y correspondra jamais — ce sont jusqu'à 500 balayages complets, toutes les 60 s (`refetchInterval` de `CoveragePage`), pour ne colorer aucun polygone.
- Reproduction  : ouvrir `frontend/src/features/coverage/FranceCoverageMap.tsx` ; `grep -c "LOG(" `.
- Correctif     : (a) supprimer les 32 `LOG()` et le `fetch()` de diagnostic — c'est de l'instrumentation de mise au point du Sprint 18.9c laissée en place (~30 min) ; (b) sortir le survol de l'état React (écrire dans un nœud positionné par `ref`, ou au minimum n'appeler `setHover` que **si le code de département a changé**) (~1 h) ; (c) remplacer la boucle par un seul `querySourceFeatures` suivi d'un index `Map<code, id>` (~30 min). Aucun ne change ce que l'utilisateur voit.
- Statut        : ouvert

---

### [G42-012] Les squelettes n'ont jamais la hauteur du contenu qu'ils remplacent, et huit écrans n'en ont aucun

- Sévérité      : **S3**
- Domaine       : interface / performance perçue
- Référence     : `frontend/` identique de `c0c453d` à `a3c42d6`
- Emplacement   : `frontend/src/components/ui/Skeleton.tsx:5-15` · `ContactsListPage.tsx:175` · `crm-console/ConsoleGate.tsx:61-66` · `CompaniesListPage.tsx:618`
- Constat       : `CompaniesTableSkeleton` affiche **6** lignes d'attente là où l'écran en rendra **50** (`ContactsListPage.tsx:175`, `rows={6}`, `per_page=50`) ; `ConsoleListSkeleton` en affiche **8** pour 50. Le contenu ne peut donc que **sauter** au moment où il arrive. Par ailleurs **7 écrans** (et 2 sous-composants du tableau de bord) n'affichent rien pendant le chargement.
- Preuve        :
  ```
  $ grep -rn "Skeleton\|Spinner" src/features --include=*.tsx  (inventaire par écran)
  fichiers avec squelette : 22 / 31
  fichiers sans           : CampaignWizardPage, CoveragePage, RoumaniePage,
                            JournalistsListPage, MediaListPage, ObservabilityPage,
                            SettingsPage            (7 écrans de route)
                            + ActivityFeed, TopDeptsCard   (2 sous-composants)
  $ grep -n "rows=" src/features/contacts/ContactsListPage.tsx
  175:        <CompaniesTableSkeleton rows={6} />          # pour per_page = 50
  ```
  Ordres de grandeur mesurés (§2) : l'écran vide de `ContactsListPage` compte **44 nœuds DOM**, l'écran à 100 lignes en compte **2 545**.
- Témoin négatif : le décompte sait distinguer un écran instrumenté d'un écran nu — il attribue 10 marqueurs à `DashboardPage` (qui a un `DashboardSkeleton` complet) et 0 à `SettingsPage`. Lecture manuelle des deux fichiers : le verdict tient.
- Impact        : décalage de mise en page à chaque chargement de liste (l'ordre de grandeur est de plusieurs milliers de pixels sur `ContactsListPage` : 6 barres de 48 px puis 50 lignes de ~64 px). Sur les 7 écrans sans squelette, l'utilisateur voit une zone vide, puis le contenu — c'est-à-dire, sur une production sérialisée (A-010), plusieurs secondes de « rien ».
  **Périmètre** : l'agent 25 traite les cinq états de chargement ; je ne rapporte ici que leur **coût de rendu** et le **saut** qu'ils provoquent.
- Reproduction  : les `grep` ci-dessus.
- Correctif     : donner au squelette le nombre de lignes de la page (`rows={perPage}`) et une hauteur de ligne identique à la vraie — 30 min pour les 22 fichiers concernés, puisque tout passe par deux composants. Les 7 écrans sans squelette relèvent de l'agent 25.
- Statut        : ouvert

---

## 5. Ce que je n'ai PAS pu vérifier, et pourquoi

Cette liste est un livrable.

1. **Tout parcours authentifié, dans un vrai navigateur.** La console n'est pas utilisable
   (200 → 403 → 2FA 500 ; constats A-012 et A07-001). Aucun LCP, INP, CLS, TBT ni long-task de
   terrain. Les chronos du §2 sont des chronos **jsdom**, sans mise en page ni peinture : ils
   minorent le coût réel.
2. **Le temps réellement perçu à l'ouverture d'un écran.** Il est dominé par le temps de réponse
   de l'API, donc par la file de A-010. Le mesurer aurait produit un nombre vrai et inutile.
   Je m'y suis refusé plutôt que de publier une mesure de file d'attente sous le nom du produit.
3. **Le critère 1 du CDC dans son entier** (« résultats à la frappe, **moins de 5 s** »). J'ai
   mesuré la moitié qui m'est accessible — le nombre de requêtes émises, et l'absence
   d'anti-rebond. La seconde moitié — le délai — dépend du serveur, donc de A-010 : **non
   concluant tant que A-010 n'est pas corrigé.**
4. **Le rendu de `FranceCoverageMap` à l'écran.** MapLibre exige WebGL : jsdom ne peut pas le
   monter, et je n'ai pas de navigateur authentifié. J'ai donc mesuré ce qui **transite**
   (802 715 o + 1 079 714 o), ce que le fichier **contient** (96 polygones, 31 244 points) et ce
   que le code **fait** — pas ce qui s'affiche. **Le mandat demandait « est-ce tenable ? » : sur
   96 polygones et 31 244 points, oui, le rendu l'est ; c'est le transport et le montage qui ne
   le sont pas.**
5. **Le volume 100 000 lignes sur `CompaniesListPage` et `ContactsHubPage`.** Voir §2 : la mesure
   à 100 000 sur `ContactsListPage` a consommé **plus de 3,5 Go et plus de 8 minutes de temps
   processeur sans aboutir**, et j'ai arrêté le processus. C'est un résultat — le rendu **ne
   converge pas** — mais je ne peux pas en donner de chrono, et je ne l'extrapole pas.
6. **Le coût CPU du re-rendu par frappe, chiffré.** J'ai établi la **cause** (0 `memo`, état de
   recherche porté par la page) et le **volume de DOM** re-rendu. Le mesurer en millisecondes
   demanderait le profileur React dans un vrai navigateur — cf. point 1.
7. **L'écran `/coverage` aux niveaux « région » et « ville ».** J'ai lu le plafond serveur
   (`LIMIT 500`) et l'algorithme client. Je n'ai pas pu observer le nombre de cellules
   réellement renvoyé en production : `GET /coverage` exige une session, et il n'y en a pas.
8. **Toute comparaison avant/après d'un correctif.** Je n'ai modifié aucun fichier du produit.
   Les gains annoncés dans les rubriques « Correctif » sont des **calculs sur les octets mesurés**,
   pas des mesures de bundles corrigés.
9. **⚠️ Point de méthode, à transmettre au chef de chantier.** Le dépôt frontend **n'a aucune
   garde de budget de bundle** : `pnpm build` n'échoue à aucun seuil, il se contente d'un
   avertissement Rollup (`chunkSizeWarningLimit: 500`, très largement dépassé par deux chunks à
   chaque build depuis longtemps). Il n'y a ni `size-limit`, ni Lighthouse CI, ni budget dans
   `ci.yml` pour ce dépôt. **Aucune PR qui alourdit ce bundle ne rougira.** Tout correctif issu
   de ce rapport doit donc être mesuré **à la main, avant et après**, exactement comme ici.
   (C'est le même piège que celui déjà consigné pour le dépôt du site : une gate réputée
   bloquante qui ne l'est pas. Ici, il n'y a même pas de gate.)

---

## 6. Réponses directes aux six questions du mandat

1. **Le poids livré.** 2 178 093 octets bruts / 627 369 gzip au premier écran, sur **toutes** les
   routes. Le chunk `index-*.js` de production fait bien **1 046 364 octets** ; il contient
   68,2 % de dépendances et 31,4 % de code maison, dont **272 330 octets d'écrans de route** que
   l'utilisateur n'a pas demandés. **Découpage par route : NON — zéro import dynamique.**
2. **Les listes longues.** Virtualisation : **1 fichier sur 31**. Chronos aux 5 volumes : §2.
   Le rendu est **linéaire en nœuds** (≈ 25 nœuds/ligne) et **superlinéaire en temps** au-delà de
   quelques milliers de lignes. Le seul garde-fou est le plafond de pagination **du serveur**.
3. **Les re-rendus.** 0 `React.memo`, 2 `useCallback`, 190 fonctions et 48 objets recréés en prop,
   0 contexte, 12 `key` par index dont 10 inoffensifs. Cause dominante : l'état des filtres et de
   la recherche vit **dans la page**.
4. **La recherche à la frappe.** **Aucun anti-rebond**, sur les 4 champs mesurés. Un seul
   anti-rebond existe dans tout le produit, ailleurs. Croisé A-010 : oui, taper vite dans un champ
   de recherche est un moyen simple d'occuper l'unique processus PHP.
5. **`FranceCoverageMap`.** 0 marqueur, 96 polygones, **31 244 points**, servis par un fichier de
   **1 079 714 octets non caché**, téléchargé **deux fois** par montage, avec un moteur de
   **802 715 octets préchargé sur les 37 routes**. Le rendu est tenable ; le transport ne l'est pas.
6. **Le chargement perçu.** Squelettes sur 22 fichiers sur 31 ; **aucun n'a la hauteur du contenu**
   qu'il remplace ; 7 écrans (et 2 sous-composants) n'en ont pas. Coût de rendu des squelettes : négligeable (44 nœuds).
