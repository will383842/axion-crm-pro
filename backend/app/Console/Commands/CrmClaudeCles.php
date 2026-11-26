<?php

namespace App\Console\Commands;

use App\Crm\Claude\RotationCles;
use Illuminate\Console\Command;

/**
 * ÉTAT DES CLÉS CLAUDE — lecture seule par défaut, jamais la clé (alias seulement).
 * `--reactiver=<alias>` remet un alias `invalide` ou `epuisee` à `disponible`.
 */
class CrmClaudeCles extends Command
{
    protected $signature = 'crm:claude:cles
                            {--reactiver= : Alias à remettre disponible}';

    protected $description = 'État de chaque clé Claude de la liste (alias seulement, jamais la clé).';

    public function handle(): int
    {
        $rotation = RotationCles::depuisConfig();
        $alias = $rotation->aliasValides();
        if ($alias === []) {
            $this->error('Aucun alias valide dans CRM_CLAUDE_CLES_ENRICHISSEMENT.');

            return self::FAILURE;
        }

        $demande = $this->option('reactiver');
        if (is_string($demande) && $demande !== '') {
            if (! in_array($demande, $alias, true)) {
                $this->error('Alias inconnu ou absent de la liste.');

                return self::FAILURE;
            }
            $rotation->reactiver($demande);
            $this->info("Alias {$demande} remis disponible.");
        }

        $etats = $rotation->etats();
        foreach ($alias as $a) {
            $e = $etats[$a] ?? null;
            $this->line(sprintf('%-12s %-11s %s', $a, $e['etat'] ?? 'disponible', $e !== null ? $e['depuis']->toIso8601String() : '-'));
        }

        return self::SUCCESS;
    }
}
