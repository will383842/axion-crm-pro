<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\Taxonomy;
use App\Models\Candidate;
use App\Support\MasquageCoordonnees;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * VIVIER CANDIDATS — l'univers étanche (plan §2.1, conception §2.2).
 *
 * Trois verrous se superposent ici, et aucun n'est de trop :
 *   1. l'APPARTENANCE (`user_workspaces`) : un utilisateur business-only prend
 *      un 403 franc — pas une liste vide, qui se confond avec « il n'y a rien » ;
 *   2. le WORKSPACE : la requête ne s'exécute que sous
 *      `WorkspaceContext::run($vivier)`, donc sous les policies RLS du lot L0 ;
 *   3. le SCOPE explicite `where('workspace_id', $vivier)`.
 *
 * Le premier est le seul qui produise un message ; les deux autres sont la
 * ceinture et la bretelle qui rattrapent un contrôleur oublieux.
 */
class CandidatesController extends ConsoleController
{
    /** @var array<string, string> */
    private const SORTS = [
        'recent' => 'derniere_interaction_at',
        'created' => 'created_at',
        'nom' => 'last_name',
    ];

    public function index(Request $request): JsonResponse
    {
        $vivier = $this->vivierWorkspace($request);
        $perPage = $this->perPage($request);

        $validated = $request->validate([
            'relation_type' => ['nullable', 'string', 'in:' . implode(',', Taxonomy::CANDIDATE_RELATION_TYPES)],
            'lifecycle_stage' => ['nullable', 'string', 'in:' . implode(',', Taxonomy::CANDIDATE_LIFECYCLE_STAGES)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:120'],
            'consent' => ['nullable', 'string', 'in:vivier,sans_vivier,tous'],
            'q' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'string', 'in:' . implode(',', array_keys(self::SORTS))],
        ]);

        return WorkspaceContext::run($vivier, function () use ($vivier, $validated, $perPage): JsonResponse {
            $query = Candidate::query()
                ->whereNull('deleted_at')
                ->where('workspace_id', $vivier)
                ->with('tags');

            $relationType = $validated['relation_type'] ?? null;
            if (is_string($relationType)) {
                $query->where('relation_type', $relationType);
            }

            $lifecycleStage = $validated['lifecycle_stage'] ?? null;
            if (is_string($lifecycleStage)) {
                $query->where('lifecycle_stage', $lifecycleStage);
            }

            $this->applyConsent($query, $validated['consent'] ?? null);
            $this->applyTags($query, $validated['tags'] ?? null);

            $q = $validated['q'] ?? null;
            if (is_string($q) && trim($q) !== '') {
                $term = trim($q);
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('last_name', 'ilike', $term . '%')
                        ->orWhere('first_name', 'ilike', $term . '%')
                        ->orWhere('email', 'ilike', $term . '%');
                });
            }

            $sortKey = is_string($validated['sort'] ?? null) ? $validated['sort'] : 'recent';
            $column = self::SORTS[$sortKey] ?? 'derniere_interaction_at';

            $page = $query->orderByDesc($column)->orderByDesc('id')->cursorPaginate($perPage);

            return $this->ok([
                // 🔴 SITE JUMEAU de B12-002 / F36-006. Le vivier est le SEUL
                // univers ou la fiche est nominative par construction : un
                // candidat n'a pas d'« accueil de societe » derriere lequel
                // s'abriter. `present()` rend un TABLEAU : `masquerSiRequis`,
                // qui mute des objets, n'y ferait rien — d'ou la porte
                // `masquerTableauSiRequis`, qui RETOURNE la valeur masquee.
                'data' => MasquageCoordonnees::masquerTableauSiRequis(array_map(
                    fn (Candidate $candidate): array => $this->present($candidate),
                    $page->items(),
                )),
                'meta' => [
                    'per_page' => $page->perPage(),
                    'next_cursor' => $page->nextCursor()?->encode(),
                    'prev_cursor' => $page->previousCursor()?->encode(),
                    'has_more' => $page->hasMorePages(),
                ],
            ]);
        });
    }

    /**
     * Compteurs par famille de métiers et par étape — les pastilles de la
     * navigation vivier (conception §2.2).
     */
    public function counts(Request $request): JsonResponse
    {
        $vivier = $this->vivierWorkspace($request);

        return WorkspaceContext::run($vivier, function () use ($vivier): JsonResponse {
            /** @var array<string, int> $byFamily */
            $byFamily = array_fill_keys(Taxonomy::CANDIDATE_RELATION_TYPES, 0);
            /** @var array<string, int> $byStage */
            $byStage = array_fill_keys(Taxonomy::CANDIDATE_LIFECYCLE_STAGES, 0);

            $rows = DB::table('candidates')
                ->selectRaw('relation_type, lifecycle_stage, count(*) AS total')
                ->where('workspace_id', $vivier)
                ->whereNull('deleted_at')
                ->groupBy('relation_type', 'lifecycle_stage')
                ->get();

            $total = 0;
            foreach ($rows as $row) {
                $count = (int) $row->total;
                $total += $count;

                $family = is_string($row->relation_type) ? $row->relation_type : '';
                if (array_key_exists($family, $byFamily)) {
                    $byFamily[$family] += $count;
                }

                $stage = is_string($row->lifecycle_stage) ? $row->lifecycle_stage : '';
                if (array_key_exists($stage, $byStage)) {
                    $byStage[$stage] += $count;
                }
            }

            return $this->ok([
                'total' => $total,
                'by_relation_type' => $byFamily,
                'by_lifecycle_stage' => $byStage,
            ]);
        });
    }

    /**
     * Filtre de CONSENTEMENT — pas un raffinement d'interface : `consent_vivier_at`
     * est ce qui distingue une candidature en cours d'étude (base
     * `precontractual`, purge à la clôture) d'un profil conservé en CVthèque
     * (base `consent`, horloge CNIL 2 ans). Les deux populations ne se traitent
     * pas pareil ; les confondre dans une même liste, c'est démarcher la
     * première.
     *
     * @param  Builder<Candidate>  $query
     */
    private function applyConsent(Builder $query, mixed $consent): void
    {
        $mode = is_string($consent) ? $consent : 'tous';

        if ($mode === 'vivier') {
            $query->whereNotNull('consent_vivier_at');
        } elseif ($mode === 'sans_vivier') {
            $query->whereNull('consent_vivier_at');
        }
    }

    /**
     * @param  Builder<Candidate>  $query
     */
    private function applyTags(Builder $query, mixed $tags): void
    {
        if (! is_array($tags) || $tags === []) {
            return;
        }

        $slugs = array_values(array_unique(array_filter(
            array_map(static fn (mixed $t): string => is_string($t) ? $t : '', $tags),
            static fn (string $t): bool => $t !== '',
        )));

        foreach ($slugs as $slug) {
            $query->whereExists(function (\Illuminate\Database\Query\Builder $sub) use ($slug): void {
                $sub->select(DB::raw('1'))
                    ->from('candidate_tag')
                    ->join('tags', 'tags.id', '=', 'candidate_tag.tag_id')
                    ->whereColumn('candidate_tag.candidate_id', 'candidates.id')
                    ->where('tags.slug', $slug);
            });
        }
    }

    /**
     * Projection explicite. `cv_ref` est une RÉFÉRENCE au fichier resté côté
     * site (plan §2.5) : la console affiche un lien, elle ne duplique jamais la
     * pièce.
     *
     * @return array<string, mixed>
     */
    private function present(Candidate $candidate): array
    {
        return [
            'id' => $candidate->id,
            'first_name' => $candidate->getAttribute('first_name'),
            'last_name' => $candidate->last_name,
            'email' => $candidate->getAttribute('email'),
            'phone' => $candidate->getAttribute('phone'),
            'relation_type' => $candidate->relation_type,
            'lifecycle_stage' => $candidate->lifecycle_stage,
            'offer_slug' => $candidate->getAttribute('offer_slug'),
            'source' => $candidate->getAttribute('source'),
            'legal_basis' => $candidate->getAttribute('legal_basis'),
            'consent_version' => $candidate->consent_version,
            'consent_vivier_at' => $candidate->consent_vivier_at,
            'derniere_interaction_at' => $candidate->derniere_interaction_at,
            // Horloge CNIL « CVthèque » : 2 ans après la dernière interaction.
            // Affichée, jamais subie (conception §2.3).
            'purge_prevue_le' => $candidate->derniere_interaction_at?->copy()->addYears(2),
            // ⚠️ `$candidate->attributes` renverrait le tableau d'attributs
            // BRUTS d'Eloquent (propriété `Model::$attributes`), pas la colonne
            // JSONB du wizard. Le seul accès correct est `getAttribute()`.
            'attributes' => $candidate->getAttribute('attributes'),
            'cv_ref' => $candidate->getAttribute('cv_ref'),
            'opt_out' => $candidate->opt_out,
            'person_key' => $candidate->person_key,
            'tags' => $candidate->tags->pluck('slug')->values()->all(),
        ];
    }
}
