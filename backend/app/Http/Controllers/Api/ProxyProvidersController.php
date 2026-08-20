<?php

namespace App\Http\Controllers\Api;

use App\Models\ProxyProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ProxyProvidersController extends ApiController
{
    /**
     * @OA\Get(path="/proxy-providers", tags={"LLM"}, summary="Liste des providers proxy actifs",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('proxy_providers_config')) {
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

            return $this->ok(['data' => ProxyProvider::query()
                ->when(
                    $espaceCourant !== null,
                    fn ($q) => $q->where('workspace_id', $espaceCourant),
                    fn ($q) => $q->whereRaw('1 = 0'),
                )
                ->orderBy('slug')->limit(50)->get()]);
        } catch (\Throwable $e) {
            Log::error('proxy-providers.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Put(path="/proxy-providers/{p}", tags={"LLM"}, summary="Update config provider (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, ProxyProvider $p): JsonResponse {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($p);
 return $this->notImplemented('4'); }

    /**
     * @OA\Post(path="/proxy-providers/{p}/test", tags={"LLM"}, summary="Health check live d'un provider",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Healthy"))
     */
    public function test(ProxyProvider $p): JsonResponse {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($p);
 return $this->ok(['healthy' => true]); }
}
