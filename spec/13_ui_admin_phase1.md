# 13 — UI admin Phase 1 (17 pages) + Phase 2 scaffold (5 pages)

> **PRINCIPE FONDATEUR : console = point de pilotage UNIQUE de toute la plateforme.**
> Aucune action ne nécessite SSH, modification de code, outil tiers ou intervention manuelle. 22 pages au total.
> **Stack frontend :** React 19 + TypeScript 5.6 + Vite 6 + Tailwind 4 + shadcn/ui + TanStack Query/Virtual + MapLibre + Recharts.

---

## §0 — Structure `src/pages/`

```
src/
├── app/
│   ├── routes.tsx                — TanStack Router config
│   ├── providers.tsx             — TanStack Query + Auth + Theme providers
│   └── layouts/
│       ├── AppLayout.tsx         — sidebar + topbar + content
│       └── AuthLayout.tsx        — centré, sans sidebar
├── pages/
│   ├── auth/ (Login, MagicLink, TwoFactor, ResetPassword)
│   ├── dashboard/ (DashboardPage)
│   ├── coverage/ (CoveragePage)
│   ├── companies/ (ListPage, DetailPage)
│   ├── contacts/ (ListPage, DetailPage)
│   ├── scraping-config/ (SourcesPage, RotationsPage, ProxyProvidersPage, RunsPage)
│   ├── llm/ (LLMRouterPage with tabs)
│   ├── rgpd/ (RequestsPage, AuditPage)
│   ├── workspace/ (UsersPage, SettingsPage)
│   ├── alerts/ (AnomaliesPage)
│   └── phase2-scaffold/ (CampaignsPage, ColdEmailPage, LinkedInPage, CrmPage, AnalyticsPage)
├── features/                     — composants partagés métier
├── components/ui/                — shadcn/ui copiables
└── lib/                          — api client, utils, types
```

---

## §1 — Login + 2FA + Magic Link

### Page Login

```
┌───────────────────────────────────┐
│         🔷 Axion CRM Pro          │
├───────────────────────────────────┤
│  Email      : [_______________]   │
│  Mot de passe: [_______________]  │
│              ☐ Se souvenir         │
│  [        Se connecter       ]    │
│  ────────── ou ──────────         │
│  [   Recevoir un magic link   ]   │
│                                    │
│  Mot de passe oublié ? · S'inscrire│
└───────────────────────────────────┘
```

Flow :
1. Submit email + password → POST `/login` (Sanctum cookie)
2. Si TOTP enabled → redirect `/two-factor` (input 6 digits + recovery codes link)
3. Else → redirect `/dashboard`
4. Magic link : POST `/magic-link/request` → email avec token UUID → GET `/magic-link/{token}` → login auto

---

## §2 — Dashboard global

```
┌──────────────────────────────────────────────────────────────────────┐
│ Bonjour {{name}} 👋    Workspace: [Axion-IA ▼]  · 🔔 (3)            │
├──────────────────────────────────────────────────────────────────────┤
│  ╔ KPIs TEMPS RÉEL ════════════════════════════════════════════════╗ │
│  ║                                                                  ║ │
│  ║ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌──────────┐  ║ │
│  ║ │  🟢      │ │  🟡      │ │  🔴      │ │ Today    │ │ Coût     │  ║ │
│  ║ │ Fiches   │ │ Fiches   │ │ Fiches   │ │ scraping │ │ 30j      │  ║ │
│  ║ │ COMPLÈTES│ │ PARTIELLES││ BASIQUES │ │ 7 432    │ │ 245.30€  │  ║ │
│  ║ │ 42 187   │ │ 28 350   │ │ 18 290   │ │ +312/h   │ │ -8% vs J7│  ║ │
│  ║ └─────────┘ └─────────┘ └─────────┘ └─────────┘ └──────────┘  ║ │
│  ╚══════════════════════════════════════════════════════════════════╝ │
│                                                                       │
│  Distribution par taille                  Throughput dernières 24h    │
│  ┌──────────────────────┐                ┌────────────────────────┐  │
│  │  TPE     ████ 35%    │                │ ◢◣◢◣◢◣◢◣◢◣◢◣◢◣◢◣◢◣◢◣ │  │
│  │  PME     ██   18%    │                │                          │  │
│  │  ETI     █    8%     │                │                          │  │
│  │  Grandes ▪    2%     │                │                          │  │
│  └──────────────────────┘                └────────────────────────┘  │
│                                                                       │
│  Coûts ventilés                          Activité scraper             │
│  ┌──────────────────────┐                ┌────────────────────────┐  │
│  │ Proxies     45 €/mo  │                │ source     | runs 24h  │  │
│  │ LLM         55 €/mo  │                │ insee      | 1 023     │  │
│  │ Captcha     12 €/mo  │                │ ann. entr. | 856       │  │
│  │ Hosting     180 €/mo │                │ google_maps| 740       │  │
│  └──────────────────────┘                └────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────┘
```

Data fetcher TanStack Query : `useDashboardKpis()` (poll 30s).

---

## §3 — Coverage Map + Matrix

Couvert dans `11_carte_france_interactive.md`. Une seule page combinant :

- Tab "Carte" → `<FranceCoverageMap />`
- Tab "Matrix" → tableau `coverage_matrix_cells` (dept × NAF × size), filtres, export CSV

---

## §4 — Liste entreprises

```
┌──────────────────────────────────────────────────────────────────────┐
│ Entreprises                                                          │
│ ┌─────────────────────────────────────────────────────────────────┐  │
│ │ Filtres avancés (10 dimensions) :                                │  │
│ │ [Qualité: 🟢 ▼] [Taille: tous ▼] [NAF: tous ▼] [Région: tous ▼] │  │
│ │ [Discovery source: tous ▼] [Signal: tous ▼] [Priorité: tous ▼]  │  │
│ │ [Tags: ___] [Recherche: __________]  [⚡ Prêt cold email]        │  │
│ │                                          Actions ▼  Export CSV/XLSX│  │
│ ├─────────────────────────────────────────────────────────────────┤  │
│ │ ☐ │ Quali │ Taille │ Raison sociale     │ Ville  │ Decideur     │  │
│ │ ──┼───────┼────────┼─────────────────────┼────────┼──────────────│  │
│ │ ☐ │  🟢   │  PME   │ AXION-IA OÜ        │ Tallinn│ W. Jullin    │  │
│ │ ☐ │  🟢   │  ETI   │ EXEMPLE Industries │ Lyon   │ M. Dupont    │  │
│ │ ☐ │  🟡   │  TPE   │ SARL TEST          │ Paris  │ J. Martin    │  │
│ │ ☐ │  🔴   │  TPE   │ EI Démo            │ Lille  │ —            │  │
│ └─────────────────────────────────────────────────────────────────┘  │
│                                       ◀ Précédent   1 2 3...   Suivant ▶│
└──────────────────────────────────────────────────────────────────────┘
```

Composant `<CompaniesTable />` :
- TanStack Table + TanStack Virtual (50k rows fluides)
- TanStack Query infinite scroll
- Filtres URL-synced (Spatie Query Builder côté backend)
- Actions masse : Enrichir / Tag / Export / Mark contacted / Delete

### Filtre rapide "Prêt cold email"

Pills sur le haut : équivalent SQL `quality_score = 'complete' AND prospection_status IN ('discovered','enriched','qualified')`.

### Filtre par discovery_source

Pour distinguer dirigeants légaux des C-level Direction Finder.

---

## §5 — Détail entreprise

```
┌──────────────────────────────────────────────────────────────────────┐
│ ◀ Retour    AXION-IA OÜ                  [⚡ Relancer enrichissement]│
├──────────────────────────────────────────────────────────────────────┤
│ Identification                                                       │
│   SIREN 00000000000 · SAS · NAF 6201Z · Créée 2024                  │
│   18 salariés · CA 1.8 M€ · 🏷 [pme] [tech] [ia-mature]              │
│   📍 Paris 11ᵉ · 9, rue Exemple                                       │
│   🟢 FICHE COMPLÈTE  · Priorité : prioritaire · Match: mission_pme   │
├──────────────────────────────────────────────────────────────────────┤
│ Contacts (4)                                              + Ajouter  │
│   ┌──────────────────────────────────────────────────────────────┐   │
│   │ ★ Williams Jullin        Président          ⓜ ☎ 🔗 — 🟢      │   │
│   │   Trouvé via : Dirigeant légal (annuaire-entreprises)        │   │
│   │   willjullin@axion-ia.com   score 88   tel +33...            │   │
│   │ ─────────────────────────────────────────────────────────── │   │
│   │   Marie Dupont          DRH               ⓜ ☎ 🔗 — 🟢       │   │
│   │   Trouvé via : Direction Finder (page /direction du site)    │   │
│   │   m.dupont@axion-ia.com    score 82  ...                     │   │
│   └──────────────────────────────────────────────────────────────┘   │
├──────────────────────────────────────────────────────────────────────┤
│ Signaux business (3)                                                 │
│   📈 Levée 2 M€ — 2026-04-15 (Crunchbase)                            │
│   👥 12 recrutements 7 derniers jours (France Travail)               │
│   ✏ Nomination DSI 2026-03 (presse)                                  │
├──────────────────────────────────────────────────────────────────────┤
│ Sources & emails                                                     │
│   Téléphones : +33 1 23 45 67 89, +33 6 ...                          │
│   Emails entreprise (8) : contact@..., info@..., ...                 │
│   Réseaux : 🔗 LinkedIn  𝕏  Instagram  YouTube                       │
├──────────────────────────────────────────────────────────────────────┤
│ Historique enrichissement                                            │
│   🟢 Run 2026-05-15 14:32 — 9/10 étapes OK — 28s — 0.012 €           │
│   🟢 Run 2026-05-01 09:11 — 10/10 étapes OK — 24s                    │
├──────────────────────────────────────────────────────────────────────┤
│ Override scores                                                      │
│   Priorité : [prioritaire ▼]   IA maturité : [85] (override 72)      │
│   Notes : [_________________________________]                         │
└──────────────────────────────────────────────────────────────────────┘
```

Section "C-level trouvés via Direction Finder" : visible uniquement si entreprise effectif >= 100, liste tous les contacts avec `discovery_source = 'direction_finder'`.

---

## §6 — Liste contacts

Similaire à liste entreprises, filtres `discovery_source` + `seniority_level`.

```
Filtres :
  [Seniority: tous▼: c_level | director | manager | other]
  [Discovery: tous▼: legal_director | direction_finder | linkedin_finder | manual]
  [Email status: tous▼: valid | catch_all | invalid | unknown]
  [Score ≥: __]   [Recherche: __________]
```

---

## §7 — Détail contact

```
┌──────────────────────────────────────────────────────────────────────┐
│ ◀ Retour     Marie Dupont                                            │
│              DRH chez AXION-IA OÜ                                    │
├──────────────────────────────────────────────────────────────────────┤
│ 🟢 Trouvé via : Direction Finder                                     │
│   📍 Source URL : https://axion-ia.com/direction                     │
│   📍 Détectée le : 2026-05-15                                        │
│   📍 Confiance découverte : 90%                                      │
├──────────────────────────────────────────────────────────────────────┤
│ Email           m.dupont@axion-ia.com                                │
│   Status        🟢 valid · Score 82                                  │
│   Pattern utilisé : {f}.{last}@{domain}                              │
│   Dernière validation : 2026-05-15 14:33                             │
│   [🔄 Revalider]                                                     │
├──────────────────────────────────────────────────────────────────────┤
│ LinkedIn       🔗 linkedin.com/in/marie-dupont                       │
│   Trouvé via Google Search Wrapper (confidence 85)                   │
├──────────────────────────────────────────────────────────────────────┤
│ Téléphone      +33 1 ...                                             │
│ Twitter        @mdupont                                              │
├──────────────────────────────────────────────────────────────────────┤
│ Activités campagnes (Phase 2)                            (à venir)  │
└──────────────────────────────────────────────────────────────────────┘
```

---

## §8 — Configuration sources scraping

```
┌──────────────────────────────────────────────────────────────────────┐
│ Sources de scraping (14)                                             │
│ ┌───────────────────────────────────────────────────────────────┐    │
│ │ Source           │ État │ Rate /min │ TTL j │ Proxies │ Test  │   │
│ │ ─────────────────┼──────┼───────────┼───────┼─────────┼───────│   │
│ │ INSEE Sirene     │  🟢  │ 500       │ 180   │ —       │ [▶]  │   │
│ │ annuaire-entr.   │  🟢  │ 420       │ 365   │ —       │ [▶]  │   │
│ │ Infogreffe       │  🟡  │ 30        │ 90    │ FR res. │ [▶]  │   │
│ │ Societe.com      │  🟢  │ 30        │ 90    │ FR res. │ [▶]  │   │
│ │ BODACC           │  🟢  │ 300       │ 30    │ —       │ [▶]  │   │
│ │ Google Maps      │  🟢  │ 20        │ 90    │ FR res. │ [▶]  │   │
│ │ Pages Jaunes     │  🟢  │ 20        │ 90    │ FR res. │ [▶]  │   │
│ │ Sites web        │  🟢  │ 60        │ 30    │ DC      │ [▶]  │   │
│ │ Google Search    │  🟢  │ 12        │ 60    │ FR res. │ [▶]  │   │
│ │ France Travail   │  🟢  │ 60        │ 7     │ —       │ [▶]  │   │
│ │ MESRI/ONISEP     │  🟢  │ 60        │ 365   │ —       │ [▶]  │   │
│ │ Crunchbase       │  🟡  │ 6         │ 30    │ FR res. │ [▶]  │   │
│ │ BAN              │  🟢  │ 600       │ 365   │ —       │ [▶]  │   │
│ │ Social light     │  🟢  │ 30        │ 60    │ DC      │ [▶]  │   │
│ └───────────────────────────────────────────────────────────────┘    │
│                                                                      │
│ Édit source (modal) :                                                │
│   On/Off · Rate limits (per_min, per_hour) · TTL j · Proxy pool      │
│   [Bouton "Tester"] : execute 1 scrape sur un SIREN demo             │
└──────────────────────────────────────────────────────────────────────┘
```

---

## §9 — Configuration LLM Router

3 tabs : Providers / Use cases / Prompts / Usage (cf. `07_llm_router.md` §8).

---

## §10 — Rotations dashboard (5 dimensions)

5 colonnes (Proxies / UA / Cibles / Search engines / LLM) avec :
- État live (active/cooldown/captcha/disabled)
- Bascule manuelle
- Sparkline 1h
- Bouton "Forcer rotation"

WebSocket Laravel Reverb sur channel `rotation:{workspace}` → reflète RotationEvent en temps réel.

---

## §11 — Proxy providers (cf. `09_proxy_pluggable_system.md` §5)

---

## §12 — Scraper Runs (logs)

```
┌──────────────────────────────────────────────────────────────────────┐
│ Scraper Runs (filtres : source, status, date, target)                │
│ ┌───────────────────────────────────────────────────────────────┐    │
│ │ ID │ Started      │ Source        │ Status │ Tgt    │ €/run  │   │
│ │ ───┼──────────────┼───────────────┼────────┼────────┼────────│   │
│ │ 99 │ 16/05 14:32  │ direction_f.  │ ✅ ok  │ AXION  │ 0.018  │   │
│ │ 98 │ 16/05 14:30  │ google_maps   │ ✅ ok  │ AXION  │ 0.000  │   │
│ │ 97 │ 16/05 14:29  │ google_search │ ❌ cap.│ ...    │ 0.000  │   │
│ │ 96 │ 16/05 14:28  │ site_web      │ ⏭ skip│ ...    │ 0.000  │   │
│ └───────────────────────────────────────────────────────────────┘    │
│ → Click row : drill-down détail run (request log, raw response, ...) │
│                                                                      │
│ Pattern detection (auto) :                                           │
│  ⚠ google_search 18% captcha sur dernières 100 (vs 4% baseline)     │
│  → Action recommandée : pause Google 1h, fallback Bing                │
└──────────────────────────────────────────────────────────────────────┘
```

---

## §13 — Audit log viewer (hash chain)

```
┌──────────────────────────────────────────────────────────────────────┐
│ Audit log    Filtres : action, user, resource, date                  │
│ ┌───────────────────────────────────────────────────────────────┐    │
│ │ Action            │ User       │ Resource  │ Changes (diff)   │   │
│ │ ──────────────────┼────────────┼───────────┼──────────────────│   │
│ │ company.update    │ will@...   │ #abc      │ priority +→-     │   │
│ │ gdpr.request.recv │ system     │ #def      │ create           │   │
│ │ scraping.run.start│ system     │ —         │ —                │   │
│ └───────────────────────────────────────────────────────────────┘    │
│                                                                      │
│ Hash chain verification : 🟢 valid (10 245 entries, last verified 14:32)│
│ [🔄 Re-verify chain]                                                  │
└──────────────────────────────────────────────────────────────────────┘
```

Hash chain : chaque audit_logs row contient `previous_hash` + `record_hash`. Verification = recalc des hash et compare.

---

## §14 — RGPD requests

```
┌──────────────────────────────────────────────────────────────────────┐
│ Demandes RGPD                                          + Saisir reçue│
│ ┌───────────────────────────────────────────────────────────────┐    │
│ │ Type     │ Demandeur          │ État         │ Échéance      │   │
│ │ ─────────┼────────────────────┼──────────────┼───────────────│   │
│ │ erasure  │ user@example.com   │ in_progress  │ J+12          │   │
│ │ access   │ jane@example.com   │ received     │ J+25          │   │
│ │ portab.  │ john@example.com   │ identity_chk │ J+5           │   │
│ └───────────────────────────────────────────────────────────────┘    │
│                                                                      │
│ Workflow erasure :                                                   │
│   1. Vérification identité (upload CNI) → bouton "Valider identité"  │
│   2. Recherche données concernées (SIREN/email/nom)                  │
│   3. Preview affected records (companies, contacts, emails, runs)    │
│   4. Bouton "Exécuter suppression atomique" → transaction multi-table│
│   5. Génération courrier réponse PDF                                 │
└──────────────────────────────────────────────────────────────────────┘
```

Cf. `17_rgpd_aiact_owasp.md` pour le SQL transactional multi-tables.

---

## §15 — Workspaces + users + invitations + 2FA

```
┌──────────────────────────────────────────────────────────────────────┐
│ Workspaces    + Créer workspace                                      │
│  • Axion-IA  (owner: will@) · 1 user · €245.30 spent this month     │
│                                                                      │
│ Users (1 workspace courant)            + Inviter                     │
│  • Williams Jullin · will@... · Owner · 2FA ✅ · Last login il y a 2h│
│  ➕ Invite pending: julie@... (Operator) · expire J+5                │
└──────────────────────────────────────────────────────────────────────┘
```

Invitation flow :
1. Owner saisit email + rôle → POST `/invitations`
2. Email envoyé avec lien `/invitations/{token}/accept`
3. Acceptant clique → setup password + 2FA + accept → INSERT `user_workspaces`

---

## §16 — Settings workspace

```
- Nom workspace, slug
- Cost cap mensuel (kill-switch LLM)
- Timezone, locale par défaut
- Notifications (Slack webhook, Telegram bot, emails)
- API keys partenaires (Doppler-managed, masked)
- Branding (logo, couleur primaire — UI custom)
- Export complet (RGPD portabilité : full DB dump workspace)
- Danger zone : delete workspace (hard delete avec confirmation typage du slug)
```

---

## §17 — Anomalies & alertes

Affiche `anomalies` détectées par jobs hourly :
- Pic erreurs source > 15% en 1h
- Proxy success rate < 70%
- LLM cost > 2× moyenne 7j
- Search engine captcha > 5/h
- Bounce rate Phase 2 > 5%

Chaque anomalie : 🟡 ack (par user X), 🔴 unack, 🟢 resolved auto.

---

## §18-22 — Pages Phase 2 scaffold (5)

### §18 — Campaigns Hub

```
┌──────────────────────────────────────────────────────────────────────┐
│ 🟡 Module Phase 2 (scaffold) — bientôt disponible                    │
│                                                                       │
│ Campagnes orchestrateur multi-canal                  + Créer campagne│
│                                                                       │
│ ┌──────────────────────────────────────────────────────────────┐     │
│ │ Cette section vous permettra de :                            │     │
│ │  • Créer des campagnes multi-canal (email + LinkedIn)        │     │
│ │  • Définir des séquences ordonnées (étape 1 → 2 → 3...)      │     │
│ │  • Sélectionner audiences via filtres dynamiques             │     │
│ │  • Suivre KPIs (sent, opens, replies, meetings booked)       │     │
│ │  • Pause/resume/duplicate campagnes                          │     │
│ │                                                              │     │
│ │ Statut implémentation : DB + UI structure prêtes,            │     │
│ │ logique métier en Phase 2 (post-S12).                        │     │
│ └──────────────────────────────────────────────────────────────┘     │
└──────────────────────────────────────────────────────────────────────┘
```

### §19 — Cold Email Hub

Tabs (vides) : Campagnes / Templates / Domains / SMTP IPs / Warmup states / Deliverability.

### §20 — LinkedIn Outreach Hub

Tabs (vides) : Comptes opérés / Campagnes / Templates / Sequences / Messages.

### §21 — CRM Hub (pipeline kanban Phase 2)

Mockup pipeline drag-and-drop avec @dnd-kit (composant déjà installé Phase 1) :

```
┌───────────┬───────────┬──────────┬──────────┬─────────┬─────────┐
│   Lead    │ Qualified │   Demo   │ Proposal │   Won   │   Lost  │
├───────────┼───────────┼──────────┼──────────┼─────────┼─────────┤
│  (0)      │   (0)     │   (0)    │   (0)    │   (0)   │   (0)   │
│           │           │          │          │         │         │
│ Pipeline  │           │          │          │         │         │
│ vide Phase│           │          │          │         │         │
│ 1 (scaffold)│         │          │          │         │         │
└───────────┴───────────┴──────────┴──────────┴─────────┴─────────┘
```

### §22 — Analytics avancées

Placeholder « Phase 2 ».

---

## §23 — 10 vues d'organisation des données (liste entreprises)

Le sélecteur "Vue" au-dessus de la table change le grouping affiché :

| Vue | Grouping |
|-----|----------|
| 1. Région | par `region_code` |
| 2. Département | par `department_code` |
| 3. Ville | par `city_insee` |
| 4. NAF section | par `LEFT(naf_subclass_code,1)` |
| 5. Taille | par `size_category` |
| 6. Signal business | par `signal_type` (jointure signals) |
| 7. Score priorité Axion-IA | `priority_label` |
| 8. Statut prospection | `prospection_status` |
| 9. Vue matricielle | département × NAF × taille (pivot table) |
| 10. **Qualité de fiche 🟢/🟡/🔴** | `quality_score` |

Sauvegarde de filtres + vue dans `user_settings.saved_views` (table à ajouter si besoin).

---

## §24 — Composants partagés clés

### `<QualityBadge />`

```tsx
type Props = { score: 'complete'|'partial'|'basic' }
export const QualityBadge = ({ score }: Props) => {
  const config = {
    complete: { emoji: '🟢', label: 'Complète', bg: 'bg-green-100 text-green-800' },
    partial:  { emoji: '🟡', label: 'Partielle', bg: 'bg-amber-100 text-amber-800' },
    basic:    { emoji: '🔴', label: 'Basique', bg: 'bg-red-100 text-red-800' },
  }[score]
  return <span className={`px-2 py-1 rounded text-xs font-medium ${config.bg}`}>{config.emoji} {config.label}</span>
}
```

### `<DiscoverySourceBadge />`, `<SizeCategoryBadge />`, `<PrioritySelect />`, `<NafSelector />`, `<DateRangePicker />`, ...

---

## §25 — Theming

- **Dark mode + Light mode** (default light, persistant localStorage)
- Couleurs primaires :
  - Light : `--primary: #2563eb` (blue 600)
  - Dark : `--primary: #60a5fa` (blue 400)
- Tokens Tailwind 4 dans `@theme` directive

---

## §26 — Accessibilité

- WCAG 2.1 AA cible
- Tous les inputs ont `<label>` associé
- Toutes les icônes décoratives : `aria-hidden="true"`
- Toutes les actions ont `aria-label` si emoji-only
- Focus rings visibles (`focus-visible:ring-2`)
- Skip link `<a href="#main-content">` en début page

---

## Lecture suivante

→ `14_api_routes_laravel.md` (60-80 endpoints REST + Spatie Data + rate limit + Phase 2 stubs).
