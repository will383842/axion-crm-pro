# 08 — Waterfall enrichissement + classification

> **State machine :** Spatie Laravel Model States.
> **10 étapes** orchestrées pour chaque entreprise. Parallélisation entre étapes indépendantes.
> **Cible latence :** TPE/PME < 30 s, ETI/Grandes < 90 s.

---

## §1 — State machine

### États de `enrichment_runs.final_status`

```
        ┌─────────┐
        │ created │
        └────┬────┘
             │ start()
             ▼
        ┌──────────┐
        │  running │──┐
        └────┬─────┘  │ failStep()
             │         ▼
   complete()│   ┌──────────┐
             │   │  partial │ → manual_review queue
             ▼   └────┬─────┘
        ┌──────────┐  │
        │ success  │◀─┘ acceptPartial()
        └──────────┘
             │
             │ Si erreur fatale
             ▼
        ┌──────────┐
        │  failed  │ → exponential backoff retry
        └──────────┘
```

### Implémentation Spatie

```php
// app/States/Enrichment/EnrichmentRunState.php
use Spatie\ModelStates\State;

abstract class EnrichmentRunState extends State
{
    public static function config(): \Spatie\ModelStates\StateConfig
    {
        return parent::config()
            ->default(CreatedState::class)
            ->allowTransition(CreatedState::class, RunningState::class)
            ->allowTransition(RunningState::class, SuccessState::class)
            ->allowTransition(RunningState::class, PartialState::class)
            ->allowTransition(RunningState::class, FailedState::class)
            ->allowTransition(PartialState::class, SuccessState::class);
    }
}

class CreatedState extends EnrichmentRunState { public static $name = 'created'; }
class RunningState extends EnrichmentRunState { public static $name = 'running'; }
class PartialState extends EnrichmentRunState { public static $name = 'partial'; }
class SuccessState extends EnrichmentRunState { public static $name = 'success'; }
class FailedState extends EnrichmentRunState { public static $name = 'failed'; }
```

---

## §2 — Workflow orchestrateur

```php
// app/Services/Enrichment/WaterfallOrchestrator.php
class WaterfallOrchestrator
{
    public function enrichCompany(Company $company, ?User $triggeredBy = null): EnrichmentRun
    {
        $run = EnrichmentRun::create([
            'workspace_id' => $company->workspace_id,
            'company_id'   => $company->id,
            'triggered_by' => $triggeredBy?->id,
            'quality_before' => $company->quality_score,
            'final_status' => 'running',
        ]);

        // Opt-out check
        if ($this->isCompanyOptedOut($company)) {
            $run->update(['final_status' => 'success', 'completed_at' => now()]);
            return $run;
        }

        try {
            // Étape 1 — Identification INSEE (souvent déjà fait, sinon refresh)
            $this->runStep($run, 'insee', fn() => $this->insee->refresh($company));

            // Étapes 2 + 3 en PARALLÈLE (legal + contact entreprise)
            $this->runParallel($run, [
                'annuaire_entreprises' => fn() => $this->annuaire->fetch($company),
                'google_maps'          => fn() => $this->googleMaps->fetch($company),
            ]);

            // Étape 4 — Sites web (dépend de l'URL trouvée par Google Maps OU INSEE)
            $this->runStep($run, 'site_web', fn() => $this->siteWeb->crawl($company));

            // Étape 5 — Google Search Wrapper (LinkedIn URLs)
            $this->runStep($run, 'google_search_linkedin', fn() => $this->linkedinFinder->find($company));

            // Étape 6 — Direction Finder (conditionnel)
            if ($this->shouldRunDirectionFinder($company)) {
                $this->runStep($run, 'direction_finder', fn() => $this->directionFinder->run($company), softFail: true);
            }

            // Étape 7 — Email finder + validation
            $this->runStep($run, 'email_finder', fn() => $this->emailFinder->findForCompany($company));

            // Étape 8 + 9 en PARALLÈLE (géocodage + signaux)
            $this->runParallel($run, [
                'geocoding'        => fn() => $this->ban->geocode($company),
                'business_signals' => fn() => $this->signals->detect($company),
            ]);

            // Étape 10 — Classification LLM
            $this->runStep($run, 'classification', fn() => $this->classifier->classify($company));

            // Score qualité final
            $newScore = DB::statement('SELECT recompute_company_quality_score(?)', [$company->id]);
            $company->refresh();

            $run->update([
                'final_status'   => 'success',
                'quality_after'  => $company->quality_score,
                'completed_at'   => now(),
                'duration_ms'    => $run->started_at->diffInMilliseconds(now()),
                'cost_total_eur' => $this->sumLLMCost($run),
            ]);
            event(new CompanyEnriched($company, $run));
        } catch (\Throwable $e) {
            $run->update([
                'final_status' => 'failed',
                'completed_at' => now(),
                'metadata'     => array_merge($run->metadata, ['error' => $e->getMessage()]),
            ]);
            throw $e;
        }

        return $run;
    }

    private function runStep(EnrichmentRun $run, string $stepName, \Closure $fn, bool $softFail = false): void
    {
        $stepStart = now();
        try {
            $result = $fn();
            $this->appendStep($run, $stepName, 'ok', $stepStart, $result);
        } catch (\Throwable $e) {
            if ($softFail) {
                $this->appendStep($run, $stepName, 'failed_soft', $stepStart, ['error' => $e->getMessage()]);
            } else {
                $this->appendStep($run, $stepName, 'failed', $stepStart, ['error' => $e->getMessage()]);
                throw $e;
            }
        }
    }

    private function runParallel(EnrichmentRun $run, array $tasks): void
    {
        $promises = [];
        foreach ($tasks as $name => $fn) {
            $promises[$name] = Concurrency::run($fn);  // Laravel 12 fork concurrency
        }
        $results = Concurrency::wait($promises);
        foreach ($results as $name => $res) {
            $this->appendStep($run, $name, $res->isOk() ? 'ok' : 'failed', $run->started_at, $res->value());
        }
    }

    private function shouldRunDirectionFinder(Company $c): bool
    {
        return ($c->effectif_min ?? 0) >= 100
            || in_array($c->size_category, ['eti', 'ge'], true);
    }
}
```

---

## §3 — Classification LLM (étape 10)

```php
class ClassifierService
{
    public function classify(Company $company): array
    {
        $context = $this->buildContext($company);   // company + signals + keywords + website excerpt

        // 1. Maturité IA
        $maturity = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'ia_maturity_scoring',
            variables: $context,
        ));
        $maturityData = json_decode($maturity->text, true);

        // 2. Offre Axion-IA matchée
        $offer = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'axion_offer_match',
            variables: array_merge($context, ['maturity' => $maturityData]),
        ));
        $offerData = json_decode($offer->text, true);

        // 3. Auto tags
        $tags = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'auto_tag_generation',
            variables: array_merge($context, ['maturity' => $maturityData, 'offer' => $offerData]),
        ));
        $tagsArr = json_decode($tags->text, true);

        // 4. Mots-clés stratégiques
        $kw = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'extract_strategic_keywords',
            variables: $context,
        ));
        $kwArr = json_decode($kw->text, true);

        // Save
        $company->update([
            'ia_maturity_score'        => $maturityData['score'],
            'ia_maturity_label'        => $maturityData['label'],
            'axion_offer_match_code'   => $offerData['offer_code'],
            'axion_offer_match_score'  => $offerData['score'],
            'priority_label'           => $this->priorityFromOffer($offerData['score']),
            'auto_tags'                => $tagsArr['tags'] ?? [],
        ]);

        // INSERT mots-clés stratégiques détectés
        $this->saveStrategicKeywords($company, $kwArr);

        return compact('maturityData', 'offerData', 'tagsArr', 'kwArr');
    }

    private function priorityFromOffer(int $score): string
    {
        return match (true) {
            $score >= 80 => 'prioritaire',
            $score >= 60 => 'moyenne',
            $score >= 30 => 'faible',
            default => 'non_cible',
        };
    }
}
```

---

## §4 — Tags manuels + auto tags

### Auto tags (LLM)

Générés par `auto_tag_generation`. Stockés dans `companies.auto_tags TEXT[]`.

### Tags manuels

Stockés dans `companies.tags TEXT[]` + table `company_tags` (avec `is_auto = false`, `set_by = user_id`).

### Application des `auto_tag_definitions` (règles DSL)

```php
class AutoTagApplier
{
    public function applyForCompany(Company $c): void
    {
        $rules = AutoTagDefinition::where('workspace_id', $c->workspace_id)
            ->where('is_active', true)
            ->get();
        $newAutoTags = [];
        foreach ($rules as $rule) {
            if ($this->matches($rule->rule_dsl, $c)) {
                $newAutoTags[] = $rule->tag_slug;
            }
        }
        $c->update(['auto_tags' => array_unique($newAutoTags)]);
    }

    private function matches(array $rule, Company $c): bool
    {
        // DSL : { "all": [ { "naf_section": "J" }, { "size_category_in": ["pme","eti"] } ] }
        if (isset($rule['all'])) {
            return collect($rule['all'])->every(fn($r) => $this->matches($r, $c));
        }
        if (isset($rule['any'])) {
            return collect($rule['any'])->contains(fn($r) => $this->matches($r, $c));
        }
        if (isset($rule['not'])) {
            return !$this->matches($rule['not'], $c);
        }
        // Leaf rules
        if (isset($rule['naf_section'])) return substr($c->naf_subclass_code, 0, 1) === $rule['naf_section'];
        if (isset($rule['size_category_in'])) return in_array($c->size_category, $rule['size_category_in']);
        if (isset($rule['has_signal'])) return $c->businessSignals()->where('signal_type', $rule['has_signal'])->where('is_active', true)->exists();
        if (isset($rule['effectif_gte'])) return ($c->effectif_min ?? 0) >= $rule['effectif_gte'];
        if (isset($rule['city_insee_in'])) return in_array($c->city_insee, $rule['city_insee_in']);
        if (isset($rule['keyword_in'])) return $c->strategicKeywords()->whereHas('keyword', fn($q) => $q->whereIn('keyword', $rule['keyword_in']))->exists();
        return false;
    }
}
```

---

## §5 — Triggers d'enrichissement

### Auto

- Job nightly `enrich-new-discovered` (toutes les entreprises status `discovered`, batch 1000)
- Sur arrivée d'un signal BODACC (fraîcheur < 7j) → enrichissement prioritaire
- Sur arrivée d'une nouvelle entreprise INSEE → enrichissement après 1h délai (politesse APIs)

### Manuel

- Bouton "Relancer enrichissement" sur la fiche entreprise (UI admin)
- Bouton "Enrichir toutes les fiches de cette zone" (carte interactive → panneau action)
- Bulk action liste entreprises : "Enrichir les X sélectionnées"

---

## §6 — Métriques

```
axion_crm_enrichment_runs_total{status="success|partial|failed"}
axion_crm_enrichment_duration_ms_histogram{size_category}
axion_crm_enrichment_cost_eur_histogram
axion_crm_enrichment_quality_transition_total{from,to}  -- ex from=basic, to=complete
axion_crm_waterfall_step_duration_ms{step}
axion_crm_waterfall_step_failures_total{step,error}
```

---

## Lecture suivante

→ `09_proxy_pluggable_system.md` (interface ProxyProvider + 4 implémentations + routeur intelligent).
