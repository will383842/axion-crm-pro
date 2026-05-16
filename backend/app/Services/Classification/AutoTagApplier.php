<?php

namespace App\Services\Classification;

use App\Models\Company;
use App\Models\Tag;

/**
 * Applique les règles DSL JSONB stockées dans `tags.rules`.
 *
 * Format rules (exemple) :
 * {
 *   "all": [
 *     { "field": "naf", "op": "starts_with", "value": "62" },
 *     { "field": "effectif_min", "op": ">=", "value": 50 }
 *   ]
 * }
 * ou
 * {
 *   "any": [
 *     { "field": "signals.bodacc[0].type", "op": "=", "value": "procedure" }
 *   ]
 * }
 *
 * Opérateurs supportés : =, !=, >, >=, <, <=, in, starts_with, ends_with, contains, regex
 */
class AutoTagApplier
{
    public function apply(Company $company): void
    {
        $tags = Tag::query()
            ->where('workspace_id', $company->workspace_id)
            ->whereNotNull('rules')
            ->get();

        $matchedTagIds = [];
        foreach ($tags as $tag) {
            $rules = $tag->rules;
            if (empty($rules) || ! is_array($rules)) {
                continue;
            }
            if ($this->matches($company, $rules)) {
                $matchedTagIds[] = $tag->id;
            }
        }

        if (! empty($matchedTagIds)) {
            $company->tags()->syncWithoutDetaching($matchedTagIds);
        }
    }

    /** @param array<string,mixed> $rules */
    public function matches(Company $company, array $rules): bool
    {
        if (isset($rules['all']) && is_array($rules['all'])) {
            foreach ($rules['all'] as $cond) {
                if (! $this->evaluate($company, $cond)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($rules['any']) && is_array($rules['any'])) {
            foreach ($rules['any'] as $cond) {
                if ($this->evaluate($company, $cond)) {
                    return true;
                }
            }
            return false;
        }
        // Single condition implicite
        return $this->evaluate($company, $rules);
    }

    /** @param array<string,mixed> $cond */
    private function evaluate(Company $company, array $cond): bool
    {
        $field = (string) ($cond['field'] ?? '');
        $op    = (string) ($cond['op']    ?? '=');
        $value = $cond['value'] ?? null;

        $actual = $this->resolveField($company, $field);

        return match ($op) {
            '='           => $actual == $value,
            '!='          => $actual != $value,
            '>'           => is_numeric($actual) && (float) $actual > (float) $value,
            '>='          => is_numeric($actual) && (float) $actual >= (float) $value,
            '<'           => is_numeric($actual) && (float) $actual <  (float) $value,
            '<='          => is_numeric($actual) && (float) $actual <= (float) $value,
            'in'          => is_array($value) && in_array($actual, $value, true),
            'starts_with' => is_string($actual) && str_starts_with($actual, (string) $value),
            'ends_with'   => is_string($actual) && str_ends_with($actual, (string) $value),
            'contains'    => is_string($actual) && str_contains($actual, (string) $value),
            'regex'       => is_string($actual) && preg_match((string) $value, $actual) === 1,
            default       => false,
        };
    }

    private function resolveField(Company $company, string $path): mixed
    {
        $parts = preg_split('/[.\[\]]+/', trim($path, '.[]'));
        $current = $company->toArray();
        foreach ($parts as $p) {
            if ($p === '' || $p === null) continue;
            if (is_array($current) && array_key_exists($p, $current)) {
                $current = $current[$p];
            } elseif (is_array($current) && is_numeric($p) && isset($current[(int) $p])) {
                $current = $current[(int) $p];
            } else {
                return null;
            }
        }
        return $current;
    }
}
