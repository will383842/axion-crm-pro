<?php

namespace App\Http\Controllers\Api\Crm;

use App\Crm\ProvenanceTiers\ProvenanceTiers;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PROVENANCE TIERS D'UNE PERSONNE — lecture réservée au rôle OWNER (N12,
 * 03/10/2026).
 *
 * La SEULE route qui lit `contacts_provenances_tiers`. Tout autre rôle reçoit
 * un 403 sans corps utile : ni l'existence d'une provenance, ni sa référence,
 * ni sa version d'information ne lui sont dites. Lecture seule : rien ici
 * n'écrit, et rien nulle part ne supprime une provenance.
 */
class ProvenancesTiersController extends ConsoleController
{
    public function index(Request $request, int $contactId): JsonResponse
    {
        if (! ProvenanceTiers::lisiblePar($this->currentUser($request))) {
            abort(403, 'Lecture réservée au rôle owner.');
        }
        $workspaceId = $this->businessWorkspace($request);

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $contactId): JsonResponse {
            $existe = DB::table('contacts')
                ->where('workspace_id', $workspaceId)
                ->where('id', $contactId)
                ->whereNull('deleted_at')
                ->exists();
            if (! $existe) {
                abort(404);
            }

            $lignes = DB::table('contacts_provenances_tiers')
                ->where('workspace_id', $workspaceId)
                ->where('contact_id', $contactId)
                ->orderBy('id')
                ->get(['id', 'origine', 'reference_externe', 'information_tiers_version', 'derniere_sequence', 'recu_le', 'created_at', 'updated_at'])
                ->map(static function (object $l): array {
                    $version = $l->information_tiers_version === null ? null : (string) $l->information_tiers_version;

                    return [
                        'id' => (int) $l->id,
                        'origine' => (string) $l->origine,
                        'reference_externe' => (string) $l->reference_externe,
                        'information_tiers_version' => $version,
                        'information_tiers_numero' => ProvenanceTiers::numeroVersion($version),
                        'information_suffisante' => ! ProvenanceTiers::informationInsuffisante($version),
                        'derniere_sequence' => $l->derniere_sequence === null ? null : (int) $l->derniere_sequence,
                        'recu_le' => $l->recu_le,
                        'created_at' => $l->created_at,
                        'updated_at' => $l->updated_at,
                    ];
                })
                ->all();

            return $this->ok(['data' => $lignes, 'version_minimale' => ProvenanceTiers::VERSION_INFORMATION_MINIMALE]);
        });
    }
}
