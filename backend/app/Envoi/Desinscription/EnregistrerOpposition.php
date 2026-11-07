<?php

namespace App\Envoi\Desinscription;

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Enregistre l'opposition liée à une inscription. IDEMPOTENT : la contrainte
 * UNIQUE (inscription) fait qu'un rejeu ne crée ni seconde opposition, ni second
 * comptage dans la liste de suppression, ni second événement sortant.
 *
 * L'opposition MET À L'ÉCART, elle n'efface rien (règle S8). Elle est acquise
 * dès que la transaction est validée ; les effets aval (file, arrêt par
 * entreprise) sont rejoués par un rejeu du jeton tant qu'ils ne sont pas
 * terminés.
 */
final class EnregistrerOpposition
{
    public const CANAL_PAGE = 'page';

    public const CANAL_ONE_CLICK = 'one_click';

    /** @return bool false si la référence est inconnue de cet espace */
    public function executer(string $workspaceId, string $reference, string $canal): bool
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $reference, $canal): bool {
            $inscription = DB::table('envoi_inscriptions')->where('reference', $reference)->first();
            if ($inscription === null) {
                return false;
            }

            DB::transaction(function () use ($inscription, $workspaceId, $canal): void {
                $inseree = DB::affectingStatement(
                    'INSERT INTO envoi_oppositions (workspace_id, inscription_id, empreinte_adresse, canal)
                     VALUES (?, ?, ?, ?) ON CONFLICT (inscription_id) DO NOTHING',
                    [$workspaceId, $inscription->id, $inscription->empreinte_adresse, $canal],
                );
                if ($inseree !== 1) {
                    return;
                }
                $adresse = (string) $inscription->adresse;
                ListeSuppression::inscrire($adresse, ListeSuppression::MANUEL, 'desinscription_lien', (string) $inscription->portee, ['canal' => $canal]);
                (new ConsentOutboundRecorder)->recordForEmail(
                    'consent_optout',
                    $adresse,
                    (string) $inscription->portee,
                    null,
                    ['motif' => 'desinscription_lien', 'canal' => $canal],
                );
            });

            $this->terminerLesEffets($inscription, $workspaceId);

            return true;
        });
    }

    private function terminerLesEffets(\stdClass $inscription, string $workspaceId): void
    {
        $opposition = DB::table('envoi_oppositions')->where('inscription_id', $inscription->id)->first();
        if ($opposition === null || $opposition->effets_termines_le !== null) {
            return;
        }
        try {
            event(new OppositionEnregistree($workspaceId, (string) $inscription->empreinte_adresse, (string) $inscription->portee, (string) $opposition->canal));
            DB::table('envoi_oppositions')->where('id', $opposition->id)->update(['effets_termines_le' => now()]);
        } catch (Throwable $e) {
            // L'opposition est acquise ; les effets seront rejoués au prochain rejeu du jeton.
            report($e);
        }
    }
}
