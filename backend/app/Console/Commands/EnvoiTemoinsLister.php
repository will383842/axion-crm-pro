<?php

namespace App\Console\Commands;

use App\Envoi\Depot\DepotSocle;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Liste les témoins d'un workspace : empreinte abrégée, fournisseur, libellé. Jamais d'adresse. */
class EnvoiTemoinsLister extends Command
{
    protected $signature = 'envoi:temoins-lister {workspace : identifiant du workspace}';

    protected $description = 'Liste les témoins du workspace (empreinte abrégée, fournisseur, libellé)';

    public function handle(DepotSocle $depot): int
    {
        $workspace = (string) $this->argument('workspace');
        if (! Str::isUuid($workspace)) {
            $this->error('Workspace refusé : identifiant invalide.');

            return self::FAILURE;
        }
        $lignes = $depot->listerTemoins($workspace);
        $this->table(
            ['Empreinte', 'Fournisseur', 'Libellé', 'Créé le'],
            array_map(fn (array $l): array => [substr($l['empreinte'], 0, 12), $l['fournisseur'] ?? '-', $l['libelle'], $l['cree_le']], $lignes),
        );

        return self::SUCCESS;
    }
}
