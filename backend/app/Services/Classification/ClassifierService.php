<?php

namespace App\Services\Classification;

use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Models\Company;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Service Classification — orchestre 4 use cases LLM sur une entreprise :
 *  1. sector_classification          (secteur métier + maturité IA visible)
 *  2. classify_company_axion         (matching offre Axion-IA + priority)
 *  3. extract_strategic_keywords     (mots-clés stratégiques)
 *  4. auto_tag                       (tags via rules DSL JSONB)
 *
 * Met à jour : `companies.signals.classification`, `companies.priority`,
 * `companies.signals.strategic_keywords`, `company_tag` pivot.
 */
class ClassifierService
{
    public function __construct(private readonly LLMClient $llm) {}

    public function classify(Company $company): void
    {
        $signals = $company->signals ?: [];

        $sectorResp = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'sector_classification',
            variables: [
                'ext_company_data' => json_encode([
                    'denomination'   => $company->denomination,
                    'naf'            => $company->naf,
                    'effectif_range' => $company->effectif_range,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ));
        $signals['classification'] = array_merge($signals['classification'] ?? [], $sectorResp->asJson() ?? []);

        $offerResp = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'classify_company_axion',
            variables: [
                'denomination'    => $company->denomination,
                'naf'             => $company->naf,
                'effectif_range'  => $company->effectif_range,
                'ext_website_text'=> '',
            ],
        ));
        $offerJson = $offerResp->asJson() ?? [];
        $signals['classification'] = array_merge($signals['classification'], $offerJson);
        if (! empty($offerJson['priority'])) {
            $company->priority = (string) $offerJson['priority'];
        }

        $kwResp = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'extract_strategic_keywords',
            variables: ['ext_company_data' => $company->denomination . ' / ' . $company->naf],
        ));
        $signals['strategic_keywords'] = $kwResp->asJson()['keywords'] ?? [];

        $company->signals = $signals;
        $company->save();

        DB::statement('SELECT recompute_company_quality_score(?)', [$company->id]);

        // Auto-tag via LLM + rules
        $this->autoTag($company);
    }

    private function autoTag(Company $company): void
    {
        $tagResp = $this->llm->complete(new LLMRequestData(
            useCaseSlug: 'auto_tag',
            variables: [
                'denomination' => $company->denomination,
                'ext_summary'  => json_encode([
                    'naf'      => $company->naf,
                    'size'     => $company->size_category,
                    'signals'  => $company->signals,
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE),
            ],
        ));
        $tagSlugs = $tagResp->asJson()['tags'] ?? [];
        $tagIds = [];
        foreach ($tagSlugs as $slug) {
            $tag = Tag::query()->firstOrCreate(
                ['workspace_id' => $company->workspace_id, 'slug' => $slug],
                ['name' => ucfirst((string) $slug)],
            );
            $tagIds[] = $tag->id;
        }
        $company->tags()->sync($tagIds);

        // Apply DSL rules pour tags additionnels avec match programmatique
        (new AutoTagApplier())->apply($company);
    }
}
