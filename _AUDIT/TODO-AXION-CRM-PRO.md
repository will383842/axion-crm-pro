# TODO — Axion CRM Pro

> Liste exhaustive de ce qu'il reste à implémenter / configurer pour atteindre la plateforme idéale.
> Dernière mise à jour : 2026-05-18 (post-H16).
> Voir aussi : `_AUDIT/SESSION-2026-05-18-SPRINT-HARDENING-COMPLETE.md` pour l'état actuel.

Légende sévérité :
- 🔴 **Critique** : bloque la valeur business principale
- 🟠 **Important** : impact utilisateur ou conformité
- 🟡 **Optionnel** : amélioration QoL / robustesse
- 🟢 **Nice-to-have** : confort ou cosmétique

---

## 🔴 CRITIQUE — actions immédiates

### 1. Séparer le compte de facturation Google (10 min, Will solo)
**Pourquoi** : actuellement le projet `axion-crm-pro` est rattaché au compte de facturation **"SOS-Expat.com global"**. Mélange business :
- Comptabilité brouillée (impossible de séparer les coûts par projet propre)
- Risque si désactivation/dispute du compte SOS-Expat → Axion CRM Pro tombe aussi
- Mauvaise hygiène fiscale (Axion-IA OÜ Estonia ≠ SOS-Expat France)

**Comment** :
1. https://console.cloud.google.com/billing → bouton **"Créer un compte de facturation"**
2. Nom : "Axion CRM Pro Billing" (ou "Axion-IA OÜ" selon ta préf)
3. Ajouter une **carte de crédit pro Axion** (séparée de SOS-Expat)
4. Aller sur le projet `axion-crm-pro` → Settings → **"Lier ce projet à un autre compte de facturation"**
5. Sélectionner le nouveau compte → confirmer
6. **Vérifier** que le crédit gratuit Maps Platform $200/mois s'applique bien au nouveau compte (parfois il faut re-activer billing pour qu'il soit reconnu)

### 2. Configurer les budgets alerts Google + Mistral (10 min, Will solo)
**Pourquoi** : filet de sécurité financière. Si jamais une facture inattendue arrive, tu reçois un mail AVANT que ça dégénère.

**Google Cloud** :
- https://console.cloud.google.com/billing/[nouveau-compte]/budgets
- Créer budget 5 € → seuils 50/90/100% → email williamsjullin@gmail.com
- Périmètre : projet `axion-crm-pro` uniquement

**Mistral** :
- https://console.mistral.ai/ → Workspace → Billing → Usage limits
- Seuil mensuel 10 € → notifications email à 50/80/100%
- Optionnel : précharger 10 € en prepaid credits (sécurité absolue)

---

## 🟠 IMPORTANT — semaine prochaine

### 3. Lancer une campagne pilote (1-2h, validation bout-en-bout)
**Pourquoi** : tester le pipeline sur des vraies données. Identifier les blockers / ajustements ciblage maintenant.

**Recette** :
1. 1 département connu (ex: Isère 38 ou Paris 75)
2. NAF précis (ex: 62.01Z conseil IT)
3. `max_companies` = 50-100 dans le wizard
4. Observer pendant 1h dans `/admin/observability`, `/scraper-runs`, `/companies`
5. Quoi vérifier :
   - Aucune erreur waterfall dans Sentry
   - Compteurs Google Places + Mistral incrémentent
   - Ratio `ready_for_outreach` proche de 50-60%
   - LLM Mistral retourne des priorités cohérentes
   - signals.google_places remplis sur les fiches sans email préalable

### 4. Sentry alerts (5 min, Will solo)
**Pourquoi** : notification automatique si waterfall casse en prod.

**Comment** :
1. https://sentry.io → projet Axion CRM Pro (ou créer si absent)
2. Menu **Alerts** → **Create Alert** → "Issues — Number of errors"
3. Conditions : `errors > 10` dans `1 hour` → email Will
4. Tags : `service:*` (pour filtrer waterfall vs autre)

---

## 🟡 OPTIONNEL — quand tu auras le temps

### 5. CI workflows GitHub Actions cassés (1 jour, chore)
**Pourquoi** : workflows actuels échouent car ils cherchent `workers/pnpm-lock.yaml` et `frontend/pnpm-lock.yaml` qui n'existent pas (projet utilise `package-lock.json` npm).

**Fix possible** :
- Option A : `pnpm install` dans frontend + workers + commit les `pnpm-lock.yaml` générés
- Option B : modifier les workflows pour utiliser `npm ci` au lieu de `pnpm`
- Pré-existant aux sprints Hardening — pas un régression.

### 6. Restore PG drill (30 min, Will solo)
**Pourquoi** : backup daily quotidien tourne, mais le restore n'a jamais été testé en vrai. Risque : le jour où tu en as besoin, ça marche pas.

**Drill** :
1. Spinner un container PG vierge temporaire
2. Récupérer le dernier dump de Storage Box
3. Restorer dans le container temporaire
4. Vérifier que les tables critiques (workspaces, companies, contacts, audit_logs) sont OK
5. Documenter le runbook restore

### 7. KpiCard "Coûts LLM ce mois" dans /admin/observability (1h dev)
**Pourquoi** : tu vois le quota Google Places mais pas le cumul Mistral. Sans ça, pas de visibilité sur le coût mensuel cumulé.

**À ajouter** :
- Backend : `ObservabilityController::summary` ajouter `llm_cost_month` (somme cost_eur sur llm_usage workspace + mois courant)
- Frontend : nouvelle KpiCard "Coûts LLM (mois)" avec montant € + nombre de classifications
- Trigger alerte si > `cost_cap_eur` du workspace (déjà en DB)

### 8. Pages Jaunes activation Phase B (3-5 jours + $30/mois Webshare)
**Pourquoi** : marginal — INSEE couvre >99% B2B FR. Utile uniquement si tu cibles TPE/artisans visibles surtout sur PJ.

**Setup** :
1. Compte Webshare → plan Residential Premium $30/mois
2. Poser creds dans `.env` (`WEBSHARE_ENABLED=true`)
3. `MOCK_SCRAPERS=false` côté .env
4. Re-ajouter Pages Jaunes dans `DISCOVERY_SOURCES` du wizard frontend
5. Tester anti-detection (User-Agent rotation + Webshare proxy + cookies persistence)

### 9. Tests Pest en CI réel (1-2 jours dev)
**Pourquoi** : 28 nouveaux tests Pest livrés dans Hardening + H7 + H9-H16 mais jamais exécutés en CI (Docker local down + pas PHP 8.3 local).

**Setup** :
- Container PHP 8.3 + Postgres dédié pour les tests Pest CI
- Ajouter `vendor/bin/pest --parallel` dans `.github/workflows/ci.yml`
- Cible : 220+ tests verts post-merge

---

## 🟢 NICE-TO-HAVE — long terme

### 10. Brave Search activation (1h, 0-50 €/mois)
**Pourquoi** : actuellement DomainFinder stratégie 2 (Brave) est skip car key absente. ~30-40% sites web supplémentaires retrouvés si activé.

**Setup** :
- https://api.search.brave.com/app/keys → free 2K req/mois ou Pro $50/mois 40K
- `BRAVE_SEARCH_API_KEY=…` dans `.env`
- Restart api horizon

### 11. Google OAuth login (1 jour dev)
**Pourquoi** : actuellement login email/password + magic link + 2FA TOTP. Will pourrait préférer login avec compte Google directement.

### 12. Anthropic Claude fallback (5 min Will + ~25€/mois)
**Pourquoi** : si jamais Mistral down, fallback Anthropic prend le relais (déjà configuré dans fallback_chain). Tant que Mistral marche, dormant.
- Poser `ANTHROPIC_API_KEY=…` dans `.env`
- Active automatiquement le fallback

### 13. Dashboard Coverage map MapLibre interactive (3-5 jours dev)
**Pourquoi** : `/coverage` affiche déjà une liste mais pas une carte. Visualisation cartographique des zones scrapées + densité d'entreprises.

### 14. Export CSV avec colonnes personnalisables (1 jour dev)
**Pourquoi** : `/companies` a un bouton export CSV mais format fixe. Permettre à Will de choisir les colonnes (denomination, email, phone, NAF, taille, tags, etc.).

### 15. Refonte Wizard étape 4 (Budget & sécurité) pour clarté (1 jour dev)
**Pourquoi** : actuellement Will peut configurer `max_companies`, `max_duration`, `max_rpm` mais ça peut être confus. Refonte UX : presets ("Pilot 100" / "Standard 1K" / "Scale 10K") qui auto-remplissent ces 3 champs.

### 16. Page `/admin/llm-cost-history` (1-2 jours dev)
**Pourquoi** : tracking historique des coûts LLM par mois (graphique sur 12 mois) pour planning budget.

### 17. Templates emails B2B FR pré-définis (2-3 jours dev)
**Pourquoi** : helper Will avec 5-10 templates emails ready-to-use pour les cas d'usage typiques Axion-IA (intro, follow-up, relance, démo, etc.).

---

## 📋 Récap priorisation

| Priorité | Combien d'items | Effort cumulé | Coût récurrent | Quand |
|---|---|---|---|---|
| 🔴 Critique | 2 | ~20 min | 0 € | Cette semaine |
| 🟠 Important | 2 | ~2h | 0 € | Semaine prochaine |
| 🟡 Optionnel | 5 | ~1-2 semaines | 0-30 €/mois | Quand temps dispo |
| 🟢 Nice-to-have | 8 | ~3-4 semaines | 0-25 €/mois | Long terme |

## 🎯 Chemin recommandé Will

**Cette semaine** :
- [ ] #1 Séparer compte facturation Google (10 min)
- [ ] #2 Budgets alerts Google + Mistral (10 min)
- [ ] #3 Campagne pilote 50-100 entreprises (1-2h)

**Semaine prochaine** :
- [ ] #4 Sentry alerts (5 min)
- [ ] Première vraie campagne 1K-5K entreprises selon résultat pilote
- [ ] #7 KpiCard Coûts LLM dashboard (1h dev)

**Mois 2-3** (selon priorités business) :
- [ ] #5 CI workflows fix
- [ ] #6 Restore PG drill
- [ ] #8 Pages Jaunes activation (optionnel)
- [ ] #9 Tests Pest en CI
- [ ] #17 Templates emails B2B FR

---

## 🔗 Liens utiles

- App prod : https://app.axion-crm-pro.com
- Repo GitHub : https://github.com/will383842/axion-crm-pro
- Console GCP : https://console.cloud.google.com/?project=axion-crm-pro
- Console Mistral : https://console.mistral.ai/
- Webshare : https://www.webshare.io/
- Brevo (ESP recommandé H17) : https://www.brevo.com/
- Documentation interne :
  - `_AUDIT/SESSION-2026-05-18-SPRINT-HARDENING-COMPLETE.md` (état actuel)
  - `_AUDIT/SPRINT-H9-GOOGLE-PLACES-PAGES-JAUNES-ACTIVATION.md` (runbook activation)
  - `_AUDIT/COST-ESTIMATION-1M-COMPANIES.md` (projections coûts à grande échelle)
  - `load-tests/LOAD-TEST-RUNBOOK.md` (perfs/SLA)
