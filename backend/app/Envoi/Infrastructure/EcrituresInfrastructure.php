<?php

namespace App\Envoi\Infrastructure;

use App\Envoi\Configuration\DepotVersions;
use App\Envoi\Configuration\GenerateurConfiguration;
use App\Envoi\Configuration\PaquetInvalide;
use App\Envoi\Configuration\SourceConfigurationDb;
use App\Envoi\Configuration\StatutVersion;
use App\Envoi\Configuration\ValidateurPaquet;
use App\Envoi\Dns\ConfigurationDomaine;
use App\Envoi\Dns\ControleurDns;
use App\Envoi\Dns\DepotControlesDns;
use App\Envoi\Dns\EnregistrementDns;
use App\Envoi\Dns\PlanDns;
use App\Envoi\Dns\ResolveurDns;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * R15 : écritures LIMITÉES de l'infrastructure d'envoi, pour UN espace. Rien ici n'écrit dans un DNS, ne change un serveur
 * ni n'envoie : on enregistre (domaine, rappel, version « prête »), on lit le DNS par le résolveur injecté, on prépare.
 * L'Applicateur n'est jamais référencé : appliquer reste fermé (voir le contrôleur).
 */
final class EcrituresInfrastructure
{
    /** Valeurs d'un plan sans configuration locale : modèles, jamais une vraie adresse (plage de documentation). */
    private const SELECTEUR = 'axion';

    private const IP_MODELE = '192.0.2.1';

    /** Rôle du contrat <- libellé du plan. */
    private const ROLES = ['nom du HELO' => 'helo', 'PTR = HELO = A' => 'ptr', 'SPF' => 'spf', 'DKIM' => 'dkim', 'DMARC' => 'dmarc', 'MTA-STS' => 'mta_sts', 'TLS-RPT' => 'tls_rpt'];

    /** Points de contrôle par rôle ; le premier dit « présent ». */
    private const POINTS = [
        'spf' => ['spf_present', 'spf_unique', 'spf_se_termine_par_all', 'spf_10_resolutions_max', 'spf_autorise_ip'],
        'dkim' => ['dkim_present', 'dkim_cle_publique', 'dkim_cle_2048'],
        'dmarc' => ['dmarc_present', 'dmarc_unique', 'dmarc_politique', 'dmarc_rua'],
        'ptr' => ['ptr_existe', 'ptr_egal_helo'],
        'helo' => ['helo_a_vers_ip'],
        'mta_sts' => ['mta_sts_enregistrement', 'mta_sts_politique', 'mta_sts_mode_testing'],
        'tls_rpt' => ['tls_rpt'],
    ];

    public function __construct(
        private readonly DepotControlesDns $controles,
        private readonly DepotVersions $versions,
        private readonly GenerateurConfiguration $generateur,
    ) {}

    /**
     * Enregistre le domaine (une seule ligne par espace et par nom : un rejeu rend l'existant) et rend le plan DNS à publier à la main.
     *
     * @return array{id: int, nom: string, cree: bool, dns: list<array<string, string>>}
     *
     * @throws InvalidArgumentException nom ou plan invalide
     */
    public function ajouterDomaine(string $ws, string $saisie, string $flux): array
    {
        $nom = NomDomaineEnvoi::normaliser($saisie);
        $plan = $this->plan(new ConfigurationDomaine($nom, self::SELECTEUR, 'mail.' . $nom, self::IP_MODELE), []);

        return WorkspaceContext::run($ws, function () use ($ws, $nom, $flux, $plan): array {
            $ligne = DB::selectOne(
                'INSERT INTO envoi_domaines (workspace_id, nom, achete_le, flux) VALUES (?, ?, CURRENT_DATE, ?) ON CONFLICT (workspace_id, nom) DO NOTHING RETURNING id',
                [$ws, $nom, $flux],
            );
            $cree = $ligne !== null;
            $id = $cree ? (int) $ligne->id : (int) DB::table('envoi_domaines')->where('workspace_id', $ws)->where('nom', $nom)->value('id');

            return ['id' => $id, 'nom' => $nom, 'cree' => $cree, 'dns' => $plan];
        });
    }

    /**
     * Contrôle DNS (lecture) d'un domaine de CET espace ; null si inconnu de l'espace (aucune interrogation alors).
     *
     * @return ?array{pret: bool, pause: bool, dns: list<array<string, string>>}
     */
    public function verifierDns(string $ws, int $id, ResolveurDns $dns, DateTimeImmutable $quand): ?array
    {
        $nom = $this->nomDomaine($ws, $id);
        if ($nom === null) {
            return null;
        }
        /** @var array<string, array<string, string>> $locales */
        $locales = (array) config('envoi.dns.domaines', []);
        $p = $locales[$nom] ?? null;
        try {
            $c = new ConfigurationDomaine($nom, (string) ($p['selecteur'] ?? self::SELECTEUR), (string) ($p['helo'] ?? 'mail.' . $nom), (string) ($p['ip'] ?? self::IP_MODELE));
        } catch (InvalidArgumentException) {
            $c = new ConfigurationDomaine($nom, self::SELECTEUR, 'mail.' . $nom, self::IP_MODELE);
            $p = null;
        }
        $rapport = (new ControleurDns($dns))->controler($c);
        $this->controles->enregistrer($ws, $rapport, $quand);
        // Sans paramètres locaux, l'adresse du modèle ne prouve rien : le domaine n'est pas marqué vérifié.
        if ($p !== null && $rapport->pret()) {
            $this->controles->marquerVerifie($ws, $nom, $quand);
        }
        $points = $rapport->enTableau();
        $plan = $this->plan($c, $points);

        return ['pret' => $rapport->pret(), 'pause' => $rapport->pause(), 'dns' => $plan];
    }

    /**
     * Enregistre un RAPPEL (aucune publication). Null si le domaine n'est pas de cet espace.
     *
     * @return ?array{politique: string, rappel_le: ?string}
     */
    public function rappelDmarc(string $ws, int $id, string $politique, ?DateTimeImmutable $rappelLe, string $auteur): ?array
    {
        if ($this->nomDomaine($ws, $id) === null) {
            return null;
        }
        $quand = $rappelLe?->format('Y-m-d H:i:sP');
        WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines_rappels_dmarc')->insert([
            'workspace_id' => $ws, 'domaine_id' => $id, 'politique' => $politique, 'rappel_le' => $quand, 'auteur' => $auteur,
        ]));

        return ['politique' => $politique, 'rappel_le' => $rappelLe?->format('c')];
    }

    /**
     * Prépare une version « prête » du paquet de configuration (validée, jamais appliquée). L'adresse n'est NI conservée NI journalisée :
     * le paquet ne porte que domaine, débit et plafond (débit 1 par heure et plafond partagé, valeurs prudentes à revoir à la main).
     *
     * @return string 'ok' | 'serveur' | 'domaine' | 'flux' (flux différent de celui du domaine) | 'paquet' (refusé par le validateur)
     */
    public function preparerAdresseIp(string $ws, int $serveurId, int $domaineId, string $flux): string
    {
        $serveur = WorkspaceContext::run($ws, fn () => DB::table('envoi_serveurs')->where('workspace_id', $ws)->where('id', $serveurId)->exists());
        if (! $serveur) {
            return 'serveur';
        }
        $fluxDomaine = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->where('id', $domaineId)->first(['flux']));
        if ($fluxDomaine === null) {
            return 'domaine';
        }
        if ($fluxDomaine->flux !== $flux) {
            return 'flux';
        }
        $noms = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->pluck('nom')->map(fn ($n) => (string) $n)->all());
        $plafond = max(1, intdiv(min((int) config('envoi.plafond_jour'), (int) config('envoi.plafond_global_jour')), max(1, count($noms))));
        $parametres = array_fill_keys($noms, ['debit_heure' => 1, 'plafond_jour' => $plafond]);
        try {
            $paquet = $this->generateur->generer($ws, new SourceConfigurationDb($ws, $parametres));
            ValidateurPaquet::verifier($ws, $paquet->contenu);
        } catch (PaquetInvalide $e) {
            $this->versions->noter($ws, hash('sha256', 'r15:' . $e->getMessage()), StatutVersion::Invalide, $e->getMessage());

            return 'paquet';
        }
        $this->versions->noter($ws, $paquet->empreinte(), StatutVersion::Preparee, 'préparée (ajout d\'une adresse IP, flux ' . $flux . ') : à appliquer par un humain');

        return 'ok';
    }

    private function nomDomaine(string $ws, int $id): ?string
    {
        $nom = WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->where('workspace_id', $ws)->where('id', $id)->value('nom'));

        return $nom === null ? null : (string) $nom;
    }

    /**
     * Lignes du plan au format du contrat. `etat` : « absent » tant que rien n'est vérifié ; avec les résultats d'un contrôle,
     * absent (pas présent), different (présent mais un point en défaut) ou ok.
     *
     * @param  array<string, string>  $points
     * @return list<array<string, string>>
     */
    private function plan(ConfigurationDomaine $c, array $points): array
    {
        return array_map(function (EnregistrementDns $e) use ($points): array {
            $role = self::ROLES[$e->role] ?? 'spf';

            return ['role' => $role, 'type' => $e->type, 'nom' => $e->nom, 'valeur' => $e->valeurModele, 'etat' => $this->etat($role, $points)];
        }, PlanDns::enregistrements($c));
    }

    /** @param array<string, string> $points */
    private function etat(string $role, array $points): string
    {
        if ($points === []) {
            return 'absent';
        }
        $codes = self::POINTS[$role];
        if (($points[$codes[0]] ?? 'ko') === 'ko') {
            return 'absent';
        }
        foreach ($codes as $code) {
            if (($points[$code] ?? 'ko') !== 'ok') {
                return 'different';
            }
        }

        return 'ok';
    }
}
