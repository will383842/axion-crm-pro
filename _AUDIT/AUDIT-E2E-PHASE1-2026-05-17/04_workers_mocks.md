# Phase 4 — Workers Node + Playwright + Mocks

> Sub-agent Explore brut : **68 / 100**.

## Constats clés

| Critère | Cible | Réalité |
|---------|-------|---------|
| Scrapers 14 sources | 14 | 5 Playwright (google-maps, pj, web, search, dir-finder) + 9 API côté Laravel |
| Mocks Node | 5 | 5 (correspondent aux 5 Playwright) |
| Mocks backend | 14 | 14 ✅ (`backend/app/Services/*/Mocks/`) |
| Fixtures | ≥ 20/service | **5 fixtures totaux** → 1.8 % cible |
| Browser stealth | requis | 4 techniques (`navigator.webdriver=undef`, plugins=[1..5], chrome.runtime, `--disable-blink-features=AutomationControlled`) ✅ |
| HMAC X-Worker-Signature | both sides | ✅ Laravel hash_hmac + Node createHmac |
| Bridge JSON harmonisé | requis | ✅ `axion:scrape:<source>` listes Redis simples (BullMQ retiré) |
| Direction Finder paths | requis | 13 paths candidats ✅ |
| C-level titles | requis | 14 titres (pdg/ceo/dg/cfo/cto/cmo/chro/cco/coo) ✅ |
| BullMQ dans deps | retiré | ❌ **mort** dans package.json:21 (1.4 MB inutile) |
| pdf-parse | utilisé | ❌ déclaré mais inutilisé |
| Tests workers Vitest | ≥ 40 | **0 ou 1** |

## Forces

1. **Browser launcher robuste** — Chromium + stealth init script + proxy support + viewport.
2. **HMAC bridge sécurisé** — sha256 X-Worker-Signature vérifié par `ScraperResultController`.
3. **Format JSON inspectable** — Listes Redis brutes (BRPOP) au lieu de BullMQ binaire.
4. **Direction Finder structuré** — 13 paths + regex 14 titres + heuristique nom Maj.
5. **Google Search Wrapper** — Rotation 3 moteurs + détection captcha early-fail.

## Faiblesses

1. **Fixtures famine** — 5 fichiers vs ≥20/service cible. CI sans fixtures réalistes.
2. **BullMQ dead weight** — package.json:21 importé mais aucune occurrence dans le code.
3. **Tests workers vides** — workers/tests/extract.test.ts seul → 0 % couverture scrapers.
4. **SSRF non protégé côté Node** — `website.playwright.ts:21` accepte n'importe quelle URL,
   risque metadata AWS/GCP.
5. **PDF parsing absent** — pdf-parse importé sans usage, spec/05 Direction Finder rapport
   annuel PDF non traité.

## P0 bloquants prod

- **SSRF côté Node** — Playwright website + direction-finder peuvent accéder 169.254.169.254.
- **Fixtures < 20** — Mocks fallback générique = tests non représentatifs.

## Score Phase 4 : **68 / 100**
