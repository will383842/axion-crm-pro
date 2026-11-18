<?php

namespace App\Console\Commands;

use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Battement de l'homme mort (INF-025) : appelle `sante()` du serveur d'envoi. Planifié toutes les 5 minutes ;
 * si le planificateur s'arrête, 15 minutes plus tard toutes les files passent en pause.
 */
class EnvoiSante extends Command
{
    protected $signature = 'envoi:sante';

    protected $description = 'Sonde la santé du serveur d’envoi (battement de l’homme mort, INF-025)';

    /**
     * `sante()` est interrogée DANS le contexte de chaque espace qui porte un adaptateur réel (lecture par
     * `envoi_couples_reels_portes()`, sans contexte) : hors contexte l'aiguilleur ne voit aucun adaptateur actif et répondrait toujours KO.
     * Un seul KO ⇒ échec. Aucun espace ⇒ un seul appel hors contexte, comme avant.
     */
    public function handle(ServeurEnvoi $serveur): int
    {
        $espaces = array_values(array_unique(array_map(
            static fn ($l): string => (string) $l->workspace_id,
            DB::select('SELECT workspace_id FROM envoi_couples_reels_portes()'),
        )));
        if ($espaces === []) {
            return $this->afficher($serveur->sante(), null) ? self::SUCCESS : self::FAILURE;
        }
        $tousOk = true;
        foreach ($espaces as $ws) {
            $s = WorkspaceContext::run($ws, fn (): SanteServeur => $serveur->sante());
            $tousOk = $this->afficher($s, $ws) && $tousOk;
        }

        return $tousOk ? self::SUCCESS : self::FAILURE;
    }

    private function afficher(SanteServeur $s, ?string $ws): bool
    {
        $this->line(($s->ok ? 'OK' : 'KO') . ($ws === null ? '' : ' [' . substr($ws, 0, 8) . ']') . ' : ' . $s->detail);

        return $s->ok;
    }
}
