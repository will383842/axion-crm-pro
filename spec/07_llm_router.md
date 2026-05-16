# 07 — LLM ROUTER UNIFIÉ

## Vue d'ensemble

Le **LLM Router** est le composant central qui route chaque appel LLM dans Axion CRM Pro vers le **bon provider × bon modèle × bon prompt versionné**, en fonction du `use_case`. Il gère le **fallback chain** automatique en cas d'échec, le **cost tracking** par requête, l'**A/B testing** de prompts, et expose un **dashboard de coûts** dans la console admin. La configuration de chaque use case est **éditable runtime** (table `llm_use_cases`) — aucun redéploiement nécessaire pour changer un provider, un modèle, ou un prompt.

Le LLM Router est le seul point d'entrée LLM autorisé dans Axion CRM Pro. Anti-pattern interdit : appel direct à `\Anthropic::messages()` depuis un service métier. Tout passe par `LLMClient::generate($useCase, $variables)`.

---

## 1. Interface PHP `LLMClient`

```php
namespace App\Modules\LlmRouter\Contracts;

interface LLMClient
{
    /**
     * @param string $useCaseKey  ex: 'ia_maturity_scoring', 'axion_offer_match', 'extract_team_from_page'
     * @param array $variables   variables interpolées dans le prompt template
     * @param int|null $workspaceId  workspace pour scoping config + cost tracking
     * @param array $opts        ['related_entity' => 'company:12345', 'ab_force_variant' => 'B']
     */
    public function generate(string $useCaseKey, array $variables, ?int $workspaceId = null, array $opts = []): LlmResponse;
}

final class LlmResponse
{
    public function __construct(
        public readonly string $content,            // texte ou JSON décodé selon use case
        public readonly array $structured,          // parsed JSON si JSON expected
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costEurMicro,
        public readonly int $latencyMs,
        public readonly string $status,             // 'ok', 'fallback', 'partial'
        public readonly ?string $abVariant,
    ) {}
}
```

---

## 2. Implémentations des 5 providers

### `App\Modules\LlmRouter\Providers\AnthropicProvider`

```php
final class AnthropicProvider implements ProviderClient
{
    public function __construct(private HttpClient $http, private SecretsVault $vault) {}

    public function call(string $model, string $system, string $user, array $opts): ProviderResponse
    {
        $apiKey = $this->vault->get('kv/llm/anthropic/api_key');
        $payload = [
            'model'      => $model,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
            'max_tokens' => $opts['max_tokens'] ?? 1024,
            'temperature' => $opts['temperature'] ?? 0.2,
        ];
        $started = microtime(true);
        $response = $this->http->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'json' => $payload,
            'timeout' => 30,
        ]);
        $latencyMs = (int) ((microtime(true) - $started) * 1000);
        if (!$response->successful()) {
            return ProviderResponse::error($response->status(), $response->body(), $latencyMs);
        }
        $body = $response->json();
        $costMicro = $this->priceMicro($model, $body['usage']['input_tokens'], $body['usage']['output_tokens']);
        return new ProviderResponse(
            content: $body['content'][0]['text'],
            inputTokens: $body['usage']['input_tokens'],
            outputTokens: $body['usage']['output_tokens'],
            costEurMicro: $costMicro,
            latencyMs: $latencyMs,
            status: 'ok',
        );
    }

    /** Prix Anthropic au 2026-05 (à actualiser dans table `llm_providers.config.pricing`) */
    private function priceMicro(string $model, int $in, int $out): int
    {
        $pricing = [
            'claude-haiku-4-5'   => ['in' => 0.80, 'out' => 4.00],  // $ per M tokens
            'claude-sonnet-4-6'  => ['in' => 3.00, 'out' => 15.00],
            'claude-opus-4-7'    => ['in' => 15.00, 'out' => 75.00],
        ];
        $p = $pricing[$model] ?? $pricing['claude-haiku-4-5'];
        $eurPerUsd = 0.93;
        $usd = ($in * $p['in'] + $out * $p['out']) / 1_000_000;
        return (int) round($usd * $eurPerUsd * 1_000_000);
    }
}
```

### `OpenAIProvider`, `MistralProvider`, `OpenRouterProvider`, `OllamaProvider`

Structure quasi-identique. Différences :

- **OpenAI** : `POST https://api.openai.com/v1/chat/completions` avec `Authorization: Bearer {key}` + body OpenAI format (`messages`, `temperature`, `max_tokens`).
- **Mistral** : `POST https://api.mistral.ai/v1/chat/completions` (format OpenAI-compatible).
- **OpenRouter** : `POST https://openrouter.ai/api/v1/chat/completions` + `HTTP-Referer: https://crm.axion-ia.com`. Permet accès à 80+ modèles avec une seule clé.
- **Ollama (local)** : `POST http://llm-gpu-01.axion-crm-private:11434/api/chat` (réseau privé vSwitch). Pas d'API key. Free.

Toutes les implémentations renvoient un `ProviderResponse` uniforme.

---

## 3. Orchestrateur avec fallback chain

```php
final class LlmRouterOrchestrator implements LLMClient
{
    public function __construct(
        private LlmUseCaseRepository $useCases,
        private PromptTemplateRepository $templates,
        private ProviderRegistry $providers,
        private LlmUsageRecorder $recorder,
        private LlmAbTester $abTester,
    ) {}

    public function generate(string $useCaseKey, array $variables, ?int $workspaceId = null, array $opts = []): LlmResponse
    {
        $useCase = $this->useCases->getByKey($workspaceId, $useCaseKey);
        if (!$useCase || !$useCase->enabled) {
            throw new LlmUseCaseDisabledException($useCaseKey);
        }

        // Determine A/B variant
        $abVariant = $this->abTester->resolve($useCase, $opts['ab_force_variant'] ?? null);
        $templateId = $abVariant === 'B' ? $useCase->ab_test_config['variant_b']['template_id'] : $useCase->active_template_id;
        $template = $this->templates->getActiveVersion($templateId);
        $system = $this->interpolate($template->system_prompt, $variables);
        $user = $this->interpolate($template->user_prompt, $variables);

        $chain = array_merge(
            [['provider' => $useCase->primary_provider, 'model' => $useCase->primary_model]],
            $useCase->fallback_chain
        );

        $attempts = [];
        foreach ($chain as $i => $step) {
            $provider = $this->providers->get($step['provider']);
            $r = $provider->call(
                model: $step['model'],
                system: $system,
                user: $user,
                opts: [
                    'max_tokens' => $useCase->max_tokens,
                    'temperature' => $useCase->temperature,
                ],
            );
            $attempts[] = ['step' => $i, 'provider' => $step['provider'], 'model' => $step['model'], 'status' => $r->status];
            $this->recorder->record($workspaceId, $useCase, $step, $r, $abVariant, $opts['related_entity'] ?? null);
            if ($r->status === 'ok') {
                $structured = $this->maybeParseJson($r->content);
                return new LlmResponse(
                    content: $r->content,
                    structured: $structured,
                    provider: $step['provider'],
                    model: $step['model'],
                    inputTokens: $r->inputTokens,
                    outputTokens: $r->outputTokens,
                    costEurMicro: $r->costEurMicro,
                    latencyMs: $r->latencyMs,
                    status: $i === 0 ? 'ok' : 'fallback',
                    abVariant: $abVariant,
                );
            }
        }
        throw new LlmAllProvidersFailedException($useCaseKey, $attempts);
    }
}
```

---

## 4. Prompt templates versionnés

### Format YAML d'un template (export/import admin)

```yaml
use_case: ia_maturity_scoring
version: 7
system_prompt: |
  Tu es un analyste senior chez Axion-IA. Tu évalues la maturité IA d'une entreprise française B2B.
  Critères : présence d'équipe data/IA, signaux digitaux, mots-clés stratégiques détectés sur le site,
  secteur d'activité, taille. Réponds STRICTEMENT en JSON conforme au schéma.
user_prompt: |
  Entreprise : {{ legal_name }}
  Site web : {{ website }}
  NAF : {{ naf_code }} ({{ naf_label }})
  Effectif estimé : {{ effectif }}
  Mots-clés stratégiques détectés : {{ keywords_csv }}
  Description : {{ description }}

  Réponds en JSON :
  { "maturity": "decouverte" | "en_cours" | "avancee" | "inconnue",
    "confidence": 0..100,
    "rationale": "max 2 phrases" }
variables_spec:
  - { name: legal_name, type: string, required: true }
  - { name: website, type: string, required: false }
  - { name: naf_code, type: string, required: true }
  - { name: naf_label, type: string, required: true }
  - { name: effectif, type: integer, required: false }
  - { name: keywords_csv, type: string, required: false }
  - { name: description, type: string, required: false }
```

### Versioning

- Chaque modification du prompt **crée une nouvelle version** (`prompt_template_versions.version` = MAX(version) + 1).
- L'ancienne version reste consultable et reproductible (rollback en 1 clic).
- `prompt_templates.current_version_id` pointe vers la version "live".
- Migration via UI : "Mettre la version 8 en production" → `UPDATE prompt_templates SET current_version_id = 8`.

### Interpolation

Moteur Mustache-like : `{{ variable }}` → remplacé. Variables absentes du payload : si `required = true` → exception `LlmTemplateValidationException` ; sinon → remplacé par chaîne vide.

---

## 5. Cost tracking par requête

Chaque appel LLM insère une ligne dans `llm_usage` (table partitionnée mois, voir fichier 03) avec :
- `workspace_id`, `use_case_key`, `provider_key`, `model`
- `template_id`, `template_version`
- `input_tokens`, `output_tokens`, `cost_eur_micro`
- `latency_ms`, `status` (`ok` / `retry` / `fallback` / `error`)
- `ab_variant`
- `related_entity` (ex: `company:12345`)
- `request_hash` (sha256 du payload — sert à dédupliquer si même requête tombe 2× via réessais)

Le `cost_eur_micro` est calculé par le `ProviderClient` selon la grille tarifaire stockée dans `llm_providers.config.pricing` (modifiable depuis admin sans redéploiement quand un provider change ses prix).

---

## 6. A/B testing

Configuration par use case :

```jsonc
{
  "enabled": true,
  "variant_b": {
    "template_id": 42,
    "primary_provider": "openai",
    "primary_model": "gpt-4o-mini"
  },
  "split": 0.10  // 10% du trafic vers B
}
```

Sélection variant : hash(`workspace_id` + `use_case_key` + `related_entity` + jour) → % → `A` ou `B`. Garantit stabilité (même entité = toujours même variant).

Comparaison post-hoc via dashboard : pour chaque use case, afficher côte-à-côte A vs B sur cost / latency / qualité (qualité = % de réponses jugées correctes par échantillonnage manuel ou auto-évaluation par un LLM "juge").

---

## 7. Dashboard "coût par enrichissement"

Vue admin (page 9 dans `13_ui_admin_phase1.md`) avec :

| Métrique | Granularité | Source |
|---|---|---|
| Coût total LLM jour / semaine / mois | journée | agrégation `llm_usage` |
| Coût par use_case (top 10) | mensuel | `GROUP BY use_case_key` |
| Coût par provider | mensuel | `GROUP BY provider_key` |
| Coût par entreprise enrichie | calculé | total / `companies.last_enriched_at` count |
| Latence p50/p95/p99 par use_case | quotidien | percentiles |
| Taux de fallback par use_case | quotidien | `WHERE status = 'fallback'` |
| Tokens consommés (in/out) par use_case | quotidien | sommes |
| A/B winner par use_case | hebdomadaire | comparaison |

Graphes Recharts. Filtres : période, workspace, use_case, provider. Export CSV.

---

## 8. Configuration des 10 use cases Phase 1 (seed)

| use_case_key | Primary | Fallback chain | Cible |
|---|---|---|---|
| `sector_classification` | Mistral Small | OpenAI gpt-4o-mini, Claude Haiku 4.5 | Classifier NAF si manquant |
| `ia_maturity_scoring` | Claude Haiku 4.5 | OpenAI gpt-4o-mini, Mistral Small | Maturité IA estimée |
| `axion_offer_match` | Claude Haiku 4.5 | OpenAI gpt-4o-mini | Recommander offre Axion-IA |
| `extract_team_from_page` | Claude Haiku 4.5 | OpenAI gpt-4o-mini | Parser HTML → JSON team |
| `parse_company_description` | Claude Haiku 4.5 | Mistral Small | Résumer description en 500 chars |
| `detect_email_pattern` | Mistral Small | Claude Haiku 4.5 | Inférer pattern email |
| `extract_strategic_keywords` | Mistral Small | Claude Haiku 4.5 | Tag mots-clés stratégiques |
| `geocoding_disambiguation` | Claude Haiku 4.5 | OpenAI gpt-4o-mini | Choisir bon résultat BAN |
| `business_signal_detection` | Claude Haiku 4.5 | OpenAI gpt-4o-mini | Classifier signal BODACC |
| `auto_tag_generation` | Claude Haiku 4.5 | OpenAI gpt-4o-mini, Mistral Small | Générer tags business |

### 5 use cases Phase 2 (scaffold, désactivés en V1)

| use_case_key | Primary | Cible |
|---|---|---|
| `cold_email_personalization_standard` | Claude Haiku 4.5 | Phase 2 |
| `cold_email_personalization_vip` | Claude Sonnet 4.6 | Phase 2 |
| `linkedin_message_generation` | OpenAI gpt-4o-mini | Phase 2 |
| `reply_intent_detection` | Llama 3.3 70B (Ollama local) | Phase 2 |
| `crm_lead_scoring` | Llama 3.3 70B (Ollama local) | Phase 2 |

> **Doctrine de routing :** classifications déterministes + faible créativité → Mistral Small (le moins cher). Tâches nécessitant raisonnement structuré + parsing JSON → Claude Haiku 4.5 (excellent rapport qualité/prix). Tâches sensibles ou nécessitant qualité narrative (cold email VIP Phase 2) → Claude Sonnet 4.6. Tâches volumineuses internes sans contraintes UE strictes → Llama local sur Ollama.

---

## 9. Anti-hallucination (JSON expected)

Pour les use cases qui exigent un JSON parsable :
- Toujours préciser le format dans le prompt (avec exemple)
- Setter `temperature = 0.0` à `0.2` max
- Si JSON parse fail → retry 1 fois en mode "extract first JSON block" + log warning
- Si retry échoue → fallback chain
- Côté Anthropic, utiliser `response_format` quand applicable

```php
private function maybeParseJson(string $content): array
{
    $trimmed = trim($content);
    if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    }
    // Tentative regex
    if (preg_match('/```json\s*(\{[\s\S]+\})\s*```/', $content, $m)) {
        return json_decode($m[1], true) ?? [];
    }
    if (preg_match('/(\{[\s\S]+\})/', $content, $m)) {
        return json_decode($m[1], true) ?? [];
    }
    return [];
}
```

---

## 10. Budget LLM cible Phase 1

| Hypothèse | Valeur |
|---|---|
| Entreprises enrichies/mois | 200 000 |
| Use cases LLM par enrichissement | 4-5 |
| Tokens moyens par appel | ~1500 (in 1200 + out 300) |
| Tokens totaux/mois | ~1,2 Md |
| **% routé Mistral Small** | 30% |
| **% routé Claude Haiku 4.5** | 60% |
| **% routé OpenAI mini** | 7% |
| **% routé fallback Sonnet** | 3% |
| **Coût LLM total/mois cible** | **150-250 €/mois** |

Si dépassement > 350€/mois : alerte Slack + email Will. Mitigation : déplacer plus de use cases vers Mistral Small ou Ollama local.

---

## 11. Tests d'acceptance (S2 + S10)

- [ ] LLM Router route correctement chaque use case vers son provider primary
- [ ] Fallback chain s'active en cas de 5xx provider (test mocké)
- [ ] A/B testing 90/10 produit 9% à 11% de variant B sur dataset de 10 000 appels
- [ ] Cost tracking dans `llm_usage` correspond au cumul reçu de chaque provider à ±0,5%
- [ ] Dashboard de coûts affiche les bonnes valeurs sur tous les filtres
- [ ] Modification d'un use_case dans l'admin (ex: changer Haiku → Sonnet) prend effet **au prochain appel** sans redéploiement
- [ ] Création d'une nouvelle version de template + activation rollback OK

---

## 12. Anti-patterns interdits

- ❌ Appel direct au SDK Anthropic/OpenAI/Mistral hors du LLM Router
- ❌ Stockage de clés API LLM dans `.env` (vault uniquement)
- ❌ Templates de prompt hardcodés dans le code PHP (tous en DB versionnés)
- ❌ Use case "fourre-tout" (`generic_call`) — chaque besoin = 1 use case nommé
- ❌ Ignorer le `cost_eur_micro` dans les analyses budgétaires
- ❌ A/B testing > 50 % sur use case business-critique sans validation préalable

---

## Prochaine étape

→ Lire `08_waterfall_enrichissement_classification.md` pour l'orchestration waterfall 9 étapes.
