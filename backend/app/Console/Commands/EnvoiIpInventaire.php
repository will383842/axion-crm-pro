<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\DepotIps;
use App\Exceptions\MissingWorkspaceContextException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/** Inventaire des IP d'envoi : commande LOCALE (une IP est une donnée d'infrastructure, pas un geste d'écran). */
class EnvoiIpInventaire extends Command
{
    protected $signature = 'envoi:ip-inventaire {workspace : identifiant du workspace} {action : ajouter|retirer} {ip : IPv4 pointée} {--flux= : prospection|lettre (ajout)} {--auteur= : qui agit}';

    protected $description = 'Inscrit ou retire une IP d\'envoi de l\'inventaire (local, ajout seul)';

    public function handle(DepotIps $depot): int
    {
        $ws = (string) $this->argument('workspace');
        $ip = (string) $this->argument('ip');
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        try {
            switch ($this->argument('action')) {
                case 'ajouter':
                    $depot->ajouter($ws, $ip, (string) $this->option('flux'), $auteur);
                    $this->info('IP inscrite.');

                    return self::SUCCESS;
                case 'retirer':
                    if (! $depot->retirer($ws, $ip)) {
                        $this->error('IP inconnue ou déjà retirée : rien changé.');

                        return self::FAILURE;
                    }
                    $this->warn('IP retirée (conservée dans l\'inventaire).');

                    return self::SUCCESS;
                default:
                    $this->error('Action inconnue : ajouter ou retirer.');

                    return self::FAILURE;
            }
        } catch (InvalidArgumentException $e) {
            $this->error('Refusé : ' . $e->getMessage() . '.');
        } catch (MissingWorkspaceContextException) {
            $this->error('Refusé : espace inconnu ou invalide.');
        } catch (QueryException) {
            $this->error('Refusé par la base (IP déjà inscrite, espace inconnu ou accès refusé).');
        }

        return self::FAILURE;
    }
}
