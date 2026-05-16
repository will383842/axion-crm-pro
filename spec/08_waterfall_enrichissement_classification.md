# 08 — WATERFALL ENRICHISSEMENT + CLASSIFICATION

## Vue d'ensemble

Le **waterfall d'enrichissement** est le pipeline principal d'Axion CRM Pro : pour chaque entreprise nouvellement découverte (via INSEE ou import manuel), il enchaîne 9 étapes qui transforment progressivement une simple ligne SIREN en une fiche entreprise complète + scorée + tagguée + géocodée + qualifiée business.

Latence cible bout-en-bout : **< 30 secondes** pour une entreprise standard. Pipeline résilient : chaque étape peut échouer indépendamment sans casser les autres ; on accepte une fiche partiellement enrichie (mieux que rien). Parallélisation maximale : 5 étapes peuvent tourner en parallèle dès que l'étape 1 est terminée.

---

## Diagramme du waterfall 9 étapes

```
                  ┌─────────────────────────────────────────────────────────────────┐
                  │  ENTRÉE : SIREN ou raison sociale + ville (lookup INSEE)        │
                  └────────────────────────────┬────────────────────────────────────┘
                                               │
                                               ▼
                  ┌─────────────────────────────────────────────────────────────────┐
                  │  ÉTAPE 1 — IDENTIFICATION                                       │
                  │  Source : INSEE Sirene API                                      │
                  │  Output : companies.siren / legal_name / naf_code / effectif    │
                  │  Output : companies.address_line / city_id                      │
                  │  ► Bloquante : si échec, on abandonne l'entreprise              │
                  └────────────────────────────┬────────────────────────────────────┘
                                               │
                  ┌────────────────────────────┼────────────────────────────────────┐
                  │ ╔══════════════════════════╧══════════════════════════════════╗ │
                  │ ║  PARALLELISATION DES ÉTAPES 2 + 3 + 7 (~3-8 secondes)        ║ │
                  │ ╚══════════════════════════╤══════════════════════════════════╝ │
                  │              ┌─────────────┼─────────────┐                       │
                  │              ▼             ▼             ▼                       │
                  │  ┌──────────────────┐ ┌──────────┐ ┌────────────────┐          │
                  │  │  ÉTAPE 2         │ │ ÉTAPE 3  │ │ ÉTAPE 7        │          │
                  │  │  ENRICHIR LÉGAL  │ │ CONTACT  │ │ GÉOCODAGE BAN  │          │
                  │  │  + FINANCIER     │ │ ENTREPR. │ │                │          │
                  │  │                  │ │          │ │                │          │
                  │  │  annu-ent +      │ │ Gmaps +  │ │ api-adresse    │          │
                  │  │  Infogreffe +    │ │ PJ       │ │ + LLM disamb.  │          │
                  │  │  Societe.com     │ │          │ │                │          │
                  │  │                  │ │          │ │                │          │
                  │  │  → contacts      │ │ → phone  │ │ → geom_point   │          │
                  │  │    (dirigeants)  │ │   site   │ │                │          │
                  │  │  → revenue_eur   │ │   avis   │ │                │          │
                  │  └──────────────────┘ └──────────┘ └────────────────┘          │
                  └────────────────────────────┬────────────────────────────────────┘
                                               │ (attente convergence 3 branches)
                                               ▼
                  ┌─────────────────────────────────────────────────────────────────┐
                  │  ÉTAPE 4 — SCRAPING SITE WEB                                    │
                  │  Source : workers Node.js + Playwright + cheerio                │
                  │  Input  : companies.website (de l'étape 3 si absent étape 1)    │
                  │  Output : company_emails (TOUS classifiés)                      │
                  │  Output : contacts (équipe extraite via LLM)                    │
                  │  Output : company_social_handles                                │
                  │  Output : company_strategic_keywords                            │
                  │  Output : email_patterns détecté                                │
                  │  ► Non bloquant : si pas de website, skip                       │
                  └────────────────────────────┬────────────────────────────────────┘
                                               │
                  ┌────────────────────────────┼────────────────────────────────────┐
                  │ ╔══════════════════════════╧══════════════════════════════════╗ │
                  │ ║  PARALLELISATION DES ÉTAPES 5 + 8 (~5-15 secondes)           ║ │
                  │ ╚══════════════════════════╤══════════════════════════════════╝ │
                  │              ┌─────────────┴─────────────┐                       │
                  │              ▼                           ▼                       │
                  │  ┌──────────────────┐         ┌────────────────────────┐        │
                  │  │  ÉTAPE 5         │         │  ÉTAPE 8               │        │
                  │  │  C-LEVEL         │         │  SIGNAUX BUSINESS      │        │
                  │  │  LinkedIn PB     │         │  BODACC + FT + CB +    │        │
                  │  │                  │         │  Crunchbase + news     │        │
                  │  │  → contacts      │         │  → company_business_   │        │
                  │  │    (DRH, DAF,    │         │    signals             │        │
                  │  │     DSI, etc.)   │         │                        │        │
                  │  └──────────────────┘         └────────────────────────┘        │
                  └────────────────────────────┬────────────────────────────────────┘
                                               │ (attente convergence 2 branches)
                                               ▼
                  ┌─────────────────────────────────────────────────────────────────┐
                  │  ÉTAPE 6 — EMAIL FINDER + VALIDATION SMTP                       │
                  │  Pour chaque contact créé (étapes 2 + 4 + 5),                   │
                  │  exécute cascade SMTP cf fichier 06.                            │
                  │  → company_emails enrichis avec validation_score                │
                  └────────────────────────────┬────────────────────────────────────┘
                                               │
                                               ▼
                  ┌─────────────────────────────────────────────────────────────────┐
                  │  ÉTAPE 9 — CLASSIFICATION & SCORING LLM                         │
                  │  Use cases :                                                    │
                  │  • ia_maturity_scoring     → companies.ia_maturity              │
                  │  • axion_offer_match       → companies.axion_offer + score      │
                  │  • auto_tag_generation     → company_tags                       │
                  │  • extract_strategic_keys  → company_strategic_keywords (boost) │
                  │  • Calcul priority_score   → companies.priority_score           │
                  │  • Calcul contact_priority → companies.contact_priority         │
                  └────────────────────────────┬────────────────────────────────────┘
                                               │
                                               ▼
                  ┌─────────────────────────────────────────────────────────────────┐
                  │  FINALISATION                                                   │
                  │  UPDATE companies SET last_enriched_at = NOW()                  │
                  │  UPDATE enrichment_runs SET state = 'completed'                 │
                  │  Audit log INSERT                                               │
                  │  Refresh materialized view coverage_matrix_cells si seuil       │
                  │  Telegram notification si signal critique                       │
                  └─────────────────────────────────────────────────────────────────┘
```

---

## State Machine (Spatie Laravel Model States)

### Définition des états

```php
namespace App\Modules\Scraping\States;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class EnrichmentState extends State
{
    abstract public function key(): string;
    abstract public function isTerminal(): bool;

    public static function config(): StateConfig
    {
        return parent::config()
            ->default(Pending::class)
            ->allowTransition(Pending::class, Identifying::class)
            ->allowTransition(Identifying::class, [Enriching::class, FailedIdentification::class])
            ->allowTransition(Enriching::class, [Crawling::class, FailedEnrichment::class])
            ->allowTransition(Crawling::class, [Investigating::class, Crawling::class])
            ->allowTransition(Investigating::class, [Validating::class, Classifying::class])
            ->allowTransition(Validating::class, Classifying::class)
            ->allowTransition(Classifying::class, [Completed::class, FailedClassification::class])
            ->allowTransition(Completed::class, Enriching::class)  // re-enrichissement périodique
            ->allowTransition([FailedIdentification::class, FailedEnrichment::class, FailedClassification::class], Pending::class);
    }
}

final class Pending extends EnrichmentState { public function key(): string { return 'pending'; } public function isTerminal(): bool { return false; } }
final class Identifying extends EnrichmentState { /* étape 1 */ public function key(): string { return 'identifying'; } public function isTerminal(): bool { return false; } }
final class Enriching extends EnrichmentState { /* étapes 2-3-7 parallèles */ public function key(): string { return 'enriching'; } public function isTerminal(): bool { return false; } }
final class Crawling extends EnrichmentState { /* étape 4 site web */ public function key(): string { return 'crawling'; } public function isTerminal(): bool { return false; } }
final class Investigating extends EnrichmentState { /* étapes 5-8 parallèles */ public function key(): string { return 'investigating'; } public function isTerminal(): bool { return false; } }
final class Validating extends EnrichmentState { /* étape 6 SMTP cascade */ public function key(): string { return 'validating'; } public function isTerminal(): bool { return false; } }
final class Classifying extends EnrichmentState { /* étape 9 LLM */ public function key(): string { return 'classifying'; } public function isTerminal(): bool { return false; } }
final class Completed extends EnrichmentState { public function key(): string { return 'completed'; } public function isTerminal(): bool { return true; } }
final class FailedIdentification extends EnrichmentState { public function key(): string { return 'failed_identification'; } public function isTerminal(): bool { return true; } }
final class FailedEnrichment extends EnrichmentState { public function key(): string { return 'failed_enrichment'; } public function isTerminal(): bool { return true; } }
final class FailedClassification extends EnrichmentState { public function key(): string { return 'failed_classification'; } public function isTerminal(): bool { return true; } }
```

### Modèle Eloquent `EnrichmentRun`

```php
namespace App\Modules\Scraping\Models;

use App\Modules\Scraping\States\EnrichmentState;
use App\Modules\Scraping\States\Pending;
use Illuminate\Database\Eloquent\Model;
use Spatie\ModelStates\HasStates;

class EnrichmentRun extends Model
{
    use HasStates;

    protected $casts = [
        'state' => EnrichmentState::class,
        'steps_completed' => 'array',
    ];

    public function company() { return $this->belongsTo(Company::class); }
}
```

---

## Logique de décision par étape

### Étape 1 — Identification (BLOQUANTE)

- Requête INSEE Sirene avec SIREN ou raison sociale + ville.
- Si échec (404, timeout 3 fois) → état `FailedIdentification`. Pas de retry automatique. L'entreprise n'existe peut-être pas.
- Si succès → UPSERT `companies` avec données INSEE → transition `Enriching`.

### Étape 2 — Enrichissement légal & financier (NON BLOQUANTE)

- Appel annuaire-entreprises.data.gouv.fr (API gratuite).
- Si succès : extrait dirigeants → INSERT `contacts` + UPDATE `companies.revenue_eur/year`.
- Si échec ou données partielles : fallback Infogreffe (Playwright).
- Si échec total : continue sans bloquer (juste pas de dirigeants).

### Étape 3 — Contact entreprise (NON BLOQUANTE)

- Appel Google Maps via worker Node Playwright stealth.
- Si échec : fallback Pages Jaunes.
- Si succès : UPDATE `companies.website` (si absent), INSERT `company_phones`, `company_addresses`.

### Étape 4 — Scraping site web (NON BLOQUANTE, dépend de l'étape 3)

- Si `companies.website` est null après étape 3 → SKIP (état step_completed = `['website_skipped_no_url']`).
- Sinon : worker Node Playwright crawler 2-3 niveaux + extraction TOUS emails classifiés + équipe LLM + comptes sociaux + mots-clés + pattern email.
- Si captcha/anti-bot → cooldown 24h, status `rate_limited`, run retry plus tard.

### Étape 5 — C-level via LinkedIn PhantomBuster (NON BLOQUANTE, COÛTEUSE)

- Vérifier quota LinkedIn account du jour (`linkedin_accounts.daily_used < daily_limit`).
- Si quota épuisé → reporter à demain (next_attempt_at = demain 09:00).
- Sinon : lancer recherche PhantomBuster Sales Navigator filtrée par `company_id` + filtres fonction (DSI/DAF/DRH/CMO/CCO).
- Coût ~370€/mois → important de ne déclencher que pour entreprises `priority_score IN ('prioritaire','moyenne')`.

### Étape 6 — Email Finder + Validation SMTP

- Pour chaque `contact` créé sans email → générer 18 patterns + valider cascade (cf fichier 06).
- Si `email_patterns` connu pour le domaine avec confidence ≥ 75 → 1 seul candidat (efficacité maximale).

### Étape 7 — Géocodage BAN (NON BLOQUANTE)

- Appel `api-adresse.data.gouv.fr` avec adresse complète.
- Si plusieurs résultats avec scores proches → use case LLM `geocoding_disambiguation`.
- UPDATE `companies.geom_point`.

### Étape 8 — Signaux business (NON BLOQUANTE)

- BODACC : recherche `registre = siren` sur 90 derniers jours.
- France Travail : recherche offres avec `entreprise.siret = siret_head` filtrées C-level.
- Crunchbase : si entreprise tech, scraping prudent.
- INSERT `company_business_signals` pour chaque signal détecté.

### Étape 9 — Classification & scoring LLM (NON BLOQUANTE)

- Appels parallèles aux 4 use cases LLM (`ia_maturity_scoring`, `axion_offer_match`, `auto_tag_generation`, `extract_strategic_keywords`).
- Si tous échouent (rare) → état `FailedClassification`.
- Sinon → calcul `priority_score` et `contact_priority` (cf formule plus bas) → UPDATE `companies`.

---

## Code orchestrateur PHP

```php
namespace App\Modules\Scraping\Orchestrator;

use App\Modules\Scraping\Jobs\StepIdentifyJob;
use App\Modules\Scraping\Jobs\StepEnrichLegalJob;
use App\Modules\Scraping\Jobs\StepContactInfoJob;
use App\Modules\Scraping\Jobs\StepWebsiteCrawlJob;
use App\Modules\Scraping\Jobs\StepClevelJob;
use App\Modules\Scraping\Jobs\StepGeocodeJob;
use App\Modules\Scraping\Jobs\StepBusinessSignalsJob;
use App\Modules\Scraping\Jobs\StepEmailFinderJob;
use App\Modules\Scraping\Jobs\StepClassifyJob;
use App\Modules\Scraping\Jobs\StepFinalizeJob;
use Illuminate\Support\Facades\Bus;

final class WaterfallOrchestrator
{
    public function launch(Company $company): EnrichmentRun
    {
        $run = EnrichmentRun::create([
            'workspace_id' => $company->workspace_id,
            'company_id'   => $company->id,
            'state'        => Pending::class,
            'started_at'   => now(),
            'steps_completed' => [],
        ]);
        $run->state->transitionTo(Identifying::class);

        Bus::chain([
            new StepIdentifyJob($run->id),                                  // séquentiel
            Bus::batch([                                                    // parallèle
                new StepEnrichLegalJob($run->id),
                new StepContactInfoJob($run->id),
                new StepGeocodeJob($run->id),
            ]),
            new StepWebsiteCrawlJob($run->id),                              // séquentiel
            Bus::batch([                                                    // parallèle
                new StepClevelJob($run->id),
                new StepBusinessSignalsJob($run->id),
            ]),
            new StepEmailFinderJob($run->id),                               // séquentiel
            new StepClassifyJob($run->id),                                  // séquentiel
            new StepFinalizeJob($run->id),                                  // séquentiel
        ])
        ->onQueue('enrichment-orchestrator')
        ->dispatch();

        return $run;
    }
}
```

Chaque job individuel transitionne l'état + met à jour `steps_completed` + insère ses propres `scraper_runs`. Les jobs `Bus::batch()` sont attendus avant de poursuivre la chaîne (Laravel gère cela nativement).

---

## Classification automatique LLM

### Use case `ia_maturity_scoring`

- **Input :** legal_name, website, naf_code, naf_label, effectif, keywords_csv, description
- **Output :** `{ "maturity": "decouverte"|"en_cours"|"avancee"|"inconnue", "confidence": 0..100, "rationale": "..." }`
- **Heuristiques de prompt :**
  - "decouverte" si site web minimaliste + aucun mot-clé IA/data/cloud + secteur traditionnel
  - "en_cours" si présence team data + mots-clés "transformation" / "modernisation"
  - "avancee" si chief data officer / équipe IA explicite / publications techniques

### Use case `axion_offer_match`

- **Input :** legal_name, naf_code, effectif_range_id, revenue_eur, ia_maturity, signaux_actifs[]
- **Output :** `{ "offer": "audit_flash"|"audit_cible"|"mission_pme"|"mission_eti"|"grand_programme"|"non_cible", "confidence": 0..100, "rationale": "..." }`
- **Règles métier :**
  - TPE 5-15 + maturité "decouverte" → `audit_flash`
  - PME 15-100 + maturité "decouverte"/"en_cours" → `audit_cible` ou `mission_pme`
  - PME 100-249 + maturité "en_cours" + signal actif → `mission_pme`
  - ETI 250-2000 + maturité "en_cours"/"avancee" → `mission_eti`
  - GE > 5000 ou groupes cotés → `grand_programme`
  - Hors cible (admin publique, agriculture, etc.) → `non_cible`

### Use case `auto_tag_generation`

- **Input :** legal_name, naf_label, keywords_csv, description, signals_csv
- **Output :** `{ "tags": [{"key": "fintech_b2b","confidence":80}, ...] }`
- Tags sélectionnés depuis `auto_tag_definitions WHERE source = 'llm'`.

### Use case `extract_strategic_keywords`

- Détecte les mots-clés stratégiques présents dans la description / site / news.
- Output : liste de keyword_id depuis `strategic_keywords` table.
- Boost confidence si keyword apparaît multiple fois dans le contenu rendered.

---

## Calcul `priority_score` et `contact_priority`

### Formule `priority_score`

```php
function computePriorityScore(Company $c, Collection $signals): string
{
    $offerWeight = match($c->axion_offer) {
        'mission_eti', 'grand_programme' => 1.0,
        'mission_pme' => 0.85,
        'audit_cible' => 0.7,
        'audit_flash' => 0.5,
        'non_cible'   => 0.0,
        default => 0.3,
    };

    $maturityWeight = match($c->ia_maturity) {
        'en_cours' => 1.0,
        'avancee'  => 0.8,           // déjà avancée = moins de besoin
        'decouverte' => 0.6,
        'inconnue' => 0.4,
        default => 0.4,
    };

    $signalsActive = $signals->where('signal_severity', 'critical')->count() * 2
        + $signals->where('signal_severity', 'high')->count();
    $signalWeight = min($signalsActive / 3, 1.0);  // saturé à 3 signaux hauts

    $sizeWeight = ($c->effectif_estimated && $c->effectif_estimated >= 50 && $c->effectif_estimated <= 2000) ? 1.0 : 0.6;

    $score = ($offerWeight * 0.5) + ($maturityWeight * 0.2) + ($signalWeight * 0.2) + ($sizeWeight * 0.1);

    return match(true) {
        $score >= 0.75 => 'prioritaire',
        $score >= 0.55 => 'moyenne',
        $score >= 0.30 => 'faible',
        default        => 'non-cible',
    };
}
```

### Formule `contact_priority`

```php
function computeContactPriority(Company $c, Collection $signals): string
{
    $hasCriticalActiveSignal = $signals->where('signal_severity', 'critical')
        ->where('detected_at', '>=', now()->subDays(30))->isNotEmpty();
    $hasHighSignalRecent = $signals->where('signal_severity', 'high')
        ->where('detected_at', '>=', now()->subDays(60))->isNotEmpty();

    if ($c->priority_score === 'non-cible') return 'frozen';
    if ($hasCriticalActiveSignal) return 'hot';
    if ($hasHighSignalRecent && $c->priority_score === 'prioritaire') return 'hot';
    if ($hasHighSignalRecent) return 'warm';
    if ($c->priority_score === 'prioritaire') return 'warm';
    if ($c->priority_score === 'moyenne') return 'cold';
    return 'frozen';
}
```

---

## Override manuel et tags personnalisables

Depuis la console admin (page "Détail entreprise") :

- L'opérateur peut **forcer** `priority_score`, `axion_offer`, `contact_priority` ou `ia_maturity` → stocké dans `companies.priority_override` + `companies.priority_override_by` (audit log).
- L'opérateur peut ajouter **manuellement** des tags (depuis `auto_tag_definitions` avec `source = 'manual'` ou créer un nouveau tag à la volée).
- L'opérateur peut "Relancer enrichissement" sur une entreprise → transition vers `Enriching` (saute `Identifying`).

Tous ces actes sont audit-loggés (`audit_logs.action` = `company.override.priority` / `company.tag.applied.manual` / `company.enrichment.relaunched`).

---

## Critères de done étape 9 (S10)

- [ ] Waterfall complet < 30s p95 pour 95 % des entreprises avec site web
- [ ] Taux de fiches "completed" (toutes étapes OK) ≥ 75 %
- [ ] Taux de classification LLM réussie ≥ 95 %
- [ ] Coût LLM moyen par entreprise classifiée ≤ 0,0015 €
- [ ] Override manuel testé (ne disparaît pas au re-enrichissement)
- [ ] Telegram notif sur signal critique fonctionne en bout-en-bout

---

## Prochaine étape

→ Lire `09_proxy_pluggable_system.md` pour le système de proxies pluggable.
