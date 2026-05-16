# Axion CRM Pro

> Plateforme B2B de prospection bout en bout pour le cabinet IA opérationnel **Axion-IA**.
>
> **Scraping multi-sources + Enrichissement waterfall + Classification automatique IA + Coverage Matrix + Carte France interactive**, le tout 100 % pilotable depuis une console admin unique — aucune intervention SSH, code ou outil tiers requise pour les opérateurs.

---

## Vision

Axion CRM Pro est conçue dès le départ comme une **plateforme globale de prospection B2B, de bout en bout, 100 % automatisée et pilotable depuis une console centralisée unique**.

- **Phase 1 (spec ⇒ implémentation V1)** : scraping 14 sources, enrichissement waterfall 9 étapes, classification LLM, coverage matrix, carte France interactive, auth multi-tenant, monitoring.
- **Phase 2 (scaffold DB + UI dès V1, logique métier en V2)** : Cold Email de masse personnalisé IA, LinkedIn Outreach automatisé, CRM pipeline complet, Analyses avancées + ROI, Orchestrateur de campagnes multi-canal.

**Aucune action utilisateur ne doit jamais nécessiter d'accès SSH, modification de code, outil tiers ou intervention manuelle technique.** Toute la plateforme est pilotée par la console admin.

---

## Stack

| Couche                | Technologie                                                                          |
|-----------------------|--------------------------------------------------------------------------------------|
| Backend API           | Laravel 12 + PHP 8.3 + Laravel Sanctum + Horizon + Spatie Permission + Spatie Data   |
| Base de données       | PostgreSQL 16 (pg_trgm, postgis, pgvector, pg_partman) + Redis 7                     |
| Workers scraping      | Node.js 22 LTS + Playwright 1.49+ + playwright-extra stealth + BullMQ                |
| Frontend admin        | React 19 + TypeScript 5.6 + Vite 6 + Tailwind 4 + TanStack Query 5 + MapLibre GL JS  |
| Auth                  | Sanctum cookie SPA + TOTP 2FA (`pragmarx/google2fa-laravel`) + Magic link            |
| Hébergement           | Hetzner Cloud Frankfurt (compte dédié, IPs distinctes d'axion-ia.com)                |
| Monitoring            | Grafana + Prometheus + Loki + Tempo + GlitchTip + Uptime Kuma (auto-hébergés)        |
| CI/CD                 | GitHub Actions → Coolify v4 / k3s                                                    |
| LLM Router            | Anthropic Claude + OpenAI + Mistral + OpenRouter + Ollama local (configurable)       |
| Carte interactive     | MapLibre GL JS + OpenFreeMap + IGN AdminExpress COG 2026 + BAN api-adresse           |
| Données B2B           | INSEE Sirene + annuaire-entreprises.data.gouv.fr + BODACC + France Travail + …       |

---

## Statut

**Phase 1 spec en cours.**

La spec exhaustive (24 fichiers Markdown denses) se trouve dans [`./spec/`](./spec/). Lecture recommandée :

1. [`spec/00_INDEX.md`](./spec/00_INDEX.md) — sommaire complet et glossaire
2. [`spec/01_thinking_executive_naming.md`](./spec/01_thinking_executive_naming.md) — chain-of-thought architecte + exec summary

L'implémentation du code Phase 1 démarre après validation de la spec et utilise les 12 prompts Claude Code prêts à l'emploi listés dans [`spec/23_interfaces_phase2_execution_pack.md`](./spec/23_interfaces_phase2_execution_pack.md).

---

## Indépendance technique vs axion-ia.com

Axion CRM Pro est **techniquement 100 % indépendant** d'axion-ia.com. Le partage du dossier parent local est purement organisationnel.

- Stack différente (Laravel + React + workers Node ; axion-ia.com = Next.js 15)
- Compte Hetzner différent, IPs différentes, domaine différent, DB différente, auth différente
- Aucun lien de code, de DNS, ou d'infrastructure

---

## Licence

Code propriétaire. © Axion-IA OÜ.

---

## Contact

DPO et contact projet : `contact@axion-ia.com`
