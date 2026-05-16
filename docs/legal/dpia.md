# DPIA — Data Protection Impact Assessment

> **Analyse d'impact relative à la protection des données** (art. 35 RGPD)
>
> **Responsable de traitement :** Axion-IA OÜ
> **DPO :** `contact@axion-ia.com`
> **Version :** 1.0
> **Date :** 2026-05-16
> **Statut :** validé avant mise en production Axion CRM Pro V1
> **Revue :** annuelle ou en cas de changement substantiel du traitement
> **Méthodologie :** **PIA CNIL 2.0** (référentiel français) + lignes directrices WP248 G29

## Pourquoi ce DPIA ?

L'art. 35 RGPD impose une DPIA en cas de **traitement susceptible d'engendrer un risque élevé pour les droits et libertés** des personnes. Le profilage automatique systématique est l'un des cas listés par la CNIL (Liste des traitements soumis à DPIA, déclaration publiée au JO 2018-11-06, item 7).

Axion CRM Pro V1 effectue du **profilage automatique d'entreprises et de personnes physiques** (dirigeants, C-level) via :
- Scoring automatique de maturité IA (`ia_maturity_scoring`)
- Recommandation automatique d'offre commerciale (`axion_offer_match`)
- Calcul de score de priorité (`priority_score`)
- Calcul de priorité de contact (`contact_priority`)
- Tagging automatique (`auto_tag_generation`)

Bien que ces décisions n'aient **pas d'effet juridique contraignant** (l'opérateur humain prend la décision finale de contacter ou non), elles **affectent significativement** les personnes concernées (être ciblé ou non par une prospection). Par prudence, une DPIA est conduite.

---

## 1. Description du traitement

### 1.1 Finalités

| Use case LLM | Finalité opérationnelle |
|---|---|
| `ia_maturity_scoring` | Classifier la maturité IA d'une entreprise (decouverte / en_cours / avancee / inconnue) |
| `axion_offer_match` | Recommander l'offre Axion-IA la plus pertinente (audit_flash / audit_cible / mission_pme / mission_eti / grand_programme / non_cible) |
| `auto_tag_generation` | Générer des tags business automatiques pour faciliter la segmentation |
| `extract_strategic_keywords` | Détecter mots-clés stratégiques sur le site web entreprise |
| `business_signal_detection` | Classifier les annonces BODACC / France Travail / news en signaux d'achat |
| `extract_team_from_page` | Parser les pages "équipe" pour identifier les contacts |
| `parse_company_description` | Résumer description entreprise en 500 caractères |
| `detect_email_pattern` | Inférer le pattern email de l'entreprise (`{first}.{last}@`...) |
| `geocoding_disambiguation` | Trancher entre plusieurs candidats géocodage BAN |
| `sector_classification` | Classifier secteur NAF si manquant |

### 1.2 Données traitées

| Catégorie | Exemples | Source |
|---|---|---|
| Identité pro entreprise | SIREN, raison sociale, NAF, effectif, CA, adresse | INSEE, annuaire-entreprises, BODACC |
| Identité pro personne | nom, prénom, fonction, employeur, email pro nominatif | sites web entreprises, LinkedIn (PhantomBuster), annuaire-entreprises |
| Données dérivées (scores) | maturité IA, offre recommandée, priorité contact, tags | calculées par LLM Router |
| Signaux business | recrutements, levées de fonds, redressements | France Travail, BODACC, Crunchbase, news FR |

**Aucune donnée sensible** au sens de l'art. 9 RGPD n'est traitée (pas de santé, opinions politiques, religion, etc.).

### 1.3 Catégories de personnes concernées

- Dirigeants légaux et C-level d'entreprises commerciales françaises (TPE/PME/ETI/GE)
- Décideurs académiques (universités, écoles)
- Personnes agissant dans un cadre exclusivement professionnel

### 1.4 Destinataires des données

- Personnel d'Axion-IA habilité (Will + futurs operators avec rôle Spatie Permission)
- Sous-processeurs LLM (Anthropic, OpenAI, Mistral, OpenRouter) — DPA signés
- Sous-processeur scraping (PhantomBuster US — DPA + SCC à signer)
- Sous-processeurs infrastructure (Hetzner, Cloudflare, Backblaze) — DPA signés

### 1.5 Durées de conservation

- Données entreprises : 730 jours après dernière interaction ou MAJ
- Logs scraping : 90 jours puis purge auto
- Audit logs : 365 jours (puis archivage hash chain conservé)
- Email verifications : 30 jours (TTL revalidation)

---

## 2. Mesures techniques et organisationnelles

### 2.1 Mesures techniques

| Mesure | Implémentation |
|---|---|
| **Chiffrement in-transit** | TLS 1.3 (Caddy + Let's Encrypt + HSTS 12mo preload) |
| **Chiffrement at-rest** | Backups Postgres chiffrés GPG AES-256, vault Infisical pour secrets |
| **Isolation tenant** | RLS PostgreSQL au niveau DB (defense in depth, pas seulement applicatif) |
| **Auth** | Sanctum cookie SPA + TOTP 2FA obligatoire + magic link backup |
| **RBAC** | Spatie Permission 4 rôles + ~50 permissions granulaires |
| **Audit log** | Append-only hash chain SHA-256 (triggers PG bloquent UPDATE/DELETE) |
| **Minimisation** | Pas d'emails perso, pas de données sensibles, pas de raw HTML stocké inutilement |
| **Pseudonymisation** | IPs hashées SHA-256 dans logs, pas en clair |
| **Monitoring** | Alertes anomalies + 40+ métriques Prometheus + Grafana |
| **Backup + DR** | RPO 1h / RTO 4h documentés, restore mensuel testé |
| **Headers sécurité** | CSP nonce, HSTS, X-Frame DENY, Permissions-Policy |
| **Rate limiting** | Par IP / user / workspace |
| **SSRF protection** | `UrlSsrfGuard` sur toute URL user-controlled (audit P0 #3) |
| **Prompt injection guard** | `PromptInjectionGuard` sur inputs LLM (audit P0 #10) |

### 2.2 Mesures organisationnelles

- DPO joignable `contact@axion-ia.com` (interim Will jusqu'à embauche dédiée — engagement formalisé pour transfert à un DPO indépendant si chiffre d'affaires > 1 M€ ou si embauche d'un second employé)
- Procédure droits CNIL documentée (cf spec fichier 17 §2)
- LIA documenté (`docs/legal/lia.md`)
- Registre des traitements (table `data_processing_log`)
- Sensibilisation Will : revue trimestrielle des risques (cf spec fichier 22 §17)
- DPA signés ou à signer avec tous les sous-processeurs

### 2.3 Human-in-the-loop systématique

**🔑 Mesure clé pour ce DPIA :** aucune décision automatique du LLM Router ne s'applique sans possibilité d'override humain :

- L'opérateur peut **forcer** `priority_score`, `axion_offer`, `contact_priority`, `ia_maturity` (cf spec fichier 08 §6)
- Tout override est audit-logué (`audit_logs.action = company.override.priority`)
- Le `companies.priority_override` est préservé même en cas de re-classification LLM (anti-overwrite)
- Métrique `axion_llm_human_override_rate` exposée (à monitorer pour détecter dérives LLM)
- L'utilisateur final (la personne profilée) peut exercer son droit d'opposition art. 21 → opt-out cross-workspace immédiat

---

## 3. Analyse des risques

### 3.1 Risque RGPD #1 — Accès illégitime aux données

| Vraisemblance | Gravité | Risque résiduel |
|---|---|---|
| Faible | Modérée | **Acceptable** |

**Mesures :** RLS PostgreSQL + RBAC + 2FA obligatoire + audit log hash chain + monitoring auth_failed_logins + headers sécurité + rate limiting.

**Vrai positif si...** Will perd ses credentials machine principale (cf risque #14 fichier 22).

### 3.2 Risque RGPD #2 — Modification non désirée des données

| Vraisemblance | Gravité | Risque résiduel |
|---|---|---|
| Très faible | Modérée | **Acceptable** |

**Mesures :** audit log hash chain SHA-256 + triggers PG bloquant UPDATE/DELETE sur `audit_logs` + vérification intégrité quotidienne + backups quotidiens chiffrés offsite B2 + version control Git pour migrations DB.

### 3.3 Risque RGPD #3 — Disparition des données

| Vraisemblance | Gravité | Risque résiduel |
|---|---|---|
| Très faible | Modérée | **Acceptable** |

**Mesures :** backup PG quotidien GPG → Backblaze B2 offsite, WAL archivés hourly, RPO 1h / RTO 4h documentés, test restore mensuel automatisé (audit P1 #20).

### 3.4 Risque RGPD #4 — Décision automatisée affectant indûment une personne

| Vraisemblance | Gravité | Risque résiduel |
|---|---|---|
| Très faible | Modérée | **Acceptable** |

**Mesures :** human-in-the-loop systématique, override manuel sur tous les scores, pas d'effet juridique contraignant, transparence dans politique de confidentialité, possibilité d'opposition art. 21 (opt-out), métrique `axion_llm_human_override_rate` monitorée.

### 3.5 Risque RGPD #5 — Profilage biaisé ou erroné

| Vraisemblance | Gravité | Risque résiduel |
|---|---|---|
| Faible | Faible | **Acceptable** |

**Mesures :** LLM Router avec fallback chain (pas dépendance à un seul provider qui pourrait dériver), A/B testing prompts, prompt templates versionnés, evals régulières recommandées (audit P1 #19 — golden dataset hebdomadaire), métrique de désaccord LLM/humain monitorée.

### 3.6 Risque RGPD #6 — Transferts hors UE non encadrés

| Vraisemblance | Gravité | Risque résiduel |
|---|---|---|
| Faible (si DPA+SCC) | Élevée (si non encadré) | **Acceptable sous condition** |

**Sous-processeurs hors UE :**
- **PhantomBuster (US)** : DPA + SCC à signer avant go-live S12 (action humaine bloquante)
- **OpenAI (US)** : DPA + SCC fournis par OpenAI (à signer)
- **Anthropic (US)** : DPA + SCC fournis par Anthropic (à signer)
- **Backblaze (US)** : DPA + SCC fournis par Backblaze (à signer)

**Sous-processeurs UE (DPA standard suffisant) :**
- Hetzner (DE) ✅
- Cloudflare (en UE depuis politique 2023) ✅
- Mistral (FR) ✅
- OpenRouter (mixed — privilégier modèles UE)

### 3.7 Risque RGPD #7 — Prompt injection / exfiltration LLM

| Vraisemblance | Gravité | Risque résiduel |
|---|---|---|
| Moyenne (sans guard) → Faible (avec guard) | Modérée | **Acceptable avec mesures** |

**Mesures :** `PromptInjectionGuard` côté LLM Router (audit P0 #10), sanitization variables interpolées, monitoring `axion_llm_prompt_injection_blocked_total`, prompts système ne contiennent JAMAIS de données utilisateur en confiance.

### 3.8 Risque RGPD #8 — Réidentification après pseudonymisation

| Vraisemblance | Gravité | Risque résiduel |
|---|---|---|
| Faible | Faible | **Acceptable** |

**Mesures :** Les données B2B publiques (SIREN, raison sociale, dirigeants légaux) sont par nature non-anonymisables — c'est la finalité même. Toutefois la procédure RGPD erasure supprime intégralement les fiches sur demande (cf spec fichier 17 §2).

---

## 4. Analyse spécifique AI Act

**Classification du système IA :** `limited risk` selon AI Act (Règlement UE 2024/1689 art. 6).

**Justification :**
- Le profilage Axion CRM Pro **ne prend pas de décision juridiquement contraignante** (pas de crédit, pas d'embauche, pas d'accès à un service public)
- Il **n'évalue pas la solvabilité** au sens AI Act art. 6.2 annexe III
- Il **n'est pas utilisé en HR** (embauche, performance, licenciement)
- Il **n'évalue pas la fiabilité d'une personne physique**

**Obligations de transparence (AI Act art. 13) :**
- Mention dans la politique de confidentialité que des LLM sont utilisés pour le scoring (déjà prévue spec fichier 17 §3)
- Label visible dans l'UI "Score calculé par IA — override humain disponible" (à formaliser P1)
- Documentation des modèles utilisés dans table `ai_act_register`

**Si l'AI Act se durcit** (cf risque #10 fichier 22) ou si Axion CRM Pro est utilisé pour des cas HR/scoring de personnes : ce DPIA devra être révisé et reclassifié en `high risk` avec obligations renforcées (DPIA enrichie, journalisation détaillée, certification...).

---

## 5. Consultation des personnes concernées

L'art. 35.9 RGPD recommande de consulter les personnes concernées. Axion CRM Pro étant un outil interne B2B sans interaction directe avec les personnes profilées (qui sont contactées seulement après décision humaine), la consultation directe n'est pas opérationnellement applicable.

**Mesures de substitution :**
- Politique de confidentialité publique `/legal/privacy` explicite sur l'utilisation IA
- Procédure de droits CNIL accessible et traitée < 30j
- DPO joignable publiquement `contact@axion-ia.com`

---

## 6. Décision et validation

**Avis du DPO interim (Will) :** le traitement présente un risque résiduel **modéré et acceptable** au regard des mesures techniques et organisationnelles mises en place. La DPIA conclut que le traitement peut être mis en production V1 sous réserve de :

1. ✅ Maintenir toutes les mesures techniques listées §2.1
2. 🔄 Signer les DPA + SCC avec PhantomBuster, OpenAI, Anthropic, Backblaze AVANT go-live S12
3. 🔄 Activer `PromptInjectionGuard` (P0 #10 spec)
4. 🔄 Activer `UrlSsrfGuard` (P0 #3 spec)
5. 🔄 Implémenter override manuel + métriques `axion_llm_human_override_rate` + `axion_llm_prompt_injection_blocked_total`
6. 🔄 Implémenter evals hebdomadaires LLM (P1 spec — golden dataset 50 entreprises)
7. 🔄 Revue annuelle de cette DPIA (prochaine 2027-05-16)
8. 🔄 Revue en cas de modification substantielle

**Décision :** ✅ **Traitement validé** sous réserve des actions 2-7 ci-dessus.

---

## 7. Annexes

### 7.1 Plan d'action

| Action | Responsable | Échéance | Statut |
|---|---|---|---|
| Signer DPA + SCC PhantomBuster | Will | Avant S12 go-live | ⏳ À faire |
| Signer DPA + SCC OpenAI/Anthropic/Backblaze | Will | Avant S12 go-live | ⏳ À faire |
| Implémenter `PromptInjectionGuard` | Dev (Sprint 4) | S4 | ⏳ À coder |
| Implémenter `UrlSsrfGuard` | Dev (Sprint 1) | S1 | ⏳ À coder |
| Activer `axion_llm_human_override_rate` métrique | Dev (Sprint 11) | S11 | ⏳ À coder |
| Mise en place evals hebdomadaires LLM | Dev (Sprint 12) | S12 | ⏳ À coder |
| Première revue annuelle DPIA | DPO | 2027-05-16 | ⏳ Planifié |

### 7.2 Documents associés

- `docs/legal/lia.md` — Legitimate Interest Assessment
- `spec/17_rgpd_aiact_owasp.md` — détails RGPD + AI Act + OWASP
- `spec/22_risques_mitigations.md` — top 15 risques
- `data_processing_log` table — registre des traitements
- `ai_act_register` table — registre AI Act

---

## Signature et validation

**Responsable de traitement :** Axion-IA OÜ — Williams Jullin
**DPO interim :** `contact@axion-ia.com` — Williams Jullin
**Date :** 2026-05-16
**Prochaine revue obligatoire :** 2027-05-16

---

*Ce DPIA est conservé en archive interne et présentable sur demande à la CNIL en cas de contrôle. Il est obligatoirement révisé en cas de modification substantielle du traitement (ajout d'un use case LLM impactant les personnes, changement de sous-processeur, mise sur le marché Phase 2 avec cold email automatisé, etc.).*
