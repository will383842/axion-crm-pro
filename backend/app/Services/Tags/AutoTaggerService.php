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
 *  - implantation-XX (category=geo, kind=auto)     — depuis signals.implantations (pays ISO2)
 *  - size-{cat}     (category=size, kind=auto)     — depuis size_category
 *  - sector-{cat}   (category=sector, kind=auto)   — depuis sector_main
 *  - {tag}          (category=intent, kind=llm)    — depuis signals.llm_classification.tags
 *
 * Crée les tags absents à la volée. Sync :
 *  - Retire les tags kind=auto sur la company qui ne matchent plus les attributs actuels
 *  - Ajoute les tags qui matchent et qui ne sont pas déjà attachés
 *  - Ne touche jamais aux tags kind=manual (gestion utilisateur)
 *  - Ne retire jamais un tag de provenance `src:` ni un tag VERROUILLÉ
 *    (`tags.is_locked`) : ce sont des faits constatés, pas des dérivés
 */
class AutoTaggerService
{
    private const COLOR_BY_CATEGORY = [
        'geo' => 'sky',
        'sector' => 'violet',
        'size' => 'amber',
        'intent' => 'emerald',
        'custom' => 'slate',
    ];

    /**
     * Libellés FR des pays d'implantation rencontrés (fallback = code ISO2).
     * Liste courte et volontairement locale : on l'étend quand une nouvelle
     * campagne pays arrive, pas besoin d'un référentiel complet.
     */
    private const IMPLANTATION_COUNTRY_LABELS = [
        'RO' => 'Roumanie',
    ];

    /** Libellés FR des natures d'entité (cf. companies.entity_nature). */
    private const ENTITY_NATURE_LABELS = [
        'entreprise' => 'Entreprise',
        'association' => 'Association',
        'cci' => 'Chambre de commerce',
        'enseignement' => 'Enseignement',
        'cabinet' => 'Cabinet (conseil, avocats)',
        'institution' => 'Institution',
        'media' => 'Média',
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
            ->select('ct.tag_id', 't.slug', 't.kind', 't.is_locked', 'ct.assigned_by')
            ->get();

        $added = [];
        $removed = [];

        // 1. Retirer les tags auto qui ne sont plus désirés
        foreach ($currentlyAttached as $row) {
            if ($row->assigned_by === 'user' || $row->kind === 'manual') {
                continue;  // Skip tags manuels
            }
            // Les tags de PROVENANCE (`src:scraping-<slug>`, posés par le funnel
            // L3, cumulatifs) ne sont jamais « désirés » par cette synchro : les
            // retirer effacerait silencieusement l'origine de la fiche à la
            // première passe d'enrichissement. On ne touche pas au namespace src:.
            if (str_starts_with((string) $row->slug, 'src:')) {
                continue;
            }
            // Même raisonnement, un cran plus large : un tag VERROUILLÉ
            // (`tags.is_locked`, référentiel gouverné du GovernedTagsSeeder)
            // décrit un fait constaté que cette synchro ne sait pas re-dériver
            // des attributs de la fiche — `svc:audit` (l'entreprise a demandé
            // un audit, posé par SiteSyncIngestService), `cand-offre:*`… Ils ne
            // sont donc JAMAIS « désirés » ici, et la boucle de retrait les
            // emportait à la première passe d'enrichissement, alors que l'API
            // refuse déjà de les retirer (CompanyTagsBulkController → 422
            // « tag_verrouille »). Le retrait d'un tag gouverné passe par une
            // action explicite, jamais par un recalcul.
            if ((bool) $row->is_locked) {
                continue;
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
                'company_id' => $company->id,
                'tag_id' => $tag->id,
                'workspace_id' => $company->workspace_id,
                'assigned_at' => now(),
                'assigned_by' => $spec['assigned_by'],
            ]);
            $added[] = $slug;
        }

        // Sprint H4 — Audit log uniquement si delta > 0 (évite spam pour companies stables)
        if (! empty($added) || ! empty($removed)) {
            AuditLogger::log('company.tags_synced', [
                'workspace_id' => (string) $company->workspace_id,
                'resource_type' => 'company',
                'resource_id' => (string) $company->id,
                'siren' => $company->siren,
                'added' => $added,
                'removed' => $removed,
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
                'name' => 'Département ' . $company->department_code,
                'category' => 'geo',
                'kind' => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }
        if ($company->region_code) {
            $slug = 'region-' . strtolower($company->region_code);
            $tags[$slug] = [
                'name' => 'Région ' . $company->region_code,
                'category' => 'geo',
                'kind' => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }

        // Pays d'immatriculation, pour les entités NON françaises : sans lui,
        // une association roumaine serait indiscernable d'une fiche Sirene
        // dans une campagne. (Les 4,29 M de fiches FR ne sont pas taguées :
        // ce serait un tag universel, donc sans pouvoir de tri.)
        $country = strtoupper((string) ($company->country_code ?? 'FR'));
        if ($country !== 'FR' && preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $slug = 'pays-' . strtolower($country);
            $tags[$slug] = [
                'name' => 'Pays : ' . (self::IMPLANTATION_COUNTRY_LABELS[$country] ?? $country),
                'category' => 'geo',
                'kind' => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }

        // Nature de l'entité — c'est ELLE qui permet de viser « les
        // associations » ou « les chambres de commerce » sans les mélanger
        // aux entreprises dans une campagne.
        $nature = $company->entity_nature ?? null;
        if (is_string($nature) && isset(self::ENTITY_NATURE_LABELS[$nature])) {
            $tags['nature-' . $nature] = [
                'name' => self::ENTITY_NATURE_LABELS[$nature],
                'category' => 'custom',
                'kind' => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }

        // Implantations à l'étranger (signals.implantations, clef = ISO2) —
        // le tag est DÉRIVÉ des données, donc il survit à toutes les resyncs.
        $implantations = ($company->signals ?? [])['implantations'] ?? [];
        if (is_array($implantations)) {
            foreach (array_keys($implantations) as $country) {
                if (! is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
                    continue;
                }
                $cc = strtoupper($country);
                $slug = 'implantation-' . strtolower($cc);
                $tags[$slug] = [
                    'name' => 'Implantation : ' . (self::IMPLANTATION_COUNTRY_LABELS[$cc] ?? $cc),
                    'category' => 'geo',
                    'kind' => 'auto',
                    'assigned_by' => 'auto-rule',
                ];
            }
        }
        if ($company->size_category) {
            $slug = 'size-' . strtolower($company->size_category);
            $tags[$slug] = [
                'name' => 'Taille : ' . ucfirst($company->size_category),
                'category' => 'size',
                'kind' => 'auto',
                'assigned_by' => 'auto-rule',
            ];
        }
        if ($company->sector_main) {
            $slug = 'sector-' . strtolower(str_replace('_', '-', $company->sector_main));
            $tags[$slug] = [
                'name' => 'Secteur : ' . str_replace('_', ' ', $company->sector_main),
                'category' => 'sector',
                'kind' => 'auto',
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
                    'name' => $rawTag,
                    'category' => 'intent',
                    'kind' => 'llm',
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
                'name' => $name,
                'color' => self::COLOR_BY_CATEGORY[$category] ?? 'slate',
                'category' => $category,
                'kind' => $kind,
                'description' => null,
                'rules' => [],
            ],
        );
    }
}
