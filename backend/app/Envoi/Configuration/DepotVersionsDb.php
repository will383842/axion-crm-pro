<?php

namespace App\Envoi\Configuration;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/** Ajout seul : une ligne par tentative (empreinte, statut). Aucun contenu stocké. */
final class DepotVersionsDb implements DepotVersions
{
    public function noter(string $workspaceId, string $empreinte, StatutVersion $statut, ?string $motif = null): void
    {
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_config_versions')->insert([
            'workspace_id' => $workspaceId, 'empreinte' => $empreinte, 'statut' => $statut->value, 'motif' => $motif,
        ]));
    }
}
