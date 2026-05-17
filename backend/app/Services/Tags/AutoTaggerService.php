<?php

namespace App\Services\Tags;

use App\Models\Company;
use App\Models\Tag;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applique automatiquement les tags structurés sur une Company :
 *  - dept-XX        (category=geo, kind=auto)      — depuis department_code
 *  - region-XX      (category=geo, kind=auto)      — depuis region_code
 *  - size-{cat}     (category=size, kind=auto)     — depuis size_category
 *  - sector-{cat}   (category=sector, kind=auto)   — depuis sector_main
 *  - {tag}          (category=intent, kind=llm)    — depuis signals.llm_classification.tags
 *
 * Crée les tags absents à la volée. Sync :
 *  - Retire les tags kind=auto sur la company qui ne matchent plus les attributs actuels
 *  - Ajoute les tags qui matchent et qui ne sont pas déjà attachés
 *  - Ne touche jamais aux tags kind=manual (gestion utilisateur)
 */
class AutoTaggerService
{
    private const COLOR_BY_CATEGORY = [
        'geo'    => 'sky',
        'sector' => 'violet',
        'size'   => 'amber',
        'intent' => 'emerald',
        'custom' => 'slate',
    ];

    /**
     * Synchronise les tags auto pour une company.
     * Retourne le delta : ['added' => [...], 'removed' => [...]]
     *
     * @return array{added: list<string>, removed: list<string>}
     */
    public function syncTags(Company $company): array
    {
        $desiredAutoTags = $this->computeDesiredTags($company);
        $desiredSlugs = array_keys($desiredAutoTags);

        // Ensure tags exist in DB
        $tagModelsBySlug = [];
        foreach ($desiredAutoTags as $slug => $spec) {
            $tagModelsBySlug[$slug] = $this->ensureTag(
                $company->workspace_id,
                $slug,
                $spec['name'],
                $spec['category'],
                $spec['kind'],
            );
        }

        // Tags actuellement attachés à la company (avec leur kind)
        $currentlyAttached = DB::table('company_tag as ct')
            ->join('tags as t', 't.id', '=', 'ct.tag_id')
            ->where('ct.company_id', $company->id)
            ->select('ct.tag_id', 't.slug', 't.kind', 'ct.assigned_by')
            ->get();

        $added = [];
        $removed = [];

        // 1. Retirer les tags auto qui ne sont plus désirés
        foreach ($currentlyAttached as $row) {
            if ($row->assigned_by === 'user' || $row->kind === 'manual') {
                continue;  // Skip tags manuels
            }
            if (! in_array($row->slug, $desiredSlugs, true)) {
                DB::table('company_tag')
                    ->where('company_id', $company->id)
                    ->where('tag_id', $row->tag_id)
                    ->delete();
                $removed[] = $row->slug;
            }
        }

        // 2. Ajouter les tags désirés qui ne sont pas attachés
        $attachedSlugs = array_column($currentlyAttached->toArray(), 'slug');
        foreach ($desiredAutoTags as $slug => $spec) {
            if (in_array($slug, $attachedSlugs, true)) {
                continue;
            }
            $tag = $tagModelsBySlug[$slug];
            DB::table('company_tag')->insertOrIgnore([
                'company_id'   => $company->id,
                'tag_id'       => $tag->id,
                'workspace_id' => $company->workspace_id,
                'assigned_at'  => now(),
                'assigned_by'  => $spec['assigned_by'],
            ]);
            $added[] = $slug;
        }

        // Sprint H4 — Audit log uniquement si delta > 0 (évite spam pour companies stables)
        if (! empty($added) || ! empty($removed)) {
            AuditLogger::log('company.tags_synced', [
                'workspace_id'  => (string) $company->workspace_id,
                'resource_type' => 'company',
                'resource_id'   => (string) $company->id,
                'siren'         => $company->siren,
                'added'         => $added,
                'removed'       => $removed,
            ]);
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @return array<string, array{name: string, category: string, kind: string, assigned_by: string}>
     */
    private function computeDesiredTags(Company $company): array
    {
        $tags = [];

        if ($company->department_code) {
            $slug = 'dept-' . strtolower($company->department_code);
            $tags[$slug] = [
                'name'        => 'Département ' . $company->department_code,
                'category'    => 'geo',
                'kind'        => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }
        if ($company->region_code) {
            $slug = 'region-' . strtolower($company->region_code);
            $tags[$slug] = [
                'name'        => 'Région ' . $company->region_code,
                'category'    => 'geo',
                'kind'        => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }
        if ($company->size_category) {
            $slug = 'size-' . strtolower($company->size_category);
            $tags[$slug] = [
                'name'        => 'Taille : ' . ucfirst($company->size_category),
                'category'    => 'size',
                'kind'        => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }
        if ($company->sector_main) {
            $slug = 'sector-' . strtolower(str_replace('_', '-', $company->sector_main));
            $tags[$slug] = [
                'name'        => 'Secteur : ' . str_replace('_', ' ', $company->sector_main),
                'category'    => 'sector',
                'kind'        => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }

        // Tags LLM (intent)
        $signals = $company->signals ?? [];
        $llmTags = $signals['llm_classification']['tags'] ?? [];
        if (is_array($llmTags)) {
            foreach ($llmTags as $rawTag) {
                if (! is_string($rawTag) || trim($rawTag) === '') {
                    continue;
                }
                $slug = Str::slug($rawTag, '-');
                if (strlen($slug) > 60) {
                    $slug = substr($slug, 0, 60);
                }
                if (! $slug) {
                    continue;
                }
                $tags[$slug] = [
                    'name'        => $rawTag,
                    'category'    => 'intent',
                    'kind'        => 'llm',
                    'assigned_by' => 'llm',
                ];
            }
        }

        return $tags;
    }

    private function ensureTag(string $workspaceId, string $slug, string $name, string $category, string $kind): Tag
    {
        return Tag::firstOrCreate(
            ['workspace_id' => $workspaceId, 'slug' => $slug],
            [
                'name'        => $name,
                'color'       => self::COLOR_BY_CATEGORY[$category] ?? 'slate',
                'category'    => $category,
                'kind'        => $kind,
                'description' => null,
                'rules'       => [],
            ],
        );
    }
}
