<?php

namespace App\Console\Commands;

use App\Crm\Emails\CacheDomaines;
use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResolveurDnsUdp;
use App\Crm\Emails\Dns\ResultatDns;
use App\Crm\Emails\QualificationEmail;
use App\Crm\Emails\VerificationEmail;
use App\Crm\EspaceProspection;
use App\Crm\Joignabilite\Joignabilite;
use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * VÉRIFICATION DES E-MAILS — `companies.email_generic`, les adresses des
 * canaux (`signals.contact_channels`) et `contacts.email`.
 *
 * Pour chaque adresse : syntaxe ; domaine jetable ; le domaine reçoit-il du
 * courrier (MX, sinon A puis AAAA — résultat gardé PAR DOMAINE, daté, dans
 * `email_domaines`) ; webmail grand public (marqué, jamais rejeté) ; type
 * générique ou nominatif ; nombre de fiches qui la portent. Elle écrit un
 * statut, la date et le motif — vocabulaire et emplacements dans
 * `App\Crm\Emails\VerificationEmail`.
 *
 * ── Ce qu'elle ne fait JAMAIS ────────────────────────────────────────────
 *
 *  - aucun sondage SMTP (`RCPT TO`) : on demande au DNS si le domaine reçoit,
 *    jamais au serveur si la boîte existe — le sondage abîme la réputation de
 *    l'expéditeur, il est interdit ici ;
 *  - aucune adresse supprimée ni réécrite : `invalide`, elle reste sur sa
 *    fiche, lisible pour audit, avec son motif ;
 *  - aucun `invalide` sur une panne DNS : un délai dépassé ou un SERVFAIL
 *    laissent l'adresse telle quelle (compteur `indeterminees`).
 *
 * ── Ce qui la rend sûre sur ~1,5 M d'adresses ────────────────────────────
 *
 *  - PAR LOTS (`--lot`, 1 000 par défaut), chacun dans SA transaction courte
 *    (`lock_timeout` 5 s), SANS point de sauvegarde : les verrous tenus ne
 *    dépendent ni de la taille du lot ni du nombre de lots (le bilan les
 *    affiche). C'est ce qui a manqué à l'import des fédérations (#256,
 *    `max_locks_per_transaction`).
 *  - Curseur par identifiant, source par source (`entreprises` puis
 *    `contacts`) : interrompue, elle dit exactement où reprendre.
 *  - Idempotente : une fiche dont la vérification n'a pas changé n'est pas
 *    réécrite ; un domaine résolu il y a moins de `--revalider-apres` jours ne
 *    l'est pas deux fois.
 *  - Une fiche MODIFIÉE entre la lecture et l'écriture n'est pas écrasée
 *    (garde sur l'adresse, `email_status` et l'empreinte de `signals`) :
 *    comptée `modifiees_entre_temps`, la relance suffit.
 *  - `updated_at` n'est pas touché (`app.conserver_updated_at`) : vérifier
 *    une adresse n'est pas modifier la fiche.
 *  - Journalisée : une entrée de la chaîne d'audit par lot écrit, une entrée
 *    de fin même en cas d'échec ; aucune adresse dans le journal ni à
 *    l'écran — seulement des nombres.
 *
 * `--dry-run` interroge le DNS (lecture seule) et calcule TOUT avec le même
 * code, mais n'écrit RIEN — ni les fiches, ni le cache des domaines, ni
 * l'audit.
 *
 * Planification hebdomadaire : `routes/console.php`, FERMÉE par défaut
 * (`CRM_EMAILS_VERIFICATION_PLANIFIEE`).
 */
class CrmEmailsVerifier extends Command
{
    protected $signature = 'crm:emails:verifier
                            {--dry-run : Tout calculer (DNS compris, en lecture), ne RIEN écrire}
                            {--workspace= : Identifiant ou slug de l\'espace (défaut : l\'espace business des campagnes)}
                            {--source=toutes : entreprises | contacts | toutes}
                            {--lot=1000 : Fiches par lot (1 à 2000)}
                            {--depuis-id=0 : Reprendre APRÈS cette fiche, dans la PREMIÈRE source traitée}
                            {--max-lots=0 : S\'arrêter après N lots (0 = jusqu\'au bout)}
                            {--pause-ms=0 : Pause entre deux lots}
                            {--seulement-jamais-verifies : Ignorer les adresses que cette commande a déjà vérifiées}
                            {--revalider-apres= : Revérifier un domaine résolu il y a plus de N jours (défaut : configuration)}
                            {--resolveur= : Résolveur DNS, IP[:port] (défaut : configuration, sinon /etc/resolv.conf)}
                            {--dns-parallele= : Requêtes DNS en vol au plus}
                            {--dns-debit= : Requêtes DNS par seconde au plus}
                            {--dns-delai-ms= : Attente par requête DNS}
                            {--dns-essais= : Tentatives par requête DNS}';

    protected $description = 'Vérifie les e-mails (syntaxe, MX/A, jetables, webmails, type, partage) — sans sondage SMTP, sans rien supprimer.';

    public const SOURCES = ['entreprises', 'contacts'];

    private const LOT_MAX = 2000;

    private const TEMOIN_RECOIT = 'recoit';

    private const TEMOIN_NE_RECOIT_PAS = 'ne_recoit_pas';

    private const TEMOIN_INJOIGNABLE = 'injoignable';

    /** @var array<string, int> */
    private array $compteurs = [];

    /** @var array<string, array{resultat: ResultatDns, date: ?string, a: ?string}> domaine => ce qu'on en sait pour cette exécution */
    private array $dns = [];

    private int $verrousMax = 0;

    private int $verrousTransactionMax = 0;

    private bool $dryRun = false;

    /** L'univers de la liste de suppression (`business` | `vivier`), dérivé de l'espace vérifié. */
    private string $univers = 'business';

    private bool $seulementJamais = false;

    private int $revaliderApres = 30;

    private string $aujourdhui = '';

    private ResolveurDns $resolveur;

    /** Domaines gardés en mémoire au plus, le temps d'une exécution (relecture E5). */
    private int $memoireMax = 20000;

    /** Un domaine qui reçoit du courrier À COUP SÛR : il juge le résolveur (relecture E6). */
    private string $temoin = 'gmail.com';

    public function handle(AuditHashChain $audit): int
    {
        $designation = is_string($this->option('workspace')) && $this->option('workspace') !== ''
            ? $this->option('workspace')
            : (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = EspaceProspection::resoudre($designation);
        if ($workspaceId === null) {
            $this->error("Espace introuvable : « {$designation} ».");

            return self::FAILURE;
        }
        // La liste de suppression est partagée par univers, pas par espace :
        // l'espace du vivier lit `vivier`, tout autre espace `business`.
        $this->univers = DB::table('workspaces')->where('id', $workspaceId)->whereNull('deleted_at')->value('slug') === Taxonomy::VIVIER_WORKSPACE_SLUG
            ? 'vivier'
            : 'business';

        $source = (string) $this->option('source');
        if ($source !== 'toutes' && ! in_array($source, self::SOURCES, true)) {
            $this->error('--source : entreprises, contacts ou toutes.');

            return self::FAILURE;
        }
        $lot = (int) $this->option('lot');
        if ($lot < 1 || $lot > self::LOT_MAX) {
            $this->error('--lot : entre 1 et ' . self::LOT_MAX . '.');

            return self::FAILURE;
        }
        $jours = $this->option('revalider-apres');
        $this->revaliderApres = is_string($jours) && $jours !== ''
            ? (int) $jours
            : (int) config('crm.emails_verification.revalider_apres_jours', 30);
        if ($this->revaliderApres < 1) {
            $this->error('--revalider-apres : au moins 1 jour.');

            return self::FAILURE;
        }

        $resolveur = $this->construireResolveur();
        if (is_string($resolveur)) {
            $this->error($resolveur);

            return self::FAILURE;
        }
        $this->resolveur = $resolveur;
        $this->memoireMax = max(1, (int) config('crm.emails_verification.memoire_domaines', 20000));
        $this->temoin = strtolower(trim((string) config('crm.emails_verification.domaine_temoin', 'gmail.com')));

        // Un résolveur qui répondrait « n'existe pas » à tort ferait passer
        // des milliers d'adresses saines à `invalide` : on le JUGE d'abord, sur
        // un domaine qui reçoit du courrier à coup sûr. Rien n'est lu ni écrit
        // avant (relecture E6).
        $etats = $this->etatsTemoin();
        if ($etats !== [self::TEMOIN_RECOIT]) {
            $this->error("REFUS : le résolveur {$resolveur->nom()}, interrogé à deux reprises sur le domaine témoin « {$this->temoin} », "
                . self::decrireTemoin($etats) . '. Rien n’a été vérifié ni écrit. '
                . match (true) {
                    ! in_array(self::TEMOIN_NE_RECOIT_PAS, $etats, true) => 'Il est injoignable ou saturé : vérifier le réseau, ou changer de résolveur (--resolveur).',
                    ! in_array(self::TEMOIN_INJOIGNABLE, $etats, true) => 'Il se trompe : changer de résolveur (--resolveur) ou de témoin (CRM_EMAILS_DOMAINE_TEMOIN).',
                    default => 'Il est instable : changer de résolveur (--resolveur).',
                });

            return self::FAILURE;
        }

        $this->dryRun = (bool) $this->option('dry-run');
        $this->seulementJamais = (bool) $this->option('seulement-jamais-verifies');
        $this->aujourdhui = CarbonImmutable::now()->toDateString();
        $maxLots = max(0, (int) $this->option('max-lots'));
        $pauseMs = max(0, (int) $this->option('pause-ms'));
        $depuis = max(0, (int) $this->option('depuis-id'));
        $sources = $source === 'toutes' ? self::SOURCES : [$source];
        $operateur = self::operateur();
        $this->reinitialiser();

        $this->info(sprintf(
            '%s — sources %s, lots de %d, à partir de l\'id %d, domaines revérifiés après %d jours%s.',
            $this->dryRun ? '[À BLANC] rien ne sera écrit (le DNS est interrogé)' : 'Vérification des e-mails',
            implode(' puis ', $sources),
            $lot,
            $depuis,
            $this->revaliderApres,
            $this->seulementJamais ? ', adresses jamais vérifiées seulement' : '',
        ));

        $enCours = $sources[0];
        $dernier = $depuis;
        $termine = false;
        $erreur = null;
        $auditFinEchoue = null;
        $lots = 0;
        try {
            WorkspaceContext::run($workspaceId, function () use ($workspaceId, $sources, $lot, $maxLots, $pauseMs, $audit, $operateur, &$enCours, &$dernier, &$termine, &$erreur, &$lots): void {
                foreach ($sources as $rang => $src) {
                    $enCours = $src;
                    if ($rang > 0) {
                        $dernier = 0;
                    }
                    while (true) {
                        $fiches = $src === 'entreprises'
                            ? $this->lireEntreprises($workspaceId, $dernier, $lot)
                            : $this->lireContacts($workspaceId, $dernier, $lot);
                        if ($fiches === []) {
                            break;
                        }
                        $ids = array_map(static fn (stdClass $f): int => (int) $f->id, $fiches);
                        $bas = min($ids);
                        $haut = max($ids);
                        $avant = $this->compteurs;

                        try {
                            $tracer = function () use ($audit, $workspaceId, $operateur, $src, $bas, $haut, $avant): void {
                                $this->auditer($audit, $workspaceId, $operateur, 'VERIFICATION_EMAILS_LOT', 200, [
                                    'source' => $src,
                                    'ids' => [$bas, $haut],
                                    'fiches_modifiees' => $this->compteurs['fiches_modifiees'] - $avant['fiches_modifiees'],
                                    'contacts_modifies' => $this->compteurs['contacts_modifies'] - $avant['contacts_modifies'],
                                ], "{$src} ids {$bas}-{$haut}");
                            };
                            $src === 'entreprises'
                                ? $this->traiterEntreprises($workspaceId, $fiches, $tracer)
                                : $this->traiterContacts($workspaceId, $fiches, $tracer);
                        } catch (Throwable $e) {
                            // Le lot est annulé en entier ; tout ce qui précède
                            // est acquis. Seul le code d'état part au journal :
                            // le message SQL peut citer des adresses.
                            $erreur = match (true) {
                                $e instanceof QueryException => 'SQLSTATE ' . $e->getCode(),
                                str_starts_with($e->getMessage(), 'resolveur_suspect:') => 'résolveur suspect sur le domaine témoin : ' . substr($e->getMessage(), strlen('resolveur_suspect:')),
                                default => get_class($e),
                            };
                            Log::error('crm:emails:verifier : lot annulé', ['source' => $src, 'apres_id' => $dernier, 'erreur' => $erreur]);
                            $this->compteurs = $avant;

                            return;
                        }

                        $lots++;
                        $dernier = $haut;
                        $this->compteurs['lots']++;
                        $this->line(sprintf(
                            '  %s, lot %d : ids %d à %d — %d valides, %d invalides, %d jetables, %d indéterminées',
                            $src,
                            $lots,
                            $bas,
                            $haut,
                            $this->compteurs['valides'] - $avant['valides'],
                            $this->compteurs['invalides'] - $avant['invalides'],
                            $this->compteurs['jetables'] - $avant['jetables'],
                            $this->compteurs['indeterminees'] - $avant['indeterminees'],
                        ));
                        Log::info('crm:emails:verifier lot', ['a_blanc' => $this->dryRun, 'source' => $src, 'ids' => [$bas, $haut]]);

                        if ($maxLots > 0 && $lots >= $maxLots) {
                            return;
                        }
                        if ($pauseMs > 0) {
                            usleep($pauseMs * 1000);
                        }
                    }
                }
                $termine = true;
            });
        } catch (Throwable $e) {
            $erreur ??= get_class($e);

            throw $e;
        } finally {
            if (! $this->dryRun) {
                try {
                    $this->auditer($audit, $workspaceId, $operateur, 'VERIFICATION_EMAILS_FIN', $erreur === null ? 200 : 500, [
                        'termine' => $termine, 'source' => $enCours, 'dernier_id' => $dernier, 'erreur' => $erreur, 'compteurs' => $this->compteurs,
                    ], $termine ? 'terminé' : "arrêté dans {$enCours} après l'id {$dernier}");
                } catch (Throwable $e) {
                    $auditFinEchoue = get_class($e);
                    Log::error('crm:emails:verifier : entrée d\'audit de fin NON écrite', ['erreur' => $auditFinEchoue]);
                }
            }
        }

        $this->afficherBilan();
        Log::info('crm:emails:verifier fin', [
            'a_blanc' => $this->dryRun, 'termine' => $termine, 'source' => $enCours, 'dernier_id' => $dernier,
            'erreur' => $erreur, 'compteurs' => $this->compteurs, 'operateur' => $operateur,
        ]);

        // `--depuis-id` vaut pour la PREMIÈRE source traitée : arrêtée dans les
        // entreprises, une exécution « toutes » reprend par « toutes ».
        $reprise = '--source=' . ($enCours === $sources[0] ? $source : $enCours) . " --depuis-id={$dernier}";
        if ($erreur !== null) {
            $this->error("ÉCHEC : un lot a été annulé ({$erreur}). Rien n'a été écrit pour ce lot ; tout ce qui précède est acquis et journalisé.");
            $this->error("Reprendre avec : {$reprise}");

            return self::FAILURE;
        }
        if ($auditFinEchoue !== null) {
            $this->error("ÉCHEC : l'entrée d'audit de fin n'a pas pu être écrite ({$auditFinEchoue}).");

            return self::FAILURE;
        }
        if (! $termine) {
            $this->warn("Arrêt demandé après {$this->compteurs['lots']} lot(s). Reprendre avec : {$reprise}");
        } else {
            $this->info($this->dryRun ? '[À BLANC] terminé : rien n\'a été écrit.' : 'Vérification terminée.');
        }

        return self::SUCCESS;
    }

    /**
     * Le résolveur : celui que le conteneur fournit s'il y en a un (la suite de
     * tests installe le sien, et REFUSE tout réseau sinon — cf.
     * `AppServiceProvider`), sinon le résolveur UDP réglé par les options et
     * la configuration.
     */
    private function construireResolveur(): ResolveurDns|string
    {
        if (app()->bound(ResolveurDns::class)) {
            return app(ResolveurDns::class);
        }
        $reglage = static function (mixed $option, string $cle, int $defaut): int {
            return is_string($option) && $option !== '' ? (int) $option : (int) config('crm.emails_verification.' . $cle, $defaut);
        };
        $designe = is_string($this->option('resolveur')) ? $this->option('resolveur') : (string) config('crm.emails_verification.resolveur', '');
        $serveur = ResolveurDnsUdp::serveurParDefaut($designe);
        if ($serveur === null) {
            return 'Aucun résolveur DNS connu : --resolveur=IP[:port], ou CRM_EMAILS_DNS_RESOLVEUR.';
        }
        try {
            return new ResolveurDnsUdp(
                $serveur,
                $reglage($this->option('dns-parallele'), 'parallele', 32),
                $reglage($this->option('dns-debit'), 'debit', 100),
                $reglage($this->option('dns-delai-ms'), 'delai_ms', 3000),
                $reglage($this->option('dns-essais'), 'essais', 2),
            );
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    private function reinitialiser(): void
    {
        $this->dns = [];
        $this->verrousMax = 0;
        $this->verrousTransactionMax = 0;
        $this->compteurs = array_fill_keys([
            'lots', 'entreprises_lues', 'contacts_lus', 'adresses_verifiees',
            'valides', 'invalides', 'jetables', 'indeterminees',
            'motif_mx', 'motif_a', 'motif_mx_nul', 'motif_sans_courrier', 'motif_inexistant', 'motif_syntaxe', 'motif_jetable',
            'webmails', 'generiques', 'nominatives', 'partagees', 'deja_verifiees_ignorees', 'canaux_illisibles',
            'domaines_du_cache', 'domaines_resolus', 'domaines_indetermines', 'memoire_dns_videe', 'resolveur_rejuge',
            'fiches_a_modifier', 'fiches_modifiees', 'contacts_a_modifier', 'contacts_modifies',
            'statuts_contacts_changes', 'modifiees_entre_temps', 'joignabilites_recalculees',
        ], 0);
    }

    // ── Lecture ──────────────────────────────────────────────────────────────

    /** @return list<stdClass> */
    private function lireEntreprises(string $workspaceId, int $apresId, int $taille): array
    {
        return $this->objets(DB::select(
            "SELECT c.id, c.email_generic,
                    c.signals -> 'email_generic_verification' AS verif_generique,
                    c.signals -> 'contact_channels' AS canaux,
                    md5(coalesce(c.signals::text, '')) AS empreinte_signaux
             FROM companies c
             WHERE c.workspace_id = ? AND c.id > ? AND c.deleted_at IS NULL
               AND ((c.email_generic IS NOT NULL AND c.email_generic <> '') OR jsonb_exists(c.signals, 'contact_channels'))
             ORDER BY c.id
             LIMIT " . $taille,
            [$workspaceId, $apresId],
        ));
    }

    /** @return list<stdClass> */
    private function lireContacts(string $workspaceId, int $apresId, int $taille): array
    {
        return $this->objets(DB::select(
            "SELECT ct.id, lower(btrim(ct.email::text)) AS adresse_lue, ct.email_status AS statut_lu,
                    ct.metadata -> 'email_verification' AS verif_lue,
                    ct.metadata ->> 'email_type' AS type_lu,
                    ct.metadata -> 'domaine_verifie' AS verifie_lu,
                    ct.metadata ->> 'domaine_verifie_le' AS verifie_le_lu
             FROM contacts ct
             WHERE ct.workspace_id = ? AND ct.id > ? AND ct.deleted_at IS NULL
               AND ct.email IS NOT NULL AND ct.email <> ''
             ORDER BY ct.id
             LIMIT " . $taille,
            [$workspaceId, $apresId],
        ));
    }

    /**
     * @param  array<int, mixed>  $lignes
     * @return list<stdClass>
     */
    private function objets(array $lignes): array
    {
        return array_values(array_filter($lignes, static fn (mixed $l): bool => $l instanceof stdClass));
    }

    // ── Entreprises : e-mail générique et canaux ────────────────────────────

    /** @param  list<stdClass>  $fiches */
    private function traiterEntreprises(string $workspaceId, array $fiches, \Closure $tracer): void
    {
        /** @var list<array{fiche: stdClass, generique: ?string, gen_avant: mixed, canaux: list<string>, cles: array<string, string>, details: array<string, mixed>}> $plans */
        $plans = [];
        $adresses = [];
        foreach ($fiches as $f) {
            $this->compteurs['entreprises_lues']++;
            $generique = is_string($f->email_generic) && trim($f->email_generic) !== '' ? QualificationEmail::normaliser($f->email_generic) : null;
            $genAvant = self::json($f->verif_generique);
            if ($generique !== null && $this->seulementJamais && VerificationEmail::statutDe($genAvant, $generique) !== null) {
                $this->compteurs['deja_verifiees_ignorees']++;
                $generique = null;
            }

            [$canaux, $cles, $details] = $this->canaux($f->canaux);
            if ($this->seulementJamais) {
                $canaux = array_values(array_filter($canaux, function (string $a) use ($details, $cles): bool {
                    $deja = VerificationEmail::statutDe($details[$cles[$a] ?? $a] ?? null, $a) !== null;
                    if ($deja) {
                        $this->compteurs['deja_verifiees_ignorees']++;
                    }

                    return ! $deja;
                }));
            }
            if ($generique === null && $canaux === []) {
                continue;
            }
            $plans[] = ['fiche' => $f, 'generique' => $generique, 'gen_avant' => $genAvant, 'canaux' => $canaux, 'cles' => $cles, 'details' => $details];
            if ($generique !== null) {
                $adresses[] = $generique;
            }
            array_push($adresses, ...$canaux);
        }
        $adresses = array_values(array_unique($adresses));
        $this->preparerDns($adresses);
        $partage = $this->fichesParAdresse($workspaceId, $adresses);

        $aEcrire = [];
        foreach ($plans as $p) {
            $gen = null;
            if ($p['generique'] !== null) {
                $nouvelle = $this->ficheDeVerification($p['generique'], $p['gen_avant'], $partage);
                if ($nouvelle !== null && VerificationEmail::change($p['gen_avant'], $nouvelle)) {
                    $gen = $nouvelle;
                }
            }
            $det = [];
            foreach ($p['canaux'] as $a) {
                $cle = $p['cles'][$a] ?? $a;
                $avant = $p['details'][$cle] ?? null;
                $nouvelle = $this->ficheDeVerification($a, $avant, $partage);
                if ($nouvelle !== null && VerificationEmail::change($avant, $nouvelle)) {
                    $det[$cle] = $nouvelle;
                }
            }
            if ($gen !== null || $det !== []) {
                $this->compteurs['fiches_a_modifier']++;
                $aEcrire[] = [
                    (int) $p['fiche']->id,
                    (string) $p['fiche']->empreinte_signaux,
                    $gen === null ? null : json_encode($gen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    $det === [] ? null : json_encode($det, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        // Rien à écrire (lot entièrement ignoré par `--seulement-jamais-verifies`,
        // ou déjà juste) : ni transaction, ni entrée d'audit vide.
        if ($this->dryRun || $aEcrire === []) {
            return;
        }
        $this->ecrireDansUnLot(function () use ($workspaceId, $aEcrire): void {
            $avecGen = "CASE WHEN v.gen IS NULL THEN coalesce(c.signals, '{}'::jsonb)
                             ELSE jsonb_set(coalesce(c.signals, '{}'::jsonb), '{email_generic_verification}', v.gen, true) END";
            $liaisons = [];
            foreach ($aEcrire as $l) {
                array_push($liaisons, ...$l);
            }
            $liaisons[] = $workspaceId;
            $ecrites = DB::select(
                "UPDATE companies AS c
                 SET signals = CASE WHEN v.det IS NULL THEN {$avecGen}
                                    ELSE jsonb_set({$avecGen}, '{contact_channels,details}',
                                                   coalesce(({$avecGen}) -> 'contact_channels' -> 'details', '{}'::jsonb) || v.det, true) END
                 FROM (VALUES " . implode(', ', array_fill(0, count($aEcrire), '(?::bigint, ?::text, ?::jsonb, ?::jsonb)')) . ') AS v(fiche_id, empreinte_lue, gen, det)
                 WHERE c.id = v.fiche_id AND c.workspace_id = ? AND c.deleted_at IS NULL
                   AND md5(coalesce(c.signals::text, \'\')) = v.empreinte_lue
                 RETURNING c.id',
                $liaisons,
                false,
            );
            $this->compteurs['fiches_modifiees'] += count($ecrites);
            $this->compteurs['modifiees_entre_temps'] += count($aEcrire) - count($ecrites);
            // Chantier D : la joignabilité des fiches dont la vérification vient
            // de changer, recalculée DANS la transaction du lot.
            $this->recalculerJoignabilite($workspaceId, array_map(static fn (mixed $l): int => $l instanceof stdClass ? (int) $l->id : 0, $ecrites));
        }, $tracer);
    }

    /**
     * Les adresses des canaux : la liste `emails` et les clés de `details`.
     * `cles` rend, pour chaque adresse normalisée, la clé EXACTE sous laquelle
     * `details` la range déjà — on n'en crée jamais une seconde.
     *
     * @return array{0: list<string>, 1: array<string, string>, 2: array<string, mixed>}
     */
    private function canaux(mixed $brut): array
    {
        $canaux = self::json($brut);
        if (! is_array($canaux) || array_is_list($canaux)) {
            return [[], [], []];
        }
        $details = $canaux['details'] ?? [];
        if (! is_array($details) || ($details !== [] && array_is_list($details))) {
            // Un `details` qui n'est pas un objet : on n'y écrit pas.
            $this->compteurs['canaux_illisibles']++;

            return [[], [], []];
        }
        $cles = [];
        foreach (array_keys($details) as $cle) {
            $cles[QualificationEmail::normaliser((string) $cle)] = (string) $cle;
        }
        $adresses = array_keys($cles);
        foreach (is_array($canaux['emails'] ?? null) ? $canaux['emails'] : [] as $e) {
            if (is_string($e) && trim($e) !== '') {
                $adresses[] = QualificationEmail::normaliser($e);
            }
        }

        return [array_values(array_unique($adresses)), $cles, $details];
    }

    // ── Contacts ─────────────────────────────────────────────────────────────

    /** @param  list<stdClass>  $fiches */
    private function traiterContacts(string $workspaceId, array $fiches, \Closure $tracer): void
    {
        // `--seulement-jamais-verifies` : l'empreinte est un HMAC à clé
        // applicative — le filtre se fait donc ici, pas en SQL.
        $retenues = [];
        foreach ($fiches as $f) {
            if ($this->seulementJamais && VerificationEmail::statutDe(self::json($f->verif_lue), (string) $f->adresse_lue) !== null) {
                $this->compteurs['deja_verifiees_ignorees']++;

                continue;
            }
            $this->compteurs['contacts_lus']++;
            $retenues[] = $f;
        }
        $adresses = array_values(array_unique(array_map(static fn (stdClass $f): string => (string) $f->adresse_lue, $retenues)));
        // Les rebonds durs du lot, en UNE requête — lus avant le DNS ; l'UPDATE
        // les relit (`NOT EXISTS`) pour fermer la course jusqu'à l'écriture.
        $rebonds = $this->enRebondDur($adresses);
        $this->preparerDns($adresses);
        $partage = $this->fichesParAdresse($workspaceId, $adresses);

        $aEcrire = ['avec_statut' => [], 'sans_statut' => []];
        foreach ($retenues as $f) {
            $adresse = (string) $f->adresse_lue;
            $avant = self::json($f->verif_lue);
            $calculee = $this->ficheDeVerification($adresse, $avant, $partage, is_string($f->type_lu) ? $f->type_lu : null);
            if ($calculee === null) {
                continue;
            }
            $statutLu = is_string($f->statut_lu) ? $f->statut_lu : null;
            $issue = VerificationEmail::statutContact(
                $statutLu,
                (string) $calculee['statut'],
                $avant,
                $adresse,
                // Le rebond dur SEUL retient la réversion (voir `statutContact`).
                isset($rebonds[ListeSuppression::empreinte($adresse)]),
            );
            $statut = $issue['statut'];
            // Ce qu'il faudra rétablir si le domaine revient (relecture E1).
            $calculee['email_status_avant'] = $issue['avant'];
            $calculee['email_status_pose'] = $issue['pose'];
            $verifieLe = $calculee['domaine_verifie'] === true ? (string) $calculee['verifie_le'] : null;
            // Les clés de l'import des fédérations (#255), tenues à jour.
            $miroir = [
                'email_type' => is_string($f->type_lu) && $f->type_lu !== '' ? $f->type_lu : $calculee['type'],
                'domaine_verifie' => $calculee['domaine_verifie'],
                'domaine_verifie_le' => $verifieLe,
            ];
            $miroirChange = $miroir['email_type'] !== $f->type_lu
                || self::json($f->verifie_lu) !== $miroir['domaine_verifie']
                || $f->verifie_le_lu !== $miroir['domaine_verifie_le'];
            if (! VerificationEmail::change($avant, $calculee) && ! $miroirChange && $statut === $statutLu) {
                continue;
            }
            $this->compteurs['contacts_a_modifier']++;
            $patch = json_encode($miroir + ['email_verification' => $calculee], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $ligne = [(int) $f->id, $patch, $this->horodatage($adresse), $adresse, $statutLu];
            if ($statut !== $statutLu) {
                $ligne[] = $statut;
                $ligne[] = ListeSuppression::empreinte($adresse);
                $aEcrire['avec_statut'][] = $ligne;
            } else {
                $aEcrire['sans_statut'][] = $ligne;
            }
        }

        if ($this->dryRun) {
            $this->compteurs['statuts_contacts_changes'] += count($aEcrire['avec_statut']);

            return;
        }
        // Rien à écrire : ni transaction, ni entrée d'audit vide.
        if ($aEcrire['avec_statut'] === [] && $aEcrire['sans_statut'] === []) {
            return;
        }
        $this->ecrireDansUnLot(function () use ($workspaceId, $aEcrire): void {
            // DEUX requêtes : citer `email_status` dans un UPDATE déclenche le
            // recalcul du score de la fiche (`contacts_recompute_score`) — on
            // ne le cite que là où il change vraiment.
            foreach ($aEcrire as $famille => $lignes) {
                if ($lignes === []) {
                    continue;
                }
                $avecStatut = $famille === 'avec_statut';
                $gabarit = $avecStatut ? '(?::bigint, ?::jsonb, ?::timestamptz, ?::text, ?::text, ?::text, ?::text)' : '(?::bigint, ?::jsonb, ?::timestamptz, ?::text, ?::text)';
                $liaisons = [];
                foreach ($lignes as $l) {
                    array_push($liaisons, ...$l);
                }
                $liaisons[] = $workspaceId;
                // Relue DANS la transaction du lot (3e relecture, X3) : un rebond
                // dur arrivé depuis la lecture interdit encore la réversion.
                // `coalesce` : un statut NULL rendrait tout le `NOT (…)` NULL, et
                // la fiche serait écartée à chaque passage (4e relecture).
                $gardeRebond = '';
                if ($avecStatut) {
                    $gardeRebond = "
                       AND NOT (coalesce(c.email_status, '') IN ('invalid', 'disposable')
                                AND coalesce(v.statut_nouveau, '') NOT IN ('invalid', 'disposable')
                                AND EXISTS (SELECT 1 FROM email_suppressions s
                                            WHERE s.scope = ? AND s.reason = ? AND s.email_hash = v.empreinte_suppression))";
                    array_push($liaisons, $this->univers, ListeSuppression::REBOND_DUR);
                }
                $ecrites = DB::select(
                    "UPDATE contacts AS c
                     SET metadata = coalesce(c.metadata, '{}'::jsonb) || v.patch,
                         last_verified_at = v.verifie_a" . ($avecStatut ? ', email_status = v.statut_nouveau' : '') . '
                     FROM (VALUES ' . implode(', ', array_fill(0, count($lignes), $gabarit)) . ') AS v(fiche_id, patch, verifie_a, adresse_attendue, statut_attendu' . ($avecStatut ? ', statut_nouveau, empreinte_suppression' : '') . ')
                     WHERE c.id = v.fiche_id AND c.workspace_id = ? AND c.deleted_at IS NULL
                       AND lower(btrim(c.email::text)) = v.adresse_attendue
                       AND c.email_status IS NOT DISTINCT FROM v.statut_attendu' . $gardeRebond . '
                     RETURNING c.id, c.company_id',
                    $liaisons,
                    false,
                );
                $this->compteurs['contacts_modifies'] += count($ecrites);
                // Chantier D : la personne ET sa fiche (son état dépend de ses personnes).
                $this->recalculerJoignabilite($workspaceId, array_map(static fn (mixed $l): int => $l instanceof stdClass ? (int) $l->company_id : 0, $ecrites));
                $this->compteurs['modifiees_entre_temps'] += count($lignes) - count($ecrites);
                if ($avecStatut) {
                    $this->compteurs['statuts_contacts_changes'] += count($ecrites);
                }
            }
        }, $tracer);
    }

    // ── Commun ───────────────────────────────────────────────────────────────

    /**
     * Recalcule la joignabilité (`App\Crm\Joignabilite\Joignabilite`) des
     * fiches données et de leurs personnes — appelée DANS la transaction du
     * lot, après l'écriture : un statut qui change change l'état.
     *
     * @param  list<int>  $ids
     */
    private function recalculerJoignabilite(string $workspaceId, array $ids): void
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        $ecrites = Joignabilite::recalculer($workspaceId, $ids);
        $this->compteurs['joignabilites_recalculees'] += $ecrites['entreprises'] + $ecrites['personnes'];
    }

    /**
     * La fiche de vérification d'une adresse, comptée dans le bilan ; null si
     * le DNS n'a pas permis de conclure (rien ne sera écrit pour elle).
     *
     * @param  array<string, int>  $partage
     * @return array<string, mixed>|null
     */
    private function ficheDeVerification(string $adresse, mixed $avant, array $partage, ?string $typeConnu = null): ?array
    {
        $domaine = QualificationEmail::domaine($adresse);
        $dns = $domaine !== null && VerificationEmail::demandeLeDns($adresse) ? ($this->dns[$domaine] ?? null) : null;
        $verdict = VerificationEmail::conclure($adresse, $dns['resultat'] ?? null);
        if ($verdict === null) {
            $this->compteurs['indeterminees']++;

            return null;
        }
        $this->compteurs['adresses_verifiees']++;
        $this->compteurs[match ($verdict['statut']) {
            VerificationEmail::VALIDE => 'valides',
            VerificationEmail::JETABLE => 'jetables',
            default => 'invalides',
        }]++;
        $this->compteurs['motif_' . $verdict['motif']]++;

        $webmail = $domaine !== null && QualificationEmail::estWebmail($domaine);
        $type = in_array($typeConnu, ['generique', 'nominatif'], true) ? $typeConnu : QualificationEmail::type($adresse);
        $fiches = max(1, $partage[$adresse] ?? 1);
        $nouvelle = VerificationEmail::fusionner($avant, [
            'type' => $type,
            'domaine_verifie' => $verdict['domaine_verifie'],
            'verifie_le' => VerificationEmail::date($avant, $verdict, $dns['date'] ?? null, $this->aujourdhui),
            'statut' => $verdict['statut'],
            'motif' => $verdict['motif'],
            'webmail' => $webmail,
            'fiches' => $fiches,
            'empreinte' => VerificationEmail::empreinte($adresse),
        ]);
        $this->compteurs[$nouvelle['type'] === 'generique' ? 'generiques' : 'nominatives']++;
        if ($webmail) {
            $this->compteurs['webmails']++;
        }
        if ($fiches > 1) {
            $this->compteurs['partagees']++;
        }

        return $nouvelle;
    }

    /** L'instant de la vérification d'une adresse : la résolution de son domaine, sinon maintenant. */
    private function horodatage(string $adresse): string
    {
        $domaine = QualificationEmail::domaine($adresse);

        return ($domaine !== null ? ($this->dns[$domaine]['a'] ?? null) : null) ?? CarbonImmutable::now()->toIso8601String();
    }

    /**
     * Ce que l'on sait du domaine de chacune de ces adresses : la mémoire de
     * l'exécution, puis le cache (`email_domaines`, moins de N jours), puis
     * le DNS — en UNE demande pour tout le lot, parallèle et à débit limité.
     *
     * @param  list<string>  $adresses
     */
    private function preparerDns(array $adresses): void
    {
        // Mémoire BORNÉE : au-delà de `memoire_domaines`, on repart à vide —
        // le cache en base (`email_domaines`) prend le relais, sans DNS.
        if (count($this->dns) > $this->memoireMax) {
            $this->dns = [];
            $this->compteurs['memoire_dns_videe']++;
        }
        $domaines = [];
        foreach ($adresses as $a) {
            $d = QualificationEmail::domaine($a);
            if ($d !== null && VerificationEmail::demandeLeDns($a) && ! isset($this->dns[$d])) {
                $domaines[$d] = true;
            }
        }
        $domaines = array_keys($domaines);
        if ($domaines === []) {
            return;
        }
        foreach (CacheDomaines::fraiches($domaines, $this->revaliderApres) as $d => $connu) {
            $this->dns[$d] = [
                'resultat' => $connu['resultat'],
                'date' => $connu['resolu_le']->toDateString(),
                'a' => $connu['resolu_le']->toIso8601String(),
            ];
            $this->compteurs['domaines_du_cache']++;
        }
        $aResoudre = array_values(array_filter($domaines, fn (string $d): bool => ! isset($this->dns[$d])));
        if ($aResoudre === []) {
            return;
        }
        $maintenant = CarbonImmutable::now();
        $resultats = $this->resolveur->resoudre($aResoudre);
        foreach ($aResoudre as $d) {
            $r = $resultats[$d] ?? new ResultatDns(ResultatDns::INDETERMINE);
            $resultats[$d] = $r;
            $indetermine = $r->verdict === ResultatDns::INDETERMINE;
            $this->dns[$d] = [
                'resultat' => $r,
                'date' => $indetermine ? null : $maintenant->toDateString(),
                'a' => $indetermine ? null : $maintenant->toIso8601String(),
            ];
            $this->compteurs[$indetermine ? 'domaines_indetermines' : 'domaines_resolus']++;
        }
        // AUCUN verdict NÉGATIF (`inexistant`, `sans_courrier`, `mx_nul`)
        // n'entre au cache, ni sur une fiche, sans que le résolveur ait été
        // rejugé sur le témoin DANS CE LOT (2e relecture, E6 élargi) : pas de
        // seuil de proportion ni de nombre — un petit lot comme un grand, et
        // quel que soit le type de réponse négative. Coût : une question DNS
        // par lot qui en contient. S'il se trompe, le lot est annulé (rien
        // d'écrit, ni fiche ni cache) et la commande dit où reprendre.
        $negatifs = count(array_filter($aResoudre, static fn (string $d): bool => $resultats[$d]->recoit() === false));
        if ($negatifs > 0) {
            $this->compteurs['resolveur_rejuge']++;
            $etats = $this->etatsTemoin();
            if ($etats !== [self::TEMOIN_RECOIT]) {
                throw new \RuntimeException('resolveur_suspect:' . self::decrireTemoin($etats));
            }
        }
        if (! $this->dryRun) {
            CacheDomaines::enregistrer($resultats, $this->resolveur->nom(), $maintenant);
        }
    }

    /**
     * Les empreintes des adresses en REBOND DUR dans la liste de suppression
     * de l'univers, en une requête (servie par l'index unique scope + empreinte).
     *
     * @param  list<string>  $adresses
     * @return array<string, true>
     */
    private function enRebondDur(array $adresses): array
    {
        if ($adresses === []) {
            return [];
        }
        $empreintes = array_values(array_unique(array_map(static fn (string $a): string => ListeSuppression::empreinte($a), $adresses)));
        $lignes = DB::table('email_suppressions')
            ->where('scope', $this->univers)
            ->where('reason', ListeSuppression::REBOND_DUR)
            ->whereRaw('email_hash = ANY(?::text[])', ['{' . implode(',', $empreintes) . '}'])
            ->pluck('email_hash');
        $rebonds = [];
        foreach ($lignes as $h) {
            $rebonds[(string) $h] = true;
        }

        return $rebonds;
    }

    /**
     * Ce que le résolveur dit du témoin, avec UN nouvel essai avant de
     * conclure (3e relecture) : une réponse perdue ne suffit pas à juger le
     * résolveur. Rend `[recoit]` dès qu'un essai aboutit ; sinon les DEUX
     * états observés, dans l'ordre (4e relecture : le message ne doit pas
     * dire « deux fois » quand les deux essais ont divergé). Les réponses ne
     * sont ni gardées ni enregistrées.
     *
     * @return list<string>
     */
    private function etatsTemoin(): array
    {
        $etats = [];
        for ($essai = 1; $essai <= 2; $essai++) {
            $r = $this->resolveur->resoudre([$this->temoin])[$this->temoin] ?? null;
            $recoit = $r?->recoit();
            if ($recoit === true) {
                return [self::TEMOIN_RECOIT];
            }
            $etats[] = $recoit === false ? self::TEMOIN_NE_RECOIT_PAS : self::TEMOIN_INJOIGNABLE;
        }

        return $etats;
    }

    /** @param  list<string>  $etats */
    private static function decrireTemoin(array $etats): string
    {
        $dire = static fn (string $e): string => $e === self::TEMOIN_INJOIGNABLE
            ? 'pas de réponse (délai dépassé)'
            : 'réponse « ne reçoit pas de courrier »';
        if (count(array_unique($etats)) === 1) {
            return $dire($etats[0]) . ', deux fois';
        }

        return implode(', puis ', array_map($dire, $etats));
    }

    /**
     * Combien de fiches de l'espace portent chaque adresse : organisations
     * (e-mail générique OU canaux, une fois par organisation) et personnes.
     * Chaque requête reprend l'expression et le prédicat de son index
     * (`idx_companies_email_generic_minuscules`, `idx_companies_canaux_emails`,
     * `idx_contacts_email`).
     *
     * @param  list<string>  $adresses
     * @return array<string, int>
     */
    private function fichesParAdresse(string $workspaceId, array $adresses): array
    {
        $compte = [];
        foreach (array_chunk($adresses, 500) as $paquet) {
            $tableau = 'ARRAY[' . implode(', ', array_fill(0, count($paquet), '?')) . ']';
            $organisations = DB::select(
                "SELECT orgs.adresse_cle, count(*) AS n_fiches
                 FROM (
                     SELECT lower(co.email_generic) AS adresse_cle, co.id AS fiche
                     FROM companies co
                     WHERE co.workspace_id = ? AND co.deleted_at IS NULL AND co.email_generic IS NOT NULL
                       AND lower(co.email_generic) = ANY({$tableau}::text[])
                     UNION
                     SELECT e.valeur AS adresse_cle, co.id AS fiche
                     FROM companies co
                     CROSS JOIN LATERAL unnest(canaux_emails(co.signals)) AS e(valeur)
                     WHERE co.workspace_id = ? AND co.deleted_at IS NULL
                       AND jsonb_exists(co.signals, 'contact_channels')
                       AND canaux_emails(co.signals) && {$tableau}::text[]
                       AND e.valeur = ANY({$tableau}::text[])
                 ) orgs
                 GROUP BY orgs.adresse_cle",
                array_merge([$workspaceId], $paquet, [$workspaceId], $paquet, $paquet),
            );
            $personnes = DB::select(
                "SELECT lower(ct.email::text) AS adresse_cle, count(*) AS n_fiches
                 FROM contacts ct
                 WHERE ct.workspace_id = ? AND ct.deleted_at IS NULL AND ct.email IS NOT NULL
                   AND ct.email = ANY({$tableau}::citext[])
                 GROUP BY lower(ct.email::text)",
                array_merge([$workspaceId], $paquet),
            );
            foreach ([...$organisations, ...$personnes] as $l) {
                if ($l instanceof stdClass) {
                    $cle = (string) $l->adresse_cle;
                    $compte[$cle] = ($compte[$cle] ?? 0) + (int) $l->n_fiches;
                }
            }
        }

        return $compte;
    }

    /**
     * UNE transaction courte par lot, SANS point de sauvegarde : c'est ce qui
     * borne les verrous. L'entrée d'audit est écrite DANS la transaction, en
     * dernier : un lot validé a toujours sa trace.
     */
    private function ecrireDansUnLot(\Closure $ecrire, \Closure $tracer): void
    {
        DB::transaction(function () use ($ecrire, $tracer): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
            $ecrire();
            $tracer();
            $verrous = DB::selectOne(
                "SELECT count(*) AS n_verrous, count(*) FILTER (WHERE locktype = 'transactionid') AS n_transactions
                 FROM pg_locks WHERE pid = pg_backend_pid()",
            );
            if ($verrous instanceof stdClass) {
                $this->verrousMax = max($this->verrousMax, (int) $verrous->n_verrous);
                $this->verrousTransactionMax = max($this->verrousTransactionMax, (int) $verrous->n_transactions);
            }
        });
    }

    private static function json(mixed $valeur): mixed
    {
        if (! is_string($valeur)) {
            return $valeur;
        }

        return json_decode($valeur, true);
    }

    /** « utilisateur@hôte » du processus qui a lancé la commande. */
    private static function operateur(): string
    {
        $utilisateur = get_current_user();
        $hote = gethostname();

        return ($utilisateur !== '' ? $utilisateur : '?') . '@' . ($hote !== false ? $hote : '?');
    }

    /** @param  array<string, mixed>  $details */
    private function auditer(AuditHashChain $audit, string $workspaceId, string $operateur, string $evenement, int $statut, array $details, string $resume): void
    {
        $audit->record([
            'workspace_id' => $workspaceId,
            'user_id' => null,
            'method' => $evenement,
            'path' => 'artisan crm:emails:verifier — ' . $resume,
            'status' => $statut,
            'ip' => null,
            'user_agent' => 'cli ' . $operateur,
            'payload_hash' => hash('sha256', json_encode($details, JSON_THROW_ON_ERROR)),
        ]);
    }

    private function afficherBilan(): void
    {
        $this->newLine();
        $this->info($this->dryRun ? '═══ BILAN DE L\'ESSAI À BLANC (rien n\'a été écrit) ═══' : '═══ BILAN DE LA VÉRIFICATION ═══');
        $compteurs = $this->compteurs;
        if ($this->dryRun) {
            unset($compteurs['fiches_modifiees'], $compteurs['contacts_modifies'], $compteurs['modifiees_entre_temps']);
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($compteurs),
            array_values($compteurs),
        ));
        if (! $this->dryRun) {
            $this->line("Verrous tenus au plus en fin de lot : {$this->verrousMax} (dont {$this->verrousTransactionMax} d'identifiants de transaction).");
        }
        $this->line('Résolveur : ' . $this->resolveur->nom() . '. Aucun sondage SMTP. Aucune adresse supprimée.');
    }
}
