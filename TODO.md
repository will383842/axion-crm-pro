# TODO — Axion CRM Pro

> **Source de vérité** de ce qu'il reste à faire avant Sprint 1 + production.
> Dernière mise à jour : 2026-05-16
> Voir aussi : `spec/AUDIT_v1.md`, `poc/README.md`, `spec/21_couts_roadmap.md`

---

## Vue d'ensemble — état global

| Bloc | État | Commentaire |
|------|------|-------------|
| Spec v1.2 complète | ✅ 100 % | 26 fichiers spec + AUDIT_v1, sur GitHub |
| 16 P0 audit corrigés | ✅ 100 % | Vérifiés par grep |
| 7 P1 audit corrigés | ✅ 100 % | OTel, Langfuse, i18next, Terraform, WCAG 2.2, fuzzy filter, métriques business |
| Frontend design system | ✅ 100 % | Responsive full mobile→desktop, 13 sections UX |
| POCs codés | ✅ 100 % | 50 fichiers TypeScript prêts dans `./poc/` |
| **POCs exécutés en réel** | ❌ **0 %** | À faire par Will, voir §1 |
| **Code Sprint 1 (Bootstrap)** | ❌ **0 %** | À faire APRÈS POCs verts, voir §2 |
| Conformité avant prod | 🟡 partielle | DPIA + DPA + pentest à produire, voir §3 |
| Décisions stratégiques | 🟡 4 ouvertes | STOP & ASK, voir §4 |

---

## §1 — POCs à exécuter en réel (~150 € + 1-2 semaines)

Ordre du moins cher au plus coûteux.

### POC #5 — Anti-doublon perf 1 M rows (0 €, 15 min)

- [ ] Installer Docker Desktop
- [ ] `cd poc/05_dedup_performance && pnpm install`
- [ ] `pnpm run start`
- [ ] Lire `RESULTS.md`

Critère GO : p95 < 50 ms à 10 M rows.

### POC #4 — SMTP validation (0-5 €, 1-2 j)

- [ ] Compléter `datasets/emails_gold.json` (100 emails réels étiquetés)
- [ ] Vérifier port 25 sortant (sinon VPS Hetzner ~5 €/sem)
- [ ] `cd poc/04_smtp_validation && pnpm install && pnpm run validate`

Critère GO : accuracy ≥ 90 %, FPR < 5 %.

### POC #3 — Direction Finder 20 ETI (~35 $ + 5 €, 4 j)

- [ ] Compte Anthropic + 10 $ → clé `sk-ant-...`
- [ ] Compte Webshare 10 $/mo → `proxies.txt`
- [ ] `cd poc/03_direction_finder && pnpm install && pnpm exec playwright install chromium`
- [ ] `.env` rempli + `pnpm run run`

Critère GO : ≥ 5 ETI/20 avec ≥ 1 C-level email validé.

### POC #1 — Google Maps anti-ban (~40 $, 7 j runtime)

- [ ] IPRoyal residential 30 $ pay-as-you-go
- [ ] `cd poc/01_google_maps && pnpm install && pnpm exec playwright install chromium`
- [ ] `.env` rempli + `pnpm run run -- --day 1` à `--day 7`
- [ ] `pnpm run synthesize`

Critère GO : success rate jour 7 ≥ 75 %.

### POC #2 — Google Search Wrapper (~50 $, 5 j runtime)

- [ ] 2captcha + 20 $
- [ ] IPRoyal sticky sessions (depuis POC #1)
- [ ] `cd poc/02_google_search && pnpm install`
- [ ] `.env` rempli + `pnpm run run -- --day 1` à `--day 5`
- [ ] `pnpm run synthesize`

Critère GO : captcha rate < 15 %, ≥ 70 % URLs LinkedIn entreprises trouvées.

### Synthèse POCs

- [ ] Créer `poc/SYNTHESIS.md`
- [ ] Si tout 🟢 : `git tag pocs-validated-YYYY-MM-DD`
- [ ] Si un POC 🔴 : retravailler stratégie selon recommandations

---

## §2 — Code Sprint 1 → S12 (14-17 semaines)

Démarrer UNIQUEMENT après POCs validés.

### Pré-requis avant Prompt 1 Bootstrap

- [ ] Compte Hetzner Cloud CRM-Pro dédié créé + clé SSH
- [ ] Domaine acheté (`axion-pro.com` ou autre)
- [ ] Compte Cloudflare distinct créé
- [ ] Doppler ou Infisical compte créé
- [ ] Tokens API tous stockés dans Doppler : `HETZNER_API_TOKEN`, `CLOUDFLARE_API_TOKEN`, `IPROYAL_*`, `WEBSHARE_*`, `ANTHROPIC_API_KEY`, `MISTRAL_API_KEY`, `CAPTCHA_2CAPTCHA_KEY`, `OWNER_INITIAL_PASSWORD`

### Roadmap 12 sprints (cf. `spec/21_couts_roadmap.md`)

- [ ] S1 Bootstrap : infra Hetzner + Coolify + Postgres + Redis + skeletons + auth + 2FA
- [ ] S2 Patterns techniques : dedup 6 niveaux + LLM Router + Proxy pluggable + rotations
- [ ] S3 Sources officielles : INSEE + annuaire-entreprises + BODACC + Coverage Matrix
- [ ] S4 Google Maps + Pages Jaunes workers
- [ ] S5 Sites web (emails + équipe + pattern + sociaux)
- [ ] S6 Google Search Wrapper + Direction Finder + France Travail + MESRI
- [ ] S7 Crunchbase + Infogreffe + Societe.com + BAN + social light
- [ ] S8 Email finder + SMTP cascade complète
- [ ] S9 Carte France interactive 3 modes
- [ ] S10 Classification LLM + UI 17 pages + Proxy admin
- [ ] S11 Scaffold Phase 2 (5 pages) + RGPD UI + Monitoring
- [ ] S12 Pentest + DPIA + DPA + tests E2E + promotion prod

### Prompt à utiliser pour Sprint 1

Le prompt complet est dans `spec/23_interfaces_phase2_execution_pack.md` § B.4 « Prompt 1 — Bootstrap projet ».

---

## §3 — Conformité OBLIGATOIRE avant prod publique

### DPIA (Data Protection Impact Assessment)

- [ ] Produire `_DOCS/DPIA-2026.md` selon plan dans `spec/17_rgpd_aiact_owasp.md` § DPIA
- [ ] Validation DPO interne (Williams Jullin)
- [ ] Effort : 4-8 h

### DPA sous-processeurs LLM

- [ ] Signer DPA Anthropic (Trust Center)
- [ ] Signer DPA Mistral AI
- [ ] Signer DPA Cloudflare
- [ ] Signer DPA Webshare + IPRoyal + Backblaze

### Pentest interne avant promotion prod S12

- [ ] Burp Suite Community + OWASP ZAP + Nmap
- [ ] Manual tests SSRF + prompt injection
- [ ] Critère : 0 vulnérabilité High/Critical
- [ ] Effort : 1-2 j

### DR drill avant promotion prod

- [ ] Restore depuis pgbackrest sur serveur temporaire
- [ ] Mesure RTO réel (cible < 4 h)
- [ ] Validation hash chain post-restore

---

## §4 — Décisions stratégiques en attente

### Domaine final

- A. `crm.axion-pro.com` (recommandée par défaut)
- B. `console.axionprospect.io`
- C. `app.axion-crm.fr`

### Coolify v4 vs k3s

- A. Coolify v4 (recommandé démarrage)
- B. k3s (si scale dès S1)

### GPU Ollama — quand activer

- A. Plus tard (S10+ si LLM API > 300 €/mois)
- B. Dès S1

### Secrets manager

- A. Doppler (SaaS gratuit jusqu'à 5 users)
- B. Infisical (self-hosted)

---

## §5 — Actions humaines de sécurité

### Rotation password `WJullin1974/*` (CRITIQUE)

- [ ] Considère ce password comme brûlé pour TOUS services
- [ ] Au déploiement S1 : générer nouveau pwd 32 chars random (Bitwarden/1Password/KeePassXC)
- [ ] Stocker UNIQUEMENT dans Doppler comme `OWNER_INITIAL_PASSWORD`
- [ ] Au 1er login UI : activer 2FA + changer pwd via UI
- [ ] Supprimer `OWNER_INITIAL_PASSWORD` de Doppler après usage

### Surveillance abonnements à résilier si non gardés

- [ ] Webshare 10 $/mo : résilier si stratégie change

---

## §6 — Backlog Phase 2 (post-S12)

- [ ] Cold Email orchestrateur + warmup IPs SMTP
- [ ] LinkedIn Outreach automatisé (Unipile/LiCM)
- [ ] CRM pipeline kanban (deals, activités, tâches)
- [ ] Analytics avancées (funnels, cohorts, ROI)
- [ ] Achat 3-5 domaines secondaires cold email
- [ ] Compliance avocat cold email B2B

Volume Phase 2 : ~3 mois dev supplémentaires.

---

## §7 — Calendrier indicatif (si POCs verts)

```
Semaine 1-2   : Exécution 5 POCs (~150 €)
Semaine 3-4   : Souscriptions services + setup Hetzner + Doppler
Semaine 5-16  : Sprint 1 → S12 code Phase 1 (~12 sem)
Semaine 17    : Pentest + DPIA + DPA + DR drill
Semaine 18    : Promotion prod
Mois 5-7      : Phase 2 (cold email + LinkedIn + CRM + analytics)
```

Total Phase 1 GO PROD : ~4 mois calendaires.

---

## §8 — Liens utiles

- Repo GitHub : https://github.com/will383842/axion-crm-pro
- Spec sommaire : `spec/00_INDEX.md`
- Audit critique : `spec/AUDIT_v1.md`
- Roadmap coûts : `spec/21_couts_roadmap.md`
- Risques : `spec/22_risques_mitigations.md`
- Setup POCs : `poc/README.md`
- 12 prompts Claude Code : `spec/23_interfaces_phase2_execution_pack.md` § B.4

---

**À mettre à jour à chaque changement majeur (commit + push).**
