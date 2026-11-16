<?php

namespace App\Console\Commands;

use App\Envoi\Imap\DepotAccesImap;
use App\Envoi\Imap\HotesImapBoites;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pose (ou retire avec --retirer) l'accès IMAP d'une boîte d'envoi. Commande LOCALE, aucune route, aucune connexion IMAP.
 * L'hôte vient de la liste fermée {@see HotesImapBoites}. L'identifiant et le mot de passe ne sont JAMAIS des arguments :
 * invite masquée (terminal) ou deux lignes sur l'entrée standard. Rien de sensible n'est affiché ni journalisé.
 */
class EnvoiBoiteSecret extends Command
{
    protected $signature = 'envoi:boite-secret {workspace : identifiant du workspace} {boite : identifiant de la boîte} {hote_imap : hôte IMAP de la liste fermée} {--retirer : retire l\'accès (ligne inactive, aucune saisie)}';

    protected $description = 'Pose ou retire l\'accès IMAP chiffré d\'une boîte (identifiant puis mot de passe en invite masquée ou sur STDIN ; aucune sortie sensible)';

    public function handle(DepotAccesImap $depot): int
    {
        $workspace = (string) $this->argument('workspace');
        $boite = (string) $this->argument('boite');
        $hote = (string) $this->argument('hote_imap');
        if (! Str::isUuid($workspace) || ! Str::isUuid($boite)) {
            $this->error('Refusé : identifiant de workspace ou de boîte invalide.');

            return self::FAILURE;
        }
        if (! HotesImapBoites::hoteAutorise($hote)) {
            $this->error('Hôte refusé. Valeurs permises : ' . implode(', ', HotesImapBoites::LISTE) . '.');

            return self::FAILURE;
        }
        try {
            if ($this->option('retirer')) {
                $depot->retirer($workspace, $boite);
                $this->info('Accès retiré (nouvelle ligne inactive, sans secret).');

                return self::SUCCESS;
            }
            [$identifiant, $motDePasse] = $this->lire();
            $depot->poser($workspace, $boite, $hote, $identifiant, $motDePasse);
        } catch (InvalidArgumentException) {
            $this->error('Accès refusé : boîte inconnue de cet espace, identifiant ou mot de passe absent ou invalide.');

            return self::FAILURE;
        } catch (QueryException) {
            $this->error('Accès refusé : écriture impossible.'); // message fixe : ni requête ni valeur

            return self::FAILURE;
        }
        $this->info('Accès posé (chiffré, nouvelle ligne : la dernière fait foi).');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} */
    private function lire(): array
    {
        if ($this->input->isInteractive()) {
            return [trim((string) $this->secret('Identifiant IMAP (saisie masquée)')), (string) $this->secret('Mot de passe IMAP (saisie masquée)')];
        }
        $flux = $this->laravel->bound('envoi.flux_entree') ? $this->laravel->make('envoi.flux_entree') : @fopen('php://stdin', 'r');
        $a = is_resource($flux) ? fgets($flux, 300) : false;
        $m = is_resource($flux) ? fgets($flux, 1030) : false;

        return [is_string($a) ? trim($a) : '', is_string($m) ? rtrim($m, "\r\n") : ''];
    }
}
