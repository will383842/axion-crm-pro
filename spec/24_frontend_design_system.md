# 24 — Frontend design system + UX patterns + responsive

> **v1.2** — Complète la spec frontend (`13_ui_admin_phase1.md` couvrait pages + wireframes ASCII). Ce fichier traite :
> - Design tokens (couleurs, typo, spacing, shadows, radii)
> - Branding + logo
> - Empty states, loading states, error boundaries
> - Toast patterns (sonner)
> - Form validation UX (react-hook-form + zod)
> - **Responsive full mobile + tablette + desktop** (320 → 2560 px)
> - Onboarding tour 1er login
> - Saved views persistance DB
> - Notifications header (🔔)
> - Recherche globale ⌘K (cmdk)
> - Print / export PDF

---

## §1 — Design tokens (Tailwind 4 `@theme`)

### Couleurs

```css
/* frontend/src/styles/tokens.css */
@import "tailwindcss";

@theme {
  /* === Brand === */
  --color-primary-50:  #eff6ff;
  --color-primary-100: #dbeafe;
  --color-primary-200: #bfdbfe;
  --color-primary-300: #93c5fd;
  --color-primary-400: #60a5fa;
  --color-primary-500: #3b82f6;
  --color-primary-600: #2563eb;  /* main */
  --color-primary-700: #1d4ed8;
  --color-primary-800: #1e40af;
  --color-primary-900: #1e3a8a;

  /* === Sémantique === */
  --color-success: #10b981;       /* 🟢 quality complete, deal won, succès toast */
  --color-warning: #f59e0b;       /* 🟡 quality partial, captcha détecté */
  --color-danger:  #ef4444;       /* 🔴 quality basic, erreurs, opt-out */
  --color-info:    #3b82f6;
  --color-neutral: #6b7280;

  /* === Taille catégorie (6 cat. v1.1) === */
  --color-size-artisan:    #d97706;     /* terracotta CMA */
  --color-size-commercant: #e11d48;     /* rose commerce */
  --color-size-tpe:        #0284c7;     /* bleu ciel */
  --color-size-pme:        #4f46e5;     /* indigo */
  --color-size-eti:        #9333ea;     /* violet */
  --color-size-ge:         #475569;     /* slate sobre */

  /* === Typography === */
  --font-sans: "Inter Variable", "Inter", system-ui, -apple-system, sans-serif;
  --font-mono: "JetBrains Mono", "Fira Code", ui-monospace, monospace;

  /* Modular scale 1.25 (major third) */
  --text-xs:   0.75rem;   /* 12 px */
  --text-sm:   0.875rem;  /* 14 px — defaut tables */
  --text-base: 1rem;      /* 16 px — body */
  --text-lg:   1.125rem;  /* 18 px */
  --text-xl:   1.25rem;   /* 20 px — section titles */
  --text-2xl:  1.5rem;    /* 24 px — page H1 */
  --text-3xl:  1.875rem;  /* 30 px — dashboard KPIs */
  --text-4xl:  2.25rem;   /* 36 px — hero/empty state */

  /* === Spacing scale (4 px base) === */
  --spacing-1: 0.25rem;   /* 4  */
  --spacing-2: 0.5rem;    /* 8  */
  --spacing-3: 0.75rem;   /* 12 */
  --spacing-4: 1rem;      /* 16 */
  --spacing-6: 1.5rem;    /* 24 */
  --spacing-8: 2rem;      /* 32 */
  --spacing-12: 3rem;     /* 48 */
  --spacing-16: 4rem;     /* 64 */

  /* === Radii === */
  --radius-sm: 0.125rem;  /* 2  px */
  --radius:    0.375rem;  /* 6  px — boutons, inputs */
  --radius-md: 0.5rem;    /* 8  px — cards */
  --radius-lg: 0.75rem;   /* 12 px — panels */
  --radius-xl: 1rem;      /* 16 px — modals */

  /* === Shadows === */
  --shadow-sm:  0 1px 2px 0 rgb(0 0 0 / 0.05);
  --shadow:     0 1px 3px 0 rgb(0 0 0 / 0.10), 0 1px 2px -1px rgb(0 0 0 / 0.10);
  --shadow-md:  0 4px 6px -1px rgb(0 0 0 / 0.10);
  --shadow-lg:  0 10px 15px -3px rgb(0 0 0 / 0.10);
  --shadow-xl:  0 20px 25px -5px rgb(0 0 0 / 0.10);

  /* === Dark mode === */
  &[data-theme="dark"] {
    --color-primary-600: #60a5fa;    /* primary inversé pour contraste */
    /* ... cf. shadcn/ui dark tokens */
  }
}
```

### Tokens TypeScript

```typescript
// frontend/src/lib/design-tokens.ts
export const sizeColors = {
  artisan:    'text-amber-700 bg-amber-50 border-amber-200',
  commercant: 'text-rose-700 bg-rose-50 border-rose-200',
  tpe:        'text-sky-700 bg-sky-50 border-sky-200',
  pme:        'text-indigo-700 bg-indigo-50 border-indigo-200',
  eti:        'text-purple-700 bg-purple-50 border-purple-200',
  ge:         'text-slate-700 bg-slate-100 border-slate-300',
} as const

export const qualityColors = {
  complete: { emoji: '🟢', text: 'text-green-800', bg: 'bg-green-100', ring: 'ring-green-500' },
  partial:  { emoji: '🟡', text: 'text-amber-800', bg: 'bg-amber-100', ring: 'ring-amber-500' },
  basic:    { emoji: '🔴', text: 'text-red-800',   bg: 'bg-red-100',   ring: 'ring-red-500' },
} as const
```

---

## §2 — Branding + logo (placeholder Phase 1)

Phase 1 : pas de logo final commandé. Placeholder typographique :

```tsx
// frontend/src/components/brand/Logo.tsx
export const Logo = ({ size = 'md' }: { size?: 'sm'|'md'|'lg' }) => {
  const cls = size === 'sm' ? 'text-base' : size === 'md' ? 'text-xl' : 'text-3xl'
  return (
    <span className={`font-mono font-semibold tracking-tight ${cls}`}>
      <span className="text-primary-600">axion</span>
      <span className="text-slate-400">/</span>
      <span className="text-slate-900 dark:text-slate-100">crm</span>
    </span>
  )
}
```

**À commander en S10-S12 :** logo SVG vectoriel (designer freelance, ~200-400 €) + variantes (color, mono, dark, favicon, OG image).

---

## §3 — Empty states (par page)

Convention : composant `<EmptyState>` réutilisable.

```tsx
// frontend/src/components/ui/EmptyState.tsx
type Props = {
  icon: LucideIcon
  title: string
  description: string
  action?: { label: string; onClick: () => void; href?: string }
  illustration?: React.ReactNode    // optionnel : SVG décoratif
}

export const EmptyState = ({ icon: Icon, title, description, action, illustration }: Props) => (
  <div className="flex flex-col items-center justify-center py-12 px-4 text-center">
    {illustration ?? <Icon className="h-12 w-12 text-slate-400 mb-4" aria-hidden />}
    <h3 className="text-xl font-semibold text-slate-900 dark:text-slate-100 mb-2">{title}</h3>
    <p className="text-sm text-slate-600 dark:text-slate-400 mb-6 max-w-md">{description}</p>
    {action && (
      <Button onClick={action.onClick} {...(action.href && { asChild: true })}>
        {action.href ? <Link to={action.href}>{action.label}</Link> : action.label}
      </Button>
    )}
  </div>
)
```

### Inventaire empty states Phase 1

| Page | Empty state |
|------|-------------|
| Liste entreprises (0 results) | « Aucune entreprise scrappée. Lance ton premier scraping depuis la **Coverage Map**. » + bouton « Aller à la carte » |
| Liste entreprises (filtres trop stricts) | « Aucun résultat. Essaie d'élargir tes filtres. » + bouton « Réinitialiser filtres » |
| Détail entreprise contacts (0) | « Aucun contact détecté. Relance enrichissement. » + bouton « Relancer enrichissement » |
| Coverage Map (0 % global) | « Bienvenue. Sélectionne une zone et lance ton premier scraping. » + tutorial overlay |
| Scraper Runs (0) | « Aucun run encore. Lance un scraping depuis la Coverage Map ou la liste entreprises. » |
| LLM Router Usage (0) | « Aucune utilisation LLM cette période. » |
| RGPD Requests (0) | « Aucune demande RGPD reçue. ✅ Tu es à jour. » |
| CRM Pipeline (Phase 2 scaffold) | « 🟡 Module Phase 2 — bientôt disponible. » (déjà spec'é dans 13) |
| Anomalies (0 active) | « ✅ Aucune anomalie active. Système nominal. » |

---

## §4 — Loading states

### Skeletons (TanStack Query `isPending`)

Préférés aux spinners pour les contenus prévisibles (listes, dashboards).

```tsx
// frontend/src/components/ui/Skeleton.tsx (déjà shadcn/ui)
export const CompaniesTableSkeleton = ({ rows = 10 }: { rows?: number }) => (
  <div className="space-y-2">
    {Array.from({ length: rows }).map((_, i) => (
      <div key={i} className="flex gap-4 py-2">
        <Skeleton className="h-6 w-4" />
        <Skeleton className="h-6 w-20" />
        <Skeleton className="h-6 w-32" />
        <Skeleton className="h-6 flex-1" />
        <Skeleton className="h-6 w-24" />
      </div>
    ))}
  </div>
)
```

### Spinners (actions ponctuelles)

Pour boutons en cours d'exécution + overlays.

```tsx
<Button disabled={mutation.isPending}>
  {mutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
  {mutation.isPending ? 'Lancement...' : 'Relancer enrichissement'}
</Button>
```

### Convention

| Cas | Loading state |
|-----|----------------|
| Chargement initial liste/dashboard | Skeleton (TanStack Query `isPending`) |
| Refetch données (background) | Spinner discret en haut droite + données stale gardées affichées |
| Action utilisateur (mutation) | Spinner inline bouton + bouton disabled |
| Navigation entre pages | Top progress bar (nprogress-style) |
| Chargement carte MapLibre | Splash overlay « Chargement carte... » + skeleton tiles |

---

## §5 — Error boundaries

### Stratégie 3 niveaux

**Niveau 1 — App level** (catch erreurs catastrophiques)

```tsx
// frontend/src/app/AppErrorBoundary.tsx
export const AppErrorBoundary = () => {
  const error = useRouteError()
  Sentry.captureException(error)   // GlitchTip
  return (
    <div className="min-h-screen flex flex-col items-center justify-center p-4">
      <AlertTriangle className="h-16 w-16 text-red-500 mb-4" />
      <h1 className="text-2xl font-bold mb-2">Une erreur est survenue</h1>
      <p className="text-slate-600 mb-6">L'incident a été reporté.</p>
      <Button onClick={() => window.location.href = '/'}>Retour au dashboard</Button>
      {import.meta.env.DEV && <pre className="mt-8 text-xs bg-slate-100 p-4 rounded">{String(error)}</pre>}
    </div>
  )
}
```

**Niveau 2 — Route level** (catch erreurs par page)

Via TanStack Router `errorComponent` par route.

**Niveau 3 — Section level** (catch erreurs widgets)

```tsx
<ErrorBoundary fallback={<KpiCardErrorFallback />}>
  <KpiCard metric="fresh_complete_prospects" />
</ErrorBoundary>
```

Un widget cassé ne casse pas le dashboard entier.

---

## §6 — Toast patterns (sonner)

### Conventions

```tsx
// frontend/src/lib/toast.ts
import { toast } from 'sonner'

export const toastSuccess = (msg: string, opts?: ToastOptions) =>
  toast.success(msg, { duration: 4000, ...opts })

export const toastError = (msg: string, opts?: ToastOptions) =>
  toast.error(msg, { duration: 6000, action: opts?.action })

export const toastWarning = (msg: string, opts?: ToastOptions) =>
  toast.warning(msg, { duration: 5000, ...opts })

export const toastInfo = (msg: string, opts?: ToastOptions) =>
  toast.info(msg, { duration: 4000, ...opts })

export const toastPromise = <T>(promise: Promise<T>, msgs: { loading: string; success: string; error: string }) =>
  toast.promise(promise, msgs)
```

### Convention placement

- Position : `top-right` desktop, `bottom-center` mobile (auto via breakpoint sonner)
- Max 3 toasts visibles simultanément
- Click → dismiss
- Pas de toast pour les actions confirmées par UI évidente (ex: ajout d'un filtre, déjà visible dans l'UI)

### Inventaire toasts Phase 1

| Action | Toast |
|--------|-------|
| Enrichissement lancé | success « Enrichissement de X entreprises en file d'attente » |
| Enrichissement échoué | error « Erreur enrichissement : <message> » + action « Voir détails » → /scraper-runs |
| Export CSV téléchargé | success « Export CSV téléchargé (X lignes) » |
| RGPD erasure exécutée | warning « Suppression atomique terminée. X enregistrements anonymisés. » |
| Bouton « Tester source » réussi | success « Test source <name> : OK (<latency>ms) » |
| Anomalie détectée (push websocket) | warning « <kind> détectée. Voir Anomalies. » + action |
| 2FA setup confirmé | success « 2FA activée. Sauvegardez les 8 codes de recovery. » |
| Password changé | success « Mot de passe mis à jour. Toutes les autres sessions ont été invalidées. » |

---

## §7 — Form validation UX (react-hook-form + zod)

### Convention

- Validation **field-level** au blur (inline error sous le champ)
- Validation **submit-level** finale avant POST API
- Server errors mappés sur les fields concernés via `setError`
- Submit disabled tant que form invalide (visuellement clair)

```tsx
// frontend/src/features/companies/CreateCompanyForm.tsx
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'

const Schema = z.object({
  legal_name: z.string().min(2, 'Min 2 caractères'),
  siren: z.string().regex(/^\d{9}$/, 'SIREN = 9 chiffres').optional(),
  website_url: z.string().url('URL invalide').optional(),
})
type FormData = z.infer<typeof Schema>

export const CreateCompanyForm = () => {
  const form = useForm<FormData>({
    resolver: zodResolver(Schema),
    mode: 'onBlur',                   // validation au blur
  })

  const onSubmit = async (data: FormData) => {
    try {
      await api.companies.create(data)
      toastSuccess('Entreprise créée')
    } catch (e) {
      if (e.response?.status === 422) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
          form.setError(field as any, { message: (msgs as string[])[0] })
        })
      } else {
        toastError('Erreur création')
      }
    }
  }

  return (
    <form onSubmit={form.handleSubmit(onSubmit)}>
      <FormField label="Raison sociale" error={form.formState.errors.legal_name?.message}>
        <Input {...form.register('legal_name')} />
      </FormField>
      <FormField label="SIREN" error={form.formState.errors.siren?.message} optional>
        <Input {...form.register('siren')} inputMode="numeric" maxLength={9} />
      </FormField>
      <Button type="submit" disabled={!form.formState.isValid || form.formState.isSubmitting}>
        Créer
      </Button>
    </form>
  )
}
```

### Composant `<FormField>` standard

```tsx
export const FormField = ({ label, error, optional, children, hint }: Props) => (
  <div className="space-y-1">
    <Label>
      {label}
      {optional && <span className="ml-1 text-slate-400 text-xs">(facultatif)</span>}
    </Label>
    {children}
    {hint && !error && <p className="text-xs text-slate-500">{hint}</p>}
    {error && <p className="text-xs text-red-600" role="alert">{error}</p>}
  </div>
)
```

---

## §8 — Responsive — full mobile + tablette + desktop (P0 v1.2)

> **Cible :** console utilisable sur smartphone (320 px) → desktop XL (2560 px).
> **Stratégie :** mobile-first Tailwind. Wireframes spec 13 retravaillés ci-dessous pour breakpoints.

### Breakpoints Tailwind 4 (standard)

| Token | Min-width | Devices |
|-------|-----------|---------|
| (default) | 0 px | Mobile portrait (320-639) |
| `sm:` | 640 px | Mobile landscape, petites tablettes portrait |
| `md:` | 768 px | Tablettes portrait |
| `lg:` | 1024 px | Tablettes landscape, laptops petits |
| `xl:` | 1280 px | Desktop standard |
| `2xl:` | 1536 px | Desktop large |

### Adaptations par page

**Layout général :**

```tsx
// AppLayout
// Mobile (< md) : drawer sidebar (slide-in via hamburger), bottom tab bar 5 raccourcis principaux
// Tablette (md à lg) : sidebar collapsible (icons-only par défaut, hover-expand)
// Desktop (≥ lg) : sidebar fixe expanded
<div className="flex">
  <Sidebar className="
    hidden md:flex md:w-16 lg:w-64
    md:fixed md:h-screen
  "/>
  <MobileDrawer className="md:hidden" />          {/* hamburger mobile */}
  <main className="flex-1 md:ml-16 lg:ml-64 pb-16 md:pb-0">
    <Topbar />
    {children}
    <BottomTabBar className="md:hidden fixed bottom-0 left-0 right-0" />
  </main>
</div>
```

**Liste entreprises (page la plus complexe) :**

| Breakpoint | Vue |
|------------|-----|
| Mobile (< sm) | **Cards stacked** (1 entreprise = 1 card) avec qualité + taille + nom + ville + bouton « Voir détail » |
| Tablette (sm-lg) | Table compact (4 colonnes : qualité, taille, raison, ville). Filters en drawer top |
| Desktop (≥ lg) | Table complète (10 colonnes) avec filters sidebar gauche + sticky |

```tsx
{breakpoint.below('sm') ? <CompaniesCardList /> : <CompaniesTable />}
```

**Coverage Map :**

| Breakpoint | Vue |
|------------|-----|
| Mobile | Carte plein écran + panneau bottom-sheet (drag to expand) pour KPIs + Action mode |
| Tablette+ | Layout côte-à-côte (carte + panneau latéral droit) |

**Détail entreprise :**

| Breakpoint | Vue |
|------------|-----|
| Mobile | Sections empilées verticalement, accordions pour parties longues (Sources & emails, Historique) |
| Tablette+ | 2-3 colonnes (identification + contacts + signaux à gauche, métadonnées à droite) |

**Dashboard KPIs :**

| Breakpoint | Grille |
|------------|--------|
| Mobile | 2 colonnes (5 KPIs = 3 rows) |
| Tablette | 3 colonnes |
| Desktop | 5 colonnes |

### Tables → cards sur mobile (pattern)

```tsx
// frontend/src/components/responsive/ResponsiveTable.tsx
export const ResponsiveTable = ({ data, columns, cardRender }: Props) => {
  const isMobile = useBreakpoint('sm', 'below')
  if (isMobile) {
    return <div className="space-y-2">{data.map(row => cardRender(row))}</div>
  }
  return <DataTable data={data} columns={columns} />
}
```

### Tactile-first

- Touch targets ≥ 44×44 px (Apple HIG) sur mobile (WCAG 2.2 : 24×24 minimum, mais 44 plus confortable tactile)
- Swipe gestures sur cards mobile : swipe left = actions (Enrichir / Tag / Delete)
- Bottom sheet pattern (react-spring + tailwind-merge) pour overlays mobile (vs modals desktop)

### Tests visuels CI

Playwright `--config=playwright.config.responsive.ts` lance les tests E2E sur 4 viewports :
- iPhone 13 (390×844)
- iPad Air portrait (820×1180)
- Desktop standard (1440×900)
- Desktop XL (2560×1440)

Screenshots comparés à baseline (`toHaveScreenshot()`).

---

## §9 — Onboarding tour 1er login (react-joyride)

### Trigger

À la fin du flow 1er login (après setup 2FA + password change), affiche un walkthrough 8 étapes :

```tsx
// frontend/src/features/onboarding/FirstLoginTour.tsx
import Joyride, { Step } from 'react-joyride'

const STEPS: Step[] = [
  { target: '#sidebar-dashboard', content: 'Bienvenue 👋 — ici ton vue d''ensemble (KPIs temps réel, throughput, coûts).' },
  { target: '#sidebar-coverage',  content: 'La Coverage Map te permet de visualiser et lancer du scraping par zone.' },
  { target: '#sidebar-companies', content: 'Liste entreprises avec filtres avancés (qualité 🟢🟡🔴, taille 6 catégories, NAF, ville...).' },
  { target: '#sidebar-scraping',  content: 'Config sources scraping — active/désactive et règle TTL par source.' },
  { target: '#sidebar-llm',       content: 'LLM Router — 9 use cases, A/B testing, cost tracking.' },
  { target: '#sidebar-rgpd',      content: 'Conformité RGPD — gère les demandes droit accès/suppression.' },
  { target: '#topbar-cmdk',       content: 'Recherche globale ⌘K (Ctrl+K Windows) — accède à tout en 2 keystrokes.' },
  { target: '#topbar-notifications', content: 'Notifications 🔔 — anomalies + signaux haute valeur (levée, recrutement, nomination).' },
]

export const FirstLoginTour = () => {
  const { user } = useAuth()
  const [run, setRun] = useState(user?.metadata?.onboarding_completed_at === undefined)
  return <Joyride steps={STEPS} run={run} continuous showProgress showSkipButton
                 callback={({ status }) => {
                   if (['finished','skipped'].includes(status)) {
                     api.me.markOnboardingDone()
                     setRun(false)
                   }
                 }} />
}
```

Stocke `users.metadata.onboarding_completed_at = now()` côté backend.

---

## §10 — Saved views persistance DB

### Table à ajouter en Phase 1

```sql
-- 03_db_schema_phase1.md addition (à intégrer dans v1.2)
CREATE TABLE user_saved_views (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id         UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    page_slug       TEXT NOT NULL,                      -- 'companies'|'contacts'|'scraper_runs'|...
    label           TEXT NOT NULL,
    filters         JSONB NOT NULL,                      -- structure URL params Spatie Query Builder
    columns         TEXT[],                              -- columns visibles (override default)
    sort            TEXT,                                -- ex: '-updated_at'
    is_default      BOOLEAN NOT NULL DEFAULT false,      -- vue par défaut pour cet utilisateur/page
    is_shared       BOOLEAN NOT NULL DEFAULT false,      -- partagée workspace (lecture autres users)
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (workspace_id, user_id, page_slug, label)
);
CREATE INDEX idx_saved_views_user_page ON user_saved_views (user_id, page_slug);
CREATE INDEX idx_saved_views_shared ON user_saved_views (workspace_id, page_slug) WHERE is_shared = true;
```

### UI

Sur chaque page liste (companies, contacts, scraper_runs), bouton « 💾 Sauvegarder cette vue » + dropdown « 📁 Mes vues » avec les vues persistées.

---

## §11 — Notifications header (🔔)

### Backend

Table existante `anomalies` (Phase 1 v1.1) + signaux haute valeur (`company_business_signals` avec `signal_score >= 70`).

Endpoint `/api/v1/notifications` :

```json
{
  "data": {
    "unread_count": 3,
    "items": [
      { "id":"...", "type":"anomaly", "severity":"warning", "title":"Pic erreurs google_search", "ts":"2026-05-16T..." },
      { "id":"...", "type":"signal",  "severity":"info",    "title":"Levée de fonds détectée : EXEMPLE SA 5M€", "ts":"..." },
      { "id":"...", "type":"job",     "severity":"info",    "title":"Enrichissement bulk terminé (1247 entreprises)", "ts":"..." }
    ]
  }
}
```

### Frontend

Composant `<NotificationsBell />` dans Topbar :
- Badge rouge avec compteur unread
- Click → dropdown panel avec liste (max 20 récents)
- Click sur item → navigation vers la page concernée + marquage as read
- Live update via WebSocket Reverb channel `notifications.{user_id}`

```tsx
export const NotificationsBell = () => {
  const { data, refetch } = useQuery({ queryKey: ['notifications'], queryFn: api.notifications.list })
  useWebsocket(`notifications.${userId}`, () => refetch())
  return (
    <DropdownMenu>
      <DropdownMenuTrigger>
        <Bell className="h-5 w-5" />
        {data?.unread_count > 0 && <Badge>{data.unread_count}</Badge>}
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-80 max-h-96 overflow-y-auto">
        {data?.items.map(n => <NotificationItem key={n.id} notification={n} />)}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
```

---

## §12 — Recherche globale ⌘K (cmdk)

### Scope cherchable

- **Entreprises** (par raison sociale + SIREN + ville)
- **Contacts** (par nom + email)
- **Pages navigation** (raccourci clavier)
- **Actions rapides** (« Lancer scraping », « Voir RGPD requests », « Tester un prompt LLM »)
- **Vues sauvegardées** (cf. §10)

### Implémentation

```tsx
// frontend/src/features/cmdk/GlobalCommand.tsx
import { Command } from 'cmdk'

export const GlobalCommand = () => {
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const { data: results } = useQuery({
    queryKey: ['cmdk', q], enabled: q.length >= 2,
    queryFn: () => api.search.global(q),
    placeholderData: keepPreviousData,
  })

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        setOpen(o => !o)
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  return (
    <Command.Dialog open={open} onOpenChange={setOpen} label="Recherche globale">
      <Command.Input value={q} onValueChange={setQ} placeholder="Chercher entreprise, contact, action..." autoFocus />
      <Command.List>
        <Command.Empty>Aucun résultat</Command.Empty>
        <Command.Group heading="Entreprises">
          {results?.companies?.map(c => (
            <Command.Item key={c.id} onSelect={() => router.navigate(`/companies/${c.id}`)}>
              <Building className="h-4 w-4 mr-2" /> {c.legal_name} <span className="text-slate-400">— {c.city}</span>
            </Command.Item>
          ))}
        </Command.Group>
        <Command.Group heading="Contacts">{/* ... */}</Command.Group>
        <Command.Group heading="Actions">
          <Command.Item onSelect={runScrapingPrompt}>⚡ Lancer scraping zone...</Command.Item>
          <Command.Item onSelect={openLlmTester}>🧪 Tester un prompt LLM...</Command.Item>
        </Command.Group>
        <Command.Group heading="Pages">
          <Command.Item onSelect={() => router.navigate('/dashboard')}>📊 Dashboard</Command.Item>
          <Command.Item onSelect={() => router.navigate('/coverage')}>📍 Coverage Map</Command.Item>
          {/* ... 20 pages */}
        </Command.Group>
      </Command.List>
    </Command.Dialog>
  )
}
```

### Backend search

Endpoint `/api/v1/search/global?q=` qui interroge Meilisearch (déjà spec'é stack) sur indexes `companies` + `contacts` (Phase 1 ; ajoute `deals` + `campaigns` en Phase 2).

---

## §13 — Print / Export PDF (fiche entreprise + reporting)

### Fiche entreprise PDF

Bouton « 🖨️ Imprimer fiche PDF » sur détail entreprise → génère un PDF A4 propre (1-2 pages) :

```typescript
// frontend/src/features/companies/printCompanyPdf.ts
import { jsPDF } from 'jspdf'
import autoTable from 'jspdf-autotable'

export async function printCompanyPdf(company: Company, contacts: Contact[]) {
  const doc = new jsPDF()
  doc.setFontSize(18)
  doc.text(company.legal_name, 14, 20)
  doc.setFontSize(10)
  doc.text(`SIREN ${company.siren} · ${company.size_category.toUpperCase()} · NAF ${company.naf_subclass_code}`, 14, 27)

  autoTable(doc, {
    startY: 35,
    head: [['Champ', 'Valeur']],
    body: [
      ['Effectif', `${company.effectif_min}-${company.effectif_max ?? '+'} salariés`],
      ['CA', company.ca_eur ? `${company.ca_eur.toLocaleString('fr-FR')} €` : '—'],
      ['Adresse', `${company.headquarter_address ?? '—'}`],
      ['Site web', company.website_url ?? '—'],
      ['LinkedIn', company.linkedin_url ?? '—'],
      ['Qualité fiche', company.quality_score],
      ['Priorité Axion-IA', company.priority_label],
      ['Offre matchée', `${company.axion_offer_match_code} (${company.axion_offer_match_score}/100)`],
    ],
  })

  if (contacts.length > 0) {
    doc.addPage()
    doc.text(`Contacts (${contacts.length})`, 14, 20)
    autoTable(doc, {
      startY: 30,
      head: [['Nom', 'Fonction', 'Email', 'LinkedIn']],
      body: contacts.map(c => [`${c.first_name} ${c.last_name}`, c.position_label, c.primary_email ?? '—', c.linkedin_url ?? '—']),
    })
  }

  doc.setFontSize(8)
  doc.text(`Généré par Axion CRM Pro le ${new Date().toLocaleDateString('fr-FR')}`, 14, 285)
  doc.save(`fiche-${company.siren ?? company.id}.pdf`)
}
```

### Reporting hebdomadaire PDF

Job `app:weekly-report-pdf` (lundi 09:00) génère un PDF récap envoyé à Will :
- KPIs semaine (fiches 🟢 produites, signaux, coûts)
- Top 10 prospects prioritaires
- Anomalies traitées

Lib backend : `dompdf` (HTML → PDF Laravel) ou `wkhtmltopdf`.

---

## §14 — Récap effort frontend additionnel

Volume code estimé pour couvrir les 13 points ci-dessus :

| Section | Composants à créer | Effort dev |
|---------|---------------------|-------------|
| Design tokens + dark mode | tokens.css + lib/design-tokens.ts | 0.5 j |
| Empty states (9 cas) | EmptyState + 9 instances | 0.5 j |
| Loading skeletons (5 patterns) | 5 composants | 0.5 j |
| Error boundaries 3 niveaux | 3 ErrorBoundary | 0.5 j |
| Toast conventions | lib/toast.ts + 8 instances | 0.5 j |
| Form patterns | FormField + 12 forms | 1 j |
| Responsive mobile/tablette/desktop | refactor 22 pages | **3-4 j** |
| Onboarding tour | FirstLoginTour | 0.5 j |
| Saved views | DB migration + UI + API | 1 j |
| Notifications header | Bell + dropdown + API + WebSocket | 1 j |
| Recherche globale ⌘K | GlobalCommand + Meilisearch index | 1 j |
| Print PDF | jsPDF integration + 1 template | 0.5 j |
| **Total** | | **~10-11 j additionnels** |

**Impact roadmap :** S10 (Classification + UI complète) passe de 9 j à 14 j. Ou réparti S9-S12.

> **Budget global révisé v1.2** : +10 j dev = ~13-17 semaines total (vs 14-16 v1.1, vs 12 v1.0 initial).

---

## Lecture suivante

→ Aucune. Spec v1.2 complète. Retour à `21_couts_roadmap.md` pour validation roadmap, puis 5 POCs (cf. `AUDIT_v1.md` § 13) avant code business.
