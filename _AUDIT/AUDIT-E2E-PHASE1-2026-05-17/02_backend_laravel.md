# Phase 2 — Backend Laravel

> Sub-agent Explore brut : **42 / 100**.

## Constats clés

| Critère | Cible | Réalité |
|---------|-------|---------|
| Migrations CREATE TABLE | ~66 | 60 réparties dans 9 fichiers consolidés |
| Tests Pest | ≥ 200 | **14** (`test()`/`it()` cumulés) |
| Controllers métier | 27 | 11/27 retournent `notImplemented('X')` partiellement |
| Waterfall étapes | 10 | 7 implémentées + 1 dispatch async (4-6 délégué Node) + 1 manquante (7 = email finder) |
| Dedup niveaux | 6 | **6 / 6** ✅ |
| Auth services branchés | 3 | AuthService + TwoFactorService + MagicLinkService câblés ✅ |
| Horizon supervisors | 7 | **2** (`supervisor-default` + `supervisor-scraping`) |
| RLS tables | ≥ 40 | 27 dans `000008_enable_rls_policies.php` |
| `dd()`/`dump()` oubliés | 0 | **0** ✅ |
| Dépendances Composer | toutes | OK (Spatie\*, pragmarx, league/csv, smalot/pdfparser, l5-swagger) |

## Forces

1. **DeduplicationService 6 niveaux complets** — POC #5 p95 35 ms validé.
2. **WaterfallOrchestrator structuré** — 7 étapes branchées sur services réels (INSEE, Annuaire,
   BODACC, BAN), dispatch BullMQ pour étapes 4-6.
3. **Auth chain complète** — login + 2FA TOTP + magic-link + password reset, RateLimiter, lock 24h.
4. **AuditHashChain implémentée** — SHA-256 prev||row||secret, `verifyChain()` itère cursor().
5. **Configs Laravel 11 publiés** — auth/cache/cors/database/horizon/l5-swagger/logging/mail/
   permission/queue/sanctum/session/filesystems (13 configs).

## Faiblesses

1. **Couverture tests catastrophique** — 14 Pest tests vs cible ≥200 (7 %).
2. **Horizon sous-configuré** — 2 supervisors pour 11 queues (scraping × 11 sources +
   email + LLM + audit) → bottleneck production garanti.
3. **Email finder absent du waterfall** — étape 7 `step7_email` n'existe pas. ContactsController
   `update`/`destroy` retournent 501.
4. **PHPStan + Pint non exécutés** — Pas de garantie level 9 / PSR-12.
5. **Tests d'intégration 0** — Aucun test endpoint, RLS, audit chain end-to-end.

## P0 bloquants prod

- **Email finder étape 7** absente → 0 contact email collecté en prod.
- **Tests endpoint inexistants** → déploiement aveugle.
- **Horizon 2 supervisors** → queues stagnent sous charge.

## Score Phase 2 : **42 / 100**
