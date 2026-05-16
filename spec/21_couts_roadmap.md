# 21 — COÛTS + ROADMAP 12 SEMAINES

## Vue d'ensemble

Axion CRM Pro est conçue pour fonctionner à un coût mensuel **maîtrisé** dès la V1 : **600-700 €/mois tout compris**, soit environ **0,003 €/entreprise enrichie** au volume cible de 200 000 entreprises/mois. La roadmap 12 semaines décrit le séquencement de la livraison Phase 1 avec critères "done" mesurables par semaine.

---

## 1. Tableau coûts mensuels détaillé Phase 1

| Poste | Coût € HT/mois | Note |
|---|---|---|
| **Hetzner Cloud Compte 2** | | |
| edge-01 (CCX23) | 30,00 | Caddy reverse proxy HTTPS |
| app-01 (CCX23) | 30,00 | Laravel Octane + frontend |
| app-02 (CCX23) | 30,00 | Scheduler + Horizon master |
| db-01 (CCX33) | 60,00 | PostgreSQL 16 |
| redis-01 (CCX13) | 12,00 | Redis 7 |
| worker-php-01 (CCX23) | 30,00 | Workers PHP |
| worker-node-01 (CPX31) | 16,00 | Workers Node Playwright |
| worker-node-02 (CPX31) | 16,00 | Workers Node Playwright |
| obs-01 (CCX23) | 30,00 | Stack monitoring auto-hébergée |
| Volume backup 1 To | 40,00 | Backups locaux |
| IPv4 supplémentaires (3) | 6,00 | edge, worker-node × 2 |
| **Sous-total Hetzner V1 (sans GPU)** | **300,00** | |
| **Sous-total Hetzner V1 (avec GPU Ollama)** | **370,00** | +llm-gpu-01 EX44 dédié 70€ |
| | | |
| **Proxies** | | |
| Webshare datacenter (100 IPs) | 10,00 | Démarrage S1 |
| IPRoyal résidentiel rotating | 60,00 | À activer S5+ |
| Smartproxy résidentiel premium | 75,00 | Optionnel à partir S9 |
| **Sous-total proxies V1 phase démarrage** | **10,00** | |
| **Sous-total proxies V1 phase scale** | **145,00** | |
| | | |
| **LinkedIn** | | |
| PhantomBuster | 70,00 | (~70 $ / mois) |
| Sales Navigator (3 comptes × 99,99 $) | 280,00 | (~300 $ / mois) |
| **Sous-total LinkedIn** | **350,00** | |
| | | |
| **LLM APIs** | | |
| Anthropic Claude Haiku 4.5 | 80,00 | ~80 M tokens in + 20 M out/mois |
| OpenAI GPT-4o mini | 30,00 | fallback |
| Mistral Small | 20,00 | classifications déterministes |
| OpenRouter | 10,00 | flex divers |
| **Sous-total LLM** | **140,00** | Variable selon volume |
| | | |
| **Domaine + Cloudflare** | | |
| Namecheap `axion-ia.com` | 1,00 | (12 €/an) |
| Cloudflare Pro plan (optionnel) | 0,00 | Free plan suffit V1 |
| **Sous-total Domaine + CDN** | **1,00** | |
| | | |
| **Backups offsite** | | |
| Backblaze B2 (~200 Go) | 5,00 | $0.005/Go-mois |
| **Sous-total Backups** | **5,00** | |
| | | |
| **Monitoring auto-hébergé** | | |
| Grafana + Prometheus + Loki + Tempo + GlitchTip + Uptime Kuma | 0,00 | OSS auto-hébergés sur obs-01 |
| Slack workspace (free) | 0,00 | |
| Telegram bot | 0,00 | |
| **Sous-total Monitoring** | **0,00** | |
| | | |
| **Carto** | | |
| MapLibre GL JS | 0,00 | OSS |
| OpenFreeMap tiles | 0,00 | gratuit illimité |
| IGN AdminExpress COG 2026 | 0,00 | Open License Etalab |
| api-adresse.data.gouv.fr (BAN) | 0,00 | gratuit illimité officiel |
| **Sous-total Carto** | **0,00** | |
| | | |
| **Données B2B** | | |
| INSEE Sirene API | 0,00 | gratuit (30 req/min) |
| BODACC API | 0,00 | gratuit |
| annuaire-entreprises.data.gouv.fr | 0,00 | gratuit |
| France Travail API | 0,00 | gratuit |
| MESRI/ONISEP open data | 0,00 | gratuit |
| **Sous-total Données B2B** | **0,00** | |
| | | |
| **Secrets vault** | | |
| Infisical self-hosted | 0,00 | OSS sur obs-01 |
| **Sous-total Secrets** | **0,00** | |

### Récapitulatif total

| Configuration | Total € HT/mois |
|---|---|
| **V1 démarrage minimal (S1-S5)** | Hetzner 300 + proxies 10 + LinkedIn 350 + LLM 80 + autres 6 = **~746 €** |
| **V1 confort (S6-S12)** | Hetzner 300 + proxies 70 + LinkedIn 350 + LLM 140 + autres 6 = **~866 €** |
| **V1 avec GPU Ollama** | + 70€ GPU = **~936 €** |

> En soustrayant les phases progressives (proxy IPRoyal pas activé S1, LLM coût croît avec le volume), la **cible moyenne S1-S5 = 600-700 €/mois**, **cible S6-S12 = 750-900 €/mois**.

### Coût par entreprise enrichie

| Volume mois | Coût total | Coût/entreprise |
|---|---|---|
| 10 000 (S1-S5 ramp-up) | ~746 € | 0,075 € |
| 50 000 (S6-S8) | ~800 € | 0,016 € |
| 200 000 (S9+) | ~900 € | **~0,005 €** |
| 1 000 000 (Phase 2 scale) | ~1500 € (estimé) | ~0,0015 € |

**Cible** : ~0,003 €/entreprise enrichie à régime de croisière 200k/mois (mention en exec summary). Très en deçà des concurrents (Apollo ~$0,10, Hunter ~$0,02-0,05).

---

## 2. Roadmap 12 semaines

> Chaque semaine = 5 jours de travail. Critères "done" mesurables. Coût Will = dev solo full-time + supervision Claude Code.

### Semaine 1 — Setup infra + skeleton

**Objectif :** poser les fondations.

Tâches :
- Provisionner Hetzner Compte 2 (10 serveurs + vSwitch)
- Créer DB PostgreSQL 16 + extensions
- Appliquer toutes les migrations Phase 1 + Phase 2 scaffold (~83 tables)
- Setup Laravel 12 + Sanctum + Spatie Permission + Horizon
- Setup React 19 + Vite 6 + Tailwind 4 + skeleton 5 pages (login + dashboard + companies + coverage + admin)
- DNS `crm.axion-ia.com` → edge-01 + Cloudflare proxy ON
- TLS Caddy Let's Encrypt actif
- GitHub Actions CI/CD initial

**Done :**
- [ ] Healthcheck `https://crm.axion-ia.com/api/monitoring/health` retourne 200
- [ ] Login Will fonctionne avec 2FA (seed user + workspace `Axion-IA`)
- [ ] Migration tables OK + RLS appliquée (test cross-tenant fuzz)

### Semaine 2 — Patterns techniques + LLM Router

**Objectif :** poser les couches transverses critiques.

- Anti-ban : rotation user-agents 50+ + stealth plugins Playwright
- Rotation proxies pluggable (interface + Webshare seul)
- Déduplication 6 niveaux (services + tests)
- Email finder pattern generator (18 patterns)
- SMTP validator cascade 5 niveaux
- LLM Router 5 providers (mocks + tests)
- Tables `llm_use_cases`, `llm_providers`, `prompt_template_versions` seedées

**Done :**
- [ ] LLM Router test : `generate('test_use_case', ...)` route vers primary, fallback fonctionne (mocked)
- [ ] SMTP validator : cascade complète testée sur 10 emails (valid/invalid/catchall)
- [ ] Webshare proxy lease + report fonctionne

### Semaine 3 — Sources INSEE + annu-ent + BODACC + Coverage Matrix

**Objectif :** récupérer les premières entreprises FR.

- Plugin INSEE Sirene
- Plugin annuaire-entreprises.data.gouv.fr
- Plugin BODACC
- Materialized view `coverage_matrix_cells` + refresh job hourly
- Page `/companies` liste virtualisée + filtres basiques
- Page `/coverage` carte MapLibre embryon (region level)

**Done :**
- [ ] Import 10 000 entreprises Paris depuis INSEE en < 30 min
- [ ] Coverage matrix refresh < 30s
- [ ] Page `/companies` affiche 10k entreprises sans lag

### Semaine 4 — Sources Google Maps + Pages Jaunes

**Objectif :** premier scraping Playwright.

- Worker Node.js Playwright stealth bootstrap
- Plugin gmaps (BullMQ → `gmaps-scrape`)
- Plugin Pages Jaunes (BullMQ → `pj-scrape`)
- Communication `scrape-results` queue Node → PHP
- Stockage `company_addresses`, `company_phones`, `company_emails` génériques

**Done :**
- [ ] Worker scrape Google Maps sur "Boulangerie Paris 75" → 100+ business
- [ ] Aucun captcha sur 50 runs successifs (stealth fonctionne)
- [ ] PJ scraping pagination sans limite jusqu'à fin réelle

### Semaine 5 — Source Sites web (CŒUR EMAIL FINDER)

**Objectif :** extraction email exhaustive.

- Plugin `website-crawler` (cheerio + Playwright fallback)
- Extraction TOUS emails + classification (nominative/role_based/generic/no_reply)
- Détection pattern email entreprise (heuristique + LLM fallback)
- Détection comptes sociaux + mots-clés stratégiques
- Use cases LLM : `extract_team_from_page`, `extract_strategic_keywords`, `detect_email_pattern`

**Done :**
- [ ] Crawl 100 sites entreprises → moyenne 5 emails/site extraits
- [ ] Pattern email entreprise détecté correctement sur ≥ 80 % des entreprises avec ≥ 3 emails nominatifs
- [ ] 0 email `no_reply` ne passe en `is_excluded = false`

### Semaine 6 — Source LinkedIn (PhantomBuster) + France Travail + écoles

**Objectif :** C-level et signaux d'achat.

- Plugin LinkedIn via PhantomBuster (3 comptes Sales Nav configurés)
- Rotation `linkedin_accounts` + état (active/rate_limited/cooldown/suspicious/banned)
- Plugin France Travail (signaux recrut_clevel)
- Plugin MESRI/ONISEP (écoles + universités)

**Done :**
- [ ] Recherche LinkedIn "DSI ETI Île-de-France" via PB → 50 profils enrichis
- [ ] Rotation comptes LinkedIn : daily_used reset minuit + auto-bascule actif/rate_limited
- [ ] 1 000 écoles importées depuis MESRI

### Semaine 7 — Sources Crunchbase + Infogreffe + Societe.com fallbacks + BAN géocodage + social light

**Objectif :** compléter le panel sources.

- Crunchbase scraping prudent (hebdo, résidentiel)
- Infogreffe Playwright fallback
- Societe.com Playwright fallback (résidentiel obligatoire)
- BAN géocodage tous les SIREN sans `geom_point`
- Social light : handles X/Insta/TikTok/FB/YouTube/GitHub

**Done :**
- [ ] 95 %+ des companies ont `geom_point` non null
- [ ] Crunchbase pull hebdo extrait 50+ levées FR/semaine
- [ ] Au moins 1 handle social détecté sur ≥ 30 % des companies

### Semaine 8 — Email finder validation SMTP complète

**Objectif :** validation industrielle des emails.

- Workers `email-validate` dédiés (2 instances, IPs séparées)
- DNS records SPF/DKIM/DMARC pour `validator.axion-ia.com`
- Cache TTL 30 jours sur `email_verifications`
- Job `RevalidateExpiredEmailsJob` nightly

**Done :**
- [ ] 10 000 emails validés (mix nominative + role_based) en < 30 min
- [ ] Taux faux positifs < 3 % (validation manuelle sur sample 100)
- [ ] Cache 30j fonctionne : re-validate = 0 SMTP call

### Semaine 9 — Carte France interactive (3 modes)

**Objectif :** interface de pilotage central.

- MapLibre GL JS + OpenFreeMap + IGN AdminExpress import
- Composant `<FranceCoverageMap />` 3 modes (visualization / search / action)
- API `/api/coverage/matrix`, `/api/coverage/zones/{type}/{code}`
- `<ZoneDetailPanel>` avec stats + actions launch scraping
- `<CitySearchBar>` auto-suggest 2157 villes

**Done :**
- [ ] Carte charge < 2,5 s p95 sur 4G
- [ ] FPS ≥ 30 au zoom 9 avec 2157 communes
- [ ] Clic zone → launch scraping zone fonctionne end-to-end

### Semaine 10 — Classification LLM + proxy providers UI

**Objectif :** intelligence métier + autonomie ops.

- Use cases LLM activés : `ia_maturity_scoring`, `axion_offer_match`, `auto_tag_generation`
- Calcul `priority_score` + `contact_priority` (formules fichier 08)
- Page `/proxies` : ajouter/désactiver providers depuis admin
- UI rotations dashboard `/rotations` (5 onglets)

**Done :**
- [ ] 5 000 entreprises classifiées avec `axion_offer` ≠ null
- [ ] Coût moyen LLM par classification ≤ 0,0015 €
- [ ] Ajout d'un nouveau provider de proxies depuis admin sans redéploiement OK

### Semaine 11 — Scaffold complet UI Phase 2 + monitoring stack

**Objectif :** terminer scaffold Phase 2 + observabilité.

- 5 pages Phase 2 placeholders implémentées (Campaigns, Cold Email, LinkedIn Outreach, CRM, Analytics)
- 10 dashboards Grafana provisionnés
- Alertmanager rules + 3 channels (Slack/Telegram/email)
- Loki ingestion logs PHP + Node
- Uptime Kuma 6 monitors externes

**Done :**
- [ ] 5 pages Phase 2 affichent "Module en développement"
- [ ] Test alerting : kill -9 postgres → alerte reçue en < 2 min sur les 3 canaux
- [ ] Dashboard "Vue exécutive" lisible par Will sans formation

### Semaine 12 — Polish UI + RGPD + tests E2E + go-live

**Objectif :** prêt prod publique.

- 17 pages Phase 1 complètes + audit UI/UX par Will
- Page `/gdpr` RGPD complete (requêtes + opt-out + registre)
- Audit log hash chain integrity verification job
- Tests Playwright E2E 5 parcours clés
- Documentation utilisateur `/docs/` Markdown
- Snapshot Hetzner pré-go-live
- Migration prod (Coolify trigger)

**Done :**
- [ ] 5 parcours E2E verts (login, scrape zone carte, override score, RGPD erasure, LLM test)
- [ ] Penetration test léger (burp + zap) : 0 HIGH ou CRITICAL
- [ ] 200 000 entreprises totales en base (cumulé)
- [ ] Will signe le go-live

---

## 3. Effort dev solo estimé par bloc

| Bloc | Semaines | Heures estimées | Avec Claude Code |
|---|---|---|---|
| Fondations (S1-S2) | 2 | ~80h | ~50h (gain 35%) |
| Sources scraping (S3-S7) | 5 | ~200h | ~140h |
| Email finder + valid (S8) | 1 | ~40h | ~25h |
| Carte (S9) | 1 | ~40h | ~25h |
| Classification + UI ops (S10) | 1 | ~40h | ~25h |
| Scaffold + monitoring (S11) | 1 | ~40h | ~25h |
| Polish + RGPD + tests (S12) | 1 | ~40h | ~30h |
| **TOTAL Phase 1** | **12** | **~480h** | **~320h** |

Avec Claude Code Opus 4.7 1M context : **~6-7 semaines effectives de Will** au lieu de 12 sans assistance (gain ~40 %).

---

## 4. Roadmap au-delà de S12 (préparation Phase 2)

- **S13-S15** : observation production, ajustements perf, scale proxies si volume > 50k req/jour
- **S16-S20** : implémentation Cold Email Hub (sending domains, SMTP IPs, warmup, deliverability tracking, sequences engine)
- **S21-S24** : implémentation LinkedIn Outreach (campagnes, sequences, anti-detection avancé)
- **S25-S28** : implémentation CRM Hub (pipeline kanban, deals, activités, tasks)
- **S29-S32** : Analytics avancées (funnels, cohorts, ROI)
- **S33-S36** : Orchestrateur multi-canal (campagnes croisées Email × LinkedIn × CRM)

Phase 2 ≈ 24 semaines effort dev solo avec Claude Code.

---

## 5. Validation budget mensuel

Une alerte Alertmanager déclenche notif Telegram + email à Will dès que :
- Coût Hetzner courant projection mois > 350 €
- Coût LLM cumul mois > 300 €
- Coût proxies cumul mois > 200 €
- **Total cumulé mois > 800 €** → CRITICAL : revoir routing LLM + proxy allocation

---

## 6. Anti-patterns interdits côté coûts

- ❌ Activer BrightData avant que la volumétrie le justifie (S13+ minimum)
- ❌ Router systématiquement vers Claude Sonnet (5× plus cher que Haiku — réserver aux VIP Phase 2)
- ❌ Doubler les workers Node "pour être tranquille" (chaque worker = 16 €/mois)
- ❌ Stocker tous les logs > 30j (Loki coûts disk explosent)
- ❌ Charger 200k entreprises sans pagination (perf + coûts CPU app-01)

---

## Prochaine étape

→ Lire `22_risques_mitigations.md` pour le top 15 risques + mitigations.
