# 13 — UI ADMIN PHASE 1

## Principe fondateur

> **L'admin console est le point de pilotage UNIQUE de toute la plateforme Axion CRM Pro.**

Depuis cette console, l'utilisateur peut entièrement piloter sans jamais ouvrir une CLI :
- Lancer/arrêter/configurer n'importe quel scraper en temps réel
- Voir l'état de toutes les rotations (proxies, comptes LinkedIn, IPs) en temps réel
- Configurer les LLM use cases sans aucun redéploiement (changement runtime)
- Lancer un scraping ciblé par simple clic sur la carte France interactive
- Voir toutes les entreprises/contacts/runs/coûts/anomalies en temps réel
- Configurer workspaces, utilisateurs, rôles, permissions
- Gérer toute la conformité RGPD (suppressions, exports)
- Configurer les providers de proxies (ajout/désactivation/budget)
- Override manuel des scores et tags automatiques

**AUCUNE action ne doit jamais nécessiter SSH, modification de code, outil externe ou ligne de commande.**

---

## Stack frontend récap

- React 19 + TypeScript 5.6 (strict) + Vite 6 + Tailwind 4
- React Router 7 + TanStack Query 5 (cache + sync)
- shadcn/ui-react (Button, Card, Dialog, Combobox, Drawer, Toast, Skeleton, etc.)
- MapLibre GL JS 4 (carte)
- Recharts (graphes)
- TanStack Virtual (virtualization listes 200k+)
- @dnd-kit (drag&drop — utilisé Phase 2 pour pipeline CRM)
- axios + interceptors Sanctum (CSRF + retry)
- i18n FR canonique (EN miroir Phase 2)
- Dark theme par défaut (Tailwind `zinc-950` base + `terracotta` accent Axion-IA)

---

## Architecture des fichiers React

```
frontend/
├── package.json
├── vite.config.ts
├── tailwind.config.ts
├── tsconfig.json
├── index.html
├── public/
│   └── (images, fonts self-hosted Inter)
└── src/
    ├── main.tsx               # bootstrap (QueryClient + Router + Theme)
    ├── router.tsx             # tree React Router 7
    ├── api/
    │   ├── client.ts          # axios instance + interceptors Sanctum + retry
    │   ├── auth.ts            # login/2fa/logout/magic-link
    │   ├── workspaces.ts
    │   ├── companies.ts       # CRUD + search + bulk export + actions
    │   ├── contacts.ts
    │   ├── coverage.ts        # matrix + zones
    │   ├── scraper.ts         # runs + sources + targets
    │   ├── llm.ts             # use_cases + templates + usage
    │   ├── proxies.ts
    │   ├── rotations.ts
    │   ├── gdpr.ts
    │   ├── audit-log.ts
    │   └── monitoring.ts
    ├── components/
    │   ├── ui/                # shadcn/ui (Button, Card, Drawer, Dialog, Combobox, Toast…)
    │   ├── layout/            # AppShell, Sidebar, Topbar, Breadcrumbs
    │   ├── companies/         # CompaniesTable (virtualized), FiltersSidebar, BulkActions
    │   ├── coverage/          # FranceCoverageMap, ZoneDetailPanel, CoverageStats
    │   ├── llm/               # UseCasesEditor, TemplateEditor (Monaco), CostsDashboard
    │   ├── rotations/         # ProxiesPanel, LinkedInPanel, UserAgentsPanel
    │   ├── monitoring/        # MetricsGauges, AlertsCenter, AnomalyDetector
    │   ├── scraper-runs/      # RunsTable, RunDetailDrawer, ErrorPatternsDetector
    │   └── auth/              # LoginForm, TwoFASetup, MagicLink
    ├── hooks/
    │   ├── useCompany.ts
    │   ├── useCoverageMatrix.ts
    │   ├── useLlmRouter.ts
    │   ├── useRotations.ts
    │   └── ...
    ├── pages/                 # 22 pages (17 Phase 1 + 5 Phase 2 placeholders)
    ├── lib/                   # utils (formatters, datetime, csv-export, currency)
    ├── i18n/                  # FR (EN miroir Phase 2)
    └── styles/                # globals.css, tailwind extends
```

### Pattern API client + TanStack Query

```ts
// src/api/client.ts
export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
});
api.interceptors.request.use(async (cfg) => {
  // CSRF cookie pour Sanctum
  await axios.get('/sanctum/csrf-cookie');
  return cfg;
});
api.interceptors.response.use(
  (r) => r,
  async (err) => {
    if (err.response?.status === 401) window.location.href = '/login';
    return Promise.reject(err);
  }
);

// src/hooks/useCompany.ts
export function useCompany(companyId: number) {
  return useQuery({
    queryKey: ['company', companyId],
    queryFn: () => api.get(`/companies/${companyId}`).then((r) => r.data),
    staleTime: 60_000,
  });
}
```

---

## Les 17 pages Phase 1 (IMPLÉMENTÉES) + 5 pages Phase 2 (SCAFFOLDÉES)

### Page 1 — Login + 2FA setup (`/login`)

- Email + password + (optionnel) 2FA token TOTP
- Option "Se connecter via magic link" (envoyé par email)
- Sortie d'erreur 401 douce (sans révéler si l'email existe)
- Sur 1ʳᵉ connexion → forcer setup 2FA (QR code TOTP + 8 backup codes)
- Composants : `<LoginForm/>`, `<TwoFASetupModal/>`, `<MagicLinkRequest/>`

### Page 2 — Dashboard global (`/`)

KPIs temps réel sur la home :
- **Throughput scraping** : entreprises enrichies dernière heure / 24h / 7j (graphe Recharts)
- **Coûts LLM jour** : total + breakdown par use case
- **Coûts proxies mois** : budget vs réel par provider
- **Alertes actives** : nombre d'alertes critiques ouvertes
- **Signaux business détectés** : 24h (critical/high/medium)
- **Queue depth** : depth global toutes queues + alerte si > 5000
- **Sources actives** : 14/14 OK avec ✓ ou ⚠️
- **Top 3 zones à attaquer** : recommandation algo (cf fichier 12)
- **Dernières actions audit-loggées** : 10 lignes scrollables

Wireframe ASCII :
```
┌─ AXION CRM PRO ─────────────────────────────────────────────────────────────┐
│  [Dashboard] [Coverage] [Companies] [Contacts] [Scraper Runs] [LLM] [...]   │
├─────────────────────────────────────────────────────────────────────────────┤
│  ╔═══════════════╗  ╔══════════════╗  ╔══════════════╗  ╔══════════════╗     │
│  ║ Throughput 24h║  ║ Coûts LLM jour║  ║ Coûts proxies║  ║ Alertes 🔴 3  ║     │
│  ║   7 248 ent.  ║  ║   8,42 €     ║  ║   62/80 €    ║  ║              ║     │
│  ╚═══════════════╝  ╚══════════════╝  ╚══════════════╝  ╚══════════════╝     │
│  ╔══════════════════════════════════╗  ╔═════════════════════════════════╗ │
│  ║  Throughput last 7d (graph)      ║  ║ Top 3 zones à attaquer          ║ │
│  ║  ███████████████░░░               ║  ║ 1. ETI IDF NAF Tech    score 0.92║ │
│  ║                                  ║  ║ 2. PME Lyon NAF Conseil score 0.88║ │
│  ║                                  ║  ║ 3. ETI Toulouse NAF Aero score 0.86║ │
│  ╚══════════════════════════════════╝  ╚═════════════════════════════════╝ │
│  ╔══════════════════════════════════════════════════════════════════════╗   │
│  ║ Sources actives :                                                    ║   │
│  ║  INSEE ✓  annu-ent ✓  BODACC ✓  Gmaps ⚠️ (cooldown 75)  PJ ✓ ...    ║   │
│  ╚══════════════════════════════════════════════════════════════════════╝   │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Page 3 — Coverage Map + Matrix (`/coverage`)

Hub principal de la carte de France (cf fichier 11).
- En haut : segmented control 3 modes (Visualization / Search / Action)
- Centre : carte MapLibre (700px de haut)
- Sidebar gauche : filtres composables (`<CoverageFilterPanel>`)
- Sidebar droite (apparait sur clic zone) : `<ZoneDetailPanel>` + actions
- Below the fold : top recommandations zones (algo fichier 12) + table des `target_zones` actives

### Page 4 — Liste entreprises (`/companies`)

Page la plus complexe. Doit gérer 200 k+ lignes virtuelles.

- Header : barre de recherche + bouton "Filtres" (drawer right)
- Filtres composables sur les 10 dimensions de classification + free text + range CA + range effectif
- Table virtualisée TanStack Virtual avec :
  - SIREN, Nom, Ville (région), NAF, Tier, Maturité IA, Offre Axion, Score priorité, Signaux récents, Dernier enrichissement
  - Tri multi-colonnes
  - Bulk actions : "Marquer disqualifié", "Re-lancer enrichissement", "Export CSV/Excel", "Ajouter à campagne (Phase 2)"
- Sticky footer : N entreprises affichées sur M total, [Exporter sélection]

### Page 5 — Détail entreprise (`/companies/:id`)

Wireframe ASCII détaillé :

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ← Retour     [Re-lancer enrichissement]  [Override scores]  [Voir audit]   │
├─────────────────────────────────────────────────────────────────────────────┤
│  SARL DUPONT TECH                                                            │
│  SIREN 123 456 789 · NAF 6201Z · Île-de-France · Paris 75008                 │
│  📍 [carte mini] 📞 +33 1 23 45 67 89 · 🌐 dupont-tech.fr                    │
│  ┌──────────────────┬──────────────────┬──────────────────┬──────────────┐  │
│  │ Effectif: 45-99  │ CA 2024: 4.2 M€  │ Score Axion: 87  │ Maturité IA  │  │
│  │ Tier: PME        │ Offer: mission_pme│ Priority: high  │ en_cours     │  │
│  └──────────────────┴──────────────────┴──────────────────┴──────────────┘  │
│  ╔══════════════════════════════════════════════════════════════════════╗   │
│  ║ Tags : [fintech_b2b] [scale_up] [iot] [+ Ajouter tag manuel]         ║   │
│  ╚══════════════════════════════════════════════════════════════════════╝   │
│  [Contacts]  [Emails]  [Signaux]  [Audit Trail]  [Raw Data]                  │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │  Contacts (12)                                                         │  │
│  │  Jean Dupont      CEO         [linkedin]    j.dupont@dupont-tech.fr ✓  │  │
│  │  Marie Martin     DRH         [linkedin]    m.martin@... (score 92)    │  │
│  │  Pierre Durand    DSI         [linkedin]    (email à valider)          │  │
│  │  ...                                                                   │  │
│  └───────────────────────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │  Signaux business actifs                                               │  │
│  │  🔴 Levée fonds 2.3M€ — 2026-04-12 — source: crunchbase                │  │
│  │  🟡 Recrutement DSI — 2026-05-08 — source: france_travail              │  │
│  └───────────────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────────────┘
```

Drawer "Override scores" : édition manuelle de `priority_score`, `axion_offer`, `contact_priority`, `ia_maturity` avec champ "Raison override" obligatoire + signature `audit_logs`.

### Page 6 — Liste contacts (`/contacts`)

Idem page 4 mais pour `contacts`. Filtres par `position_function` (DRH/DAF/DSI/Marketing/Commercial), `is_legal_representative`, `is_executive`, présence/absence email validé.

### Page 7 — Détail contact (`/contacts/:id`)

Fiche contact + historique enrichissement + emails associés + bouton "Trouver email" (déclenche cascade SMTP cf fichier 06).

### Page 8 — Configuration sources scraping (`/sources`)

Pour chacune des 14 sources :
- Toggle on/off
- Rate limit / minute (éditable)
- Liste des proxies utilisés (dropdown multi-select)
- Dernière exécution + statistiques succès
- Bouton "Tester maintenant" → lance un scrape sur SIREN test (123456789 par défaut) et affiche résultat raw
- TTL j (éditable)

### Page 9 — Configuration LLM Router (`/llm`)

Onglets :
- **Use cases** : table des 10 use cases Phase 1 + 5 Phase 2. Éditable runtime (provider primary, modèle, fallback chain, max tokens, temperature, A/B config).
- **Templates** : éditeur Monaco pour `system_prompt` + `user_prompt`. Variables détectées et listées. Versioning (history visible, rollback en 1 clic).
- **Usage / Costs Dashboard** : graphes Recharts (coût par jour, coût par use_case, coût par provider, latence p95). Filtres période + workspace.
- **A/B testing** : pour chaque use case avec A/B activé, comparaison côte-à-côte coût / latence / qualité.
- **Bouton "Tester un prompt"** : input variables → lance le call → affiche réponse + coût + latence.

### Page 10 — Rotations dashboard (`/rotations`)

Cf fichier 10 wireframe complet. Onglets : Proxies / User-Agents / Cibles géo / LinkedIn / LLMs.

### Page 11 — Proxy providers (`/proxies`)

- Table des providers (Webshare / IPRoyal / Smartproxy / BrightData)
- Pour chacun : status, IPs actives, success rate 24h, latence p95, coût mensuel actuel vs budget
- Per-provider drill-down : détail IPs (table 100 lignes), per-domain success rate
- Bouton "Ajouter un nouveau provider" → modal (provider_key + api_endpoint + api_key vaultable)
- Bouton "Tester maintenant" → 3 requêtes test sur httpbin
- Budget mensuel éditable

### Page 12 — Scraper Runs (`/scraper-runs`)

- Table virtuelle des `scraper_runs` (partitionnée mois)
- Filtres : date, source, statut, scraper_name, workspace, user
- Drill-down par run (drawer right) : toutes métadonnées (proxy, UA, durée, tokens, cost, contacts/emails trouvés, error stacktrace)
- **Détection patterns d'erreur** : badge "Pattern d'erreur détecté" quand >15% d'échecs sur 1h pour la même source
- Export CSV runs filtrés

### Page 13 — Audit log viewer (`/audit-log`)

- Table virtuelle des `audit_logs` (append-only, hash chain)
- Filtres : action, entity_type, actor, date
- Bouton "Vérifier intégrité hash chain" → lance job de vérification, affiche résultat (OK ou ligne à laquelle le chain casse)
- Pas de bouton "Supprimer" (table append-only)

### Page 14 — RGPD requests (`/gdpr`)

- Table des `gdpr_requests` (toutes statuts)
- Bouton "Nouvelle requête" → formulaire (type, sujet, evidence_url, notes)
- Drill-down : montre les `affected_entities` détectées via recherche cross-tables
- Bouton "Traiter cette requête" → modal de confirmation + UPDATE atomique (suppression OU export selon type) + audit log
- Deadline countdown (30 jours RGPD)

### Page 15 — Workspaces + users + invitations + 2FA management + rôles (`/admin`)

- Table workspaces (V1 : 1 seul, mais préparation Phase 2)
- Table users avec rôle, status, last_login, 2FA enabled
- Bouton "Inviter utilisateur" → modal (email + rôle + workspace) → email envoyé
- Bouton "Désactiver" / "Reset 2FA" par user (audit log)
- Onglet Rôles & Permissions : édition matrice rôle × permission (Spatie)

### Page 16 — Settings workspace (`/settings`)

- Préférences (timezone, locale)
- Intégrations : Slack webhook URL, Telegram bot token, email recipients critiques
- Notifications : matrice événement × canal (email/Slack/Telegram)
- Branding console (logo, couleur accent — bloqué V1, locké terracotta + monogramme)
- API tokens (Spatie Sanctum personal tokens — pour scripts internes)

### Page 17 — Anomalies & alertes (`/alerts`)

- Centre de notification : timeline de toutes les anomalies détectées
- Filtres : sévérité (critical/high/medium/low), source, résolu/non-résolu
- Drill-down par alerte : graphe contextuel, recommandations action
- Bouton "Acknowledge" / "Résoudre" (audit log)

---

## Les 5 pages Phase 2 SCAFFOLDÉES (placeholders)

### Page 18 — **Campagnes** (`/campaigns`)

Interface de **création de campagne déclarative** : "Cible PME BTP 50-250 employés en Isère, durée 6 semaines, canaux Email + LinkedIn". L'orchestrateur automatique de Phase 2 transformera cela en séquences cross-canal.

V1 : page créée avec mention :
```
┌─────────────────────────────────────────────────────────────────────────────┐
│  📡 Campagnes — Module en développement                                       │
│  Ce module sera activé en Phase 2. Vous pourrez créer des campagnes          │
│  multi-canal (Cold Email + LinkedIn + appels) avec orchestrateur IA.         │
│  Voir la roadmap → /docs/phase-2-roadmap.md                                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Page 19 — **Cold Email Hub** (`/cold-email`)

Placeholder Phase 2 : séquences, templates, sending domains, SMTP IPs, warmup, deliverability tracking.

### Page 20 — **LinkedIn Outreach Hub** (`/linkedin-outreach`)

Placeholder Phase 2 : campagnes, templates, comptes Sales Nav, analytics.

### Page 21 — **CRM Hub** (`/crm`)

Placeholder Phase 2 : pipeline kanban (avec @dnd-kit), deals, activités, tâches, reports.

### Page 22 — **Analytics avancées** (`/analytics`)

Placeholder Phase 2 : funnels, cohorts, ROI.

---

## Les 9 vues d'organisation des données (page Liste entreprises `/companies`)

L'opérateur peut basculer entre 9 perspectives composables :

1. **Vue par région** : carte choropleth + table 13 régions avec compteurs
2. **Vue par département** : liste 101 dept avec coverage et top NAF
3. **Vue par ville** : 2 157 villes recherchables (auto-suggest)
4. **Vue par secteur NAF** : arborescence 5 niveaux pliable (sections → divisions → groupes → classes → sous-classes)
5. **Vue par taille** : tabs TPE / PME / ETI / GE
6. **Vue par signal business actif** : entreprises avec signal critical/high d'abord
7. **Vue par score priorité Axion-IA** : prioritaire / moyenne / faible / non-cible (tabs)
8. **Vue par statut prospection** : pipeline kanban scaffold (Phase 2 enrichi)
9. **Vue croisée matricielle** : croisement de 2 dimensions au choix (département × NAF, taille × région, etc.)

Switcher de vue dans la barre du haut de `/companies`. État stocké en URL query params pour partage.

---

## Layout général + AppShell

```tsx
function AppShell() {
  return (
    <div className="h-screen flex bg-zinc-950 text-zinc-100">
      <Sidebar />
      <div className="flex-1 flex flex-col">
        <Topbar />
        <main className="flex-1 overflow-auto p-6">
          <Outlet />
        </main>
      </div>
      <Toaster />
    </div>
  );
}
```

Sidebar : navigation + workspace switcher (V1: figé sur Axion-IA) + user menu + 2FA status indicator.

Topbar : breadcrumbs + global search (Ctrl+K) + notifications bell + theme toggle (dark default).

---

## Performance UX

| Optimisation | Impact |
|---|---|
| Virtualization (TanStack Virtual) | Affichage 200k entreprises sans lag |
| React Query cache | Refresh page = 0 fetch si data fraîche |
| Skeleton loaders | Pas d'écran blanc lors fetch |
| Prefetch sur hover | Détail entreprise pré-chargé avant clic |
| Code splitting par route | Bundle initial < 200 Ko gz |
| Self-host Inter (woff2) | Pas de FOIT lié Google Fonts |
| Optimistic updates (mutations) | Feel instant |

---

## Accessibilité

- WCAG AA strict (contrastes, focus visible, navigation clavier)
- All actions atteignables au clavier (Ctrl+K command palette inclus)
- Screen reader labels sur tous les boutons d'icône
- Pas de couleur seule pour signifier un statut (texte + icône)

---

## Critères de done UI Phase 1 (S12)

- [ ] 17 pages Phase 1 implémentées et navigables
- [ ] 5 pages Phase 2 affichent placeholder "Module en développement"
- [ ] 9 vues d'organisation switchables dans `/companies` sans rechargement
- [ ] Coverage map fonctionne en 3 modes (Visualization / Search / Action)
- [ ] LLM Router config UI permet de changer un provider sans redéploiement
- [ ] Override manuel scores entreprise audit-loggé
- [ ] Bulk actions sur companies (max 5000 sélection) fonctionnent
- [ ] Tests Vitest unitaires composants critiques (couverture > 70%)
- [ ] Tests Playwright E2E des 5 parcours clés (login, scrape zone, override, RGPD request, LLM test)

---

## Prochaine étape

→ Lire `14_api_routes_laravel.md` pour les 70+ endpoints REST.
