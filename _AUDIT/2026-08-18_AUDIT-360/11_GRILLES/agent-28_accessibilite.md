# AGENT 28 — Auditeur d'accessibilité

- **Référence mesurée** : `main = 8db8229` au début de ma session, `d95de24` en cours de route,
  **`4ca52c9` à la fin** — une autre session pousse sur `main` pendant l'audit, comme le dossier commun
  l'annonce ; j'ai donc relu `git log` trois fois plutôt qu'une. **Les trois fois**,
  `git diff --stat e8924b8..HEAD -- frontend/ .github/workflows/a11y.yml` rend **vide** : les commits
  intermédiaires ne touchent que `_AUDIT/`. Le code et la porte que j'ai mesurés sont donc **exactement**
  ceux de `e8924b8`, la référence du dossier commun, celle sur laquelle l'agent 27 a travaillé.
  Preuve : `04_PREUVES/agent-28/00_reference.txt`. Corroboration inattendue : `frontend/dist/`, construit
  par un autre agent à 14:22, contient `index-C8i6k4WZ.js` **au même nom de hachage et à l'octet près**
  que mon propre build de 14:32 — deux constructions indépendantes de la même source.
- **Périmètre** : les **37 écrans** de `frontend/src/features/` (liste tirée de `src/app/routeTree.tsx`),
  les **34 composants** de `frontend/src/components/`, et `.github/workflows/a11y.yml`.
- **Preuves brutes** : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-28/` (16 relevés + 13 scripts).

---

## 0. Méthode, et ce que je n'ai PAS pu atteindre

**La console n'est pas utilisable** (A-012, A07-001) et, pendant ma session, l'API locale ne répondait
même plus : `https://api.localhost/up` → **502**, `docker logs axion-crm-api` bloqué au-delà de 120 s
(A-009/A-010 : `php -S`, un seul processus, plusieurs agents dessus). Je n'ai donc **pas** mesuré
l'interface authentifiée.

Ce que j'ai fait à la place, et pourquoi c'est mieux qu'une capture d'écran :

1. **J'ai reconstruit le frontend** (`pnpm exec vite build`, 2 212 modules, sortie hors du dépôt) et je
   l'ai servi sur `http://127.0.0.1:4188` par un serveur statique de mon cru avec repli SPA. **C'est
   exactement la cible que la porte `a11y.yml` mesure** (`vite preview` du build, sans API) — mes
   nombres sont donc directement comparables aux siens.
2. **J'ai visité les 37 écrans**, en clair **et** en sombre, avec `axe-core` 4.x injecté dans la page.
3. **J'ai ensuite dépassé cet état** là où c'était possible sans API réelle : j'ai **servi des données**
   à `/companies` (5 fiches au *shape* du code, `page.route` + en-têtes CORS), **ouvert** la recherche
   globale, la modale et le tiroir, **ouvert** le menu utilisateur, et **avancé** l'assistant de
   campagne d'une étape. C'est là que se trouvent les défauts que la porte ne voit jamais.
4. **Chaque sonde a son témoin.** La sonde d'indicateur de focus, la sonde de libellé et la sonde de
   débordement ont été validées sur des cas plantés avant d'être crues (§7).

**Ce que je ne peux pas dire** est listé au §9. Ce n'est pas un aveu, c'est une partie du résultat.

---

## 1. GRILLE — les 37 écrans

Colonnes : `axe clair` / `axe sombre` = nombre de **nœuds** en violation (WCAG 2.0/2.1/2.2 A+AA), dans
l'état **coquille sans API**. `focus.` = éléments atteignables au clavier effectivement rendus.
`sans ind.` = éléments focalisés **sans** indicateur visible. `bout. main` = `<button>` écrits à la main
sans l'anneau `focus-visible:ring` du système. `row/table` = `role="row"` / `role="table"|"grid"`.
`champs` = `<input>/<select>/<textarea>` bruts dans le fichier de l'écran.

| # | Écran | Fichier | axe clair | axe sombre | focus. | sans ind. | bout. main | row/table | champs | mode sombre appliqué |
|--:|---|---|--:|--:|--:|--:|--:|:--|--:|:--|
| 1 | `login` | `features/auth/LoginPage.tsx` | 1 | 1 | 7 | **0** | 0 | 0/0 | 1 | 🔴 **non** |
| 2 | `/2fa` | `features/auth/TwoFactorPage.tsx` | 0 | 0 | 2 | **0** | 0 | 0/0 | 1 | 🔴 **non** |
| 3 | `magic-link` | `features/auth/MagicLinkPage.tsx` | 0 | 0 | 2 | **0** | 0 | 0/0 | 0 | 🔴 **non** |
| 4 | `password-reset` | `features/auth/PasswordResetPage.tsx` | 0 | 0 | 2 | **0** | 0 | 0/0 | 0 | 🔴 **non** |
| 5 | `/` (tableau de bord) | `features/dashboard/DashboardPage.tsx` | 2 | 4 | 21 | **0** | 0 | 0/0 | 0 | oui |
| 6 | `/companies` | `features/companies/CompaniesListPage.tsx` | 1 → **30 peuplé** | 3 → **22 peuplé** | 45 | **0** | 1 | **1/0** | 7 | oui |
| 7 | `/companies/$id` | `features/companies/CompanyDetailPage.tsx` | 1 | 3 | 22 | **0** | 0 | 0/0 | 0 | oui |
| 8 | `/contacts` | `features/contacts/ContactsListPage.tsx` | 1 | 3 | 25 | **0** | 0 | **2/0** | 3 | oui |
| 9 | `/international/roumanie` | `features/international/RoumaniePage.tsx` | 1 | 3 | 34 | **0** | 4 | 0/0 | 0 | oui |
| 10 | `/media` | `features/media/MediaListPage.tsx` | 1 | 4 | 30 | **0** | 0 | 0/0 | 2 | oui |
| 11 | `/media/$id` | `features/media/MediaDetailPage.tsx` | 1 | *(délai dépassé)* | 21 | **0** | 0 | 0/0 | 0 | oui |
| 12 | `/journalists` | `features/media/JournalistsListPage.tsx` | 1 | 4 | 23 | **0** | 0 | 0/0 | 0 | oui |
| 13 | `/coverage` | `features/coverage/CoveragePage.tsx` | 1 | **7** | 28 | **0** | 5 | 0/0 | 0 | oui |
| 14 | `/scraper-runs` | `features/scraping/ScraperRunsPage.tsx` | 2 | 3 | 28 | **0** | 0 | 0/0 | 0 | oui |
| 15 | `/llm/router` | `features/llm/LlmRouterPage.tsx` | 1 | 3 | 28 | **0** | 0 | **2/0** | 0 | oui |
| 16 | `/llm/proxy-providers` | `features/llm/ProxyProvidersPage.tsx` | 1 | 3 | 24 | **0** | 0 | **2/0** | 0 | oui |
| 17 | `/llm/rotations` | `features/llm/RotationsPage.tsx` | 1 | 3 | 24 | **0** | 0 | **2/0** | 0 | oui |
| 18 | `/rgpd/requests` | `features/rgpd/RgpdRequestsPage.tsx` | 1 | 3 | 28 | **0** | 0 | **2/0** | 2 | oui |
| 19 | `/rgpd/ai-act` | `features/rgpd/AiActRegisterPage.tsx` | 1 | 3 | 21 | **0** | 1 | **2/0** | 0 | oui |
| 20 | `/audit-logs` | `features/rgpd/AuditLogsPage.tsx` | 1 | 3 | 23 | **0** | 1 | **2/0** | 0 | oui |
| 21 | `/users` | `features/users/UsersPage.tsx` | 1 | 3 | 24 | **0** | 0 | **2/0** | 1 | oui |
| 22 | `/settings` | `features/settings/SettingsPage.tsx` | 1 | 4 | 27 | **0** | 2 | 0/0 | 0 | oui |
| 23 | `/campaigns` | `features/campaigns/CampaignsListPage.tsx` | 2 | 3 | 29 | **0** | 0 | 0/0 | 0 | oui |
| 24 | `/campaigns/new` | `features/campaigns/CampaignWizardPage.tsx` | 1 → **103 étape 2** | 3 | 28 | **0** | 4 | 0/0 | 3 | oui |
| 25 | `/campaigns/$id` | `features/campaigns/CampaignDetailPage.tsx` | 1 | 3 | 22 | **0** | 0 | 0/0 | 0 | oui |
| 26 | `/tags` | `features/tags/TagsManagerPage.tsx` | 1 | 3 | 24 | **0** | 0 | 0/0 | 3 | oui |
| 27 | `/audiences` | `features/audiences/AudiencesListPage.tsx` | 1 | 3 | 20 | **0** | 0 | 0/0 | 0 | oui |
| 28 | `/audiences/new` | `features/audiences/AudienceBuilderPage.tsx` | **53** | 5 | 68 | **0** | 1 | 0/0 | 3 | oui |
| 29 | `/audiences/$id` | `features/audiences/AudienceDetailPage.tsx` | 1 | 3 | 19 | **0** | 0 | 0/0 | 0 | oui |
| 30 | `/admin/observability` | `features/observability/ObservabilityPage.tsx` | 1 | 3 | 19 | **0** | 0 | 0/0 | 0 | oui |
| 31 | `/console/contacts` | `features/crm-console/ContactsHubPage.tsx` | 1 | 4 | 18 | **0** | 0 | 0/0 | 0 | oui |
| 32 | `/console/vivier` | `features/crm-console/CandidatesPage.tsx` | 1 | 4 | 18 | **0** | 0 | 0/0 | 0 | oui |
| 33 | `/console/arbitrage` | `features/crm-console/ArbitragePage.tsx` | 1 | 4 | 18 | **0** | 0 | 0/0 | 0 | oui |
| 34 | `/console/personnes/$k` | `features/crm-console/PersonTimelinePage.tsx` | 1 | 4 | 18 | **0** | 0 | 0/0 | 0 | oui |
| 35 | `/cold-email` | `features/phase2-scaffold/ColdEmailStub.tsx` | 1 | 3 | 18 | **0** | 0 | 0/0 | 0 | oui |
| 36 | `/linkedin` | `features/phase2-scaffold/LinkedInStub.tsx` | 1 | 3 | 18 | **0** | 0 | 0/0 | 0 | oui |
| 37 | `404` | `features/misc/NotFoundPage.tsx` | 0 | 0 | **0** | 0 | 0 | 0/0 | 0 | 🔴 **non** |

**Totaux, état coquille sans API** — *le seul état que la porte du dépôt mesure* :

| | critique | sérieux | modéré | mineur | **total** |
|---|--:|--:|--:|--:|--:|
| **mode clair** | **0** | **88** | 0 | 0 | **88** |
| **mode sombre** | **0** | **108** | 0 | 0 | **108** |

Deux règles seulement, mais partout :

| règle | impact | nœuds clair | écrans | nœuds sombre | écrans |
|---|---|--:|--:|--:|--:|
| `target-size` (WCAG 2.2 AA, 2.5.8) | sérieux | 33 | **33 / 37** | 32 | **32 / 37** |
| `color-contrast` (WCAG AA, 1.4.3) | sérieux | 55 | 4 / 37 | **76** | **31 / 37** |

**Totaux, états atteints en donnant des données ou en interagissant** — *ce que la porte ne voit jamais* :

| état | critique | sérieux | total | détail |
|---|--:|--:|--:|---|
| `/companies` avec 5 fiches, clair | **14** | 16 | **30** | `aria-allowed-attr` ×6, `aria-required-children` ×6, `aria-required-parent` ×2, `color-contrast` ×10, `nested-interactive` ×5, `target-size` ×1 |
| `/companies` avec 5 fiches, sombre | **14** | 8 | **22** | mêmes 14 critiques |
| `/campaigns/new` étape 2 | 0 | **103** | **103** | `color-contrast` ×102 (liste des départements), `target-size` ×1 |
| modale de recherche ouverte (mobile) | 0 | 1 | 1 | `target-size` ×1 |
| menu utilisateur ouvert | 0 | 2 | 2 | `target-size` ×2 |

Preuves : `02_axe-37-ecrans-clair.*`, `03_axe-37-ecrans-sombre.*`, `04_tableau-axe-par-ecran.txt`,
`10_companies-peuple-14-critiques.txt`, `16_assistant-campagne-etape-2.txt`.

---

## 2. GRILLE — les 34 composants

| # | Composant | Nom accessible | Rôles ARIA | Clavier | Focus visible | Mode sombre | Verdict a11y |
|--:|---|---|---|---|---|---|---|
| 1 | `ui/Button` | contenu | — | natif | `focus-visible:ring-2` + `ring-offset-1`, par variante | complet | ✅ |
| 2 | `ui/IconButton` | **`label` imposé par la signature** → `aria-label` + `title` | — | natif | `focus-visible:ring-2` | complet | ✅ **le meilleur du lot** |
| 3 | `ui/Input` | **aucun** — à la charge de l'appelant | — | natif | `focus:ring-2` | partiel | 🟠 aucune prop `label`/`aria-label` |
| 4 | `ui/FormField` | `<label htmlFor>` + `aria-describedby` + `aria-invalid` + `role="alert"` | corrects | natif | `focus-within:ring-2` | complet | ✅ mais **employé par 1 écran** (D27-008) |
| 5 | `ui/Toolbar` → `SearchInput` | 🔴 **aucun** : ni `<label>`, ni `aria-label`, ni prop pour en poser un — **seulement un `placeholder`** | — | natif | `focus:ring-2` | complet | 🔴 **D28-007**, 8 écrans |
| 6 | `ui/Toolbar` → `Toolbar` | — | aucun (`<div>`) | — | — | complet | 🟠 pas de `role="toolbar"` |
| 7 | `ui/Modal` | 🔴 `role="dialog"` **sans** `aria-label`/`aria-labelledby` alors qu'un `<h2>` existe | `aria-modal="true"` **mensonger** | 🔴 focus **non déplacé**, **non piégé**, **non restitué** ; Échap ✅ | hérité | 🔴 **D28-003** |
| 8 | `ui/Modal` → `Drawer` | idem | idem | idem | hérité | 🔴 **D28-003** |
| 9 | `ui/DropdownMenu` | 🔴 déclencheur **sans nom propre** quand on lui passe un `<button>` | `aria-haspopup`/`aria-expanded` ✅, `role="menu"`/`menuitem` ✅ | 🔴 flèches inertes, focus non déplacé, **focus perdu sur `<body>` à la fermeture** | pas d'anneau sur le déclencheur | 🔴 **D28-004 / D28-008** |
| 10 | `ui/Tabs` | contenu | `role="tablist"`/`tab`/`aria-selected` ✅ | 🔴 **ni flèches, ni `tabindex` mobile, ni `aria-controls`, ni `tabpanel`** | pas d'anneau explicite (repli navigateur) | complet | 🟠 **D28-008** |
| 11 | `ui/SegmentedControl` | contenu | idem `Tabs` | idem `Tabs` | idem | complet | 🟠 **D28-008** |
| 12 | `ui/Tooltip` | `aria-describedby` + `role="tooltip"` | corrects | ouvre sur `focus` ✅, **pas d'Échap** (WCAG 1.4.13) | — | pas de variante sombre (fond `slate-900` fixe) | 🟠 |
| 13 | `ui/StatusPill` | texte + point `aria-hidden` ✅ | — | — | — | 7 tons, **tous avec `dark:`** | ✅ contrastes 5,09 → 12,33 (§4) |
| 14 | `ui/QualityBadge` | texte + émoji `aria-hidden` ✅ | — | — | — | 🔴 **aucune variante `dark:`** | 🟠 lisible mais **inchangé** en sombre (§4) |
| 15 | `ui/SizeCategoryBadge` | texte | — | — | — | 🔴 **aucune variante `dark:`** | 🟠 idem |
| 16 | `ui/Card` (+`CardHeader/Title/Eyebrow`) | — | aucun | — | — | complet | ✅ |
| 17 | `ui/Card` → `CardFooter` | — | — | — | — | complet | ⚪ mort (D27-001) |
| 18 | `ui/PageHeader` | `<h1>` ✅ | — | — | — | complet | ✅ |
| 19 | `ui/PageShell` | — | — | — | — | complet | ✅ |
| 20 | `ui/EmptyState` | `<h2>` + icône `aria-hidden` ✅ | — | — | — | 🟠 1 jeton clair sans `dark:` | ✅ |
| 21 | `ui/Skeleton` | 🔴 rien | — | — | — | 🟠 `bg-slate-200` seul | 🟠 **D28-014** |
| 22 | `ui/Skeleton` → `CompaniesTableSkeleton` | `aria-busy="true"` | — | — | — | idem | 🟠 pas d'`aria-live` : la fin du chargement n'est pas annoncée |
| 23 | `ui/Spinner` | `aria-label="Chargement"` sur un `<svg>` **sans `role="img"`** | — | — | — | — | 🟠 nom fragile |
| 24 | `ui/Avatar` | — | — | — | — | complet | ✅ |
| 25 | `ui/Breadcrumbs` | — | à vérifier (`<nav>` ?) | — | — | complet | ⚪ non vérifié dynamiquement |
| 26 | `ui/DarkModeToggle` | 🔴 `aria-label="Theme light/system/dark"` — **en anglais**, dans une interface française ; `aria-pressed` ✅ | corrects | natif | pas d'anneau explicite (repli navigateur) | complet | 🟠 **et 22,9 × 24 px : `target-size` sur 32 écrans** |
| 27 | `ui/GlobalSearch` | `aria-label="Recherche globale"` ✅ sur le dialogue et sur le déclencheur | `role="dialog" aria-modal="true"` | 🔴 **6 Tab sur 6 sortent du dialogue** ; focus non restitué | `outline-none` sur `Command.Input` (cmdk pose son propre style) | 🔴 `<kbd>` à **1,36:1** en sombre | 🔴 **D28-003 / D28-011** |
| 28 | `ui/ErrorBoundary` | — | — | — | — | 🟠 2 jetons clairs sans `dark:` | 🔴 **jamais monté** (D27-010) → **D28-014** |
| 29 | `ui/Stat` | — | — | — | — | complet | ⚪ mort (D27-001) |
| 30 | `layout/Sidebar` | `<nav>` ✅ | — | natif | repli navigateur | jetons dédiés, documentés | 🟠 **6 `<h3>` avant le `<h1>`** → **D28-012** ; 6 boutons à 23 px de haut |
| 31 | `layout/Header` | `role="banner"` ✅ | — | natif | — | jetons dédiés | ✅ |
| 32 | `layout/UserMenu` | `aria-label` sur un `<span>` → déclencheur **correctement nommé** | — | voir `DropdownMenu` | — | complet | ✅ (c'est **le bon** usage de `DropdownMenu`) |
| 33 | `layout/WorkspaceSelector` | idem | — | idem | — | complet | ✅ |
| 34 | `layout/AutoBreadcrumbs` | — | — | — | — | complet | ⚪ non vérifié |
| 35 | `OnboardingTour` (`react-joyride`) | délégué à la bibliothèque | — | — | — | 🟠 2 littéraux couleur | ⚪ non vérifié (nécessite un 1ᵉʳ login) |
| — | `ui/cn` | utilitaire | — | — | — | — | — |

**Ce que la coquille fait bien, et qu'il faut dire** : `RootLayout` pose un **lien d'évitement**
(`<a href="#main">`), un `<main id="main">`, un `<nav>` et un `role="banner"` ; `<html lang="fr">` est
correct sur les 37 écrans ; `IconButton` **impose** `aria-label` par sa signature TypeScript, ce qui est
la bonne façon de rendre un défaut impossible ; `FormField` fait tout ce qu'il faut. Le squelette est
bon. Ce sont les **écrans qui ne s'en servent pas** qui posent problème — c'est exactement D27-004,
vu depuis l'accessibilité.

---

## 3. `a11y.yml` — ce que la porte fait RÉELLEMENT

### 3.1 Elle tourne, elle passe, et elle a déjà produit un résultat

`gh run list --workflow=a11y.yml --limit 25` : **25 exécutions**, la plupart `success`.
Journal du dernier passage sur `main` (`run 32241133030`, `14_journal-ci-a11y-run-32241133030.txt`) :

```
Running 4 tests using 2 workers
····
  4 passed (10.3s)
...
Running 14 tests using 2 workers
  14 passed (12.6s)
```

Donc, **contrairement à ce que dit `H44-002` de mon mandat** : ce job **exécute** bien Playwright, il
**produit** un résultat, et les 14 tests de `navigation.spec.ts` y tournent. Le commentaire du workflow
(«  les 4 pages échouaient AVANT le premier contrôle axe ») décrit un défaut **corrigé** ; l'ajout de
`E2E_PREVIEW` + `pnpm build` a réellement redonné une cible. **Je ne re-rapporte donc pas « la porte ne
mesure rien ».** Ce qui reste vrai de `H44-002`, c'est que le job **n'est pas une vérification requise**
sur la branche : il rougirait sans bloquer la fusion. *(Point de branche non revérifié par moi — voir §9.)*

### 3.2 Mais elle est aveugle de trois façons, et je les ai mesurées

**① Elle n'assert que sur `impact === 'critical'`.** `tests/e2e/a11y.spec.ts:26-28` :

```js
const critical = results.violations.filter((v) => v.impact === 'critical');
expect(critical).toEqual([]);
```

Rejoué à l'identique (ses 4 URL, ses 3 tags `wcag2a/wcag2aa/wcag22aa`, son filtre) —
`11_porte-a11y-rejouee-et-libelles.txt` :

| URL de la porte | violations réelles | ce que la porte retient | verdict |
|---|---|---|---|
| `/login` | `target-size[serious]×1` | aucune | **PASSE** |
| `/companies` | `target-size[serious]×1` | aucune | **PASSE** |
| `/coverage` | `target-size[serious]×1` | aucune | **PASSE** |
| `/rgpd/requests` | `target-size[serious]×1` | aucune | **PASSE** |

Sur l'ensemble du produit, **88 nœuds en clair et 108 en sombre sont `serious`, et 0 est `critical`** :
le filtre écarte **100 %** de ce qu'axe trouve dans l'état mesuré. La porte est verte parce qu'elle
regarde une case vide, pas parce que le produit est propre.

**② Elle ne visite que 4 écrans sur 37**, et deux d'entre eux (`/coverage`, `/rgpd/requests`) sont
justement ceux que l'agent 27 a désignés comme les plus abîmés.

**③ Surtout : elle mesure le produit VIDE.** Sur le runner GitHub il n'y a aucune API ; toutes les
listes rendent leur état vide. J'ai servi 5 fiches à `/companies` — **son propre écran n° 2** — et la
même porte, avec ses propres tags et son propre filtre, **échoue** :

```
/companies  violations=6 regles / 30 noeuds | {"critical":14,"serious":16,...}
   regles: aria-allowed-attr[critical]x6, aria-required-children[critical]x6,
           aria-required-parent[critical]x2, color-contrast[serious]x10,
           nested-interactive[serious]x5, target-size[serious]x1
   >>> LE TEST ECHOUE — critical retenus : aria-allowed-attr, aria-required-children, aria-required-parent
```

**C'est le piège 19 dans sa forme la plus pure** : la garde est correctement écrite, son assertion est
la bonne, son filtre attraperait le seul défaut critique du produit — et elle ne le rencontrera jamais,
parce qu'elle mesure une version de l'écran qui n'existe pour aucun utilisateur.

### 3.3 Le job `lighthouse` du même fichier

```yaml
  - run: lhci autorun --upload.target=temporary-public-storage --collect.url=https://staging.axion-crm-pro.com
    continue-on-error: true
```

`continue-on-error: true` **et** aucune assertion : le job ne peut, par construction, ni rougir ni
produire de seuil. **F38-006 est confirmé** — je ne le re-rapporte pas, je le corrobore.

### 3.4 Le « BLOQUANT » de `navigation.spec.ts`

L'étape porte le mot **BLOQUANT** dans son nom. Elle bloque **le job** (elle n'a pas
`continue-on-error`), donc le job rougit si elle rougit. Ce que le mot ne dit pas, c'est que **le job
lui-même ne bloque pas la fusion** s'il n'est pas déclaré requis. Le mot est vrai au niveau du job et
trompeur au niveau de la PR.

---

## 4. Contrastes — mesurés sur le rendu réel, clair ET sombre

**Méthode.** Je n'ai lu **aucune classe Tailwind**. J'injecte les composants dans la page réelle de
l'application (feuille de style complète, **y compris les 4 règles `!important` de `index.css:88-91`**
relevées par D27-002), je lis les couleurs **calculées**, je les résous en sRGB par un **canvas 1×1**
(la feuille est en `oklch()`, qu'aucune expression régulière ne convertit), et je remonte la chaîne des
ancêtres pour le fond effectif. Preuve : `08_contrastes-rendu-reel-clair-et-sombre.txt`.

### 4.1 Les badges du système

| composant | clair | sombre | remarque |
|---|--:|--:|---|
| `QualityBadge` complète | 6,70 | **6,70** | **fond et texte identiques** en sombre |
| `QualityBadge` partielle | 6,36 | **6,36** | idem |
| `QualityBadge` basique | 6,59 | **6,59** | idem |
| `SizeCategoryBadge` artisan | 6,43 | **6,43** | idem |
| `SizeCategoryBadge` TPE | 6,53 | **6,53** | idem |
| `SizeCategoryBadge` PME | 8,16 | **8,16** | idem |
| `SizeCategoryBadge` ETI | 7,73 | **7,73** | idem |
| `SizeCategoryBadge` grande | 7,18 | **7,18** | idem |
| `SizeCategoryBadge` inconnue | 6,92 | **6,92** | idem |

🔴 **Correction que je dois à D27-006.** L'agent 27 écrit que ces badges seront « illisibles » en mode
sombre. **Mesuré : ils sont parfaitement lisibles — 6,4 à 8,2:1.** Ils ne sont pas rattrapés par le
filet `!important` (ni `bg-emerald-100` ni `bg-orange-100` n'y figurent), donc ils gardent **exactement**
leur rendu clair : un **îlot pastel sur une page presque noire**. C'est un défaut de **cohérence
visuelle et d'éblouissement**, pas un défaut de contraste. Le correctif reste le même et le coût aussi
(~20 min) ; c'est la justification qui change, et elle doit être juste.

### 4.2 Le reste

| sonde | clair | sombre | verdict |
|---|--:|--:|---|
| `StatusPill` × 7 tons | 4,85 → 9,45 | 5,56 → 12,33 | ✅ **conforme partout, dans les deux modes** |
| `Card` + texte secondaire (`text-slate-500`) | 4,76 | 6,90 | ✅ (limite en clair) |
| **Espace réservé de champ (`placeholder:text-slate-400`)** | **2,63** | 6,90 | 🔴 **ÉCHEC AA en clair** — et c'est le **seul** libellé de `SearchInput` (§5) |
| **`<kbd>⌘K</kbd>` de la recherche globale** | — | **1,36** | 🔴 **ÉCHEC**, présent dans l'en-tête de **tous** les écrans de la coquille |
| Déclencheur de recherche globale (`text-slate-500`) | — | **3,80** | 🔴 **ÉCHEC AA**, idem |
| `EmptyState` description en sombre | — | **2,39** | 🔴 **ÉCHEC AA** |
| `/coverage` légende (`text-slate-600`) | — | **2,51** | 🔴 **ÉCHEC AA** |
| `/companies` peuplé, `text-slate-400` sur blanc | **2,63** ×10 | — | 🔴 **ÉCHEC AA**, 2 par ligne de liste |
| `/campaigns/new` étape 2 | **102 nœuds** | — | 🔴 liste des départements |
| **Lien d'évitement (`.skip-link:focus`)** | **1,19** | 14,22 | 🔴 **ÉCHEC AA en clair** — voir D28-005 |
| *témoin* `text-slate-900` sur `bg-white` | 17,83 | 15,64 | témoin haut |
| *témoin* `text-slate-300` sur `bg-white` | **1,49 (échec)** | 12,21 | témoin bas — **et il bascule** : preuve que le `!important` de D27-002 est bien actif ici |

**Le témoin bas est instructif** : `bg-white text-slate-300` échoue en clair (1,49) et **passe** en
sombre (12,21), parce que `.dark .bg-white { background: oklch(0.20 …) !important }` a repeint le fond.
J'ai donc **corroboré D27-002 sur un autre site que celui de l'agent 27**, avec une autre méthode.

**Total contrastes mesurés en échec AA, sur le rendu réel** : **55 nœuds en clair sur 4 écrans**,
**76 nœuds en sombre sur 31 écrans**, plus **10** apparus dès que `/companies` a des lignes et **102**
à l'étape 2 de l'assistant de campagne. **Le mode sombre est le plus mauvais des deux.**

---

## 5. Verdict clavier

### 5.1 Atteignabilité — ✅ bonne

Parcours réel : `Tab` répété depuis `<body>` sur les 37 écrans, jusqu'à 80 fois
(`05_clavier-37-ecrans.*`). **Aucun piège, aucun élément inatteignable** parmi ceux rendus.
5 `onClick` sur des éléments non interactifs (`components/ui/GlobalSearch.tsx:61,68`,
`components/ui/Modal.tsx:44,107`, `features/scraping/ScraperRunsPage.tsx:612`) — les quatre premiers
sont des **voiles de fermeture**, doublés par Échap : ce n'est **pas** un défaut. Le cinquième
(`ScraperRunsPage.tsx:612`) est une **ligne cliquable non focalisable** — 1 défaut réel, hors de portée
de ma mesure faute de données.

🔴 **Une exception dure : le `404` a 0 élément focalisable.** `NotFoundPage.tsx` contient bien un
`<Link to="/">`, mais sur `/route-qui-nexiste-pas` **rien n'est rendu de ce lien** : 19 éléments dans
le DOM, aucun focalisable, aucun `<main>`, aucun `<nav>`. Un utilisateur au clavier arrivé là n'a
**aucun moyen de repartir** sans la barre d'adresse ou le retour arrière.

### 5.2 Ordre de tabulation — ✅ conforme

L'ordre suit le DOM, qui suit la lecture : **lien d'évitement → barre latérale → en-tête → contenu**.
Ma sonde compte une « inversion » par écran de la coquille : c'est le passage **bas de la barre
latérale → haut de l'en-tête**, remontée verticale attendue d'une mise en page à deux colonnes, pas un
défaut. `/coverage` en a 2 et `/audiences/new` 4, pour la même raison (colonnes multiples).
**0 `tabindex` positif** dans tout le dépôt.

### 5.3 Focus visible — ✅ et je dois corriger D27-004 sur ce point

**0 élément sans indicateur de focus visible, sur les 37 écrans.** La sonde a été **validée par témoin**
avant usage (`06_temoin-negatif-sonde-focus.txt`) : elle rend `AUCUN` sur un bouton à `outline:none` non
remplacé, `box-shadow` sur un bouton à anneau custom, `outline` sur un bouton nu.

🔴 **Donc l'impact écrit dans D27-004 — « les copies perdent l'anneau de focus clavier » — est vrai sur
le code et faux sur le rendu.** Les 30 `<button>` écrits à la main sans `focus-visible:ring` (dont
`CompaniesListPage:631`, cité par l'agent 27) **n'écrivent pas `outline-none`** : ils gardent donc
l'anneau `:focus-visible` par défaut du navigateur. Le défaut réel n'est pas *« pas d'anneau »*, c'est
*« un autre anneau »* : anneau système fin sur 30 boutons contre `ring-2 ring-slate-900 ring-offset-1`
sur les autres. C'est une **incohérence visuelle (S3)**, pas une **perte d'accès (S1)**. Je ne rouvre
pas D27-004 : je corrige la phrase d'impact, mesure à l'appui.

### 5.4 Pièges de focus — 🔴 c'est là que ça casse

Trois dispositifs déclarent `aria-modal="true"`. **Aucun des trois ne tient la promesse.**
(`07_interactions-modale-menu-onglets-skiplink.txt`)

| dispositif | focus déplacé à l'ouverture | focus piégé | arrière-plan neutralisé | Échap | focus restitué |
|---|---|---|---|---|---|
| `GlobalSearch` (⌘K) | ✅ (`autoFocus` de cmdk) | 🔴 **6 Tab / 6 sortent** | 🔴 non (`inert`=false, `aria-hidden`=null) | ✅ | 🔴 non — atterrit sur « Importer » |
| `Drawer` (barre latérale mobile) | 🔴 **non** (reste sur « Ouvrir le menu ») | 🔴 **10 Tab / 10 sortent** | 🔴 non | ✅ | 🔴 non — atterrit sur « Lancer scraping → » |
| `Modal` (recherche mobile) | 🔴 **non** | 🔴 **8 Tab / 8 sortent** | 🔴 non | ✅ | 🔴 non |

Et la `Modal` **n'a même pas de nom** : `aria-label = null`, `aria-labelledby = null`, alors qu'elle
contient un `<h2>Recherche</h2>` qu'il suffirait de référencer.

`DropdownMenu` n'est pas une modale mais annonce `role="menu"` : **la flèche bas ne fait rien**, le
focus reste sur le déclencheur, et **à la fermeture par Échap le focus part sur `<body>`** — l'endroit
où l'utilisateur était est perdu.

`Tabs` / `SegmentedControl` annoncent `role="tablist"` + `role="tab"` + `aria-selected` : mesuré sur
`/settings`, **4 onglets, 0 `tabpanel`, 0 `aria-controls`, 0 `tabindex`, la flèche droite ne déplace pas
le focus**. Le rôle promet un widget que le clavier ne fournit pas.

### 5.5 `/coverage` — D27-003 confirmé à l'exécution

| | `role=tablist` | `role=tab` | `aria-selected` |
|---|--:|--:|--:|
| `/coverage` (`SegmentedControl` **local**) | **0** | **0** | **0** |
| `/` (`SegmentedControl` **du système**) — témoin | 1 | 3 | 3 |

La copie locale n'annonce **rien** ; l'original annonce tout. Mesuré dans le navigateur, pas déduit du
code.

---

## 6. Confrontation au CDC §23.5

> « Navigation clavier complète, contrastes, tailles système, libellés explicites, mode sombre. »
> (`axion-ia-crm-cahier-des-charges-fonctionnel-v2.md:810`)

| exigence | verdict | mesure |
|---|---|---|
| **Navigation clavier complète** | 🟠 **partiel** | Atteignabilité ✅ (37/37), ordre ✅, focus visible ✅ (0 sans indicateur). Mais **3 dispositifs modaux sans piège de focus**, `role=menu`/`role=tab` **sans clavier ARIA**, `404` à **0 élément focalisable**. |
| **Contrastes** | 🔴 **non tenu** | **55 nœuds en échec AA en clair**, **76 en sombre sur 31 écrans**, +10 dès que `/companies` a des lignes, +102 à l'étape 2 de l'assistant. Le **lien d'évitement lui-même** est à **1,19:1** en clair. |
| **Tailles système** | 🔴 **non tenu** | **113 tailles de police en pixels absolus** (`text-[10px]` ×38, `text-[11px]` ×75) dans **41 fichiers**. Racine portée de 16 à 24 px : `text-xs` 12→18 px, `text-sm` 14→21 px, **`text-[11px]` reste à 11 px**. Les pastilles d'état, les compteurs d'onglets et les puces de KPI **ignorent le réglage du navigateur**. |
| **Libellés explicites** | 🔴 **non tenu** | **15 emplacements de champ sans libellé associé, sur 10 écrans** (§8, D28-007), dont un composant du système, `SearchInput`, qui **n'expose aucun moyen** d'en poser un et qui sert 8 écrans. |
| **Mode sombre** | 🟠 **partiel** | Appliqué sur 32 écrans, **absent des 5 écrans hors coquille** (les 4 d'authentification + le `404`) : un utilisateur qui a choisi le sombre voit sa **page de connexion en clair**. Et c'est le mode qui **cumule le plus de défauts de contraste** (108 nœuds contre 88). |

**Deux exigences voisines, non demandées par le §23.5 mais mesurées, à porter au crédit du produit** :
`<html lang="fr">` correct sur 37/37, et **reflow à 320 px : 0 écran sur 27 ne déborde
horizontalement** (témoin joué : une page volontairement large est bien détectée à 900 px).

---

## 7. Témoins — ce qui rend mes nombres croyables

| sonde | témoin joué | résultat |
|---|---|---|
| indicateur de focus | 3 boutons plantés : `outline:none` nu / `outline:none`+`box-shadow` / bouton nu | rend `AUCUN` / `box-shadow` / `outline` — **elle sait dire non** |
| piège de focus | modale plantée **sans** piège | 3 sorties sur 4 relevées — la sonde ne dit pas « piégé » par défaut |
| libellé de champ | 5 champs plantés : `label for` / `<label>` enveloppant / `<label>` dont le **premier étiquetable est un bouton** / `placeholder` seul / `aria-label` | `OUI/OUI/NON/NON/OUI` — **exactement l'attendu**, y compris le cas subtil du premier étiquetable |
| débordement horizontal | page à 900 px dans un cadre de 320 px, puis page sage | `scroll 900 / client 320` puis `320 / 320` |
| bouton imbriqué | `<button aria-haspopup><button aria-label></button></button>` **et** `<button aria-haspopup><span aria-label></span></button>` | axe relève **`button-name [critical]`** sur le **premier seulement** — le motif de `UserMenu` n'est pas accusé à tort |
| contraste | `text-slate-900`/blanc (17,83) et `text-slate-300`/blanc (1,49) | encadrent la mesure, et le second **bascule** en sombre → le `!important` de D27-002 est actif |
| détecteur axe | `#root` non vide vérifié à chaque écran ; **DOM compté** (19 à 572 éléments) | une page blanche ne produit pas de « 0 violation » silencieux |

---

# CONSTATS

### [D28-001] Neuf écrans construisent leurs tableaux avec `role="row"` sans conteneur ni cellule : 14 violations axe CRITIQUES apparaissent dès que la liste a des lignes
- Sévérité      : S1 grave
- Domaine       : interface / UX
- Référence     : main 8db8229 (frontend identique à e8924b8)
- Emplacement   : `frontend/src/features/companies/CompaniesListPage.tsx:682,718,728` · `components/CompanyRow.tsx:52` · `contacts/ContactsListPage.tsx:189,203,217` · `llm/LlmRouterPage.tsx:103,121` · `llm/ProxyProvidersPage.tsx:74,93` · `llm/RotationsPage.tsx:85,102` · `rgpd/AiActRegisterPage.tsx:129,148` · `rgpd/AuditLogsPage.tsx:146,167` · `rgpd/RgpdRequestsPage.tsx:157,175` · `users/UsersPage.tsx:114,131`
- Constat       : 21 `role="row"` et 2 `role="rowgroup"` sont déclarés dans 9 écrans, sans aucun `role="table"`, `role="grid"`, `role="columnheader"` ni `role="cell"`/`gridcell` nulle part dans le dépôt.
- Preuve        : `grep -rn 'aria-rowindex\|role="row"\|role="rowgroup"' frontend/src --include=*.tsx` → 21 lignes, 9 écrans. `grep -rn 'role="table"\|role="grid"\|role="columnheader"\|role="cell"\|role="gridcell"'` → **0 ligne**. Mesure dynamique sur `/companies` servi avec 5 fiches (`04_PREUVES/agent-28/10_companies-peuple-14-critiques.txt`) :
  ```
  role=row : 6 | role=table/grid : 0 | role=columnheader : 0 | role=cell/gridcell : 0
  axe : 30 noeuds | {"critical":14,"serious":16,"moderate":0,"minor":0}
   - aria-allowed-attr        [critical] x6  (aria-rowindex hors d'une grille)
   - aria-required-children   [critical] x6  (role=row sans cellule)
   - aria-required-parent     [critical] x2  (role=row/rowgroup sans table)
  ```
  Identique en mode sombre (14 critiques).
- Témoin négatif: le même écran **vide** rend `0 critique` — la sonde ne fabrique pas de rouge. Et `axe.run` sur `/media` (qui emploie un `<table>` sémantique) ne relève aucune de ces trois règles : le contrôle distingue les deux idiomes.
- Impact        : pour un lecteur d'écran, ces listes ne sont **pas** des tableaux. Le mode tableau (navigation par colonne, annonce « ligne 3 sur 100, colonne Qualité ») n'est pas disponible, et `aria-rowindex` — qui annonce « ligne 3 sur 1 319 567 » — est **ignoré** faute de grille parente. Les 9 écrans concernés sont les listes centrales du produit : entreprises, contacts, journal d'audit, demandes RGPD, utilisateurs. C'est la question laissée ouverte par **D27-005** (« à vérifier écran par écran, hors de mon périmètre ») : **tranchée, et c'est le mauvais côté**.
- Reproduction  : `node 04_PREUVES/agent-28/scripts/serve.mjs` puis `node .../scripts/companies-peuple.mjs`.
- Correctif     : deux voies. (a) minimale : ajouter `role="grid"` (ou `table`) sur le conteneur, `role="rowgroup"` sur l'en-tête et le corps, `role="columnheader"` sur les cellules d'en-tête et `role="gridcell"` sur les cellules — ~30 min par écran, **~4 h** pour les 9. (b) durable : extraire le composant `DataTable` que **D27-005** demande déjà et n'écrire ces rôles qu'une fois — ~2 j, et le défaut ne peut plus revenir. **La voie (b) est la bonne** : la voie (a) recrée neuf fois la même chose.
- Statut        : ouvert

---

### [D28-002] La porte `a11y.yml` mesure quatre écrans VIDES et n'assert que sur `critical` : elle ne peut rougir sur aucun des 88 défauts sérieux du produit, et les 14 défauts critiques qui existent sont hors de sa portée
- Sévérité      : S1 grave
- Domaine       : tests
- Référence     : main 8db8229
- Emplacement   : `.github/workflows/a11y.yml:41-47` · `frontend/tests/e2e/a11y.spec.ts:4-9` et `:26-28`
- Constat       : la porte visite 4 URL sur 37, sans aucune API donc sur des écrans vides, et son assertion écarte tout ce qui n'est pas d'impact `critical`.
- Preuve        : trois mesures, toutes dans `11_porte-a11y-rejouee-et-libelles.txt`.
  1. **Elle tourne et elle passe** : `gh run view 32241133030 --log` → `Running 4 tests using 2 workers / ···· / 4 passed (10.3s)` (`14_journal-ci-a11y-run-32241133030.txt`).
  2. **Rejouée à l'identique** (ses 4 URL, ses 3 tags, son filtre) : chaque URL rend `target-size[serious]×1` et **`critical retenus : aucun`** → `LE TEST PASSE` ×4.
  3. **La même porte, sur son propre écran `/companies`, avec 5 fiches servies** :
     `{"critical":14,"serious":16}` → **`LE TEST ECHOUE`**.
  Et sur l'ensemble du produit (`02_`/`03_axe-37-ecrans-*`) : **88 nœuds sérieux en clair, 108 en sombre, 0 critique** — le filtre écarte **100 %** de ce qui existe dans l'état qu'elle mesure.
- Témoin négatif: la porte **sait** échouer — elle échoue dès que l'écran a des données (mesure 3). Le vert n'est donc pas un test cassé, c'est un test qui regarde au mauvais endroit. Par ailleurs `a11y.spec.ts:19` contrôle déjà que `#root` n'est pas vide : les auteurs ont vu le piège « vert sans rien mesurer » et l'ont fermé **au niveau du DOM**, sans voir qu'il restait ouvert **au niveau des données**.
- Impact        : le tableau de bord CI affiche « Accessibility ✅ » alors que 33 écrans sur 37 portent une violation `serious` et que les 9 listes du produit portent 14 violations `critical` en usage réel. C'est **A-011 / piège 19** : la garde mesure le mauvais objet — ici, la mauvaise **version** de l'objet. Corollaire pratique : **un correctif d'accessibilité ne sera jamais confirmé par cette porte, et une régression ne sera jamais attrapée.**
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/porte-et-libelles.mjs`.
- Correctif     : trois gestes, par ordre de rendement.
  1. **Assertier sur `serious` ET `critical`** (`expect(results.violations.filter(v => ['critical','serious'].includes(v.impact))).toEqual([])`) — après avoir traité les 2 règles en cours, sinon la porte rougit d'emblée sur 33 écrans. **~15 min de code, ~2 j de dette à solder d'abord.**
  2. **Servir des données** : soit un `page.route` de bouchons dans la spec (la voie que j'ai jouée, ~1 h), soit un jeu de référence portable (il en existe un depuis la PR #184). **Sans cela, aucune des trois règles critiques ne sera jamais évaluée.**
  3. **Élargir de 4 à 37 écrans** : la boucle existe déjà, il n'y a qu'à allonger `PAGES`. ~10 min, ~40 s de CI.
  Et déclarer le job **vérification requise** sur `main`, faute de quoi il informe sans protéger.
- Statut        : ouvert

---

### [D28-003] `Modal`, `Drawer` et `GlobalSearch` déclarent `aria-modal="true"` sans piéger le focus, sans le déplacer à l'ouverture, sans le restituer, et sans neutraliser l'arrière-plan
- Sévérité      : S1 grave
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/Modal.tsx:43` (`Modal`) et `:106` (`Drawer`) · `components/ui/GlobalSearch.tsx:61-67`
- Constat       : les trois conteneurs portent `role="dialog" aria-modal="true"` ; aucun ne déplace le focus à l'ouverture (sauf `GlobalSearch`, par `autoFocus` de cmdk), aucun ne l'empêche de sortir, aucun ne pose `inert` ou `aria-hidden` sur le reste de la page, aucun ne le restitue au déclencheur à la fermeture.
- Preuve        : mesure au clavier dans le navigateur, `07_interactions-modale-menu-onglets-skiplink.txt` :
  ```
  A. Recherche globale : focus a l ouverture INPUT (dialog=true)
     6 Tab -> Notifications / Theme light / Theme system / Theme dark / UUtilisateur / Importer
     >>> SORTIES du dialogue : 6 / 6
     Escape ferme : true | focus restitue a : BUTTON "Importer"   (et non au declencheur)
  B. Drawer mobile : focus a l ouverture BUTTON "Ouvrir le menu" (dialog=false)
     10 Tab, dans le dialogue ? [false ×10]   >>> SORTIES : 10 / 10
     arriere-plan inert/aria-hidden ? {"inert":false,"ariaHidden":null}
     Escape ferme : true | focus restitue a : A "Lancer scraping →"
  C. Modal mobile : aria-label null, aria-labelledby null, titre <h2> « Recherche » present
     focus a l ouverture BUTTON "Rechercher" (dialog=false)   >>> SORTIES : 8 / 8
  ```
- Témoin négatif: la même sonde, sur une modale plantée **volontairement sans piège**, relève 3 sorties sur 4 et détecte le retour dans la modale au 4ᵉ Tab (`06_temoin-negatif-sonde-focus.txt`, section « temoin piege de focus »). Elle sait donc distinguer « sort » de « revient ». Par ailleurs Échap fonctionne sur les trois : le contrôle ne dit pas « tout est cassé ».
- Impact        : `aria-modal="true"` **dit** au lecteur d'écran que le reste de la page n'existe plus. Il existe. Un utilisateur qui tabule depuis la modale se retrouve à piloter la page **derrière** un voile qu'il ne voit pas, dans un contexte que son outil lui a annoncé comme fermé. À la fermeture, il est déposé à un endroit arbitraire de la page — sur `/companies` mesuré, le bouton « Importer », qui **lance un import** si on presse Entrée par réflexe. Le tiroir de la barre latérale mobile est la **seule** navigation sur téléphone : cette combinaison touche tous les utilisateurs mobiles au clavier ou au lecteur d'écran. WCAG 2.1.2 (pas de piège) est respecté ; **2.4.3 (ordre du focus) et 4.1.2 (nom, rôle, valeur) ne le sont pas**.
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/interactions.mjs`.
- Correctif     : un seul crochet `useFocusTrap(ref, open)` monté dans `Modal`, `Drawer` et `GlobalSearch` : mémoriser `document.activeElement` à l'ouverture, focaliser le premier élément focalisable du conteneur, boucler `Tab`/`Shift+Tab` sur les bornes, restituer le focus à la fermeture, et poser `inert` sur le frère `<div>` de la coquille. Ajouter `aria-labelledby` pointant sur le `<h2>` de `Modal`/`Drawer` (`useId`). **Coût : ~4 h**, un seul endroit, les trois dispositifs corrigés d'un coup — c'est le meilleur rapport de tout mon lot.
- Statut        : ouvert

---

### [D28-004] Cinq emplacements imbriquent un `<button>` dans le `<button>` de `DropdownMenu` : le déclencheur perd son nom accessible et axe relève `nested-interactive`
- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/DropdownMenu.tsx:47-55` (le conteneur fautif) — appelants : `features/audiences/AudiencesListPage.tsx:202`, `features/campaigns/CampaignsListPage.tsx:261`, `features/companies/CompanyDetailPage.tsx:153`, `features/companies/components/CompanyRow.tsx:116`, `features/scraping/ScraperRunsPage.tsx:641`
- Constat       : `DropdownMenu` enveloppe systématiquement le nœud `trigger` dans son propre `<button>`, et 5 des 7 appelants lui passent un `<button>` ou un `IconButton`.
- Preuve        : `grep -rn -A6 "<DropdownMenu" frontend/src --include=*.tsx` → 7 appels, 5 avec un `<button>`/`IconButton` en `trigger`, 2 avec un `<span>` (`layout/UserMenu.tsx:81`, `layout/WorkspaceSelector.tsx:62`).
  Mesuré à l'exécution sur `/companies` servi avec 5 fiches (`10_companies-peuple-14-critiques.txt`) :
  ```
  declencheurs aria-haspopup=menu : 7 | dont contenant un <button> IMBRIQUE : 5
   - nested-interactive [serious] x5 : Interactive controls must not be nested
  ```
  Et le témoin isolé (`09_…temoin-bouton-imbrique.txt`) montre ce qu'axe en dit hors contexte : **`button-name [critical]`** sur le déclencheur imbriqué — un `<button>` dont tout le contenu est interactif n'a **aucun** nom calculable.
- Témoin négatif: le même témoin comprend un second déclencheur bâti comme `UserMenu` (`<span aria-label>`) : axe **ne le relève pas**. La règle ne condamne donc pas le composant, elle condamne cinq usages. Et sur la page vide, `dont contenant un <button> IMBRIQUE : 0` — le compteur n'invente rien.
- Impact        : HTML invalide (`<button>` n'admet pas de contenu interactif). Le déclencheur du menu d'actions de chaque ligne d'entreprise, de campagne et d'audience n'a pas de nom : un lecteur d'écran annonce « bouton », sans plus. Au clavier, deux arrêts de tabulation pour un seul contrôle. Et c'est **la même racine que D27-009** : `IconButton` **impose** `aria-label` par sa signature — la garantie est bonne, elle est simplement annulée par l'enveloppe.
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/companies-peuple.mjs`.
- Correctif     : faire de `DropdownMenu` un composant qui **clone** son `trigger` (`cloneElement` avec `onClick`, `aria-haspopup`, `aria-expanded`) au lieu de l'envelopper — le patron est déjà employé par `Tooltip.tsx:33-41`, dans le même dossier. Les 2 appelants à `<span>` deviennent alors des `IconButton` comme les 5 autres, et le composant retrouve une seule forme. **Coût : ~2 h** (composant + 7 appelants).
- Statut        : ouvert

---

### [D28-005] Le lien d'évitement, seul dispositif de saut de navigation du produit, est illisible en mode clair : 1,19:1 mesuré au pixel
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/styles/index.css:93-102` · `frontend/src/app/RootLayout.tsx:73`
- Constat       : `.skip-link:focus` se repositionne en `left:1rem; top:1rem` — c'est-à-dire **au-dessus de la barre latérale bleu profond** — sans fond ni rembourrage, en héritant de la couleur de texte du `body` (`oklch(0.15 0 0)`, quasi noir).
- Preuve        : capture d'écran du rectangle du lien focalisé, puis lecture des pixels (`12_tailles-systeme-et-reflow.txt` / `09_…txt`, section 1) :
  ```
  clair   | texte rgb(11,11,11) | fond peint dominant rgb(4,32,58) (1478 px) | ratio 1.19 | seuil 4.5 -> ECHEC AA
          | fond propre du lien : rgba(0, 0, 0, 0) | padding : 0px | position 16,16
  sombre  | texte rgb(238,238,238) | fond peint dominant rgb(4,32,58)        | ratio 14.22 | OK
  ```
  Le lien **fonctionne** par ailleurs : premier `Tab` de chaque écran, `hash` = `#main` après Entrée.
- Témoin négatif: la même chaîne de mesure rend 17,83:1 sur `text-slate-900`/`bg-white` et 1,49:1 sur `text-slate-300`/`bg-white` — elle encadre correctement. Et le mode sombre du **même** lien rend 14,22 : ce n'est pas la sonde qui échoue toujours, c'est le mode clair qui est mauvais.
- Impact        : le seul mécanisme qui permet à un utilisateur au clavier d'éviter les ~14 liens de la barre latérale à chaque page est **invisible dans le thème par défaut** — texte noir sur bleu marine, sans fond. Il fonctionne, mais on ne peut pas savoir qu'on l'a. WCAG 1.4.3 non tenu, et le §23.5 « navigation clavier complète » perd son point d'entrée. **Aucune porte ne le verra jamais** : axe ne mesure pas l'état `:focus` d'un élément hors flux.
- Reproduction  : ouvrir `/companies` en mode clair, presser `Tab` une fois, regarder le coin supérieur gauche.
- Correctif     : quatre déclarations dans `index.css` — `background: var(--color-brand-600)`, `color: #fff`, `padding: .5rem .75rem`, `border-radius: var(--radius-button)`. **Coût : ~10 min.** Le meilleur rapport correctif/effort du rapport.
- Statut        : ouvert

---

### [D28-006] Les 37 écrans partagent le même titre de document
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/index.html` (le seul `<title>`) — aucun écran ne le modifie
- Constat       : `document.title` vaut `"Axion CRM Pro"` sur les 37 écrans ; aucune route ne le met à jour.
- Preuve        : parcours des 37 routes, lecture de `document.title` (`09_titre-lang-sombre-mouvement-temoin-bouton-imbrique.txt`) :
  ```
  >>> titres de document DISTINCTS sur 37 ecrans : 1 ["Axion CRM Pro"]
  ```
  `grep -rn "document.title\|useDocumentTitle\|<title" frontend/src` → aucune occurrence hors `index.html`.
- Témoin négatif: la même sonde relève correctement les autres attributs du document qui **varient** — `document.documentElement.classList.contains('dark')` rend `true` sur 32 écrans et `false` sur 5 (D28-010). Elle n'est donc pas aveugle aux différences.
- Impact        : WCAG 2.4.2 (Page Titled) non tenu. Un utilisateur de lecteur d'écran qui change d'écran n'entend **rien** annoncer ; il doit explorer la page pour savoir où il est. Avec plusieurs onglets ouverts — un usage évident pour un CRM (une fiche, une liste, le journal d'audit) — les onglets sont **indiscernables**. L'historique du navigateur est une liste de 37 entrées identiques. Et le §23.5 « libellés explicites » commence par celui de la page.
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/divers.mjs`, section 2.
- Correctif     : TanStack Router expose `head`/`meta` par route ; à défaut, un `useEffect` de 3 lignes dans `RootLayout` alimenté par le `PageHeader` déjà présent sur 27 écrans. **Coût : ~2 h** pour les 37.
- Statut        : ouvert

---

### [D28-007] Quinze emplacements de champ sans libellé associé, sur dix écrans — dont `SearchInput`, composant du système employé par huit écrans, qui n'expose aucun moyen d'en poser un
- Sévérité      : S2 défaut
- Domaine       : interface / UX / conformité (CDC §23.5)
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/Toolbar.tsx:30` (`SearchInput`) — appelants : `companies/CompaniesListPage.tsx:476`, `contacts/ContactsListPage.tsx:123`, `crm-console/CandidatesPage.tsx:100`, `crm-console/ContactsHubPage.tsx:116`, `media/JournalistsListPage.tsx:96`, `media/MediaListPage.tsx:230`, `rgpd/AuditLogsPage.tsx:109`, `scraping/ScraperRunsPage.tsx:384` — plus `campaigns/CampaignsListPage.tsx:148`, `campaigns/CampaignWizardPage.tsx:431`, `:478`, `:721`, `:769`, `:785`, `:847`
- Constat       : quinze contrôles de saisie n'ont ni `<label for>`, ni `<label>` enveloppant dont ils soient le premier élément étiquetable, ni `aria-label`, ni `aria-labelledby`.
- Preuve        : deux mesures qui se recoupent.
  **(a) À l'exécution**, sonde de nom accessible sur les 37 écrans (`11_porte-a11y-rejouee-et-libelles.txt`) :
  ```
  TOTAL (etat SANS API) : 46 champs rendus, 7 SANS nom accessible, dont 7 n'ont qu'un placeholder.
    companies / contacts / media / journalists / scraper-runs / audit-logs / campaigns
  ```
  plus 1 de plus à l'**étape 2** de l'assistant de campagne (`16_assistant-campagne-etape-2.txt`) :
  `[{"tag":"input","type":"text","ph":"Rechercher un dépt…","nom":"NON"}]`.
  **(b) Sur le code**, les emplacements que l'état sans API n'atteint pas :
  - `Toolbar.tsx:13-37` — la signature de `SearchInput` est `{ value, onChange, placeholder, className }` : **il n'existe aucune prop pour nommer le champ**. Les 8 appelants ne peuvent donc pas corriger sans toucher au composant.
  - `CampaignWizardPage.tsx:769` et `:785` — `<label className="text-[10px]…">RPM</label>` puis `<Input …/>` : le `<label>` est un **frère sans `htmlFor`**, jamais associé.
  - `CampaignWizardPage.tsx:847` (`NumberField`, rendu 2 fois en `:694` et `:704`) — l'étiquette est un `<span>`, pas un `<label>`.
  - `CampaignWizardPage.tsx:431` — `<input type="datetime-local">` placé dans un `<Field label="Planification">`, mais le **premier élément étiquetable** de ce `<label>` est un `<button>` du `SegmentedControl` : l'étiquette part sur le bouton, le champ reste nu. *(Ce cas est celui que **D27-008** signalait en passant ; je le confirme et j'en donne la cause exacte.)*
  - `CampaignWizardPage.tsx:721` — `<input type="range">` sans `<label>` du tout.
  **Répartition** : 8 emplacements pour `SearchInput`, 5 pour `Input`, 2 champs bruts. **Dix écrans** : `/companies`, `/contacts`, `/console/vivier`, `/console/contacts`, `/journalists`, `/media`, `/audit-logs`, `/scraper-runs`, `/campaigns`, `/campaigns/new` (qui en concentre 6).
- Témoin négatif: la sonde a été validée sur cinq champs plantés — `label for` → OUI, `<label>` enveloppant → OUI, **`<label>` dont le premier étiquetable est un bouton → NON**, `placeholder` seul → NON, `aria-label` → OUI. Elle attrape donc précisément le cas subtil de `:431`. Et sur le corpus réel elle rend `0 sans nom` sur `login`, `/2fa`, `magic-link`, `password-reset`, `campaigns-new` étape 1, `audiences-new` : elle ne condamne pas tout le monde.
- Impact        : `axe` **ne verra jamais ces sept-là** : sa règle `label` accepte `non-empty-placeholder` comme source de nom. Or un espace réservé **disparaît dès la première frappe** et son contraste est de **2,63:1** (§4) — un utilisateur qui revient sur un champ rempli n'a plus aucun moyen de savoir ce qu'il contient. Sur `/audit-logs`, le champ non nommé est celui qui filtre le journal réglementaire. Le CDC §23.5 exige des « libellés explicites ». Et `FormField`, qui fait déjà tout cela correctement, est employé par **un** écran (D27-008).
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/porte-et-libelles.mjs` et `node scripts/assistant-campagne.mjs`.
- Correctif     : ajouter une prop `label: string` **obligatoire** à `SearchInput` — comme `IconButton` le fait pour `label` — rendue en `<label class="sr-only">` ; les 8 appelants deviennent des erreurs de compilation TypeScript, ce qui garantit qu'aucun n'est oublié. Puis convertir les 7 autres emplacements vers `FormField`. **Coût : ~3 h.**
- Statut        : ouvert

---

### [D28-008] `role="menu"` et `role="tab"` sont annoncés sans le clavier qu'ils promettent, et la fermeture du menu perd le focus sur `<body>`
- Sévérité      : S2 défaut
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/DropdownMenu.tsx:58,71` · `components/ui/Tabs.tsx:21,25,52,56` · `components/ui/SegmentedControl.tsx:23,36`
- Constat       : `DropdownMenu` déclare `role="menu"`/`role="menuitem"` et `Tabs`/`SegmentedControl` déclarent `role="tablist"`/`role="tab"`/`aria-selected`, sans gestion des flèches, sans `tabindex` mobile, sans `aria-controls`, sans `role="tabpanel"`, et sans restitution du focus.
- Preuve        : mesure clavier dans le navigateur (`07_interactions-modale-menu-onglets-skiplink.txt`) :
  ```
  D. Menu utilisateur : menu ouvert : 1
     focus juste apres ouverture : BUTTON "MWMon workspace"   (reste sur le declencheur)
     apres FlecheBas             : BUTTON "MWMon workspace"   (inchange)
     Escape ferme : true | focus : BODY                       (le focus est perdu)
  E. Onglets (/settings) : role=tablist 1 | role=tab 4 | role=tabpanel 0
     onglets avec aria-controls : 0
     FlecheDroite deplace-t-elle le focus ? Workspace -> Workspace | NON
     tabindex des onglets : [null,null,null,null]             (pas de roving)
  ```
- Témoin négatif: la même sonde relève **`Escape ferme : true`** pour les trois dispositifs et l'entrée du focus dans le menu par `Tab` (`dansMenu: true`) : elle mesure bien les comportements présents, elle ne rend pas « rien ne marche ».
- Impact        : un utilisateur de lecteur d'écran entend « menu », « onglet 1 sur 4 sélectionné », et applique les gestes que ces rôles impliquent — flèches. Rien ne bouge. Les onglets restent **tous** dans l'ordre de tabulation (pas de `tabindex` mobile), donc traverser une barre de 4 onglets coûte 4 `Tab` au lieu d'un. Le `role="tab"` sans `aria-controls` ni `tabpanel` empêche le saut direct au contenu de l'onglet. Et la perte du focus sur `<body>` à la fermeture d'un menu ramène l'utilisateur **au début de la page**. WCAG 4.1.2 et 2.4.3.
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/interactions.mjs`, sections D et E.
- Correctif     : deux crochets, écrits une fois dans `components/ui/`. `useRovingTabIndex` pour `Tabs`/`SegmentedControl` (flèches, `Home`/`End`, `tabindex` 0/−1) + `aria-controls`/`id` reliant chaque onglet à son panneau ; `useMenuKeyboard` pour `DropdownMenu` (focus au premier item à l'ouverture, flèches, `Home`/`End`, restitution au déclencheur à la fermeture). **Coût : ~6 h**, et les 11 écrans à onglets plus les 7 menus en bénéficient sans être touchés.
- Statut        : ouvert

---

### [D28-009] Cent treize tailles de police en pixels absolus : le réglage « taille de police » du navigateur ne les atteint pas
- Sévérité      : S2 défaut
- Domaine       : interface / conformité (CDC §23.5 « tailles système »)
- Référence     : main 8db8229
- Emplacement   : 41 fichiers de `frontend/src` — `text-[11px]` ×75, `text-[10px]` ×38 ; entre autres `components/ui/StatusPill.tsx:64`, `ui/KpiCard.tsx`, `ui/Tabs.tsx:40,71`, `ui/Tooltip.tsx:58`, `features/campaigns/CampaignWizardPage.tsx:769,785`
- Constat       : 113 classes de taille de police sont exprimées en pixels absolus, unité qui ne dépend pas de la taille de police racine.
- Preuve        : `grep -rhoE "text-\[[0-9.]+px\]" frontend/src --include=*.tsx | sort | uniq -c` → `75 text-[11px]`, `38 text-[10px]` ; `grep -rlE …| wc -l` → **41 fichiers**.
  Mesure du réglage navigateur, racine portée de 16 px à 24 px (le cran « Très grande » de Chrome), `12_tailles-systeme-et-reflow.txt` :
  ```
  a 16 px : {"text-xs":"12px","text-sm":"14px","text-base":"16px","text-[10px]":"10px","text-[11px]":"11px"}
  a 24 px : {"text-xs":"18px","text-sm":"21px","text-base":"24px","text-[10px]":"10px","text-[11px]":"11px"}
  ```
- Témoin négatif: dans la **même** mesure, `text-xs`, `text-sm` et `text-base` **suivent** (12→18, 14→21, 16→24). La sonde n'est donc pas aveugle au changement : elle montre que le mécanisme fonctionne, et que 113 déclarations s'y soustraient.
- Impact        : un utilisateur qui agrandit la police de son navigateur — le geste d'accessibilité le plus courant, et celui que le CDC nomme « tailles système » — voit le texte courant grossir de 50 % et les **pastilles d'état, compteurs d'onglets, puces de KPI et étiquettes de limites de débit rester à 10-11 px**. L'écart devient tel que ces éléments deviennent le point illisible d'une page par ailleurs agrandie. WCAG 1.4.4 (Resize text) n'est pas tenu pour ces 113 occurrences.
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/tailles-et-reflow.mjs`, section 1.
- Correctif     : remplacer `text-[10px]` par `text-[0.625rem]` et `text-[11px]` par `text-[0.6875rem]` — substitution mécanique, rendu **identique** à 16 px, et le réglage système reprend effet. `sed` sur 41 fichiers + relecture visuelle. **Coût : ~1 h.** Mieux : deux jetons `--text-2xs`/`--text-3xs` dans `index.css`, qui donnent en plus une échelle typographique au design system (cf. D27 §6, « espacements : aucune échelle projet »).
- Statut        : ouvert

---

### [D28-010] Le mode sombre n'est pas appliqué sur les cinq écrans hors coquille, dont la page de connexion
- Sévérité      : S2 défaut
- Domaine       : interface / conformité (CDC §23.5 « mode sombre »)
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/DarkModeToggle.tsx:14-28` — le thème n'est appliqué que par l'effet de ce composant, monté uniquement dans `layout/Header.tsx:74`, lui-même monté uniquement dans `app/RootLayout.tsx:95`
- Constat       : `applyTheme()` n'est appelé que depuis l'effet de `DarkModeToggle` ; les 4 écrans d'authentification et le `404` sont enfants directs de `rootRoute` et ne montent pas `RootLayout`, donc la classe `.dark` n'est jamais posée.
- Preuve        : parcours des 37 routes avec `localStorage['axion-theme'] = 'dark'` posé **avant** le chargement (`09_titre-lang-sombre-mouvement-temoin-bouton-imbrique.txt`) :
  ```
  login          | .dark = false        magic-link     | .dark = false
  2fa            | .dark = false        password-reset | .dark = false
  404            | .dark = false        les 32 autres  | .dark = true
  ```
  `routeTree.tsx:61-64` et `:105` confirment que ces 5 routes ne passent pas par `layoutRoute`.
- Témoin négatif: la préférence est **la même** pour les 37 écrans (posée par `addInitScript` avant tout script de page) et 32 écrans la respectent. Ce n'est donc ni la sonde ni le stockage qui échouent.
- Impact        : l'utilisateur qui a choisi le thème sombre voit **la page de connexion, la page de code à 6 chiffres, le lien magique, la réinitialisation de mot de passe et la page d'erreur en blanc éclatant** — c'est-à-dire l'éblouissement précisément là où la transition est la plus brutale (souvent de nuit, souvent le premier écran de la journée). Le CDC §23.5 exige « mode sombre » sans exception. C'est aussi le premier écran que verra la première personne qui se connectera au CRM en production (A-012) : la première impression du produit ignore le réglage de son utilisateur.
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/divers.mjs`, section 2.
- Correctif     : sortir l'application du thème du composant. Trois lignes dans `main.tsx`, avant le rendu : lire `localStorage['axion-theme']`, résoudre `system` via `matchMedia`, poser la classe sur `<html>`. Cela supprime au passage le clignotement clair→sombre au chargement des 32 autres écrans. `DarkModeToggle` ne garde que le changement. **Coût : ~30 min.**
- Statut        : ouvert

---

### [D28-011] Le mode sombre est le mode le plus contrasté à l'envers : 76 défauts de contraste sur 31 écrans, dont le raccourci ⌘K de l'en-tête à 1,36:1
- Sévérité      : S2 défaut
- Domaine       : interface
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/GlobalSearch.tsx:50` et `:55` (déclencheur + `<kbd>`, présents dans l'en-tête des 32 écrans de la coquille) · `components/ui/EmptyState.tsx:18` · `features/coverage/CoveragePage.tsx` · `features/audiences/AudienceBuilderPage.tsx`
- Constat       : en mode sombre, axe relève 76 nœuds en échec de contraste sur 31 écrans, contre 55 sur 4 écrans en mode clair.
- Preuve        : `03_axe-37-ecrans-sombre.json` et `04_tableau-axe-par-ecran.txt`. Valeurs mesurées par axe (couleur avant/arrière et ratio) :
  | élément | texte | fond | ratio | seuil |
  |---|---|---|--:|--:|
  | `<kbd>⌘K</kbd>` de la recherche globale | `#45556c` | `#314158` | **1,36** | 4,5 |
  | déclencheur « Rechercher » | `#62748e` | `#13161a` | **3,80** | 4,5 |
  | description d'`EmptyState` | `#45556c` | `#13161a` | **2,39** | 4,5 |
  | légende de `/coverage` | `#45556c` | `#0c1014` | **2,51** | 4,5 |
  | pastille de tag, `/audiences/new` | `#b3e4fc` | `#00a6f4` | **1,98** | 4,5 |
  Les deux premiers sont dans l'**en-tête**, donc sur **tous** les écrans de la coquille : c'est ce qui explique les « 2 à 3 nœuds » constants de la colonne sombre du §1.
- Témoin négatif: en mode **clair**, les mêmes éléments **passent** (le `<kbd>` n'apparaît pas dans les 55 nœuds clairs) : la sonde ne condamne pas l'élément, elle condamne le mode. Et `StatusPill`, mesuré indépendamment (§4), rend 5,56 à 12,33 en sombre : les composants correctement dotés de `dark:` s'en sortent.
- Impact        : le raccourci clavier annoncé à l'utilisateur — le seul indice visuel qu'une recherche ⌘K existe — est **invisible** en mode sombre (1,36:1, à peine au-dessus du fond). L'état vide, présent sur 22 écrans, y perd sa phrase d'explication. Et cela **contredit l'intuition qui a présidé au filet `!important` de D27-002** : celui-ci a été posé pour « rattraper » le mode sombre, et le résultat mesuré est que le sombre est le mode **le plus** en défaut. Le filet corrige 4 propriétés et laisse tomber tout le reste.
- Reproduction  : `node scripts/serve.mjs` puis `DARK=1 node scripts/axe-run2.mjs`.
- Correctif     : ajouter les `dark:` manquantes sur les 5 familles relevées ; `text-slate-500`/`600` sans variante sombre est le motif dominant. Puis, à terme, **retirer le filet `!important` de D27-002** — mais seulement après, sinon 174 déclarations `dark:` aujourd'hui mortes reprennent effet d'un coup, sans que personne ne les ait jamais vues. **Coût : ~1 j** pour les contrastes ; le retrait du filet est un chantier à part, à ne pas mélanger.
- Statut        : ouvert

---

### [D28-012] La barre latérale émet six `<h3>` avant le `<h1>` de la page, et cinq écrans n'ont aucun repère de région
- Sévérité      : S3 finition
- Domaine       : interface / navigation
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/layout/Sidebar.tsx` (les 6 titres de groupe) · `features/auth/*.tsx` et `features/misc/NotFoundPage.tsx` (absence de repère)
- Constat       : sur les 32 écrans de la coquille, la séquence de titres commence par six `<h3>` puis descend au `<h1>` ; sur les 5 écrans hors coquille, il n'y a ni `<main>`, ni `<nav>`, ni `role="banner"`.
- Preuve        : lecture de la séquence de titres (`12_tailles-systeme-et-reflow.txt`, section 4) :
  ```
  /companies : ["H3 « Aujourd'hui »","H3 « Contacts »","H3 « Collecte »","H3 « Pilotage »",
                "H3 « Conformité »","H3 « Réglages »","H1 « Entreprises »"]
  /settings  : [... les 6 memes H3 ...,"H1 « Paramètres »","H3 « Identité et limites »"]
  ```
  Repères (`09_…txt`, section 2, colonne `main/nav/banner`) : `1/2/2` sur les 32 écrans de la coquille, **`0/0/0`** sur `login`, `/2fa`, `magic-link`, `password-reset` et `404`.
- Témoin négatif: la même sonde relève bien `1/2/2` ailleurs, et distingue les écrans à `<table>` sémantique des autres. Elle voit ce qui est là.
- Impact        : un utilisateur de lecteur d'écran qui parcourt la page par titres (`H` ou la liste des titres) rencontre six libellés de navigation avant d'apprendre sur quel écran il se trouve, et la structure lui présente un document dont le titre principal arrive en septième position, après six sous-titres. Sur les 5 écrans d'authentification, la navigation par régions (`D` en NVDA) ne donne rien : tout le contenu est hors repère. Ce n'est pas bloquant — d'où S3 — mais c'est le genre de détail qui fait qu'un produit « fonctionne » sans être praticable.
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/tailles-et-reflow.mjs`, section 4.
- Correctif     : dans `Sidebar`, remplacer les `<h3>` de groupe par des `<div role="presentation">` ou les rattacher à un `<nav aria-labelledby>` ; envelopper le contenu des 4 écrans d'authentification et du `404` dans un `<main>`. **Coût : ~1 h.**
- Statut        : ouvert

---

### [D28-013] Le `404` n'a aucun élément atteignable au clavier
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/features/misc/NotFoundPage.tsx` · `frontend/src/app/routeTree.tsx:105` (`path: '/*'`)
- Constat       : sur une URL inexistante, la page rendue ne contient aucun élément focalisable, aucun repère de région et aucun `<h1>` ; le `<Link to="/">` écrit dans le composant n'apparaît pas.
- Preuve        : parcours clavier sur `/route-qui-nexiste-pas` (`05_clavier-37-ecrans.log`) :
  ```
  404   focusables=0 parcours=4 sansIndicateur=0 inversions=0 premier=BODY
  ```
  et `09_…txt` : `404 | Axion CRM Pro | fr | false | h1=0 | main/nav/banner = 0/0/0`, DOM = **19 éléments**.
  Or `NotFoundPage.tsx` contient bien `<Link to="/" className="mt-4 …">Retour au tableau de bord</Link>`.
- Témoin négatif: la **même** sonde compte 7 éléments focalisables sur `login` et 45 sur `/companies` — elle sait compter. Et le DOM du `404` n'est pas vide (19 éléments) : ce n'est pas un écran blanc, c'est un écran **sans issue**.
- Impact        : un utilisateur qui suit un lien périmé — un signet, un lien d'e-mail, une adresse tapée de travers — arrive sur une page dont **on ne peut sortir ni au clavier ni au lecteur d'écran**. Il ne lui reste que le retour arrière ou la barre d'adresse. C'est le seul cas de mon lot où le clavier ne permet **rien**. À creuser : `path: '/*'` sur `rootRoute` semble ne pas produire le composant attendu — le défaut peut être de routage plutôt que d'accessibilité, mais son effet est d'accessibilité.
- Reproduction  : `node scripts/serve.mjs`, ouvrir `http://127.0.0.1:4188/route-qui-nexiste-pas`, presser `Tab`.
- Correctif     : d'abord comprendre pourquoi le composant ne rend pas son lien (TanStack Router v1 attend `notFoundComponent` sur la route racine plutôt qu'un `path: '/*'`) ; puis monter le `404` **dans** `layoutRoute` pour qu'il hérite de la barre latérale, du lien d'évitement et du thème (ce qui referme aussi D28-010 et D28-012 pour cet écran). **Coût : ~1 h**, mesure de la cause comprise.
- Statut        : ouvert

---

### [D28-014] Trois régions d'annonce dans tout le produit, aucun `ErrorBoundary` monté : ni les chargements, ni les changements d'écran, ni les erreurs de rendu ne sont annoncés
- Sévérité      : S3 finition
- Domaine       : interface / UX
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/Skeleton.tsx:1-14` · `components/ui/ErrorBoundary.tsx` (jamais importé) · `components/ui/Spinner.tsx:6`
- Constat       : le dépôt entier contient **3** régions d'annonce (`aria-live`, `role="status"`, `role="alert"`) — `ui/FormField.tsx:42`, `features/companies/CompaniesListPage.tsx`, `features/tags/TagsManagerPage.tsx` — et `CompaniesTableSkeleton` porte `aria-busy="true"` sans `aria-live`, donc la fin du chargement n'est jamais annoncée.
- Preuve        : décompte sur les 84 fichiers `.tsx` (`01_scan-statique.json`, champ `ariaLive`) → **3 fichiers, 3 occurrences**. `Skeleton` nu : aucun attribut. `Spinner` : `aria-label="Chargement"` sur un `<svg>` **sans `role="img"`**, nom non garanti. `grep -rn "ErrorBoundary" frontend/src --include=*.tsx` hors `components/ui/` → **aucune ligne** (D27-010, confirmé).
- Témoin négatif: le même décompte **trouve** les 3 régions existantes, dont le `role="alert"` de `FormField.tsx:42` : la sonde n'est pas aveugle aux attributs qu'elle cherche. Atténuation honnête : `sonner` (monté dans `main.tsx:57`) pose ses propres annonces pour les messages éphémères — les 23 écrans qui appellent `toast()` sont donc couverts pour **ce** canal-là, et je ne les compte pas comme muets.
- Impact        : un utilisateur de lecteur d'écran qui change d'écran, lance un filtre ou attend une liste **n'entend rien** : ni « chargement », ni « 1 319 entreprises chargées ». Combiné à **D28-006** (titre de document unique), il n'a **aucun** signal de changement d'écran. Et comme `ErrorBoundary` n'est monté nulle part (D27-010), une exception de rendu produit une **page blanche silencieuse** : pas de message, pas d'annonce, pas de repli — le pire cas pour quelqu'un qui ne voit pas l'écran.
- Reproduction  : `node scripts/scan.mjs`, champ `ariaLive`.
- Correctif     : `role="status" aria-live="polite"` sur `CompaniesTableSkeleton` et `Spinner` (+`role="img"`), une région d'annonce unique dans `RootLayout` alimentée au changement de route, et le montage d'`ErrorBoundary` déjà chiffré par **D27-010** (~1 h). **Coût : ~2 h** pour la partie annonces.
- Statut        : ouvert

---

### [D28-015] Deux cibles tactiles sous 24 × 24 px, présentes sur tous les écrans : 33 nœuds en clair, 32 en sombre
- Sévérité      : S3 finition
- Domaine       : interface
- Référence     : main 8db8229
- Emplacement   : `frontend/src/components/ui/DarkModeToggle.tsx:40-50` (22,9 × 24 px, dans l'en-tête des 32 écrans de la coquille) · `frontend/src/features/auth/LoginPage.tsx:110` (bouton « Afficher le mot de passe », **20 × 20 px**)
- Constat       : deux contrôles mesurent moins de 24 px dans une dimension et n'ont pas l'espacement de compensation que WCAG 2.5.8 admet.
- Preuve        : `13_cibles-tactiles-sous-24px.txt` :
  ```
  /login      .pointer-events-auto | Target has insufficient size (20px by 20px, should be at least 24px by 24px)
              boutons < 24px : [{"n":"Afficher le mot de passe","w":20,"h":20}]
  /companies  button[aria-label="Theme dark"] | 22.9px by 24px
  ```
  Ce sont les **33 nœuds `target-size`** du §1 : un par écran de la coquille, plus celui de `login`.
  Relevé secondaire : les **6 boutons de groupe de la barre latérale** mesurent 243 × **23** px — axe ne les relève pas (leur largeur compense), mais ils sont à 1 px du seuil.
- Témoin négatif: la même règle **ne relève pas** les `IconButton` du système (36 × 36 px en taille `md`, 28 × 28 en `sm`) ni les `Button` (32 à 44 px de haut) : elle ne condamne pas tout ce qui est petit.
- Impact        : sur écran tactile, le sélecteur de thème et le révélateur de mot de passe demandent une précision que WCAG 2.5.8 juge excessive. Faible portée — d'où S3 — mais c'est **la seule règle axe qui rougit sur 33 écrans sur 37**, donc c'est le premier obstacle à lever si l'on veut faire assertier la porte sur `serious` (D28-002, correctif ①). **Deux composants à corriger débloquent 33 écrans.**
- Reproduction  : `node scripts/serve.mjs` puis le script de `13_cibles-tactiles-sous-24px.txt`.
- Correctif     : `DarkModeToggle` — passer `px-2 py-1` à `px-2.5 py-1.5` (24 × 26 px). `LoginPage:110` — employer `IconButton size="sm"` (28 × 28) au lieu du bouton écrit à la main, ce qui referme au passage une occurrence de D27-009. **Coût : ~20 min.** C'est, en rapport nombre-d'écrans-nettoyés sur temps-passé, le meilleur correctif du rapport.
- Statut        : ouvert

---

### [D28-016] Aucune prise en compte de `prefers-reduced-motion` : quatre animations, dont une infinie
- Sévérité      : S3 finition
- Domaine       : interface
- Référence     : main 8db8229
- Emplacement   : `frontend/src/styles/index.css:54-74` (4 animations, aucune requête de média) — `axion-pulse-dot` employée par `ui/StatusPill.tsx:65` et `ui/PageHeader.tsx` (`LiveBadge`)
- Constat       : `index.css` définit quatre animations et n'inclut aucun bloc `@media (prefers-reduced-motion: reduce)` ; `axion-pulse-dot` tourne en `infinite`.
- Preuve        : navigateur lancé avec `reducedMotion: 'reduce'` (`09_…txt`, section 3) :
  ```
  sous prefers-reduced-motion: reduce, la classe .axion-pulse-dot :
    {"animationName":"axion-pulse-dot","duree":"2s","iterations":"infinite"}
  ```
  `grep -rn "prefers-reduced-motion" frontend/src` → **aucune occurrence**.
- Témoin négatif: le contexte navigateur applique bien la préférence — c'est un réglage de `newContext` que Playwright transmet à Chromium, et la même sonde lit correctement les autres propriétés calculées. La préférence est posée, elle n'est simplement pas écoutée par la feuille de style.
- Impact        : un utilisateur qui a demandé la réduction des animations à son système continue de voir la pastille « en direct » clignoter indéfiniment sur les écrans de scraping et de campagnes, plus les transitions d'ouverture des modales, du tiroir et des menus. WCAG 2.3.3 (niveau AAA) et l'esprit de 2.2.2. Portée limitée — les animations sont brèves et petites — d'où S3.
- Reproduction  : `node scripts/serve.mjs` puis `node scripts/divers.mjs`, section 3.
- Correctif     : sept lignes à la fin d'`index.css` :
  `@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; } }`. **Coût : ~10 min.**
- Statut        : ouvert

---

## 8. Ce que je corrige ou nuance chez d'autres agents (règle 1 appliquée à mes collègues)

| constat | ce qu'il dit | ce que j'ai mesuré |
|---|---|---|
| **D27-004** | « les copies **perdent l'anneau de focus clavier** » | 🔴 **Vrai sur le code, faux sur le rendu.** Les 30 boutons écrits à la main n'écrivent pas `outline-none` : ils gardent l'anneau `:focus-visible` du navigateur. **0 élément sans indicateur sur les 37 écrans**, sonde validée par témoin. Le défaut est une **incohérence d'anneau (S3)**, pas une perte d'accès. |
| **D27-006** | les badges seront « **illisibles** » en mode sombre | 🔴 **Non : 6,4 à 8,2:1, parfaitement lisibles.** Ils gardent leur rendu clair à l'identique (le filet `!important` ne les touche pas) : c'est un **îlot pastel sur fond noir**, défaut de cohérence et d'éblouissement. Le correctif et son coût ne changent pas ; la justification, si. |
| **D27-005** | « le `role="row"` sans parent n'est pas suffisant partout : **à vérifier écran par écran, hors de mon périmètre** » | ✅ **Vérifié : 9 écrans, 14 violations `critical` dès qu'il y a des lignes.** → **D28-001**. |
| **D27-003** | le `SegmentedControl` local de `/coverage` perd `role=tablist` et `aria-selected` | ✅ **Confirmé à l'exécution** : `/coverage` = 0/0/0, témoin `/` = 1/3/3. |
| **D27-002** | 4 règles `!important` neutralisent 174 `dark:` | ✅ **Corroboré par une autre méthode et sur un autre site** : mon témoin bas `bg-white text-slate-300` bascule de 1,49 (échec) en clair à 12,21 en sombre. Et j'ajoute une conséquence qu'il n'énonce pas : **le mode sombre reste le plus mauvais des deux** (108 nœuds contre 88). |
| **D27-010** | `ErrorBoundary` jamais monté, garde e2e aveugle | ✅ **Confirmé** ; j'en tire la conséquence a11y dans **D28-014**. |
| **H44-002** | « le seul job qui exécute Playwright n'est pas une vérification requise ; le BLOQUANT ne bloque rien » | 🟠 **À nuancer.** Le job **tourne, produit un résultat et passe** (25 exécutions, `4 passed` + `14 passed`). Le mot « BLOQUANT » est **exact au niveau du job**. Ce qui est vrai, c'est qu'il ne bloque pas la **fusion**. Le vrai problème n'est pas qu'il ne mesure rien — c'est **ce** qu'il mesure (**D28-002**). |
| **F38-006** | `lhci` sort en 1 sous `continue-on-error`, `success` sans score | ✅ **Confirmé par lecture** : `a11y.yml:78` porte `continue-on-error: true` et aucune assertion. Je ne le re-rapporte pas. |

---

## 9. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **L'interface authentifiée.** Connexion 200 → premier écran 403 → enrôlement 2FA 500 (A-012, A07-001), et pendant ma session `https://api.localhost/up` répondait **502**. **Aucun** de mes nombres ne provient d'une session réelle.
2. **Conséquence directe : mes comptes sont un plancher, pas un total.** Dans l'état sans API, 30 écrans sur 37 rendent 300 à 450 éléments de DOM contre 572 pour le plus riche. Tout ce qui dépend des données — lignes de liste, pastilles de statut réelles, menus d'actions, tiroirs de détail, tableaux d'audit — n'a pas été évalué, **sauf** sur `/companies` (que j'ai peuplé) et à l'étape 2 de l'assistant de campagne. Le passage de `/companies` de **1 à 30 nœuds** donne l'ordre de grandeur de ce qui manque : **un facteur 30 sur un écran**.
3. **Les étapes 3 et 4 de l'assistant de campagne** : « Continuer » reste désactivé tant qu'aucune zone n'est choisie, et le référentiel géographique vient de l'API. Les 5 emplacements de champ de `CampaignWizardPage` que je rapporte en **D28-007** sont donc établis **sur le code**, pas à l'exécution.
4. **Les modales de `/users`, `/tags`, `/rgpd/requests`** : ouvertes par des boutons qui exigent des données ou une mutation. Je n'ai mesuré le piège de focus que sur la modale de recherche mobile, le tiroir de la barre latérale mobile et la recherche globale — trois instances des **mêmes** composants `Modal`, `Drawer` et `GlobalSearch`, donc le verdict de **D28-003** vaut pour toutes leurs instances ; mais je ne l'ai pas vu sur celles-là.
5. **`OnboardingTour` (`react-joyride`)** : ne se déclenche qu'au premier login. Non évalué.
6. **`Breadcrumbs` et `AutoBreadcrumbs`** : présents dans l'en-tête mais non vérifiés isolément (`<nav aria-label>` ? `aria-current="page"` ?).
7. **`/media/$id` en mode sombre** : la sonde a dépassé son délai sur cet écran (colonne « sombre » vide au §1). Le mode clair a été mesuré.
8. **Le statut « vérification requise » du job `a11y` sur `main`** : je le reprends de **H44-002** et **je ne l'ai pas revérifié** (`gh api repos/:owner/:repo/branches/main/protection` non joué). Tout ce que j'affirme moi-même sur la porte porte sur **ce qu'elle mesure**, pas sur son pouvoir de blocage.
9. **Aucun lecteur d'écran réel** (NVDA, JAWS, VoiceOver). Mes verdicts ARIA portent sur ce que l'arbre d'accessibilité **contient**, pas sur ce qu'une synthèse vocale **prononce**. Les deux coïncident presque toujours ; « presque » mérite d'être écrit.
10. **Je n'ai installé aucune autorité racine** et je n'ai modifié **aucun fichier du produit** : le build est sorti hors du dépôt (`--outDir` vers le bac à sable), mes scripts vivent dans `04_PREUVES/agent-28/scripts/`, et je n'ai jamais approché le worktree `crmpro-wt-etape1a`.
