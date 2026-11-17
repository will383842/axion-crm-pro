<?php

namespace App\Console\Commands;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\FeuVert\DepotAttestationServeurReel;
use App\Socle\Alertes\Alerte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** X5-A : attestation « serveur réel en place ». Commande LOCALE, journalisée, jamais une route (elle libère des points). */
class EnvoiServeurReelAttester extends Command
{
    protected $signature = 'envoi:serveur-reel-attester {workspace : identifiant du workspace} {geste : atteste|retire} {--auteur= : qui atteste} {--motif= : pourquoi (10 à 500 caractères, sans adresse)}';

    protected $description = 'Atteste (ou retire) que le serveur d’envoi réel est en place (local, journalisé, jamais par API)';

    public function handle(DepotAttestationServeurReel $depot, DepotSocle $journal, Alerte $alerte): int
    {
        $ws = (string) $this->argument('workspace');
        $geste = (string) $this->argument('geste');
        $auteur = trim((string) $this->option('auteur'));
        $motif = trim((string) $this->option('motif'));
        if ($auteur === '' || $motif === '') {
            $this->error('--auteur et --motif sont obligatoires : rien n’est écrit.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($depot, $journal, $ws, $geste, $auteur, $motif): void {
                $depot->inscrire($ws, $geste, $auteur, $motif);
                $journal->journaliser($ws, 'attestation_serveur_reel', $auteur, ['geste' => $geste]);
            });
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage() . ' Rien n’est écrit.');

            return self::FAILURE;
        }
        $alerte->envoyer('Attestation serveur réel', "Geste : {$geste}. Auteur : {$auteur}.", 'envoi', 'critique');
        $this->warn("Attestation « {$geste} » enregistrée (journalisée).");

        return self::SUCCESS;
    }
}
