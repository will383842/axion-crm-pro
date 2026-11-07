<?php

namespace App\Console\Commands;

use App\Envoi\Serveur\Rattrapage;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Réconciliation quotidienne (02:30) : tirage par curseur, 72 h au plus. Inerte sans serveur ni secret. */
final class EnvoiReconcilier extends Command
{
    protected $signature = 'envoi:reconcilier';

    protected $description = 'Rattrape les événements du serveur d’envoi manquants (tirage par curseur, 72 h)';

    public function handle(Rattrapage $rattrapage): int
    {
        if ((string) config('envoi.serveur.secret', '') === '') {
            return self::SUCCESS;
        }
        foreach (DB::table('workspaces')->pluck('id') as $ws) {
            $sources = WorkspaceContext::run((string) $ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->where('actif', true)->pluck('source'));
            foreach ($sources as $source) {
                try {
                    $rattrapage->executer((string) $ws, (string) $source);
                } catch (Throwable $e) {
                    $this->error("réconciliation {$source} : " . $e->getMessage()); // un serveur en panne ne bloque pas les autres
                }
            }
        }

        return self::SUCCESS;
    }
}
