<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkspaceController extends ApiController
{
    use VerrouOptimiste;

    /**
     * @OA\Get(path="/workspace", tags={"Workspace"}, summary="Workspace courant (settings + cost_cap)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function show(Request $r): JsonResponse
    {
        // Sprint 18.9 — defensive : si la relation currentWorkspace lance (FK manquante,
        // workspace soft-deleted, etc.) on retourne null plutôt que 500.
        try {
            $user = $r->user();
            if (! $user) {
                return $this->ok(null);
            }

            return $this->ok($user->currentWorkspace);
        } catch (\Throwable $e) {
            Log::error('workspace.show failed', [
                'user_id' => optional($r->user())->id,
                'exception' => $e->getMessage(),
            ]);
            report($e);

            return $this->ok(null);
        }
    }

    /**
     * 🔑 TROIS CHAMPS MODIFIABLES, ET DEUX REFUS ECRITS.
     *
     * `name`, `settings` et `cost_cap_eur` se reglent depuis la console. Deux
     * colonnes de la table sont volontairement HORS de portee de cette route,
     * et il faut dire pourquoi plutot que de les omettre en silence :
     *
     *   `slug`      il est `CITEXT NOT NULL UNIQUE` et sert d'adresse. Le
     *               changer depuis un ecran casserait, sans bruit, toute
     *               reference exterieure qui le porte.
     *   `is_active` un espace se desactive depuis l'ADMINISTRATION, jamais
     *               depuis lui-meme. Se couper le courant de l'interieur
     *               enferme tous ses membres dehors, sans recours par le
     *               produit.
     *
     * Ces deux-la ne sont pas « oublies » : `$r->validate()` ne rend que les
     * cles qu'il a validees, donc une requete qui les envoie les voit tomber.
     * La garde `EcrituresQuiRepondaient501Test` le mesure explicitement — et
     * verifie AUSSI, dans le meme appel, qu'un champ legitime passe : sans quoi
     * elle serait verte sur une route qui refuse tout.
     *
     * 🔑 L'ESPACE VISE VIENT DU CONTEXTE, JAMAIS DU CORPS. Meme raisonnement que
     * `AiActRegisterController::store` : accepter un `workspace_id` depuis la
     * requete permettrait de regler l'espace du voisin — y compris son plafond
     * de depense.
     *
     * @OA\Put(path="/workspace", tags={"Workspace"}, summary="Règle l'espace courant (nom, réglages, plafond)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Aucun espace courant"),
     *     @OA\Response(response=422, description="Champs invalides"))
     */
    public function update(Request $r): JsonResponse
    {
        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            abort(404);
        }

        $courant = Workspace::query()->whereKey($espace)->whereNull('deleted_at')->first();
        if ($courant === null) {
            abort(404);
        }

        // 🔑 G43-005 — VERROU OPTIMISTE. La garde `VerrouOptimisteEtenduTest`
        // avait ANTICIPE ce moment : « un update() qui rend 501 n'ecrit rien :
        // il n'y a pas de saisie a perdre. Le jour ou il est cable, il
        // apparaitra ici. » Il l'a fait, le 2026-08-23, et sa liste de
        // derogations est vide A DESSEIN — « ce n'est pas une derogation, c'est
        // le registre de ce qui reste a faire ».
        //
        // Sans ce controle, deux saisies concurrentes perdent du travail EN
        // SILENCE : la seconde ecrase la premiere sans que personne l'apprenne.
        $this->refuserSiVersionPerimee($r, $courant);

        $valide = $r->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'settings' => ['sometimes', 'array'],
            // Le plafond est un `NUMERIC(10,2)` : au-dela de 99 999 999,99 la
            // base leve « numeric field overflow », donc un 500 sur une saisie.
            // On borne ici pour que la reponse soit un 422 qui nomme le champ.
            'cost_cap_eur' => ['sometimes', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $champs = [];
        if (array_key_exists('name', $valide)) {
            $champs['name'] = $valide['name'];
        }
        if (array_key_exists('settings', $valide)) {
            // Remplacement, pas fusion — et c'est delibere. Une fusion rendrait
            // impossible le retrait d'un reglage : il n'y aurait aucun moyen
            // d'exprimer « enleve cette cle ». Un PUT remplace.
            $champs['settings'] = json_encode($valide['settings'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('cost_cap_eur', $valide)) {
            $champs['cost_cap_eur'] = $valide['cost_cap_eur'];
        }

        // 🔑 `whereNull('deleted_at')` SUR LES DEUX REQUETES, ET CE N'EST PAS
        // COSMETIQUE. `workspaces` porte `deleted_at` : un espace archive doit
        // etre INTROUVABLE, pas seulement invisible. Sans ce filtre, un compte
        // dont le contexte pointe encore un espace ferme continuerait a en
        // regler le nom et le plafond de depense.
        //
        // C'est la garde `B10-016-PORTEE` qui l'a trouve — elle recense les
        // appels « aveugles au deleted_at » et son plafond `workspaces` est
        // passe de 17 a 18. Le piege n. 8 du dossier dit de ne JAMAIS relever
        // un plafond pour accommoder son propre code ; en refusant, on trouve
        // la vraie reponse. C'etait le cas ici.
        if ($champs !== []) {
            $champs['updated_at'] = now();
            DB::table('workspaces')
                ->where('id', $espace)
                ->whereNull('deleted_at')
                ->update($champs);
        }

        $ligne = DB::table('workspaces')
            ->where('id', $espace)
            ->whereNull('deleted_at')
            ->first(['id', 'slug', 'name', 'settings', 'cost_cap_eur', 'is_active', 'updated_at']);

        if ($ligne === null) {
            abort(404);
        }

        $sortie = (array) $ligne;
        // `settings` est un JSONB que le pilote restitue en CHAINE — meme
        // traitement que `filters` dans `SavedViewsController` et
        // `impact_assessment` dans `AiActRegisterController`. Les trois doivent
        // s'accorder, sinon l'ecran decode a un endroit sur trois.
        $sortie['settings'] = json_decode((string) ($sortie['settings'] ?? '{}'), true) ?: [];
        $sortie['is_active'] = (bool) $sortie['is_active'];

        return $this->ok(['data' => $sortie]);
    }
}
