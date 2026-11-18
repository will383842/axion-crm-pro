<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\DepotIps;
use App\Exceptions\MissingWorkspaceContextException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/** Relie un domaine d'envoi à une IP de l'inventaire (même flux, IP non retirée) : commande LOCALE, journalisée par la base. */
class EnvoiDomaineIp extends Command
{
    protected $signature = 'envoi:domaine-ip {workspace : identifiant du workspace} {domaine : domaine d\'envoi} {ip : IP de l\'inventaire} {--auteur= : qui agit}';

    protected $description = 'Relie un domaine d\'envoi à une IP de l\'inventaire (local, journalisé)';

    public function handle(DepotIps $depot): int
    {
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        try {
            $change = $depot->lier((string) $this->argument('workspace'), (string) $this->argument('domaine'), (string) $this->argument('ip'), $auteur);
            $this->info($change ? 'Domaine relié à l\'IP (journalisé).' : 'Déjà relié : rien changé.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $this->error('Refusé : ' . $e->getMessage() . '.');
        } catch (MissingWorkspaceContextException) {
            $this->error('Refusé : espace inconnu ou invalide.');
        } catch (QueryException $e) {
            // Le message de la base (préfixé « envoi_domaines : ») est sans valeur secrète : on n'affiche que cette raison.
            $this->error('Refusé : ' . (preg_match('/envoi_domaines : ([^\n]+?)(?: \(Connection|\n|$)/u', $e->getMessage(), $m) ? $m[1] : 'accès refusé par la base') . '.');
        }

        return self::FAILURE;
    }
}
