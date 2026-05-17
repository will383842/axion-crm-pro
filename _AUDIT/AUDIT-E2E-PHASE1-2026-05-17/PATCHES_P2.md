# Patches P2 — Polish

- **P2-1** Retirer BullMQ dead weight `workers/package.json:21` (1.4 MB inutile).
- **P2-2** Retirer pdf-parse non utilisé `workers/package.json` (à réintégrer Sprint 13 PDF parsing).
- **P2-3** ARCHITECTURE.md synthèse haut niveau (renvoi spec).
- **P2-4** SDK TypeScript client API généré depuis OpenAPI.
- **P2-5** ARIA labels exhaustifs frontend (>WCAG 2.1 AA, viser 2.2).
- **P2-6** Subresource Integrity sur scripts CDN (MapLibre tiles).
- **P2-7** Comments WHY sur LLM router, dedup, SSRF guard (1-2 lignes max).
- **P2-8** `tsr generate` typed routes TanStack Router.
- **P2-9** Coverage CI Vitest + Pest gate ≥ 60 %.
- **P2-10** Feature flags `config/features.php` (lancement progressif Sprint 13+).
- **P2-11** Subresource Integrity sur scripts CDN (MapLibre tiles, Inter font).
- **P2-12** Sentry/GlitchTip release tracking auto-tag via CI.
- **P2-13** Métriques Web Vitals frontend → table `web_vital_samples` (migration 000006 déjà
  créée) — endpoint ingestion à ajouter.
- **P2-14** Onboarding tour react-joyride 1er login.
- **P2-15** Cross-tab session sync via BroadcastChannel.

**Effort total P2 :** ~30-50 h.
