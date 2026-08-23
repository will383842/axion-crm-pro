<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ACTIONS DE MASSE sur les tags d'entreprises (plan §2.11).
 *
 * Poser une étiquette sur une sélection, c'est ce qui permet de constituer un
 * segment de campagne : « ces 40 fiches roumaines → tag `campagne-ro-sept` ».
 * Sans cela, il faut ouvrir 40 fiches.
 *
 * ── Trois refus DÉLIBÉRÉS ────────────────────────────────────────────────
 *
 * 1. **Les tags VERROUILLÉS sont interdits.** Les `src:*` disent d'où vient
 *    une fiche : c'est un FAIT, constaté par la collecte ou par l'ingestion.
 *    Laisser un humain en poser un en masse, c'est laisser fabriquer une
 *    provenance — et toute la traçabilité RGPD repose dessus.
 *
 * 2. **Pas de création de tag à la volée.** Le tag doit exister dans le
 *    workspace. Une faute de frappe créerait `campagne-ro-setp` sans rien
 *    dire, et le segment serait introuvable le lendemain.
 *
 * 3. **Sélection EXPLICITE et bornée.** On agit sur des identifiants fournis,
 *    jamais sur « tout ce qui correspond au filtre » : sur 4,29 M de fiches,
 *    une case cochée par mégarde deviendrait irréversible. Le plafond de 500
 *    tient dans une requête et reste annulable à la main.
 */
class CompanyTagsBulkController extends ApiController
{
    /**
     * Plafond par appel. Assez pour une page de sélection, assez peu pour
     * qu'une erreur reste rattrapable.
     */
    private const MAX_FICHES = 500;

    public function __invoke(Request $request): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;

        if ($workspaceId === null) {
            return $this->ok([
                'error' => 'workspace_absent',
                'message' => "Aucun univers courant : l'action de masse est refusée.",
            ], 422);
        }

        $valide = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_FICHES],
            'ids.*' => ['integer'],
            'tag' => ['required', 'string', 'max:120'],
            'action' => ['required', 'string', 'in:add,remove'],
        ]);

        // Colonnes EXPLICITES : `first()` rend un objet non typé, et
        // l'analyse statique ne peut rien garantir de ses propriétés. On lit ce
        // qu'on utilise, et rien d'autre.
        $tag = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('slug', $valide['tag'])
            ->first(['id', 'is_locked']);

        if ($tag === null) {
            // On ne crée PAS : un tag inventé par une faute de frappe rendrait
            // le segment introuvable, sans le moindre message.
            return $this->ok([
                'error' => 'tag_inconnu',
                'message' => "Ce tag n'existe pas dans cet univers. Créez-le d'abord.",
            ], 422);
        }

        $tagId = (int) $tag->id;

        if ((bool) $tag->is_locked) {
            return $this->ok([
                'error' => 'tag_verrouille',
                'message' => "Ce tag est verrouillé : il décrit une PROVENANCE constatée, pas une étiquette qu'on pose.",
            ], 422);
        }

        // Scope EXPLICITE : on ne touche que des fiches de cet univers, même si
        // des identifiants d'ailleurs sont glissés dans la requête.
        $idsAutorises = array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            DB::table('companies')
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->whereIn('id', $valide['ids'])
                ->pluck('id')
                ->all(),
        ));

        $ignorees = count($valide['ids']) - count($idsAutorises);

        if ($idsAutorises === []) {
            return $this->ok(['modifiees' => 0, 'ignorees' => $ignorees, 'action' => $valide['action']]);
        }

        $modifiees = $valide['action'] === 'add'
            ? $this->poser($idsAutorises, $tagId, (string) $workspaceId)
            : $this->retirer($idsAutorises, $tagId);

        return $this->ok([
            'modifiees' => $modifiees,
            // Le nombre d'ignorées est RENDU, pas tu : une action de masse qui
            // annonce « fait » alors qu'elle a écarté la moitié des lignes est
            // pire qu'une erreur franche.
            'ignorees' => $ignorees,
            'action' => $valide['action'],
        ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function poser(array $ids, int $tagId, string $workspaceId): int
    {
        $lignes = array_map(static fn (int $id): array => [
            'company_id' => $id,
            'tag_id' => $tagId,
            // 🔴 `workspace_id` — CONSTAT `G43-004`, ET IL EST INVISIBLE EN LOCAL.
            //
            // Cette colonne manquait. En local, sans RLS, l'insertion PASSAIT :
            // la ligne existait, non cloisonnée, et rien ne le disait. Sous la
            // RLS de production, la même insertion est REFUSEE —
            // `SQLSTATE[42501] new row violates row-level security policy`.
            // L'action de masse aurait donc echoue le jour ou `CRM_DB_APP_ROLE_ENABLED`
            // serait arme, sans que le moindre test local ne l'annonce.
            //
            // Les deux autres sites d'ecriture de ce pivot posent deja la
            // colonne (`ScrapingBackfillSrcTags:167-168`) : c'etait une
            // omission, pas un choix.
            //
            // ⚠️ La valeur est SURE, et pas seulement plausible : `$idsAutorises`
            // a ete filtre plus haut par `where('workspace_id', $workspaceId)`.
            // Chaque fiche taggee appartient donc bien a cet univers. Ecrire ici
            // l'espace de la REQUETE sur une fiche d'ailleurs serait pire que de
            // ne rien ecrire — cela fabriquerait une fuite entre univers.
            'workspace_id' => $workspaceId,
            // `assigned_at` n'est pas ecrit : la colonne porte `DEFAULT now()`
            // (migration `2026_05_18_000007`), qui est exactement la bonne
            // valeur. L'ecrire a la main n'ajouterait qu'une occasion de se
            // tromper d'horloge.
            //
            // `user` : le vocabulaire fermé de `assigned_by` distingue ce qu'un
            // humain a posé de ce qu'une règle a déduit. Les confondre ferait
            // passer une décision pour une observation.
            'assigned_by' => 'user',
        ], $ids);

        // `insertOrIgnore` : reposer un tag déjà présent n'est pas une erreur,
        // c'est une non-action. La clé primaire (company_id, tag_id) garantit
        // l'unicité.
        return DB::table('company_tag')->insertOrIgnore($lignes);
    }

    /**
     * @param  list<int>  $ids
     */
    private function retirer(array $ids, int $tagId): int
    {
        return DB::table('company_tag')
            ->where('tag_id', $tagId)
            ->whereIn('company_id', $ids)
            ->delete();
    }
}
