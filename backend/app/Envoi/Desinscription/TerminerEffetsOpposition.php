<?php

namespace App\Envoi\Desinscription;

use App\Crm\Outbound\OppositionSortante;
use App\Support\WorkspaceContext;
use Closure;
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
    /** @var Closure(string, string, string, string): void émet l'événement sortant « consent_optout » */
    private readonly Closure $emettreEvenementSortant;

    /**
     * @param  Closure|null  $emettreEvenementSortant  (espace, empreinte, portée, canal) ; par défaut l'enregistreur CRM.
     *                                                 L'événement sortant est émis ICI (rejouable), pas dans la transaction d'opposition :
     *                                                 sa panne ne peut donc jamais annuler l'opposition.
     */
    public function __construct(?Closure $emettreEvenementSortant = null)
    {
        $this->emettreEvenementSortant = $emettreEvenementSortant ?? static function (string $espace, string $empreinte, string $portee, string $canal): void {
            // LÈVE en cas d'erreur (rejeu par `effets_termines_le`) ; ne part que si la ligne `opt_out` est née au CRM.
            (new OppositionSortante)->signaler($empreinte, $portee, 'desinscription_lien', $canal);
        };
    }

    /** @return bool true si cet appel a terminé les effets */
    public function pour(string $workspaceId, int $oppositionId): bool
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $oppositionId): bool {
            try {
                return DB::transaction(function () use ($workspaceId, $oppositionId): bool {
                    $o = DB::table('envoi_oppositions')->where('id', $oppositionId)->where('workspace_id', $workspaceId)->lockForUpdate()->first();
                    if ($o === null || $o->effets_termines_le !== null) {
                        return false;
                    }
                    $portee = (string) DB::table('envoi_liens_desinscription')->where('workspace_id', $workspaceId)->where('id', $o->lien_id)->value('portee');
                    ($this->emettreEvenementSortant)($workspaceId, (string) $o->empreinte_adresse, $portee, (string) $o->canal);
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
