# POC #2 — Google Search Wrapper anti-captcha

> **Hypothèse à valider** (spec v1.2 `05_scrapers_14_sources.md` § 9) :
> peut-on récupérer **500 URLs LinkedIn/jour pendant 5 jours** via rotation Google/Bing/DuckDuckGo + IPRoyal sticky + 2captcha sans dépasser **15 %** de captchas résiduels (après résolution) ?
>
> **Budget : ~50 $** (IPRoyal 30 $ + 2captcha 20 $).
> **Durée : 5 jours runtime + 1 jour analyse.**

---

## Pré-requis

- Node 22 LTS + pnpm
- IPRoyal residential (~30 $ prépayé) — **sticky sessions OBLIGATOIRES** (cf. spec)
- 2captcha balance (~15-20 $) — solde se met sur dashboard 2captcha
- Chromium (Playwright)

---

## Setup

```powershell
cd "C:\Users\willi\Documents\Projets\Axion-CRM-Pro\poc\02_google_search"
pnpm install
pnpm exec playwright install chromium
copy .env.example .env
notepad .env       # IPROYAL_* + CAPTCHA_2CAPTCHA_KEY
```

---

## Dataset 1000 cibles (companies + persons)

`datasets/targets_1000.json` contient :
- 500 entreprises FR (variées TPE/PME/ETI) → recherche `"<entreprise>" site:linkedin.com/company/`
- 500 personnes connues (dirigeants pub) → recherche `"<prénom nom>" "<entreprise>" site:linkedin.com/in/`

---

## Lancement

```powershell
# Test rapide (20 cibles, ~10 min)
pnpm run test

# POC complet 5 jours
pnpm run run -- --day 1
# (lendemain)
pnpm run run -- --day 2
# ... jusqu'à day 5

# Synthèse
pnpm run synthesize
```

---

## Critères GO / NO-GO

| KPI | Cible | Statut |
|-----|-------|--------|
| **Captcha rate après 2captcha** | **< 15 %** | **CRITIQUE** |
| URLs LinkedIn entreprises trouvées | ≥ 70 % des cibles | |
| URLs LinkedIn personnes trouvées | ≥ 50 % des cibles | |
| Coût 2captcha total | < 15 € | |
| Rotation Google/Bing/DuckDuckGo équilibrée | toutes utilisées | |

**Si captcha rate > 30 %** → NO-GO : passer à PhantomBuster Phase 2 obligatoire.

---

## Cleanup

- IPRoyal : rien à faire (crédit reste sur compte)
- 2captcha : rien à faire (solde reste)
