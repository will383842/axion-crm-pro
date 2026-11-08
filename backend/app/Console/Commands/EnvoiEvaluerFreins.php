<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\GardeChauffe;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Évalue les freins automatiques d’un espace et ouvre les pauses (alertes internes). */
class EnvoiEvaluerFreins extends Command
{
    protected $signature = 'envoi:evaluer-freins {workspace? : identifiant du workspace (tous les espaces si absent)}';

    protected $description = 'Évalue les freins d’envoi (rebonds, plaintes, réponses, humain, homme mort)';

    public function handle(GardeChauffe $garde): int
    {
        $ws = $this->argument('workspace');
        $espaces = $ws !== null ? [(string) $ws] : $this->tousLesEspaces();
        $total = 0;
        $echecs = 0;
        $maintenant = now()->toDateTimeImmutable(); // horloge de l'appli (figeable en test), pas new DateTimeImmutable
        foreach ($espaces as $espace) {
            // Un espace en erreur ne doit pas empêcher d'évaluer les suivants (leurs freins comptent autant).
            try {
                foreach ($garde->evaluer($espace, $maintenant) as $d) {
                    $total++;
                    $this->warn($espace . ' — pause ouverte : ' . $d->motif . ($d->fournisseur !== null ? ' (' . $d->fournisseur . ')' : ''));
                }
            } catch (\Throwable $e) {
                $echecs++;
                report($e);
                $this->error($espace . ' — évaluation impossible : ' . $e->getMessage());
            }
        }
        $this->info($total . ' pause(s) ouverte(s).');

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function tousLesEspaces(): array
    {
        return WorkspaceContext::runWithoutScope(
            'envoi:evaluer-freins : parcourt tous les espaces (lecture des identifiants seulement)',
            fn (): array => array_values(array_map('strval', DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all())),
        );
    }
}
