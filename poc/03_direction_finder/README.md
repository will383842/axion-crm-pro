# POC #3 — Direction Finder sur 20 ETI réelles

> **Hypothèse à valider** (spec v1.2 `05_scrapers_14_sources.md` § Direction Finder) :
> sur 20 ETI françaises connues, le module Direction Finder trouve **≥ 25 %** d'entre elles avec **≥ 1 C-level** identifié (DRH/DAF/DSI/CMO/CCO) + email pattern viable.
>
> **Budget : ~35 $ + 5 €** (Webshare 10 $/mo + Anthropic Claude Haiku ~5 €).
> **Durée : 4 heures de runtime + setup.**

---

## Pré-requis

- Node 22 LTS + pnpm
- Compte Webshare 10 $/mo (proxies datacenter) — cf. `../README.md` § Services
- Compte Anthropic API + 10 $ de crédit chargé
- Chromium (auto-installé par Playwright)

---

## Setup

```powershell
cd "C:\Users\willi\Documents\Projets\Axion-CRM-Pro\poc\03_direction_finder"
pnpm install
pnpm exec playwright install chromium       # ~250 MB
copy .env.example .env
notepad .env       # ajouter ANTHROPIC_API_KEY + WEBSHARE_PROXIES (1 par ligne)
```

---

## Dataset 20 ETI test

`datasets/etis_test.json` contient 20 ETI françaises avec leur site web :
TotalEnergies, Veolia, Sanofi, Carrefour, BNP Paribas, Capgemini, Atos, Dassault Systèmes, Vinci, Bouygues, L'Oréal, Saint-Gobain, Air Liquide, Schneider Electric, Thales, Safran, Pernod Ricard, Sodexo, Accor, Publicis.

Tu peux ajouter/retirer des ETI selon ce qui t'intéresse (focus secteur, taille, etc.).

---

## Lancement

```powershell
pnpm run run
```

Pour chaque ETI :
1. **Source 1** : crawl 25 paths corporate `/direction`, `/equipe`, `/leadership`, etc. (FR + EN)
2. **Source 2** : crawl `/presse`, `/newsroom` (10 derniers articles) + LLM detection nominations
3. **Source 3** : recherche rapport annuel PDF (Google `filetype:pdf` + AMF si coté) + parse + LLM extract leadership
4. **Source 4** : Google Search étendu C-level (5 postes × 2 variantes FR/EN)

Résultats agrégés dans `RESULTS.md`.

---

## Critères GO / NO-GO

| KPI | Cible | Statut |
|-----|-------|--------|
| **≥ 5 ETI sur 20 avec ≥ 1 C-level trouvé** | **25 %+** | **CRITIQUE** |
| C-level avec email pattern inféré | ≥ 60 % des C-level trouvés | |
| Coût LLM total | < 10 € | |
| Durée moyenne par ETI | < 90 s | |
| Page corporate /direction trouvée | sur ≥ 50 % des ETI | |

**Si < 25 % succès → NO-GO ou ajustement spec** (cf. recommandations dans RESULTS.md généré).

---

## Cleanup

- Désactiver l'abonnement Webshare si tu ne le gardes pas pour Phase 1
- Anthropic : rien à faire, le crédit restant attend ton prochain usage
