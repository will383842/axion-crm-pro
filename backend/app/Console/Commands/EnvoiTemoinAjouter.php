<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LitAdresseSansTrace;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\TemoinRefuse;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Ajoute un témoin (commande locale, jamais par API). L'adresse n'est jamais affichée ni journalisée,
 * et n'est JAMAIS un argument ni une option : invite masquée, ou une ligne sur l'entrée standard.
 */
class EnvoiTemoinAjouter extends Command
{
    use LitAdresseSansTrace;

    private const LIBELLE_LONGUEUR_MAX = 60;

    private const MOTIFS_ADRESSE_REFORMULEE = '/@|\bat\b|\barobase\b|\[\s*at\s*\]|\(\s*at\s*\)|\{\s*at\s*\}/iu';

    protected $signature = 'envoi:temoin-ajouter {workspace : identifiant du workspace} {fournisseur : gmail, google_workspace, outlook_com, microsoft_365, yahoo ou orange} {--libelle= : libellé libre, jamais une adresse} {--auteur= : qui demande}';

    protected $description = 'Ajoute un témoin au workspace (adresse lue en invite masquée ou sur STDIN ; empreinte seulement, journalisé, idempotent, 6 témoins max)';

    public function handle(DepotSocle $depot): int
    {
        $workspace = (string) $this->argument('workspace');
        $fournisseur = (string) $this->argument('fournisseur');
        $libelle = trim((string) $this->option('libelle'));

        if (! Str::isUuid($workspace)) {
            $this->error('Workspace refusé : identifiant invalide.');

            return self::FAILURE;
        }
        if (! in_array($fournisseur, DepotSocleDb::FOURNISSEURS_TEMOINS, true)) {
            $this->error('Fournisseur refusé. Valeurs permises : ' . implode(', ', DepotSocleDb::FOURNISSEURS_TEMOINS) . '.');

            return self::FAILURE;
        }
        if (mb_strlen($libelle) > self::LIBELLE_LONGUEUR_MAX || preg_match(self::MOTIFS_ADRESSE_REFORMULEE, $libelle) === 1) {
            $this->error('Libellé refusé : ' . self::LIBELLE_LONGUEUR_MAX . ' caractères au plus, et jamais une adresse (même reformulée).');

            return self::FAILURE;
        }
        $lue = $this->lireAdresse('Adresse du témoin (saisie masquée)');
        if ($lue === null) {
            $this->error('Adresse absente : fournissez-la par l\'invite masquée ou sur l\'entrée standard (une ligne).');

            return self::FAILURE;
        }
        $adresse = (string) preg_replace('/\s+/u', '', $lue);
        if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $adresse) !== 1) {
            $this->error('Adresse refusée : format invalide.');

            return self::FAILURE;
        }
        try {
            if (EmpreinteTemoin::pourDecision($adresse) === null) {
                $this->error('Adresse refusée : seules les adresses ASCII sont acceptées.');

                return self::FAILURE;
            }
        } catch (EnvoiRefuse) {
            $this->error('Refus : configuration de l\'empreinte absente.');

            return self::FAILURE;
        }

        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        try {
            $nouveau = $depot->ajouterTemoin($workspace, $adresse, $fournisseur, $libelle, $auteur);
        } catch (TemoinRefuse $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info($nouveau ? 'Témoin ajouté (journalisé).' : 'Témoin déjà présent : rien à faire (journalisé).');

        return self::SUCCESS;
    }
}
