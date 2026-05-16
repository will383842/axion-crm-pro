# 17 — RGPD + AI ACT + OWASP

## Vue d'ensemble

Axion CRM Pro manipule des données personnelles (emails pros, dirigeants, contacts C-level) ET utilise des LLMs pour profilage/scoring → triple obligation :

1. **RGPD (Règlement (UE) 2016/679)** : base légale, registre des traitements, droits d'accès/effacement/portabilité, sous-processeurs documentés, DPO joignable.
2. **AI Act (Règlement (UE) 2024/1689)** : registre des modèles utilisés, classification risque, transparence, human-in-the-loop pour décisions à fort impact.
3. **OWASP Top 10** : sécurité applicative (auth, injection, accès, crypto, etc.).

DPO contact : **`contact@axion-ia.com`** (Will jusqu'à embauche dédiée).

---

## 1. Registre RGPD (template rempli)

Stocké dans table `data_processing_log`. Voici les **7 traitements** initiaux Axion CRM Pro V1.

### Traitement T1 — Prospection B2B (cœur métier)

| Champ | Valeur |
|---|---|
| `processing_key` | `prospection_b2b` |
| `legal_basis` | Intérêt légitime art. 6.1.f RGPD (prospection B2B nominative) |
| `purpose` | Identifier et qualifier des entreprises françaises (TPE/PME/ETI/GE + écoles) susceptibles d'acheter des prestations de conseil IA |
| `data_categories` | `['identity_pro', 'contact_pro', 'public_business']` |
| `retention_days` | 730 (2 ans après dernier contact / mise à jour) |
| `subprocessors` | `['anthropic','openai','mistral','phantombuster','webshare','iproyal','smartproxy','hetzner','cloudflare','backblaze']` |
| `documentation_url` | https://crm.axion-ia.com/docs/rgpd/prospection-b2b |

### Traitement T2 — Enrichissement légal & financier

| Champ | Valeur |
|---|---|
| `processing_key` | `enrichment_legal` |
| `legal_basis` | Intérêt légitime + données publiques (BODACC, annuaire-entreprises = open data Etalab) |
| `purpose` | Compléter les fiches entreprises avec dirigeants légaux, CA, bilans |
| `data_categories` | `['identity_pro','public_business','financial_public']` |
| `retention_days` | 730 |
| `subprocessors` | DILA (BODACC), data.gouv.fr (annuaire-entreprises), Infogreffe |

### Traitement T3 — Détection signaux business

| `processing_key` | `signal_detection` |
| `legal_basis` | Intérêt légitime + open data |
| `purpose` | Identifier signaux d'achat (recrutements C-level, levées fonds, changements dirigeants) |
| `data_categories` | `['identity_pro','recruitment_public','business_news']` |
| `retention_days` | 365 (signal expire après 1 an) |
| `subprocessors` | France Travail, BODACC, Crunchbase |

### Traitement T4 — Email validation

| `processing_key` | `email_validation` |
| `legal_basis` | Intérêt légitime (vérification livrabilité email pro) |
| `purpose` | Vérifier qu'un email professionnel est livrable sans envoyer de message réel |
| `data_categories` | `['contact_pro']` |
| `retention_days` | 30 (TTL revalidation) |
| `subprocessors` | aucun (validation in-house) |

### Traitement T5 — LinkedIn C-level enrichissement

| `processing_key` | `linkedin_clevel` |
| `legal_basis` | Intérêt légitime + informations rendues publiques par l'intéressé sur LinkedIn |
| `purpose` | Identifier les C-level non-dirigeants (DRH, DAF, DSI, Marketing, Commercial) |
| `data_categories` | `['identity_pro','contact_pro','professional_role']` |
| `retention_days` | 730 |
| `subprocessors` | PhantomBuster (US — Privacy Shield/SCC) |

> ⚠️ PhantomBuster est basé aux US → DPA + Standard Contractual Clauses requis. Documenté dans contrat. Si Will signe DPA, OK.

### Traitement T6 — Audit log + monitoring

| `processing_key` | `audit_security` |
| `legal_basis` | Obligation légale (sécurité système, registres RGPD art.30) + intérêt légitime |
| `purpose` | Sécurité, traçabilité, débogage |
| `data_categories` | `['identity_pro','technical_logs']` |
| `retention_days` | 365 (logs IP + UA) puis purgés |
| `subprocessors` | Hetzner (UE) |

### Traitement T7 — Profilage IA (AI Act)

| `processing_key` | `ai_scoring_profiling` |
| `legal_basis` | Intérêt légitime + transparence AI Act |
| `purpose` | Scoring automatique maturité IA + recommandation offre Axion-IA |
| `data_categories` | `['identity_pro','public_business','derived_scores']` |
| `retention_days` | 730 |
| `subprocessors` | Anthropic, OpenAI, Mistral, OpenRouter (UE/US) |
| `human_review_required` | TRUE (override manuel toujours possible) |

---

## 2. Procédure droits CNIL

### Endpoint `POST /api/gdpr/requests/{id}/process`

```php
final class GdprProcessingService
{
    /** Action atomique : ERASE — supprime/anonymise toutes les entités liées au sujet. */
    public function processErasure(GdprRequest $req): array
    {
        return DB::transaction(function () use ($req) {
            $affected = ['companies' => [], 'contacts' => [], 'emails' => []];

            // 1. Trouver entités liées au sujet
            $contacts = Contact::query()
                ->when($req->subject_email, fn ($q) => $q->orWhereHas('emails', fn ($q) => $q->where('email', $req->subject_email)))
                ->when($req->subject_name,  fn ($q) => $q->orWhere(DB::raw('LOWER(unaccent(full_name))'),
                                                                     'LIKE', '%'.strtolower($req->subject_name).'%'))
                ->when($req->subject_phone, fn ($q) => $q->orWhereHas('phones', fn ($q) => $q->where('phone_e164', $req->subject_phone)))
                ->get();

            foreach ($contacts as $c) {
                // Anonymisation (préserve les FK/audit sans données personnelles)
                $c->update([
                    'first_name' => null, 'last_name' => null, 'full_name' => '[ERASED]',
                    'position_title' => null, 'linkedin_url' => null,
                    'raw_data' => null, 'notes' => null,
                ]);
                CompanyEmail::query()->where('contact_id', $c->id)->delete();
                CompanyPhone::query()->where('contact_id', $c->id)->delete();
                $c->delete();        // soft delete
                $affected['contacts'][] = $c->id;
            }

            // 2. Si email est domaine pro complet → opt-out automatique sur le domaine
            if ($req->subject_email && Str::endsWith($req->subject_email, ['@axion-ia.com','@gmail.com']) === false) {
                OptOut::firstOrCreate(
                    ['email' => $req->subject_email],
                    ['reason' => 'gdpr_erasure_request_id_'.$req->id, 'source' => 'gdpr_request']
                );
            }
            if ($req->subject_phone) {
                OptOut::firstOrCreate(['phone_e164' => $req->subject_phone], ['reason' => 'gdpr_phone_erasure']);
            }

            // 3. Update GDPR request
            $req->update([
                'status' => 'completed',
                'processed_by' => auth()->id(),
                'processed_at' => now(),
                'affected_entities' => $affected,
            ]);

            // 4. Audit log
            AuditLog::logSafe([
                'workspace_id' => $req->workspace_id,
                'actor_user_id' => auth()->id(),
                'action' => 'gdpr.request.processed',
                'entity_type' => 'gdpr_request',
                'entity_id' => $req->id,
                'payload' => ['request_type' => 'erasure', 'affected_count' => count($affected['contacts'])],
            ]);

            return $affected;
        });
    }

    /** Action atomique : EXPORT — produit un dump JSON des données du sujet (portabilité). */
    public function processExport(GdprRequest $req): array
    {
        $contacts = Contact::query()->/* mêmes critères que erasure */->get();
        $payload = [
            'request_id' => $req->id,
            'subject' => [
                'email' => $req->subject_email,
                'name' => $req->subject_name,
                'phone' => $req->subject_phone,
            ],
            'data_extracted_at' => now()->toIso8601String(),
            'contacts' => $contacts->map->toArray(),
            'companies' => $contacts->pluck('company')->unique('id')->values()->toArray(),
            'emails' => CompanyEmail::query()->whereIn('contact_id', $contacts->pluck('id'))->get()->toArray(),
            'business_signals' => CompanyBusinessSignal::query()->whereIn('company_id', $contacts->pluck('company_id'))->get()->toArray(),
        ];
        $req->update(['status' => 'completed', 'processed_at' => now()]);
        return $payload;
    }
}
```

### Délais et procédures

- **Réception requête** : `gdpr_requests.received_at`, `deadline_at = received_at + 30 jours`.
- **Acknowledgment réponse** : email automatique envoyé au sujet sous 72h ("Nous avons bien reçu votre demande, traitement en cours").
- **Traitement** : Will (DPO) trie + déclenche processErasure ou processExport depuis admin.
- **Export portabilité** : dump JSON envoyé par email chiffré (zip + password aléatoire envoyé par SMS).
- **Refus motivé** : si requête frauduleuse (ex: pas le bon sujet), `status = 'rejected'` avec `notes` détaillé.
- **Stats CNIL** : dashboard `/gdpr` affiche taux traitement < 30j en % (cible 100%).

---

## 3. Page mention légale + politique de confidentialité (template)

Stockée comme contenu Markdown dans le repo (`docs/legal/`), servie par Laravel sur `/legal/mentions` et `/legal/privacy` (routes publiques, sans auth).

### `docs/legal/mentions.md`

```markdown
# Mentions légales — Axion CRM Pro

**Éditeur :** Axion-IA OÜ
Adresse siège : Sepapaja tn 6, 15551 Tallinn, Estonie
N° d'enregistrement : 16384275
DPO : `contact@axion-ia.com`

**Hébergement :** Hetzner Online GmbH (Frankfurt, Allemagne)
Conformément au RGPD UE (Art. 3 territorial).

**Directeur de la publication :** Williams Jullin

**Contact :** contact@axion-ia.com
```

### `docs/legal/privacy.md`

Structure obligatoire RGPD Art. 13/14 :
1. Identité du responsable de traitement
2. Coordonnées du DPO
3. Finalités et bases légales (7 traitements ci-dessus)
4. Catégories de données traitées
5. Catégories de destinataires (sous-processeurs)
6. Transferts hors UE (PhantomBuster US → SCC)
7. Durées de conservation
8. Droits des personnes (accès / rectification / effacement / opposition / portabilité / limitation)
9. Procédure d'exercice des droits → `contact@axion-ia.com` sous 30 jours
10. Droit de plainte CNIL

---

## 4. AI Act — Registre `ai_act_register`

Classification AI Act du système Axion CRM Pro :

- **Risk class** : `limited` (profilage automatique de personnes physiques sans prise de décision juridiquement contraignante).
- **Transparence obligatoire** : utilisateurs informés via page Privacy + label visible sur scores dans l'UI ("Score calculé par IA — validation humaine possible").
- **Human-in-the-loop** : override manuel toujours disponible (cf fichier 08).
- **Documentation des modèles** : table `ai_act_register` remplie avec 1 entrée par use case LLM Phase 1 (10 entrées).

Exemple entrée :

```json
{
  "use_case_key": "axion_offer_match",
  "risk_class": "limited",
  "provider": "anthropic",
  "model": "claude-haiku-4-5",
  "purpose": "Recommander automatiquement quelle offre Axion-IA proposer à une entreprise donnée",
  "decision_impact": "Aide à la décision commerciale interne. Aucun impact juridique direct. Override manuel disponible.",
  "human_review_required": true,
  "transparency_doc_url": "https://crm.axion-ia.com/docs/ai-act/axion-offer-match"
}
```

---

## 5. OWASP Top 10 — application concrète

### A01 — Broken Access Control

- ✅ Multi-tenant RLS PostgreSQL (cf fichier 15)
- ✅ Middleware Laravel `InjectWorkspace` injecte `app.workspace_id` SET LOCAL
- ✅ Spatie Permission policies sur tous les controllers
- ✅ Tests E2E : fuzzing cross-workspace → 0 leak
- ✅ Pas de bypass admin RLS sauf super_admin Will (rôle PG dédié)

### A02 — Cryptographic Failures

- ✅ TLS Let's Encrypt auto via Caddy (forced HTTPS, redirect HTTP → HTTPS 301)
- ✅ HSTS 12 mois preload
- ✅ Cookies Sanctum HttpOnly Secure SameSite=Lax
- ✅ Secrets dans Infisical vault (jamais en `.env` prod)
- ✅ Passwords bcrypt cost 12
- ✅ TOTP secret chiffré (Laravel `Crypt::encryptString` via APP_KEY)
- ✅ Backup codes 2FA hashés bcrypt
- ✅ PII (emails) hashés SHA-256 dans logs

### A03 — Injection

- ✅ Eloquent ORM partout (no raw queries sauf SQL paramétrés explicites)
- ✅ Validation typed via Spatie Data DTOs
- ✅ Pas de templating user-controllable
- ✅ Échappement systématique XSS dans React (par défaut)

### A04 — Insecure Design

- ✅ Threat modeling fait (cf fichier 22 risques + fichier 01 thinking)
- ✅ Defense in depth : auth + RLS + RBAC + audit
- ✅ Rate limiting fin grained (cf fichier 14)

### A05 — Security Misconfiguration

- ✅ Headers sécurité injectés sur toutes réponses (cf fichier 15 §9)
- ✅ Environnement prod : `APP_DEBUG=false`, `APP_ENV=production`, error pages génériques
- ✅ Désactivation server signatures (Caddy header `Server` retiré)
- ✅ Dépendances minimales (audit `composer audit` + `npm audit` CI)
- ✅ Permissions filesystem strict (storage/, bootstrap/cache/ writable seulement)

### A06 — Vulnerable and Outdated Components

- ✅ CI scanne dépendances (`composer audit`, `npm audit`, `pnpm audit`)
- ✅ Renovate ou Dependabot bots actifs pour bumps mineurs auto
- ✅ Patches sécurité Laravel/PHP appliqués sous 7j
- ✅ Playwright Chromium maintenu à jour (mensuel)

### A07 — Identification and Authentication Failures

- ✅ Sanctum cookie SPA + TOTP obligatoire
- ✅ Rate limiting login (5 essais / 15 min / IP+email)
- ✅ Magic link single-use 15 min
- ✅ Session expiration 7j
- ✅ Logout invalide session
- ✅ Pas de "remember me" persistant

### A08 — Software and Data Integrity Failures

- ✅ Audit logs append-only hash chain (cf fichier 15)
- ✅ Migrations DB versionnées Git
- ✅ Dockerfile multi-stage avec SHA256 pinning des images de base
- ✅ Container scanning CI (Trivy ou Grype)
- ✅ Pas d'exécution code non vérifié (eval/exec interdit)

### A09 — Security Logging and Monitoring Failures

- ✅ Audit logs centralisés
- ✅ Alertmanager + 3 canaux (Slack/Telegram/email)
- ✅ Anomaly detection nightly
- ✅ GlitchTip pour erreurs runtime
- ✅ Logs retention 30j minimum

### A10 — Server-Side Request Forgery (SSRF)

- ✅ Workers Playwright restreints à liste de domaines en allowlist
- ✅ Pas de fetch URL user-controllable côté backend
- ✅ Egress firewall Hetzner : workers Node ne peuvent atteindre que ports 80/443/8080 vers Internet (pas SSH/RDP/SMB autres serveurs)

---

## 6. Checklist conformité avant prod (S12)

- [ ] 7 traitements documentés dans `data_processing_log`
- [ ] 10 entrées `ai_act_register` complétées
- [ ] Page `/legal/mentions` et `/legal/privacy` publiées et accessibles publiquement
- [ ] DPA signé avec PhantomBuster (US sous-processeur)
- [ ] DPA papier signé avec Hetzner
- [ ] DPA online signé avec Cloudflare + Backblaze
- [ ] Procédure droits CNIL testée e2e (1 erasure + 1 export)
- [ ] Audit hash chain vérification OK sur 10k+ logs
- [ ] OWASP top 10 : 10/10 contrôles appliqués (CSP, HSTS, etc.)
- [ ] Penetration test léger (burp + zap) sans HIGH ou CRITICAL findings
- [ ] Backups chiffrés AES-256 testés (restore staging OK)
- [ ] Plan de réponse incident (cf fichier 18) documenté

---

## 7. Anti-patterns interdits

- ❌ Scraper d'emails personnels (`gmail.com`, `hotmail.fr`, etc.) sauf publication explicite contexte pro
- ❌ Envoyer un email réel pour valider (incident RGPD)
- ❌ Stocker secrets en clair en DB
- ❌ Désactiver les audit logs "temporairement"
- ❌ Ignorer un opt-out (re-scraping interdit)
- ❌ Profilage avec décision juridique automatique (sortie du `limited` AI Act vers `high` → out of scope V1)
- ❌ Pas de DPO joignable (`contact@axion-ia.com` minimum)
- ❌ Logger un mot de passe ou un token (filtrage `LogMaskingMiddleware`)

---

## Prochaine étape

→ Lire `18_deploiement_hetzner.md` pour le déploiement Hetzner Compte 2 + docker-compose + DR runbooks.
