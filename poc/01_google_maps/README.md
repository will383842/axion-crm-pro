# POC #1 — Google Maps scraping anti-ban

> **Hypothèse à valider** (spec v1.2 `05_scrapers_14_sources.md` § 6) :
> peut-on scraper **1 000 entreprises/jour pendant 7 jours consécutifs** sur Google Maps avec proxies IPRoyal résidentiels + stealth + fingerprint randomization, **sans dégrader le success rate sous 75 %** ?
>
> **Budget : ~40 $** (Webshare datacenter 10 $/mo + IPRoyal résidentiel 30 $ pay-as-you-go).
> **Durée : 7 jours runtime + 1 jour analyse.**

---

## Pré-requis

- Node 22 LTS + pnpm
- Chromium (auto-installé via Playwright)
- Compte IPRoyal residential (~30 $ prépayé)
- Compte Webshare 10 $/mo (optionnel, fallback datacenter)

---

## Setup

```powershell
cd "C:\Users\willi\Documents\Projets\Axion-CRM-Pro\poc\01_google_maps"
pnpm install
pnpm exec playwright install chromium
copy .env.example .env
notepad .env       # IPROYAL_USERNAME + IPROYAL_PASSWORD
```

---

## Dataset 1000 SIRENs FR

`datasets/companies_1000.json` contient 1000 entreprises FR test pré-générées (raison sociale + ville).

**Pour POC réel, à remplacer par 1000 SIRENs actifs récupérés via INSEE Sirene API :**

```typescript
// Script utilitaire (à coder si besoin) — pas inclus dans le POC
const resp = await fetch('https://api.insee.fr/...')
```

Le POC fonctionne avec le dataset livré (entreprises connues + villes FR).

---

## Lancement

### Mode test rapide (10 entreprises, 5 min)

```powershell
pnpm run test
```

### Mode POC complet (1000 entreprises × 7 jours = 7000 scrapings)

```powershell
# Jour 1 : lance le scraping en background (durée ~6-8h)
pnpm run run -- --day 1

# Jour 2 (lendemain) :
pnpm run run -- --day 2

# ... jusqu'au jour 7

# Synthèse finale après 7 jours
pnpm run synthesize
```

Chaque jour produit `results/day_N.json`. La synthèse produit `RESULTS.md` global.

---

## Critères GO / NO-GO

| KPI | Cible | Statut |
|-----|-------|--------|
| **Success rate jour 7** | **≥ 75 %** | **CRITIQUE** |
| Captchas rencontrés | < 15 % | |
| IPs bannies sur pool 100 | < 30 % | |
| Latence p95 par scraping | < 8 s | |
| Coût proxy total | < 50 $ | |

**Si success rate jour 7 < 50 %** → NO-GO. Mitigation : passage Smartproxy / BrightData (cf. spec § R1).

---

## Cleanup

- Résilier Webshare si tu ne le gardes pas (dashboard Webshare → Cancel subscription)
- IPRoyal : rien à faire, crédit restant attend
- Supprimer cache Playwright si tu veux libérer disque : `rmdir /S playwright-cache`
