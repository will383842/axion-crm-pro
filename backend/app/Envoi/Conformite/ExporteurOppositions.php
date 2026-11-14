<?php

namespace App\Envoi\Conformite;

use App\Support\CelluleCsv;
use App\Support\MasquageCoordonnees;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Export CSV du registre d'opposition d'UN espace (lot R05), écrit au fil de l'eau sur un flux. LECTURE SEULE.
 * L'adresse en clair n'est donnée que si la fiche du CRM de l'espace la porte encore ET si le compte peut voir les
 * coordonnées ; sinon la colonne reste vide. Jamais d'empreinte, de jeton ni de référence de lien.
 */
final class ExporteurOppositions
{
    public const ENTETE = ['Identifiant', 'Portée', 'Adresse', 'Adresse masquée', 'Motif', 'Source', 'Inscrite le'];

    /**
     * @param  resource  $flux
     * @return int nombre de lignes de données écrites
     */
    public function ecrire(string $workspaceId, $flux, bool $voirAdresses): int
    {
        fwrite($flux, "\xEF\xBB\xBF");
        fputcsv($flux, self::ENTETE);
        $n = 0;
        $requete = DB::table('envoi_oppositions as o')
            ->join('envoi_liens_desinscription as l', function ($j): void {
                $j->on('l.workspace_id', '=', 'o.workspace_id')->on('l.id', '=', 'o.lien_id');
            })
            ->leftJoin('contacts as c', function ($j): void {
                $j->on('c.workspace_id', '=', 'l.workspace_id')->on('c.id', '=', 'l.contact_id')->whereNull('c.deleted_at')
                    ->whereRaw("encode(sha256(convert_to(lower(btrim(c.email::text)), 'UTF8')), 'hex') = l.empreinte_adresse");
            })
            ->where('o.workspace_id', $workspaceId)
            ->selectRaw('o.id, o.canal, o.enregistree_le, c.email AS email_fiche');

        $requete->orderBy('o.id')->chunkById(500, function ($lot) use ($flux, $voirAdresses, &$n): void {
            foreach ($lot as $l) {
                $email = $l->email_fiche === null ? null : (string) $l->email_fiche;
                fputcsv($flux, CelluleCsv::ligne([
                    (string) $l->id,
                    'adresse',
                    $voirAdresses && $email !== null ? $email : '',
                    $email === null ? 'Adresse non conservée (désinscription n° ' . $l->id . ')' : (string) MasquageCoordonnees::email($email),
                    $l->canal === 'one_click' ? 'Désinscription en un clic depuis l’en-tête du message' : 'Désinscription depuis le lien du message',
                    'lien',
                    Carbon::parse((string) $l->enregistree_le)->toIso8601String(),
                ]));
                $n++;
            }
        }, 'o.id', 'id');

        return $n;
    }
}
