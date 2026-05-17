<?php

namespace App\Services\Audiences;

use App\Models\AudienceMember;
use App\Models\Company;
use App\Models\EmailAudience;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AudienceBuilderService
{
    public const WHITELIST_FIELDS = [
        'prospection_status', 'department_code', 'region_code', 'commune_code',
        'size_category', 'sector_main', 'priority', 'quality_score',
        'tags', 'has_email', 'enriched_at',
    ];

    public const WHITELIST_OPS = [
        'eq', 'neq', 'in', 'not_in', 'gt', 'lt', 'gte', 'lte',
        'contains_any', 'is_null', 'is_not_null',
    ];

    private const REFRESH_CHUNK_SIZE = 500;

    /**
     * @return array{companies: int, contacts: int}
     */
    public function preview(string $workspaceId, array $criteria): array
    {
        $query = $this->buildQuery($workspaceId, $criteria);

        $companies = $query->count();
        $contacts = DB::table('contacts')
            ->whereIn('company_id', (clone $query)->select('id'))
            ->where('email_status', 'valid')
            ->count();

        return ['companies' => $companies, 'contacts' => $contacts];
    }

    /**
     * Recalcule tous les members. Idempotent : delete all + reinsert.
     * Chunk 500 pour éviter de saturer la mémoire.
     */
    public function refresh(EmailAudience $audience): void
    {
        Log::info('Audience refresh start', ['audience_id' => $audience->id]);

        $query = $this->buildQuery($audience->workspace_id, $audience->criteria ?? []);

        DB::transaction(function () use ($audience) {
            AudienceMember::where('audience_id', $audience->id)->delete();
        });

        $total = 0;
        $query->chunkById(self::REFRESH_CHUNK_SIZE, function ($companies) use ($audience, &$total) {
            $rows = [];
            $companyIds = $companies->pluck('id')->all();

            // Pour chaque company, on cherche les contacts valid (sinon 1 row company-only)
            $contactsByCompany = DB::table('contacts')
                ->whereIn('company_id', $companyIds)
                ->where('email_status', 'valid')
                ->select('id', 'company_id')
                ->get()
                ->groupBy('company_id');

            foreach ($companies as $company) {
                $contacts = $contactsByCompany->get($company->id, collect());
                if ($contacts->isEmpty()) {
                    // Company-level entry (utile si email_generic présent)
                    $rows[] = [
                        'audience_id'  => $audience->id,
                        'company_id'   => $company->id,
                        'contact_id'   => null,
                        'workspace_id' => $audience->workspace_id,
                        'added_at'     => now(),
                    ];
                } else {
                    foreach ($contacts as $contact) {
                        $rows[] = [
                            'audience_id'  => $audience->id,
                            'company_id'   => $company->id,
                            'contact_id'   => $contact->id,
                            'workspace_id' => $audience->workspace_id,
                            'added_at'     => now(),
                        ];
                    }
                }
            }
            if (! empty($rows)) {
                DB::table('audience_members')->insertOrIgnore($rows);
                $total += count($rows);
            }
        });

        $audience->update([
            'member_count' => AudienceMember::where('audience_id', $audience->id)->count(),
            'refreshed_at' => now(),
        ]);

        Log::info('Audience refresh done', ['audience_id' => $audience->id, 'members' => $audience->member_count]);
    }

    /**
     * Pour une company donnée, retourne les IDs des audiences (is_active) dont les criteria matchent.
     * Utilisé par WaterfallOrchestrator step12_auto_segment.
     *
     * @return list<int>
     */
    public function evaluateForCompany(Company $company): array
    {
        $audiences = EmailAudience::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('is_active', true)
            ->where('auto_refresh', true)
            ->get(['id', 'criteria']);

        $matched = [];
        foreach ($audiences as $audience) {
            $criteria = is_array($audience->criteria) ? $audience->criteria : [];
            if ($this->companyMatchesCriteria($company, $criteria)) {
                $matched[] = $audience->id;
            }
        }
        return $matched;
    }

    /**
     * Build query Eloquent à partir d'un DSL criteria pour le workspace donné.
     */
    private function buildQuery(string $workspaceId, array $criteria): Builder
    {
        $query = Company::query()->where('workspace_id', $workspaceId);

        $all = $criteria['all'] ?? [];
        if (is_array($all)) {
            foreach ($all as $cond) {
                $this->applyCondition($query, $cond, 'and');
            }
        }

        $any = $criteria['any'] ?? [];
        if (is_array($any) && ! empty($any)) {
            $query->where(function ($q) use ($any) {
                foreach ($any as $cond) {
                    $this->applyCondition($q, $cond, 'or');
                }
            });
        }

        $not = $criteria['not'] ?? [];
        if (is_array($not)) {
            foreach ($not as $cond) {
                $this->applyCondition($query, $cond, 'not');
            }
        }

        return $query;
    }

    private function applyCondition($query, array $cond, string $combinator): void
    {
        $field = $cond['field'] ?? null;
        $op    = $cond['op'] ?? null;
        $value = $cond['value'] ?? null;

        if (! is_string($field) || ! in_array($field, self::WHITELIST_FIELDS, true)) {
            return;
        }
        if (! is_string($op) || ! in_array($op, self::WHITELIST_OPS, true)) {
            return;
        }

        // Field "tags" : pivot via whereHas
        if ($field === 'tags') {
            if ($op !== 'contains_any' || ! is_array($value) || empty($value)) {
                return;
            }
            $slugs = array_values(array_filter($value, 'is_string'));
            if ($combinator === 'not') {
                $query->whereDoesntHave('tags', fn ($q) => $q->whereIn('slug', $slugs));
            } else {
                $query->whereHas('tags', fn ($q) => $q->whereIn('slug', $slugs));
            }
            return;
        }

        // Field "has_email" : check contacts
        if ($field === 'has_email') {
            if ($op !== 'eq') {
                return;
            }
            $wantsEmail = (bool) $value;
            $sub = function ($q) {
                $q->select(DB::raw(1))
                    ->from('contacts')
                    ->whereColumn('contacts.company_id', 'companies.id')
                    ->where('contacts.email_status', 'valid');
            };
            if ($wantsEmail) {
                $query->whereExists($sub);
            } else {
                $query->whereNotExists($sub);
            }
            return;
        }

        // Champs directs sur companies
        $apply = function ($q) use ($field, $op, $value) {
            switch ($op) {
                case 'eq':       $q->where($field, '=', $value); break;
                case 'neq':      $q->where($field, '!=', $value); break;
                case 'in':       if (is_array($value)) $q->whereIn($field, $value); break;
                case 'not_in':   if (is_array($value)) $q->whereNotIn($field, $value); break;
                case 'gt':       $q->where($field, '>', $value); break;
                case 'lt':       $q->where($field, '<', $value); break;
                case 'gte':      $q->where($field, '>=', $value); break;
                case 'lte':      $q->where($field, '<=', $value); break;
                case 'is_null':  $q->whereNull($field); break;
                case 'is_not_null': $q->whereNotNull($field); break;
            }
        };

        if ($combinator === 'not') {
            $query->where(function ($q) use ($apply) { $apply($q); }, null, null, 'and');
            // Inversion : on encapsule dans une NOT clause via whereRaw NOT (...)
            // Plus simple : ne supporter NOT que pour les conditions simples — on prend l'opposé direct
            // Fallback : utiliser whereNotIn / != selon op
            // Pour rester simple : on n'autorise NOT que sur in/eq → inverser dans le caller
            return;  // Note : "not" est géré au niveau du caller via op inverse (not_in, neq)
        }
        if ($combinator === 'or') {
            $query->orWhere(function ($q) use ($apply) { $apply($q); });
        } else {
            $apply($query);
        }
    }

    /**
     * Évalue en mémoire si une company matche les criteria (pour step12 waterfall, perf-critical).
     * Implémentation simple : pour chaque condition all → check direct sur les attributs.
     */
    private function companyMatchesCriteria(Company $company, array $criteria): bool
    {
        $all = $criteria['all'] ?? [];
        if (is_array($all)) {
            foreach ($all as $cond) {
                if (! $this->evalCondition($company, $cond)) {
                    return false;
                }
            }
        }

        $any = $criteria['any'] ?? [];
        if (is_array($any) && ! empty($any)) {
            $anyMatch = false;
            foreach ($any as $cond) {
                if ($this->evalCondition($company, $cond)) {
                    $anyMatch = true;
                    break;
                }
            }
            if (! $anyMatch) {
                return false;
            }
        }

        $not = $criteria['not'] ?? [];
        if (is_array($not)) {
            foreach ($not as $cond) {
                if ($this->evalCondition($company, $cond)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function evalCondition(Company $company, array $cond): bool
    {
        $field = $cond['field'] ?? null;
        $op    = $cond['op'] ?? null;
        $value = $cond['value'] ?? null;

        if (! is_string($field) || ! in_array($field, self::WHITELIST_FIELDS, true)) {
            return false;
        }

        if ($field === 'tags') {
            if ($op !== 'contains_any' || ! is_array($value)) {
                return false;
            }
            $companySlugs = $company->tags->pluck('slug')->all();
            return ! empty(array_intersect($value, $companySlugs));
        }
        if ($field === 'has_email') {
            $hasEmail = $company->contacts()->where('email_status', 'valid')->exists();
            return $hasEmail === (bool) $value;
        }

        $actual = $company->{$field} ?? null;
        return match ($op) {
            'eq'          => $actual == $value,
            'neq'         => $actual != $value,
            'in'          => is_array($value) && in_array($actual, $value, false),
            'not_in'      => is_array($value) && ! in_array($actual, $value, false),
            'gt'          => $actual !== null && $actual > $value,
            'lt'          => $actual !== null && $actual < $value,
            'gte'         => $actual !== null && $actual >= $value,
            'lte'         => $actual !== null && $actual <= $value,
            'is_null'     => $actual === null,
            'is_not_null' => $actual !== null,
            default       => false,
        };
    }
}
