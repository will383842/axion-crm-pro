# Estimation coûts mensuels — Axion CRM Pro à 1M companies/mois

Sprint Pipeline 360° Hardening (H5 commit 15) — 2026-05-17

> **Honnêteté** : ces chiffres assument l'**enrichissement complet** (waterfall full)
> sur 1M entreprises chaque mois. La cible **réaliste honnête** Axion CRM Pro
> S12 (sprint memory) est ~200k qualifiées / mois, ce qui divise les coûts
> variables par ~5.

## Scénario A — 1M companies / mois (cible aspirationnelle Will)

Assumption pessimiste : **toutes les companies passent dans le waterfall complet**
incluant verify email systématique.

| Service | Volume / mois | Coût unitaire | Coût mensuel |
|---|---:|---|---:|
| Mistral LLM classification | 1M × 1 call (~$0.10/1K) | $0.10/1K calls | **$100** |
| Hunter.io email verification | 1M × 2 contacts (= 2M vérifs) | $0.005/vérif | **$10 000** |
| INSEE Sirene API (plan public 30 req/min) | 1M calls | $0 | **$0** |
| INSEE plan authentifié (500 req/min) | 1M calls | $0 (gratuit) | **$0** |
| France Travail API | 1M calls | $0 | **$0** |
| Brave Search API | 1M calls (free 2K, puis $5/1K) | $5/1K | **$4 990** |
| Pages Jaunes via Webshare proxy | 100K calls (Phase B) | $30/mo flat | **$30** |
| BODACC data.gouv | 1M | $0 | **$0** |
| Hetzner CPX42 + Storage Box backup | flat | €12.49 + €3.20 | **€15.69 (~$17)** |
| Postgres storage 1M rows ~5GB | inclus CPX42 | $0 | **$0** |
| Redis (Coolify managed, inclus) | 0.2GB | $0 | **$0** |
| Sentry (free tier 5K events/mo) | dépend tag rates | $0 (sample) | **$0** |
| **TOTAL** | | | **~$15 137 / mois** |

### Leviers d'optimisation (réalistes)

**Levier 1 — Verify email uniquement sur companies à fort score** :
ne verify email que sur companies dont `quality_score > 60` → divise Hunter par ~5 →
**~$3 000/mo Hunter**, total ~$8 100/mo.

**Levier 2 — Cache Brave 90j** :
sur 1M companies/mois, ~70% sont reverifiées (cache hit). Économie Brave ~70% →
**~$1 500/mo Brave**, total ~$13 600/mo.

**Levier 3 — Brave seulement si Stratégie 1 échoue (signals.legal.siteweb)** :
Annuaire Entreprises remonte website ~40% des cas. Économise Brave 40% supplémentaires.

**Combo 1+2+3** : **~$3 800/mo**. Hunter reste le poste dominant.

## Scénario B — 200K qualifiées / mois (cible réaliste honnête)

Reflète mieux la trajectoire S12 du projet (cf. memory `axion_crm_pro_project.md`).

| Service | Volume | Coût mensuel |
|---|---:|---:|
| Mistral LLM | 200K | **$20** |
| Hunter.io (50K qualifiés × 2 contacts = 100K vérifs) | 100K | **$500** |
| Brave Search (cache 70% → 60K appels) | 60K | **$290** |
| INSEE + FT + BODACC | 200K | **$0** |
| Webshare (si Phase B activée) | flat | **$30** |
| Hetzner + backup | flat | **~$17** |
| **TOTAL** | | **~$857 / mois** |

Cohérent avec l'estimation honnête de la spec projet `~750€/mo`.

## Scénario C — 50K MVP / mois (V1 Sprint 1-3 actuel)

Maintenant que MOCK_MODE=false pour INSEE+FT, scénario réel actuel :

| Service | Volume | Coût mensuel |
|---|---:|---:|
| INSEE + FT + BODACC | 50K | **$0** |
| Hunter.io (5K × 2 = 10K vérifs) | 10K | **$50** (free tier saturé → Starter $34/mo + $0.005 × 9750) |
| Brave Search | 50K (free 2K + 48K @ $5/1K) | **$240** |
| Webshare | flat (Phase B uniquement) | **$0** (disabled) |
| LLM Mistral | 50K | **$5** |
| Hetzner | flat | **~$17** |
| **TOTAL V1** | | **~$312 / mois** |

## Risques budget

1. **Quota Brave gratuit explosé** (2K/mois) : à partir du jour 1, le free tier
   sera consommé en < 12h. Acheter Brave Pro $50/mo (40K req) ou $5/1K pay-as-you-go.

2. **Quota Hunter explosé** : alert UI à 80% mensuel (cf. dashboard observability
   commit 12). Si dépassé → degraded vers `status=unknown` (graceful, pas de crash).

3. **Pic d'enrichissement** (campagne marketing → 100K companies en 1 jour) :
   - INSEE 30 req/min plan public = 43K/jour max → migrate vers plan authentifié 500/min
   - Brave 5 req/s = 432K/jour OK
   - Hunter ~10 req/s = 864K/jour OK

## Recommandations pour Will (S2 immédiat)

1. **Créer compte Brave** (free 2K, garder option upgrade)
2. **Créer compte Hunter** plan free 25 vérifs → vérifier flow OK → upgrade Starter $34/mo
3. **Activer Sentry alerts** : >10 errors/h tag service=*` → email Will
4. **NE PAS** activer Webshare avant validation Phase A (Brave + Hunter en prod 1 semaine)
5. **Cibler 50K companies/mois** pendant Sprint 2-5 pour valider perf + qualité
   avant scale 200K

## Sources

- Hunter pricing : https://hunter.io/pricing
- Brave pricing : https://brave.com/search/api/
- INSEE Sirene v3.11 : https://www.sirene.fr/sirene/public/static/open-data
- Hetzner CPX42 facture réelle : `_AUDIT/HETZNER-INVOICES/`
