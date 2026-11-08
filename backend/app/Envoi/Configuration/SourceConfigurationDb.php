<?php

namespace App\Envoi\Configuration;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Domaines lus dans la base du CRM (envoi_domaines). Sélecteur, clé PUBLIQUE, débit et plafond souhaités
 * viennent de $parametres (fichier local de l'opérateur, hors dépôt). Un domaine sans paramètres est ignoré.
 */
final class SourceConfigurationDb implements SourceConfiguration
{
    /** @param array<string, array<string, mixed>> $parametres */
    public function __construct(private readonly string $workspaceId, private readonly array $parametres) {}

    public function domaines(): array
    {
        $noms = WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_domaines')
            ->where('workspace_id', $this->workspaceId)->pluck('nom')->all());
        $domaines = [];
        foreach ($noms as $nom) {
            $p = $this->parametres[$nom] ?? null;
            if (is_array($p)) {
                $domaines[] = new DomaineConfig((string) $nom, (string) ($p['selecteur'] ?? ''), (string) ($p['cle_publique'] ?? ''), (int) ($p['debit_heure'] ?? 0), (int) ($p['plafond_jour'] ?? 0));
            }
        }

        return $domaines;
    }
}
