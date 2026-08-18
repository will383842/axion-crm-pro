# Tests d'écran — mode d'emploi

Couvrir un écran de route coûte ~30 lignes. Ce fichier dit lesquelles, ce qui
mord si on l'ignore, et quels écrans restent à faire.

État mesuré au 2026-08-18 : **21 fichiers, 118 cas, tous verts**
(`pnpm test`) — dont **6 écrans de route sur 37**.

---

## 1. La recette (à copier)

Créer `tests/screens/<MonEcran>.test.tsx` :

```tsx
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { MonEcran } from '@/features/…/MonEcran';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, recordGet, recordPost } from '../msw/handlers';

describe('MonEcran — rendu', () => {
  it('affiche …', async () => {
    await renderScreen(<MonEcran />, {
      path: '/mon-chemin/$id',          // COPIÉ depuis src/app/routeTree.tsx
      url: '/mon-chemin/42',            // l'URL réellement visitée
      handlers: [getJson('/mon-endpoint/42', FIXTURE)],
      landingRoutes: ['/ailleurs'],     // toute destination d'un Link/navigate
    });
    expect(await screen.findByRole('heading', { name: '…' })).toBeVisible();
  });
});

describe('MonEcran — parcours', () => {
  it('cliquer … change …', async () => {
    const user = userEvent.setup();
    const { handler, urls } = recordGet('/mon-endpoint', FIXTURE);
    const view = await renderScreen(<MonEcran />, { path: '/mon-chemin', handlers: [handler] });

    await user.click(screen.getByRole('button', { name: '…' }));

    await waitFor(() => {
      expect(new URL(urls[urls.length - 1]).searchParams.get('filtre')).toBe('valeur');
    });
  });
});
```

Règles :

- `renderScreen` est **`async`** — l'oublier rend un écran vide.
- **Un test de rendu ET un test de parcours** par écran. Un « parcours » est un
  geste `user-event` (taper, cliquer, sélectionner, déplier) **suivi d'une
  assertion sur ce qui CHANGE** — pas un second `toBeInTheDocument()`.
- S'il n'y a rien de mieux à assurer sur un écran (bouchon, page vide), **le
  dire dans le rapport**, ne pas fabriquer un test creux.

---

## 2. Ce que le harnais fournit

| Fichier | Rôle |
|---|---|
| `tests/setup.ts` | `cleanup`, stubs jsdom, `window.location` observable, MSW `listen/reset/close`, `VITE_ECHO_DISABLED` |
| `tests/helpers/renderScreen.tsx` | monte un écran avec **un vrai routeur en mémoire**, React Query, i18n FR |
| `tests/helpers/location.ts` | `navigations()`, `lastNavigation()`, `wasRedirectedToLogin()`, `setLocation()` |
| `tests/msw/handlers.ts` | `getJson`, `postJson`, `getStatus`, `postStatus`, `recordGet`, `recordPost`, `dynamicGet`, `getPending` + fixtures |
| `tests/msw/server.ts` | le serveur MSW unique |
| `tests/lib/msw-contract.test.ts` | **le harnais se teste lui-même** (CSRF, 401, query string) |

### Options de `renderScreen`

| Option | Défaut | Quand |
|---|---|---|
| `path` | `'/'` | le chemin **exact** de `routeTree.tsx` |
| `url` | `path` | l'URL visitée, paramètres résolus |
| `handlers` | `[]` | handlers MSW du cas |
| `landingRoutes` | `[]` | destinations d'un `Link`/`navigate` — chaîne (balise repère) ou `{ path, element }` (vrai écran) |
| `withLayout` | `false` | seulement si le test porte **sur** `RootLayout` |
| `outsideLayout` | `false` | `/login`, `/2fa`, `/magic-link`, `/password-reset` |
| `consoleFeatures` | — | `'open'` / `'vivier'` / `'closed'` pour les écrans `/console/*` |
| `queryClient` | neuf | rarement utile |

---

## 3. Décisions de conception (et pourquoi)

**Vrai routeur, pas `vi.mock('@tanstack/react-router')`.** Le motif historique
(`tests/components/ContactsHubPage.test.tsx:25`) remplaçait `Link` par un
`<span>`. Trois choses sortaient alors du test : `useParams({ from })` (le test
fournissait le paramètre qu'il prétendait vérifier), `useNavigate` (on assurait
« la fonction a été appelée », pas « on a atterri ») et la résolution des `to`.
On construit un arbre **minimal** aux identifiants identiques à ceux du vrai
(`id: 'layout'`), pas l'arbre réel : celui-ci chargerait 37 modules
(maplibre-gl, react-joyride, pusher…) pour en tester un.
⚠️ Contrepartie assumée : un **renommage de chemin dans `routeTree.tsx`** n'est
pas vu ici. C'est le rôle de `tests/e2e/navigation.spec.ts`.

**MSW, pas `vi.mock('@/lib/api')`.** Le mock de module retire du test
`ensureCsrf()`, l'intercepteur 401 → `/login`, et la query string réellement
construite par axios. MSW intercepte au niveau réseau : les trois restent
couverts. Coût : ~0,3 s par fichier et des URLs absolues. `msw` était installé
et **jamais utilisé** — il l'est maintenant.

**Écran seul, pas sous `RootLayout`.** La coquille ajoute 9 sections de
navigation : « Contacts » existerait deux fois et `getByRole` rougirait pour une
raison étrangère à l'écran. `withLayout: true` reste disponible.

---

## 4. Les pièges (tous rencontrés pour de vrai)

1. **`onUnhandledRequest: 'error'`** — toute requête non simulée fait rougir.
   Penser aux appels des **composants enfants** (le tableau de bord en fait
   trois qu'on ne voit pas dans `DashboardPage.tsx`).
2. **Drapeau de module `csrfFetched` (`src/lib/api.ts:13`)** — le cookie Sanctum
   n'est demandé **qu'une fois par fichier de test**. Assurer « le POST est
   parti » : toujours possible. Assurer « le cookie a été demandé d'abord » :
   seulement dans un fichier dédié, avec `resetCsrfFlag()` et un import
   dynamique. Cf. la note en pied de `tests/setup.ts`.
3. **`window.location.assign`** — non implémenté par jsdom. Le stub le rend
   observable : `wasRedirectedToLogin()`. Sans lui, un 401 non simulé produit
   `Not implemented: navigation` et un rouge illisible.
4. **`ConsoleGate` a trois états** — inconnu (muet), fermé (message), ouvert.
   Passer `consoleFeatures`. **Écrire aussi le cas fermé** : l'inertie n'est pas
   un message, c'est l'ABSENCE de requête (assurer `urls` vide).
5. **`RootLayout` appelle `/auth/me`** et **ouvre une connexion temps réel** —
   déjà couverts par le handler par défaut et `VITE_ECHO_DISABLED`.
6. **i18n** — `LanguageDetector` lit `navigator.language`, que jsdom fixe à
   `en-US`. `renderScreen` épingle le français ; assurer les libellés **FR**.
7. **Faux minuteurs + MSW = expiration.** `vi.useFakeTimers()` gèle aussi la
   résolution des requêtes MSW. Pour un écran anti-rebond, laisser le temps
   réel et attendre avec `waitFor(fn, { timeout: 3000 })` — cf.
   `AudienceBuilderPage.test.tsx`.
8. **Corps de POST vide** — `api.post('/x')` sans corps faisait lever
   `request.json()` et MSW rendait l'erreur illisible. `recordPost` enregistre
   `null` : c'est réglé, ne pas le refaire à la main.
9. **`SegmentedControl` rend des `role="tab"`**, pas des boutons.
10. **`Field` casse le nom accessible de sa première puce** (défaut produit,
    §6) : interroger le `textContent`, pas `getByRole(..., { name })`.
11. **Un libellé peut exister deux fois** (vignette KPI *et* onglet). Réduire
    par conteneur — voir les helpers `vignette()` / `bloc()` des tests d'écran.

---

## 5. La CI

`.github/workflows/ci.yml:295-297` lance `pnpm test` de façon **bloquante** :
aucune modification de workflow n'est nécessaire pour qu'un écran cassé fasse
rougir la CI (prouvé deux fois, cf. le rapport du lot).

⚠️ **Les seuils de couverture de `vitest.config.ts` sont DÉCORATIFS.** La CI
lance `pnpm test`, jamais `pnpm test:coverage` : `lines/statements/functions 60,
branches 50` n'ont jamais rien bloqué et ne bloquent rien aujourd'hui. Les
rendre mordants suppose de changer le workflow — hors périmètre de ce lot.

---

## 6. Défauts de PRODUIT trouvés en écrivant ces tests

Aucun n'est corrigé ici (`src/**` hors périmètre). Ils sont **consignés dans les
tests** pour ne plus passer inaperçus.

| Défaut | Où | Effet |
|---|---|---|
| `queryKey: ['dashboard-stats']` sans `period` | `DashboardPage.tsx:78` | changer de période **ne déclenche aucune requête** ; sans `period_label` du serveur, l'écran **annonce une période qu'il n'a pas chargée** |
| `Field` enveloppe ses enfants dans un `<label>` | `AudienceBuilderPage.tsx:495` | la **première puce** de chacun des 5 groupes hérite du nom accessible du groupe ENTIER — un lecteur d'écran énonce toute la liste |
| `placeholderData` objet (et non fonction) | `DashboardPage.tsx:80` | `isLoading` reste faux, `DashboardSkeleton` n'est **jamais** rendu ; le premier écran est une grille de zéros |

---

## 7. Écrans restant à couvrir — 31 sur 37

Établi en parcourant `src/app/routeTree.tsx`. Chemins relatifs à `frontend/src/`.

### Hors coquille (`outsideLayout: true`)

| Route | Fichier |
|---|---|
| `/2fa` | `features/auth/TwoFactorPage.tsx` |
| `/magic-link` | `features/auth/MagicLinkPage.tsx` |
| `/password-reset` | `features/auth/PasswordResetPage.tsx` |

### Sous la coquille `layout`

| Route | Fichier |
|---|---|
| `/companies` | `features/companies/CompaniesListPage.tsx` |
| `/contacts` | `features/contacts/ContactsListPage.tsx` |
| `/international/roumanie` | `features/international/RoumaniePage.tsx` |
| `/media` | `features/media/MediaListPage.tsx` |
| `/media/$mediaId` | `features/media/MediaDetailPage.tsx` |
| `/journalists` | `features/media/JournalistsListPage.tsx` |
| `/coverage` | `features/coverage/CoveragePage.tsx` |
| `/scraper-runs` | `features/scraping/ScraperRunsPage.tsx` |
| `/llm/router` | `features/llm/LlmRouterPage.tsx` |
| `/llm/proxy-providers` | `features/llm/ProxyProvidersPage.tsx` |
| `/llm/rotations` | `features/llm/RotationsPage.tsx` |
| `/rgpd/requests` | `features/rgpd/RgpdRequestsPage.tsx` |
| `/rgpd/ai-act` | `features/rgpd/AiActRegisterPage.tsx` |
| `/audit-logs` | `features/rgpd/AuditLogsPage.tsx` |
| `/users` | `features/users/UsersPage.tsx` |
| `/settings` | `features/settings/SettingsPage.tsx` |
| `/campaigns` | `features/campaigns/CampaignsListPage.tsx` |
| `/campaigns/new` | `features/campaigns/CampaignWizardPage.tsx` |
| `/campaigns/$campaignId` | `features/campaigns/CampaignDetailPage.tsx` |
| `/tags` | `features/tags/TagsManagerPage.tsx` |
| `/audiences` | `features/audiences/AudiencesListPage.tsx` |
| `/audiences/$audienceId` | `features/audiences/AudienceDetailPage.tsx` |
| `/admin/observability` | `features/observability/ObservabilityPage.tsx` |
| `/console/vivier` | `features/crm-console/CandidatesPage.tsx` — ⚠️ `ConsoleGate requiresVivier` : trois cas (`'open'` → refus, `'vivier'` → accès, `'closed'` → console fermée) |
| `/console/arbitrage` | `features/crm-console/ArbitragePage.tsx` |
| `/cold-email` | `features/phase2-scaffold/ColdEmailStub.tsx` — bouchon |
| `/linkedin` | `features/phase2-scaffold/LinkedInStub.tsx` — bouchon |

### Route attrape-tout

| Route | Fichier |
|---|---|
| `/*` | `features/misc/NotFoundPage.tsx` |

⚠️ `/crm` (`CrmStub`) et `/analytics` (`AnalyticsStub`) ont été **SUPPRIMÉS** :
ne pas les couvrir, ne pas les recréer. Ils tombent sur `NotFoundPage`.

### Déjà couverts (`tests/screens/`)

`/login` · `/` · `/companies/$companyId` · `/audiences/new` ·
`/console/contacts` · `/console/personnes/$personKey`
