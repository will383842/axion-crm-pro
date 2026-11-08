<?php

namespace App\Envoi\Dns;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class DepotControlesDnsDb implements DepotControlesDns
{
    public function enregistrer(string $workspaceId, RapportControle $rapport, DateTimeImmutable $quand): void
    {
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_controles_dns')->insert([
            'workspace_id' => $workspaceId,
            'domaine' => $rapport->domaine,
            'controle_le' => $quand->format('Y-m-d H:i:sP'),
            'pret' => $rapport->pret(),
            'pause' => $rapport->pause(),
            'resultats' => json_encode($rapport->enTableau(), JSON_THROW_ON_ERROR),
        ]));
    }

    public function marquerVerifie(string $workspaceId, string $domaine, DateTimeImmutable $quand): void
    {
        $t = $quand->format('Y-m-d H:i:sP');
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_domaines')
            ->where('workspace_id', $workspaceId)
            ->where('nom', $domaine)
            ->update(['spf_verifie_le' => $t, 'dkim_verifie_le' => $t, 'dmarc_verifie_le' => $t]));
    }
}
