<?php

namespace App\Http\Controllers\Api;

use App\Models\ProxyProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ProxyProvidersController extends ApiController
{
    /**
     * @OA\Get(path="/proxy-providers", tags={"LLM"}, summary="Liste des providers proxy actifs",
     *     security={{"sanctumCookie":{}}},
     *
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
     *
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=501, description="Not implemented"))
     */
    /**
     * 🔑 `slug` N'EST PAS MODIFIABLE : c'est par lui que le code resout un
     * fournisseur, et le docblock de `test()` juste en dessous explique
     * precisement pourquoi cette resolution est deja fragile. Le renommer
     * depuis un ecran acheverait de la casser.
     *
     * @OA\Put(path="/proxy-providers/{p}", tags={"LLM"}, summary="Règle un mandataire de l'espace courant",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Inconnu, ou hors de mon espace"),
     *     @OA\Response(response=422, description="Type ou zone invalide"))
     */
    public function update(Request $r, ProxyProvider $p): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($p);

        $valide = $r->validate([
            // La colonne porte CHECK (residential, datacenter, mobile) : une
            // valeur inventee doit tomber ICI, pas dans Postgres — sinon
            // l'appelant apprend qu'il a casse le serveur au lieu qu'il s'est
            // trompe de mot.
            'type' => ['sometimes', Rule::in(['residential', 'datacenter', 'mobile'])],
            'zone' => ['sometimes', 'string', 'max:32'],
            'enabled' => ['sometimes', 'boolean'],
            // Un poids negatif n'a pas de sens dans un tirage pondere, et un
            // poids demesure revient a desactiver tous les autres.
            'weight' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $champs = [];
        foreach (['type', 'zone', 'enabled', 'weight'] as $clef) {
            if (array_key_exists($clef, $valide)) {
                $champs[$clef] = $valide[$clef];
            }
        }
        if (array_key_exists('metadata', $valide)) {
            $champs['metadata'] = json_encode($valide['metadata'], JSON_UNESCAPED_UNICODE);
        }

        if ($champs !== []) {
            $champs['updated_at'] = now();
            DB::table('proxy_providers_config')->where('id', $p->getKey())->update($champs);
        }

        return $this->ok(['data' => ProxyProvider::query()->findOrFail($p->getKey())]);
    }

    /**
     * 🔴 CONSTAT C19-008 (S1) — UN CONTROLE DE SANTE QUI DISAIT TOUJOURS
     * « EN BONNE SANTE ».
     *
     * Cette methode s'ecrivait :
     *
     *     public function test(ProxyProvider $p): JsonResponse { return $this->ok(['healthy' => true]); }
     *
     * ...tandis que sa documentation OpenAPI annoncait « Health check live d'un
     * provider ». Identifiants absents, quota epuise, fournisseur injoignable :
     * tout repondait « sain ». `ProxyProvidersPage.tsx` traduit fidelement ce
     * mensonge en « Operationnel ✓ » dans un bandeau vert.
     *
     * *Le seul bouton de diagnostic du sous-systeme mandataire etait celui
     * auquel on ne pouvait pas se fier — et il ne rougissait jamais, y compris
     * le jour ou le mandataire EST la cause de la panne.*
     *
     * ─────────────────────────────────────────────────────────────────────────
     * POURQUOI 501 ET PAS UN VRAI CONTROLE — LA MESURE, PUIS LA DECISION
     * ─────────────────────────────────────────────────────────────────────────
     *
     * Deux `healthCheck()` REELS existent bien (`WebshareProvider:67`,
     * `IPRoyalProvider:53` : requete via le mandataire vers `api.ipify.org`), et
     * aucun controleur ne les appelle. La tentation evidente est de brancher.
     *
     * ⚠️ **Le brancher aujourd'hui reproduirait exactement le meme mensonge, une
     * couche plus bas.** Deux mesures le disent :
     *
     *  1. `MockServicesProvider:106` lie `App\Contracts\ProxyProvider` a
     *     `WebshareProvider` OU `MockProxyProvider` selon `MOCK_PROXIES` — et le
     *     defaut de ce depot est `MOCK_PROXIES=true`. Or
     *     `MockProxyProvider::healthCheck()` s'ecrit `return true;`. On aurait
     *     donc remplace un `true` en dur dans le controleur par un `true` en dur
     *     dans le simulacre : meme reponse, mensonge mieux cache.
     *
     *  2. Le conteneur ne lie QU'UN SEUL fournisseur. La route, elle, recoit un
     *     `{p}` — une LIGNE de `proxy_providers_config`. Tester la ligne
     *     « iproyal-eu » interrogerait Webshare. Resoudre le bon fournisseur
     *     depuis le `slug` de la ligne est un choix de conception (fabrique,
     *     table de correspondance, gestion du fournisseur inconnu) : cela change
     *     la semantique de la route et ne se prend pas au detour d'un correctif.
     *
     * `501` est donc l'etat HONNETE, et c'est aussi celui que l'audit prescrit
     * en premier ressort. C'est enfin ce que fait deja `update()` douze lignes
     * plus haut : le controleur savait dire « pas implemente » ; `test()` avait
     * ete ecrit pour dire « sain » a la place.
     *
     * ⚠️ CE QUI RESTE A FAIRE, ET QUI N'EST PAS DANS CE PERIMETRE.
     * `ProxyProvidersPage.tsx:47` traduit toute erreur par « Echec du test ».
     * Un `501` y affichera donc « Echec du test » plutot que « Diagnostic non
     * disponible ». C'est imprecis, mais ce n'est plus faux — et le frontend est
     * hors du perimetre de ce lot.
     *
     * Garde : `tests/Feature/Api/CorpsCodeEnDurTest.php`.
     *
     * @OA\Post(path="/proxy-providers/{p}/test", tags={"LLM"}, summary="Health check live d'un provider (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="p", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function test(ProxyProvider $p): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($p);

        return $this->notImplemented('4');
    }
}
