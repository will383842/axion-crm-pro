<?php

namespace App\Envoi\Desinscription;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Termine les effets aval d'une opposition (retrait de la file, arrêt par
 * entreprise). SÉRIALISÉ par un verrou de ligne : deux appels simultanés ne
 * déclenchent qu'UN événement. Tant que `effets_termines_le` est NULL, la
 * commande planifiée `envoi:relancer-effets-opposition` rejoue — sans nouveau clic.
 */
final class TerminerEffetsOpposition
{
    /** @return bool true si cet appel a terminé les effets */
    public function pour(string $workspaceId, int $oppositionId): bool
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $oppositionId): bool {
            try {
                return DB::transaction(function () use ($workspaceId, $oppositionId): bool {
                    $o = DB::table('envoi_oppositions')->where('id', $oppositionId)->lockForUpdate()->first();
                    if ($o === null || $o->effets_termines_le !== null) {
                        return false;
                    }
                    $portee = (string) DB::table('envoi_liens_desinscription')->where('id', $o->lien_id)->value('portee');
                    event(new OppositionEnregistree($workspaceId, (string) $o->empreinte_adresse, $portee, (string) $o->canal));
                    DB::table('envoi_oppositions')->where('id', $o->id)->update(['effets_termines_le' => now()]);

                    return true;
                });
            } catch (Throwable $e) {
                // L'opposition est acquise ; la relance planifiée reprendra.
                report($e);

                return false;
            }
        });
    }
}
