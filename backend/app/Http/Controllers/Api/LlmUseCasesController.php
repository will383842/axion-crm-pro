<?php

namespace App\Http\Controllers\Api;

use App\Models\LlmUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class LlmUseCasesController extends ApiController
{
    /**
     * Plafond de versions de prompt rendues.
     *
     * Un cas d'usage accumule une version par edition : la table n'a pas de
     * purge. Une lecture non bornee y est une bombe a retardement (`G41-007`).
     */
    private const PLAFOND_VERSIONS = 100;

    /**
     * @OA\Get(path="/llm/use-cases", tags={"LLM"}, summary="Liste des 9 use cases LLM (router)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('llm_use_cases')) {
            return $this->ok(['data' => []]);
        }

        try {
            // 🔴 CONSTATS P6-API-001 / P6-API-002 (S0). Cette liste ne portait AUCUN
            // filtre d'espace de travail : elle rendait les lignes de TOUS les
            // clients a tout compte authentifie.
            //
            // Le correctif de cloisonnement du 2026-08-20 avait porte
            // `refuserHorsEspace()` sur 36 methodes UNITAIRES (show/update/destroy)
            // et sur AUCUNE liste -- parce que son controle de completude enumerait
            // les methodes qui recoivent un modele par RESOLUTION DE ROUTE, et
            // qu'un `index()` n'en recoit aucun. Les listes lui etaient
            // structurellement invisibles, et il etait vert.
            //
            // Une fuite par la LISTE est pire qu'une fuite par la fiche : la fiche
            // demande de deviner un identifiant, la liste les donne tous.
            //
            // Sans contexte d'espace, on ne rend RIEN : le doute se tranche en
            // faveur du silence. La garde est
            // `tests/Feature/Rgpd/CloisonnementDesListesTest.php`, et elle mesure
            // le CORPS de la reponse -- pas la presence d'un appel de methode.
            $espaceCourant = $this->espaceCourantOuNull();

            return $this->ok(['data' => LlmUseCase::query()
                ->when(
                    $espaceCourant !== null,
                    fn ($q) => $q->where('workspace_id', $espaceCourant),
                    fn ($q) => $q->whereRaw('1 = 0'),
                )
                ->orderBy('slug')->limit(50)->get()]);
        } catch (\Throwable $e) {
            Log::error('llm.use-cases.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Put(path="/llm/use-cases/{useCase}", tags={"LLM"}, summary="Update config use case (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="useCase", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, LlmUseCase $useCase): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403.
        $this->refuserHorsEspace($useCase);

        // 🔑 `slug` N'EST PAS MODIFIABLE, et c'est le point de ce reglage.
        // Le code appelle un cas d'usage PAR SON SLUG : le renommer depuis un
        // ecran ferait tomber, en silence et a l'execution, tout appelant qui
        // le nomme. Le nom d'appel n'est pas une etiquette d'affichage.
        //
        // `prompt_version` n'y est pas non plus : elle a sa propre route
        // (`updatePrompt`), qui verifie d'abord que la version existe.
        $valide = $r->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'primary_provider' => ['sometimes', 'string', 'max:64'],
            'model' => ['sometimes', 'string', 'max:128'],
            'fallback_chain' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
            // NUMERIC(10,4) : borne ici pour rendre 422 plutot que laisser
            // Postgres lever « numeric field overflow » en 500.
            'cost_cap_eur' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.9999'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $champs = [];
        foreach (['description', 'primary_provider', 'model', 'cost_cap_eur', 'enabled'] as $clef) {
            if (array_key_exists($clef, $valide)) {
                $champs[$clef] = $valide[$clef];
            }
        }
        foreach (['fallback_chain', 'options'] as $clef) {
            if (array_key_exists($clef, $valide)) {
                $champs[$clef] = json_encode($valide[$clef], JSON_UNESCAPED_UNICODE);
            }
        }

        if ($champs !== []) {
            $champs['updated_at'] = now();
            DB::table('llm_use_cases')->where('id', $useCase->getKey())->update($champs);
        }

        return $this->ok(['data' => LlmUseCase::query()->findOrFail($useCase->getKey())]);
    }

    /**
     * @OA\Get(path="/llm/use-cases/{useCase}/prompts", tags={"LLM"}, summary="Versions de prompt pour un use case",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="useCase", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function prompts(LlmUseCase $useCase): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($useCase);

        // 🔴 CONSTAT B12-007 (S1). Cette methode s'ecrivait :
        //
        //     return $this->ok(['versions' => []]);
        //
        // Un corps ECRIT DANS LE CODE, rendu avec un `200`. L'onglet « Prompts »
        // de `LlmRouterPage.tsx` annonce pourtant, en toutes lettres : « Les
        // templates prompts sont versionnes en DB (prompt_template_versions) ».
        // La phrase etait vraie de la BASE, et fausse de la ROUTE.
        //
        // ✅ Les deux tables EXISTENT (migration `2026_05_16_000004`) :
        // `prompt_templates (use_case_id, slug)` et
        // `prompt_template_versions (prompt_template_id, version, content,
        // changelog, created_by)`, sous `UNIQUE (prompt_template_id, version)`.
        //
        // ⚠️ Le cloisonnement se fait par le CAS D'USAGE, pas par une colonne
        // `workspace_id` : ni `prompt_templates` ni `prompt_template_versions`
        // n'en portent. C'est `refuserHorsEspace($useCase)` ci-dessus qui tient
        // la frontiere — un cas d'usage d'un autre espace rend 404 avant qu'on
        // arrive ici, donc aucune version d'un autre espace n'est joignable.
        // Si un jour ces tables gagnaient un `workspace_id`, ce serait la
        // ceinture ET les bretelles ; aujourd'hui c'est la seule ceinture, et
        // il faut le savoir.
        if (! Schema::hasTable('prompt_template_versions') || ! Schema::hasTable('prompt_templates')) {
            return $this->ok(['versions' => []]);
        }

        try {
            $versions = DB::table('prompt_template_versions AS v')
                ->join('prompt_templates AS t', 't.id', '=', 'v.prompt_template_id')
                ->where('t.use_case_id', $useCase->getKey())
                ->orderBy('t.slug')
                ->orderByDesc('v.version')
                ->limit(self::PLAFOND_VERSIONS)
                ->get([
                    'v.id AS id',
                    't.slug AS template_slug',
                    'v.version AS version',
                    'v.content AS content',
                    'v.changelog AS changelog',
                    'v.created_by AS created_by',
                    'v.created_at AS created_at',
                ])
                ->map(fn (object $l) => (array) $l)
                ->all();

            return $this->ok([
                'versions' => $versions,
                // La version ACTIVE est portee par le cas d'usage lui-meme
                // (`llm_use_cases.prompt_version`). Sans elle, l'ecran liste des
                // versions sans savoir laquelle tourne — ce qui est exactement
                // l'information qu'on vient chercher sur cette page.
                'active_version' => (int) ($useCase->prompt_version ?? 1),
            ]);
        } catch (\Throwable $e) {
            Log::error('llm.use-cases.prompts failed', [
                'use_case_id' => $useCase->getKey(),
                'exception' => $e->getMessage(),
            ]);
            report($e);

            return $this->ok(['versions' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Put(path="/llm/use-cases/{useCase}/prompts/{v}", tags={"LLM"}, summary="Update version prompt (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="useCase", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="v", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=501, description="Not implemented"))
     */
    /**
     * ACTIVER la version `{v}` du prompt de ce cas d'usage.
     *
     * 🔑 CETTE ROUTE ACTIVE, ELLE NE REECRIT PAS. Une version de prompt est
     * IMMUABLE : c'est tout l'interet de les versionner. `prompts()` rend deja
     * `active_version` depuis `llm_use_cases.prompt_version` — c'est donc cette
     * colonne, et elle seule, que le geste deplace.
     *
     * 🔴 ET ON VERIFIE QUE LA VERSION EXISTE AVANT DE L'ACTIVER. Poser un
     * numero qui ne correspond a aucune ligne ferait tourner le routeur sur un
     * prompt introuvable : la panne serait silencieuse, et a l'execution — donc
     * en production, sur un appel client, et pas ici.
     *
     * Le cloisonnement passe par le CAS D'USAGE : ni `prompt_templates` ni
     * `prompt_template_versions` ne portent de `workspace_id`. C'est
     * `refuserHorsEspace($useCase)` qui tient la frontiere, comme dans
     * `prompts()` — meme ceinture, et toujours pas de bretelles.
     *
     * @OA\Put(path="/llm/use-cases/{useCase}/prompts/{v}", tags={"LLM"}, summary="Active une version de prompt",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="useCase", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="v", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Cas d'usage hors de mon espace, ou version inexistante"))
     */
    public function updatePrompt(Request $r, LlmUseCase $useCase, int $v): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($useCase);

        $existe = DB::table('prompt_template_versions AS pv')
            ->join('prompt_templates AS t', 't.id', '=', 'pv.prompt_template_id')
            ->where('t.use_case_id', $useCase->getKey())
            ->where('pv.version', $v)
            ->exists();

        if (! $existe) {
            abort(404);
        }

        DB::table('llm_use_cases')
            ->where('id', $useCase->getKey())
            ->update(['prompt_version' => $v, 'updated_at' => now()]);

        return $this->ok(['data' => LlmUseCase::query()->findOrFail($useCase->getKey())]);
    }
}
