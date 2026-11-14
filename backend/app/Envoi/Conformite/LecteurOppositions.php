<?php

namespace App\Envoi\Conformite;

use App\Support\MasquageCoordonnees;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lecture SEULE du registre d'opposition d'un espace (lot R05) : `envoi_oppositions` + `envoi_liens_desinscription`.
 *
 * L'opposition ne garde qu'une EMPREINTE de l'adresse. L'adresse n'est retrouvée que par la fiche du CRM de l'espace
 * (contact dont l'adresse actuelle donne la même empreinte) ET si le compte peut voir les coordonnées ; sinon `null`
 * et une forme masquée. Ni empreinte, ni jeton, ni référence de lien ne sortent. Aucune écriture, aucune suppression.
 */
final class LecteurOppositions
{
    /** Canal d'enregistrement → source du contrat (page et en-tête « un clic » sont deux gestes du même lien). */
    public const SOURCE = 'lien';

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function page(string $workspaceId, ?string $recherche, int $page, int $parPage, bool $voirAdresses): array
    {
        $base = $this->base($workspaceId);
        $recherche = $recherche === null ? null : mb_strtolower(trim($recherche));
        if ($recherche !== null && $recherche !== '') {
            // Chercher par adresse sans le droit de la voir ferait de la recherche un oracle : aucun résultat.
            $voirAdresses
                ? $base->whereRaw('lower(c.email::text) LIKE ?', ['%' . addcslashes($recherche, '%_\\') . '%'])
                : $base->whereRaw('false');
        }
        $total = (clone $base)->count();
        $lignes = $base->selectRaw('o.id, o.canal, o.enregistree_le, l.id AS lien_id, c.email AS email_fiche')
            ->orderByDesc('o.enregistree_le')->orderByDesc('o.id')
            ->forPage($page, $parPage)->get();

        $data = [];
        foreach ($lignes as $l) {
            $email = $l->email_fiche === null ? null : (string) $l->email_fiche;
            $data[] = [
                'id' => (string) $l->id,
                'adresse' => $voirAdresses ? $email : null,
                'adresse_masquee' => $email === null
                    ? 'Adresse non conservée (désinscription n° ' . $l->id . ')'
                    : (string) MasquageCoordonnees::email($email),
                'canal' => (string) $l->canal,
                'survenue_le' => Carbon::parse((string) $l->enregistree_le)->toIso8601String(),
            ];
        }

        return ['data' => $data, 'total' => $total];
    }

    /** @return array{total: int, ce_mois: int, ce_mois_par_lien: int, domaines_entiers: int, organisations: int} */
    public function resume(string $workspaceId): array
    {
        $debutDuMois = Carbon::now()->startOfMonth();
        $total = DB::table('envoi_oppositions')->where('workspace_id', $workspaceId)->count();
        $ceMois = DB::table('envoi_oppositions')->where('workspace_id', $workspaceId)->where('enregistree_le', '>=', $debutDuMois)->count();

        // Aucune table ne porte de portée « organisation » ou « domaine » (pas de migration dans ce lot) : 0, pas une estimation.
        return ['total' => $total, 'ce_mois' => $ceMois, 'ce_mois_par_lien' => $ceMois, 'domaines_entiers' => 0, 'organisations' => 0];
    }

    private function base(string $workspaceId): Builder
    {
        return DB::table('envoi_oppositions as o')
            ->join('envoi_liens_desinscription as l', function ($j): void {
                $j->on('l.workspace_id', '=', 'o.workspace_id')->on('l.id', '=', 'o.lien_id');
            })
            // Fiche de CET espace dont l'adresse d'aujourd'hui a bien l'empreinte enregistrée (sinon l'adresse a changé : masquée).
            ->leftJoin('contacts as c', function ($j): void {
                $j->on('c.workspace_id', '=', 'l.workspace_id')
                    ->on('c.id', '=', 'l.contact_id')
                    ->whereNull('c.deleted_at')
                    ->whereRaw("encode(sha256(convert_to(lower(btrim(c.email::text)), 'UTF8')), 'hex') = l.empreinte_adresse");
            })
            ->where('o.workspace_id', $workspaceId);
    }
}
