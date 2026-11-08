<?php

namespace App\Console\Commands;

use App\Envoi\Configuration\AlerteDerive;
use App\Envoi\Configuration\AlerteDeriveLog;
use App\Envoi\Configuration\Applicateur;
use App\Envoi\Configuration\DepotVersions;
use App\Envoi\Configuration\DepotVersionsDb;
use App\Envoi\Configuration\Paquet;
use App\Envoi\Configuration\PaquetInvalide;
use App\Envoi\Configuration\ServeurEnvoiConfiguration;
use App\Envoi\Configuration\SourceConfigurationDb;
use App\Envoi\Configuration\StatutVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Commande LOCALE au CRM (jamais d'API, jamais sur le serveur d'envoi) : génère le paquet d'un espace depuis la
 * base, puis le remet au serveur d'envoi par l'adaptateur ServeurEnvoiConfiguration (passerelle HTTPS signée).
 * Le CRM ne pose aucun fichier sur le serveur d'envoi.
 */
class EnvoiConfigAppliquer extends Command
{
    protected $signature = 'envoi:config-appliquer {workspace : UUID de l\'espace} {parametres : fichier JSON local (hors dépôt)}';

    protected $description = 'Remet au serveur d\'envoi le paquet de configuration d\'un espace (K18)';

    public function handle(): int
    {
        $ws = (string) $this->argument('workspace');
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $ws) !== 1
            || ! DB::table('workspaces')->where('id', $ws)->exists()) {
            $this->error('Espace inconnu : un UUID présent dans workspaces est attendu.');

            return self::FAILURE;
        }
        if (! app()->bound(ServeurEnvoiConfiguration::class)) {
            $this->error('adaptateur de configuration absent (K9-suite)');

            return self::FAILURE;
        }
        $parametres = json_decode((string) @file_get_contents((string) $this->argument('parametres')), true);
        if (! is_array($parametres)) {
            $this->error('Fichier de paramètres illisible.');

            return self::FAILURE;
        }
        $cle = 'envoi_config:' . $ws;
        if (DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS ok', [$cle])->ok !== true) {
            $this->error('Une application est déjà en cours pour cet espace.');

            return self::FAILURE;
        }
        try {
            return $this->appliquer($ws, $parametres);
        } finally {
            DB::selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0)) AS ok', [$cle]);
        }
    }

    /** @param array<mixed> $parametres */
    private function appliquer(string $ws, array $parametres): int
    {
        $depot = app()->bound(DepotVersions::class) ? app(DepotVersions::class) : new DepotVersionsDb;
        $alerte = app()->bound(AlerteDerive::class) ? app(AlerteDerive::class) : new AlerteDeriveLog;
        $source = new SourceConfigurationDb($ws, $parametres);
        try {
            try {
                $paquet = Paquet::pourEspace($ws, $source);
            } catch (PaquetInvalide $e) {
                $depot->noter($ws, hash('sha256', 'parametres:' . $e->getMessage()), StatutVersion::Invalide, $e->getMessage());
                $this->error('invalide : ' . $e->getMessage());

                return self::FAILURE;
            }
            foreach ($source->ignores() as $nom) {
                $this->line('ignoré : ' . $nom);
            }
            $note = $source->ignores() === [] ? null : count($source->ignores()) . ' domaine(s) ignoré(s) sans paramètres';
            $statut = (new Applicateur(app(ServeurEnvoiConfiguration::class), $depot, $alerte))->appliquer($ws, $paquet->contenu, $note);
        } catch (Throwable $e) {
            $this->error('échec : ' . $e::class);

            return self::FAILURE;
        }
        $this->line($statut->value . ' ' . $paquet->empreinte());

        return $statut === StatutVersion::Appliquee ? self::SUCCESS : self::FAILURE;
    }
}
