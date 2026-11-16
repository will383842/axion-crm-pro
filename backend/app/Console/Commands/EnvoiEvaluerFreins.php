<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Évalue les freins automatiques d’un espace et ouvre les pauses (alertes internes). */
class EnvoiEvaluerFreins extends Command
{
    protected $signature = 'envoi:evaluer-freins {workspace? : identifiant du workspace (tous les espaces si absent)}';

    protected $description = 'Évalue les freins d’envoi (rebonds, plaintes, réponses, humain, homme mort)';

    public function handle(GardeChauffe $garde, DepotChauffe $depot, DepotSocle $socle): int
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
                // X5 : un battement par jour et par espace, SEULEMENT après une évaluation sans exception (signal lu par le feu vert).
                $jour = JourEnvoi::de($maintenant);
                if (! $depot->dejaJournaliseLe($espace, 'evaluation_freins', $jour)) {
                    $socle->journaliser($espace, 'evaluation_freins', 'freins', [], $jour);
                }
            } catch (\Throwable $e) {
                $echecs++;
                // Jamais le message de l'exception (une QueryException porte du SQL et des valeurs) : classe seulement.
                Log::error('[ENVOI] évaluation des freins : espace en erreur.', ['workspace' => $espace, 'exception' => $e::class]);
                $this->error($espace . ' — évaluation impossible (' . $e::class . ') : détail au journal technique.');
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
