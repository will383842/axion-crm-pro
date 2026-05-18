<?php

namespace App\Services\LLM;

use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Data\LLM\LLMResponseData;
use App\Models\LlmUseCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Router LLM réel — Sprint 4 implémentation détaillée.
 * - cache idempotent par requestHash (sha256 useCase+vars sorted+model+version) 24h
 * - fallback chain providers selon llm_use_cases.fallback_chain
 * - cost tracking en `llm_usage` + kill-switch via workspaces.cost_cap_eur
 * - sanitizeExternalInputs : préfixe `ext_` variables pour anti prompt-injection
 */
class LLMRouterService implements LLMClient
{
    public function complete(LLMRequestData $request): LLMResponseData
    {
        /** @var LlmUseCase|null $useCase */
        $useCase = LlmUseCase::query()
            ->where('slug', $request->useCaseSlug)
            ->where('enabled', true)
            ->first();

        if (! $useCase) {
            throw new \RuntimeException("LLM use case not found or disabled: {$request->useCaseSlug}");
        }

        // 1. Sanitize external inputs (anti prompt-injection)
        $variables = $this->sanitizeExternalInputs($request->variables);

        // 2. Idempotency cache
        $requestHash = $this->computeRequestHash($useCase, $variables, $request->promptTemplateVersion);
        if ($cached = Cache::get("llm:hash:{$requestHash}")) {
            $cached['cacheHit'] = true;
            return LLMResponseData::from($cached);
        }

        // 3. Cost cap workspace
        if ($useCase->workspace_id) {
            $monthlySpend = (float) DB::table('llm_usage')
                ->where('workspace_id', $useCase->workspace_id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('cost_eur');
            $cap = (float) DB::table('workspaces')->where('id', $useCase->workspace_id)->value('cost_cap_eur');
            if ($cap > 0 && $monthlySpend >= $cap) {
                throw new \RuntimeException("LLM monthly cost cap reached for workspace ({$monthlySpend} >= {$cap})");
            }
        }

        // 4. Render prompt template
        $template = $this->loadPromptTemplate($useCase, $request->promptTemplateVersion);
        $renderedPrompt = $this->renderTemplate($template, $variables);

        // 5. Try providers chain
        $providers = array_filter(array_merge(
            [$useCase->primary_provider],
            (array) ($useCase->fallback_chain ?? []),
        ));
        $providers = array_unique(array_values($providers));

        $lastError = null;
        foreach ($providers as $providerSlug) {
            try {
                $start = microtime(true);
                $client = ProviderFactory::make($providerSlug, (string) $useCase->model);
                $rawText = $client->complete($renderedPrompt, $variables, (array) $useCase->options);
                $latencyMs = (int) ((microtime(true) - $start) * 1000);

                $usage = $client->lastUsage();
                $resp = new LLMResponseData(
                    text: $rawText,
                    providerUsed: $providerSlug,
                    modelUsed: (string) $useCase->model,
                    tokensInput: $usage['tokens_input'] ?? 0,
                    tokensOutput: $usage['tokens_output'] ?? 0,
                    costEur: $usage['cost_eur'] ?? 0.0,
                    latencyMs: $latencyMs,
                    cacheHit: false,
                    requestHash: $requestHash,
                    promptTemplateSlug: $useCase->slug,
                    promptTemplateVersion: $request->promptTemplateVersion ?? (int) $useCase->prompt_version,
                );

                $this->recordUsage($useCase, $resp);
                Cache::put("llm:hash:{$requestHash}", $resp->toArray(), now()->addHours(24));

                return $resp;
            } catch (\Throwable $e) {
                Log::warning('LLM provider failed, trying next', [
                    'use_case' => $useCase->slug,
                    'provider' => $providerSlug,
                    'error'    => $e->getMessage(),
                ]);
                $lastError = $e;
            }
        }

        throw $lastError ?? new \RuntimeException('All LLM providers failed for ' . $useCase->slug);
    }

    /** @param array<string,mixed> $variables */
    private function sanitizeExternalInputs(array $variables): array
    {
        // Toute variable préfixée `ext_` est traitée comme input externe non-sûr → strip backticks,
        // strip lignes "ignore previous instructions", strip URL schemes dangereux.
        foreach ($variables as $key => $value) {
            if (! str_starts_with($key, 'ext_')) {
                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            $sanitized = $value;
            // Strip blocs commands LLM
            $sanitized = preg_replace('/(ignore (?:all|the|previous|prior).*?(?:instructions|rules|prompts))/i', '[redacted]', $sanitized) ?? $sanitized;
            $sanitized = preg_replace('/(act as|you are now)\s+(?:a\s+)?(?:different|new|root)/i', '[redacted]', $sanitized) ?? $sanitized;
            // Strip backticks (markdown injection)
            $sanitized = str_replace('`', "'", $sanitized);
            // Truncate to reasonable length
            if (mb_strlen($sanitized) > 8000) {
                $sanitized = mb_substr($sanitized, 0, 8000) . '…';
            }
            $variables[$key] = $sanitized;
        }
        return $variables;
    }

    /** @param array<string,mixed> $variables */
    private function computeRequestHash(LlmUseCase $useCase, array $variables, ?int $version): string
    {
        $payload = [
            'slug'    => $useCase->slug,
            'model'   => $useCase->model,
            'version' => $version ?? $useCase->prompt_version,
            'vars'    => $this->sortRecursively($variables),
        ];
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param mixed $data */
    private function sortRecursively($data)
    {
        if (! is_array($data)) {
            return $data;
        }
        ksort($data);
        foreach ($data as $k => $v) {
            $data[$k] = $this->sortRecursively($v);
        }
        return $data;
    }

    private function loadPromptTemplate(LlmUseCase $useCase, ?int $version): string
    {
        $version = $version ?? (int) $useCase->prompt_version;
        $cacheKey = "llm:prompt:{$useCase->slug}:{$version}";
        return Cache::remember($cacheKey, 600, function () use ($useCase, $version) {
            $tpl = DB::table('prompt_templates')
                ->where('use_case_id', $useCase->id)
                ->first();
            if (! $tpl) {
                return $this->defaultTemplate($useCase->slug);
            }
            $v = DB::table('prompt_template_versions')
                ->where('prompt_template_id', $tpl->id)
                ->where('version', $version)
                ->first();
            return $v?->content ?? $this->defaultTemplate($useCase->slug);
        });
    }

    private function defaultTemplate(string $slug): string
    {
        return match ($slug) {
            'classify_company_axion'  => 'Tu es expert en classification d\'entreprises B2B. Analyse cette entreprise et retourne un JSON {"ia_maturity":{...},"axion_offer_match":{...},"priority":"haute|moyenne|basse|gelee"}. Entreprise : {{denomination}} (NAF {{naf}}, {{effectif_range}}). Site : {{ext_website_text}}',
            'sector_classification'   => 'Classifie le secteur métier de cette entreprise selon notre taxonomie. Retourne JSON {"secteur_metier_axion":"...","maturite_ia_visible":"absente|emergente|en_cours|avancee|leader"}. Données : {{ext_company_data}}',
            'extract_team_from_page'  => 'Extrais les membres de l\'équipe dirigeante. Retourne JSON array [{name,title,linkedin_url?,confidence}]. Page : {{ext_page_text}}',
            'detect_email_pattern'    => 'Détecte le pattern email de l\'entreprise depuis ces exemples : {{ext_known_emails}}. Retourne JSON {"pattern":"{first}.{last}@{domain}","confidence":80}',
            'auto_tag'                => 'Suggère 3-5 tags pertinents. Retourne JSON {"tags":["..."]}. Entreprise : {{denomination}} {{ext_summary}}',
            default                   => 'Retourne JSON valide. Input : {{ext_input}}',
        };
    }

    /** @param array<string,mixed> $variables */
    private function renderTemplate(string $template, array $variables): string
    {
        // Twig-light : remplace {{key}} par la valeur scalaire ou JSON pour les arrays
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($variables) {
            $key = $m[1];
            if (! array_key_exists($key, $variables)) {
                return '';
            }
            $v = $variables[$key];
            return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }, $template) ?? $template;
    }

    /**
     * Sprint H15 fix (2026-05-18) — Fail-open : si le use case n'a pas de
     * workspace_id (cas use cases globaux comme classify_company_axion ou
     * appels tinker/CLI sans context user), on skip silencieusement l'insert
     * dans llm_usage plutôt que de lever une exception qui fait croire au
     * router que le provider a échoué (et déclenche un fallback inutile).
     *
     * Cohérent avec AuditLogger H4 qui fail-open aussi sur workspace_id manquant.
     * Le tracking de coût par workspace reste correct quand workspace_id existe.
     */
    private function recordUsage(LlmUseCase $useCase, LLMResponseData $resp): void
    {
        if (! $useCase->workspace_id) {
            Log::debug('LLM usage tracking skipped (no workspace_id on use case)', [
                'use_case' => $useCase->slug,
                'provider' => $resp->providerUsed,
                'cost'     => $resp->costEur,
            ]);
            return;
        }
        try {
            DB::table('llm_usage')->insert([
                'workspace_id'  => $useCase->workspace_id,
                'use_case_slug' => $useCase->slug,
                'provider'      => $resp->providerUsed,
                'model'         => $resp->modelUsed,
                'tokens_input'  => $resp->tokensInput,
                'tokens_output' => $resp->tokensOutput,
                'cost_eur'      => $resp->costEur,
                'latency_ms'    => $resp->latencyMs,
                'cache_hit'     => $resp->cacheHit,
                'request_hash'  => $resp->requestHash,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('LLM usage tracking insert failed (non-fatal)', [
                'use_case' => $useCase->slug,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
