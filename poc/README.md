# POCs Axion CRM Pro — Validation hypothèses critiques

> **Objectif :** valider 5 hypothèses risquées de la spec v1.2 **avant** d'engager 14-17 semaines de dev Sprint 1-S12.
> **Budget total estimé :** ~150 € (pay-as-you-go, sauf Webshare 10 $/mo résiliable).
> **Durée :** 1-2 semaines parallélisables (POCs #1 et #2 sont à lancer en arrière-plan 5-7 jours, le reste = quelques heures).

---

## Ordre d'exécution recommandé (du moins cher au plus complexe)

| Ordre | POC | Coût | Durée | Pré-requis |
|-------|-----|------|--------|-------------|
| **1** | [#5 — Anti-doublon perf](./05_dedup_performance/) | **0 €** | 2 j | Docker Desktop + Node 22 |
| **2** | [#4 — SMTP validation](./04_smtp_validation/) | ~5 € (optionnel) | 1-2 j | Node 22, IPs Hetzner optionnelles |
| **3** | [#3 — Direction Finder](./03_direction_finder/) | ~35 $ + 5 € | 4 j | Webshare proxies + Anthropic API key |
| **4** | [#1 — Google Maps](./01_google_maps/) | ~40 $ | 7 j (background) | Webshare + IPRoyal |
| **5** | [#2 — Google Search Wrapper](./02_google_search/) | ~50 $ | 5 j (background) | IPRoyal sticky + 2captcha |

---

## Services à souscrire au fur et à mesure

### Avant POC #3 — Anthropic API

1. Créer compte sur https://console.anthropic.com
2. Onglet « Billing » → ajouter méthode paiement → **charger 10 $** (pay-as-you-go)
3. Onglet « API Keys » → créer une key `axion-crm-pro-pocs`
4. **NE PAS partager la key.** Ajoute-la dans `./03_direction_finder/.env` (gitignored)

### Avant POC #1 et #3 — Webshare datacenter proxies

1. Compte sur https://www.webshare.io
2. Plan « Proxy 100 » à 10 $/mo (abonnement — pense à résilier après POC si pas conservé Phase 1)
3. Onglet « Proxy List » → exporter `Username:Password@IP:Port` (100 lignes)
4. **Important :** noter dans un calendrier la date de résiliation si tu ne gardes pas.

### Avant POC #1 et #2 — IPRoyal résidentiels

1. Compte sur https://iproyal.com
2. **Pay-as-you-go residential proxies** — recharger 30 $ (≈ 4-6 GB selon taux du jour)
3. Récupérer credentials : `username:password@geo.iproyal.com:12321`
4. **Pas d'abonnement** — tu consommes, c'est tout.

### Avant POC #2 — 2captcha

1. Compte sur https://2captcha.com
2. Recharger compte avec **15-20 $** (≈ 7000-10000 captchas)
3. Récupérer `API_KEY` dans dashboard
4. **Pas d'abonnement** — pay-as-you-go.

### Avant POC #4 — IPs Hetzner dédiées (OPTIONNEL)

POC #4 peut tourner depuis localhost mais idéalement contre 2 IPs propres :
1. Compte Hetzner Cloud
2. Provisionner 2 instances CAX11 (~3,80 €/mois chacune) avec rDNS configurés
3. **Activation à l'heure** : ~0,01 €/h pendant la durée du POC
4. Snapshot/destroy après mesures

**Coût POC #4 si tu actives 1 semaine : ~5 €.**

---

## Pré-requis machine locale

```powershell
# Node 22 LTS
node --version    # doit être >= 22

# pnpm (gestionnaire packages plus rapide que npm)
npm install -g pnpm@9

# Docker Desktop
docker --version  # doit être >= 27

# Git
git --version
```

---

## Workflow d'exécution d'un POC

```powershell
# 1. Aller dans le dossier POC
cd "C:\Users\willi\Documents\Projets\Axion-CRM-Pro\poc\05_dedup_performance"

# 2. Installer dépendances
pnpm install

# 3. Copier .env.example en .env et remplir (jamais commit .env !)
copy .env.example .env
notepad .env

# 4. Lancer le POC
pnpm run start

# 5. Lire les résultats
type RESULTS.md
```

---

## Décision GO / NO-GO après chaque POC

Chaque POC produit un `RESULTS.md` à la fin avec **un verdict explicite** :

- 🟢 **GO** : hypothèse validée, on continue la spec telle quelle
- 🟡 **GO conditionnel** : KPI partiellement atteints, ajustement spec mineur
- 🔴 **NO-GO** : hypothèse invalidée, refonte stratégique nécessaire avant Sprint 1

**Si 1 POC est 🔴** → ne PAS coder Sprint 1 tant que pivot stratégique n'est pas spec'é (cf. `22_risques_mitigations.md` pour les mitigations alternatives).

---

## Aide / dépannage

- Problèmes Playwright headless : voir `01_google_maps/TROUBLESHOOTING.md`
- Captcha 2captcha qui timeout : voir `02_google_search/TROUBLESHOOTING.md`
- Postgres Docker qui refuse de démarrer : voir `05_dedup_performance/TROUBLESHOOTING.md`

---

## Synthèse finale

Après les 5 POCs, **créer `./poc/SYNTHESIS.md`** récapitulant :
- Verdict global GO/NO-GO
- KPIs réels mesurés vs cibles spec
- Ajustements à apporter à la spec v1.2 → v1.3 le cas échéant
- Recommandation : « OK pour Sprint 1 » ou « NO-GO, refonte X requise »

Si GO global, tag git :
```bash
git tag -a "pocs-validated-$(date +%Y-%m-%d)" -m "5 POCs verts, prêt pour Sprint 1"
git push origin --tags
```
