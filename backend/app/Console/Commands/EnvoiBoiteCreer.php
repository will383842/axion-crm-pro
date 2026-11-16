<?php

namespace App\Console\Commands;

use App\Envoi\Smtp\DepotBoites;
use App\Envoi\Smtp\EnvoiSmtpRefuse;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Crée une boîte d'envoi (commande LOCALE, aucune route). L'identifiant et le mot de passe SMTP ne sont JAMAIS des arguments :
 * invite masquée (terminal) ou deux lignes sur l'entrée standard. Aucune connexion SMTP : seul le DNS de l'hôte est résolu
 * (garde anti-SSRF existante). Rien de sensible n'est affiché ni journalisé.
 */
class EnvoiBoiteCreer extends Command
{
    protected $signature = 'envoi:boite-creer {workspace : identifiant du workspace} {adresse : adresse d\'envoi de la boîte} {hote_smtp : hôte SMTP} {port_smtp : port SMTP} {chiffrement : tls ou starttls}';

    protected $description = 'Crée une boîte d\'envoi (identifiant puis mot de passe SMTP en invite masquée ou sur STDIN ; aucune sortie sensible)';

    public function handle(DepotBoites $boites): int
    {
        $workspace = (string) $this->argument('workspace');
        $adresse = trim((string) $this->argument('adresse'));
        if (! Str::isUuid($workspace)) {
            $this->error('Workspace refusé : identifiant invalide.');

            return self::FAILURE;
        }
        if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false || ! ctype_digit((string) $this->argument('port_smtp'))) {
            $this->error('Boîte refusée : adresse ou port invalide.');

            return self::FAILURE;
        }
        [$utilisateur, $motDePasse] = $this->lire();
        if ($utilisateur === '' || $motDePasse === '') {
            $this->error('Boîte refusée : identifiant ou mot de passe SMTP absent.');

            return self::FAILURE;
        }
        try {
            $id = $boites->creer($workspace, $adresse, (string) $this->argument('hote_smtp'), (int) $this->argument('port_smtp'), (string) $this->argument('chiffrement'), $utilisateur, $motDePasse);
        } catch (EnvoiSmtpRefuse) {
            $this->error('Boîte refusée : hôte, port ou chiffrement SMTP non admis.');

            return self::FAILURE;
        } catch (QueryException) {
            $this->error('Boîte refusée : espace inexistant ou écriture impossible.'); // message fixe : ni requête ni valeur

            return self::FAILURE;
        }
        $this->info($id);

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} */
    private function lire(): array
    {
        if ($this->input->isInteractive()) {
            return [trim((string) $this->secret('Identifiant SMTP (saisie masquée)')), (string) $this->secret('Mot de passe SMTP (saisie masquée)')];
        }
        $flux = $this->laravel->bound('envoi.flux_entree') ? $this->laravel->make('envoi.flux_entree') : @fopen('php://stdin', 'r');
        $a = is_resource($flux) ? fgets($flux, 300) : false;
        $m = is_resource($flux) ? fgets($flux, 1030) : false;

        return [is_string($a) ? trim($a) : '', is_string($m) ? rtrim($m, "\r\n") : ''];
    }
}
