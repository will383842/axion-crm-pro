<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TagsController extends ApiController
{
    use VerrouOptimiste;

    /** Borne de la liste (854 étiquettes en production le 2026-10-02). */
    public const LIMITE_LISTE = 5000;

    public static function cleComptes(string $espace): string
    {
        return 'crm:tags:comptes:v2:' . $espace;
    }

    /**
     * Nombre de fiches par étiquette de l'espace, mis en cache : frais 10 min,
     * servi périmé jusqu'à 1 jour pendant qu'UN recalcul part après la
     * réponse (`Cache::flexible`, même mécanique que `CompteursHub`).
     *
     * @return array{comptes: array<string, int>, computed_at: ?string}
     */
    public static function comptesParEtiquette(string $espace): array
    {
        /** @var mixed $charge */
        $charge = Cache::flexible(self::cleComptes($espace), [600, 86400], static function () use ($espace): array {
            return WorkspaceContext::run($espace, static function () use ($espace): array {
                $comptes = DB::table('company_tag as ct')
                    ->join('tags as t', 't.id', '=', 'ct.tag_id')
                    ->where('t.workspace_id', $espace)
                    ->groupBy('ct.tag_id')
                    ->selectRaw('ct.tag_id, count(*) AS c')
                    ->pluck('c', 'tag_id')
                    ->mapWithKeys(static fn ($c, $id): array => [(string) $id => (int) $c])
                    ->all();

                return ['comptes' => $comptes, 'computed_at' => now()->utc()->toIso8601ZuluString()];
            });
        }, lock: ['seconds' => 60]);

        return is_array($charge) && is_array($charge['comptes'] ?? null) ? $charge : ['comptes' => [], 'computed_at' => null];
    }

    /**
     * Oublie les comptes de l'espace : appelé après un étiquetage en masse,
     * pour que la page Étiquettes ne montre pas un compte périmé de 10 min.
     */
    public static function oublierComptes(string $espace): void
    {
        Cache::forget(self::cleComptes($espace));
    }

    /**
     * @OA\Get(path="/tags", tags={"Tags"}, summary="Liste des tags du workspace",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('tags')) {
            return $this->ok(['data' => []]);
        }

        try {
            $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
            $q = Tag::query()->orderBy('category')->orderBy('name');
            if ($workspaceId) {
                $q->where('workspace_id', $workspaceId);
            }
            if ($category = $r->query('category')) {
                $q->where('category', $category);
            }
            if ($kind = $r->query('kind')) {
                $q->where('kind', $kind);
            }

            // Ajoute count companies par tag (left join optimisé)
            // Lot 3 (2026-10-02) — « liste tronquée à 500, 3,6 s ».
            //  - la borne passe à LIMITE_LISTE : la production porte 854
            //    étiquettes, la page en montrait 500 sans le dire ;
            //  - le nombre de fiches par étiquette compte `company_tag`
            //    (20,8 M de lignes, ≈ 7 s à froid sous le rôle applicatif) :
            //    il est servi depuis un cache par espace (`comptesParEtiquette`),
            //    recalculé APRÈS la réponse quand il a plus de 10 min.
            $tags = $q->limit(self::LIMITE_LISTE)->get();
            $comptes = $workspaceId
                ? self::comptesParEtiquette((string) $workspaceId)
                : ['comptes' => [], 'computed_at' => null];
            $counts = collect($comptes['comptes']);

            return $this->ok([
                'data' => TagResource::collection($tags->map(function ($t) use ($counts) {
                    // `companies_count` n'est pas une colonne : c'est l'attribut
                    // que `withCount('companies')` produirait. On l'écrit via
                    // setAttribute() — strictement équivalent à `$t->x = …`
                    // (Model::__set délègue à setAttribute) — parce que
                    // l'affectation directe est vue par PHPStan comme l'écriture
                    // d'une propriété de comptage de relation, en lecture seule.
                    $t->setAttribute('companies_count', (int) $counts->get((string) $t->id, 0));

                    return $t;
                })),
                'meta' => [
                    'total' => $tags->count(),
                    'tronquee' => $tags->count() >= self::LIMITE_LISTE,
                    'comptes_calcules_le' => $comptes['computed_at'],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('tags.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/tags", tags={"Tags"}, summary="Crée un tag manuel",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=201, description="Created"))
     */
    public function store(Request $r): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['error' => 'workspace required'], 422);
        }

        $data = $r->validate([
            'slug' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'in:geo,sector,size,intent,custom'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = $data['slug'] ?? Str::slug($data['name'], '-');
        $existing = Tag::where('workspace_id', $workspaceId)->where('slug', $slug)->first();
        if ($existing) {
            return $this->ok(['error' => 'slug already exists', 'tag' => new TagResource($existing)], 409);
        }

        $tag = Tag::create([
            'workspace_id' => $workspaceId,
            'slug' => $slug,
            'name' => $data['name'],
            'color' => $data['color'] ?? 'slate',
            'category' => $data['category'] ?? 'custom',
            'kind' => 'manual',
            'description' => $data['description'] ?? null,
            'rules' => [],
        ]);

        return $this->ok(['data' => new TagResource($tag)], 201);
    }

    /**
     * @OA\Put(path="/tags/{tag}", tags={"Tags"}, summary="Update tag",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function update(Request $r, Tag $tag): JsonResponse
    {
        // Constat B12-001 : `if ($workspaceId && ...)` ne refusait rien quand le
        // contexte d'espace manquait, et rendait un 404 dans une enveloppe 200
        // (`$this->ok(..., 404)` positionne bien le code HTTP, mais le corps
        // annonce une reussite). La garde durcie de `ApiController` refuse
        // franchement -- et refuse aussi quand elle ne sait pas.
        $this->refuserHorsEspace($tag);
        // Garde-fou : on ne modifie pas les tags auto/llm (générés par le système)
        if ($tag->kind !== 'manual') {
            return $this->ok(['error' => 'cannot update auto/llm tag'], 403);
        }

        // ── VERROU OPTIMISTE (G43-005) ───────────────────────────────────
        //
        // Deux personnes ouvrent la meme fiche, la modifient, enregistrent :
        // la seconde ecrasait la premiere, et les DEUX recevaient « succes ».
        // Rien ne le disait a personne. La saisie perdue ne laisse aucune trace.
        //
        // Le mecanisme n'est pas invente ici : `CompaniesController` le porte
        // depuis le lot G43-005, par le trait partage. Il reste OPTIONNEL —
        // sans en-tete `If-Match`, le comportement historique ne change pas, ce
        // qui evite de casser les clients existants. Mais le client qui l'envoie
        // est desormais protege ICI AUSSI.
        $this->refuserSiVersionPerimee($r, $tag);

        $data = $r->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'category' => ['sometimes', 'nullable', 'string', 'in:geo,sector,size,intent,custom,ia'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $tag->update($data);

        // ⚠️ L'EN-TETE `ETag` PORTE LE JETON DE L'ETAT D'APRES.
        //
        // Sans lui, aucun client ne peut obtenir de jeton, et le verrou pose
        // au-dessus serait du DECOR : `refuserSiVersionPerimee()` ne se declenche
        // que si le client annonce un etat, et il ne peut l'annoncer que s'il l'a
        // recu. Le CORPS de la reponse n'est pas modifie d'un octet.
        //
        // Ce controleur n'expose pas de `show()` : la reponse du PUT est le SEUL
        // endroit ou le jeton peut etre remis.
        // `refresh()` et non `fresh()` : `fresh()` peut rendre `null` (PHPStan le
        // signale a juste titre — la ligne peut avoir disparu entre l'ecriture et
        // la relecture), et il partait ici DEUX fois en base, une pour le corps
        // et une pour le jeton. `refresh()` recharge l'instance en place et rend
        // `$this` : un seul aller-retour, et un modele non nul.
        $tag->refresh();

        return $this->avecJetonDeVersion(
            $this->ok(['data' => new TagResource($tag)]),
            $tag,
        );
    }

    /**
     * @OA\Delete(path="/tags/{tag}", tags={"Tags"}, summary="Delete tag",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function destroy(Tag $tag): JsonResponse
    {
        // Constat B12-001 : `if ($workspaceId && ...)` ne refusait rien quand le
        // contexte d'espace manquait, et rendait un 404 dans une enveloppe 200
        // (`$this->ok(..., 404)` positionne bien le code HTTP, mais le corps
        // annonce une reussite). La garde durcie de `ApiController` refuse
        // franchement -- et refuse aussi quand elle ne sait pas.
        $this->refuserHorsEspace($tag);
        if ($tag->kind !== 'manual') {
            return $this->ok(['error' => 'cannot delete auto/llm tag (will be re-created by AutoTagger)'], 403);
        }
        $tag->delete();

        return $this->ok(['ok' => true]);
    }
}
