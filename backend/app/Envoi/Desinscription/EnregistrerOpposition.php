<?php

namespace App\Envoi\Desinscription;

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre l'opposition liée à une inscription. IDEMPOTENT : la contrainte
 * UNIQUE (lien) fait qu'un rejeu ne crée ni seconde opposition, ni second
 * comptage dans la liste de suppression, ni second événement sortant.
 *
 * L'opposition MET À L'ÉCART, elle n'efface rien (règle S8). Elle est acquise
 * dès que la transaction est validée ; les effets aval (file, arrêt par
 * entreprise) sont rejoués par un rejeu du jeton tant qu'ils ne sont pas
 * terminés.
 */
final class EnregistrerOpposition
{
    public function __construct(private readonly TerminerEffetsOpposition $effets) {}

    public const CANAL_PAGE = 'page';

    public const CANAL_ONE_CLICK = 'one_click';

    /** @return bool false si la référence est inconnue de cet espace */
    public function executer(string $workspaceId, string $reference, string $canal): bool
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $reference, $canal): bool {
            $inscription = DB::table('envoi_liens_desinscription')->where('reference', $reference)->first();
            if ($inscription === null) {
                return false;
            }

            DB::transaction(function () use ($inscription, $workspaceId, $canal): void {
                $inseree = DB::affectingStatement(
                    'INSERT INTO envoi_oppositions (workspace_id, lien_id, empreinte_adresse, canal)
                     VALUES (?, ?, ?, ?) ON CONFLICT (lien_id) DO NOTHING',
                    [$workspaceId, $inscription->id, $inscription->empreinte_adresse, $canal],
                );
                if ($inseree !== 1) {
                    return;
                }
                // Jamais l'adresse en clair : l'empreinte seule (décision du 2026-08-18).
                $empreinte = (string) $inscription->empreinte_adresse;
                ListeSuppression::inscrireEmpreinte($empreinte, ListeSuppression::MANUEL, 'desinscription_lien', (string) $inscription->portee, ['canal' => $canal]);
                (new ConsentOutboundRecorder)->record(
                    'consent_optout',
                    $empreinte,
                    (string) $inscription->portee,
                    null,
                    ['motif' => 'desinscription_lien', 'canal' => $canal],
                );
            });

            $opposition = DB::table('envoi_oppositions')->where('lien_id', $inscription->id)->first();
            if ($opposition !== null) {
                $this->effets->pour($workspaceId, (int) $opposition->id);
            }

            return true;
        });
    }
}
