# AGENT 27 — Auditeur du design system

- **Référence mesurée** : `main = e8924b8` (`git rev-parse HEAD` rejoué au début et à la fin ; identique au `e8924b8` annoncé par le dossier commun).
- **Périmètre** : les 34 composants de `frontend/src/components/` × les 37 écrans de `frontend/src/features/`
  (liste des 37 écrans établie depuis `frontend/src/app/routeTree.tsx`, pas depuis un document).
- **Nature du travail** : statique (lecture, comptage, comparaison), conformément à la consigne A-009.
  **Une seule** mesure dynamique a été faite, et elle ne passe pas par l'API : une lecture de styles
  calculés dans le navigateur sur `https://app.localhost` (conteneur `app`), pour trancher une question
  de cascade CSS que le code seul ne tranche pas (cf. D27-002).
- **Preuves brutes** : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-27/`
  - `01_usage-composants.txt` — composant → écrans, écran → composants
  - `02_mode-sombre.txt` / `02_mode-sombre-v2.txt` / `02_mode-sombre-v3.txt` — trois générations du détecteur (v1 et v2 conservées car elles montrent **mes propres faux positifs**, cf. §« méthode »)
  - `03_temoin-negatif-detecteur.txt` — témoin positif/négatif du détecteur de mode sombre
  - `04_override-important.txt` — décompte des `dark:` neutralisées
  - `05_navigateur-important-vs-dark.txt` — mesure navigateur + témoin négatif
  - `06_balisage-recopie.txt` — écran → balisage recopié
  - `07_temoin-negatif-recopie.txt` — témoin positif/négatif du détecteur de recopie

---

## 0. Méthode — et l'aveu qui va avec (règle 1 appliquée à moi-même)

Mon premier détecteur de mode sombre (`02_mode-sombre.txt`) a rendu **97 occurrences sur 36 fichiers**.
Il avait tort. Il analysait chaîne de caractères par chaîne de caractères, alors que la moitié du code
écrit `cn('bg-white ring-1 ring-slate-200', 'dark:bg-slate-900 dark:ring-slate-800', …)` — deux arguments,
deux chaînes. Il accusait `Modal`, `KpiCard`, `DropdownMenu` et les 8 en-têtes de tableau à tort.

La v2 (fenêtre de ±6 lignes) est tombée dans l'excès inverse et a **effacé** de vrais défauts.

La v3 extrait l'**expression `className=` complète, accolades équilibrées**, et évalue la couverture
`dark:` à l'intérieur de cette expression seulement. Elle rend **92 jetons sur 15 fichiers**.
**Témoin positif/négatif joué** (`03_temoin-negatif-detecteur.txt`) : sur une copie de
`components/` augmentée de deux fichiers plantés, le détecteur signale `PlanteBad.tsx`
(`bg-white text-black`, sans `dark:`) et **ne signale pas** `PlanteGood.tsx`
(`bg-white … dark:bg-slate-900 dark:text-white`).

Le détecteur de balisage recopié a subi le même contrôle (`07_temoin-negatif-recopie.txt`) :
l'écran planté est relevé sur ses 5 motifs, le témoin sain rend 0.

Les chiffres cités plus bas sont donc **ceux de la v3**. Je laisse les deux versions fausses dans les
preuves : un audit qui ne montre que sa dernière tentative demande qu'on lui fasse confiance.

---

## 1. GRILLE — composant → nombre d'écrans qui l'emploient

« Écrans » = les 37 composants de route. La colonne « autres consommateurs » compte les fichiers hors
`components/` qui l'importent (sous-composants d'écran, coquille `app/RootLayout.tsx`).

| # | Composant | Écrans /37 | Autres consommateurs | Verdict |
|---|---|---:|---:|---|
| 1 | `ui/Card` | **29** | 37 | pilier du DS |
| 2 | `ui/PageHeader` | **27** | 27 | pilier du DS |
| 3 | `ui/Button` | **23** | 24 | pilier du DS |
| 4 | `ui/EmptyState` | **22** | 25 | pilier du DS |
| 5 | `ui/cn` | **19** | 27 | utilitaire, sain |
| 6 | `ui/StatusPill` | **17** | 19 | sain |
| 7 | `ui/KpiCard` | **15** | 15 | sain |
| 8 | `ui/Input` | **10** | 10 | **sous-employé** — 19 champs écrits à la main à côté (D27-008) |
| 9 | `ui/Skeleton` (`CompaniesTableSkeleton`) | **9** | 9 | sain |
| 10 | `ui/Tabs` | **8** | 8 | sain |
| 11 | `ui/Toolbar` (`SearchInput`) | **8** | 8 | sain |
| 12 | `ui/Toolbar` (`Toolbar`) | **6** | 6 | sain |
| 13 | `ui/Spinner` | **5** | 5 | sain |
| 14 | `ui/Skeleton` (`Skeleton`) | **5** | 6 | sain |
| 15 | `ui/Modal` (`Modal`) | **4** | 5 | sain |
| 16 | `ui/DropdownMenu` | **4** | 5 | sain |
| 17 | `ui/Modal` (`Drawer`) | **3** | 4 | sain |
| 18 | `ui/PageShell` | **3** | 3 | **2 des 3 sont les stubs morts `/cold-email` et `/linkedin`** (D27-013) |
| 19 | `ui/SegmentedControl` | **3** | 3 | **refait à la main dans `/coverage`** (D27-003) |
| 20 | `ui/Avatar` | **3** | 6 | sain |
| 21 | `ui/IconButton` | **2** | 3 | **cloné 3 fois à la main** (D27-009) |
| 22 | `ui/Breadcrumbs` | **1** | 1 (+`AutoBreadcrumbs`) | sain |
| 23 | `ui/DarkModeToggle` | **1** | 1 (+`Header`) | sain |
| 24 | `ui/FormField` | **1** | 1 | **quasi mort** — 30 champs bruts à côté (D27-008) |
| 25 | `ui/QualityBadge` | **1** | 3 | mode sombre absent (D27-006) |
| 26 | `ui/SizeCategoryBadge` | **1** | 2 | mode sombre absent (D27-006) |
| 27 | `ui/Tooltip` | **1** | 1 (+`Sidebar`) | sain |
| 28 | `ui/GlobalSearch` | **0** | 1 (`RootLayout` + `Header`) | normal : monté dans la coquille |
| 29 | `layout/Sidebar` | **0** | 1 (`RootLayout`) | normal : coquille |
| 30 | `layout/Header` | **0** | 1 (`RootLayout`) | normal : coquille |
| 31 | `layout/UserMenu` | **0** | 1 (`Header`) | normal : coquille |
| 32 | `layout/WorkspaceSelector` | **0** | 1 (`Sidebar`) | normal : coquille |
| 33 | `layout/AutoBreadcrumbs` | **0** | 1 (`Header`) | normal : coquille |
| 34 | `OnboardingTour` | **0** | 1 (`RootLayout`) | normal : coquille |
| — | `ui/index.ts` | barillet | — | 34 exports nommés |
| **A** | **`ui/Stat`** | **0** | **0** | 🔴 **MORT** — et recopié à la main 2 fois (D27-001) |
| **B** | **`ui/ErrorBoundary`** | **0** | **0** | 🔴 **MORT** — jamais monté (D27-010) |
| **C** | **`ui/Card` → `CardFooter`** | **0** | **0** | 🔴 **MORT** (export du barillet jamais importé) |

**Composants morts : 3** (`Stat`, `ErrorBoundary`, `CardFooter`).
Aucun autre composant n'est à 0 consommateur : les six de `layout/` + `OnboardingTour` + `GlobalSearch`
sont montés une fois dans la coquille, ce qui est leur emploi normal.

### Écran → composants du DS importés

| Écran | Nb | Composants |
|---|---:|---|
| `/scraper-runs` | 20 | Button, Card, Drawer, DropdownMenu, EmptyState, IconButton, KpiCard, LiveBadge, MenuItem, Modal, PageHeader, SearchInput, Skeleton, StatusPill, TabItem, Tabs, Toolbar, Tooltip, cn, mapStatusToTone |
| `/companies/$id` | 15 | Avatar, Breadcrumbs, Button, Card, CardEyebrow, CardHeader, CardTitle, DropdownMenu, EmptyState, IconButton, PageShell, QualityBadge, SizeCategoryBadge, Spinner, cn |
| `/campaigns` | 14 | Button, Card, DropdownMenu, EmptyState, Input, KpiCard, LiveBadge, MenuItem, PageHeader, Skeleton, StatusPill, TabItem, Tabs, cn |
| `/llm/router` | 12 | Card, CardEyebrow, CardHeader, CardTitle, CompaniesTableSkeleton, EmptyState, KpiCard, PageHeader, StatusPill, TabItem, Tabs, cn |
| `/rgpd/ai-act` | 12 | Card, CardEyebrow, CardHeader, CardTitle, CompaniesTableSkeleton, Drawer, EmptyState, KpiCard, PageHeader, StatusPill, StatusTone, cn |
| `/rgpd/requests` | 11 | Button, Card, CompaniesTableSkeleton, EmptyState, Input, Modal, PageHeader, SegmentedControl, StatusPill, cn, mapStatusToTone |
| `/audit-logs` | 11 | Button, Card, CompaniesTableSkeleton, Drawer, EmptyState, PageHeader, SearchInput, StatusPill, StatusTone, Toolbar, cn |
| `/settings` | 11 | Button, Card, CardEyebrow, CardHeader, CardTitle, DarkModeToggle, Input, PageHeader, StatusPill, TabItem, Tabs |
| `/contacts` | 10 | Avatar, Card, CompaniesTableSkeleton, EmptyState, PageHeader, SearchInput, StatusPill, StatusTone, Toolbar, cn |
| `/users` | 10 | Avatar, Button, Card, CompaniesTableSkeleton, EmptyState, Input, Modal, PageHeader, StatusPill, cn |
| `/campaigns/$id` | 10 | Button, Card, KpiCard, LiveBadge, PageHeader, Spinner, StatusPill, TabItem, Tabs, cn |
| `/ (dashboard)` | 9 | Button, Card, EmptyState, KpiCard, LiveBadge, PageHeader, SegmentedControl, Skeleton, cn |
| `/companies` | 9 | Button, Card, CompaniesTableSkeleton, EmptyState, KpiCard, PageHeader, SearchInput, Toolbar, cn |
| `/audiences` | 9 | Button, Card, DropdownMenu, EmptyState, KpiCard, MenuItem, PageHeader, Skeleton, StatusPill |
| `/console/contacts` | 9 | Card, EmptyState, KpiCard, PageHeader, SearchInput, TabItem, Tabs, Toolbar, cn |
| `/llm/proxy-providers` | 8 | Button, Card, CompaniesTableSkeleton, EmptyState, PageHeader, StatusPill, StatusTone, cn |
| `/llm/rotations` | 8 | Card, CardEyebrow, CardTitle, CompaniesTableSkeleton, EmptyState, PageHeader, StatusPill, cn |
| `/tags` | 8 | Button, Card, EmptyState, FormField, KpiCard, Modal, PageHeader, Skeleton |
| `/audiences/$id` | 8 | Button, Card, KpiCard, PageHeader, Spinner, StatusPill, TabItem, Tabs |
| `/console/vivier` | 8 | Card, EmptyState, KpiCard, PageHeader, SearchInput, TabItem, Tabs, Toolbar |
| `/media` | 7 | Button, Card, EmptyState, KpiCard, PageHeader, SearchInput, cn |
| `/campaigns/new` | 7 | Button, Card, Input, PageHeader, SegmentedControl, StatusPill, cn |
| `/audiences/new` | 7 | Button, Card, Input, PageHeader, Spinner, StatusPill, cn |
| `/journalists` | 6 | Button, Card, EmptyState, KpiCard, PageHeader, SearchInput |
| `/console/arbitrage` | 5 | Button, Card, EmptyState, Input, PageHeader |
| `/console/personnes/$k` | 5 | Card, CardTitle, EmptyState, PageHeader, StatusPill |
| `/media/$id` | 4 | Card, EmptyState, Spinner, cn |
| `login` | 3 | Button, Card, Input |
| `/admin/observability` | 3 | Card, KpiCard, PageHeader |
| `magic-link` | 2 | Button, Input |
| `password-reset` | 2 | Button, Input |
| `2fa` | 1 | Button |
| `/international/roumanie` | 1 | PageHeader |
| `/cold-email` | 1 | PageShell |
| `/linkedin` | 1 | PageShell |
| 🔴 `/coverage` | **0** | **— AUCUN —** (et 4 noms du DS redéfinis localement) |
| 🔴 `404` | **0** | — AUCUN — |

---

## 2. GRILLE — écran → balisage recopié à la main

Six motifs cherchés, chacun correspondant à un composant qui **existe déjà**. Numéros de ligne dans
`06_balisage-recopie.txt`.

| Écran | Total | En-tête page | Bouton nu | Carte | Pastille | En-tête tableau | Ombre en dur |
|---|---:|---|---|---|---|---|---|
| 🔴 `/coverage` | **18** | 1 (L85) | 5 (L200,285,301,309,379) | 5 (L143,276,332,345,363) | 2 (L81,244) | 0 | 5 (L143,243,276,363) |
| `/campaigns/new` | 7 | 0 | 4 (L500,532,584,750) | 2 (L714,842) | 1 (L504) | 0 | 0 |
| `/international/roumanie` | 5 | 0 | 4 (L121,147,240,249) | 0 | 1 (L219) | 0 | 0 |
| `/audiences/new` | 4 | 0 | 1 (L439) | 1 (L469) | 2 (L444,470) | 0 | 0 |
| `/companies/$id` | 3 | **1 (L117)** | 1 (L218) | 0 | 1 (L131) | 0 | 0 |
| `/settings` | 3 | 0 | 2 (L84,288) | 1 (L247) | 0 | 0 | 0 |
| `/ (dashboard)` | 2 | 0 | 0 | 2 (L263,271) | 0 | 0 | 0 |
| `/companies` | 2 | 0 | 1 (L455) | 0 | 0 | 1 (L684) | 0 |
| `/media/$id` | 2 | 1 (L92) | 0 | 0 | 1 (L94) | 0 | 0 |
| `login` | 2 | 1 (L36) | 1 (L110) | 0 | 0 | 0 | 0 |
| `/rgpd/ai-act` | 2 | 0 | 1 (L145) | 0 | 0 | 1 (L131) | 0 |
| `/audit-logs` | 2 | 0 | 1 (L165) | 0 | 0 | 1 (L148) | 0 |
| `/audiences` | 2 | 0 | 1 (L202) | 1 (L224) | 0 | 0 | 0 |
| `/console/contacts` | 2 | 0 | 1 (L151) | 0 | 1 (L232) | 0 | 0 |
| `/console/vivier` | 2 | 0 | 0 | 0 | 2 (L141,160) | 0 | 0 |
| `/contacts` | 1 | 0 | 0 | 0 | 0 | 1 (L191) | 0 |
| `/llm/router` | 1 | 0 | 0 | 0 | 0 | 1 (L105) | 0 |
| `/llm/proxy-providers` | 1 | 0 | 0 | 0 | 0 | 1 (L76) | 0 |
| `/rgpd/requests` | 1 | 0 | 0 | 0 | 0 | 1 (L159) | 0 |
| `/users` | 1 | 0 | 0 | 0 | 0 | 1 (L116) | 0 |
| `/campaigns` | 1 | 0 | 1 (L261) | 0 | 0 | 0 | 0 |
| `/tags` | 1 | 0 | 1 (L349) | 0 | 0 | 0 | 0 |
| `404` | 1 | 1 (L7) | 0 | 0 | 0 | 0 | 0 |
| 14 écrans restants | **0** | — | — | — | — | — | — |

**Écrans qui recopient au moins un motif : 23 / 37. Occurrences : 61.**
Écrans propres (0 recopie) : `2fa`, `magic-link`, `password-reset`, `/media`, `/journalists`,
`/scraper-runs`, `/llm/rotations`, `/campaigns/$id`, `/audiences/$id`, `/admin/observability`,
`/console/arbitrage`, `/console/personnes/$k`, `/cold-email`, `/linkedin`.

| Motif | Occurrences | Écrans | Ce que le DS fournit déjà |
|---|---:|---:|---|
| Bouton écrit en `<button>` nu | 25 | 14 | `Button`, `IconButton` |
| Carte recopiée | 12 | 6 | `Card` |
| Pastille d'état recopiée | 11 | 8 | `StatusPill`, `QualityBadge` |
| En-tête de tableau recopié | 8 | 8 | **rien — il n'y a aucun composant `Table`** |
| En-tête de page recopié (`<h1>`) | 5 | 5 | `PageHeader`, `PageShell` |
| Ombre en valeur littérale | 5 | 1 | `shadow-[var(--shadow-card)]` |

---

## 3. GRILLE — mode sombre, écran par écran

Détecteur v3, témoin joué. « Jetons » = classes de couleur claire (`bg-white`, `bg-slate-50/100/200`,
`text-black`, `*-50/100/200` colorés…) **sans variante `dark:` de la même propriété dans la même
expression `className`**.

| Fichier | Occurrences | Jetons | Littéraux couleur (`#hex`, `rgb()`) |
|---|---:|---:|---:|
| 🔴 `features/coverage/CoveragePage.tsx` | 21 | **39** | 5 |
| 🔴 `features/coverage/FranceCoverageMap.tsx` | 8 | **14** | **15** |
| 🔴 `features/international/RoumaniePage.tsx` | 7 | **8** | 0 |
| 🔴 `components/ui/SizeCategoryBadge.tsx` | 6 | 6 | 0 |
| `components/layout/Sidebar.tsx` | 4 | 5 | 0 |
| 🔴 `components/ui/QualityBadge.tsx` | 3 | 3 | 0 |
| `components/OnboardingTour.tsx` | 2 | 2 | 2 |
| 🔴 `components/ui/ErrorBoundary.tsx` | 2 | 2 | 0 |
| `components/ui/EmptyState.tsx` | 1 | 2 | 0 |
| `components/ui/Tooltip.tsx` | 1 | 2 | 0 |
| `components/ui/Skeleton.tsx` | 1 | 1 | 0 |
| `features/phase2-scaffold/ColdEmailStub.tsx` | 1 | 2 | 0 |
| `features/phase2-scaffold/LinkedInStub.tsx` | 1 | 2 | 0 |
| `features/auth/LoginPage.tsx` | 1 | 1 | 0 |
| `features/campaigns/CampaignWizardPage.tsx` | 1 | 1 | 0 |
| `features/companies/CompaniesListPage.tsx` | 1 | 1 | 0 |
| `features/misc/NotFoundPage.tsx` | 1 | 1 | 0 |
| **TOTAL** | **62** | **92** | **22** |

**Écrans concernés : 9 / 37** (`/coverage`, `/international/roumanie`, `login`, `/campaigns/new`,
`/companies`, `404`, `/cold-email`, `/linkedin`, et `/companies` via `CompanyRow`).
Mais **6 des 17 fichiers touchés sont des composants du DS lui-même** — un défaut là se propage
partout où le composant est employé. `SizeCategoryBadge` et `QualityBadge` **n'ont aucune variante
sombre du tout**.

Nuance importante : quatre de ces classes (`bg-white`, `bg-slate-50`, `text-slate-900`,
`border-slate-200`, sans suffixe d'opacité) sont **rattrapées** en sombre par un filet `!important`
dans `styles/index.css:88-91`. Ce filet est lui-même un défaut — voir **D27-002**, qui est le constat
le plus grave de mon lot.

Les 5 écrans à `Sidebar`/`text-white`/`bg-sidebar-active` ne sont **pas** des défauts : la barre
latérale est colorée en clair comme en sombre, par décision documentée dans `index.css:19-24`.

---

## 4. GRILLE — cohérence : combien de façons d'écrire la même chose ?

| Objet | Formes distinctes | Détail |
|---|---:|---|
| **Bouton d'action plein** | **≥ 12** | 1 dans le DS (`Button variant="primary"`, `bg-gradient-to-b from-slate-900 to-slate-800`) + 11 chaînes écrites à la main : `…rounded-lg …from-slate-900 to-slate-800…` (CompaniesListPage:412 et :631, deux variantes), `h-11 …rounded-xl …` (DashboardPage:146), `rounded-xl bg-gradient-to-**br** from-slate-900 to-slate-**700**` (CoveragePage:303), `rounded-md bg-slate-900 px-3 py-1.5` (RoumaniePage:129), `bg-sky-500 …ring-sky-600` (CampaignWizard:539), les **mêmes classes dans un autre ordre** (AudienceBuilder:446), `bg-sky-500 text-white` seul (CampaignWizard:612), `bg-emerald-500 …ring-emerald-500` (CampaignWizard:365), `bg-rose-600` plat (ErrorBoundary:33, alors que le DS a `destructive` en dégradé), `bg-brand-600` (DarkModeToggle:46) |
| **Bouton secondaire** | **4** | DS `secondary` (`bg-white ring-1 ring-slate-200`) · `rounded-xl border border-slate-300 bg-white px-4 py-2.5` (CoveragePage:312) · `rounded-md bg-slate-100 px-3 py-1.5` (RoumaniePage:130) · `rounded-md border border-slate-300 px-3 py-1.5` (RoumaniePage:244,253) |
| **Bouton-icône** | **4** | DS `IconButton` (`rounded-lg h-9 w-9`, `aria-label` **obligatoire** par signature) · clone `rounded-md p-1 text-slate-400 hover:bg-slate-100` **×3 fichiers** · `rounded-full p-1 …` (CoveragePage:287) · `opacity-50 hover:opacity-100` sans fond (TagsManagerPage:353) |
| **Pastille d'état** | **5 dans le DS + 11 recopies** | Le DS lui-même en propose **cinq**, aux rayons et tailles **incompatibles** : `StatusPill` (`rounded-full px-2 py-0.5 text-[11px]` + point coloré, 7 tons) · `LiveBadge` (`rounded-full px-2 py-0.5 text-[11px]` + `ring-1`) · `KpiCard` chip (`rounded-full px-2 py-0.5 text-[10px]` majuscules) · `QualityBadge` (`rounded-full px-2 py-0.5 **text-xs**`) · `SizeCategoryBadge` (**`rounded-md`** px-2 py-0.5 text-xs) — un badge carré au milieu de pastilles rondes. Plus 11 recopies manuelles mélangeant `text-[10px]`, `text-[11px]`, `text-xs`, `px-2`, `px-2.5`, `py-0.5`, `py-1` |
| **Carte** | **4 variantes DS + 12 recopies** | `Card` expose `default / glass / flat / outline` × `none/sm/md/lg`. À côté : 12 `<div>` qui refont le même anneau + rayon + fond, dont **2 copies mot pour mot** de `VARIANTS.default + PADS.md` dans `DashboardPage:263,271` |
| **Champ de saisie** | **19** | `Input` existe (10 écrans). **19 chaînes de classes distinctes** contenant `rounded-lg bg-white … ring-1 ring-slate-200` sont écrites à la main, réparties sur **30 `<input>/<select>/<textarea>` bruts dans 13 écrans** |
| **Tableau** | **3 idiomes, 0 composant** | `<table>` sémantique sur **7 écrans** (`/audiences/$id`, `/campaigns/$id`, `/international/roumanie`, `/journalists`, `/media/$id`, `/media`, `/admin/observability`) · grille de `<div role="row">` sur **9 écrans** · une 3ᵉ forme dans `/llm/rotations` (`grid …bg-slate-50/60 px-5 py-2`, ni le rayon ni le fond des 8 autres). **Aucun composant `Table`, `TableHeader` ou `TableRow` n'existe dans `components/ui/`** |
| **Ombre** | **jeton + 3 littéraux divergents** | `--shadow-card`, `--shadow-card-hover`, `--shadow-popover`, `--shadow-soft` définis dans `index.css:45-48`. 12 usages du jeton (`shadow-[var(--shadow-*)]`) contre **5 valeurs littérales** qui ont **déjà divergé** — cf. D27-007 |

---

## 5. GRILLE — `spec/24_frontend_design_system.md` : la spec dit-elle vrai, le code la suit-il ?

**La spec ment d'abord sur elle-même.** `spec/00_INDEX.md:80` annonce `24_frontend_design_system.md`
à **~1500 lignes** : elle en fait **776** (`wc -l`), soit un facteur **1,93**.
`00_INDEX.md:54` annonce `13_ui_admin_phase1.md` à **~1500** : elle en fait **614**, facteur **2,44**.
Corrobore la mesure de l'agent 9 sur `00_INDEX.md`. **Je ne me suis donc servi de ces deux specs que
comme d'un catalogue d'intentions, jamais comme d'une référence de vérité.**

| Point de la spec §24 | Ce qu'elle prescrit | Ce que le code fait | Écart |
|---|---|---|---|
| Fichier de jetons | `frontend/src/styles/tokens.css` | **ce fichier n'existe pas** ; les jetons sont dans `src/styles/index.css` | 🔴 chemin faux |
| Jetons TypeScript | `frontend/src/lib/design-tokens.ts` exportant `sizeColors`, `qualityColors` | **ce fichier n'existe pas** ; les tables sont recopiées en dur dans `SizeCategoryBadge.tsx` et `QualityBadge.tsx` | 🔴 absent |
| Palette de marque | `--color-primary-50…900`, hex, `#2563eb` en 600 | `--color-brand-50…900`, **oklch**, `oklch(0.45 0.20 250)` en 600 | 🔴 nom, espace colorimétrique et valeurs différents |
| Stratégie sombre | `&[data-theme="dark"]` dans `@theme` | `@variant dark (&:where(.dark, .dark *))` — stratégie **classe** ; `data-theme` n'apparaît que dans `DarkModeToggle.tsx` | 🔴 stratégie différente |
| Catégories de taille | **6**, dont `commercant` (rose `#e11d48`) et `ge` (slate `#475569`) | **6**, mais **`commercant` n'existe pas** et il y a un `inconnue` en plus ; `grande_entreprise` est **fuchsia**, pas slate ; `artisan` est **orange**, la spec dit amber/terracotta | 🔴 référentiel divergent |
| Qualité | `complete` en `green-100/green-800` | `emerald-100/emerald-800` | 🟠 teinte différente |
| Rayons | `--radius: 0.375rem` (6 px) boutons ; `--radius-md: 0.5rem` cards ; `--radius-xl: 1rem` modales | `--radius-button: 0.5rem` (8 px) ; `--radius-card: 0.75rem` ; **et aucun des trois n'est employé** : les composants écrivent `rounded-lg`, `rounded-xl`, `rounded-2xl` en dur | 🔴 jetons définis puis ignorés |
| Ombres | `--shadow-sm/…/xl`, échelle Tailwind standard | `--shadow-soft/card/card-hover/popover`, échelle maison | 🟠 échelle différente (défendable) |
| Empty states | exigés | `EmptyState` employé par **22 écrans** | ✅ |
| Loading states | exigés | `Skeleton` + `CompaniesTableSkeleton` + `Spinner` sur 15 écrans | ✅ |
| **Error boundaries** | exigés | `ErrorBoundary` écrit… **et jamais monté** | 🔴 **D27-010** |
| Toasts `sonner` | exigés | `<Toaster/>` dans `main.tsx`, `toast()` sur 23 écrans | ✅ |
| `react-hook-form` + `zod` | exigés | `react-hook-form` employé par **2 écrans sur 37** ; les autres formulaires roulent leur propre `useState` | 🟠 partiellement suivi |
| Recherche globale ⌘K (`cmdk`) | exigée | `GlobalSearch.tsx` avec `cmdk`, montée dans `Header` | ✅ |
| Notifications 🔔 | exigées | icône `Bell` présente dans `Header.tsx` | ✅ (présence seule vérifiée) |
| Print / PDF | exigé | `@media print { .no-print }` dans `index.css:105` | ✅ |
| Onboarding 1ᵉʳ login | exigé | `OnboardingTour.tsx`, 6 cibles `data-tour`, **toutes présentes dans le DOM** | ✅ |
| Saved views | exigées | hors de mon périmètre — voir **A-002** (la seule route ment) | non vérifié ici |

---

## 6. GRILLE — jetons de style : source unique ou valeurs dispersées ?

| Famille | Source unique ? | Mesure |
|---|---|---|
| Couleurs de marque | 🟠 **oui pour `brand-*` et `sidebar-*`**, non pour le reste | `--color-brand-50…900` et `--color-sidebar-*` définis une fois dans `index.css` et employés via `bg-brand-600`, `bg-sidebar-active`. Mais **toutes les autres couleurs du produit sont des utilitaires Tailwind bruts** (`slate`, `sky`, `emerald`, `rose`, `amber`, `violet`, `indigo`, `fuchsia`, `orange`), écrits directement dans 34 fichiers |
| Couleurs sémantiques | 🔴 **non** | `--color-success/warning/danger/info` sont définis dans `index.css:31-34` et **ne sont employés nulle part** dans le code TSX (0 occurrence de `bg-success`, `text-danger`…). Chaque composant refait sa propre table : `StatusPill` (7 tons), `KpiCard` (6 tons), `QualityBadge` (3), `SizeCategoryBadge` (6), plus la table locale de `CoveragePage` (4) |
| Rayons | 🔴 **non** | `--radius-card/button/input` définis, **0 usage**. À la place : `rounded-md`, `rounded-lg`, `rounded-xl`, `rounded-2xl`, `rounded-full` écrits en dur |
| Espacements | 🔴 **non** | aucune échelle projet ; `p-1 p-3 p-4 p-5 p-6`, `py-0.5 py-1 py-1.5 py-2.5 py-3` dispersés |
| Ombres | 🟠 **jeton + doublons divergents** | 12 `shadow-[var(--shadow-*)]` contre **5 littéraux** — cf. **D27-007** |
| Couleurs littérales | 🔴 **22 occurrences** | 15 dans `FranceCoverageMap.tsx` (échelle chorophlèthe MapLibre — techniquement justifiée, mais **dupliquée** entre les `paint` de la carte L206-210 et la légende L332-336 : deux listes de 5 hex à maintenir en parallèle), 5 dans `CoveragePage`, 2 dans `OnboardingTour` (`#7c3aed`, `#0f172a` — la couleur primaire de la visite guidée **ne correspond à aucun jeton**) |

---

# CONSTATS

### [D27-001] Trois composants du design system ne sont employés nulle part, et `Stat` a été recopié à la main dans les deux écrans qui en avaient besoin
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/ui/Stat.tsx` · `ui/ErrorBoundary.tsx` · `ui/Card.tsx:50` (`CardFooter`) — copies : `frontend/src/features/audiences/AudiencesListPage.tsx:224` et `frontend/src/features/coverage/CoveragePage.tsx:343`
- Constat       : `Stat`, `ErrorBoundary` et `CardFooter` sont exportés par `components/ui/index.ts` et importés par zéro fichier du dépôt, tandis que le balisage de `Stat` figure mot pour mot dans deux écrans.
- Preuve        : `node scratchpad/usage.mjs` → `04_PREUVES/agent-27/01_usage-composants.txt` (`Stat ecrans=0 fichiersHorsComp=0 interne=[]`). Comparaison ligne à ligne :
  - `ui/Stat.tsx:17` → `rounded-xl bg-slate-50 p-3 ring-1 ring-slate-100 dark:bg-slate-800/60 dark:ring-slate-800`
  - `AudiencesListPage.tsx:224` → **la même chaîne, octet pour octet**, puis `ui/Stat.tsx:18` reproduite en `:225`, et `ui/Stat.tsx:22` reproduite en `:229` **avec `text-2xl` au lieu de `text-lg`**
  - `CoveragePage.tsx:345` → la même chaîne **amputée des deux variantes `dark:`** et de `tabular-nums`
  Le seul `Stat` que le code exécute est donc l'un des deux clones, et **les deux ont déjà divergé de l'original et l'un de l'autre**.
- Témoin négatif: le même script, sur le même corpus, rend `Card ecrans=29`, `PageHeader ecrans=27`, `Button ecrans=23` — il sait compter les composants employés. Contrôle joué en plus : `07_temoin-negatif-recopie.txt`, où un fichier planté déclarant `function KpiCard()` est détecté comme redéfinissant un nom du DS et un fichier sain rend 0.
- Impact        : règle 8 (« on étend, on ne réinvente pas ») enfreinte. Une correction portée sur `ui/Stat.tsx` — contraste, `tabular-nums`, mode sombre — n'atteindra **aucun** des deux endroits où le composant est réellement affiché. C'est exactement le **piège 15** : la constante dupliquée ne signale jamais qu'elle a divergé. `CardFooter` est du code mort sec.
- Reproduction  : `grep -rn "\bStat\b" frontend/src --include=*.tsx | grep -v "components/ui/Stat.tsx"` → 4 lignes, dont 3 dans `CoveragePage` (déclaration locale + 2 appels) et 1 dans le barillet.
- Correctif     : remplacer les deux copies par `<Stat …/>` (ajouter une prop `size` pour le `text-2xl` d'`AudiencesListPage`) ; monter `ErrorBoundary` (cf. D27-010) ; supprimer `CardFooter` ou l'employer. **Coût : ~1 h.**
- Statut        : ouvert

---

### [D27-002] Quatre règles `!important` de la feuille de style globale neutralisent 174 déclarations `dark:` écrites dans les composants
- Sévérité      : S1 grave
- Domaine       : interface
- Référence     : main e8924b8
- Emplacement   : `frontend/src/styles/index.css:88-91`
- Constat       : les règles `.dark .bg-white`, `.dark .bg-slate-50`, `.dark .text-slate-900` et `.dark .border-slate-200`, toutes en `!important`, l'emportent sur les variantes `dark:` que les composants déclarent, qui ne s'appliquent donc jamais.
- Preuve        : mesure en navigateur réel sur `https://app.localhost` (`04_PREUVES/agent-27/05_navigateur-important-vs-dark.txt`). Sonde `bg-white dark:bg-slate-900 text-slate-900 dark:text-white border border-slate-200 dark:border-slate-800`, classe `.dark` posée sur `<html>`, couleurs résolues en sRGB via un canvas 1×1 :

  | | mesuré en sombre | ce que la variante `dark:` du composant demande | ce que le `!important` impose |
  |---|---|---|---|
  | fond | `19,22,26` | `15,23,43` (slate-900) | **`19,22,26`** |
  | texte | `238,238,238` | `255,255,255` (white) | **`238,238,238`** |
  | bordure | `42,46,51` | `29,41,61` (slate-800) | **`42,46,51`** |

  Décompte des déclarations concernées (`node scratchpad/override.mjs` → `04_override-important.txt`) :
  `bg-white` 42 éléments dont **38** portent un `dark:bg-*` explicite · `bg-slate-50` 26 dont **23** ·
  `text-slate-900` 98 dont **91** · `border-slate-200` 24 dont **22**. **Total : 174 déclarations `dark:` mortes.**
- Témoin négatif: joué dans la même session (`05_…txt`, section TÉMOIN NÉGATIF). Sur `bg-slate-100 dark:bg-slate-800` — une utilitaire **absente** des 4 règles — la mesure rend `29,41,61`, c'est-à-dire exactement slate-800 : **la variante `dark:` gagne**. Idem pour `bg-white/80 dark:bg-slate-900/60`, que le sélecteur `.dark .bg-white` ne matche pas (classe `.bg-white\/80`). Le dispositif sait donc distinguer les deux issues ; il n'est pas biaisé vers « le `!important` gagne ».
- Impact        : (a) le thème sombre réel du produit n'est **pas** celui que les composants décrivent : il est défini par 4 lignes de CSS global que personne ne relit ; (b) toute correction de contraste faite dans un composant sur ces 4 propriétés est **silencieusement sans effet** — le développeur voit son code, pas son résultat ; (c) le filet masque les manques : un nouvel écran qui oublie ses `dark:` « marche quand même » et le défaut ne se révélera que le jour où on retirera le filet ; (d) `!important` sur un sélecteur aussi générique bloque toute correction ponctuelle par `className`.
- Reproduction  : ouvrir `https://app.localhost`, activer le mode sombre, exécuter le script de `05_navigateur-important-vs-dark.txt` dans la console.
- Correctif     : supprimer `index.css:88-91` **puis** compléter les `dark:` manquants là où le filet les couvrait (cf. D27-006). Retirer les 4 lignes sans le travail préalable **casserait visiblement** les écrans à `bg-white` nu. Ordre : (1) inventorier les `bg-white`/`bg-slate-50`/`text-slate-900`/`border-slate-200` **sans** `dark:` frère — 4+3+7+2 = **16 éléments** d'après `04_override-important.txt` ; (2) les compléter ; (3) supprimer les 4 règles. **Coût : ~3 h.**
- Statut        : ouvert

---

### [D27-003] `/coverage` n'importe aucun composant du design system et redéfinit localement quatre de ses noms
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/coverage/CoveragePage.tsx` — `SegOption:176`, `SegmentedControl:178`, `KpiCard:228`, `Stat:343`
- Constat       : sur les 37 écrans, `/coverage` est le seul (avec le `404`) à n'importer aucun composant de `@/components/ui`, et il redéclare sous les mêmes noms quatre symboles que le barillet exporte.
- Preuve        : `01_usage-composants.txt` → `/coverage (0) — AUCUN —`. `06_balisage-recopie.txt` → `/coverage TOTAL=18`, ligne `>>> REDEFINIT LOCALEMENT DES NOMS DU DS : SegOption@L176, SegmentedControl@L178, KpiCard@L228, Stat@L343`.
  Divergences mesurées entre la copie locale et l'original :

  | | `ui/SegmentedControl.tsx` | copie `CoveragePage.tsx:178` |
  |---|---|---|
  | accessibilité | `role="tablist"`, `role="tab"`, `aria-selected={active}` | **aucun des trois** |
  | mode sombre | 4 groupes de variantes `dark:` | **aucune** |
  | anneau au repos | `ring-slate-200/60` | `ring-slate-200` (plus dense) |
  | API | `size`, `className`, `icon` | absentes |

  | | `ui/KpiCard.tsx` | copie `CoveragePage.tsx:228` |
  |---|---|---|
  | tons | 6 (`sky violet emerald amber rose slate`) | 4 |
  | mode sombre | `dark:` sur chaque ton | **aucune** |
  | ombre | `shadow-[var(--shadow-card)]` | valeur littérale **amputée** (cf. D27-007) |
  | API | `icon`, `trend`, `className` | absentes |

  L'en-tête de page est lui aussi recopié : `CoveragePage.tsx:84` porte `mb-6 flex flex-wrap items-end justify-between gap-4`, **la chaîne exacte** de `ui/PageHeader.tsx:18` ; et `CoveragePage.tsx:81` reproduit le `LiveBadge` de `ui/PageHeader.tsx:45` avec un `animate-pulse` au lieu de la classe projet `axion-pulse-dot`.
- Témoin négatif: le même détecteur rend `TOTAL=0` sur 14 écrans, dont `/scraper-runs` qui importe 20 composants — il ne signale pas tout le monde. Et le contrôle planté (`07_temoin-negatif-recopie.txt`) prouve qu'il relève bien un `function KpiCard()` local et rend 0 sur un écran qui emploie le DS.
- Impact        : le contrôle segmenté de la carte France n'est pas annoncé aux lecteurs d'écran (ni `tablist`, ni `aria-selected`) alors que le composant du DS l'est ; l'écran entier est illisible en mode sombre (39 jetons clairs sans `dark:`, le plus gros total du dépôt) ; toute évolution du DS ignore cet écran.
- Reproduction  : `grep -c "@/components/ui" frontend/src/features/coverage/CoveragePage.tsx` → `0`.
- Correctif     : remplacer les 4 déclarations locales par les imports du barillet et adapter les appels (`tone` accepte déjà les 4 tons employés ; `Stat` accepte `label`/`value`). Le `SegmentedControl` du DS est un sur-ensemble strict du local : le remplacement est mécanique. **Coût : ~3 h**, dont la moitié pour le mode sombre de la carte elle-même.
- Statut        : ouvert

---

### [D27-004] 23 écrans sur 37 recopient à la main du balisage qu'un composant du système fournit déjà
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/` — tableau complet au §2 de ce rapport, 61 emplacements avec numéros de ligne dans `04_PREUVES/agent-27/06_balisage-recopie.txt`
- Constat       : 61 occurrences de balisage réécrit à la main sont réparties sur 23 écrans, alors que `Button`, `IconButton`, `Card`, `StatusPill` et `PageHeader` existent et sont employés ailleurs dans le même dépôt.
- Preuve        : `node scratchpad/recopie.mjs` → `06_balisage-recopie.txt`. Les copies les plus littérales :
  - `features/dashboard/DashboardPage.tsx:263` et `:271` → `rounded-2xl bg-white p-5 ring-1 ring-slate-200/70 shadow-[var(--shadow-card)] dark:bg-slate-900 dark:ring-slate-800` = **exactement** `Card.tsx:11` (`VARIANTS.default`) + `Card.tsx:17` (`PADS.md = p-5`) + `Card.tsx:23` (`rounded-2xl`). `<Card><Skeleton/></Card>` faisait le même rendu.
  - `features/companies/CompanyDetailPage.tsx:117` → `text-2xl font-semibold tracking-tight md:text-3xl bg-gradient-to-br from-slate-900 to-slate-600 bg-clip-text text-transparent dark:from-white dark:to-slate-300` = **la concaténation exacte** de `PageHeader.tsx:27` et `:29`. Et cet écran **importe déjà `PageShell`** (lignes 81 et 90) : il emploie le composant pour ses états de chargement et le recopie pour son état nominal.
  - `features/companies/CompaniesListPage.tsx:631` → `inline-flex h-9 items-center justify-center rounded-lg bg-gradient-to-b from-slate-900 to-slate-800 px-4 text-sm font-medium text-white` = `Button.tsx:19` (`VARIANTS.primary`) + `Button.tsx:39` (`SIZES.md`), **sans** le `focus-visible:ring-2`, **sans** le `active:scale-[0.98]`, **sans** les variantes `dark:` — la copie a perdu l'anneau de focus au clavier.
  - le clone d'`IconButton` `rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800` apparaît **à l'identique dans 3 fichiers** (`AudiencesListPage:205`, `CampaignsListPage:264`, `SettingsPage:88`).
- Témoin négatif: `07_temoin-negatif-recopie.txt` — un écran planté portant `<h1>`, une carte recopiée, un `<button>` nu, une pastille recopiée et une ombre littérale est relevé sur les 5 motifs ; un écran témoin employant `PageHeader`, `Card`, `Button` et `StatusPill` rend `TOTAL=0`. Et sur le corpus réel, 14 écrans rendent 0.
- Impact        : les copies **perdent systématiquement quelque chose** que le composant portait — l'anneau de focus clavier (`CompaniesListPage:631`), les rôles ARIA (`CoveragePage:178`), le mode sombre, le `active:scale`. Le rendu diverge d'un écran à l'autre sur un produit qui se vend sur sa cohérence. Toute retouche du DS n'atteint qu'une fraction des écrans.
- Reproduction  : `node scratchpad/recopie.mjs` depuis la racine du dépôt.
- Correctif     : reprise écran par écran, en commençant par les 6 plus chargés (`/coverage` 18, `/campaigns/new` 7, `/international/roumanie` 5, `/audiences/new` 4, `/companies/$id` 3, `/settings` 3) qui concentrent **40 des 61 occurrences**. **Coût : ~2 j** pour ces six, ~4 j pour les 23.
- Statut        : ouvert

---

### [D27-005] Aucun composant de tableau n'existe : trois idiomes coexistent sur 16 écrans et le même en-tête de 210 caractères est copié à l'identique dans 8 fichiers
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/ui/` (absence) — copies : `CompaniesListPage.tsx:684`, `ContactsListPage.tsx:191`, `LlmRouterPage.tsx:105`, `ProxyProvidersPage.tsx:76`, `AiActRegisterPage.tsx:131`, `AuditLogsPage.tsx:148`, `RgpdRequestsPage.tsx:159`, `UsersPage.tsx:116`
- Constat       : `components/ui/` ne contient ni `Table`, ni `TableHeader`, ni `TableRow`, et les 16 écrans qui affichent une liste tabulaire se répartissent en trois manières incompatibles de la construire.
- Preuve        :
  - `ls frontend/src/components/ui/ | grep -iE "tabl|grid|row"` → **vide**.
  - `<table>` sémantique : 7 écrans (`/audiences/$id`, `/campaigns/$id`, `/international/roumanie`, `/journalists`, `/media/$id`, `/media`, `/admin/observability`).
  - grille de `<div role="row">` : 9 écrans (`/companies`, `/contacts`, `/llm/router`, `/llm/proxy-providers`, `/llm/rotations`, `/rgpd/ai-act`, `/audit-logs`, `/rgpd/requests`, `/users`).
  - `grep -c "sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80"` → **8 fichiers**, chaîne strictement identique.
  - une 3ᵉ forme dans `/llm/rotations:87` : `grid items-center gap-3 bg-slate-50/60 px-5 py-2 …` — ni le même fond (`/60` vs `/80`), ni le même rembourrage (`px-5 py-2` vs `px-4 py-3`), ni le `sticky`, ni la bordure.
- Témoin négatif: le même `grep` sur `bg-slate-50/60` ne rend qu'**un** fichier — le contrôle sait distinguer les deux chaînes et n'agrège pas tout ce qui ressemble à un en-tête.
- Impact        : les tableaux du produit ne se comportent pas pareil selon l'écran — 7 écrans donnent la sémantique de tableau aux lecteurs d'écran, 9 ne la donnent pas (le `role="row"` sans `role="table"`/`role="grid"` parent n'est pas suffisant partout : à vérifier écran par écran, hors de mon périmètre). L'en-tête de `/llm/rotations` ne colle plus au coup d'œil, et la 9ᵉ divergence est déjà là. **Une confusion de navigation qui fait perdre l'utilisateur est au minimum S2.**
- Reproduction  : les trois `grep` ci-dessus.
- Correctif     : extraire un composant `DataTable` (ou au minimum `TableHeaderRow` + `TableRow`) et convertir les 9 grilles. Trancher d'abord si l'idiome cible est `<table>` ou la grille CSS — les deux ont des raisons (colonnes fixes vs tri/virtualisation). **Coût : ~2 j** pour le composant + les 9 conversions ; les 7 `<table>` peuvent rester en l'état si l'idiome retenu est `<table>`.
- Statut        : ouvert

---

### [D27-006] 92 couleurs claires sans variante sombre, dont 11 dans deux composants du système qui n'ont aucun mode sombre
- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : main e8924b8
- Emplacement   : 17 fichiers, tableau complet au §3 — `components/ui/SizeCategoryBadge.tsx:4-9`, `components/ui/QualityBadge.tsx:4-6`, `features/coverage/CoveragePage.tsx` (39 jetons), `features/coverage/FranceCoverageMap.tsx` (14), `features/international/RoumaniePage.tsx` (8)
- Constat       : 92 classes de couleur claire n'ont pas de variante `dark:` de la même propriété dans la même expression `className`, et 22 lignes portent une couleur en valeur littérale.
- Preuve        : `node scratchpad/dark3.mjs` → `04_PREUVES/agent-27/02_mode-sombre-v3.txt`, totaux : `62 occurrences · 92 jetons · 22 littéraux · 17 fichiers`. Les deux badges du DS sont intégralement clairs :
  `SizeCategoryBadge.tsx:4` → `{ bg: 'bg-orange-100', fg: 'text-orange-800' }` — six entrées, **zéro `dark:`** ;
  `QualityBadge.tsx:4` → `{ bg: 'bg-emerald-100', fg: 'text-emerald-800' }` — trois entrées, **zéro `dark:`**.
  Ces deux composants ne sont pas rattrapés par le filet `!important` de D27-002 : `bg-orange-100` et `bg-emerald-100` ne figurent pas dans les 4 règles.
- Témoin négatif: `03_temoin-negatif-detecteur.txt` — sur une copie du dossier `components/` augmentée de deux fichiers plantés, le détecteur relève `PlanteBad.tsx` (`bg-white p-4 text-black`, 2 jetons) et **ne relève pas** `PlanteGood.tsx` (`bg-white … dark:bg-slate-900 dark:text-white`). Il ne signale par ailleurs ni `Card.tsx`, ni `Modal.tsx`, ni `StatusPill.tsx`, ni `PageHeader.tsx`, ni les 8 en-têtes de tableau du D27-005 — tous correctement appairés. C'est précisément la correction de mes v1 et v2, conservées dans les preuves.
- Impact        : en mode sombre, les badges de qualité et de catégorie de taille restent des pastilles pastel sur fond noir — illisibles, et présents dans les listes d'entreprises, l'écran le plus consulté. `/coverage` et `/international/roumanie` sont entièrement clairs. `FranceCoverageMap` maintient **deux listes parallèles des mêmes 5 hex** (`L206-210` pour le rendu MapLibre, `L332-336` pour la légende) : rien ne signalera leur divergence.
- Reproduction  : `node scratchpad/dark3.mjs`, ou visuellement : basculer le mode sombre sur `/companies` et regarder la colonne « qualité ».
- Correctif     : ajouter les `dark:` manquantes dans `SizeCategoryBadge` et `QualityBadge` (**~20 min, forte valeur**), puis `/coverage` + `FranceCoverageMap` + `/international/roumanie` (~1 j). Fusionner les deux listes de hex de la carte en une seule constante. **Coût total : ~1,5 j.**
- Statut        : ouvert

---

### [D27-007] Le jeton d'ombre est dupliqué en valeur littérale et a déjà divergé
- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : main e8924b8
- Emplacement   : `frontend/src/styles/index.css:45-48` (définition) — copies : `features/coverage/CoveragePage.tsx:143,243,276,363` et `features/coverage/FranceCoverageMap.tsx:326`
- Constat       : `--shadow-card` et `--shadow-card-hover` sont des ombres à deux couches, et les cinq copies littérales n'en reproduisent qu'une.
- Preuve        :
  - `index.css:46` → `--shadow-card: 0 4px 24px -8px rgb(0 0 0 / 0.06)**, 0 1px 2px 0 rgb(0 0 0 / 0.04)**;`
  - `CoveragePage.tsx:243,276,363` → `shadow-[0_4px_24px_-8px_rgb(0_0_0/0.06)]` — **la seconde couche a disparu**.
  - `index.css:47` → `--shadow-card-hover: 0 8px 32px -8px rgb(0 0 0 / 0.10)**, 0 2px 4px 0 rgb(0 0 0 / 0.06)**;`
  - `CoveragePage.tsx:243` → `hover:shadow-[0_8px_32px_-8px_rgb(0_0_0/0.10)]` — **idem**.
  - `CoveragePage.tsx:143` → `shadow-[0_8px_32px_-12px_rgb(0_0_0/0.12)]` et `FranceCoverageMap.tsx:326` → `shadow-[0_8px_32px_-8px_rgb(0_0_0/0.12)]` : **deux valeurs différentes** (`-12px` vs `-8px`) pour deux panneaux flottants voisins, et aucune des deux ne correspond à `--shadow-popover`.
  - décompte : `grep -rc "shadow-\[var(--shadow-" --include=*.tsx` → **12 usages du jeton** ; `grep -rn "shadow-\[0_"` → **5 valeurs littérales**.
- Témoin négatif: le même `grep` trouve bien les 12 usages du jeton — il n'est pas aveugle à la forme correcte. Et sur les 34 composants du DS, **aucun** ne porte de littéral : ils emploient tous `shadow-[var(--shadow-*)]`, ce qui montre que la convention existe et est respectée là où elle a été appliquée.
- Impact        : **piège 15** en situation. Ajuster `--shadow-card` ne touchera pas les 3 cartes de `/coverage`, et rien ne le dira. La divergence est **déjà survenue** : la seconde couche est perdue, et les deux panneaux flottants s'écartent l'un de l'autre.
- Reproduction  : `grep -rn "shadow-\[0_" frontend/src --include=*.tsx`
- Correctif     : remplacer les 5 littéraux par `shadow-[var(--shadow-card)]` / `shadow-[var(--shadow-card-hover)]` / `shadow-[var(--shadow-popover)]`. **Coût : 20 min.** Une règle ESLint `no-restricted-syntax` interdisant `shadow-[0_` empêcherait la récidive (~1 h).
- Statut        : ouvert

---

### [D27-008] `Input` et `FormField` existent, et 30 champs de saisie sont écrits à la main en 19 variantes de classes
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/ui/Input.tsx` (10 écrans) et `ui/FormField.tsx` (**1 écran**) — champs bruts dans 13 écrans, dont `CompaniesListPage.tsx` (7), `TagsManagerPage.tsx` (3), `ContactsListPage.tsx` (3), `CampaignWizardPage.tsx` (3), `AudienceBuilderPage.tsx` (3)
- Constat       : 30 balises `<input>`, `<select>` ou `<textarea>` sont écrites directement dans les écrans, avec 19 chaînes de classes distinctes qui reproduisent toutes le même champ.
- Preuve        : `grep -rc "<input\|<select\|<textarea" frontend/src/features --include=*.tsx` → 30 sur 13 fichiers.
  `grep -rhoE '"[^"]*rounded-lg bg-white[^"]*ring-1 ring-slate-200[^"]*"' --include=*.tsx . | sort -u | wc -l` → **19 chaînes distinctes**, qui varient sur la hauteur (`h-8`, `h-9`, sans hauteur), la largeur (`w-full`, `w-24`, `w-28`, `w-44`, `w-56`), la police (`text-sm`, `text-xs`, `font-mono`), l'ordre des classes de focus, et la présence ou non de `placeholder:text-slate-400`.
  `grep -rl "FormField" frontend/src/features --include=*.tsx | wc -l` → **1**.
- Témoin négatif: le même `grep` compte bien 10 écrans qui importent `Input` — il ne conclut pas à l'abandon du composant. Et le contrôle a11y joué en parallèle (script Python sur les 30 champs) montre que **28 sur 30** portent au moins un `placeholder`, `aria-label` ou `<label>` : le problème mesuré ici est la **duplication**, pas l'accessibilité, et je ne le grossis pas.
- Impact        : les champs du produit n'ont pas la même hauteur ni le même anneau de focus d'un écran à l'autre. `FormField` — qui porte l'appariement `label`/`id`, `aria-describedby`, le message d'erreur et le texte d'aide — n'est employé que par `/tags` : les 36 autres écrans n'en bénéficient pas. Les 2 champs sans étiquette relevés (`CampaignWizardPage.tsx:431` et `:721`) en sont la conséquence directe.
- Reproduction  : les trois commandes ci-dessus.
- Correctif     : convertir les 30 champs vers `Input` (et `FormField` là où il y a une étiquette). Le composant accepte déjà `className`, les variantes de largeur passent sans modification. **Coût : ~1,5 j.**
- Statut        : ouvert

---

### [D27-009] Douze formes distinctes du bouton d'action plein, quatre du bouton-icône
- Sévérité      : S3 finition
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `components/ui/Button.tsx:17-41` (la référence) — variantes concurrentes listées au §4
- Constat       : le bouton d'action plein s'écrit d'au moins douze façons dans le dépôt, dont une seule passe par le composant.
- Preuve        : `grep -rhoE "'[^']*text-white[^']*'|\"[^\"]*text-white[^\"]*\"" --include=*.tsx . | grep -E "bg-|from-" | sort -u` (sortie complète en session). Écarts les plus parlants :
  - trois dégradés différents pour la même intention : `from-slate-900 to-slate-800` (DS + 3 copies), `from-slate-900 to-slate-**700**` en `to-**br**` (`CoveragePage:303`), `bg-slate-900` plat (`RoumaniePage:129`) ;
  - trois rayons : `rounded-lg` (DS), `rounded-xl` (`CoveragePage:303`, `DashboardPage:146`), `rounded-md` (`RoumaniePage:129`) ;
  - `bg-sky-500 text-white shadow-sm ring-1 ring-sky-600` (`CampaignWizardPage:539`) et `bg-sky-500 text-white ring-1 ring-sky-600 shadow-sm` (`AudienceBuilderPage:446`) — **les mêmes classes dans un autre ordre**, deux chaînes à maintenir pour un rendu identique ;
  - `ErrorBoundary:33` emploie `bg-rose-600` plat là où le DS a `destructive` en dégradé `from-rose-600 to-rose-700`.
  Bouton-icône : `IconButton` (`rounded-lg h-9 w-9`, `aria-label` **imposé par la signature**) contre le clone `rounded-md p-1` répété dans 3 fichiers, `rounded-full p-1` (`CoveragePage:287`) et une 4ᵉ forme sans fond (`TagsManagerPage:353`).
- Témoin négatif: le même relevé montre que `Button` **est** employé par 23 écrans sur 37 — la forme canonique domine, ce qui exclut que le décompte agrège du bruit. Et les 4 chaînes de la barre latérale (`bg-sidebar-active`, `hover:bg-white/10`) sont exclues du décompte : elles relèvent d'un jeton de navigation documenté (`index.css:19-24`), pas d'une divergence.
- Impact        : finition. Les trois clones qui recopient les classes du DS ont perdu `focus-visible:ring-2` : ces boutons n'ont **plus d'anneau de focus au clavier**, ce qui touche la navigation sans souris.
- Reproduction  : la commande `grep` ci-dessus.
- Correctif     : convertir les 11 variantes vers `Button` en ajoutant au composant les deux variantes manquantes (`accent` bleu, `success` vert) plutôt que de les laisser vivre en dehors. **Coût : ~1 j.**
- Statut        : ouvert

---

### [D27-010] `ErrorBoundary` n'est monté nulle part, et la garde end-to-end qui prétend le surveiller mesure un objet absent
- Sévérité      : S2 défaut
- Domaine       : interface / tests
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/ui/ErrorBoundary.tsx` · `frontend/src/main.tsx` · `frontend/src/app/RootLayout.tsx` · `frontend/tests/e2e/console-locale.spec.ts:378-381`
- Constat       : aucun fichier du dépôt n'importe `ErrorBoundary`, et un test end-to-end vérifie l'absence du texte que ce composant est le seul à pouvoir produire.
- Preuve        :
  - `grep -rn "ErrorBoundary" frontend/src/main.tsx frontend/src/app frontend/src/features` → **aucune sortie**. La seule mention hors du fichier lui-même est `components/ui/index.ts:33` (le barillet) et le test e2e.
  - `console-locale.spec.ts:378-381` :
    ```
    await expect(body, "L'ErrorBoundary a capturé une exception de rendu.").not.toContainText(
      'Une erreur est survenue.',
    );
    ```
    La chaîne `'Une erreur est survenue.'` n'existe **que** dans `ErrorBoundary.tsx:28`. Le composant n'étant jamais monté, l'assertion est **vraie par construction**, sur les 37 écrans, quoi qu'il arrive.
- Témoin négatif: le contrôle est capable de trouver un montage quand il y en a un — le même `grep` sur `OnboardingTour`, `GlobalSearch`, `Sidebar` et `Header` rend bien leur import dans `app/RootLayout.tsx`. L'absence relevée n'est donc pas un artefact de la commande.
- Impact        : (a) une exception de rendu dans n'importe quel écran démonte l'arbre React entier — l'utilisateur voit une page blanche, pas le message de repli ; la spec §24 exige explicitement des error boundaries. (b) **Piège 19** en situation : la garde est irréprochable dans sa formulation et mesure le mauvais objet ; elle est verte depuis toujours et le restera. Elle est le type même de contrôle qui donne l'illusion de la couverture. Atténuation honnête : l'assertion voisine `expect(page.locator('#main')).toBeVisible()` (ligne 372) **attraperait** l'écran blanc consécutif à un démontage — le test n'est pas entièrement aveugle, mais l'assertion `ErrorBoundary` n'y contribue en rien.
- Reproduction  : `grep -rn "ErrorBoundary" frontend/src --include=*.tsx --include=*.ts | grep -v "components/ui/"` → une seule ligne, le barillet.
- Correctif     : monter `<ErrorBoundary level="root">` autour du `<RouterProvider>` dans `main.tsx` et `<ErrorBoundary level="page">` dans `RootLayout` autour de l'`<Outlet/>`. Compléter au passage le mode sombre du repli (D27-006) et corriger `ErrorBoundary.tsx:27`, où `p-${level === 'root' ? 8 : 4}` construit une classe Tailwind par interpolation — un motif que le scanner JIT ne voit pas. **Coût : ~1 h** ; le test e2e devient alors une vraie garde.
- Statut        : ouvert

---

### [D27-011] `spec/24_frontend_design_system.md` prescrit deux fichiers qui n'existent pas, et son référentiel de couleurs ne correspond pas au code
- Sévérité      : S3 finition
- Domaine       : conformité / interface
- Référence     : main e8924b8
- Emplacement   : `spec/24_frontend_design_system.md:22`, `:47-52`, `:88-96`, `:104-120` — code : `frontend/src/styles/index.css`, `frontend/src/components/ui/SizeCategoryBadge.tsx`
- Constat       : la spec désigne `frontend/src/styles/tokens.css` et `frontend/src/lib/design-tokens.ts`, aucun des deux n'existe, et le référentiel de catégories de taille qu'elle définit diffère de celui que le code implémente.
- Preuve        : `test -f frontend/src/styles/tokens.css` → **ABSENT** ; `test -f frontend/src/lib/design-tokens.ts` → **ABSENT** (`ls frontend/src/lib/` → `api.ts echo.ts i18n.ts prospection-referentiels.ts sentry.ts`).
  Référentiel : `spec/24:47-52` déclare six catégories dont `--color-size-commercant: #e11d48` et `--color-size-ge: #475569`. `SizeCategoryBadge.tsx:1` déclare `'artisan' | 'tpe' | 'pme' | 'eti' | 'grande_entreprise' | 'inconnue'` : **`commercant` n'existe pas**, `inconnue` est en plus, et `grande_entreprise` est rendu en **fuchsia** là où la spec dit slate. `artisan` est rendu en **orange** là où le mapping TypeScript de la spec dit `text-amber-700 bg-amber-50`.
  Palette : la spec définit `--color-primary-50…900` en hex ; le code définit `--color-brand-50…900` en oklch. Aucun nom en commun.
  Stratégie sombre : la spec écrit `&[data-theme="dark"]` ; `index.css:4` écrit `@variant dark (&:where(.dark, .dark *))` — stratégie **classe**.
  Rayons : `--radius-card/button/input` sont définis dans `index.css:40-42` et employés **zéro fois** (`grep -rc "rounded-card\|rounded-button\|rounded-input" --include=*.tsx` → 0).
- Témoin négatif: la même méthode confirme les points **conformes** — `sonner` (`main.tsx:5`), `cmdk` (`GlobalSearch.tsx:2`), `@media print { .no-print }` (`index.css:105`), les 6 ancres `data-tour` de l'onboarding toutes présentes dans le DOM. La grille du §5 n'est donc pas un réquisitoire : elle sépare ce qui tient de ce qui ne tient pas.
- Impact        : quiconque suit la spec cherchera deux fichiers introuvables et posera une palette qui n'existe pas. La divergence du référentiel de tailles est la plus coûteuse : si l'API renvoie un jour `commercant`, `SizeCategoryBadge` retombe sur `inconnue` sans le dire.
- Reproduction  : les commandes ci-dessus.
- Correctif     : mettre la spec au niveau du code (elle décrit une intention de 2025, le code a tranché autrement et mieux — oklch, stratégie classe). **Ne pas** faire l'inverse. Trancher séparément la question `commercant` : c'est un écart de **référentiel métier**, pas de style, et il dépasse mon périmètre. **Coût : ~2 h** pour la spec.
- Statut        : ouvert

---

### [D27-012] `spec/00_INDEX.md` annonce des tailles fausses pour les deux specs de mon périmètre
- Sévérité      : S3 finition
- Domaine       : conformité
- Référence     : main e8924b8
- Emplacement   : `spec/00_INDEX.md:54` et `:80`
- Constat       : l'index annonce `~1500` lignes pour `13_ui_admin_phase1.md` et `~1500` pour `24_frontend_design_system.md` ; les fichiers en font 614 et 776.
- Preuve        : `wc -l spec/24_frontend_design_system.md spec/13_ui_admin_phase1.md spec/00_INDEX.md` → `776 / 614 / 289`. Facteurs : **2,44** et **1,93**.
- Témoin négatif: `wc -l` rend bien 289 pour `00_INDEX.md` lui-même — l'outil compte juste ; l'écart est dans le document.
- Impact        : mineur en soi, mais c'est **la deuxième confirmation indépendante** de ce que l'agent 9 a mesuré. Le facteur 2,44 mesuré ici est exactement l'ordre de grandeur qu'il annonçait. Conséquence de méthode : **aucune affirmation quantitative de `spec/` ne doit être reprise sans re-mesure**, et c'est la règle que j'ai appliquée au §5.
- Reproduction  : `wc -l spec/00_INDEX.md spec/13_ui_admin_phase1.md spec/24_frontend_design_system.md`
- Correctif     : régénérer la colonne « lignes » de `00_INDEX.md` par script. **Coût : 30 min**, à faire une fois pour les 25 entrées, pas seulement les deux miennes.
- Statut        : ouvert

---

### [D27-013] `PageShell` n'est employé que par trois écrans, dont deux stubs morts, et le troisième recopie l'en-tête au lieu de s'en servir
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/ui/PageShell.tsx` — consommateurs : `features/phase2-scaffold/ColdEmailStub.tsx:7`, `features/phase2-scaffold/LinkedInStub.tsx:7`, `features/companies/CompanyDetailPage.tsx:81,90`
- Constat       : le composant que son propre commentaire dit « conservé pour compatibilité avec les 18 pages existantes » n'est importé que par trois écrans, dont les deux stubs `/cold-email` et `/linkedin`.
- Preuve        : `01_usage-composants.txt` → `PageShell ecrans=3 fichiersHorsComp=3`.
  `PageShell.tsx:12` affirme : *« kept for backwards compatibility with existing 18 pages »*. **Mesure : 3, pas 18.** Le commentaire est faux d'un facteur 6.
  Les deux stubs sont les écrans que le constat **A-005** signale déjà comme joignables par URL sans être au menu — les seuls consommateurs « purs » de `PageShell` sont donc deux écrans factices.
  Le troisième, `CompanyDetailPage`, l'emploie **uniquement** pour ses états de chargement (`:81`) et d'erreur (`:90`), puis recopie mot pour mot le `<h1>` de `PageHeader` pour son état nominal (`:117`, cf. D27-004).
- Témoin négatif: le même relevé rend `PageHeader ecrans=27` — les 27 écrans qui n'emploient pas `PageShell` emploient bien son remplaçant. `PageShell` n'est donc **pas** un composant qu'on aurait oublié d'adopter : c'est un composant que la migration vers `PageHeader` a vidé de sa substance sans aller au bout.
- Impact        : mineur en rendu (`PageShell` délègue à `PageHeader`), réel en lisibilité : un développeur qui lit le commentaire croit toucher 18 pages en le modifiant, il en touche 3. Le fait que `CompanyDetailPage` importe le composant **et** recopie ce qu'il produit est le signe que la migration a été interrompue en cours.
- Reproduction  : `grep -rn "PageShell" frontend/src --include=*.tsx | grep -v components/ui`
- Correctif     : convertir `CompanyDetailPage` (retirer le `<h1>` recopié de `:117`, passer par `PageHeader` avec `breadcrumbs` et `badge`), puis les deux stubs, puis supprimer `PageShell` et son commentaire faux. **Coût : ~2 h.** Si les stubs doivent disparaître (cf. A-005), le coût tombe à ~1 h.
- Statut        : ouvert

---

# CE QUE JE N'AI PAS PU VÉRIFIER, ET POURQUOI

1. **Le rendu visuel réel des 37 écrans.** Je n'ai ouvert aucun écran pour de vrai : l'API locale est
   sérialisée (A-009) et le mandat me demandait un travail statique. **Conséquence honnête : mes
   constats de recopie et de mode sombre sont établis sur le code, pas sur ce que l'utilisateur voit.**
   La seule mesure de rendu que j'ai faite est celle de D27-002, et elle porte sur une sonde que j'ai
   injectée, pas sur un écran du produit. Un contrôle visuel des 9 écrans à défaut de mode sombre
   reste à faire.
2. **Le contraste effectif (WCAG AA/AAA).** Je compte les couleurs sans variante sombre ; je n'ai
   mesuré **aucun ratio de contraste**, ni en clair ni en sombre. `index.css:24` affirme viser « AA au
   repos, AAA sur l'actif » pour la barre latérale : **cette affirmation n'est pas vérifiée**, ni par
   moi ni, à ma connaissance, par personne. C'est un audit à part entière.
3. **La sémantique des tableaux pour les lecteurs d'écran.** J'ai constaté que 9 écrans emploient
   `role="row"` ; je n'ai pas vérifié écran par écran la présence d'un parent `role="table"`/`role="grid"`
   ni des `role="columnheader"`. Sans cela, `role="row"` seul est inopérant. À mesurer.
4. **Le responsive (320 → 2560 px) exigé par la spec §24.** Hors du temps que j'avais. Aucun chiffre.
5. **Les 22 littéraux de couleur de `FranceCoverageMap`** : je les compte et je constate la duplication
   entre le `paint` MapLibre et la légende, mais je n'ai **pas** vérifié que les deux listes sont
   aujourd'hui encore identiques valeur par valeur — elles le paraissent, je ne l'affirme pas.
6. **Les « saved views »** de la spec §24 : hors périmètre, et le constat **A-002** montre que la seule
   route côté API ment. Je n'ai rien mesuré de plus.
7. **Le coût réel de mes correctifs.** Les estimations sont des ordres de grandeur, pas des mesures.
8. **`spec/13_ui_admin_phase1.md`** : je l'ai ouvert pour en mesurer la taille (D27-012) mais je n'ai
   **pas** confronté ses wireframes écran par écran au code. Seule `spec/24` a été passée en revue.
9. **La stabilité de la référence.** J'ai relu `git log` au début et à la fin de mon travail : `HEAD`
   valait `e8924b8` dans les deux cas. Mais le dossier commun signale qu'une session de construction
   pousse sur `main` pendant l'audit (A-008) : **si une PR atterrit après ma dernière lecture, mes
   numéros de ligne se décalent.** Ils sont à re-vérifier avant tout correctif.
