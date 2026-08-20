<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Registre AI Act (reglement UE 2024/1689) — les systemes d'IA deployes, leur
 * finalite, leur classe de risque et leur analyse d'impact.
 *
 * 🔴 CONSTAT B16-007 (S1) — mesure le 2026-08-20 sur ce depot.
 *
 * Ce controleur tenait en deux lignes :
 *
 *     public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
 *     public function store(Request $r): JsonResponse { return $this->notImplemented('11'); }
 *
 * Or la table `ai_act_register` EXISTE : migration `2026_05_16_000006`, policy
 * RLS `ai_act_register_workspace_isolation`, trigger `updated_at`, et un
 * `AiActRegisterSeeder` qui y depose l'entree « LLM Router ». La route rendait
 * `200 {"data":[]}` quoi que la table contienne.
 *
 * Pourquoi c'est un S1 et pas une simple lacune de fonctionnalite : un `200`
 * avec une liste vide n'est PAS lisible comme « pas encore implemente ». Il se
 * lit « nous tenons un registre, et il ne contient rien » — c'est-a-dire la
 * seule reponse qu'un controle de conformite ne pourrait pas distinguer d'un
 * registre reellement tenu. La route ne mentait pas par ce qu'elle disait,
 * mais par ce qu'elle avait l'air d'avoir regarde.
 *
 * Et le registre etait vide PAR CONSTRUCTION : l'ecriture repondait 501. Le
 * produit n'offrait aucun moyen d'y inscrire quoi que ce soit ; seul le seeder
 * pouvait le faire, et uniquement pour l'espace de slug `axion-ia`.
 */
class AiActRegisterController extends ApiController
{
    /**
     * Les seules classes de risque admises par l'AI Act — et par la contrainte
     * CHECK de la table. Ecrites ici pour que la route TRANCHE elle-meme : sans
     * cette validation, une valeur inventee remonterait jusqu'a Postgres et
     * ressortirait en 500, ce qui apprend a l'appelant qu'il a casse le serveur
     * plutot qu'il s'est trompe de mot.
     *
     * @var list<string>
     */
    public const CLASSES_DE_RISQUE = ['prohibited', 'high', 'limited', 'minimal'];

    /**
     * @OA\Get(path="/ai-act/register", tags={"RGPD"}, summary="Registre AI Act (art. 9-15) — systèmes IA déployés",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="Liste des entrées"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('ai_act_register')) {
            return $this->ok(['data' => []]);
        }

        // Cloisonnement pose ICI, explicitement. Le registre nomme des
        // fournisseurs, des modeles et des analyses d'impact : c'est de la
        // documentation interne, pas un referentiel public. Meme raisonnement
        // que B16-004 sur le journal d'audit — et meme conclusion : sans
        // contexte d'espace, on ne rend RIEN plutot que tout.
        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            return $this->ok(['data' => []]);
        }

        try {
            $lignes = DB::table('ai_act_register')
                ->where('workspace_id', $espace)
                ->orderBy('id')
                ->get()
                ->map(function (object $ligne): array {
                    $tableau = (array) $ligne;

                    // `impact_assessment` est un JSONB : le pilote le restitue
                    // en CHAINE. Le rendre tel quel obligerait chaque lecteur a
                    // le decoder une seconde fois — et une analyse d'impact
                    // illisible est une analyse d'impact non consultee.
                    $tableau['impact_assessment'] = json_decode(
                        (string) ($tableau['impact_assessment'] ?? '{}'),
                        true
                    ) ?: [];

                    return $tableau;
                })
                ->all();

            return $this->ok(['data' => $lignes]);
        } catch (\Throwable $e) {
            Log::error('ai-act.register.index failed', ['exception' => $e->getMessage()]);
            report($e);

            // `degraded` : on distingue explicitement « rien a montrer » de
            // « je n'ai pas pu regarder ». C'est precisement la distinction que
            // l'ancienne version effacait.
            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/ai-act/register", tags={"RGPD"}, summary="Inscrit un système IA au registre AI Act",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=201, description="Entrée créée"),
     *     @OA\Response(response=403, description="Droit rgpd.handle requis"),
     *     @OA\Response(response=422, description="Classe de risque hors AI Act"))
     */
    public function store(Request $r): JsonResponse
    {
        // ⚠️ Le droit est verifie ICI, dans le controleur, et non par un
        // middleware `permission:` sur la route : `routes/api.php` est hors du
        // perimetre de ce lot (edition concurrente par d'autres agents). Un
        // middleware serait preferable — il refuserait avant d'atteindre le
        // controleur — et reste a poser. En attendant, mieux vaut ce controle
        // ici que pas de controle du tout : tenir le registre AI Act est le
        // meme geste de conformite que traiter une demande RGPD, donc le meme
        // droit (`rgpd.handle`, porte par owner et admin, PAS par viewer).
        $utilisateur = $r->user();
        if ($utilisateur === null || ! $utilisateur->can('rgpd.handle')) {
            abort(403);
        }

        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            abort(403);
        }

        $valide = $r->validate([
            'system_name' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:5000'],
            'risk_class' => ['required', Rule::in(self::CLASSES_DE_RISQUE)],
            'provider' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'dpia_url' => ['nullable', 'string', 'max:2048'],
            'impact_assessment' => ['nullable', 'array'],
        ]);

        // 🔑 L'espace est IMPOSE, jamais lu dans le corps. Un `workspace_id`
        // accepte depuis la requete permettrait d'ecrire dans le registre du
        // voisin — et un registre de conformite falsifiable par un tiers ne
        // vaut rien. `$r->validate()` ne rend que les cles validees, donc un
        // `workspace_id` envoye par l'appelant est deja tombe ; on l'ecrase
        // tout de meme explicitement, pour que la lecture du code n'oblige pas
        // a connaitre ce detail.
        $id = DB::table('ai_act_register')->insertGetId([
            'workspace_id' => $espace,
            'system_name' => $valide['system_name'],
            'purpose' => $valide['purpose'],
            'risk_class' => $valide['risk_class'],
            'provider' => $valide['provider'] ?? null,
            'model' => $valide['model'] ?? null,
            'dpia_url' => $valide['dpia_url'] ?? null,
            'impact_assessment' => json_encode($valide['impact_assessment'] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->ok(['data' => ['id' => $id]], 201);
    }
}
