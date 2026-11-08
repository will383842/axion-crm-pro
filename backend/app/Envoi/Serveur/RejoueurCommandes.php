<?php

namespace App\Envoi\Serveur;

use App\Envoi\Serveur\Contrat\CommandeurServeur;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Relit les INTENTIONS non acquittées du journal et les renvoie avec le MÊME commande_id (le serveur est
 * idempotent ; le journal aussi : une commande déjà acquittée n'est jamais renvoyée). Rien n'est fait tant que
 * `reel_actif` ET `k1_actif` ne sont pas vrais. Un échec est compté et journalisé SANS contenu de réponse ;
 * l'intention reste non acquittée, donc reprise au passage suivant.
 */
final class RejoueurCommandes
{
    public const LIMITE_PAR_ESPACE = 50;

    public function __construct(private readonly CommandeurServeur $commandeur, private readonly JournalCommandes $journal) {}

    /** @return array{rejouees: int, echecs: int} */
    public function rejouer(): array
    {
        $bilan = ['rejouees' => 0, 'echecs' => 0];
        if (config('envoi.reel_actif') !== true || config('envoi.k1_actif') !== true) {
            return $bilan;
        }
        foreach (DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id') as $espace) {
            foreach ($this->journal->nonAcquittees((string) $espace, self::LIMITE_PAR_ESPACE) as $i) {
                try {
                    if ($this->commandeur->commander((string) $espace, $i['source'], $i['commande_id'], $i['commande'])) {
                        $bilan['rejouees']++;
                    }
                } catch (Throwable $e) {
                    $bilan['echecs']++;
                    Log::warning('envoi.rejeu_commande_echec', ['workspace_id' => (string) $espace, 'commande_id' => $i['commande_id'], 'motif' => $e->getMessage()]);
                }
            }
        }

        return $bilan;
    }
}
