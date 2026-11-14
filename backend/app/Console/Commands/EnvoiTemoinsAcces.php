<?php

namespace App\Console\Commands;

use App\Envoi\Temoins\DepotAccesTemoins;
use App\Envoi\Temoins\HotesTemoins;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pose l'accès IMAP d'un témoin (R16), SEULE porte d'entrée : commande LOCALE, aucune route. L'hôte vient de la liste
 * fermée {@see HotesTemoins} (aucune option d'hôte). L'adresse et le mot de passe ne sont JAMAIS des arguments : invite
 * masquée (terminal) ou deux lignes sur l'entrée standard (adresse, puis mot de passe). Rien n'est affiché ni journalisé.
 */
class EnvoiTemoinsAcces extends Command
{
    protected $signature = 'envoi:temoins-acces {workspace : identifiant du workspace} {fournisseur : gmail, google_workspace, outlook_com, microsoft_365, yahoo ou orange}';

    protected $description = 'Pose l\'accès IMAP chiffré d\'un témoin (adresse puis mot de passe en invite masquée ou sur STDIN ; hôte pris dans une liste fermée ; aucune sortie sensible)';

    public function handle(DepotAccesTemoins $depot): int
    {
        $workspace = (string) $this->argument('workspace');
        $fournisseur = (string) $this->argument('fournisseur');
        if (! Str::isUuid($workspace)) {
            $this->error('Workspace refusé : identifiant invalide.');

            return self::FAILURE;
        }
        if (! array_key_exists($fournisseur, HotesTemoins::LISTE)) {
            $this->error('Fournisseur refusé. Valeurs permises : ' . implode(', ', array_keys(HotesTemoins::LISTE)) . '.');

            return self::FAILURE;
        }
        [$adresse, $motDePasse] = $this->lire();
        try {
            $depot->poser($workspace, $fournisseur, $adresse, $motDePasse);
        } catch (InvalidArgumentException) {
            $this->error('Accès refusé : adresse ou mot de passe absent ou invalide.');

            return self::FAILURE;
        } catch (QueryException) {
            // Espace inexistant (clé étrangère) ou refus de la base : message fixe, ni requête ni valeur (adresse, mot de passe).
            $this->error('Accès refusé : espace inexistant ou écriture impossible.');

            return self::FAILURE;
        }
        $this->info('Accès posé (chiffré, nouvelle ligne : la dernière fait foi).');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} */
    private function lire(): array
    {
        if ($this->input->isInteractive()) {
            return [trim((string) $this->secret('Adresse du témoin (saisie masquée)')), (string) $this->secret('Mot de passe d\'application (saisie masquée)')];
        }
        $flux = $this->laravel->bound('envoi.flux_entree') ? $this->laravel->make('envoi.flux_entree') : @fopen('php://stdin', 'r');
        $a = is_resource($flux) ? fgets($flux, 300) : false;
        $m = is_resource($flux) ? fgets($flux, 1030) : false;

        return [is_string($a) ? trim($a) : '', is_string($m) ? rtrim($m, "\r\n") : ''];
    }
}
