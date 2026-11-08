<?php

namespace App\Envoi\Configuration;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Domaines lus dans la base du CRM (envoi_domaines). Débit et plafond souhaités viennent de $parametres
 * (fichier local de l'opérateur, hors dépôt) : par domaine, exactement debit_heure et plafond_jour.
 * Un domaine de la base sans paramètres est ignoré (et listé) ; un paramètre pour un domaine absent est refusé.
 */
final class SourceConfigurationDb implements SourceConfiguration
{
    /** @var list<string> */
    private array $ignores = [];

    /** @param array<string, mixed> $parametres */
    public function __construct(private readonly string $workspaceId, private readonly array $parametres) {}

    public function domaines(): array
    {
        $noms = WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_domaines')
            ->where('workspace_id', $this->workspaceId)->pluck('nom')->map(fn ($n) => (string) $n)->all());
        foreach (array_keys($this->parametres) as $nom) {
            if (! in_array((string) $nom, $noms, true)) {
                throw new PaquetInvalide('paramètres pour un domaine absent de la base de l\'espace');
            }
        }
        $domaines = [];
        $this->ignores = [];
        foreach ($noms as $nom) {
            $p = $this->parametres[$nom] ?? null;
            if ($p === null) {
                $this->ignores[] = $nom;

                continue;
            }
            if (! is_array($p) || array_keys($p) === [] || array_diff(array_keys($p), ['debit_heure', 'plafond_jour']) !== []
                || ! is_int($p['debit_heure'] ?? null) || ! is_int($p['plafond_jour'] ?? null)) {
                throw new PaquetInvalide('paramètres d\'un domaine : exactement debit_heure et plafond_jour (entiers)');
            }
            $domaines[] = new DomaineConfig($nom, $p['debit_heure'], $p['plafond_jour']);
        }
        sort($this->ignores);

        return $domaines;
    }

    public function ignores(): array
    {
        return $this->ignores;
    }
}
