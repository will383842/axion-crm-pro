<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REGISTRE DES ENVOIS PRESSE — « à qui a-t-on envoyé quoi, et quand ».
 *
 * ── Pourquoi ce n'est PAS une table `communiques_envoyes` ─────────────────
 * Le besoin exprimé était « un onglet communiqués de presse envoyés ». La
 * tentation immédiate est de créer la table du même nom. Elle aurait été un
 * silo : le CRM consigne déjà TOUS les touchpoints dans `activities`
 * (workspace-scopée, vocabulaire fermé, `external_ref` idempotent), et un
 * envoi de communiqué est un touchpoint comme un autre. Deux stockages pour
 * un même geste, c'est deux vérités qui divergent au premier bug — et la
 * fiche média afficherait l'une pendant que l'onglet afficherait l'autre.
 *
 * Cet écran est donc une LECTURE de `activities`, filtrée sur les natures
 * presse. Il n'écrit rien. La conséquence directe, et c'est elle qui compte :
 * ce que Will voulait pour la presse vaut du même coup pour tous les contacts,
 * puisque c'est le même registre.
 *
 * ── Les deux populations ───────────────────────────────────────────────────
 * Un envoi vise soit une RÉDACTION (`subject_type = 'media'` — Le Mémorial se
 * joint à `redaction@…`, sans personne nommée), soit un JOURNALISTE
 * (`subject_type = 'journalist'`). Les deux comptent comme « envoyé au
 * Mémorial ». On résout donc le nom de la cible des deux côtés, et on rattache
 * le journaliste à sa rédaction quand elle est connue — sinon la ligne dirait
 * « envoyé à Untel » sans qu'on sache à quel titre il écrit.
 */
class PresseEnvoisController extends ApiController
{
    /**
     * Natures retenues par défaut.
     *
     * `press_release_sent` seul répondrait littéralement à « communiqués
     * envoyés », mais rendrait l'écran trompeur : une relance et une réponse
     * appartiennent à la même conversation. Un onglet qui montre l'envoi et
     * cache la réponse laisse croire qu'on n'a jamais eu de retour.
     *
     * @var list<string>
     */
    private const NATURES = [
        'press_release_sent',
        'press_followup',
        'press_reply',
        'press_coverage',
    ];

    public function index(Request $request): JsonResponse
    {
        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;

        // Défensif, comme le reste des contrôleurs de ce module : un écran de
        // suivi qui renvoie 500 parce qu'une table manque est pire qu'un écran
        // vide — il fait douter des données qu'il affiche ailleurs.
        if (! $workspaceId || ! Schema::hasTable('activities')) {
            return $this->ok([
                'data' => [],
                'meta' => ['total' => 0, 'per_page' => $perPage, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        $natures = self::NATURES;
        $kindDemande = (string) $request->query('kind', '');
        if ($kindDemande !== '' && in_array($kindDemande, self::NATURES, true)) {
            $natures = [$kindDemande];
        }

        $query = DB::table('activities')
            ->where('workspace_id', $workspaceId)
            ->whereIn('kind', $natures)
            ->whereIn('subject_type', ['media', 'journalist']);

        // Recherche sur le titre de l'échange. Le nom de la cible vit dans une
        // AUTRE table selon `subject_type` : le filtrer ici demanderait deux
        // jointures conditionnelles pour un gain faible à cette échelle.
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('title', 'ILIKE', '%' . $q . '%');
        }

        $total = (clone $query)->count();

        $lignes = $query
            ->orderByRaw('coalesce(occurred_at, created_at) DESC')
            ->forPage($page, $perPage)
            ->get(['id', 'kind', 'title', 'content', 'occurred_at', 'created_at', 'subject_type', 'subject_id'])
            ->all();

        $this->resoudreCibles($lignes);

        return $this->ok([
            'data' => $lignes,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    /**
     * Attache à chaque ligne le nom de sa cible, et la rédaction derrière elle.
     *
     * Deux requêtes groupées plutôt qu'une par ligne : à 50 lignes par page,
     * la version naïve ferait 100 allers-retours pour afficher un tableau.
     *
     * Une cible peut avoir disparu (fiche supprimée après l'envoi). On écrit
     * alors « fiche supprimée » plutôt que de masquer la ligne : l'envoi a bien
     * eu lieu, et un registre qui efface son passé ne sert plus de registre.
     *
     * @param  array<int, object>  $lignes
     */
    private function resoudreCibles(array $lignes): void
    {
        $idsMedia = [];
        $idsJournaliste = [];
        foreach ($lignes as $l) {
            if ($l->subject_type === 'media') {
                $idsMedia[] = $l->subject_id;
            } elseif ($l->subject_type === 'journalist') {
                $idsJournaliste[] = $l->subject_id;
            }
        }

        $medias = [];
        if ($idsMedia !== [] && Schema::hasTable('media')) {
            foreach (DB::table('media')->whereIn('id', $idsMedia)->get(['id', 'name']) as $m) {
                $medias[(string) $m->id] = $m->name;
            }
        }

        $journalistes = [];
        if ($idsJournaliste !== [] && Schema::hasTable('journalists')) {
            // Pas de `full_name` : la colonne n'existe pas (mesuré en base le
            // 2026-08-25) et le modèle n'a pas d'accesseur. Le nom se compose.
            $colonnes = ['id', 'first_name', 'last_name', 'media_id'];
            foreach (DB::table('journalists')->whereIn('id', $idsJournaliste)->get($colonnes) as $j) {
                $journalistes[(string) $j->id] = $j;
            }

            // La rédaction du journaliste, quand le rattachement a été fait.
            $idsRedaction = array_values(array_filter(array_map(
                static fn (object $j) => $j->media_id,
                $journalistes,
            )));
            if ($idsRedaction !== [] && Schema::hasTable('media')) {
                foreach (DB::table('media')->whereIn('id', $idsRedaction)->get(['id', 'name']) as $m) {
                    $medias[(string) $m->id] = $m->name;
                }
            }
        }

        foreach ($lignes as $l) {
            if ($l->subject_type === 'media') {
                $l->cible = $medias[(string) $l->subject_id] ?? 'fiche supprimée';
                $l->cible_type = 'redaction';
                $l->redaction = $l->cible;

                continue;
            }

            $j = $journalistes[(string) $l->subject_id] ?? null;
            $l->cible = $j !== null
                ? (trim(((string) $j->first_name) . ' ' . ((string) $j->last_name))
                    ?: ('journaliste #' . $l->subject_id))
                : 'fiche supprimée';
            $l->cible_type = 'journaliste';
            // null explicite, et non chaîne vide : l'écran doit pouvoir dire
            // « rédaction à rattacher », ce qui est un arbitrage en attente, et
            // non une rédaction dont le nom serait vide.
            $l->redaction = ($j !== null && $j->media_id !== null)
                ? ($medias[(string) $j->media_id] ?? null)
                : null;
        }
    }
}
