# AGENT 30 — Auditeur mobile et responsive

- **Référence mesurée** : `main = 4ca52c9` à la toute dernière relecture (`git log` rejoué au début, en cours et à la fin ; `main` a avancé **cinq fois** pendant ma mission : `e8924b8` → `8db8229` → `7777f27` → `a3c42d6` → `7be3753` → `4ca52c9`, toutes des livraisons d'agents d'audit). **`frontend/` n'a pas bougé d'une ligne** : `git diff --stat e8924b8 HEAD -- frontend/` rend une **sortie vide**, revérifié à la clôture. Mes constats valent donc sur la même référence que les agents 22, 23 et 27, et je les note **`main 4ca52c9 (frontend = e8924b8)`**.
- **Artefact mesuré** : `/assets/index-BVK1vh1a.js` — le bundle **officiel reconstruit** par le chef de chantier, celui que l'agent 22 a identifié comme référence. Conformité **D-011** établie. Aucune mesure sur le bundle périmé de D23-001.
- **Périmètre** : les **37 écrans** de `routeTree.tsx`, à **375 × 812 px**, plus la barre latérale repliée, le tiroir de navigation mobile, la recherche mobile, les tableaux larges et les cibles tactiles.
- **Écrans réellement ouverts à 375 px dans un vrai navigateur** : **37 / 37**, deux fois, avec deux instruments indépendants.
- **Preuves** : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-30/`
  - `00_METHODE.txt` — comment, avec quoi, et **ce que cela ne mesure pas**
  - `01_mesure-375px.json` — les 37 écrans, mesure complète (94 Ko)
  - `02_barre-tableaux-temoins.txt` — barre repliée, tiroir, sondes tableaux A/B, **témoins négatifs**
  - `03_statique-barre-basse-et-tableaux.txt` — le code, avec témoin positif
  - `04_largeurs-tableaux.txt` — largeur minimale **mesurée** des 9 tableaux-grille
  - `05_parcours-et-tiroir.txt` — budget de gestes bureau vs téléphone
  - `captures-375/` — **41 captures** : les 37 écrans, le tiroir de navigation, la recherche mobile, et les deux états de la barre latérale
  - `scripts/` — les 4 scripts, rejouables par une commande

---

## 0. Méthode — et les deux aveux qui vont avec

### 0.1 Aucune autorité racine installée

Chrome refuse `https://app.localhost` : le certificat vient de « Caddy Local Authority », absente du magasin Windows. **Installer cette autorité est une modification de sécurité du poste du dirigeant : je ne l'ai pas faite** — l'agent 22 a refusé, à raison, et je fais pareil. J'ai repris sa méthode : un conteneur Caddy **temporaire**, en lecture seule, retiré à la fin (`docker rm -f a30-proxy` → joué, vérifié), qui relaie `app:5173` en clair sur `http://app.localhost:58130` et sert **une** page statique (mon banc d'essai). **Aucun fichier du produit n'a été modifié. Aucune écriture en production.**

### 0.2 `resize_window` ne descend pas à 375 px — comment j'ai obtenu un vrai viewport de 375

`mcp__claude-in-chrome__resize_window` a répondu « Successfully resized … to 375x812 » et `window.innerWidth` valait **toujours 1920**. Une fenêtre Chrome ne descend pas sous sa largeur minimale. **Une mesure faite là-dessus aurait été muette et plausible — exactement le piège 23/24 du dossier.**

Deux instruments **indépendants** ont donc été employés :

1. **Un banc d'essai à `<iframe>` de 375 × 812**, servi depuis la **même origine** que le SPA, piloté dans le Chrome du poste. Un `<iframe>` a son propre viewport de mise en page : `matchMedia('(min-width:1024px)')` y rend **`false` à 375** et **`true` à 1280**, la barre latérale disparaît et le hamburger apparaît **exactement au seuil `lg`**. C'est mon **témoin d'instrument** : le banc applique bien les requêtes de média.
2. **Playwright 1.60** (déjà dans `frontend/node_modules`), `viewport: 375×812`, `isMobile: true`, `hasTouch: true`, `deviceScaleFactor: 2`.

**Écart entre les deux : 477 vs 473 cibles tactiles, 465 vs 461 sous 44 px** (4 éléments de plus dans le banc, mesurés sur un écran encore en cours de chargement). **Mêmes 3 écrans en débordement, mêmes nombres de pixels perdus : 83 / 47 / 231.** Les chiffres retenus dans ce rapport sont **ceux de Playwright**, parce qu'ils se rejouent par une commande.

### 0.3 Une mesure que je **refuse** de rapporter

Dans le banc à `<iframe>`, le panneau du tiroir portait un `transform: translateX(24px)` résiduel — j'ai failli en faire un constat. C'est la **première image** de l'animation `axion-slide-in-right` (`index.css:63-65`, `from { transform: translateX(24px) }`), **figée par le ralentissement des animations dans une iframe**. Sous Playwright : aucun décalage. **Ce n'était pas un défaut du produit, c'était un artefact de mon instrument.** Je le laisse écrit parce qu'un audit qui ne montre que ses mesures réussies demande qu'on lui fasse confiance.

### 0.4 Ce que je n'ai pas pu mesurer, et je le dis ici avant la grille

Le SPA porte une base d'API **absolue** (`frontend/src/lib/api.ts:3` → `https://api.localhost`). Depuis mon origine en clair, **tous** les appels échouent. Les 37 écrans sont donc mesurés **non connecté, API injoignable** — et la console reste de toute façon inutilisable (**A-012**, **A07-001**). **Conséquence directe et lourde : aucune liste du produit ne s'affiche.** `tables=0` et `gridRows=0` sur les 37 écrans, non parce qu'il n'y a pas de tableaux, mais parce qu'ils ne se rendent qu'avec des lignes.

**Je n'ai donc pas déduit le sort des tableaux larges : je l'ai mesuré autrement**, en injectant dans le `<main>` réel le **patron exact** du produit (le `<Card padding="none" className="overflow-hidden">` + le `role="row"` portant la chaîne de 210 caractères de D27-005 + le `gridTemplateColumns` copié du fichier), **avec un témoin** monté dans un conteneur `overflow-x-auto`. Voir §5 et `04_largeurs-tableaux.txt`.

---

## 1. GRILLE — les 37 écrans à 375 px

Colonnes : `main` = largeur du contenu / largeur visible du conteneur principal · **hors écran** = pixels rognés et **inatteignables** · `dép.` = éléments qui dépassent le bord droit sans conteneur défilable · **fil** = fil d'Ariane, largeur nécessaire / largeur accordée · **déf.** = conteneurs à défilement horizontal · **cibles** = éléments interactifs visibles · **<44** = ceux qui ne font pas 44 × 44, dont (coquille / contenu).

| # | Écran | `main` | hors écran | dép. | fil | déf. | cibles | <44 (coq./cont.) | conformes 44×44 |
|---|---|---|---:|---:|---|---:|---:|---|---|
| 1 | `/` | 458/375 | **83 px** | 5 | 94/94 | 0 | 13 | 12 (7/5) | 1 — « Démarrer sur /coverage » 195×44 |
| 2 | `/companies` | 422/375 | **47 px** | 3 | 150/94 | 0 | 33 | **33 (8/25)** | **aucune** |
| 3 | `/companies/$id` | 375/375 | 0 | 0 | 219/94 | 0 | 10 | 10 (9/1) | aucune |
| 4 | `/contacts` | 375/375 | 0 | 0 | 138/94 | 0 | 13 | 13 (8/5) | aucune |
| 5 | `/international/roumanie` | 375/375 | 0 | 0 | 237/94 | 0 | 22 | 22 (8/14) | aucune |
| 6 | `/media` | 375/375 | 0 | 0 | 125/94 | 0 | 20 | 20 (8/12) | aucune |
| 7 | `/media/$id` | 375/375 | 0 | 0 | 209/94 | 0 | 9 | 9 (8/1) | aucune |
| 8 | `/journalists` | 375/375 | 0 | 0 | 148/94 | 0 | 13 | 13 (8/5) | aucune |
| 9 | `/coverage` | 375/375 | 0 | 0 | 190/94 | 0 | 16 | 15 (8/7) | 1 — mais c'est le **canevas** MapLibre 287×640, pas un contrôle |
| 10 | `/scraper-runs` | 375/375 | 0 | 0 | 202/94 | 0 | 17 | 17 (8/9) | aucune |
| 11 | `/campaigns` | 375/375 | 0 | 0 | 139/94 | 0 | 17 | 17 (8/9) | aucune |
| 12 | `/campaigns/new` | 375/375 | 0 | 0 | 187/94 | 0 | 17 | 14 (9/5) | 3 — 1 `textarea` + « Lancer maintenant » 112×52 + « Planifier » 122×52 |
| 13 | `/campaigns/$id` | 375/375 | 0 | 0 | 223/94 | 0 | 10 | 10 (9/1) | aucune |
| 14 | `/audiences` | 375/375 | 0 | 0 | 146/94 | 0 | 10 | 10 (8/2) | aucune |
| 15 | `/audiences/new` | 375/375 | 0 | 0 | 194/94 | 0 | **59** | **58 (8/50)** | 1 — un `textarea` |
| 16 | `/audiences/$id` | 375/375 | 0 | 0 | 232/94 | 0 | 9 | 9 (8/1) | aucune |
| 17 | `/tags` | 375/375 | 0 | 0 | 115/94 | 0 | 10 | 10 (8/2) | aucune |
| 18 | `/users` | 375/375 | 0 | 0 | 151/94 | 0 | 10 | 10 (8/2) | aucune |
| 19 | `/settings` | 375/375 | 0 | 0 | 151/94 | 0 | 13 | 13 (8/5) | aucune |
| 20 | `/llm/router` | 375/375 | 0 | 0 | 174/94 | 0 | 14 | 14 (9/5) | aucune |
| 21 | `/llm/proxy-providers` | 375/375 | 0 | 0 | 176/94 | 0 | 10 | 10 (9/1) | aucune |
| 22 | `/llm/rotations` | 375/375 | 0 | 0 | 189/94 | 0 | 10 | 10 (9/1) | aucune |
| 23 | `/rgpd/requests` | **606/375** | **231 px** | 4 | 195/94 | 0 | 17 | 12 (9/3) | 5 — **et les 5 sont hors écran** (§4) |
| 24 | `/rgpd/ai-act` | 375/375 | 0 | 0 | 226/94 | 0 | 10 | 10 (9/1) | aucune |
| 25 | `/audit-logs` | 375/375 | 0 | 0 | 181/94 | 0 | 12 | 12 (8/4) | aucune |
| 26 | `/admin/observability` | 375/375 | 0 | 0 | 222/94 | 0 | 9 | 9 (8/1) | aucune |
| 27 | `/console/contacts` | 375/375 | 0 | 0 | 205/94 | 0 | 9 | 9 (8/1) | aucune |
| 28 | `/console/vivier` | 375/375 | 0 | 0 | 189/94 | 0 | 9 | 9 (8/1) | aucune |
| 29 | `/console/arbitrage` | 375/375 | 0 | 0 | 209/94 | 0 | 9 | 9 (8/1) | aucune |
| 30 | `/console/personnes/$k` | 375/375 | 0 | 0 | **275/94** | 0 | 9 | 9 (8/1) | aucune |
| 31 | `/cold-email` | 375/375 | 0 | 0 | 169/94 | 0 | 9 | 9 (8/1) | aucune |
| 32 | `/linkedin` | 375/375 | 0 | 0 | 204/94 | 0 | 9 | 9 (8/1) | aucune |
| 33 | `/login` | — | 0 | 1* | — | 0 | 7 | 7 (0/7) | aucune |
| 34 | `/2fa` | — | 0 | 1* | — | 0 | 3 | 2 (0/2) | 1 — le champ « Code à 6 chiffres » 295×56 |
| 35 | `/magic-link` | — | 0 | 1* | — | 0 | 3 | 3 (0/3) | aucune |
| 36 | `/password-reset` | — | 0 | 1* | — | 0 | 3 | 3 (0/3) | aucune |
| 37 | `404` | — | 0 | 0 | — | 0 | 0 | 0 | — |

\* Les 4 écrans d'authentification n'ont pas de `<main id="main">` (ils ne passent pas par `RootLayout`). Leur unique « dépassement » est le halo décoratif `absolute -right-32 bottom-1/4`, **contenu dans un parent `overflow-hidden` et sans contenu** : ce n'est **pas** un défaut, et je ne le compte pas.

**TOTAUX**

| | |
|---|---:|
| Écrans mesurés à 375 px | **37 / 37** |
| Écrans dont le contenu **déborde et est rogné** | **3** — `/` (83 px), `/companies` (47 px), `/rgpd/requests` (231 px) |
| Écrans dont la **page** défile horizontalement (barre de défilement du document) | **0** — parce que `<main>` **rogne** au lieu de laisser défiler (D30-001) |
| **Conteneurs à défilement horizontal** sur les 37 écrans | **0** |
| **Barres basses** détectées | **0** |
| Cibles tactiles visibles, toutes routes | **473** |
| **Cibles sous 44 × 44 px** | **461** (**97,5 %**) — dont **263** dans la coquille et **198** dans le contenu |
| Cibles sous **24 × 24 px** (minimum WCAG 2.2 AA, critère 2.5.8) | **82** (17,3 %) |
| Cibles conformes 44 × 44 | **12** — dont 1 canevas de carte et 5 hors écran → **6 contrôles réellement atteignables**, sur 3 écrans |

**Répartition des hauteurs des 473 cibles** : 16 px ×8 · 20 px ×42 · 24 px ×131 · 25 px ×42 · 28 px ×99 · 32 px ×31 · 36 px ×58 · 38 px ×10 · 40 px ×40 · **44 px ×1** · 52 px ×6 · 56 px ×1 · 70/72/80 px ×3 · 640 px ×1 (le canevas). **La hauteur est le facteur limitant : 11 cibles sur 473 atteignent 44 px de haut.**

---

## 2. GRILLE — la barre basse à cinq entrées du §23.3 : **elle n'existe pas**

| Ce que le CDC §23.3 exige sur téléphone | Mesure |
|---|---|
| Une barre basse à 5 entrées : **Aujourd'hui · Contacts · Échanges · Rechercher · Plus** | **absente** |
| Composant `BottomBar` / `BottomNav` / `TabBar` / `MobileNav` / `bottom-nav` / `BarreBasse` | **0 fichier** pour chacun des 6 noms |
| Élément ancré en bas d'écran (`bottom-0`) dans tout `frontend/src` | **1 seul** — `components/ui/Modal.tsx:124`, le pied de modale. **C'est mon témoin positif : le contrôle n'est pas aveugle à un élément ancré en bas.** |
| Élément `position: fixed/sticky` occupant le bas du viewport, sur les 37 écrans, mesuré dans le navigateur | **0** |
| Le libellé « Échanges » (le CDC en fait un **groupe entier**) | **0 occurrence** dans tout `frontend/src` |
| Ce qui tient lieu de navigation à 375 px | un **hamburger de 28 × 28 px** en haut à gauche, qui ouvre un **tiroir** (§3) |

**Témoin négatif joué** (`02_barre-tableaux-temoins.txt`, §4) : sur `/` à 375 px, le détecteur rend **0** barre basse ; on **plante alors** dans le DOM une `<nav>` `position:fixed; bottom:0; height:56px` portant exactement les cinq entrées *Aujourd'hui · Contacts · Échanges · Rechercher · Plus* ; le détecteur rend **1**, et la restitue mot pour mot. **Il aurait vu la barre si elle existait.**

Ce point confirme et **complète** ce que l'agent 23 avait laissé ouvert au §13.3 de `10_NAVIGATION-CIBLE.md` (« le code ne contient aucune barre basse — mais je ne l'affirme pas sans l'avoir vue »). **Je l'ai vue ne pas être là**, dans le navigateur, sur les 37 écrans.

---

## 3. GRILLE — la barre latérale : repliée, et sur téléphone

### 3.1 La barre repliée (bureau, 1280 px) — le §23.3 exige « **aux mêmes positions** »

| | déployée | repliée |
|---|---|---|
| Largeur | **260 px** | **64 px** |
| Titres de section visibles | **6** (Aujourd'hui, Contacts, Collecte, Pilotage, Conformité, Réglages) | **0** |
| Entrées visibles | **1 sur `/`**, **4 sur `/companies`** (une seule section ouverte à la fois) | **20**, toutes |
| Taille d'une entrée | 243 × 32 px | **32 × 28 px** |
| Pied de barre | `["Réduire"]` | `["Étendre la barre latérale"]` |

**Écarts de position mesurés** (`02_barre-tableaux-temoins.txt`, §1) :

| entrée | déployée | repliée | écart |
|---|---:|---:|---:|
| `/` (depuis `/`) | y = 152 | y = 81 | **71 px** |
| `/contacts` (depuis `/companies`) | y = 187 | y = 121 | **66 px** |
| `/companies` | y = 221 | y = 151 | **70 px** |
| `/journalists` | y = 255 | y = 181 | **74 px** |
| `/media` | y = 289 | y = 211 | **78 px** |

**Aucune entrée ne se replie à sa position.** Et l'écart n'est pas constant : il **croît** de 66 à 78 px, parce que le pas passe de 34 px à 30 px. Surtout : **16 à 19 des 20 entrées affichées en mode replié n'existent pas du tout en mode déployé** — l'accordéon n'ouvre qu'une section. Les deux modes n'affichent pas le même ensemble d'objets ; « les mêmes positions » est donc **inatteignable par construction**, pas mal réglé.

C'est ce que le code annonce d'ailleurs en toutes lettres (`Sidebar.tsx:270-272`) : *« Barre réduite : … On affiche donc tout — l'accordéon n'a de sens que quand les libellés sont là. »* La décision est **assumée et documentée** ; elle **contredit** simplement le §23.3. Ce n'est pas un bug, c'est un **arbitrage à trancher** — et il complète le point 4 laissé ouvert par l'agent 23.

Captures : `captures-375/barre-1280-deployee.png` et `barre-1280-repliee.png`.

### 3.2 Sur téléphone (375 px) — le tiroir

| Point | Mesure |
|---|---|
| Barre latérale de bureau à 375 px | **masquée** (`hidden lg:flex`, `RootLayout.tsx:76`) — vérifié : largeur 0 |
| Ce qui la remplace | un `Drawer` ouvert par le hamburger `28 × 28` |
| Contenu du tiroir | la **même** barre, `collapsed={false}` : `Navigation | A | Axion CRM Pro | MW | Mon workspace | AUJOURD'HUI | CONTACTS | Contacts | Entreprises | Journalistes | Médias (presse) | COLLECTE | PILOTAGE | CONFORMITÉ | RÉGLAGES | Réduire` |
| Cibles tactiles du tiroir | **14, dont 14 sous 44 × 44** — ✕ 36×36 · titres de section 243×**23** · entrées 243×32 · « Réduire » 243×28 |
| Géométrie | panneau **0 → 375** (tout l'écran) ; barre à l'intérieur **0 → 260** → **bande morte de 115 px** à droite |
| Voile (fermeture par appui à côté) | **inatteignable** : le panneau couvre 100 % de la largeur. Appui en (10, 400) → **tiroir toujours ouvert** |
| Appui dans la bande morte (330, 600) | **ne fait rien** (l'élément sous le doigt est `DIV.-mx-6 -my-4`, à l'intérieur du panneau) |
| Fermeture après navigation | **NON** — après un appui sur une entrée, `location.pathname = /journalists` **et** le tiroir est **toujours ouvert**, `document.body.style.overflow = "hidden"` |
| Sorties réellement disponibles | le ✕ de **36 × 36** en haut à droite, le bouton « Réduire » **tout en bas** de la colonne, et **Échap** — *témoin joué : Échap ferme bien le tiroir. Un téléphone n'a pas de touche Échap.* |

Capture : `captures-375/tiroir-navigation.png`.

### 3.3 Budget de gestes — le §23.4 est raté d'un facteur 2

Mesuré (`05_parcours-et-tiroir.txt`), départ `/`, barre déployée (état par défaut) :

| Intention | Bureau 1280 | Téléphone 375 |
|---|---:|---:|
| Aller à **Paramètres** | **2 clics** | **4 appuis** |
| Aller à **Journaux d'audit** | **2 clics** | **4 appuis** |
| Aller à **Journaux de collecte** | **2 clics** | **4 appuis** |

Le 4ᵉ appui n'est pas un « geste tactile près » : c'est un appui **obligatoire sur le ✕ de 36 × 36**, sans quoi la destination reste cachée sous le menu.

---

## 4. GRILLE — le débordement horizontal, écran par écran

### 4.1 Le mécanisme

`RootLayout.tsx:100` : `<main id="main" className="flex-1 overflow-x-hidden …">`.

`overflow-x: hidden` **n'est pas défilable par l'utilisateur** : ni barre, ni geste tactile. Tout ce qui dépasse est donc **rogné et perdu**. Mesure de `getComputedStyle(main).overflowX` sur les 37 écrans : **`hidden`**, partout.

C'est pourquoi la ligne « la page déborde-t-elle ? » rend **`false` sur les 37 écrans** alors que **3 d'entre eux perdent du contenu**. *Un contrôle qui se contenterait de `document.scrollWidth > clientWidth` conclurait « aucun débordement » — et il aurait tort trois fois.*

### 4.2 Les trois écrans

| Écran | contenu | visible | **perdu** | ce qui disparaît |
|---|---:|---:|---:|---|
| `/` | 458 px | 375 px | **83 px** | le bouton **« ⟳ Actualiser »** (95 px) — visible en sliver sur `captures-375/accueil.png` |
| `/companies` | 422 px | 375 px | **47 px** | la fin de la barre d'actions **« Importer · Exporter · Lancer scraping → »** |
| `/rgpd/requests` | 606 px | 375 px | **231 px** | **« Rectification · art. 16 »** et **« Opposition · art. 21 »** du sélecteur de type de requête — voir `captures-375/rgpd_requests.png` |

Sur `/rgpd/requests`, les **5 seules cibles conformes 44 × 44 de l'écran** (les 5 boutons du contrôle segmenté, 52 px de haut) sont précisément **celles qu'on ne peut pas atteindre**. Un écran **réglementaire** où deux droits RGPD sur cinq ne sont pas sélectionnables sur téléphone.

### 4.3 La cause racine — `PageHeader`

`components/ui/PageHeader.tsx` :
- ligne 18 : `<header className="mb-6 flex flex-wrap items-end justify-between gap-4">` — le repli est prévu ;
- ligne 37 : `<div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>` — **`shrink-0` interdit à ce bloc de se réduire**, et un bloc qui ne se réduit pas ne replie jamais son propre contenu : il conserve sa largeur `max-content` (mesuré : **418 px** sur `/`, **382 px** sur `/companies`) et sort de l'écran.

`PageHeader` est employé par **27 écrans sur 37**. Aujourd'hui seuls 2 débordent, parce que les 25 autres sont sur un état vide et n'affichent qu'une ou deux actions. **Le défaut est latent sur les 27.**

---

## 5. GRILLE — le sort des tableaux larges

**Ce que je mesure et ce que je ne mesure pas.** Aucune liste ne se rend sans données, et la console est inutilisable (A-012 / A07-001) : `tables = 0` et `role="row" = 0` sur les 37 écrans. **Je n'ai donc pas pu voir un tableau rempli.** J'ai mesuré autre chose, et c'est décisif : **le conteneur**, et **la largeur minimale que le gabarit de colonnes impose**.

### 5.1 Sonde du patron réel, et son témoin (`02_barre-tableaux-temoins.txt`, §3)

Injection dans le `<main>` réel de `/contacts`, à 375 px, du patron **exact** du produit — `<Card padding="none" className="overflow-hidden">` + `<div role="row">` portant la chaîne sticky de 210 caractères + le `gridTemplateColumns` copié de `ContactsListPage.tsx:58` :

| | **SONDE A** — patron du produit | **SONDE B** — témoin, même contenu dans `overflow-x-auto` |
|---|---|---|
| largeur visible de la carte | 343 px | 343 px |
| largeur du contenu | **1046 px** | 1046 px (dans le conteneur) |
| `overflow-x` du conteneur | **`hidden`** | `auto` |
| **défilable par l'utilisateur** | **NON** | **OUI** |
| bord droit de la dernière colonne | x = 1062 | x = 1062 |
| dernière colonne visible | non | non — **mais atteignable en faisant défiler** |

Le témoin prouve que le contrôle sait distinguer les deux issues : **le même contenu, dans le bon conteneur, devient atteignable.** Ce qui manque n'est pas la place, c'est le conteneur.

### 5.2 Largeur minimale **mesurée** des 9 tableaux « grille `role="row"` » (`04_largeurs-tableaux.txt`)

Écran de 375 px → `<main>` à `px-4` → **343 px utiles**.

| Écran | gabarit déclaré | largeur mini **mesurée** | hors écran | facteur |
|---|---|---:|---:|---:|
| `/audit-logs` | `AuditLogsPage.tsx:45` | **1088 px** | 745 px | ×3,2 |
| `/rgpd/requests` | `RgpdRequestsPage.tsx:64` | **1086 px** | 743 px | ×3,2 |
| `/contacts` | `ContactsListPage.tsx:58` | **1046 px** | 703 px | ×3,0 |
| `/users` | `UsersPage.tsx:37` | **1004 px** | 661 px | ×2,9 |
| `/rgpd/ai-act` | `AiActRegisterPage.tsx:50` | **986 px** | 643 px | ×2,9 |
| `/llm/proxy-providers` | `ProxyProvidersPage.tsx:34` | **968 px** | 625 px | ×2,8 |
| `/llm/router` | `LlmRouterPage.tsx:53` | **936 px** | 593 px | ×2,7 |
| `/companies` | `CompanyRow.tsx:32` | **858 px** | 515 px | ×2,5 |
| `/llm/rotations` | `RotationsPage.tsx:35` | **718 px** | 375 px | ×2,1 |

`overflow-x` du `<Card>` : **`hidden`**. `overflow-x` du `<main>` : **`hidden`**. **Aucun de ces neuf tableaux n'est ni défilable, ni repliable, ni réduit à des cartes sur téléphone : entre 52 % et 68 % de chaque ligne est hors d'atteinte.**

### 5.3 Les 7 écrans à `<table>` sémantique — 4 tiennent, 3 non

| Écran | conteneur | verdict |
|---|---|---|
| `/media` | `MediaListPage.tsx:271` `overflow-x-auto` + `min-w-[860px]` | ✅ **défile** |
| `/journalists` | `JournalistsListPage.tsx:109` `overflow-x-auto` + `min-w-[760px]` | ✅ **défile** |
| `/audiences/$id` | `AudienceDetailPage.tsx:219` `overflow-x-auto` | ✅ **défile** |
| `/international/roumanie` | `RoumaniePage.tsx:174` `overflow-x-auto` + `min-w-full` | ✅ **défile** |
| `/campaigns/$id` | `CampaignDetailPage.tsx:338, 385, 422` — **3 tables**, `Card overflow-hidden`, **aucun conteneur défilable** | 🔴 **rogné** |
| `/media/$id` | `MediaDetailPage.tsx:176` `w-full`, **aucun conteneur** | 🔴 **rogné** |
| `/admin/observability` | `ObservabilityPage.tsx:134` `w-full`, **aucun conteneur** | 🔴 **rogné** |

**Bilan tableaux : 4 écrans sur 16 se comportent correctement à 375 px. 12 rognent.** Et l'inventaire des conteneurs défilables de tout le dépôt tient en **5 lignes sur 4 fichiers** (`grep overflow-x-auto|overflow-x-scroll frontend/src`), dont une porte sur un `<pre>` de JSON.

C'est **D27-005 vu de profil** : les trois idiomes de tableau ne divergent pas seulement en style, ils divergent en **atteignabilité sur téléphone** — et l'idiome le plus répandu (9 écrans) est celui qui rogne.

---

## 6. GRILLE — la densité responsive du dépôt

| Mesure | Valeur |
|---|---:|
| Préfixes responsive dans tout `frontend/src` (`sm:` `md:` `lg:` `xl:`) | **96** — 21 + 34 + 34 + 7 |
| Fichiers `.tsx` | 84 |
| **Fichiers de `features/` sans aucun préfixe responsive** | **23 / 49** |
| Conteneurs à défilement horizontal | **5** |
| Conteneurs `overflow-x-hidden` | **1** — et c'est le conteneur principal |

À titre de repère : la coquille (`RootLayout` + `Header` + `Sidebar`) concentre à elle seule une bonne part des 96 préfixes. **La moitié des écrans n'a jamais reçu une seule règle de mise en page pour petit écran.**

---

# CONSTATS

### [D30-001] Le conteneur principal rogne au lieu de laisser défiler : ce qui dépasse 375 px est perdu, sans barre ni geste pour y accéder
- Sévérité      : S1 grave
- Domaine       : interface / UX
- Référence     : main 4ca52c9 — `frontend/` identique à e8924b8 (`git diff --stat e8924b8 HEAD -- frontend/` vide)
- Emplacement   : `frontend/src/app/RootLayout.tsx:100`
- Constat       : `<main id="main">` porte `overflow-x-hidden`, et sur trois écrans son contenu mesure 422 à 606 px pour 375 px visibles ; les 47 à 231 px excédentaires ne sont atteignables par aucun moyen.
- Preuve        : `node scripts/mesure375.mjs` → `01_mesure-375px.json`. `getComputedStyle(main).overflowX = "hidden"` sur **37/37** écrans. Trois écrans hors écran : `/` **458/375 (83 px)**, `/companies` **422/375 (47 px)**, `/rgpd/requests` **606/375 (231 px)**. Sur les mêmes écrans, `document.documentElement.scrollWidth = 375` : **la page ne défile pas**, et aucun conteneur défilable n'existe (`scrollers = 0` sur les 37). Captures : `captures-375/accueil.png` (le bouton « Actualiser » coupé au bord droit), `captures-375/rgpd_requests.png`.
- Témoin négatif: le détecteur rend `nOffenders = 0` sur **32 écrans sur 37** — il ne signale pas tout le monde. Et il **sait** reconnaître le cas défilable : la SONDE B de `02_barre-tableaux-temoins.txt`, même contenu de 1046 px dans un `overflow-x-auto`, rend `defilableParUtilisateur: true`, là où la SONDE A (patron du produit) rend `false`. Enfin, sur les 4 écrans d'authentification, le halo décoratif `absolute -right-32` est relevé comme dépassant, et j'ai **écarté** ce cas explicitement : il est vide et contenu. Le contrôle sépare donc bien les trois issues (défile / rogne / décoratif).
- Impact        : sur téléphone, un utilisateur ne peut pas rafraîchir le tableau de bord (`/`), ne peut pas atteindre la fin de la barre d'actions de `/companies`, et — le plus grave — **ne peut pas sélectionner « Rectification · art. 16 » ni « Opposition · art. 21 » sur `/rgpd/requests`** : deux des cinq droits RGPD que l'écran existe pour traiter. Il n'y a **aucun signal** que du contenu manque : pas d'ombre, pas de barre, pas de dégradé. L'écran a simplement l'air fini.
- Reproduction  : conteneur relais actif, puis `node scripts/mesure375.mjs http://app.localhost:58130 <dossier>` ; ou visuellement, ouvrir `http://app.localhost:58130/rgpd/requests` dans un viewport de 375 px.
- Correctif     : (1) corriger la cause racine dans `PageHeader` (D30-008) ; (2) sur `/rgpd/requests`, envelopper le `SegmentedControl` d'un `overflow-x-auto` ou le passer en `<select>` sous `md:` ; (3) **ne pas** retirer `overflow-x-hidden` de `<main>` sans avoir traité (1) et (2) — le retirer d'abord ferait apparaître une barre de défilement horizontale sur toute l'application, ce qui est un défaut différent, pas un progrès. **Coût : ~1 j** pour les trois écrans, à condition de faire (1) d'abord.
- Statut        : ouvert

---

### [D30-002] Les neuf tableaux « grille » exigent 718 à 1088 px et ne sont enfermés dans aucun conteneur défilable : entre 52 % et 68 % de chaque ligne est inatteignable sur téléphone
- Sévérité      : S1 grave
- Domaine       : interface / UX
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : `ContactsListPage.tsx:58` · `CompanyRow.tsx:32` · `LlmRouterPage.tsx:53` · `ProxyProvidersPage.tsx:34` · `RotationsPage.tsx:35` · `AiActRegisterPage.tsx:50` · `AuditLogsPage.tsx:45` · `RgpdRequestsPage.tsx:64` · `UsersPage.tsx:37` — et `CampaignDetailPage.tsx:338,385,422` · `MediaDetailPage.tsx:176` · `ObservabilityPage.tsx:134` pour l'idiome `<table>`
- Constat       : les gabarits de colonnes déclarés imposent une largeur minimale de 718 à 1088 px, le conteneur visible en fait 343, et ni le `<Card padding="none" className="overflow-hidden">` ni le `<main>` ne sont défilables.
- Preuve        : `node scripts/largeurs-tableaux.mjs` → `04_largeurs-tableaux.txt`. Le patron **exact** du produit est injecté dans le `<main>` réel à 375 px et sa `scrollWidth` est lue : `/audit-logs` **1088 px**, `/rgpd/requests` **1086**, `/contacts` **1046**, `/users` **1004**, `/rgpd/ai-act` **986**, `/llm/proxy-providers` **968**, `/llm/router` **936**, `/companies` **858**, `/llm/rotations` **718** — pour **343 px** visibles. `overflow-x` mesuré : `Card = hidden`, `main = hidden`. Côté `<table>` : `grep -rn -B4 "<table" frontend/src/features` → **4 des 7** écrans ont un `overflow-x-auto` (`/media` `min-w-[860px]`, `/journalists` `min-w-[760px]`, `/audiences/$id`, `/international/roumanie`) et **3 n'en ont pas** (`/campaigns/$id` — trois tables —, `/media/$id`, `/admin/observability`). Inventaire complet des conteneurs défilables du dépôt : **5 occurrences, 4 fichiers**.
- Témoin négatif: SONDE A / SONDE B de `02_barre-tableaux-temoins.txt`. Même contenu, même largeur de 1046 px : dans le patron du produit `defilableParUtilisateur = false`, dans un conteneur `overflow-x-auto` `= true`. Le contrôle n'est donc pas biaisé vers « rien ne défile ». Second témoin : le même relevé statique **trouve** les 4 écrans qui ont bien leur conteneur — il ne rend pas un « aucun » de principe.
- Impact        : sur téléphone, `/audit-logs` ne montre que ses deux premières colonnes sur sept ; `/users` masque les rôles, l'état 2FA et la dernière connexion ; `/contacts` masque l'e-mail, le score, le téléphone. **Aucun signal ne dit qu'il manque des colonnes.** Sur `/audit-logs`, un écran dont l'objet est la preuve, cela revient à consulter un journal amputé sans le savoir. C'est **D27-005 vu de profil** : l'idiome majoritaire (9 écrans) est celui qui rogne, et il a été copié à l'identique dans 8 fichiers — le défaut l'a été avec lui.
- Reproduction  : `node scripts/largeurs-tableaux.mjs http://app.localhost:58130 <dossier>`.
- Correctif     : envelopper chaque tableau d'un `<div className="overflow-x-auto">` — c'est **une ligne par écran**, le motif existe déjà 4 fois dans le dépôt et n'a rien à inventer (**~2 h pour les 12**). C'est le **palliatif**, pas la cible : une ligne de 1088 px lue par défilement horizontal reste médiocre. La vraie réponse est une bascule en cartes sous `md:` — qui n'a de sens **qu'une fois le composant `Table` du D27-005 extrait**, sinon il faut l'écrire 12 fois (**~3 j après le composant**). *Ordre recommandé : le pansement d'abord, il est disponible aujourd'hui.*
- Statut        : ouvert

---

### [D30-003] 461 cibles tactiles sur 473 mesurent moins de 44 × 44 px, dont 82 moins de 24 × 24 ; la coquille de navigation elle-même n'en a aucune conforme
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : `components/layout/Header.tsx:27-77` (les 8 cibles de coquille présentes sur les 32 écrans à coquille) · `components/ui/Button.tsx` (`SIZES.md` → 36 px de haut) · `components/ui/IconButton.tsx` (`size="sm"` → 28 px) · `components/ui/DarkModeToggle.tsx` (24 px) · `components/layout/Sidebar.tsx:340` (entrées 32 px, 28 px en replié)
- Constat       : sur les 37 écrans à 375 px, 473 éléments interactifs sont visibles et 461 ne remplissent pas 44 × 44 px, la hauteur étant le facteur limitant dans la quasi-totalité des cas.
- Preuve        : `01_mesure-375px.json`. Totaux : **473 cibles · 461 sous 44 × 44 (97,5 %) · 82 sous 24 × 24 (17,3 %) · 12 conformes**. Répartition des hauteurs : 16 px ×8, 20 px ×42, 24 px ×131, 25 px ×42, 28 px ×99, 32 px ×31, 36 px ×58, 38 px ×10, 40 px ×40, **44 px ×1**, 52 px ×6, 56 px ×1, puis 3 zones de saisie et 1 canevas. La coquille contribue **263** des 461 (8 à 9 par écran, sur 32 écrans) : hamburger 28×28, recherche 28×28, notifications 28×28, les trois bascules de thème 26×24 / 26×24 / 23×24, le lien du fil d'Ariane 66×20, l'avatar 44×**40**. Détail complet de `/companies` : **33 cibles, 33 non conformes, aucune exception**. Les 12 conformes se réduisent en pratique à **6 contrôles atteignables** : 5 des 12 sont les boutons de `/rgpd/requests` **hors écran** (D30-001) et 1 est le canevas MapLibre de `/coverage`.
- Témoin négatif: `02_barre-tableaux-temoins.txt` §4 — on plante dans `<main>` un `<button style="width:48px;height:48px">` ; le détecteur le relève aussitôt parmi les cibles conformes, aux côtés de « Démarrer sur /coverage » et des cinq entrées de la barre basse plantée. **Il sait dire oui.** Et il dit oui 12 fois sur le corpus réel, sur 6 écrans distincts : le 97,5 % n'est pas un artefact de sélecteur.
- Impact        : le seuil de 44 × 44 est celui du mandat (et de WCAG 2.1 AAA 2.5.5) ; le seuil **plancher** de WCAG 2.2 AA (2.5.8) est 24 × 24, et **82 cibles ne l'atteignent même pas**. Les voici nommément, elles ne sont que quatre familles : la bascule **« Theme dark » 22,9 × 24** (présente sur les 32 écrans à coquille), les **liens du fil d'Ariane, 20 px de haut** (≈ 41 occurrences), **« Retour à la connexion » 295 × 16** sur les 3 écrans d'authentification secondaires, et **2 cases à cocher de 16 × 16**. Concrètement : sur téléphone, **ouvrir le menu, lancer une recherche, changer de thème et ouvrir son compte se font tous sur des cibles de 23 à 28 px**. Un utilisateur qui rate le hamburger de 28 px n'a **aucune autre voie de navigation** — il n'y a pas de barre basse (D30-004).
- Reproduction  : `node scripts/mesure375.mjs http://app.localhost:58130 <dossier>`, puis lire `totaux` dans `01_mesure-375px.json`.
- Correctif     : ne pas retoucher 473 éléments un par un — **agir dans le système**, ce qui est possible parce que 5 composants concentrent l'essentiel : porter `IconButton size="sm"` et `Button SIZES.sm/md` à 44 px de hauteur **sous `md:` uniquement** (une variante `@media (pointer: coarse)` évite d'alourdir le bureau), et faire de même pour les entrées de `Sidebar` et les bascules de `DarkModeToggle`. **Coût : ~1 j** pour les 5 composants, ce qui traite mécaniquement les 263 cibles de coquille et une large part des 198 de contenu. **À faire après D30-001** : agrandir les actions d'en-tête avant d'avoir corrigé `PageHeader` aggraverait le débordement.
- Statut        : ouvert

---

### [D30-004] La barre basse à cinq entrées exigée par le §23.3 n'existe pas, et rien n'en tient lieu : la seule navigation sur téléphone est un hamburger de 28 × 28 px
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : absence — `frontend/src/app/RootLayout.tsx` (aucun élément de bas d'écran) · `frontend/src/components/layout/Header.tsx:27` (le hamburger qui en tient lieu)
- Constat       : aucun composant ni aucun élément ancré au bas du viewport ne porte les entrées *Aujourd'hui · Contacts · Échanges · Rechercher · Plus*, ni sur les 37 écrans mesurés, ni dans le code.
- Preuve        : `03_statique-barre-basse-et-tableaux.txt` §A. Six noms cherchés (`BottomBar`, `BottomNav`, `TabBar`, `MobileNav`, `bottom-nav`, `BarreBasse`) → **0 fichier chacun**. `grep -rn "bottom-0" frontend/src` → **une seule ligne**, `components/ui/Modal.tsx:124`, le pied de modale. Le libellé « Échanges » → **0 occurrence** dans tout `frontend/src`. Et dans le navigateur, sur les 37 écrans à 375 px : `nBarreBasse = 0` (`01_mesure-375px.json`, `totalBarresBasses: 0`).
- Témoin négatif: double. (a) Statique : le même `grep "bottom-0"` **trouve** le pied de `Modal` — il n'est pas aveugle à un élément de bas d'écran. (b) Dynamique (`02_barre-tableaux-temoins.txt` §4) : sur `/` à 375 px, le détecteur rend **0** ; on plante alors une `<nav>` `position:fixed; bottom:0; height:56px` portant exactement les cinq entrées du CDC ; le détecteur rend **1** et restitue `"Aujourd'hui | Contacts | Échanges | Rechercher | Plus"`. **Il aurait vu la barre si elle existait.**
- Impact        : sur téléphone, atteindre n'importe quel écran passe obligatoirement par une cible de **28 × 28 px** en haut à gauche, puis par un tiroir qui ne se referme pas (D30-005). Le critère du §23.4 — « chaque parcours tient au même budget de clics sur téléphone » — en découle mécaniquement et **est raté d'un facteur 2** (§3.3 : 2 clics au bureau, 4 appuis sur téléphone). Ce constat **ferme le point 3 laissé ouvert par l'agent 23** (`10_NAVIGATION-CIBLE.md` §13.3, « le code ne contient aucune barre basse — mais je ne l'affirme pas sans l'avoir vue ») : je l'ai vue ne pas être là, dans un navigateur, sur les 37 écrans. Il complète aussi **A-006**, qui chiffrait l'écart à la cible sur la barre latérale sans traiter le téléphone.
- Reproduction  : les deux `grep` ci-dessus ; puis `node scripts/barre-tableaux-temoins.mjs http://app.localhost:58130 <dossier>`, §4.
- Correctif     : la barre basse **n'a pas de sens tant que le groupe ÉCHANGES n'existe pas** — le CDC lui donne 1 entrée sur 5, et A-006 mesure que le groupe entier est absent. Deux options, à trancher par Will : (a) livrer la barre basse **avec** l'étape 2 du chantier CRM cible, en même temps que les écrans d'échanges (~2 j côté barre, une fois les écrans là) ; (b) livrer **maintenant** un palliatif à 4 entrées (Aujourd'hui · Contacts · Rechercher · Plus) qui traite le vrai problème d'aujourd'hui — l'absence de navigation atteignable au pouce — sans promettre un écran qui n'existe pas (~1 j). **Je recommande (b)**, pour la même raison que l'étape 0 a retiré les entrées verrouillées : un menu ne promet pas ce qui n'existe pas.
- Statut        : ouvert

---

### [D30-005] Le tiroir de navigation ne se referme pas après une navigation, couvre tout l'écran sans voile atteignable, et laisse 115 px de bande morte : chaque parcours coûte 4 appuis au lieu de 2 clics
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : `frontend/src/app/RootLayout.tsx:69-93` (état `mobileSidebarOpen`, jamais remis à `false` sur changement de route) · `frontend/src/components/ui/Modal.tsx:106-115` (`Drawer` : panneau `w-full max-w-sm`) · `frontend/src/components/layout/Sidebar.tsx:197` (`w-[260px]` fixe à l'intérieur)
- Constat       : après un appui sur une entrée du menu, l'URL change et le tiroir reste ouvert par-dessus la destination, et à 375 px il n'existe aucune zone de voile ni aucun geste latéral pour le refermer.
- Preuve        : `05_parcours-et-tiroir.txt`, joué sous Playwright à 375 × 812, `hasTouch: true`.
  - après `click` sur `[role="dialog"] a[href="/journalists"]` : `location.pathname = "/journalists"` **et** `document.querySelector('[role="dialog"]') !== null` → `tiroir encore ouvert = true`, `document.body.style.overflow = "hidden"` ;
  - géométrie : voile `x = 0 → 375`, panneau `x = 0 → 375`, barre à l'intérieur `x = 0 → 260` → **bande morte de 115 px**, l'élément sous le doigt en (330, 600) est `DIV.-mx-6 -my-4`, à l'intérieur du panneau ;
  - appui en (330, 600) → tiroir toujours ouvert ; appui en (10, 400), là où le voile serait sur un écran large → **tiroir toujours ouvert** ;
  - budget de gestes, départ `/`, trois intentions : **bureau 1280 = 2 clics** (Paramètres, Journaux d'audit, Journaux de collecte) ; **téléphone 375 = 4 appuis**, dont un appui **obligatoire** sur le ✕ de 36 × 36.
  Capture : `captures-375/tiroir-navigation.png` — la bande blanche de droite y est visible.
  Statique, confirmant la cause : `grep -n "mobileSidebarOpen|useRouterState|useLocation" RootLayout.tsx` → l'état n'est remis à `false` que par `onClose` du `Drawer` et par le bouton « Réduire » ; **aucun écouteur de changement de route**.
- Témoin négatif: la touche **Échap ferme bien le tiroir** (mesuré dans la même session). Le mécanisme de fermeture n'est donc pas cassé, et mon contrôle sait le voir fonctionner : **ce qui manque, ce sont les chemins tactiles**. Second témoin : le détecteur trouve bien les 14 cibles du tiroir et les mesure une à une — il n'est pas aveugle au contenu du panneau.
- Impact        : sur téléphone, l'utilisateur appuie sur « Paramètres », l'écran ne change pas visiblement (le menu couvre tout), et il n'a **ni voile, ni bord, ni glissement** pour sortir : il doit trouver un ✕ de 36 px en haut à droite. C'est le scénario type du « l'application est bloquée ». Les 115 px de bande morte aggravent le malentendu : c'est la seule zone qui *ressemble* à un extérieur, et elle ne réagit pas. **Le §23.4 est raté d'un facteur 2, mesuré.** *Une confusion de navigation qui fait perdre l'utilisateur est au minimum S2.*
- Reproduction  : `node scripts/parcours.mjs http://app.localhost:58130 <dossier>`.
- Correctif     : trois corrections indépendantes, toutes petites. (1) fermer le tiroir sur changement de route — `const path = useRouterState({select: s => s.location.pathname}); useEffect(() => setMobileSidebarOpen(false), [path])` dans `RootLayout` (**~20 min**, et c'est le gros du gain) ; (2) `Drawer` : `max-w-sm` → `max-w-[85%]` sous `sm:` pour qu'il reste toujours une bande de voile à toucher (**~15 min**) ; (3) donner à la `Sidebar` du tiroir une largeur `w-full` au lieu de `w-[260px]`, ce qui supprime la bande morte et agrandit du même coup les cibles (**~15 min**). **Coût total : ~1 h.** *C'est le meilleur rapport de tout mon lot.*
- Statut        : ouvert

---

### [D30-006] La barre repliée ne se replie pas « aux mêmes positions » : 66 à 78 px d'écart, et jusqu'à 19 des 20 entrées n'existent pas dans l'autre mode
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : `frontend/src/components/layout/Sidebar.tsx:270-272` (`const deplie = collapsed || ouverte`) · `:184-186` (une seule section ouverte à la fois) · `:340` (entrées `px-2 py-1.5`)
- Constat       : en mode déployé la barre n'affiche que les entrées de la section ouverte, en mode replié elle les affiche toutes, et aucune entrée ne conserve sa position verticale entre les deux modes.
- Preuve        : `02_barre-tableaux-temoins.txt` §1, mesuré à 1280 × 900 dans un vrai navigateur, deux routes.
  Depuis `/companies` : `/contacts` **y = 187 → 121 (66 px)**, `/companies` **221 → 151 (70 px)**, `/journalists` **255 → 181 (74 px)**, `/media` **289 → 211 (78 px)**. Depuis `/` : `/` **152 → 81 (71 px)**.
  Déployée : largeur **260 px**, **6 titres** de section, **1 à 4 entrées** visibles, entrées **243 × 32**.
  Repliée : largeur **64 px**, **0 titre**, **20 entrées**, entrées **32 × 28**.
  Entrées affichées en replié **et absentes** en déployé : **19** depuis `/`, **16** depuis `/companies`.
  Pied de barre : `["Réduire"]` déployée, `["Étendre la barre latérale"]` repliée — **rien d'autre**, ce qui confirme la mesure de l'agent 23 (`cible-v2.json` → `pied: ["Réduire"]`) sur le bundle reconstruit.
  Captures : `captures-375/barre-1280-deployee.png` et `barre-1280-repliee.png`.
- Témoin négatif: la sonde **lit correctement les deux états** — elle rend 260 px / 6 titres / 1 lien dans un cas et 64 px / 0 titre / 20 liens dans l'autre, et elle retrouve chaque `href`. Elle n'écrase pas un état par l'autre. Et l'écart n'est pas un décalage constant que j'aurais pu confondre avec un artefact de mesure : il **croît** de 66 à 78 px, parce que le pas passe de 34 px à 30 px.
- Impact        : le §23.3 demande que replier la barre ne déplace pas les repères. Ici, replier **change tout à la fois** : la position, la taille des cibles (**32 × 28**, soit 4 px seulement au-dessus du plancher WCAG 2.2 de 24 × 24, et 16 px sous le seuil du mandat), la présence des libellés, et **l'ensemble des entrées affichées**. L'utilisateur qui replie ne retrouve pas sa barre plus petite : il en trouve une autre. **Ce n'est pas un bug** — `Sidebar.tsx:270-272` documente la décision en toutes lettres (*« l'accordéon n'a de sens que quand les libellés sont là »*), et elle est défendable. C'est un **arbitrage à trancher**, et il complète le point 4 laissé ouvert par l'agent 23.
- Reproduction  : `node scripts/barre-tableaux-temoins.mjs http://app.localhost:58130 <dossier>`, §1.
- Correctif     : le §23.3 n'est atteignable qu'en renonçant à l'accordéon **ou** en le conservant en mode replié (chaque section repliée devient une icône de groupe qui déplie au survol). La seconde voie garde les deux qualités et préserve les positions, au prix d'un survol de plus (**~1 j**). **Décision à porter à Will avec D30-004** : les deux touchent le §23.3, et il serait absurde de les trancher séparément.
- Statut        : ouvert

---

### [D30-007] Sur téléphone, le fil d'Ariane est le seul repère de position, et il est écrasé à 94 px sur les 32 écrans qui en ont un
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : `frontend/src/components/layout/Header.tsx:39` (`<div className="min-w-0 flex-1 truncate">`)
- Constat       : le conteneur du fil d'Ariane reçoit 94 px de large à 375 px, alors que son contenu en demande de 115 à 275, et la barre latérale qui porterait l'information est masquée à cette largeur.
- Preuve        : `01_mesure-375px.json`, colonne `filAriane` — mesuré sur les 32 écrans à coquille, `clientWidth = 94` **partout**, `scrollWidth` de **115** (`/tags`) à **275** (`/console/personnes/$k`). Exemples : `/console/personnes/abc123` demande 275 px pour rendre `Accueil › / › Console › / › Personnes › / › Abc123` ; `/international/roumanie` 237 ; `/rgpd/ai-act` 226 ; `/campaigns/$id` 223. Capture : `captures-375/rgpd_requests.png`, où l'en-tête affiche `Accueil / R(` — le fil est coupé au deuxième segment.
- Témoin négatif: le même relevé rend `filAriane = null` sur les 4 écrans d'authentification et le 404 — ils n'ont pas de coquille, et le contrôle ne leur invente pas de fil d'Ariane. Sur `/` il rend **94/94**, c'est-à-dire **aucune troncature** : le fil « Accueil » tient. Le détecteur distingue donc bien tronqué et non tronqué.
- Impact        : à 375 px, la barre latérale est masquée et le tiroir est fermé : le fil d'Ariane est **le seul élément persistant qui dise où l'on est**. Réduit à 94 px, il n'en montre qu'un segment et demi — et **sans points de suspension** : la classe `truncate` ne peut pas ellipser un enfant `flex`, la coupe se fait **au milieu d'un caractère**. La capture `captures-375/rgpd_requests.png` montre littéralement `Accueil / R(`. Sur la fiche 360° (`/console/personnes/$k`), l'écran le plus profond du produit, le fil demande 275 px et en reçoit 94. Les 94 px viennent d'un `flex-1` mis en concurrence, dans la même rangée, avec **cinq blocs de contrôles** — hamburger, loupe, cloche, bascule de thème (3 boutons), menu du compte — dont **aucun ne cède** : `flex-1` obtient ce qui reste, et il ne reste que 94 px.
- Reproduction  : ouvrir n'importe quel écran à 375 px et lire `document.querySelector('header .truncate')` → `clientWidth = 94`.
- Correctif     : sous `md:`, n'afficher que **le dernier segment** du fil (le nom de l'écran courant), en clair et sur toute la largeur disponible — c'est l'information utile, les ancêtres ne sont pas cliquables utilement sur 94 px. Alternative : déplacer le fil sur une deuxième ligne sous les contrôles. **Coût : ~2 h** dans `AutoBreadcrumbs` + `Header`.
- Statut        : ouvert

---

### [D30-008] `PageHeader` pose `shrink-0` sur son bloc d'actions, ce qui annule le `flex-wrap` qu'il porte lui-même : le repli est écrit et ne peut pas se produire, sur 27 écrans
- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : `frontend/src/components/ui/PageHeader.tsx:18` et `:37`
- Constat       : l'en-tête déclare `flex flex-wrap` à la ligne 18 et `shrink-0` sur le bloc d'actions à la ligne 37 ; un bloc qui ne peut pas se réduire conserve sa largeur `max-content` et sort de l'écran au lieu de se replier.
- Preuve        : mesure dans le navigateur à 375 px (`01_mesure-375px.json`, champ `offenders`). Sur `/` : `div [flex shrink-0 flex-wrap items-center gap-2]` **w = 418 px**, bord droit à **x = 458** pour un écran de 375. Sur `/companies` : le même bloc, **w = 382 px**, bord droit à **x = 422**, contenu « Importer Exporter Lancer scraping → ». `grep -rl "PageHeader" frontend/src/features` → **27 écrans**.
- Témoin négatif: le contrôle ne signale ce bloc que **là où il déborde** : sur les 25 autres écrans qui emploient `PageHeader`, `nOffenders = 0`. Ce n'est donc pas une accusation portée sur le composant en général mais sur son comportement à une largeur donnée. Et l'en-tête `<header>` de la ligne 18, lui, **se replie bien** : le titre passe sur sa propre ligne, ce que la capture `captures-375/rgpd_requests.png` montre. Le repli extérieur fonctionne ; c'est le repli intérieur qui est bloqué.
- Impact        : cause racine de deux des trois débordements de D30-001. Aujourd'hui seuls 2 écrans sur 27 en souffrent, parce que les 25 autres sont mesurés **à l'état vide** et n'affichent qu'une ou deux actions. **Dès que les écrans afficheront leurs actions réelles — c'est-à-dire dès que la console sera utilisable — le défaut se manifestera sur une partie bien plus large des 27.** C'est un défaut **latent que l'état dégradé masque**, et c'est précisément pourquoi je le sépare de D30-001 : corriger les deux écrans sans corriger le composant ne réglerait rien.
- Reproduction  : ouvrir `/` à 375 px et lire `document.querySelector('header > div:last-child').getBoundingClientRect()`.
- Correctif     : remplacer `shrink-0` par `min-w-0` sur le bloc d'actions (ligne 37) — le `flex-wrap` déjà présent fait alors son travail et les actions passent à la ligne. Vérifier ensuite à 1280 px que le bloc ne se comprime pas sur un écran large (ajouter `md:shrink-0` si nécessaire). **Coût : ~30 min, plus une passe visuelle sur les 27 écrans.** *C'est le correctif au meilleur rendement du lot avec celui de D30-005.*
- Statut        : ouvert

---

### [D30-009] Sur téléphone, la recherche demande deux appuis pour arriver à un champ, et le résultat s'annonce en raccourcis clavier
- Sévérité      : S3 finition
- Domaine       : navigation / UX
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : `frontend/src/app/RootLayout.tsx:105-113` (`<Modal title="Recherche"><GlobalSearch/></Modal>`) · `frontend/src/components/ui/GlobalSearch.tsx:14` et `:49` (le composant porte **son propre** état `open` et **son propre** bouton déclencheur)
- Constat       : la modale de recherche mobile contient le déclencheur de la palette au lieu de la palette elle-même, ce qui empile deux dialogues et impose un second appui.
- Preuve        : `05_parcours-et-tiroir.txt`. Après appui sur l'icône loupe de l'en-tête : **1 dialogue**, contenu `"Recherche | 🔍 | Rechercher | ⌘K"`, contrôles `["Fermer 28x28", "Recherche globale 169x34"]` — **aucun champ de saisie**. Après le second appui : **2 dialogues empilés**, contenu `"Recherche | 🔍 | Esc | Tape au moins 2 caractères pour rechercher. | ⌘K pour ouvrir / fermer | ↑↓ naviguer · ↵ ouvrir"`. Capture : `captures-375/recherche-mobile.png`. Le champ obtenu mesure **217 × 44** — l'un des rares contrôles conformes du produit.
- Témoin négatif: le contrôle **compte bien 1 dialogue** au premier appui et **2** au second — il n'agrège pas, et il n'invente pas la palette absente au premier niveau. Sur les écrans sans modale ouverte, il rend 0.
- Impact        : finition, mais sur le chemin le plus court du produit. Le §23.3 fait descendre « Rechercher (⌘K) » dans le pied de barre, ce qui suppose une recherche accessible en un geste. Ici : deux appuis, une modale intermédiaire vide, et trois indications qui ne veulent rien dire sur un téléphone — `⌘K`, `↑↓ naviguer`, `↵ ouvrir`. En outre `GlobalSearch` referme **sa** palette après un résultat (`close()`, `GlobalSearch.tsx:101`) mais **pas** la modale extérieure : même défaut de fermeture que D30-005.
- Reproduction  : `node scripts/parcours.mjs`, ou à 375 px appuyer sur la loupe puis observer.
- Correctif     : donner à `GlobalSearch` une prop `open`/`onOpenChange` contrôlée depuis l'extérieur, et sur téléphone ouvrir directement la palette au lieu de la modale enveloppante (**~2 h**). Masquer `⌘K` / `↑↓` / `↵` sous `md:` (**~15 min**).
- Statut        : ouvert

---

### [D30-010] Vingt-trois des quarante-neuf fichiers d'écran ne portent aucune règle de mise en page pour petit écran
- Sévérité      : S3 finition
- Domaine       : interface
- Référence     : main 4ca52c9 (`frontend/` = e8924b8)
- Emplacement   : `frontend/src/features/` — 23 fichiers sur 49
- Constat       : 96 préfixes responsive (`sm:` `md:` `lg:` `xl:`) existent dans tout `frontend/src`, et 23 des 49 fichiers de `features/` n'en portent aucun.
- Preuve        : `03_statique-barre-basse-et-tableaux.txt` §F. `sm:` **21**, `md:` **34**, `lg:` **34**, `xl:` **7** → **96** au total sur **84** fichiers `.tsx`. Boucle sur `features/` : **23 fichiers sur 49** sans une seule occurrence. Comparaison utile : la coquille seule (`RootLayout`, `Header`, `Sidebar`) en concentre une part importante.
- Témoin négatif: le même comptage trouve bien les 96 occurrences là où elles sont, et il liste nommément les 26 fichiers qui en ont — il ne rend pas un « aucun » de principe. Corroboration indépendante : **la mesure dynamique ne trouve que 3 écrans en débordement**. Autrement dit, **l'absence de règles responsive ne produit pas 23 écrans cassés aujourd'hui** — parce que l'état vide est simple. Je ne grossis donc pas ce constat : c'est un **indicateur de dette**, pas une panne, et je le classe S3 pour cette raison.
- Impact        : les écrans qui n'ont jamais reçu de règle pour petit écran sont ceux dont on ne saura pas comment ils se comportent une fois remplis. Combiné à D30-008 (le repli des en-têtes est bloqué) et à D30-002 (les tableaux n'ont pas de conteneur), cela dit que **le comportement à 375 px n'a pas été une préoccupation de conception**, et que les 3 débordements mesurés aujourd'hui sont un plancher, pas un plafond.
- Reproduction  : la boucle du §F de `03_statique-barre-basse-et-tableaux.txt`.
- Correctif     : aucun correctif de masse n'a de sens. Ce constat sert de **prise de mesure d'avant-travaux** : le rejouer après les correctifs de D30-001/002/003/005/008 pour vérifier que la dette baisse. **Coût : nul en soi.**
- Statut        : ouvert

---

# CE QUE JE N'AI PAS PU VÉRIFIER, ET POURQUOI

1. 🔴 **Les listes remplies.** C'est ma limite principale et je la mets en premier. La console est inutilisable (**A-012**, **A07-001**) et, depuis mon origine en clair, la base d'API absolue du bundle rend tous les appels impossibles. **Aucun tableau, aucune ligne, aucune pagination, aucune action de masse n'a été vue à 375 px.** J'ai contourné pour les tableaux, en mesurant le conteneur et le gabarit de colonnes avec une sonde et son témoin (§5) — c'est solide et suffisant pour conclure au rognage. Ce n'est **pas** suffisant pour : la hauteur réelle des lignes, le comportement de l'en-tête `sticky` au défilement vertical, les menus contextuels de ligne, les cases à cocher de sélection multiple, et le pied de pagination. **À rejouer le jour où quelqu'un pourra se connecter.**
2. **Les 3 écrans à `<main>` vide** — `/media/$id`, `/campaigns/$id`, `/audiences/$id` (constat **D22-003** de l'agent 22). Je confirme leur vacuité à 375 px (9 à 10 cibles, toutes de coquille, 1 seule de contenu) mais **je n'ai donc rien mesuré de leur mise en page** : il n'y a rien à mesurer. Leur ligne dans la grille du §1 dit « pas de débordement » ; **cela ne veut pas dire qu'elle tient, cela veut dire qu'elle est vide.**
3. **Les autres largeurs.** J'ai mesuré **375 px** (le mandat) et **1280 px** (pour la barre repliée). **Je n'ai mesuré ni 320 px** (le plancher que la spec §24 prescrit — et 320 px est plus étroit que les 343 px utiles de ma mesure, donc mes chiffres de débordement y seraient **pires**), **ni 768 px** (la bascule `md:`, où la barre latérale est encore masquée mais où les tableaux ont 736 px), **ni 2560 px**. Le seuil `lg` (1024 px) est le plus intéressant des trois manquants : c'est là que la barre latérale réapparaît, et je n'ai pas vérifié qu'entre 768 et 1024 l'écran n'est pas dans un entre-deux.
4. **Le contraste et le mode sombre à 375 px.** Hors périmètre, et **D27-002** avertit que le rendu sombre réel n'est pas celui que les composants décrivent. Toutes mes mesures sont en **mode clair**. Une mesure en sombre est à refaire **après** la correction de D27-002, sans quoi elle mesurerait le filet `!important` et non les composants.
5. **Le geste tactile réel.** Playwright déclare `hasTouch: true` et j'ai mesuré des géométries, mais **je n'ai testé aucun geste de glissement** (balayage pour fermer le tiroir, défilement horizontal au doigt dans un conteneur). Mon affirmation « `overflow-x: hidden` n'est pas défilable au doigt » est une propriété du modèle CSS, **pas une mesure que j'ai jouée**. Sur un téléphone réel, à vérifier.
6. **La production.** `app.axion-crm-pro.com` n'a pas été ouvert : le dossier interdit toute écriture, et je n'avais pas besoin d'y toucher puisque `frontend/` n'a pas bougé depuis `e8924b8`. **Mais je n'affirme donc pas que le bundle de production porte le même code** — l'agent 23 laissait déjà cette question ouverte (son point 1).
7. **Le seuil de 44 px lui-même.** Je le tiens du mandat. Il correspond à WCAG 2.1 **AAA** (2.5.5) et aux règles d'Apple. Le seuil **AA** de WCAG 2.2 (2.5.8) est de **24 × 24**, et je donne les deux chiffres (461 et 82) pour que l'arbitrage soit fait sur des faits et non sur un seul seuil. **Je n'ai pas mesuré les exceptions prévues par 2.5.8** (espacement suffisant, équivalent en ligne) : le décompte de 82 est donc un **majorant**, et je ne le présente pas autrement.
8. **Le §23.4 au-delà de trois intentions.** J'ai mesuré 3 parcours sur les 20 que l'agent 23 a instruits. Les trois donnent le même résultat (2 → 4), et la cause est structurelle, mais **17 parcours n'ont pas été joués**.
9. **Le coût de mes correctifs.** Ce sont des ordres de grandeur, pas des mesures. Le seul dont je sois sûr est celui de D30-005 : trois modifications de moins de dix lignes chacune.
10. **La stabilité de la référence.** `main` a avancé **cinq fois** pendant ma mission (`e8924b8` → `4ca52c9`). J'ai revérifié à la clôture que `git diff --stat e8924b8 HEAD -- frontend/` rend une sortie vide. **Si une PR touchant `frontend/` atterrit après cette lecture, mes numéros de ligne se décalent** — à re-vérifier avant tout correctif.
