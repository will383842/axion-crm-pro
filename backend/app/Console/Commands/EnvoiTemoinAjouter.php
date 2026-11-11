<?php

namespace App\Console\Commands;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\EmpreinteTemoin;
use Illuminate\Console\Command;

/** Ajoute un témoin (commande locale, jamais par API). L'adresse n'est jamais affichée ni journalisée. */
class EnvoiTemoinAjouter extends Command
{
    protected $signature = 'envoi:temoin-ajouter {workspace : identifiant du workspace} {adresse : adresse du témoin (ASCII)} {fournisseur : gmail, google_workspace, outlook_com, microsoft_365, yahoo ou orange} {--libelle= : libellé libre, jamais une adresse} {--auteur= : qui demande}';

    protected $description = 'Ajoute un témoin au workspace (empreinte seulement, journalisé, idempotent)';

    public function handle(DepotSocle $depot): int
    {
        $adresse = (string) preg_replace('/\s+/u', '', (string) $this->argument('adresse'));
        $fournisseur = (string) $this->argument('fournisseur');
        $libelle = trim((string) $this->option('libelle'));

        if (! in_array($fournisseur, DepotSocleDb::FOURNISSEURS_TEMOINS, true)) {
            $this->error('Fournisseur refusé. Valeurs permises : ' . implode(', ', DepotSocleDb::FOURNISSEURS_TEMOINS) . '.');

            return self::FAILURE;
        }
        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $adresse) !== 1) {
            $this->error('Adresse refusée : format invalide.');

            return self::FAILURE;
        }
        if (EmpreinteTemoin::pourDecision($adresse) === null) {
            $this->error('Adresse refusée : seules les adresses ASCII sont acceptées.');

            return self::FAILURE;
        }
        if (str_contains($libelle, '@')) {
            $this->error('Libellé refusé : il ne doit jamais contenir une adresse.');

            return self::FAILURE;
        }

        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        $nouveau = $depot->ajouterTemoin((string) $this->argument('workspace'), $adresse, $fournisseur, $libelle, $auteur);
        $this->info($nouveau ? 'Témoin ajouté (journalisé).' : 'Témoin déjà présent : rien à faire (journalisé).');

        return self::SUCCESS;
    }
}
