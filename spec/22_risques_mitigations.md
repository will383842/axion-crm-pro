# 22 — RISQUES + MITIGATIONS

## Vue d'ensemble

Cette section liste les **15 risques majeurs** (techniques + légaux + opérationnels) susceptibles d'affecter Axion CRM Pro V1, classés par **probabilité × impact**, avec mitigation détaillée pour chacun. Cette liste est l'output direct du chain-of-thought architecte (fichier 01). Elle doit être relue trimestriellement et mise à jour.

---

## Risque 1 — Ban IP massif simultané sur plusieurs sources

**Probabilité :** moyenne | **Impact :** high

**Scénario :** Google Maps + Pages Jaunes + Société.com + LinkedIn bloquent tous nos IPs le même jour (incident type pic d'activité ou détection coordonnée anti-bot).

**Conséquence :** chaîne d'enrichissement s'effondre, objectif 7 000 entreprises/jour inatteignable, perte temporaire de revenu commercial.

**Mitigation :**
- 4 providers de proxies en parallèle dès le démarrage (Webshare + IPRoyal + Smartproxy + BrightData désactivé V1)
- `ProxyRouter` intelligent route automatiquement vers les providers sains
- Cool-down automatique 24h sur ban → bascule auto vers autres providers
- Circuit breaker par source : si > 50 % d'échecs sur 30 min → désactivation temporaire 2h
- Alerte Slack `#axion-crm-alerts` immédiate sur dépassement seuil
- Plan B : basculer en mode "API officielle seule" (INSEE + annuaire-entreprises + BODACC + France Travail) qui ne dépendent pas de proxies, perte d'enrichissement Google Maps/PJ/sites web acceptable 24-48h.

---

## Risque 2 — LinkedIn change ses défenses, PhantomBuster tombe

**Probabilité :** moyenne | **Impact :** high

**Scénario :** LinkedIn renforce ses défenses anti-bot (mass ban) ou PhantomBuster lui-même est compromis ou augmente massivement ses tarifs.

**Conséquence :** perte unique de la source C-level non-dirigeants (DRH, DAF, DSI, Marketing, Commercial). On garde les dirigeants légaux via annuaire-entreprises mais on perd ~40 % des contacts cibles.

**Mitigation :**
- Architecture plugin isolée : `LinkedInPhantomBusterPlugin` swappable
- Plans B identifiés :
  - **Captain Data** (alternative PB française, ~150 €/mois)
  - **Apify Linkedin Profile Scraper** (~$50/mois mais qualité variable)
  - **Manuel ciblé** : recherche Sales Nav manuelle par Will sur les 200 entreprises prioritaires/mois
- Acceptation de basculer en mode dégradé pendant 2-4 semaines le temps de patcher

---

## Risque 3 — annuaire-entreprises.data.gouv.fr change la structure

**Probabilité :** faible-moyenne | **Impact :** high

**Scénario :** la source remplaçant Pappers change son endpoint API ou son format HTML.

**Conséquence :** perte dirigeants légaux + CA + bilans. C'est notre source #1 légale post-Pappers.

**Mitigation :**
- Double approche : API officielle (`/api/recherche?q=...`) + scraping HTML cheerio comme backup
- Monitoring alerting sur drop > 30 % taux de succès (`axion_scraper_runs_total{source_key="annu_ent",status="error"}`)
- Fallback chain : `annu_ent` → `infogreffe` → `societe.com`
- Plan B : si tout casse, on accepte une couverture dégradée 5-7 jours, l'équipe Etalab répond en général sous 48h sur les changements de schéma

---

## Risque 4 — INSEE rate limits insuffisants pour volume

**Probabilité :** moyenne | **Impact :** medium

**Scénario :** 30 req/min INSEE devient insuffisant à scale (200k+ companies à parser).

**Conséquence :** retards d'enrichissement, queue depth qui explose.

**Mitigation :**
- Demande de palier "confirmé" INSEE après 30 jours d'usage stable (60 req/min)
- Batching maximisé (1000 SIREN par requête bulk)
- Cache local : ne pas re-fetcher un SIREN avant TTL 30 jours
- Plan B : si INSEE absolument insuffisant, basculer sur extracts CSV INSEE Sirene (mises à jour mensuelles publiées sur data.gouv.fr) — moins frais mais suffisant pour volume

---

## Risque 5 — Coût LLM explose (mauvais routing)

**Probabilité :** moyenne-haute | **Impact :** high

**Scénario :** un use case mal routé vers Claude Sonnet au lieu de Haiku, ou un prompt qui génère 10× trop de tokens output.

**Conséquence :** facture mensuelle multipliée par 3-10 → budget 250 € → 750 € voire 2 500 € rapidement.

**Mitigation :**
- Cost tracking par requête (table `llm_usage` partitionnée mois)
- Alerte Alertmanager à 80 % / 95 % / 100 % du budget mensuel
- Routing par défaut sur **modèles les moins chers** (Mistral Small / Haiku 4.5)
- Validation manuelle obligatoire avant routing d'un use case vers Sonnet ou Opus
- Auto-kill switch : si dépassement > 300 €/mois, désactivation automatique des use cases non-critiques (cf cascade cost cap)
- Plan B : basculer use cases sur Ollama local (gratuit après amortissement GPU 70 €/mois)

---

## Risque 6 — PostgreSQL bottleneck à 1M+ rows

**Probabilité :** moyenne (Phase 2 scale) | **Impact :** high

**Scénario :** à 1M+ entreprises × moyenne 5 contacts × moyenne 10 emails, certaines requêtes deviennent lentes (> 5s).

**Conséquence :** dégradation UX (page `/companies` lente, exports CSV timing out, refresh matrix > 10 min).

**Mitigation :**
- Partitionnement pg_partman activé sur `scraper_runs`, `llm_usage`, `proxy_usage_log`
- Indexes GIN sur JSONB + pg_trgm sur recherches fuzzy
- Materialized view `coverage_matrix_cells` (refresh hourly)
- Virtualization frontend (TanStack Virtual) pour 200k+ lignes
- Plan B Phase 2 : replica logique lecture (read-only) sur `db-02` pour offload reporting + analytics
- Plan B extrême : sharding par workspace_id (déjà préparé via RLS)

---

## Risque 7 — IGN change format AdminExpress

**Probabilité :** faible | **Impact :** medium

**Scénario :** IGN refactor le format AdminExpress (changement de SRID, propriétés, structure shapefile).

**Conséquence :** carte de France ne charge plus correctement.

**Mitigation :**
- Snapshot des données IGN (versionnées AdminExpress COG 2026) stocké en Backblaze B2
- Possibilité de rester sur la version archivée pendant 6-12 mois le temps d'adapter
- Plan B : OpenStreetMap-based polygones via OSM Boundaries — alternative complète gratuite

---

## Risque 8 — OpenFreeMap ferme ou rate-limit

**Probabilité :** moyenne (jeune service) | **Impact :** medium

**Scénario :** OpenFreeMap (service tiles gratuit que nous utilisons) ferme ou impose des limites.

**Conséquence :** carte de France ne charge plus.

**Mitigation :**
- Variable env `MAP_STYLE_URL` éditable sans redéploiement
- Plans B identifiés :
  1. Self-host tiles avec `protomaps/tile-server` + dataset Protomaps Daylight (70 Go offline)
  2. Bascule sur MapTiler Cloud free tier (100k tile loads/mois) — suffisant
  3. Bascule sur Mapbox dev tier (50 000 loads gratuits/mois) — payant si dépassement
- Surveillance Uptime Kuma de `https://tiles.openfreemap.org/styles/positron`

---

## Risque 9 — Plainte CNIL sur scraping massif

**Probabilité :** faible-moyenne | **Impact :** très high (réputationnel + sanctions)

**Scénario :** un dirigeant scrapé porte plainte CNIL pour traitement abusif de ses données personnelles.

**Conséquence :** instruction CNIL + sanction potentielle (jusqu'à 4 % CA mondial, mais en pratique < 100k€ pour 1er incident), réputation Axion-IA atteinte.

**Mitigation :**
- Base légale documentée : intérêt légitime art. 6.1.f RGPD (prospection B2B nominative)
- Registre RGPD intégré (table `data_processing_log`) avec 7 traitements documentés
- Aucun email personnel scrapé (uniquement domaines pro)
- `opt_out` cross-workspace consulté avant scraping/enrichissement/contact
- Droit d'accès / suppression traité < 30 jours (table `gdpr_requests`)
- DPO joignable `contact@axion-ia.com`
- Mention légale + politique de confidentialité publiées (`/legal/privacy`)
- DPA signés avec sous-processeurs (PhantomBuster US → SCC)
- Plan B : embauche cabinet avocat spécialisé RGPD à la moindre alerte

---

## Risque 10 — AI Act se durcit (passage `limited` → `high`)

**Probabilité :** faible (court terme) | **Impact :** high

**Scénario :** réforme AI Act ou interprétation jurisprudentielle classe le profilage automatique d'entreprises B2B comme `high risk`.

**Conséquence :** obligations supplémentaires : analyse d'impact obligatoire, journalisation renforcée, transparence accrue, certification éventuelle.

**Mitigation :**
- Table `ai_act_register` déjà créée et remplie pour les 10 use cases LLM
- Documentation transparence accessible sur `/legal/ai-act`
- Human-in-the-loop activé partout (override manuel score Axion-IA, validation manuelle classification)
- Plan B : si `high risk`, ajouter explicabilité (LLM doit fournir `rationale` détaillé), DPIA documenté

---

## Risque 11 — Hetzner suspend pour abuse (scraping massif)

**Probabilité :** faible-moyenne | **Impact :** high

**Scénario :** Hetzner détecte une activité de scraping massif depuis nos IPs (workers Node Playwright vers Google Maps, etc.) et suspend les comptes.

**Conséquence :** plateforme down brutalement, migration urgente vers autre provider.

**Mitigation :**
- **Tout le scraping passe par proxies** (Webshare/IPRoyal/Smartproxy). Hetzner IPs ne touchent JAMAIS Google Maps / PJ / Crunchbase directement
- ToS Hetzner respectés strictement (pas de hosting illégal, pas de spam SMTP, etc.)
- Pre-warning : monitoring sortants Hetzner IPs pour détecter toute requête bypassant les proxies (`netfilter` rule)
- Plan B : compte Scaleway secondaire prêt à provisionner (snapshots + IaC Terraform si on a le temps Phase 2)

---

## Risque 12 — Bug data corruption multi-tenant (leak workspace)

**Probabilité :** très faible | **Impact :** très high (RGPD critical)

**Scénario :** bug Laravel / oubli `where workspace_id` / désactivation RLS → un workspace voit les données d'un autre.

**Conséquence :** incident RGPD majeur (notification CNIL sous 72h obligatoire), perte de confiance future si on ouvre la plateforme à d'autres workspaces.

**Mitigation :**
- **Defense in depth** : middleware Laravel `InjectWorkspace` + RLS PostgreSQL au niveau DB
- Tests fuzzing automatisés cross-workspace (2 workspaces fictifs, requêtes croisées) en CI
- Pas de bypass RLS (super_admin role réservé, jamais utilisé par l'app)
- Audit log hash chain : toute action est tracée
- Plan B : procédure RGPD breach notification documentée (template prêt + délai 72h)

---

## Risque 13 — Dev solo (Will) qui s'absente longue durée

**Probabilité :** moyenne | **Impact :** high (continuité business)

**Scénario :** maladie / accident / autre projet → Will hors-jeu plusieurs mois.

**Conséquence :** plateforme tournante mais non maintenue, dette technique cumulée, opportunités commerciales loupées.

**Mitigation :**
- **Spec exhaustive** (ce document — 24 fichiers Markdown) permet à un dev externe de reprendre rapidement
- Stack standard (Laravel + React + Postgres) → marché du recrutement large
- Documentation runbooks (cf fichier 18) pour incidents typiques
- Monitoring complet auto-alertant (Slack/Telegram) → un proche peut au moins savoir si la plateforme est down
- Plan B : enveloppe budgétaire prévue pour engager un dev backup sur 3 mois si besoin (~9-12k€)

---

## Risque 14 — Vol/perte des credentials

**Probabilité :** faible | **Impact :** très high

**Scénario :** machine de Will compromise / API tokens leakés.

**Conséquence :** accès non autorisé à Hetzner / Cloudflare / Anthropic / OpenAI / Backblaze, potentiellement destruction de données.

**Mitigation :**
- Secrets dans **Infisical self-hosted** vault (jamais en `.env` ni en Git)
- 2FA obligatoire sur tous les comptes externes (Hetzner, Cloudflare, GitHub, OpenAI, Anthropic, Backblaze)
- API tokens rotables — procédure rotation documentée (fichier 18 §9)
- Backup vault Infisical chiffré offsite (Backblaze)
- GPG-armored backups Postgres → un attaquant qui obtient le dump ne peut pas le lire sans la passphrase
- Plan B : workflow "panic" qui révoque tous les tokens en 1 commande (`scripts/revoke-all-tokens.sh`)

---

## Risque 15 — Pic de volume non anticipé (succès commercial)

**Probabilité :** faible-moyenne (souhaitable !) | **Impact :** medium

**Scénario :** Will signe 3 grands clients en parallèle → besoin de scraper 500k entreprises supplémentaires en 1 mois au lieu d'étalé sur 2-3 mois.

**Conséquence :** Hetzner CPU saturé, LLM coûts × 3, proxies pool insuffisant, queue depth qui explose.

**Mitigation :**
- Infrastructure scalable : ajout horizontal app-03, worker-php-02, worker-node-03/04 en 2h chacun
- Coolify : `replicas: N` éditable runtime
- BrightData proxies massifs prêts à être activés (300-500 €/mois)
- LLM Router : Llama 3.3 70B local sur GPU = capacité illimitée sans coût marginal
- Budget alerting permet de prévenir Will avant explosion
- Plan B : étaler les nouvelles missions sur 6-8 semaines au lieu de 1 mois (négociation client)

---

## 16. Tableau de synthèse (par criticité)

| # | Risque | Proba | Impact | Score | Priorité mitigation |
|---|---|---|---|---|---|
| 5 | Coût LLM explose | Haute | High | 9 | 🔴 S1-S2 |
| 9 | Plainte CNIL | Faible-moy | Très high | 9 | 🔴 S1-S2 |
| 12 | Bug multi-tenant | Très faible | Très high | 8 | 🔴 S1-S2 |
| 1 | Ban IP massif | Moyenne | High | 7 | 🟠 S2-S3 |
| 2 | LinkedIn / PB | Moyenne | High | 7 | 🟠 S6-S8 |
| 11 | Hetzner suspend | Faible-moy | High | 6 | 🟠 S1-S2 |
| 13 | Will absent | Moyenne | High | 6 | 🟠 transverse |
| 14 | Vol credentials | Faible | Très high | 6 | 🟠 S1 |
| 3 | annu-ent change | Faible-moy | High | 5 | 🟡 S5 |
| 6 | Postgres bottleneck | Moyenne | High | 5 | 🟡 S11 + Phase 2 |
| 4 | INSEE rate limits | Moyenne | Medium | 4 | 🟡 S3 |
| 10 | AI Act se durcit | Faible | High | 4 | 🟡 trimestriel |
| 8 | OpenFreeMap ferme | Moyenne | Medium | 4 | 🟡 S9 |
| 15 | Pic volume | Faible-moy | Medium | 4 | 🟡 S12 |
| 7 | IGN change format | Faible | Medium | 3 | 🟢 trimestriel |

**Score = Proba (1-3) × Impact (1-3)** — code couleur : 🔴 ≥ 7 / 🟠 5-6 / 🟡 3-4 / 🟢 ≤ 2

---

## 17. Process de revue trimestrielle

Tous les 3 mois, Will (ou DPO si embauché) :
1. Relit cette liste de 15 risques
2. Ajuste les probas selon retour d'expérience
3. Identifie 1-2 nouveaux risques émergents
4. Documente dans `risk_review_<YYYY-Q>.md` (Git versionné)
5. Met à jour le top 5 risques sur le dashboard exécutif

Premier review prévu **2026-08-31** (3 mois après go-live S12).

---

## Prochaine étape

→ Lire `23_interfaces_phase2_execution_pack.md` pour interfaces Phase 1↔2 + code generation roadmap + 12 prompts Claude Code.
