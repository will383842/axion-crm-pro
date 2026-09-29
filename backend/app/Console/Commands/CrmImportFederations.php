<?php

namespace App\Console\Commands;

use App\Crm\Doublons\FusionFiches;
use App\Crm\Federations\EtiquettesFederation;
use App\Crm\Referentiels\Classement;
use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestOutcome;
use App\Crm\Scraping\ScrapeIngestRejection;
use App\Crm\Taxonomy;
use App\Models\Company;
use App\Services\Audit\AuditHashChain;
use App\Services\Tags\AutoTaggerService;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Importe les FÉDÉRATIONS et organisations professionnelles (chantier 3,
 * 2026-09-29). Une ligne JSON par organisme, produite hors dépôt par
 * `_FEDERATIONS/outils-import/convertir_federations.py`.
 *
 * ── Ce qui passe par la porte commune, et pourquoi ────────────────────────
 *
 * La fiche et ses personnes entrent par le FUNNEL D'INGESTION UNIQUE
 * (`ScrapedRecordIngestService`, source `federations-2026`) : c'est lui qui
 * applique la liste d'opposition (e-mail ET téléphone, cf. #251), la
 * validation MX, la dédup des personnes, l'écriture « backfill-only » (une
 * valeur existante n'est jamais écrasée), et qui pose le tag de provenance
 * VERROUILLÉ `src:scraping-federations-2026` — celui que `FichesProtegees`
 * garde. Une personne naît `legitimate_interest_b2b`, sans `first_info_at` :
 * la mention d'information art. 14 reste à faire au premier message.
 *
 * Cette commande ajoute ce que le funnel ne connaît pas : la ligne
 * `federations` (famille, niveau, secteurs représentés, taille des adhérents,
 * certitude, pertinence, contactabilité, sigle…), le secteur principal, les
 * identifiants INSEE manquants, les étiquettes, et — en DEUXIÈME passe, quand
 * toutes les fiches existent — les têtes de réseau.
 *
 * ── Rattacher, jamais dupliquer ───────────────────────────────────────────
 *
 * La clé est le SIREN, ou, sans SIREN, l'ancre (FR, `foreign_id`) décrite
 * plus bas. Une fiche déjà présente (un organisateur d'événement,
 * une CCI) est RATTACHÉE : elle reçoit sa ligne `federations`, garde sa nature,
 * sa démarche (`events.participation/intervention`, `relation_type`,
 * `lifecycle_stage`) et ses étiquettes. Une fiche à la corbeille n'est pas
 * ressuscitée : la ligne est rejetée.
 *
 * ── Les organismes SANS SIREN (2026-09-29) ────────────────────────────────
 *
 * Une union départementale, un conseil départemental d'ordre, une antenne
 * de confédération n'ont pas de personnalité juridique propre : pas de
 * SIREN, mais des coordonnées publiées. Leur ligne porte alors
 * `"siren": null` et un `identifiant` STABLE fabriqué par le convertisseur
 * (`section:fo:28`). La fiche s'ancre sur (`country_code` = FR,
 * `foreign_id` = identifiant), exactement comme les organisateurs
 * d'événements sans SIREN (#250/#251) : rattachement, dédoublonnage,
 * `run_id`, registre des retraits et tête de réseau passent par cette ancre.
 * Une ligne AVEC SIREN garde le comportement d'avant, à l'identique : son
 * `identifiant` éventuel est ignoré (le SIREN reste la seule clé).
 *
 * ── Idempotente ───────────────────────────────────────────────────────────
 *
 * Rejouer le même fichier ne crée rien : le funnel reconnaît le même contenu
 * (`run_id` = SIREN ou identifiant + empreinte de la ligne), la ligne `federations` identique
 * est comptée « inchangée ». Un ré-import ne touche JAMAIS la démarche de Will
 * (`partenariat`, relance, note). Une tête de réseau absente du fichier ne
 * retire pas celle qui est posée.
 *
 * ── Par PAQUETS (2026-09-29) ──────────────────────────────────────────────
 *
 * L'essai à blanc du 29/09 sur 35 597 lignes est mort dans la deuxième passe
 * sur `out of shared memory` (HINT : `max_locks_per_transaction`). La cause,
 * MESURÉE en CI : chaque point de sauvegarde qui écrit (celui de la ligne,
 * celui du funnel, ceux qu'ils emboîtent) reçoit son propre identifiant de
 * transaction, et le VERROU de cet identifiant n'est rendu qu'à la fin de la
 * transaction englobante — environ QUATRE par ligne (92 verrous
 * `transactionid` pour 20 lignes, 332 pour 80), plus un par tête reliée.
 * Dans UNE transaction, 35 597 lignes en tiennent ~142 000 : la table des
 * verrous de la base déborde. Les verrous de relation, eux, restent bornés
 * (~200, le nombre de tables et d'index touchés).
 *
 * Désormais chaque passe VALIDE par paquets (`--paquet=N`, 500 par défaut),
 * un point de sauvegarde par ligne à l'intérieur : les verrous tenus sont
 * bornés par la taille du paquet, pas par celle du fichier. Un paquet validé
 * le reste ; une interruption laisse les paquets précédents en base, et
 * relancer le même fichier REPREND (import idempotent). Le bilan dit combien
 * de verrous un paquet a tenus au plus.
 *
 * ── Essai à blanc ─────────────────────────────────────────────────────────
 *
 * `--dry-run` passe par le MÊME chemin que l'import réel, paquet par paquet,
 * et ANNULE chaque paquet. Ce qui en sort est dit tel quel :
 *  - MESURÉ : la première passe, sur le vrai funnel. Limite : une ligne ne
 *    voit pas ce qu'un paquet PRÉCÉDENT aurait créé (ancre en double dans le
 *    fichier, personne partagée entre deux paquets) — au sein d'un paquet,
 *    si ;
 *  - ESTIMÉ d'après la première passe : les têtes de réseau. Les fiches de la
 *    première passe n'existent plus (annulées) ; la deuxième passe est
 *    SIMULÉE avec les règles de la base (tête introuvable ou à la corbeille,
 *    tête inchangée, boucle refusée à toute profondeur, au-delà de 64 pas).
 * `--limite=N` ne traite que les N premières lignes (import par étapes : 10
 * fiches, puis tout).
 *
 * Aucune valeur de ligne (nom, adresse, e-mail) n'est jamais écrite dans la
 * sortie ni dans le journal : des compteurs et des motifs seulement.
 */
class CrmImportFederations extends Command
{
    public const SOURCE = 'federations-2026';

    protected $signature = 'crm:import-federations
                            {file : Fichier JSONL, une ligne par organisme (hors dépôt)}
                            {--dry-run : Tout parcourir puis tout annuler, et afficher le bilan}
                            {--limite= : Ne traiter que les N premières lignes (import par étapes)}
                            {--paquet=500 : Lignes (puis têtes) validées par transaction : borne les verrous tenus}';

    protected $description = 'Importe les fédérations et organisations professionnelles, et relie les têtes de réseau.';

    private const CLES_AUTORISEES = [
        'siren', 'identifiant', 'nom', 'nom_developpe', 'sigle', 'nature',
        'naf', 'forme_juridique', 'effectif', 'date_creation', 'nb_etablissements',
        'adresse', 'code_postal', 'commune', 'departement', 'region',
        'famille', 'niveau', 'secteurs', 'tailles_adherents', 'certitude', 'pertinence',
        'contactabilite', 'origine_classement',
        'email_generique', 'email_generique_verifie_le', 'emails_autres', 'telephone', 'telephones_autres',
        'site', 'sites_autres', 'linkedin', 'linkedin_autres',
        'personnes', 'tete_de_reseau',
    ];

    private const CLES_PERSONNE = ['prenom', 'nom', 'fonction', 'email', 'email_verifie_le', 'linkedin'];

    /** Une adresse de `emails_autres` : son TYPE et sa vérification. */
    private const CLES_EMAIL = ['email', 'type', 'domaine_verifie', 'verifie_le'];

    /** Types d'adresse (CADRAGE §7 bis). */
    private const TYPES_EMAIL = ['generique', 'nominatif'];

    /**
     * Origine posée dans `field_origins.sector_main` quand c'est CET import qui
     * a choisi le secteur : lui seul a le droit de le corriger ensuite.
     */
    public const ORIGINE_SECTEUR = 'federations-2026';

    /**
     * Identifiant d'un organisme SANS SIREN : l'espace de noms `section:`
     * SEULEMENT, puis au moins un segment (`section:fo:28`,
     * `section:cfe-cgc:2A`). Jamais neuf chiffres seuls (pas de confusion avec
     * un SIREN), et jamais un autre espace : une ligne `evt:…` ne peut pas se
     * rattacher à un organisateur d'événements.
     */
    public const MOTIF_IDENTIFIANT = '/^section(:[A-Za-z0-9-]+)+$/';

    /** Longueur maximale d'un identifiant (le plus long mesuré : 65). */
    public const IDENTIFIANT_MAX = 120;

    /** Pays de l'ancre `foreign_id` : ces organismes sont français. */
    private const PAYS = 'FR';

    /** Département accepté par le schéma pivot (`ScrapedRecord`). */
    private const MOTIF_DEPARTEMENT = '/^(0[1-9]|1\d|2[1-9AB]|[3-8]\d|9[0-5]|97[1-6])$/';

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> motif => nombre */
    private array $rejets = [];

    /** @var array<int, string> company_id => SIREN ou identifiant de la tête de réseau */
    private array $tetes = [];

    /** @var array<string, int> ancre (SIREN ou identifiant) => company_id, lignes acceptées */
    private array $ancres = [];

    /**
     * Taille d'un paquet (lignes, puis têtes, par transaction).
     *
     * @var positive-int
     */
    private int $paquet = 500;

    /** Verrous tenus au plus par la session en fin de paquet, dont ceux d'identifiants de transaction. */
    private int $verrousMax = 0;

    private int $verrousTransactionMax = 0;

    /** Paquets de la DEUXIÈME passe (têtes) validés. */
    private int $paquetsTetes = 0;

    /**
     * Ce que le paquet OUVERT a compté : reporté au bilan quand il se ferme,
     * oublié s'il est interrompu (ses écritures sont annulées avec lui).
     *
     * @var array{delta: array<string, int>, tetes: array<int, string>, ancres: array<string, int>}
     */
    private array $enCours = ['delta' => [], 'tetes' => [], 'ancres' => []];

    private ScrapedRecordIngestService $funnel;

    public function handle(AuditHashChain $audit, ScrapedRecordIngestService $funnel): int
    {
        $this->funnel = $funnel;

        $chemin = (string) $this->argument('file');
        if (! is_file($chemin) || ! is_readable($chemin)) {
            $this->error('Fichier illisible.');

            return self::FAILURE;
        }

        $limite = $this->option('limite');
        if ($limite !== null && (filter_var($limite, FILTER_VALIDATE_INT) === false || (int) $limite < 1)) {
            $this->error('--limite doit être un entier positif.');

            return self::FAILURE;
        }
        $limite = $limite === null ? null : (int) $limite;

        $paquet = $this->option('paquet');
        if (filter_var($paquet, FILTER_VALIDATE_INT) === false || (int) $paquet < 1) {
            $this->error('--paquet doit être un entier positif.');

            return self::FAILURE;
        }
        $this->paquet = max(1, (int) $paquet);

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;

        $source = DB::table('scraping_sources')->where('slug', self::SOURCE)->first();
        if ($source === null || ! (bool) $source->enabled) {
            // Sans elle, chaque ligne serait rejetée une à une : on le dit tout de suite.
            $this->error('Source `' . self::SOURCE . '` absente du registre ou coupée : migrer d\'abord (2026_09_29_000001).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->bilan = array_fill_keys([
            'lignes', 'rejetees', 'paquets',
            'fiches_creees', 'fiches_rattachees', 'federations_mises_a_jour', 'federations_inchangees',
            'contacts_crees', 'contacts_completes', 'personnes_sans_changement', 'personnes_ecartees',
            'personnes_opposees', 'personnes_retirees_ignorees', 'emails_refuses_mx',
            'natures_posees', 'secteurs_poses', 'secteurs_corriges', 'secteurs_conserves',
            'departements_ignores', 'emails_generiques_non_poses', 'coordonnees_gardees_en_canaux',
            'tetes_liees', 'tetes_inchangees', 'tetes_introuvables', 'tetes_refusees_cycle',
            'chaines_de_fusion_tronquees',
        ], 0);
        $this->rejets = [];
        $this->tetes = [];
        $this->ancres = [];
        $this->verrousMax = 0;
        $this->verrousTransactionMax = 0;
        $this->paquetsTetes = 0;
        $this->enCours = ['delta' => [], 'tetes' => [], 'ancres' => []];

        $interruption = null;
        try {
            WorkspaceContext::run($workspaceId, function () use ($chemin, $workspaceId, $dryRun, $limite): void {
                $this->premierePasse($chemin, $workspaceId, $limite, $dryRun);
                if ($dryRun) {
                    $this->deuxiemePasseEstimee($workspaceId);
                } else {
                    $this->deuxiemePasse($workspaceId);
                }
            });
        } catch (Throwable $e) {
            $interruption = $e;
        }

        // Un import INTERROMPU a pu valider des paquets : ils sont en base, et
        // la chaîne d'audit le dit aussi.
        if (! $dryRun && ($interruption === null || $this->bilan['paquets'] + $this->paquetsTetes > 0)) {
            $audit->record([
                'workspace_id' => $workspaceId,
                'user_id' => null,
                'method' => 'IMPORT_FEDERATIONS',
                'path' => 'artisan crm:import-federations',
                'status' => 200,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', json_encode($this->bilan, JSON_THROW_ON_ERROR)),
            ]);
        }

        if ($interruption !== null) {
            $this->error(
                "INTERROMPU après {$this->bilan['paquets']} paquet(s) de fiches et {$this->paquetsTetes} paquet(s) de têtes validé(s)"
                . ($dryRun ? ' (à blanc : rien n\'a été écrit).' : ' : ils restent en base. Relancer le même fichier REPREND (import idempotent).'),
            );

            throw $interruption;
        }

        // Une base qui refuse (RLS sans contexte, contrainte) ne doit JAMAIS
        // ressembler à un import réussi.
        $echec = ($this->rejets['erreur_base'] ?? 0) > 0
            || ($this->bilan['lignes'] > 0 && $this->bilan['rejetees'] === $this->bilan['lignes']);

        if ($echec) {
            $this->error('ÉCHEC : la base a refusé des lignes, ou toutes les lignes ont été rejetées.');
        } else {
            $this->info($dryRun ? '[À BLANC] rien n\'a été écrit.' : 'Import appliqué.');
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($this->bilan),
            array_values($this->bilan),
        ));
        $this->line("Verrous tenus au plus en fin de paquet : {$this->verrousMax} (dont {$this->verrousTransactionMax} d'identifiants de transaction), paquets de {$this->paquet}.");
        if ($dryRun) {
            $this->line('MESURÉ : la 1re passe (fiches, contacts, étiquettes…), paquet par paquet, chaque paquet annulé : une ligne ne voit pas ce qu\'un paquet PRÉCÉDENT aurait créé.');
            $this->line('ESTIMÉ d\'après la 1re passe, avec les règles de la base : tetes_liees, tetes_inchangees, tetes_introuvables, tetes_refusees_cycle.');
        }
        if ($this->rejets !== []) {
            ksort($this->rejets);
            $this->warn('Lignes rejetées, par motif :');
            foreach ($this->rejets as $motif => $n) {
                $this->line("  {$motif} : {$n}");
            }
        }

        return $echec ? self::FAILURE : self::SUCCESS;
    }

    // ── Première passe : les fiches ─────────────────────────────────────────

    private function premierePasse(string $chemin, string $workspaceId, ?int $limite, bool $dryRun): void
    {
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new RuntimeException('Ouverture impossible du fichier.');
        }

        $dansLePaquet = 0;
        try {
            $numero = 0;
            while (($ligne = fgets($flux)) !== false) {
                $numero++;
                if (trim($ligne) === '') {
                    continue;
                }
                if ($limite !== null && $this->bilan['lignes'] >= $limite) {
                    break;
                }
                $this->bilan['lignes']++;

                if ($dansLePaquet === 0) {
                    DB::beginTransaction();
                }
                $dansLePaquet++;

                try {
                    // Un point de sauvegarde par ligne : une ligne fautive est
                    // annulée seule. Ses compteurs ne sont reportés QUE si elle
                    // aboutit — et que son paquet se ferme.
                    [$delta, $companyId, $tete, $ancre] = DB::transaction(fn (): array => $this->importerLigne($ligne, $workspaceId));
                    foreach ($delta as $compteur => $n) {
                        $this->enCours['delta'][$compteur] = ($this->enCours['delta'][$compteur] ?? 0) + $n;
                    }
                    if ($tete !== null) {
                        $this->enCours['tetes'][$companyId] = $tete;
                    }
                    $this->enCours['ancres'][$ancre] = $companyId;
                } catch (InvalidArgumentException $e) {
                    // Motif produit par ce fichier (jamais une valeur de la ligne).
                    $this->rejeter($e->getMessage(), $numero);
                } catch (ScrapeIngestRejection $e) {
                    // Le MESSAGE du funnel peut citer une valeur : seul son code sort.
                    $this->rejeter('pivot_' . $e->errorCode, $numero);
                } catch (QueryException $e) {
                    $this->rejeter('erreur_base', $numero);
                    Log::warning('crm:import-federations : ligne refusee par la base', [
                        'ligne' => $numero,
                        'sqlstate' => $e->getCode(),
                    ]);
                }

                if ($dansLePaquet >= $this->paquet) {
                    $this->fermerPaquet($dryRun);
                    $dansLePaquet = 0;
                }
            }
            if ($dansLePaquet > 0) {
                $this->fermerPaquet($dryRun);
            }
        } catch (Throwable $e) {
            // Le paquet ouvert est annulé, et ce qu'il avait compté oublié.
            if ($dansLePaquet > 0) {
                DB::rollBack();
            }
            $this->enCours = ['delta' => [], 'tetes' => [], 'ancres' => []];

            throw $e;
        } finally {
            fclose($flux);
        }
    }

    /**
     * Ferme le paquet ouvert : relève les verrous que la session tient À CET
     * INSTANT (le pic du paquet : ils ne sont rendus qu'à sa fin), puis valide
     * — ou annule, à blanc — et reporte ce qu'il a compté. Aucun réglage de
     * la base n'est touché : c'est la TAILLE du paquet qui borne les verrous.
     */
    private function fermerPaquet(bool $dryRun, bool $deLignes = true): void
    {
        $verrous = DB::selectOne(
            "SELECT count(*) AS n, count(*) FILTER (WHERE locktype = 'transactionid') AS tx
             FROM pg_locks WHERE pid = pg_backend_pid()",
        );
        $this->verrousMax = max($this->verrousMax, (int) ($verrous->n ?? 0));
        $this->verrousTransactionMax = max($this->verrousTransactionMax, (int) ($verrous->tx ?? 0));

        if ($dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        foreach ($this->enCours['delta'] as $compteur => $n) {
            $this->bilan[$compteur] += $n;
        }
        foreach ($this->enCours['tetes'] as $companyId => $tete) {
            $this->tetes[$companyId] = $tete;
        }
        foreach ($this->enCours['ancres'] as $ancre => $companyId) {
            $this->ancres[$ancre] = $companyId;
        }
        $this->enCours = ['delta' => [], 'tetes' => [], 'ancres' => []];
        // `paquets` compte ceux de la PREMIÈRE passe (les lignes) : le même
        // nombre à blanc et en réel.
        if ($deLignes) {
            $this->bilan['paquets']++;
        } elseif (! $dryRun) {
            $this->paquetsTetes++;
        }
    }

    /**
     * @return array{0: array<string, int>, 1: int, 2: ?string, 3: string} compteurs de CETTE ligne, fiche, ancre de sa tête, son ancre
     */
    private function importerLigne(string $ligne, string $workspaceId): array
    {
        $l = $this->lire($ligne);
        $delta = [];

        $trouvee = $this->parAncre($workspaceId, $l['siren'], $l['identifiant'], corbeilleComprise: true)
            ->first(['id', 'deleted_at', 'email_generic', 'phone', 'website', 'linkedin_url']);
        $avant = $this->suivreFusion($workspaceId, $trouvee, ['id', 'deleted_at', 'email_generic', 'phone', 'website', 'linkedin_url']);
        // Un renvoi de fusion a été suivi : la ligne vise la fiche GARDÉE.
        $ancreGardee = $trouvee !== null && $avant !== null && (int) $trouvee->id !== (int) $avant->id
            ? FusionFiches::ancreDe($workspaceId, (int) $avant->id)
            : null;
        // Les ancres à interroger EN PLUS de celle du fichier : la fiche gardée
        // (renvoi suivi), et les fiches ABSORBÉES dans la fiche visée (une
        // personne retirée de A avant A→B ne revient pas par l'ancre de B).
        $tronquee = false;
        $ancresFusions = array_merge(
            $ancreGardee === null ? [] : [$ancreGardee],
            $avant === null ? [] : FusionFiches::ancresAbsorbees($workspaceId, (int) $avant->id, $tronquee),
        );
        if ($avant !== null && $avant->deleted_at !== null) {
            // Mise à la corbeille par Will : un import ne la ressuscite pas.
            throw new InvalidArgumentException('fiche_a_la_corbeille');
        }
        $ligneAvant = $avant === null ? null : EtiquettesFederation::ligne((int) $avant->id);

        if ($l['departement_ignore']) {
            $delta['departements_ignores'] = 1;
        }

        // Une personne RETIRÉE (supprimée, effacée) ne revient pas, même si la
        // ligne du fichier a changé : son empreinte de nom est au registre
        // `contacts_retires` (relecture sécurité R2).
        $retenues = [];
        foreach ($l['personnes'] as $p) {
            // Après un renvoi de fusion, le registre est interrogé avec les
            // DEUX ancres : celle du fichier (la fiche absorbée) ET celle de la
            // fiche gardée — une personne effacée sur la gardée y est inscrite
            // sous l'ancre de la gardée (veto RGPD, relecture #260).
            if ($this->personneRetiree($workspaceId, $l['siren'], $l['identifiant'], $p['first_name'], $p['last_name'])
                || ($ancresFusions !== [] && FusionFiches::personneRetiree($workspaceId, $ancresFusions, $p['first_name'], $p['last_name']))) {
                $delta['personnes_retirees_ignorees'] = ($delta['personnes_retirees_ignorees'] ?? 0) + 1;

                continue;
            }
            $retenues[] = $p;
        }
        $l['personnes'] = $retenues;

        // Aucune donnée vérifiée jetée : une coordonnée du fichier qui diffère
        // de celle que la fiche porte déjà part en CANAL supplémentaire au lieu
        // d'être perdue (le funnel, « backfill-only », garde celle de la fiche).
        [$canalEmails, $canalTelephones, $canalSites, $canalLinkedin, $gardees] = $this->canaux($l, $avant);
        if ($gardees > 0) {
            $delta['coordonnees_gardees_en_canaux'] = $gardees;
        }

        $outcome = $this->funnel->ingest(ScrapedRecord::fromArray($this->pivot($l, $canalEmails, $canalTelephones)), false);
        if (! in_array($outcome->status, [ScrapeIngestOutcome::CREATED, ScrapeIngestOutcome::UPDATED, ScrapeIngestOutcome::IDEMPOTENT], true)) {
            throw new InvalidArgumentException('pivot_statut_inattendu');
        }
        $delta['contacts_crees'] = $outcome->contactsCreated;
        $delta['contacts_completes'] = $outcome->contactsUpdated;
        $delta['personnes_opposees'] = $outcome->personsSkippedOptOut;
        $delta['emails_refuses_mx'] = $outcome->emailsRejectedMx;
        // Une ligne dont la chaîne des fusions a dépassé ses bornes, ici ou
        // dans le funnel : comptée UNE fois (journal : `ancres_absorbees_tronquees`).
        if ($tronquee || $outcome->chainesFusionTronquees > 0) {
            $delta['chaines_de_fusion_tronquees'] = 1;
        }
        $delta['personnes_sans_changement'] = $outcome->personsSkipped['skipped_no_change'] ?? 0;
        $delta['personnes_ecartees'] = (int) array_sum($outcome->personsSkipped) - $delta['personnes_sans_changement'];

        $fiche = $this->parAncre($workspaceId, $l['siren'], $l['identifiant'])->first()
            ?? $this->suivreFusion($workspaceId, $this->parAncre($workspaceId, $l['siren'], $l['identifiant'], corbeilleComprise: true)->first());
        if ($fiche === null || $fiche->deleted_at !== null) {
            throw new RuntimeException('fiche_introuvable_apres_ingestion');
        }
        $companyId = (int) $fiche->id;

        $delta += $this->completerFiche($fiche, $l);
        $this->ecrireCanauxTypes($fiche, $l, $canalSites, $canalLinkedin);
        $this->typerContacts($companyId, $l);

        // L'e-mail générique du fichier n'est pas sur la fiche : opposition,
        // domaine sans MX, ou autre adresse déjà présente (backfill-only).
        // Compté, pour que l'essai à blanc dise ce qui ne sera PAS joignable.
        // Sans tenir compte de la casse : `Contact@X` et `contact@x` sont une
        // seule adresse.
        if ($l['email_generique'] !== null
            && mb_strtolower(trim((string) $fiche->email_generic)) !== mb_strtolower($l['email_generique'])) {
            $delta['emails_generiques_non_poses'] = 1;
        }

        if ($avant === null) {
            $delta['fiches_creees'] = 1;
        } elseif ($ligneAvant === null) {
            $delta['fiches_rattachees'] = 1;
        }
        $change = $this->ecrireFederation($companyId, $workspaceId, $l, $ligneAvant);
        if ($ligneAvant !== null) {
            $delta[$change ? 'federations_mises_a_jour' : 'federations_inchangees'] = 1;
        }

        // Les étiquettes suivent la fiche : même chemin que l'enrichissement.
        $company = Company::query()->find($companyId);
        if ($company !== null) {
            (new AutoTaggerService)->syncTags($company);
        }

        return [$delta, $companyId, $l['tete_de_reseau'], (string) ($l['siren'] ?? $l['identifiant'])];
    }

    /**
     * Ce que le funnel n'écrit pas : identifiants INSEE et secteur principal.
     * BACKFILL-ONLY, comme le funnel — sauf le secteur, écrit quand la valeur
     * actuelle n'est PAS un secteur utile (vide, `non_classe`, hors
     * référentiel) : le secteur d'une fédération est celui qu'elle
     * REPRÉSENTE, son code NAF 94 ne dit rien (B4 du chantier 1).
     *
     * @param  array<string, mixed>  $l
     * @return array<string, int>
     */
    private function completerFiche(\stdClass $fiche, array $l): array
    {
        $delta = [];
        $maj = [];
        $origines = json_decode(is_string($fiche->field_origins ?? null) ? $fiche->field_origins : '{}', true);
        $origines = is_array($origines) ? $origines : [];

        $colonnes = [
            'naf' => $l['naf'],
            'legal_form' => $l['forme_juridique'],
            'effectif_range' => $l['effectif'],
            'region_code' => $l['region'],
        ];
        foreach ($colonnes as $colonne => $valeur) {
            if ($valeur === null) {
                continue;
            }
            $actuel = $fiche->{$colonne} ?? null;
            if ($actuel !== null && trim((string) $actuel) !== '') {
                continue;
            }
            if (($origines[$colonne] ?? null) === 'declared') {
                continue;
            }
            $maj[$colonne] = $valeur;
            $origines[$colonne] = 'collected';
        }

        // La NATURE : `entreprise` (valeur par défaut des fiches INSEE) devient
        // celle du fichier ; toute autre nature (`reseau`, `association`,
        // `cci`…) est une qualification déjà faite, jamais remplacée. Vide, le
        // funnel l'a déjà posée. L'ordre « reclassement puis import » ou
        // « import puis reclassement » donne donc le même résultat (la fiche,
        // protégée, sort du reclassement).
        if (($fiche->entity_nature ?? null) === 'entreprise' && $l['nature'] !== 'entreprise') {
            $maj['entity_nature'] = $l['nature'];
            $delta['natures_posees'] = 1;
        }

        $principal = $l['secteurs'][0] ?? null;
        if ($principal !== null) {
            $actuel = $fiche->sector_main ?? null;
            $utile = is_string($actuel) && $actuel !== Taxonomy::SECTEUR_NON_CLASSE && array_key_exists($actuel, Taxonomy::SECTEURS);
            $deCetImport = ($origines['sector_main'] ?? null) === self::ORIGINE_SECTEUR;
            if (! $utile) {
                $maj['sector_main'] = $principal;
                $origines['sector_main'] = self::ORIGINE_SECTEUR;
                $delta['secteurs_poses'] = 1;
            } elseif ($actuel !== $principal && $deCetImport) {
                // C'est CET import qui l'avait choisi : le fichier le corrige.
                $maj['sector_main'] = $principal;
                $delta['secteurs_corriges'] = 1;
            } elseif ($actuel !== $principal) {
                // Posé autrement (code NAF, saisie) : on n'y touche pas.
                $delta['secteurs_conserves'] = 1;
            }
        }

        if ($maj !== []) {
            $maj['field_origins'] = json_encode($origines === [] ? new \stdClass : $origines, JSON_THROW_ON_ERROR);
            $maj['updated_at'] = now();
            DB::table('companies')->where('id', $fiche->id)->update($maj);
        }

        return $delta;
    }

    /**
     * Les coordonnées qui partiront en CANAUX : celles que le fichier range
     * « en plus », et celles qui diffèrent d'une valeur déjà sur la fiche.
     *
     * @param  array<string, mixed>  $l
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: int}
     */
    private function canaux(array $l, ?\stdClass $avant): array
    {
        $gardees = 0;
        $emails = array_map(static fn (array $e): string => $e['email'], $l['emails_autres']);
        $telephones = $l['telephones_autres'];
        $sites = $l['sites_autres'];
        $linkedin = $l['linkedin_autres'];

        $differe = static fn (mixed $actuel, ?string $nouveau, callable $forme): bool => $nouveau !== null
            && is_string($actuel) && trim($actuel) !== ''
            && $forme($actuel) !== $forme($nouveau);
        $minuscule = static fn (string $v): string => mb_strtolower(trim($v));
        $chiffres = static fn (string $v): string => (string) preg_replace('/\D/', '', $v);
        $url = static fn (string $v): string => rtrim(mb_strtolower(trim($v)), '/');

        if ($avant !== null) {
            if ($differe($avant->email_generic, $l['email_generique'], $minuscule)) {
                $emails[] = (string) $l['email_generique'];
                $gardees++;
            }
            if ($differe($avant->phone, $l['telephone'], $chiffres)) {
                $telephones[] = (string) $l['telephone'];
                $gardees++;
            }
            if ($differe($avant->website, $l['site'], $url)) {
                $sites[] = (string) $l['site'];
                $gardees++;
            }
            if ($differe($avant->linkedin_url, $l['linkedin'], $url)) {
                $linkedin[] = (string) $l['linkedin'];
                $gardees++;
            }
        }

        return [
            array_values(array_unique($emails)),
            array_values(array_unique($telephones)),
            array_values(array_unique($sites)),
            array_values(array_unique($linkedin)),
            $gardees,
        ];
    }

    /**
     * Range dans `signals.contact_channels` ce que le funnel ne connaît pas :
     * le TYPE et la vérification de chaque adresse en canal (`details`), les
     * sites et les pages LinkedIn supplémentaires. Et, pour l'e-mail générique
     * effectivement sur la fiche, sa fiche de vérification.
     *
     * Une adresse n'a de `details` que si le funnel l'a acceptée dans les
     * canaux (opposition, MX) : on ne décrit jamais une adresse écartée.
     * L'effacement (`EffacementCoordonneesFiches`) retire les deux ensemble.
     *
     * @param  array<string, mixed>  $l
     * @param  list<string>  $sites
     * @param  list<string>  $linkedin
     */
    private function ecrireCanauxTypes(\stdClass $fiche, array $l, array $sites, array $linkedin): void
    {
        $signals = json_decode(is_string($fiche->signals ?? null) ? $fiche->signals : '{}', true);
        $signals = is_array($signals) ? $signals : [];
        $avant = $signals;
        $canaux = is_array($signals['contact_channels'] ?? null) ? $signals['contact_channels'] : [];

        $presentes = array_map(
            static fn (mixed $e): string => mb_strtolower(trim((string) $e)),
            is_array($canaux['emails'] ?? null) ? $canaux['emails'] : [],
        );
        $details = is_array($canaux['details'] ?? null) ? $canaux['details'] : [];
        $aDecrire = $l['emails_autres'];
        if ($l['email_generique'] !== null) {
            $aDecrire[] = [
                'email' => $l['email_generique'], 'type' => 'generique',
                'domaine_verifie' => $l['email_generique_verifie_le'] !== null, 'verifie_le' => $l['email_generique_verifie_le'],
            ];
        }
        foreach ($aDecrire as $e) {
            if (in_array(mb_strtolower($e['email']), $presentes, true)) {
                $details[mb_strtolower($e['email'])] = [
                    'type' => $e['type'],
                    'domaine_verifie' => $e['domaine_verifie'],
                    'verifie_le' => $e['verifie_le'],
                    'source' => self::SOURCE,
                ];
            }
        }
        if ($details !== []) {
            $canaux['details'] = $details;
        }

        foreach (['sites' => $sites, 'linkedin' => $linkedin] as $cle => $valeurs) {
            if ($valeurs === []) {
                continue;
            }
            $existants = is_array($canaux[$cle] ?? null) ? $canaux[$cle] : [];
            $canaux[$cle] = array_values(array_unique(array_merge($existants, $valeurs)));
        }
        if ($canaux !== []) {
            $signals['contact_channels'] = $canaux;
        }

        $generique = mb_strtolower(trim((string) ($fiche->email_generic ?? '')));
        if ($l['email_generique'] !== null && $generique === mb_strtolower($l['email_generique'])) {
            $signals['email_generic_verification'] = [
                'type' => 'generique',
                'domaine_verifie' => $l['email_generique_verifie_le'] !== null,
                'verifie_le' => $l['email_generique_verifie_le'],
                'source' => self::SOURCE,
            ];
        }

        if ($signals !== $avant) {
            DB::table('companies')->where('id', $fiche->id)->update([
                'signals' => json_encode($signals, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * L'adresse d'une personne est NOMINATIVE : on le note sur sa fiche, avec
     * la vérification du domaine, pour la liste de campagne.
     *
     * @param  array<string, mixed>  $l
     */
    private function typerContacts(int $companyId, array $l): void
    {
        foreach ($l['personnes'] as $p) {
            if ($p['email'] === null) {
                continue;
            }
            $contact = DB::table('contacts')->where('company_id', $companyId)->where('email', $p['email'])
                ->whereNull('deleted_at')->first(['id', 'metadata']);
            if ($contact === null) {
                continue;
            }
            $meta = json_decode(is_string($contact->metadata) ? $contact->metadata : '{}', true);
            $meta = is_array($meta) ? $meta : [];
            $nouveau = array_merge($meta, [
                'email_type' => 'nominatif',
                'domaine_verifie' => $p['email_verifie_le'] !== null,
                'domaine_verifie_le' => $p['email_verifie_le'],
            ]);
            if ($nouveau !== $meta) {
                DB::table('contacts')->where('id', $contact->id)->update([
                    'metadata' => json_encode($nouveau, JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    /**
     * La fiche d'une ancre : le SIREN, ou (pays, `foreign_id`) pour un
     * organisme sans SIREN — la même recherche que le funnel
     * (`ScrapedRecordIngestService::upsertCompany`), servie par l'index unique
     * `companies_workspace_foreign_id_unique`.
     */
    private function parAncre(string $workspaceId, ?string $siren, ?string $identifiant, bool $corbeilleComprise = false): Builder
    {
        // La corbeille n'est lue que SCIEMMENT : pour refuser d'y ressusciter
        // une fiche (`importerLigne`), jamais pour y rattacher quoi que ce soit.
        $requete = DB::table('companies')->where('workspace_id', $workspaceId)
            ->when(! $corbeilleComprise, static fn (Builder $q): Builder => $q->whereNull('deleted_at'));
        if ($siren !== null) {
            return $requete->where('siren', $siren);
        }
        if ($identifiant === null) {
            // `lire()` l'a déjà refusé : jamais une recherche sans ancre.
            throw new InvalidArgumentException('siren_ou_identifiant_manquant');
        }

        return $requete->where('country_code', self::PAYS)->where('foreign_id', $identifiant);
    }

    private function personneRetiree(string $workspaceId, ?string $siren, ?string $identifiant, ?string $prenom, ?string $nom): bool
    {
        if ($nom === null) {
            return false;
        }
        // `contacts_retires_contient*` : les seules questions que le rôle
        // applicatif peut poser au registre (il n'exécute pas
        // `contacts_retires_empreinte`, relecture S-a). Sans SIREN, le
        // registre est lu par l'ancre (pays, `foreign_id`) de l'organisme.
        $ligne = $siren !== null
            ? DB::selectOne(
                'SELECT contacts_retires_contient(?::uuid, ?, ?, ?) AS e',
                [$workspaceId, $siren, $prenom, $nom],
            )
            : DB::selectOne(
                'SELECT contacts_retires_contient_ancre(?::uuid, ?, ?, ?, ?) AS e',
                [$workspaceId, self::PAYS, $identifiant, $prenom, $nom],
            );

        return (bool) ($ligne->e ?? false);
    }

    /**
     * Crée ou met à jour la ligne `federations`. Ne touche JAMAIS la démarche
     * (`partenariat`, relance, note) ni la tête de réseau (deuxième passe).
     *
     * @param  array<string, mixed>  $l
     * @return bool la ligne existait et a changé
     */
    private function ecrireFederation(int $companyId, string $workspaceId, array $l, ?\stdClass $avant): bool
    {
        $valeurs = [
            'famille' => $l['famille'],
            'niveau' => $l['niveau'],
            'secteurs' => EtiquettesFederation::litteral($l['secteurs']),
            'tailles_adherents' => EtiquettesFederation::litteral($l['tailles_adherents']),
            'certitude' => $l['certitude'],
            'pertinence' => $l['pertinence'],
            'contactabilite' => $l['contactabilite'],
            'origine_classement' => $l['origine_classement'],
            'sigle' => $l['sigle'],
            'nom_developpe' => $l['nom_developpe'],
            'date_creation' => $l['date_creation'],
            'nb_etablissements' => $l['nb_etablissements'],
        ];

        if ($avant === null) {
            DB::table('federations')->insert($valeurs + [
                'company_id' => $companyId,
                'workspace_id' => $workspaceId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return false;
        }

        $change = false;
        foreach ($valeurs as $champ => $valeur) {
            $actuel = $avant->{$champ} ?? null;
            if (in_array($champ, ['secteurs', 'tailles_adherents'], true)) {
                $actuel = EtiquettesFederation::litteral(EtiquettesFederation::tableau($actuel));
            } elseif ($champ === 'nb_etablissements') {
                $actuel = $actuel === null ? null : (int) $actuel;
            } elseif ($champ === 'date_creation') {
                $actuel = $actuel === null ? null : substr((string) $actuel, 0, 10);
            }
            if ($actuel !== $valeur) {
                $change = true;
                break;
            }
        }

        if ($change) {
            DB::table('federations')->where('company_id', $companyId)->update($valeurs + ['updated_at' => now()]);
        }

        return $change;
    }

    // ── Deuxième passe : les têtes de réseau ────────────────────────────────

    /**
     * Les têtes, par paquets validés : chaque lien reçoit son point de
     * sauvegarde (donc un verrou d'identifiant de transaction), rendu à la
     * fin de SON paquet.
     */
    private function deuxiemePasse(string $workspaceId): void
    {
        foreach (array_chunk($this->tetes, $this->paquet, true) as $paquet) {
            $delta = array_fill_keys(['tetes_liees', 'tetes_inchangees', 'tetes_introuvables', 'tetes_refusees_cycle'], 0);
            DB::beginTransaction();
            try {
                foreach ($paquet as $companyId => $ancreTete) {
                    $teteId = $this->idDeTete($workspaceId, $ancreTete);
                    if ($teteId === null) {
                        $delta['tetes_introuvables']++;

                        continue;
                    }

                    $actuelle = DB::table('federations')->where('company_id', $companyId)->value('parent_company_id');
                    if ($actuelle !== null && (int) $actuelle === $teteId) {
                        $delta['tetes_inchangees']++;

                        continue;
                    }

                    try {
                        // Point de sauvegarde : un cycle refusé par la base n'annule que ce lien.
                        DB::transaction(function () use ($companyId, $teteId): void {
                            DB::table('federations')->where('company_id', $companyId)
                                ->update(['parent_company_id' => $teteId, 'updated_at' => now()]);
                        });
                        $delta['tetes_liees']++;
                    } catch (QueryException $e) {
                        if (! str_contains($e->getMessage(), 'federation_cycle')) {
                            throw $e;
                        }
                        $delta['tetes_refusees_cycle']++;
                    }
                }
                $this->enCours['delta'] = $delta;
                $this->fermerPaquet(dryRun: false, deLignes: false);
            } catch (Throwable $e) {
                DB::rollBack();
                $this->enCours = ['delta' => [], 'tetes' => [], 'ancres' => []];

                throw $e;
            }
        }
    }

    /** La fiche vivante d'une tête : SIREN (neuf chiffres) ou identifiant, les deux seules formes que `lire()` laisse passer. */
    private function idDeTete(string $workspaceId, string $ancreTete): ?int
    {
        $estSiren = preg_match('/^\d{9}$/', $ancreTete) === 1;
        $id = $this->parAncre($workspaceId, $estSiren ? $ancreTete : null, $estSiren ? null : $ancreTete)->value('id');
        if ($id === null) {
            // Une tête absorbée par une fusion : sa fiche gardée.
            $fiche = $this->suivreFusion($workspaceId, $this->parAncre(
                $workspaceId,
                $estSiren ? $ancreTete : null,
                $estSiren ? null : $ancreTete,
                corbeilleComprise: true,
            )->first(['id', 'deleted_at']), ['id', 'deleted_at']);

            return $fiche === null || $fiche->deleted_at !== null ? null : (int) $fiche->id;
        }

        return (int) $id;
    }

    /**
     * Chantier 5 — une fiche à la corbeille ABSORBÉE par une fusion non
     * annulée est remplacée par sa fiche gardée (vivante) : l'import met à
     * jour celle-ci au lieu de refuser la ligne. Une fiche mise à la corbeille
     * par Will (sans fusion) reste telle quelle — l'import refuse toujours de
     * la ressusciter.
     *
     * @param  list<string>  $colonnes
     */
    private function suivreFusion(string $workspaceId, ?\stdClass $fiche, array $colonnes = ['*']): ?\stdClass
    {
        if ($fiche === null || $fiche->deleted_at === null) {
            return $fiche;
        }
        $renvoi = FusionFiches::gardeDe($workspaceId, (int) $fiche->id);
        if ($renvoi === null) {
            return $fiche;
        }
        $gardee = DB::table('companies')->where('workspace_id', $workspaceId)
            ->where('id', $renvoi['garde'])->whereNull('deleted_at')->first($colonnes);

        return $gardee instanceof \stdClass ? $gardee : $fiche;
    }

    /**
     * À BLANC, les fiches de la première passe ont été annulées paquet par
     * paquet : la deuxième passe ne peut pas être JOUÉE, elle est SIMULÉE
     * d'après la première, avec les règles de la base :
     *  - la tête est une fiche acceptée par la première passe, ou une fiche
     *    vivante déjà en base (jamais à la corbeille) ; sinon introuvable ;
     *  - le lien déjà posé est « inchangé » ;
     *  - `federations_refuser_cycle` : on remonte les têtes de la tête ; si
     *    l'on y retrouve la fiche, ou au-delà de 64 pas, le lien est refusé ;
     *  - un lien accepté compte pour les suivants, dans l'ordre du fichier.
     * Rien n'est écrit.
     */
    private function deuxiemePasseEstimee(string $workspaceId): void
    {
        /** @var array<int, int> $parents company_id => tête déjà posée */
        $parents = [];
        foreach (DB::table('federations')->where('workspace_id', $workspaceId)->whereNotNull('parent_company_id')
            ->get(['company_id', 'parent_company_id']) as $f) {
            $parents[(int) $f->company_id] = (int) $f->parent_company_id;
        }

        foreach ($this->tetes as $companyId => $ancreTete) {
            $teteId = $this->ancres[$ancreTete] ?? $this->idDeTete($workspaceId, $ancreTete);
            if ($teteId === null) {
                $this->bilan['tetes_introuvables']++;

                continue;
            }
            if (($parents[$companyId] ?? null) === $teteId) {
                $this->bilan['tetes_inchangees']++;

                continue;
            }

            $cycle = $teteId === $companyId;
            $courant = $teteId;
            $pas = 0;
            while (! $cycle && ($courant = $parents[$courant] ?? null) !== null) {
                $pas++;
                $cycle = $courant === $companyId || $pas > 64;
            }
            if ($cycle) {
                $this->bilan['tetes_refusees_cycle']++;

                continue;
            }

            $parents[$companyId] = $teteId;
            $this->bilan['tetes_liees']++;
        }
    }

    // ── Lecture et validation d'une ligne ───────────────────────────────────

    /**
     * @return array<string, mixed> la ligne, validée et normalisée
     */
    private function lire(string $ligne): array
    {
        try {
            $brut = json_decode($ligne, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('json_invalide');
        }
        if (! is_array($brut)) {
            throw new InvalidArgumentException('json_invalide');
        }
        if (array_diff(array_keys($brut), self::CLES_AUTORISEES) !== []) {
            throw new InvalidArgumentException('cle_inconnue');
        }

        // L'ANCRE : le SIREN s'il est là (comportement d'avant, identifiant
        // ignoré) ; sinon l'identifiant stable d'un organisme sans SIREN.
        $siren = $this->texte($brut, 'siren');
        $identifiant = $this->texte($brut, 'identifiant');
        if ($siren !== null) {
            if (preg_match('/^\d{9}$/', $siren) !== 1) {
                throw new InvalidArgumentException('siren_invalide');
            }
            $identifiant = null;
        } elseif ($identifiant === null) {
            throw new InvalidArgumentException('siren_ou_identifiant_manquant');
        } elseif (! self::identifiantValide($identifiant)) {
            throw new InvalidArgumentException('identifiant_invalide');
        }
        $ancre = $siren ?? $identifiant;
        $nom = $this->texte($brut, 'nom');
        if ($nom === null) {
            throw new InvalidArgumentException('champ_obligatoire_manquant');
        }

        $nature = $this->texte($brut, 'nature') ?? 'federation';
        if (! array_key_exists($nature, Taxonomy::ENTITY_NATURES)) {
            throw new InvalidArgumentException('nature_inconnue');
        }

        $famille = $this->dans($brut, 'famille', array_keys(Taxonomy::FEDERATION_FAMILLES), 'famille_inconnue', obligatoire: true);
        $niveau = $this->dans($brut, 'niveau', array_keys(Taxonomy::FEDERATION_NIVEAUX), 'niveau_inconnu', obligatoire: true);
        $pertinence = $this->dans($brut, 'pertinence', array_keys(Taxonomy::FEDERATION_PERTINENCES), 'pertinence_inconnue', obligatoire: true);
        $contactabilite = $this->dans($brut, 'contactabilite', array_keys(Taxonomy::FEDERATION_CONTACTABILITES), 'contactabilite_inconnue', obligatoire: true);
        $certitude = $this->dans($brut, 'certitude', array_keys(Taxonomy::FEDERATION_CERTITUDES), 'certitude_inconnue', obligatoire: false);

        $secteurs = $this->liste($brut, 'secteurs', Taxonomy::secteursRepresentables(), 'secteur_inconnu');
        if (count($secteurs) > Taxonomy::FEDERATION_SECTEURS_MAX) {
            throw new InvalidArgumentException('trop_de_secteurs');
        }
        $tailles = $this->liste($brut, 'tailles_adherents', array_keys(Taxonomy::TAILLES), 'taille_inconnue');

        $tete = $this->texte($brut, 'tete_de_reseau');
        if ($tete !== null && ((preg_match('/^\d{9}$/', $tete) !== 1 && ! self::identifiantValide($tete)) || $tete === $ancre)) {
            throw new InvalidArgumentException('tete_de_reseau_invalide');
        }

        $effectif = $this->texte($brut, 'effectif');
        if ($effectif !== null && preg_match('/^(NN|\d{2})$/', $effectif) !== 1) {
            throw new InvalidArgumentException('effectif_invalide');
        }
        $forme = $this->texte($brut, 'forme_juridique');
        if ($forme !== null && preg_match('/^\d{4}$/', $forme) !== 1) {
            throw new InvalidArgumentException('forme_juridique_invalide');
        }

        $departement = $this->texte($brut, 'departement');
        $departement = $departement === null ? null : strtoupper($departement);
        $departementIgnore = false;
        if ($departement !== null && preg_match(self::MOTIF_DEPARTEMENT, $departement) !== 1) {
            // Collectivités d'outre-mer (975, 977, 986…) : le schéma pivot ne
            // les connaît pas. On garde la fiche, sans département.
            $departement = null;
            $departementIgnore = true;
        }
        $regionLue = $this->texte($brut, 'region');
        $region = $regionLue !== null ? Classement::region($regionLue) : null;
        $region ??= Classement::regionDuDepartement($departement);

        $codePostal = $this->texte($brut, 'code_postal');
        if ($codePostal !== null && preg_match('/^\d{5}$/', $codePostal) !== 1) {
            $codePostal = null;
        }

        $nb = $brut['nb_etablissements'] ?? null;
        if ($nb !== null && (! is_int($nb) || $nb < 0)) {
            throw new InvalidArgumentException('type_de_valeur_invalide');
        }

        $personnes = $brut['personnes'] ?? [];
        if (! is_array($personnes) || ! array_is_list($personnes)) {
            throw new InvalidArgumentException('personnes_invalides');
        }
        $propres = [];
        foreach ($personnes as $p) {
            if (! is_array($p) || array_diff(array_keys($p), self::CLES_PERSONNE) !== []) {
                throw new InvalidArgumentException('personnes_invalides');
            }
            $propres[] = [
                'first_name' => $this->texte($p, 'prenom'),
                'last_name' => $this->texte($p, 'nom'),
                'role' => $this->texte($p, 'fonction'),
                'email' => $this->email($this->texte($p, 'email')),
                'email_verifie_le' => $this->date($p, 'email_verifie_le'),
                'linkedin_url' => $this->lien($this->texte($p, 'linkedin')),
            ];
        }

        $emailsAutres = $brut['emails_autres'] ?? [];
        if (! is_array($emailsAutres) || ! array_is_list($emailsAutres)) {
            throw new InvalidArgumentException('emails_invalides');
        }
        $adresses = [];
        foreach ($emailsAutres as $e) {
            if (! is_array($e) || array_diff(array_keys($e), self::CLES_EMAIL) !== []) {
                throw new InvalidArgumentException('emails_invalides');
            }
            $type = $this->dans($e, 'type', self::TYPES_EMAIL, 'type_email_inconnu', obligatoire: true);
            $verifie = $e['domaine_verifie'] ?? false;
            if (! is_bool($verifie)) {
                throw new InvalidArgumentException('type_de_valeur_invalide');
            }
            $adresse = $this->email($this->texte($e, 'email'));
            if ($adresse === null) {
                continue;
            }
            $adresses[$adresse] = [
                'email' => $adresse,
                'type' => (string) $type,
                'domaine_verifie' => $verifie,
                'verifie_le' => $this->date($e, 'verifie_le'),
            ];
        }

        return [
            'siren' => $siren,
            'identifiant' => $identifiant,
            'nom' => $nom,
            'nom_developpe' => $this->texte($brut, 'nom_developpe'),
            'sigle' => $this->texte($brut, 'sigle'),
            'nature' => $nature,
            'naf' => $this->texte($brut, 'naf'),
            'forme_juridique' => $forme,
            'effectif' => $effectif,
            'date_creation' => $this->date($brut, 'date_creation'),
            'nb_etablissements' => $nb,
            'adresse' => $this->texte($brut, 'adresse'),
            'code_postal' => $codePostal,
            'commune' => $this->texte($brut, 'commune'),
            'departement' => $departement,
            'departement_ignore' => $departementIgnore,
            'region' => $region,
            'famille' => $famille,
            'niveau' => $niveau,
            'secteurs' => $secteurs,
            'tailles_adherents' => $tailles,
            'certitude' => $certitude,
            'pertinence' => $pertinence,
            'contactabilite' => $contactabilite,
            'origine_classement' => $this->texte($brut, 'origine_classement'),
            'email_generique' => $this->email($this->texte($brut, 'email_generique')),
            'email_generique_verifie_le' => $this->date($brut, 'email_generique_verifie_le'),
            'emails_autres' => array_values($adresses),
            'telephone' => $this->texte($brut, 'telephone'),
            'telephones_autres' => $this->chaines($brut, 'telephones_autres'),
            'site' => $this->lien($this->texte($brut, 'site')),
            'sites_autres' => array_values(array_filter(array_map(fn (string $v): ?string => $this->lien($v), $this->chaines($brut, 'sites_autres')))),
            'linkedin' => $this->lien($this->texte($brut, 'linkedin')),
            'linkedin_autres' => array_values(array_filter(array_map(fn (string $v): ?string => $this->lien($v), $this->chaines($brut, 'linkedin_autres')))),
            'personnes' => $propres,
            'tete_de_reseau' => $tete,
        ];
    }

    /**
     * Le message du schéma pivot commun (`ScrapedRecord`) pour cette ligne.
     *
     * @param  array<string, mixed>  $l
     * @param  list<string>  $canalEmails
     * @param  list<string>  $canalTelephones
     * @return array<string, mixed>
     */
    private function pivot(array $l, array $canalEmails, array $canalTelephones): array
    {
        $champs = array_filter([
            'denomination' => $l['nom'],
            'website' => $l['site'],
            'phone' => $l['telephone'],
            'email_generic' => $l['email_generique'],
            'address' => $l['adresse'],
            'postcode' => $l['code_postal'],
            'city' => $l['commune'],
            'linkedin_url' => $l['linkedin'],
            'department_code' => $l['departement'],
        ], static fn (mixed $v): bool => $v !== null);

        $personnes = [];
        foreach ($l['personnes'] as $p) {
            unset($p['email_verifie_le']);
            $personnes[] = array_filter($p + ['kind' => 'person'], static fn (mixed $v): bool => $v !== null);
        }

        $message = [
            'schema_version' => ScrapedRecord::SCHEMA_VERSION,
            'source' => self::SOURCE,
            'status' => 'success',
            // Sans SIREN : l'ancre (pays, `foreign_id`) des organisateurs
            // d'événements. Avec SIREN : le message d'avant, à l'identique (le
            // `run_id` en dépend).
            'company' => [
                ...($l['siren'] !== null ? ['siren' => $l['siren']] : ['foreign_id' => $l['identifiant']]),
                'country' => self::PAYS,
                'nature' => $l['nature'],
                'fields' => $champs,
            ],
            'persons' => $personnes,
            'channels' => [
                'emails' => $canalEmails,
                'phones' => $canalTelephones,
            ],
        ];

        // Le MÊME contenu rejoué = le même run : le funnel le reconnaît et
        // n'écrit rien (idempotence). Un contenu corrigé = un nouveau run, qui
        // complète la fiche (backfill-only).
        $message['run_id'] = self::SOURCE . ':' . ($l['siren'] ?? $l['identifiant']) . ':'
            . substr(hash('sha256', json_encode($message, JSON_THROW_ON_ERROR)), 0, 16);

        return $message;
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    /** @param  array<mixed>  $brut */
    private function texte(array $brut, string $champ): ?string
    {
        $valeur = $brut[$champ] ?? null;
        if ($valeur === null) {
            return null;
        }
        if (! is_string($valeur)) {
            throw new InvalidArgumentException('type_de_valeur_invalide');
        }
        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }

    /**
     * @param  array<mixed>  $brut
     * @param  list<string>  $autorisees
     */
    private function dans(array $brut, string $champ, array $autorisees, string $motif, bool $obligatoire): ?string
    {
        $valeur = $this->texte($brut, $champ);
        if ($valeur === null) {
            if ($obligatoire) {
                throw new InvalidArgumentException($motif);
            }

            return null;
        }
        if (! in_array($valeur, $autorisees, true)) {
            throw new InvalidArgumentException($motif);
        }

        return $valeur;
    }

    /**
     * Liste fermée, sans doublon, dans l'ordre du fichier (le premier secteur
     * est le secteur principal).
     *
     * @param  array<mixed>  $brut
     * @param  list<string>  $autorisees
     * @return list<string>
     */
    private function liste(array $brut, string $champ, array $autorisees, string $motif): array
    {
        $valeurs = [];
        foreach ($this->chaines($brut, $champ) as $v) {
            if (! in_array($v, $autorisees, true)) {
                throw new InvalidArgumentException($motif);
            }
            if (! in_array($v, $valeurs, true)) {
                $valeurs[] = $v;
            }
        }

        return $valeurs;
    }

    /**
     * @param  array<mixed>  $brut
     * @return list<string>
     */
    private function chaines(array $brut, string $champ): array
    {
        $valeurs = $brut[$champ] ?? [];
        if (! is_array($valeurs) || ! array_is_list($valeurs)) {
            throw new InvalidArgumentException('type_de_valeur_invalide');
        }
        $propres = [];
        foreach ($valeurs as $v) {
            if (! is_string($v)) {
                throw new InvalidArgumentException('type_de_valeur_invalide');
            }
            $v = trim($v);
            if ($v !== '') {
                $propres[] = $v;
            }
        }

        return $propres;
    }

    /** @param  array<mixed>  $brut */
    private function date(array $brut, string $champ): ?string
    {
        $valeur = $this->texte($brut, $champ);
        if ($valeur === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
        if ($date === false || $date->format('Y-m-d') !== $valeur) {
            throw new InvalidArgumentException('date_invalide');
        }

        return $valeur;
    }

    /** Une adresse sans « @ » n'est pas une adresse : écartée, pas rejetée. */
    private function email(?string $valeur): ?string
    {
        if ($valeur === null || filter_var($valeur, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_strtolower($valeur);
    }

    /** Seul un lien http(s) entre en base (il sera cliquable à l'écran). */
    private function lien(?string $valeur): ?string
    {
        return $valeur !== null && preg_match('#^https?://#i', $valeur) === 1 ? $valeur : null;
    }

    /** Forme d'un identifiant d'organisme sans SIREN (ancre ou tête de réseau). */
    public static function identifiantValide(string $identifiant): bool
    {
        return strlen($identifiant) <= self::IDENTIFIANT_MAX
            && preg_match(self::MOTIF_IDENTIFIANT, $identifiant) === 1;
    }

    private function rejeter(string $motif, int $numero): void
    {
        $this->bilan['rejetees']++;
        $this->rejets[$motif] = ($this->rejets[$motif] ?? 0) + 1;
        if ($this->output->isVerbose()) {
            $this->line("  ligne {$numero} : {$motif}");
        }
    }
}
