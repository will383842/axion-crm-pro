# P6 — 04 · FRONTEND, LES ÉCRANS (§4.7 du mandat, grille ÉCRAN §5.1)

> Passe 3 « regard neuf ». Auditeur neuf, sans accès aux constats des passes 1 et 2.
> Périmètre : `frontend/src/` — les 35 écrans réellement déclarés et les 33 composants
> transverses. Rédigé le 2026-08-20.

---

## 0. La référence sur laquelle j'ai mesuré — relue moi-même

Je n'ai fait confiance à aucun identifiant écrit dans un document. Commande jouée :

```
$ git -C C:/Users/willi/Documents/Projets/crmpro-wt-a35-auth log --oneline -3
23a0e5f fix(infra): la faille du 19 aout se rouvrait en un clic, et par deux autres chemins
a0a6310 test(garde): trois defauts de la garde HMAC, dont un qui polluait toute la suite
3c2c0cf docs(rectificatif): trois affirmations sur la production que ce lot ne pouvait pas mesurer

$ git -C ... rev-parse --abbrev-ref HEAD
fix/a35-authentification

$ git -C ... status --short
(vide)
```

**Référence de mesure : `23a0e5f`, branche `fix/a35-authentification`, arbre propre.**
Cette branche n'est pas fusionnée dans `main`. Le diff frontend qu'elle porte est mince —
5 fichiers, dont deux tests neufs :

```
$ git diff main..HEAD --stat -- frontend/
 frontend/src/features/auth/TwoFactorPage.tsx       | 249 +++++++++++++++++++--
 frontend/src/features/dashboard/components/ActivityFeed.tsx |  41 +++-
 frontend/src/lib/api.ts                            |  24 +-
 frontend/tests/components/ActivityFeed.test.tsx    |  80 +++++++
 frontend/tests/components/TwoFactorEnrolement.test.tsx | 102 +++++++++
 5 files changed, 470 insertions(+), 26 deletions(-)
```

Autrement dit : **sauf pour les trois fichiers ci-dessus, ce que je décris vaut aussi sur
`main`.** Les mesures backend citées en appui (routes, contrôleurs) ont été lues en LECTURE
SEULE dans `crmpro-wt-a35-auth/backend`, jamais dans `Axion-CRM-Pro/backend`.

---

## 1. ⚠️ AVERTISSEMENT — CE RAPPORT N'A OUVERT AUCUN ÉCRAN

Le §5.1 point 1 du mandat exige que chaque écran soit **ouvert à la main, dans un vrai
navigateur, capture archivée**. Je ne l'ai pas fait et je ne pouvais pas le faire : aucune
console navigateur n'est disponible dans cette session, la pile Docker est interdite
(`docker compose up`), et l'API de production est hors de portée (interdit Hetzner).

**Ce rapport est un audit statique.** Il lit le code, joue des outils en lecture (`tsc`,
`eslint`, `vitest`, le compilateur Tailwind), et recoupe les écrans avec les routes de l'API.
La section 5 dit précisément ce que cela ne permet pas de voir. Chaque constat ci-dessous
porte la commande qui l'établit ; ceux qui ne pouvaient pas être établis sans navigateur ne
sont pas écrits.

L'inventaire du mandat annonce 39 écrans. Recompté dans le code
(`grep -oE "path: '[^']+'" src/app/routeTree.tsx`) : **35 routes déclarées**, dont
`/` et le fourre-tout `/*`. Les quatre bouchons de Phase 2 ne sont plus que **deux**
(`/cold-email`, `/linkedin`) : `/crm` et `/analytics` ont été retirés du routeur
(`routeTree.tsx:104-105`). Le mandat cite `/persons/{key}` ; la route réelle est
`/console/personnes/$personKey` (`routeTree.tsx:100`).

---

## 2. LES CONSTATS

Sévérités : **S0** = l'écran affirme quelque chose de faux sur les données ou empêche le
travail · **S1** = défaut structurel qui touche tous les écrans ou perd du travail · **S2** =
défaut d'un écran ou d'un parcours · **S3** = gêne, forme, dette.

---

### P6-UI-001 · S0 · Le tableau de bord du CRM affiche « Lance ton premier scrape » — toujours, sur une base de 4,29 M de fiches

**Fichiers.**
`backend/routes/api.php:86-98` · `frontend/src/features/dashboard/DashboardPage.tsx:78-105,135-152`

**Preuve.**

```
$ grep -n "dashboard" backend/routes/api.php
86:        Route::get('/dashboard/stats', function () {

$ find backend/app -iname "*Dashboard*"
(aucun résultat)
```

`/dashboard/stats` n'est pas un contrôleur : c'est une closure qui renvoie des zéros écrits
en dur (`companies_total => 0`, `quality_distribution` à zéro, `size_distribution` à zéro).
Il n'existe **aucun** `DashboardController` dans le dépôt, et aucune seconde déclaration de
cette route.

Côté écran, `DashboardPage.tsx:105` :

```tsx
const isEmpty = !isLoading && stats.companies_total === 0;
```

`companies_total` valant toujours 0, la branche vide (lignes 137-152) est **la seule
atteignable**. Les lignes 156-236 — les quatre vignettes KPI, `QualityDistributionBar`,
`SizeDistributionChart`, `TopDeptsCard`, `ActivityFeed`, `NextActions` — sont du code mort en
exploitation. Le mandat les liste toutes les cinq comme composants à auditer ; aucune n'est
jamais rendue.

**Second défaut, dans le même écran.** Le sélecteur de période 7j/30j/90j
(`DashboardPage.tsx:116-121`) n'apparaît pas dans la clé de requête (`queryKey:
['dashboard-stats']`, ligne 79). Changer de période **ne relance aucune requête**. Seul le
sous-titre change (ligne 112, `PERIOD_LABEL[period]`). L'utilisateur voit l'étiquette
« derniers 90 jours » s'afficher et croit lire trois mois de données. (Et de toute façon la
closure serveur ignore le paramètre `period`.)

**Troisième défaut.** `placeholderData` est un **objet** (lignes 82-91), pas une fonction :
`isLoading` reste faux et `DashboardSkeleton` (lignes 243-279) n'est jamais rendu. Ce point
est d'ailleurs écrit noir sur blanc dans l'en-tête de `tests/screens/DashboardPage.test.tsx`
— il est connu, il n'est pas corrigé, et le squelette reste dans le fichier comme s'il
servait.

**Impact pour celui qui s'en sert.** L'écran d'accueil du CRM — le premier que voit
l'opérateur à chaque ouverture, celui que la visite guidée présente comme « vos KPIs » — lui
dit qu'il n'a **aucune entreprise** et lui propose de lancer son premier scrape. La base en
contient 4,29 millions. C'est la première phrase que l'outil adresse à son utilisateur, et
elle est fausse.

**Pourquoi la suite de tests ne le voit pas.** `tests/screens/DashboardPage.test.tsx:29-38`
injecte `companies_total: 4_294_898` par MSW. Le test valide un écran que la production ne
rend jamais. Rien, nulle part, ne compare la forme mockée à la route réelle
(`tests/lib/msw-contract.test.ts` teste le harnais, pas l'API).

---

### P6-UI-002 · S0 · La recherche globale ⌘K ne peut rien trouver, et deux de ses trois familles de résultats ne mènent nulle part

**Fichiers.**
`backend/app/Http/Controllers/Api/GlobalSearchController.php:17-20` ·
`backend/routes/api.php:99-105,237` · `frontend/src/components/ui/GlobalSearch.tsx:89-140`

**Preuve.**

```php
// GlobalSearchController.php:19
return $this->ok(['companies' => [], 'contacts' => [], 'tags' => []]);
```

```
$ grep -n "'/search'" backend/routes/api.php
99:        Route::get('/search', function (Request $request) {
237:        Route::get('/search', [GlobalSearchController::class, 'index']);
```

`GET /search` est déclarée **deux fois**, et les deux déclarations renvoient trois tableaux
vides écrits en dur. La recherche globale ne peut structurellement rien renvoyer.

Côté écran, même en supposant l'API réparée :

- `GlobalSearch.tsx:117` — cliquer sur un résultat **Contact** exécute
  `navigate({ to: '/contacts' })` : on atterrit sur la liste complète des contacts, non
  filtrée. On cherchait « Dupont », on obtient tout le monde.
- `GlobalSearch.tsx:133` — cliquer sur un résultat **Tag** exécute `onSelect: () => close()`.
  Le menu se ferme. **Rien d'autre ne se produit.** C'est un élément de résultat qui n'appelle
  rien.
- `GlobalSearch.tsx:31-39` — la requête ne récupère que `data`. Il n'y a **aucune** branche
  d'erreur : une panne serveur affiche « Aucun résultat pour « X » » (ligne 91).

**Impact.** La recherche globale est présente dans l'en-tête de **tous** les écrans
(`Header.tsx:45`), dupliquée dans la modale mobile (`RootLayout.tsx:112`), et la visite guidée
lui consacre une étape entière (« Recherche globale ultra-rapide »,
`OnboardingTour.tsx:36-45`). C'est l'affordance la plus visible du produit, et elle est
entièrement décorative.

**Pourquoi rien ne rougit.** `tests/e2e/global-search.spec.ts:13-21` **mocke** `/search` avec
un résultat fabriqué (`{ id: 1, siren: '111111111', denomination: 'Acme Search Result' }`) et
vérifie qu'il s'affiche. Le test est vert parce qu'il se répond à lui-même. Et il n'est de
toute façon joué par **aucun** workflow (cf. P6-UI-008).

---

### P6-UI-003 · S1 · Trente et un écrans sur trente-cinq n'ont aucune branche d'erreur : toute panne serveur est présentée à l'utilisateur comme « il n'y a rien »

**Preuve — le décompte, sur tout le frontend :**

```
$ grep -rn "isError\b" --include=*.tsx src/ | wc -l
9
$ grep -rn "isError\b" --include=*.tsx src/
src/features/companies/CompanyDetailPage.tsx:62 ,88
src/features/dashboard/components/ActivityFeed.tsx:59 ,93
src/features/international/RoumaniePage.tsx:79 ,166 ,168
src/features/media/MediaDetailPage.tsx:35 ,51
```

Quatre fichiers. Plus `ObservabilityPage.tsx:28` qui utilise `error`. **Cinq écrans sur
trente-cinq.** Partout ailleurs le motif est le même :

```tsx
{isLoading ? <Squelette/> : rows.length === 0 ? <EmptyState title="Aucun …"/> : <liste/>}
```

Il n'existe pas de troisième branche. Un 500, un 403, une coupure réseau tombent tous dans
`rows.length === 0`.

**Ce que l'utilisateur lit alors, écran par écran :**

| Écran | Fichier:ligne | Ce qui s'affiche sur une panne serveur |
|---|---|---|
| Contacts (hub console) | `crm-console/ContactsHubPage.tsx:183-186` | « Aucun contact dans cette vue — Les fiches arrivent automatiquement depuis le site, rien à créer ici. » |
| Vivier candidats | `crm-console/CandidatesPage.tsx:111-114` | « Aucun candidat dans cette vue » — et les quatre vignettes affichent 0, dont « À qualifier », la vignette anti-« 71 candidatures jamais triées » |
| À arbitrer | `crm-console/ArbitragePage.tsx:88-91` | « Rien à arbitrer — Tous les événements entrants ont trouvé leur entreprise. » |
| Fiche 360° | `crm-console/PersonTimelinePage.tsx:46-52` | « Fiche introuvable — Cette personne n'existe dans aucun univers accessible. » |
| Utilisateurs | `users/UsersPage.tsx:101-103` | « Aucun utilisateur » (alors qu'on est connecté) |
| Registre AI Act | `rgpd/AiActRegisterPage.tsx:121-123` | « Aucun système IA enregistré » |
| Rotations | `llm/RotationsPage.tsx:60-62` | « Aucune rotation configurée » |
| LLM Router | `llm/LlmRouterPage.tsx:95-97` | « Aucun cas d'usage configuré » |
| Journalistes | `media/JournalistsListPage.tsx:102-104` | « Aucun journaliste » |
| Contacts (liste) | `contacts/ContactsListPage.tsx:177-182` | « Aucun contact » |
| Tableau de bord | `dashboard/DashboardPage.tsx:137-152` | « Lance ton premier scrape » |
| Activité récente | `dashboard/components/ActivityFeed.tsx:93-98` | « Activité **bientôt disponible** » — une panne déguisée en fonctionnalité pas encore livrée |
| Recherche globale | `components/ui/GlobalSearch.tsx:89-93` | « Aucun résultat pour « X » » |

Et les quatre écrans qui **ont** une branche d'erreur mentent presque tous :

- `companies/CompanyDetailPage.tsx:88-95` : `if (isError || !c)` affiche « Entreprise
  introuvable », titre **« 404 »**, texte « Cette entreprise n'existe pas ou a été
  supprimée. » Un 500 s'affiche donc comme un 404, avec le code d'erreur écrit en toutes
  lettres à l'écran.
- `media/MediaDetailPage.tsx:51-57` : idem, « Média introuvable — Ce média n'existe pas ou a
  été supprimé. »
- `dashboard/components/ActivityFeed.tsx:93` : `isError || items.length === 0` — les deux cas
  fusionnés dans une seule branche, par construction.
- `international/RoumaniePage.tsx:166` est **le seul honnête** de tout le frontend :
  « Impossible de charger le vivier Roumanie. » Sans cause, sans bouton réessayer, mais sans
  mensonge.

**Impact.** Un opérateur ne peut jamais distinguer « la file d'arbitrage est vide » de « le
serveur est tombé ». Sur `ArbitragePage`, dont le fichier explique lui-même en tête que
l'écran existe pour que **refuser de deviner ne revienne pas à perdre la donnée**, une panne
affiche « Tous les événements entrants ont trouvé leur entreprise ». Sur `CandidatesPage`,
l'écran conçu pour qu'on ne laisse plus 71 candidatures dormir, une panne affiche « 0 à
qualifier ». Ces deux écrans deviennent dangereux exactement quand ils devraient alerter.

---

### P6-UI-004 · S1 · Aucun écran ne distingue un refus de droits d'une panne serveur

**Preuve.**

```
$ grep -rn "403" src/features/
src/features/auth/TwoFactorPage.tsx:16        (commentaire)
src/features/crm-console/useConsoleFeatures.ts:12  (commentaire)
src/features/tags/TagsManagerPage.tsx:149     (seul code réel)
```

Une seule occurrence de code dans tout le frontend, et c'est sur une **mutation**, pas sur une
lecture (`TagsManagerPage.tsx:147-152`). L'intercepteur global (`src/lib/api.ts:27-56`) traite
401 (renvoi vers `/login`) et deux codes métier 403 (`two_factor_required`,
`first_login_required`) ; **tous les autres 403 sont relancés tels quels** et retombent dans
la branche « vide » décrite en P6-UI-003.

Conséquences concrètes, vérifiables dans les routes :

- `backend/routes/api.php:216` : `DELETE /tags/{tag}` est gardée par
  `permission:companies.delete`. Un compte `viewer` qui clique la corbeille reçoit un 403 et
  lit `TagsManagerPage.tsx:150` : **« Impossible : tags auto/LLM protégés »**. C'est une
  explication fausse — ce n'est pas le tag qui est protégé, c'est le compte qui n'a pas le
  droit. L'utilisateur cherchera un autre tag au lieu de demander une permission.
- `users/UsersPage.tsx:101` : un compte sans droit sur `/users` lit « Aucun utilisateur ».
- `crm-console/*` : un compte hors univers lit « Aucun candidat dans cette vue ».

**Impact.** Le point 12 de la grille §5.1 (« Permission refusée : explicite, sans cul-de-sac »)
n'est satisfait **nulle part**. Le seul écran qui parle de permissions est `ConsoleGate`, et
il le fait à tort (cf. P6-UI-015).

---

### P6-UI-005 · S1 · `ErrorBoundary` est écrit, exporté, testé en e2e — et monté nulle part

**Preuve.**

```
$ grep -rn "ErrorBoundary" src/
src/components/ui/ErrorBoundary.tsx:6:export class ErrorBoundary extends Component<Props, State> {
src/components/ui/ErrorBoundary.tsx:15:    console.error('ErrorBoundary caught', error, info);
src/components/ui/index.ts:33:export { ErrorBoundary } from './ErrorBoundary';
```

Trois occurrences : la définition, un `console.error`, et la ré-export. **Aucun `<ErrorBoundary>`
dans un JSX.** Ni `main.tsx`, ni `app/RootLayout.tsx`, ni un seul écran ne le monte.

**Impact.** Toute exception de rendu dans l'un des 35 écrans — une propriété manquante dans
une réponse API, un `.map` sur `undefined` — démonte l'arbre React entier. L'utilisateur
obtient une **page blanche**, sans message, sans bouton, sans moyen de revenir. Il ne lui
reste que le bouton « précédent » du navigateur.

**Aggravant.** `tests/e2e/console-locale.spec.ts:378-380` assert :

```ts
await expect(body, "L'ErrorBoundary a capturé une exception de rendu.")
  .not.toContainText('Une erreur est survenue.');
```

Le test cherche la trace d'un garde-fou qui n'est pas là. Comme aucune `ErrorBoundary` n'est
montée, cette assertion est **vraie par construction** : elle ne peut jamais rougir. Un
« rien trouvé » sans témoin, exactement ce que la règle 3 du mandat interdit.

**Défaut secondaire dans le composant lui-même.** `ErrorBoundary.tsx:27` :

```tsx
className={`rounded-xl bg-rose-50 p-${level === 'root' ? 8 : 4} text-rose-900`}
```

Tailwind analyse le source à la recherche de noms de classes **littéraux** ; `p-${…}` n'en
produit aucun. Ni `p-8` ni `p-4` ne sont générés : le jour où on montera ce composant, il
n'aura aucune marge intérieure. Et il n'a aucune variante `dark:`.

---

### P6-UI-006 · S1 · Quatre règles globales `!important` neutralisent le mode sombre de tout le design system — mesuré : le texte des états vides tombe à 2,39:1

**Fichier.** `frontend/src/styles/index.css:88-91`

```css
.dark .bg-white       { background: oklch(0.20 0.01 250) !important; }
.dark .bg-slate-50    { background: oklch(0.17 0.01 250) !important; }
.dark .text-slate-900 { color: oklch(0.95 0 0) !important; }
.dark .border-slate-200 { border-color: oklch(0.30 0.01 250) !important; }
```

**Preuve — j'ai compilé la feuille réelle** (API `compile()` de `tailwindcss` v4, sur
`src/styles/index.css` avec les utilitaires en cause) :

```css
/* dans @layer utilities */
.dark\:bg-slate-900 {
  &:where(.dark, .dark *) { background-color: var(--color-slate-900); }
}

/* HORS de tout @layer, plus bas dans la feuille */
.dark .bg-white { background: oklch(0.20 0.01 250) !important; }
```

```
$ (sortie du compilateur, déclarations de couches)
@layer theme, base, components, utilities;
```

La règle globale gagne pour **trois** raisons cumulées, dont deux suffiraient :

1. `@variant dark (&:where(.dark, .dark *))` (`index.css:4`) place la variante dans un
   `:where()`, **de spécificité zéro** : `.dark\:bg-slate-900:where(…)` pèse (0,1,0), une
   seule classe. `.dark .bg-white` pèse (0,2,0).
2. Les utilitaires sont dans `@layer utilities` ; les quatre règles sont **hors couche**. Une
   règle sans couche l'emporte sur une règle en couche, quelle que soit la spécificité.
3. `!important`.

**Ampleur, mesurée :**

```
$ grep -rnoE "bg-white[^\"'`]*dark:bg-[a-z0-9-]+" --include=*.tsx src/ | wc -l
30
$ grep -rnoE "border-slate-200[^\"'`]*dark:border-[a-z0-9-]+" --include=*.tsx src/ | wc -l
16
```

**30 éléments** déclarent un fond sombre pour le mode sombre et se le font écraser, dans
`Card.tsx`, `Modal.tsx` (donc `Drawer`), `IconButton.tsx`, `SegmentedControl.tsx`,
`Toolbar.tsx`, `GlobalSearch.tsx`, `DarkModeToggle.tsx`, et une quinzaine d'écrans. Le mode
sombre du design system n'est pas celui que les composants déclarent : c'est celui que quatre
lignes de CSS global imposent.

**Et le prix concret : `EmptyState`.** `components/ui/EmptyState.tsx:15-18` n'a **aucune**
classe `dark:` :

```tsx
<div className="… border-slate-200 bg-white …">
  <h2 className="… text-slate-900">{title}</h2>
  <p className="… text-slate-600">{description}</p>
```

Le rustine globale rattrape `bg-white`, `text-slate-900` et `border-slate-200`… et **oublie
`text-slate-600`**. Mesure (conversion oklch→sRGB, formule de contraste WCAG 2.x) :

```
fond forcé par .dark .bg-white = rgb(19, 22, 26)
description  text-slate-600 #475569 sur ce fond : 2.39 : 1   ← AA exige 4.5
titre        forcé en oklch(0.95) sur ce fond   : 15.64 : 1
(référence)  text-slate-600 sur blanc            : 7.58 : 1
```

**Impact.** `EmptyState` est utilisé **27 fois**. C'est le composant qui porte tous les
messages « Aucun X » — c'est-à-dire, d'après P6-UI-003, le texte que l'utilisateur doit lire
quand un écran est vide **ou quand le serveur est tombé**. En mode sombre, ce texte est
illisible. Le titre reste net (15,64:1), l'explication disparaît.

---

### P6-UI-007 · S1 · La porte d'accessibilité de la CI mesure quatre écrans, dont trois sans session, et n'asserte que sur `critical`

**Fichiers.** `.github/workflows/a11y.yml:39-48` · `frontend/tests/e2e/a11y.spec.ts` ·
`frontend/playwright.config.ts`

**Trois défauts, cumulatifs.**

**(a) Quatre écrans sur trente-cinq.** `a11y.spec.ts:4-9` : `/login`, `/companies`,
`/coverage`, `/rgpd/requests`. Onze pour cent du périmètre. Le mandat exige les 39.

**(b) Trois de ces quatre écrans sont mesurés sans session, sur une API qui n'existe pas.**

```
$ ls -a frontend/.env*        → aucun fichier
```

Donc `VITE_API_BASE_URL` est indéfini au build de la CI, et `src/lib/api.ts:3` retombe sur
`https://api.localhost`, qui ne résout pas sur un runner GitHub. Il n'existe par ailleurs ni
`storageState`, ni `globalSetup`, ni étape de connexion :

```
$ grep -rn "storageState\|globalSetup" frontend/tests/ frontend/playwright.config.ts
(aucun résultat)
```

`a11y.spec.ts` ne mocke rien (contrairement à `navigation.spec.ts`). Les trois écrans
protégés sont donc scannés avec **toutes leurs requêtes en échec** : coquille montée,
tableaux vides, aucun formulaire peuplé, aucune ligne, aucune modale. Le garde-fou de la
ligne 19 (`expect(page.locator('#root')).not.toBeEmpty()`) prouve seulement que la coquille
React a monté — pas que l'écran a du contenu. Son commentaire (« sans ce contrôle, la porte
serait VERTE précisément quand elle ne mesure rien ») décrit une protection plus forte que
celle qu'il apporte.

**(c) L'assertion ne porte que sur `critical`.** `a11y.spec.ts:25-26` :

```ts
const critical = results.violations.filter((v) => v.impact === 'critical');
expect(critical).toEqual([]);
```

Tout ce qu'axe-core classe `serious`, `moderate` ou `minor` passe. **Le contraste de couleur
est `serious`.** Or il y a des échecs de contraste réels, sur les pages mesurées :

```
$ node -e "…formule WCAG…"
slate-400 #94a3b8 sur blanc : 2.56 : 1        ← AA exige 4.5 (et 3.0 même pour le grand texte)
slate-500 #64748b sur blanc : 4.76 : 1        (passe)

$ grep -rno "text-slate-400" src/ | wc -l
213
$ grep -c "text-slate-400" src/features/companies/CompaniesListPage.tsx \
    src/features/coverage/CoveragePage.tsx src/features/rgpd/RgpdRequestsPage.tsx \
    src/features/auth/LoginPage.tsx
4 / 2 / 2 / 3
```

**213 usages** de `text-slate-400` à 2,56:1, dont onze sur les quatre pages précisément
scannées par la porte. La porte est verte, et le défaut est là, sous ses yeux, sur les mêmes
URL.

**(d) Un seul navigateur.** `a11y.yml:48` et `:58` : `--project=chromium`.
`playwright.config.ts:26` déclare pourtant un projet `mobile-safari` (iPhone 14, 390 px).
**Il n'est jamais joué.** Le point 22 de la grille (« Responsive 375 px : aucun débordement
horizontal, cibles tactiles suffisantes ») n'est mesuré par rien.

---

### P6-UI-008 · S1 · Quatorze des seize suites e2e ne sont jouées par aucun workflow

**Preuve.**

```
$ ls frontend/tests/e2e/*.spec.ts | wc -l
16
$ grep -rn "playwright\|e2e" .github/workflows/*.yml | grep -v a11y.yml
ci.yml:488  (commentaire)
ci.yml:495  (comparaison de versions de paquets, pas une exécution)
```

Seul `a11y.yml` lance Playwright, et il ne lance nommément que **deux** fichiers :
`a11y.spec.ts` (ligne 48) et `navigation.spec.ts` (ligne 58). Le job `frontend` de `ci.yml`
(lignes 435-464) joue `typecheck`, `lint`, `pnpm test` (Vitest) et `build` — pas Playwright.

Ne tournent donc **nulle part** : `auth`, `campaigns-wizard`, `audiences-builder`,
`companies`, `console-locale`, `coverage`, `dark-mode`, `dashboard`, `global-search`, `llm`,
`onboarding`, `rgpd`, `settings`, `tags-manager`.

**Impact.** Ce sont exactement les suites qui couvrent les parcours du §11 du mandat
(l'assistant de campagne, le constructeur d'audiences, le mode sombre, la visite guidée). Le
commentaire de `a11y.yml:49-52` raconte que `navigation.spec.ts` a été **rouge en silence
depuis la PR #84** parce qu'il n'était exécuté nulle part. Quatorze fichiers sont aujourd'hui
dans cette situation exacte, et rien ne dit lesquels sont verts.

---

### P6-UI-009 · S1 · La cloche de notifications n'appelle rien — alors qu'une API de notifications existe

**Fichier.** `frontend/src/components/layout/Header.tsx:62-70`

```tsx
<IconButton label="Notifications" variant="ghost" size="sm" …>
  <Bell className="h-4 w-4" />
</IconButton>
```

Pas de `onClick`. Pas de `to`. Pas de compteur. **Le bouton ne fait rien**, sur tous les
écrans, à chaque clic.

Pendant ce temps, le backend expose bel et bien
`GET /notifications`, `POST /notifications/{n}/read` et `POST /notifications/read-all`
(`backend/routes/api.php:238-240`), et `RootLayout.tsx:39-44` s'abonne à un canal temps réel
`workspace.{id}`. Mais `src/lib/echo.ts:39-41` neutralise l'abonnement par défaut
(`VITE_ECHO_ENABLED !== 'true'` → `initEcho()` renvoie `null`).

**Impact.** Il existe une API de notifications, un canal temps réel, et une cloche dans
l'en-tête. Aucun des trois n'est relié aux deux autres. L'utilisateur voit une cloche, la
clique, et rien ne se passe — sans jamais savoir s'il a des notifications en attente. Aucune
route de l'application ne consomme `/notifications` :

```
$ grep -rn "notifications" src/ | grep -v echo.ts
(aucun appel API)
```

---

### P6-UI-010 · S1 · L'écran « Paramètres » : quatorze boutons inertes, un secret décoratif, des statuts écrits en dur, une saisie perdue en silence, huit liens vers `localhost`, et un plafond de dépense qui tombe à 0 €

**Fichier.** `frontend/src/features/settings/SettingsPage.tsx` (307 lignes)

Le mandat exige d'auditer cet écran « chaque onglet, chaque champ ». Il en a quatre.

**Onglet Intégrations (lignes 190-220).**

- Lignes 209-214 : chaque carte porte un bouton **« Renouveler »** et un bouton
  **« Configurer »**. Ni l'un ni l'autre n'a de `onClick`. Sept intégrations × 2 =
  **quatorze boutons qui ne font rien.**
- Ligne 207 : `<MaskedSecret value="sk-•••••" label={i.env} />` — la valeur est une **chaîne
  littérale**. Le bouton œil (lignes 84-91), dont l'`aria-label` promet « Afficher
  `INSEE_API_KEY` », révèle `sk-•••••`. Un contrôle de sécurité qui ne montre rien de réel.
- Lignes 48-57 : `INTEGRATIONS` est une **constante de module**. Le statut « Configuré » /
  « Optionnel » / « À configurer » est écrit en dur, jamais lu du serveur. L'écran affirme
  « INSEE Sirene : Configuré » que la clé soit posée ou non. Un écran de configuration qui
  ment sur l'état de la configuration.

**Onglet Observabilité (lignes 222-259).**

- Ligne 233 : `<Input placeholder="https://xxx@sentry.io/yyy" defaultValue="" />`. Pas de
  `name`, pas de `<form>`, pas de bouton d'enregistrement, pas d'`onChange`. **Ce que
  l'utilisateur tape est perdu** au premier changement d'onglet, sans un mot. C'est le
  « refus silencieux » que le point 18 de la grille interdit nommément.
- Lignes 59-68 : les huit liens d'outillage pointent sur `http://localhost:9090`,
  `:3000`, `:3100`, `:3200`, `:8080`, `:3001`. En production, ces liens s'ouvrent sur **la
  machine de l'utilisateur** et échouent tous. Ils s'affichent en `target="_blank"` avec
  l'URL écrite en toutes lettres sous le titre (ligne 254).

**Onglet Apparence (lignes 261-303).**

- Lignes 286-301 : le sélecteur de densité « Confortable / Compacte » écrit dans un
  `useState` local (ligne 99). `density` n'est **jamais lu ailleurs** dans le fichier, jamais
  persisté, jamais transmis à une liste. Le bouton change d'apparence, rien d'autre ne change.

**Onglet Workspace (lignes 131-188) — deux défauts sérieux.**

- Ligne 184-186 : si `/workspace` échoue, `ws.data` est `undefined` et l'écran affiche
  **« Chargement… » indéfiniment**. Pas d'erreur, pas de réessai. Un chargement infini, ce
  qui est pire qu'un message d'erreur : l'utilisateur attend.
- Lignes 145-148 :

  ```tsx
  updateMut.mutate({
    name: String(fd.get('name') ?? ''),
    cost_cap_eur: Number(fd.get('cost_cap_eur') ?? 0),
  });
  ```

  Un champ `<input type="number">` vidé renvoie `''`, et `Number('') === 0`. **Vider le champ
  et enregistrer pose le plafond LLM mensuel à 0 €.** Or ce champ porte, ligne 174, sa propre
  description : « Kill-switch automatique LLM quand atteint ». Aucun `min`, aucune
  validation, aucune confirmation. Un opérateur qui efface le champ pour le retaper et clique
  Entrée coupe l'IA du workspace.

**Manque.** Le menu utilisateur propose « **Profil** » (`UserMenu.tsx:54-59`) qui navigue vers
`/settings` — exactement comme l'entrée « Paramètres » juste en dessous
(`UserMenu.tsx:60-65`). Deux entrées, une destination. **Il n'existe aucun écran de profil**,
et aucun onglet « Compte » ici : un utilisateur qui veut changer son mot de passe ou activer
sa 2FA n'a nulle part où aller — alors que la visite guidée le lui demande explicitement
(`OnboardingTour.tsx:66`).

---

### P6-UI-011 · S1 · La visite guidée saute deux de ses sept étapes, et la croix qu'elle annonce comme sortie ne fait pas sortir

**Fichiers.** `frontend/src/components/OnboardingTour.tsx` · `src/components/layout/Sidebar.tsx:307`

**(a) Deux étapes visent des éléments masqués.** La barre latérale est un **accordéon** : une
seule section ouverte à la fois, et les autres listes portent `hidden` (donc
`display: none`) :

```tsx
// Sidebar.tsx:307
<ul id={idListe} className={cn('flex flex-col gap-0.5', !deplie && 'hidden')}>
```

La section ouverte au démarrage est celle de la page courante (`Sidebar.tsx:181-184`). La
visite se déclenche sur `/` (`OnboardingTour.tsx:99-107`), donc la section ouverte est
« Aujourd'hui », qui ne contient que `nav-dashboard`.

Or les étapes 4 et 7 visent `[data-tour="nav-companies"]` (section « Contacts ») et
`[data-tour="nav-settings"]` (section « Réglages ») — **tous deux dans un `<ul>` masqué**.

Comportement de la bibliothèque, lu dans le paquet installé
(`node_modules/react-joyride/dist/index.js:1841-1849`, version 2.9.3) :

```js
console.warn(elementExists ? "Target not visible" : "Target not mounted", step);
callback({ ...state, type: EVENTS.TARGET_NOT_FOUND, step });
if (!controlled) { store.update({ index: index + (action === ACTIONS.PREV ? -1 : 1) }); }
```

La visite **saute silencieusement** l'étape. `handleCallback` (lignes 109-116) ne traite pas
`EVENTS.TARGET_NOT_FOUND` : rien n'est journalisé côté application, rien n'est signalé.

**Résultat, sur poste de bureau :** l'étape « parcourir vos entreprises enrichies » et
l'étape finale — celle qui dit « Pensez à activer la double authentification » et « Bon
démarrage » — **ne s'affichent jamais**. Sur mobile, c'est pire : `RootLayout.tsx:77` rend la
barre latérale `hidden lg:flex` et `Header.tsx:45` rend la recherche `hidden … md:block` :
quatre des sept étapes visent alors des éléments masqués.

**(b) La croix n'arrête pas la visite.** L'étape d'accueil affirme (lignes 23-24) :
« Vous pouvez quitter à tout moment via la croix. » Le magasin de react-joyride
(`dist/index.js:822-829`) traite la fermeture ainsi :

```js
__publicField(this, "close", (origin = null) => {
  const { index, status } = this.getState();
  if (status !== STATUS.RUNNING) return;
  this.setState({ ...this.getNextState({ action: ACTIONS.CLOSE, index: index + 1, origin }) });
});
```

`index + 1` : la croix **avance d'une étape**. Elle ne quitte pas. Et `handleCallback`
(lignes 111-115) ne consigne l'achèvement que sur `STATUS.FINISHED` ou `STATUS.SKIPPED` :
aucune branche pour `ACTIONS.CLOSE`. La seule sortie réelle est le bouton « Passer ».

**(c) L'étape 5 décrit un écran qui n'existe pas.** Ligne 54 : « Le dashboard affiche vos
KPIs : entreprises totales, contacts valides, taux de succès des scrapers. » D'après
P6-UI-001, le tableau de bord n'affiche jamais rien de tel. La première chose que l'outil
enseigne à un nouvel arrivant est fausse.

---

### P6-UI-012 · S1 · Aucun écran ne met son état dans l'URL : un filtre ne se partage pas, ne survit pas au rechargement, et le retour arrière perd la position

**Preuve.**

```
$ grep -rn "useSearch\|validateSearch\|navigate({.*search" --include=*.tsx --include=*.ts src/
(aucun résultat — les seules correspondances de "search:" sont des paramètres axios
 et des useState de champ de recherche)
$ grep -rln "useState" src/features/*/[A-Z]*.tsx | wc -l
29
```

Vingt-neuf écrans construisent leurs filtres, leurs onglets, leur page courante et leur tri
en `useState`. Aucun n'utilise l'API de recherche d'URL de TanStack Router, alors qu'elle est
disponible (`@tanstack/react-router` v1.170).

**Impact quotidien, point 3 et point 7 de la grille.**

- Un opérateur filtre `Contacts → Presse → base froide → pays Roumanie`, ouvre une fiche,
  revient : **il retrouve la vue par défaut**, tout est à refaire.
- Il ne peut pas envoyer « regarde ces 40 fiches » à un collègue : l'URL ne porte rien.
- Un rechargement (F5, redéploiement, expiration de session) efface le travail de tri.
- `CompaniesListPage.tsx:751` gère une pagination — mais `page` étant dans un `useState`
  (non vérifié dans l'URL), revenir d'une fiche renvoie page 1.

---

### P6-UI-013 · S1 · Sept listes sont plafonnées à 50 ou 100 lignes, sans aucun moyen d'aller plus loin — alors que le curseur est renvoyé par l'API

**Preuve.**

```
$ grep -rn "per_page" src/features/
contacts/ContactsListPage.tsx:88      per_page: '50'
crm-console/ArbitragePage.tsx:36      /crm/arbitrage?per_page=50
crm-console/CandidatesPage.tsx:58     per_page: '50'
crm-console/ContactsHubPage.tsx:70    per_page: '50'
campaigns/CampaignsListPage.tsx:63    per_page: 50
international/RoumaniePage.tsx:85     per_page: '50'
media/JournalistsListPage.tsx:55      per_page: "100"
scraping/ScraperRunsPage.tsx:214      /scraper-runs?per_page=50
```

```
$ grep -rn "next_cursor" src/
src/features/crm-console/types.ts:93:  next_cursor: string | null;
src/features/crm-console/types.ts:94:  prev_cursor: string | null;
```

`next_cursor` et `prev_cursor` sont **déclarés dans le type et jamais lus par un seul
écran**. Le composant `Pagination` existe (`features/companies/components/Pagination.tsx`) et
n'est monté que dans **un** écran (`CompaniesListPage.tsx:751`).

**Impact, point 8 de la grille (« que se passe-t-il à 0, 1, 100, 10 000, 100 000 lignes ? »).**
Sur le hub de contacts, adossé à 4,29 M de fiches, l'utilisateur voit **cinquante lignes**.
Il n'y a ni bouton « suivant », ni défilement infini, ni indication que la liste est tronquée
— le décompte total est affiché dans les vignettes du haut, ce qui rend la troncature encore
plus trompeuse : on lit « 4 294 898 » et on voit cinquante lignes. Le 51ᵉ candidat du vivier
est inatteignable autrement qu'en devinant un terme de recherche.

---

### P6-UI-014 · S2 · La fiche 360° — « l'écran le plus consulté » du mandat — annonce qu'une personne n'existe pas quand c'est le serveur qui a échoué

**Fichier.** `frontend/src/features/crm-console/PersonTimelinePage.tsx:45-52`

```tsx
const data = timeline.data;
if (data === undefined) {
  return <EmptyState title="Fiche introuvable"
           description="Cette personne n'existe dans aucun univers accessible." />;
}
```

`data === undefined` couvre indistinctement : le 404 (la personne n'existe pas), le 403 (pas
le droit), le 500 (le serveur a planté), et la coupure réseau. Aucun `isError`.

**Impact — et il est nommé par le fichier lui-même.** L'en-tête de ce même fichier
(lignes 8-12) explique pourquoi l'encart « existe aussi dans l'autre univers » est
indispensable :

> « sans lui, un opérateur business créerait une seconde fiche pour quelqu'un qui en a déjà
> une — l'étanchéité produirait des doublons au lieu de protéger. »

C'est exactement ce que produit la ligne 49 : sur une panne, l'écran affirme que la personne
n'existe **dans aucun univers accessible**, ce qui est la formulation la plus propre à
pousser l'opérateur à recréer la fiche. Le raisonnement anti-doublon du fichier est annulé
par la branche d'erreur du même fichier.

**Défaut de forme, même écran.** Ligne 114 : `{entry.occurred_at ?? '—'}` — la date est
rendue **brute**, telle qu'elle sort de l'API (horodatage ISO). `CandidatesPage.tsx:32-36`
définit pourtant un `formatDate` en `fr-FR`. Deux écrans de la même console, deux formats de
date. `ArbitragePage.tsx:132` est également brut. Point 6 de la grille.

---

### P6-UI-015 · S2 · `ConsoleGate` affirme « Console non activée » quand il ne sait rien — et la barre latérale change de forme sur une panne

**Fichiers.** `frontend/src/features/crm-console/ConsoleGate.tsx:20-44` ·
`src/features/crm-console/useConsoleFeatures.ts:35-52` · `src/components/layout/Sidebar.tsx:144-162`

`useConsoleFeaturesQuery` pose `retry: false` (ligne 42). Si `/config/features` répond 500 ou
si le réseau tombe, `isPending` passe à faux et `data` reste `undefined`. `ConsoleGate.tsx:21`
retombe alors sur `CONSOLE_FEATURES_CLOSED` et affiche (lignes 38-41) :

> « **Console non activée** — La console CRM v2 n'est pas ouverte sur ce serveur. »

C'est une **affirmation sur la configuration du serveur**, faite à partir d'une requête qui a
échoué. Le fichier prend pourtant explicitement soin (lignes 23-26) de ne pas affirmer
pendant le chargement — la même prudence s'arrête net au cas de l'erreur.

**Conséquence en cascade dans la navigation.** `Sidebar.tsx:145` construit la section
« Contacts » à partir du même drapeau. Sur une panne de `/config/features`, la barre latérale
bascule silencieusement de trois entrées (`/console/contacts`, `/console/vivier`,
`/console/arbitrage`) à **une seule** (`/contacts`, l'ancienne liste). L'utilisateur voit son
menu changer de forme sans explication, et ses signets `/console/*` affichent « Console non
activée ».

**Effet sur les tests.** `tests/e2e/navigation.spec.ts:25-60` ne mocke pas
`/config/features`. La branche « console ouverte » de `sectionContacts` n'est donc jamais
exercée par ce test — y compris par son assertion phare « une seule entrée Contacts »
(ligne 144), qui ne vérifie que la branche fermée.

---

### P6-UI-016 · S2 · Coordonnées en clair sur cinq écrans, là où trois contrôleurs les masquent

**Preuve, côté backend :**

```
$ grep -rln "MasquageCoordonnees" backend/app/
backend/app/Http/Controllers/Api/CompaniesController.php
backend/app/Http/Controllers/Api/ContactsController.php
backend/app/Http/Controllers/Api/Crm/ContactsHubController.php
backend/app/Support/MasquageCoordonnees.php
```

Le masquage (`p***@acme.fr`, permission `contacts.view_pii`) couvre **trois** contrôleurs. Il
ne couvre pas :

```
$ for f in Crm/ArbitrageController Crm/PersonTimelineController Crm/CandidatesController \
           GlobalSearchController AudiencesController; do grep -c Masquage …; done
ArbitrageController      : 0
PersonTimelineController : 0
CandidatesController     : 0
GlobalSearchController   : 0
AudiencesController      : 0
```

**Preuve, côté frontend :**

```
$ grep -rni "view_pii\|masqu.*coordonn\|\bPII\b" src/
(rien — sauf « Masquer le mot de passe » et « Masquer ${label} » dans Settings)
```

Le frontend **n'a aucune notion** de cette permission. Les écrans affichent ce qu'on leur
donne :

- `crm-console/ArbitragePage.tsx:136` — `<Field label="E-mail" value={match.email} />`
- `crm-console/PersonTimelinePage.tsx:79` — `<div>{subject.email ?? '—'}</div>`
- `audiences/AudienceDetailPage.tsx:249-254` — adresse en `font-mono`, en clair
- `components/ui/GlobalSearch.tsx:121` — adresse de chaque contact dans la palette ⌘K

**Impact.** Un compte `viewer`, censé être en lecture réduite, lit une adresse masquée
`p***@acme.fr` sur `/contacts` et l'adresse complète de la même personne sur sa fiche 360° ou
dans le détail d'une audience. La minimisation n'est pas appliquée par écran mais par
contrôleur, et le frontend ne dit jamais qu'une valeur a été masquée : sur
`contacts/ContactsListPage.tsx:239`, l'adresse masquée est **cliquable** en `mailto:` — un
lien qui ouvre le client de messagerie sur `p***@acme.fr`.

---

### P6-UI-017 · S2 · Le fil d'Ariane parle anglais sur au moins cinq écrans

**Fichier.** `frontend/src/components/layout/AutoBreadcrumbs.tsx:11-38`

La table `LABELS` couvre 17 chemins. Elle en oublie une quinzaine ; le repli est `humanize()`
(lignes 33-38), qui met la première lettre en majuscule et remplace les tirets par des
espaces. Résultat :

| URL | Fil d'Ariane rendu |
|---|---|
| `/journalists` | Accueil › **Journalists** |
| `/admin/observability` | Accueil › Admin › **Observability** |
| `/campaigns/new` | Accueil › Collectes › **New** |
| `/audiences/new` | Accueil › Audiences › **New** |
| `/media` · `/media/42` | Accueil › **Media** › #42 |
| `/console/personnes/abc` | Accueil › **Console** › Personnes › Abc |

Six libellés anglais ou techniques dans un produit dont le sous-titre est « Console interne de
prospection B2B automatisée », et alors que la barre latérale, elle, dit bien
« Journalistes », « Observabilité », « Médias (presse) ». Le menu et le fil d'Ariane ne
nomment pas la même chose. Point 2 et point 3 de la grille.

**Résidu.** Les lignes 28-29 conservent `'/cold-email': 'E-mails à froid'` et
`'/linkedin': 'Prospection LinkedIn'` — les libellés d'entrées de menu retirées.

---

### P6-UI-018 · S2 · L'internationalisation couvre 27 clés et 5 fichiers sur 37 — et la détection de langue est active, sans sélecteur

**Preuve.**

```
$ node … flat(fr.json) / flat(en.json)
clés fr: 27   clés en: 27   divergences: []

$ grep -rln "useTranslation" --include=*.tsx src/
src/features/auth/LoginPage.tsx
src/features/auth/MagicLinkPage.tsx
src/features/auth/TwoFactorPage.tsx
src/features/phase2-scaffold/ColdEmailStub.tsx
src/features/phase2-scaffold/LinkedInStub.tsx
```

Cinq fichiers sur trente-sept. Les trente-deux autres écrans ont **100 % de leurs chaînes en
dur**, en français. Le point 24 de la grille (« aucune chaîne en dur ») est violé partout sauf
sur trois écrans d'authentification et deux bouchons.

**Le défaut visible.** `src/lib/i18n.ts:17` :

```ts
detection: { order: ['localStorage', 'navigator'], caches: ['localStorage'] },
```

La détection navigateur est **active**. Un poste dont le navigateur est en `en-US` obtient
donc la page de connexion, la page de lien magique et la page 2FA **en anglais**, puis
bascule en français dès la première page métier. Et il n'existe aucun sélecteur de langue
pour en sortir :

```
$ grep -rn "changeLanguage" src/
(aucun résultat)
```

**Détail.** `locales/fr.json` contient `"scraperRuns": "Scraper runs"` — de l'anglais dans le
fichier français ; la clé n'est de toute façon utilisée nulle part, la barre latérale codant
ses libellés en dur.

---

### P6-UI-019 · S2 · La porte BLOQUANTE `pnpm lint` est rouge sur la référence mesurée, et les seize erreurs sont toutes dans les fichiers que cette branche a touchés

**Commandes jouées.**

```
$ npx tsc --noEmit            → exit 0
$ npx eslint . --max-warnings 0
src/features/auth/TwoFactorPage.tsx  : 4 erreurs  (no-misused-promises, l. 171/198/204/257)
src/lib/api.ts                       : 9 erreurs  (no-unsafe-assignment, no-unsafe-member-access,
                                                   prefer-promise-reject-errors, l. 3/30/31/35/45/52/55)
tests/components/ActivityFeed.test.tsx        : 1 erreur
tests/components/TwoFactorEnrolement.test.tsx : 2 erreurs
✖ 16 problems (16 errors, 0 warnings)
```

`.github/workflows/ci.yml:454-456` :

```yaml
      - name: Lint (BLOQUANT)
        working-directory: frontend
        run: pnpm lint
```

et `package.json` : `"lint": "eslint . --max-warnings 0"`. **Le job `frontend` de la CI est
donc rouge sur cette branche.**

Les seize erreurs sont dans **exactement les cinq fichiers** que `main..HEAD` modifie
(cf. §0). Le mécanisme de dette gelée fonctionne comme prévu — `eslint-suppressions.json`
(natif ESLint ≥ 9.24) fige 73 violations dont 57 sont bien supprimées — et
`eslint-suppressions.README.md` énonce la règle : « Le nouveau code doit être propre : toute
violation qui n'est pas déjà dans `eslint-suppressions.json` fait rougir la CI. » C'est
précisément ce qui s'est produit, et ça n'a pas été vu.

**Impact.** La branche qui referme les constats d'authentification ne franchit pas sa propre
porte bloquante. Elle ne peut pas être fusionnée en l'état sans que quelqu'un désactive la
porte — le geste exact que le durcissement du 13 août visait à empêcher.

---

### P6-UI-020 · S2 · Modales et tiroirs sans piège de focus ni nom accessible ; onglets sans navigation au clavier

**Fichier.** `frontend/src/components/ui/Modal.tsx` (Modal + Drawer) · `src/components/ui/Tabs.tsx`

**Modal / Drawer** (`Modal.tsx:42-71` et `:105-130`) :

- `role="dialog" aria-modal="true"` mais **aucun `aria-labelledby`** : le `<h2>` du titre
  (ligne 60) n'a pas d'`id` et n'est pas rattaché. Une technologie d'assistance annonce un
  dialogue sans nom.
- **Aucun piège de focus.** Le `ref` déclaré ligne 25 et posé ligne 50 n'est utilisé nulle
  part. La touche Tab sort du dialogue et parcourt la page située derrière, qui n'est ni
  `inert` ni `aria-hidden`.
- **Aucun déplacement du focus à l'ouverture**, aucune restauration à la fermeture.

Ces composants portent le tiroir de navigation mobile et la modale de recherche mobile de
`RootLayout.tsx:82-91,106-113` : au clavier ou au lecteur d'écran, la navigation mobile n'est
pas praticable.

**Tabs** (`Tabs.tsx:21-47` et `:52-81`) : `role="tablist"` + `role="tab"` + `aria-selected`,
mais **aucun `role="tabpanel"`**, aucun `aria-controls`, aucun `id`, et surtout **aucune
gestion des flèches ←/→ ni de `tabindex` mobile**. Le modèle ARIA de l'onglet impose la
navigation par flèches ; ici chaque onglet est un bouton dans l'ordre de tabulation. Utilisé
sur `ContactsHubPage` (12 onglets), `CandidatesPage`, `SettingsPage`.

**Pourquoi la CI ne le dit pas.** Ces défauts sont soit non détectables par axe-core
(navigation clavier des onglets, piège de focus), soit classés en dessous de `critical` — et
de toute façon aucun des trois écrans concernés n'est dans les quatre pages scannées
(cf. P6-UI-007).

---

### P6-UI-021 · S2 · L'écran d'arbitrage exige de saisir un identifiant numérique de base de données, et ses deux actions sont irréversibles sans confirmation

**Fichier.** `frontend/src/features/crm-console/ArbitragePage.tsx:141-177`

```tsx
<label …>Identifiant d'entreprise
  <Input value={companyId} … placeholder="ex. 1842" inputMode="numeric" /></label>
<Button variant="primary" size="sm"
  disabled={busy || Number.isNaN(parsedCompanyId)}
  onClick={() => onAttach(parsedCompanyId)}>Rattacher</Button>
```

Il n'y a **aucun sélecteur d'entreprise**, aucune recherche, aucune suggestion. L'opérateur
doit connaître ou aller chercher la clé primaire numérique de la fiche cible.

- Point 25 de la grille (« un utilisateur non formé comprend-il cet écran ? ») : non. Le seul
  endroit où cet identifiant est visible est l'URL d'une fiche entreprise
  (`/companies/1842`) — il faut savoir le lire là.
- Point 16 (« annulation possible après action destructrice ») : les deux actions
  — « Rattacher » (crée ou retrouve une fiche personne) et « Écarter » (sort l'événement de
  la file) — partent au clic, **sans confirmation et sans annulation**. Un rattachement erroné
  n'a pas de chemin de retour dans l'interface.
- `Number.parseInt('12abc', 10)` vaut `12` : la saisie `12abc` n'est pas rejetée, elle est
  silencieusement tronquée.

---

### P6-UI-022 · S3 · Cinq écrans annoncent leur objet en jargon technique, dont deux en anglais

Point 2 de la grille : « le titre dit-il ce qu'on fait ici, **en français courant, sans terme
technique** ? »

| Écran | Fichier:ligne | Sous-titre affiché |
|---|---|---|
| Utilisateurs | `users/UsersPage.tsx:86` | « 4 rôles RBAC : owner / admin / operator / viewer (**Spatie Permission teams**) » — le nom d'une bibliothèque PHP, affiché à l'utilisateur |
| LLM Router | `llm/LlmRouterPage.tsx:84` | « 9 use cases × 5 providers + **fallback chain** + **cost tracking** + **idempotency cache 24h** » |
| Rotations | `llm/RotationsPage.tsx:54` | « 5 dimensions de rotation : proxies + **user-agents** + **targets** + moteurs de recherche + LLM providers » |
| Observabilité | `observability/ObservabilityPage.tsx:54,129` | « Santé pipeline **waterfall**, quota Hunter, archivages, échecs **audience refresh** » · « 50 derniers **business events** » |
| Tags | `tags/TagsManagerPage.tsx:175` | « Classification multi-axes des entreprises (géographie, secteur, taille, **intent**, custom) » |
| Registre AI Act | `rgpd/AiActRegisterPage.tsx:82` | « Conformité UE 2024/1689 — … » (un numéro de règlement en guise d'explication) |

---

### P6-UI-023 · S3 · La page 404 sort de la coquille : l'utilisateur perd toute sa navigation

**Fichiers.** `frontend/src/app/routeTree.tsx:107,148` · `src/features/misc/NotFoundPage.tsx`

Le fourre-tout est rattaché à `rootRoute`, **pas** à `layoutRoute` :

```tsx
const notFoundRoute = createRoute({ getParentRoute: () => rootRoute, path: '/*', component: NotFoundPage });
```

Une URL inconnue rend donc `NotFoundPage` **sans barre latérale, sans en-tête, sans fil
d'Ariane, sans menu utilisateur** — un « 404 » centré et un unique lien. L'utilisateur qui se
trompe d'URL, ou qui suit un signet devenu obsolète (`/crm`, `/analytics`, retirés en F7),
perd d'un coup l'accès à toute l'application et doit repasser par l'accueil.

---

### P6-UI-024 · S3 · Deux écrans bouchons restent joignables par URL, avec des titres en anglais

**Fichiers.** `frontend/src/app/routeTree.tsx:102-103,145-146` ·
`src/features/phase2-scaffold/{ColdEmailStub,LinkedInStub}.tsx`

Le croisement menu ↔ routes (commande en §3) montre que **plus aucune entrée de menu ne mène
à un cadenas ou à un bouchon** — c'est acquis, et c'est bien. Mais les deux routes subsistent
et rendent :

```tsx
<PageShell title="Cold email" subtitle={t('phase2.stub.title')}>       // ColdEmailStub.tsx:7
<PageShell title="LinkedIn outreach" subtitle={t('phase2.stub.title')}> // LinkedInStub.tsx:7
```

Deux titres **en anglais, écrits en dur**, dans les seuls fichiers du frontend (avec les
écrans d'authentification) qui utilisent l'i18n. Le corps affiche
`phase2.stub.description` = « Ce module est **scaffoldé** : DB + UI prêtes, logique métier
reportée à la Phase 2. » — du jargon de développeur adressé à un opérateur. Côté API,
`/cold-email` et `/linkedin` répondent toujours **501** (`backend/routes/api.php:338`).

Le fil d'Ariane continue par ailleurs de leur donner un nom français
(`AutoBreadcrumbs.tsx:28-29`), reliquat du menu d'avant.

---

### P6-UI-025 · S3 · Les champs de recherche émettent une requête par frappe, sans anti-rebond

**Fichier.** `frontend/src/components/ui/Toolbar.tsx:30-35` (`SearchInput`) —
`onChange={(e) => onChange(e.target.value)}`, sans délai.

Les consommateurs placent la valeur directement dans la clé de requête :
`ContactsHubPage.tsx:68`, `CandidatesPage.tsx:56`, `CampaignsListPage.tsx:63`,
`JournalistsListPage.tsx`, `MediaListPage.tsx`.

Taper « Dubois » = **six requêtes** successives sur la table de 4,29 M de fiches. Le seul
garde-fou existant est celui de `GlobalSearch.tsx:37` (`search.length >= 2`), qui borne le
seuil, pas la cadence.

Défaut connexe : `SearchInput` n'a **ni `<label>` ni `aria-label`**. Son seul intitulé est le
`placeholder`, qui disparaît à la saisie. `ContactsHubPage.tsx:125,137` prend soin de poser
`aria-label` sur ses deux `<select>` voisins — pas sur le champ de recherche.

---

### P6-UI-026 · S3 · Squelettes et états vides recopiés à la main dans trois fichiers

Point 20 de la grille. Réutilisation globalement bonne — `<Skeleton>` 27 fois, `<EmptyState>`
27 fois — mais trois fichiers rejouent l'animation à la main
(`coverage/CoveragePage.tsx`, `dashboard/components/ActivityFeed.tsx`,
`dashboard/components/TopDeptsCard.tsx`, cinq occurrences de `animate-pulse` hors
`Skeleton.tsx`), et huit états vides sont écrits en `<p>` ou `<div>` plutôt qu'en
`<EmptyState>` (`GlobalSearch.tsx:91`, `AudienceDetailPage.tsx:213`,
`CampaignDetailPage.tsx:285,416`, `CampaignWizardPage.tsx:526`,
`EnrichmentTimeline.tsx:48`, `PersonTimelinePage.tsx:70`, `RoumaniePage.tsx:231`,
`RotationsPage.tsx:80`, `MediaDetailPage.tsx:198`). Ce sont ces derniers qui échappent, au
passage, à tout futur correctif de contraste sur `EmptyState`.

---

## 3. MES TÉMOINS NÉGATIFS

Un « rien trouvé » ne vaut rien sans la preuve que le contrôle aurait vu le défaut. Voici les
cinq que j'ai construits.

### 3.1 — Croisement menu ↔ routes : **aucune entrée de menu ne mène nulle part**

```
$ grep -oE "path: '[^']+'" src/app/routeTree.tsx | … | sort > routes.txt   (35 routes)
$ grep -oE "to: '[^']+'"  src/components/layout/Sidebar.tsx | … | sort -u > menu.txt  (23 entrées)
$ comm -13 routes.txt menu.txt     # entrées de menu SANS route
(vide)
```

**Résultat : rien.** Chacune des 23 entrées de la barre latérale pointe sur une route
déclarée. Le mandat annonçait « six entrées verrouillées vers quatre routes 501 » : elles ont
disparu de la barre, et le test `navigation.spec.ts:138-141` en garde la trace.

**Témoin.** Le même croisement, dans l'autre sens (`comm -23`), **a bien trouvé quelque
chose** : quatorze routes sans entrée de menu, dont les deux orphelines réelles
`/cold-email` et `/linkedin` (P6-UI-024) — les douze autres étant légitimes (routes de détail
et d'authentification). Le contrôle discrimine donc bien ; son résultat vide dans le premier
sens est une information, pas un angle mort.

### 3.2 — Balayage des branches d'erreur : le grep trouve celles qui existent

`grep -rn "isError\b" --include=*.tsx src/` renvoie **9 occurrences dans 4 fichiers**
(`CompanyDetailPage`, `ActivityFeed`, `RoumaniePage`, `MediaDetailPage`). Le contrôle **n'est
donc pas aveugle** : il a désigné nommément les quatre écrans qui traitent l'erreur, et
`ObservabilityPage` a été trouvé par la variante `error`. Le fait qu'il n'en trouve pas
ailleurs est une mesure, pas un échec de mesure.

### 3.3 — Comparaison des clés `fr.json` / `en.json` : contrôle validé sur une casse volontaire

```
$ node -e "… delete enCasse.auth.login.submit; …"
témoin négatif — manquantes détectées : [ 'auth.login.submit' ]
contrôle réel  — manquantes détectées : []
```

En retirant une clé d'une copie en mémoire, le contrôle la signale. Sur les fichiers réels,
il ne signale rien : **`fr.json` et `en.json` sont réellement alignés (27 clés de part et
d'autre)**. Le défaut d'i18n (P6-UI-018) n'est pas une divergence de clés, c'est une couverture
de 5 fichiers sur 37 — ce qu'un contrôle de parité de clés ne pouvait pas voir, et c'est
pourquoi je l'ai mesuré autrement (`grep useTranslation`).

### 3.4 — Compilateur Tailwind : la sonde distingue bien couche et hors-couche

La même exécution qui montre `.dark .bg-white` **hors** de tout `@layer` montre
`.dark\:bg-slate-900` **dans** `@layer utilities`, et liste la déclaration
`@layer theme, base, components, utilities;`. La sonde sait donc lire les deux cas ; elle n'a
pas « trouvé un problème » par défaut de résolution.

### 3.5 — Les outils tournent réellement sur les fichiers audités

`npx tsc --noEmit` sort en 0 (aucune erreur de type) — mais sur **le même ensemble de
fichiers**, `npx eslint .` sort 16 erreurs et `npx vitest run` exécute 122 tests dans 23
fichiers. Les fichiers sont donc bien analysés ; le zéro de `tsc` est un vrai zéro, pas une
configuration qui ne regarde rien. (C'est le piège que
`eslint-suppressions.README.md` documente pour la période antérieure au 13 août : ESLint
plantait, masqué par un `|| true`.)

---

## 4. GRILLE ÉCRAN §5.1 — LES 25 POINTS, VERDICT TRANSVERSE

Aucune case n'est vide. « non vérifié » signifie : hors de portée d'un audit statique.

| # | Point | Verdict | Renvoi |
|---|---|---|---|
| 1 | L'écran s'ouvre-t-il vraiment ? Capture | **non vérifié** — aucun navigateur | §1, §5 |
| 2 | Titre en français courant, sans jargon | **non** sur ≥ 6 écrans | P6-UI-022, 024 |
| 3 | Fil d'Ariane · retour arrière fiable | **non** : anglais sur 6 chemins ; état perdu au retour | P6-UI-017, 012 |
| 4 | Une seule action principale | partiel — `SettingsPage` en propose 14 qui ne font rien | P6-UI-010 |
| 5 | Chaque donnée vient d'où elle prétend | **non** : tableau de bord et recherche servent des zéros en dur | P6-UI-001, 002 |
| 6 | Formats (dates, nombres, téléphones) | **non** : dates ISO brutes sur 2 écrans de la console, formatées sur un 3ᵉ | P6-UI-014 |
| 7 | Pagination/tri/filtres · URL partageable | **non** : zéro écran met son état dans l'URL | P6-UI-012 |
| 8 | 0 / 1 / 100 / 10 000 / 100 000 lignes | **non** : 7 listes plafonnées à 50-100 sans suite | P6-UI-013 |
| 9 | État de **chargement** | oui en général ; `DashboardSkeleton` injoignable ; `Settings` bloque sur « Chargement… » | P6-UI-001, 010 |
| 10 | État **vide** dessiné | oui — mais illisible en mode sombre | P6-UI-006 |
| 11 | État d'**erreur** | **non** : 31 écrans sur 35 n'en ont aucun | P6-UI-003 |
| 12 | **Permission refusée** explicite | **non** : nulle part | P6-UI-004 |
| 13 | Partiel / hors ligne signalé | **non** : indistinguable du vide | P6-UI-003 |
| 14 | Chaque bouton fait ce qu'il annonce | **non** : cloche, 14 boutons Settings, résultat Tag de ⌘K | P6-UI-002, 009, 010 |
| 15 | Bouton désactivé, et on sait pourquoi | partiel — `disabled` sans explication (Arbitrage, Wizard) | P6-UI-021 |
| 16 | Retour immédiat · annulation après action destructrice | **non** : aucun `undo` nulle part | P6-UI-021 |
| 17 | Actions de masse : décompte, aperçu, réversibilité | **non vérifié** (bulk backend existant, non exercé côté écran) | §5 |
| 18 | Validation · aucun refus silencieux | **non** : champ DSN Sentry perdu sans un mot ; plafond LLM à 0 € | P6-UI-010 |
| 19 | Perte de saisie (rechargement, coupure) | **non vérifié** — demande un navigateur | §5 |
| 20 | Composants du design system réutilisés | globalement oui ; 3 fichiers recopient l'animation, 10 l'état vide | P6-UI-026 |
| 21 | Mode sombre complet · contrastes · alignement | **non** : 4 `!important` écrasent 30 déclarations ; `EmptyState` à 2,39:1 ; 213 usages à 2,56:1 | P6-UI-006, 007 |
| 22 | Responsive 375 px | **non vérifié** — et **non mesuré par la CI** (projet `mobile-safari` jamais joué) | P6-UI-007 |
| 23 | Clavier seul · focus · ARIA · libellés | **non** : pas de piège de focus, dialogues sans nom, onglets sans flèches, recherche sans libellé | P6-UI-020, 025 |
| 24 | i18n · aucune chaîne en dur | **non** : 32 écrans sur 37 en dur ; détection navigateur active sans sélecteur | P6-UI-018 |
| 25 | Un utilisateur non formé comprend-il ? | **non** sur ≥ 6 écrans (jargon, identifiant de base à saisir, tableau de bord menteur) | P6-UI-001, 021, 022 |

---

## 5. CE QUE JE N'AI PAS PU MESURER, ET POURQUOI

Une couverture bornée qui se tait passe pour une couverture complète. Voici la mienne.

**Tout ce qui exige d'ouvrir l'écran.** Pas de navigateur dans cette session, pile Docker
interdite, production hors de portée. Sont donc **non mesurés** :

1. **Point 1 de la grille pour les 35 écrans** : je n'ai archivé aucune capture, d'aucun état.
   Ce rapport ne satisfait pas cette exigence et ne prétend pas la satisfaire.
2. **Le rendu réel du mode sombre.** J'ai prouvé la cascade CSS par compilation et calculé les
   contrastes par la formule WCAG ; je n'ai pas vu un pixel. Un écart de rendu (antialiasing,
   profil couleur, `color-scheme`) ne changerait pas les ratios, mais je ne l'ai pas constaté.
3. **La visite guidée à l'exécution.** Le saut des étapes 4 et 7 est déduit du code de
   `react-joyride` 2.9.3 lu dans `node_modules` et de la classe `hidden` de l'accordéon. Je
   n'ai pas vu la visite tourner ; je n'ai pas non plus vu le `console.warn("Target not
   visible")` qu'elle doit produire.
4. **Le responsive à 375 px** et les cibles tactiles : rien dans le dépôt ne le mesure, et je
   ne pouvais pas le mesurer non plus.
5. **La perte de saisie** (point 19) : rechargement forcé et coupure réseau pendant la frappe
   sont des gestes de navigateur.
6. **Le comportement réel aux volumes** (point 8) : j'ai lu les plafonds `per_page` ; je n'ai
   pas chargé 100 000 lignes.
7. **Les actions de masse** (point 17) : il existe un `Crm/BulkController` côté API ; je n'ai
   trouvé aucun écran qui le consomme, mais je n'ai pas cherché exhaustivement de ce côté —
   c'est le périmètre du §4.3, pas le mien.

**Tout ce qui exige de faire tourner l'API.**

8. **Le point 5 de la grille (« chaque donnée vient-elle d'où elle prétend ? ») n'est établi
   que pour deux routes** (`/dashboard/stats`, `/search`), parce que leurs bouchons sont
   visibles à la lecture. Pour les ~30 autres routes consommées par les écrans, je n'ai pas
   recoupé la forme des réponses avec ce que les écrans en attendent. Un écran qui lit
   `data.meta.total` alors que l'API renvoie `data.total` afficherait 0 sans que rien ne
   rougisse, et je ne l'aurais pas vu. **C'est mon plus gros angle mort.**
9. **Je n'ai pas joué la suite PHP** (interdit explicite : conteneur `a35r`). Toutes mes
   mesures backend sont des lectures de fichiers.
10. **Je n'ai pas joué Playwright.** Les navigateurs ne sont pas installés dans cet
    environnement et l'installation aurait été longue. Les constats P6-UI-007 et P6-UI-008
    portent donc sur ce que le workflow **déclare** exécuter, pas sur une exécution observée.
    En particulier, mon affirmation « la porte a11y mesure des écrans vides » est déduite de
    trois faits vérifiés (absence de `.env`, absence de `storageState`, absence de mocks dans
    `a11y.spec.ts`) et **non d'une exécution**.

**Deux points que j'aurais pu trancher et que je laisse ouverts, faute de preuve.**

11. **Le fourre-tout `path: '/*'`** (`routeTree.tsx:107`). L'API moderne de TanStack Router
    v1 privilégie `notFoundComponent` ou le segment `$`. Je n'ai pas vérifié que `'/*'`
    apparie réellement dans la version installée (1.170) : si ce n'est pas le cas, une URL
    inconnue ne rendrait **rien du tout** au lieu de rendre la page 404. Le e2e
    `console-locale.spec.ts:384` suppose qu'elle s'affiche, mais ce test ne tourne nulle part
    (P6-UI-008). **À trancher par un geste, en une minute, dans un navigateur.**
12. **Les deux instances simultanées de `GlobalSearch`** (`Header.tsx:46` et
    `RootLayout.tsx:112`) posent chacune un écouteur `keydown` sur `document`
    (`GlobalSearch.tsx:27`). Sur un écran assez large pour que les deux soient montées, ⌘K
    devrait basculer les deux. Le second est à l'intérieur d'une `Modal` fermée, donc démonté
    (`Modal.tsx:40` : `if (!open) return null`) — ce qui neutralise probablement le problème.
    Je n'ai pas confirmé qu'aucune largeur d'écran ne monte les deux à la fois.

---

## 6. CE QUI M'A LE PLUS FRAPPÉ, EN TROIS PHRASES

L'application ne dit jamais à son utilisateur qu'elle est en panne : elle lui dit qu'il n'a
rien. Trente et un écrans sur trente-cinq confondent « le serveur a échoué », « vous n'avez
pas le droit » et « la liste est vide », et les deux écrans les plus visibles du produit — le
tableau de bord et la recherche globale — affichent ce message d'absence **en permanence**,
parce que leurs deux routes sont des bouchons qui renvoient des zéros et des tableaux vides
écrits en dur. Les gardes qui devaient rattraper cela ne mesurent rien : l'`ErrorBoundary` est
écrit mais monté nulle part, la porte d'accessibilité scanne quatre écrans non authentifiés et
n'asserte que sur `critical`, et quatorze des seize suites e2e ne sont jouées par aucun
workflow.
