<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LitAdresseSansTrace;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enregistre la journée d'un fournisseur témoin (L8-F15) : réception, authentification alignée, bouton « Se désabonner »
 * vu, POST de désinscription effectif en moins d'une minute. AJOUT SEUL : corriger = nouvelle ligne (la dernière fait foi).
 * Aucun envoi, aucune connexion réseau. Le fournisseur se désigne par son code ; l'adresse n'est JAMAIS un argument ni
 * une option : seulement pour « indesirables » (pause via la garde existante), sur STDIN ou en invite masquée.
 */
class EnvoiTemoinsJournee extends Command
{
    use LitAdresseSansTrace;

    private const RECEPTIONS = ['inbox', 'indesirables', 'absent'];

    protected $signature = 'envoi:temoins-journee {workspace : identifiant du workspace} {fournisseur : gmail, google_workspace, outlook_com, microsoft_365, yahoo ou orange}
        {--reception= : inbox, indesirables ou absent}
        {--auth-alignee : SPF, DKIM et DMARC alignés}
        {--bouton-vu : bouton « Se désabonner » vu}
        {--post-effectif : POST de désinscription effectif en moins d\'une minute}
        {--jour= : AAAA-MM-JJ (défaut : aujourd\'hui, jamais dans le futur)}
        {--auteur= : qui enregistre}';

    protected $description = 'Enregistre la journée d\'un fournisseur témoin (ajout seul, la dernière ligne fait foi ; aucun envoi, aucun réseau, aucune adresse en option)';

    public function handle(GardeChauffe $garde, DepotSocle $depot): int
    {
        $workspace = (string) $this->argument('workspace');
        $fournisseur = (string) $this->argument('fournisseur');
        $reception = (string) $this->option('reception');

        if (! Str::isUuid($workspace)) {
            $this->error('Workspace refusé : identifiant invalide.');

            return self::FAILURE;
        }
        if (! in_array($fournisseur, DepotSocleDb::FOURNISSEURS_TEMOINS, true)) {
            $this->error('Fournisseur refusé. Valeurs permises : ' . implode(', ', DepotSocleDb::FOURNISSEURS_TEMOINS) . '.');

            return self::FAILURE;
        }
        if (! in_array($reception, self::RECEPTIONS, true)) {
            $this->error('Réception refusée. Valeurs permises : ' . implode(', ', self::RECEPTIONS) . '.');

            return self::FAILURE;
        }
        $aujourdhui = JourEnvoi::de()->format('Y-m-d');
        $jour = (string) ($this->option('jour') ?: $aujourdhui);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $jour);
        if ($date === false || $date->format('Y-m-d') !== $jour || $jour > $aujourdhui) {
            $this->error('Jour refusé : format AAAA-MM-JJ, jamais dans le futur.');

            return self::FAILURE;
        }
        $auteur = mb_substr((string) ($this->option('auteur') ?: get_current_user()), 0, 100);
        if ($auteur === '') {
            $auteur = 'inconnu';
        }

        if ($reception === 'indesirables') {
            $adresse = $this->lireAdresse('Adresse du témoin (saisie masquée)');
            if ($adresse === null) {
                $this->error('Adresse absente : fournissez-la par l\'invite masquée ou sur l\'entrée standard (une ligne).');

                return self::FAILURE;
            }
            if (! $this->temoinDeCeFournisseur($depot, $workspace, $adresse, $fournisseur)) {
                $this->error('Adresse refusée : ce n\'est pas le témoin de ce fournisseur.');

                return self::FAILURE;
            }
            try {
                $garde->indesirableChezTemoin($workspace, $adresse, null, $auteur);
            } catch (EnvoiRefuse) {
                $this->error('Refus : configuration de l\'empreinte absente.');

                return self::FAILURE;
            }
        }

        WorkspaceContext::run($workspace, fn () => DB::table('envoi_temoins_journees')->insert([
            'workspace_id' => $workspace,
            'jour' => $jour,
            'fournisseur' => $fournisseur,
            'reception' => $reception,
            'auth_alignee' => (bool) $this->option('auth-alignee'),
            'bouton_vu' => (bool) $this->option('bouton-vu'),
            'post_effectif' => (bool) $this->option('post-effectif'),
            'auteur' => $auteur,
        ]));
        $this->info('Journée enregistrée (nouvelle ligne, la dernière fait foi).');

        return self::SUCCESS;
    }

    /** L'adresse doit être un témoin connu de l'espace ET du fournisseur demandé (comparaison d'empreintes, jamais d'adresse). */
    private function temoinDeCeFournisseur(DepotSocle $depot, string $workspace, string $adresse, string $fournisseur): bool
    {
        try {
            $empreinte = EmpreinteTemoin::pourDecision($adresse);
        } catch (EnvoiRefuse) {
            return false;
        }
        if ($empreinte === null || ! $depot->estTemoin($workspace, $adresse)) {
            return false;
        }
        foreach ($depot->listerTemoins($workspace) as $t) {
            if ($t['empreinte'] === $empreinte) {
                return $t['fournisseur'] === $fournisseur;
            }
        }

        return false;
    }
}
