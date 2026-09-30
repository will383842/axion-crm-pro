<?php

namespace App\Console\Commands;

use App\Crm\Doublons\FusionFiches;
use App\Crm\Personnes\NatureEmail;
use App\Crm\Presse\EtiquettesMedia;
use App\Crm\Presse\QualificationPresse;
use App\Crm\Referentiels\Classement;
use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestOutcome;
use App\Crm\Scraping\ScrapeIngestRejection;
use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
use App\Support\EligibiliteCampagne;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * IMPORTE UNE LISTE DE DIFFUSION PRESSE (2026-09-30) — même contrat que
 * `crm:import-federations` : `--dry-run`, paquets de 500, idempotente, rejets
 * comptés par motif, aucune valeur de ligne dans la sortie ni le journal.
 *
 * Le fichier vit HORS DU DÉPÔT (dépôt public : aucune donnée nominative n'y
 * entre). Une ligne JSON par média, avec au plus un journaliste :
 *
 *   {"identifiant": "presse:<source>:<code>",        (ou "siren": "<9 chiffres>")
 *    "nom": "…", "type": "presse_quotidien",          (valeur de media.media_type)
 *    "zone": "regional",                              (national|regional|departemental|local, facultatif)
 *    "departement": "69", "ville": "…", "code_postal": "69001",
 *    "site": "https://…", "email_redaction": "redaction@…", "telephone": "…",
 *    "theme": "économie",
 *    "journaliste": {"prenom": "…", "nom": "…", "fonction": "…", "rubrique": "…",
 *                    "email": "<adresse professionnelle publiée>", "acces": "email_redaction",
 *                    "linkedin": "https://…"}}
 *
 * ── La porte d'accès du journaliste (relecture sécurité de #264) ────────
 * `acces` prend une valeur de `Taxonomy::ACCES_PRESSE`. L'adresse n'est
 * posée sur le contact QUE si elle vaut `email_redaction` ; absente, ou
 * toute autre valeur : le contact est créé SANS adresse (compteur
 * `emails_journalistes_retenus_par_acces`), comme à l'harmonisation. Choix :
 * garder la personne (nom, fonction, LinkedIn servent aux relations presse
 * faites à la main) sans jamais rendre son adresse diffusable par défaut —
 * rejeter la ligne perdrait le média et la personne pour une adresse.
 *
 * ── Un journaliste opposé dans la console ne revient jamais ────────────
 * Avant d'entrer, le journaliste est cherché dans `journalists` parmi les
 * lignes OPPOSÉES ou EFFACÉES (`opt_out`, corbeille) : même nom sur le même
 * média (média de la fiche, ou de même nom), ou même adresse. Trouvé : il
 * n'est ni créé, ni complété, ni réactivé (`journalistes_opposes`).
 *
 * Plusieurs lignes d'un même média (un journaliste par ligne) visent la même
 * fiche par la même ancre.
 *
 * Un titre DÉJÀ en base sans SIREN (fiche `media:<id>` née de l'harmonisation)
 * est REJOINT (même nom normalisé, même type, département compatible), jamais
 * doublé d'une fiche parallèle ; en cas de doute la ligne est rejetée.
 *
 * Motifs de rejet : `json_invalide`, `cle_inconnue`, `type_de_valeur_invalide`,
 * `siren_invalide`, `siren_ou_identifiant_manquant`, `identifiant_invalide`,
 * `champ_obligatoire_manquant`, `type_inconnu`, `zone_inconnue`,
 * `theme_trop_long`, `journaliste_invalide`, `journaliste_sans_nom`,
 * `acces_inconnu`, `titre_existant_non_harmonise`, `rapprochement_ambigu`,
 * `fiche_a_la_corbeille`, `pivot_<code>`, `erreur_base`.
 *
 * ── Ce que fait une ligne ────────────────────────────────────────────────
 *  - la fiche entre par la PORTE COMMUNE (funnel, source `presse-2026`) :
 *    ancre SIREN ou (FR, identifiant), backfill-only, opposition (e-mail ET
 *    téléphone), validation MX, dédup des personnes, tag de provenance
 *    VERROUILLÉ `src:scraping-presse-2026` qui PROTÈGE la fiche. Le
 *    journaliste devient un contact, `legitimate_interest_b2b` ;
 *  - nature `media` et relation `presse_media` selon `QualificationPresse`
 *    (jamais une relation posée à la main) ;
 *  - une ligne `media` rattachée à la fiche (retrouvée par son nom, sinon
 *    créée — source `liste-presse`) : l'écran « Médias & Presse » la voit, et
 *    ses étiquettes `media-type:`/`media-zone:`/`media-theme:` en dérivent.
 *    BACKFILL-ONLY : une valeur déjà présente n'est jamais remplacée ;
 *  - rubrique et type d'adresse dans `metadata` du contact.
 *
 * ── Ce qui ne revient jamais ─────────────────────────────────────────────
 *  - une fiche à la CORBEILLE : la ligne est rejetée (`fiche_a_la_corbeille`) ;
 *  - une personne RETIRÉE (registre `contacts_retires`, par l'ancre de la
 *    ligne et celles des fiches absorbées) ou à la corbeille sur la fiche.
 *
 * Une adresse de journaliste GRAND PUBLIC (gmail…) n'est pas refusée : le
 * funnel la marque `email_nature = perso`, et la liste de campagne l'écarte.
 * L'adresse de rédaction, elle, n'entre que professionnelle.
 */
class CrmPresseImporter extends Command
{
    protected $signature = 'crm:presse:importer
                            {file : Fichier JSONL, une ligne par média (hors dépôt)}
                            {--dry-run : Tout parcourir paquet par paquet, annuler chaque paquet, et afficher le bilan}
                            {--limite= : Ne traiter que les N premières lignes (import par étapes)}
                            {--paquet=500 : Lignes validées par transaction : borne les verrous tenus}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}';

    protected $description = 'Importe une liste de diffusion presse (médias et journalistes) dans les fiches et contacts.';

    public const SOURCE_MEDIA = 'liste-presse';

    private const CLES = [
        'siren', 'identifiant', 'nom', 'type', 'zone', 'departement', 'ville', 'code_postal',
        'site', 'email_redaction', 'telephone', 'theme', 'journaliste',
    ];

    private const CLES_JOURNALISTE = ['prenom', 'nom', 'fonction', 'rubrique', 'email', 'acces', 'linkedin'];

    /** Identifiant d'un média sans SIREN : l'espace de noms `presse:` SEULEMENT. */
    public const MOTIF_IDENTIFIANT = '/^presse(:[A-Za-z0-9-]+)+$/';

    public const IDENTIFIANT_MAX = 120;

    private const PAYS = 'FR';

    private const MOTIF_DEPARTEMENT = '/^(0[1-9]|1\d|2[1-9AB]|[3-8]\d|9[0-5]|97[1-6])$/';

    /** Types acceptés : ceux de `media.media_type`, sauf la production (hors presse). */
    private const TYPES_EXCLUS = ['production_audiovisuelle'];

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> */
    private array $rejets = [];

    /** @var array<string, int> */
    private array $enCours = [];

    private string $workspaceId = '';

    private ScrapedRecordIngestService $funnel;

    public function handle(AuditHashChain $audit, ScrapedRecordIngestService $funnel): int
    {
        $this->funnel = $funnel;
        $discret = (bool) $this->option('compteurs-seulement');
        $dryRun = (bool) $this->option('dry-run');

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
        $paquet = (int) $paquet;

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error($discret ? 'Espace business introuvable.' : "Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $this->workspaceId = (string) $workspaceId;

        $source = DB::table('scraping_sources')->where('slug', QualificationPresse::SOURCE)->first();
        if ($source === null || ! (bool) $source->enabled) {
            $this->error('Source `' . QualificationPresse::SOURCE . '` absente du registre ou coupée : migrer d\'abord (2026_10_01_000010).');

            return self::FAILURE;
        }

        $this->bilan = array_fill_keys([
            'lignes', 'rejetees', 'paquets',
            'fiches_creees', 'fiches_rattachees', 'titres_rapproches', 'medias_crees', 'medias_completes', 'medias_inchanges',
            'natures_posees', 'natures_conservees', 'relations_posees', 'relations_conservees',
            'emails_redaction_non_poses',
            'journalistes_lus', 'journalistes_opposes', 'emails_journalistes_retenus_par_acces', 'contacts_crees', 'contacts_completes', 'personnes_sans_changement',
            'personnes_ecartees', 'personnes_opposees', 'personnes_retirees_ignorees', 'emails_refuses_mx',
            'chaines_de_fusion_tronquees',
        ], 0);
        $this->rejets = [];
        $this->enCours = [];

        $interruption = null;
        try {
            WorkspaceContext::run($this->workspaceId, function () use ($chemin, $limite, $paquet, $dryRun, $discret): void {
                $this->parcourir($chemin, $limite, $paquet, $dryRun, $discret);
            });
        } catch (Throwable $e) {
            $interruption = $e;
        }

        if (! $dryRun && ($interruption === null || $this->bilan['paquets'] > 0)) {
            $audit->record([
                'workspace_id' => $this->workspaceId,
                'user_id' => null,
                'method' => 'IMPORT_PRESSE',
                'path' => 'artisan crm:presse:importer',
                'status' => 200,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', json_encode($this->bilan, JSON_THROW_ON_ERROR)),
            ]);
        }

        if ($interruption !== null) {
            $this->error(
                "INTERROMPU après {$this->bilan['paquets']} paquet(s) validé(s)"
                . ($dryRun ? ' (à blanc : rien n\'a été écrit).' : ' : ils restent en base. Relancer le même fichier REPREND (import idempotent).'),
            );

            if ($this->option('compteurs-seulement')) {
                // Journaux publics : jamais le message d'une exception (il peut
                // citer une valeur). Le détail va au journal du serveur.
                Log::error('crm:presse:importer interrompu', ['exception' => $interruption]);

                throw new RuntimeException('crm:presse:importer interrompu (détail masqué : --compteurs-seulement, voir le journal du serveur).');
            }

            throw $interruption;
        }

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
        if ($dryRun) {
            $this->line('MESURÉ paquet par paquet, chaque paquet annulé : une ligne ne voit pas ce qu\'un paquet PRÉCÉDENT aurait créé (un même média réparti sur deux paquets y est compté deux fois).');
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

    private function parcourir(string $chemin, ?int $limite, int $paquet, bool $dryRun, bool $discret): void
    {
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new RuntimeException('Ouverture impossible du fichier.');
        }

        $dansLePaquet = 0;
        $rejetsDuPaquet = [];
        try {
            $numero = 0;
            while (($ligne = fgets($flux)) !== false) {
                $numero++;
                if (trim($ligne) === '') {
                    continue;
                }
                if ($limite !== null && $limite <= $this->bilan['lignes'] + $dansLePaquet) {
                    break;
                }
                if ($dansLePaquet === 0) {
                    DB::beginTransaction();
                    DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
                }
                $dansLePaquet++;

                try {
                    $delta = [];
                    DB::transaction(function () use ($ligne, &$delta): void {
                        $delta = $this->importerLigne($ligne);
                    });
                    foreach ($delta as $cle => $n) {
                        $this->enCours[$cle] = ($this->enCours[$cle] ?? 0) + $n;
                    }
                } catch (InvalidArgumentException $e) {
                    $rejetsDuPaquet[] = [$e->getMessage(), $numero];
                } catch (ScrapeIngestRejection $e) {
                    $rejetsDuPaquet[] = ['pivot_' . $e->errorCode, $numero];
                } catch (QueryException $e) {
                    $rejetsDuPaquet[] = ['erreur_base', $numero];
                    Log::warning('crm:presse:importer : ligne refusee par la base', ['ligne' => $numero, 'sqlstate' => $e->getCode()]);
                }

                if ($dansLePaquet >= $paquet) {
                    $this->fermer($dryRun, $dansLePaquet, $rejetsDuPaquet, $discret);
                    $dansLePaquet = 0;
                    $rejetsDuPaquet = [];
                }
            }
            if ($dansLePaquet > 0) {
                $this->fermer($dryRun, $dansLePaquet, $rejetsDuPaquet, $discret);
            }
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->enCours = [];

            throw $e;
        } finally {
            fclose($flux);
        }
    }

    /** @param  list<array{0: string, 1: int}>  $rejets */
    private function fermer(bool $dryRun, int $lignes, array $rejets, bool $discret): void
    {
        if ($dryRun) {
            DB::rollBack();
        } else {
            DB::commit();
        }
        $this->bilan['lignes'] += $lignes;
        foreach ($this->enCours as $cle => $n) {
            $this->bilan[$cle] = ($this->bilan[$cle] ?? 0) + $n;
        }
        foreach ($rejets as [$motif, $numero]) {
            $this->bilan['rejetees']++;
            $this->rejets[$motif] = ($this->rejets[$motif] ?? 0) + 1;
            if (! $discret && $this->output->isVerbose()) {
                $this->line("  ligne {$numero} : {$motif}");
            }
        }
        $this->enCours = [];
        $this->bilan['paquets']++;
    }

    /** @return array<string, int> */
    private function importerLigne(string $ligne): array
    {
        $l = $this->lire($ligne);
        $delta = [];

        $trouvee = $this->parAncre($l, corbeilleComprise: true)->first(['id', 'deleted_at']);
        $ancreMessage = $l['siren'] !== null
            ? ['siren' => $l['siren'], 'country' => self::PAYS]
            : ['foreign_id' => $l['identifiant'], 'country' => self::PAYS];
        $ancreRegistre = $this->ancreRegistre($l);
        $ficheRapprochee = null;
        if ($trouvee === null && $l['siren'] === null) {
            // Un titre DÉJÀ en base sans SIREN (fiche `media:<id>` de
            // l'harmonisation) : on le rejoint, jamais de fiche parallèle.
            $ficheRapprochee = $this->rapprocher($l);
            if ($ficheRapprochee !== null) {
                $trouvee = $ficheRapprochee;
                $ancreRegistre = FusionFiches::ancreDe($this->workspaceId, (int) $ficheRapprochee->id);
                $ancreMessage = $ancreRegistre['siren'] !== null
                    ? ['siren' => $ancreRegistre['siren'], 'country' => self::PAYS]
                    : ['foreign_id' => (string) $ancreRegistre['foreign_id'], 'country' => (string) $ancreRegistre['pays']];
                $delta['titres_rapproches'] = 1;
            }
        }
        if ($trouvee !== null && $trouvee->deleted_at !== null) {
            // À la corbeille (Will, ou fusion) : un import ne la ressuscite pas.
            throw new InvalidArgumentException('fiche_a_la_corbeille');
        }

        // Le journaliste : retiré, ou à la corbeille sur la fiche, il ne revient pas.
        $personnes = [];
        $j = $l['journaliste'];
        if ($j !== null) {
            $delta['journalistes_lus'] = 1;
            $ancres = [$ancreRegistre];
            if ($trouvee !== null) {
                $tronquee = false;
                $ancres = array_merge($ancres, FusionFiches::ancresAbsorbees($this->workspaceId, (int) $trouvee->id, $tronquee));
                if ($tronquee) {
                    $delta['chaines_de_fusion_tronquees'] = 1;
                }
            }
            if ($this->opposeEnConsole($l, $j, $trouvee === null ? null : (int) $trouvee->id)) {
                $delta['journalistes_opposes'] = 1;
                $j = null;
            } elseif (FusionFiches::personneRetiree($this->workspaceId, $ancres, $j['prenom'], $j['nom'])
                || ($trouvee !== null && QualificationPresse::personneALaCorbeille((int) $trouvee->id, $j['prenom'], $j['nom']))) {
                $delta['personnes_retirees_ignorees'] = 1;
                $j = null;
            } else {
                // La porte d'accès : SEULE `email_redaction` laisse l'adresse
                // partir sur le contact. Sans porte, c'est non.
                if ($j['email'] !== null && $j['acces'] !== 'email_redaction') {
                    $j['email'] = null;
                    $delta['emails_journalistes_retenus_par_acces'] = 1;
                }
                $personnes[] = array_filter([
                    'kind' => 'person',
                    'first_name' => $j['prenom'],
                    'last_name' => $j['nom'],
                    'role' => $j['fonction'],
                    'email' => $j['email'],
                    'linkedin_url' => $j['linkedin'],
                ], static fn ($v): bool => $v !== null);
            }
        }

        $emailRedaction = $l['email_redaction'];
        if ($emailRedaction !== null && (NatureEmail::de($emailRedaction) !== 'pro' || ! EligibiliteCampagne::peutRecevoir($emailRedaction))) {
            // Boîte grand public (celle d'une personne), opposée ou supprimée :
            // elle ne devient ni l'adresse de la fiche, ni celle du média.
            $emailRedaction = null;
            $delta['emails_redaction_non_poses'] = 1;
        }

        $message = [
            'schema_version' => ScrapedRecord::SCHEMA_VERSION,
            'source' => QualificationPresse::SOURCE,
            'status' => 'success',
            'company' => $ancreMessage + [
                'nature' => QualificationPresse::NATURE,
                'fields' => array_filter([
                    'denomination' => $l['nom'],
                    'website' => $l['site'],
                    'phone' => $l['telephone'],
                    'email_generic' => $emailRedaction,
                    'city' => $l['ville'],
                    'postcode' => $l['code_postal'],
                    'department_code' => $l['departement'],
                ], static fn ($v): bool => $v !== null),
            ],
            'persons' => $personnes,
        ];
        $message['run_id'] = QualificationPresse::SOURCE . ':liste:' . ($l['siren'] ?? $l['identifiant']) . ':'
            . substr(hash('sha256', json_encode($message, JSON_THROW_ON_ERROR)), 0, 16);

        $outcome = $this->funnel->ingest(ScrapedRecord::fromArray($message), false);
        if (! in_array($outcome->status, [ScrapeIngestOutcome::CREATED, ScrapeIngestOutcome::UPDATED, ScrapeIngestOutcome::IDEMPOTENT], true)) {
            throw new InvalidArgumentException('pivot_statut_inattendu');
        }
        $delta['contacts_crees'] = $outcome->contactsCreated;
        $delta['contacts_completes'] = $outcome->contactsUpdated;
        $delta['personnes_opposees'] = $outcome->personsSkippedOptOut;
        $delta['emails_refuses_mx'] = $outcome->emailsRejectedMx;
        $delta['personnes_sans_changement'] = $outcome->personsSkipped['skipped_no_change'] ?? 0;
        $delta['personnes_ecartees'] = (int) array_sum($outcome->personsSkipped) - $delta['personnes_sans_changement'];
        if ($outcome->chainesFusionTronquees > 0) {
            $delta['chaines_de_fusion_tronquees'] = 1;
        }

        $companyId = $ficheRapprochee !== null ? (int) $ficheRapprochee->id : ($this->parAncre($l)->value('id') ?? $outcome->companyId);
        if ($companyId === null) {
            throw new RuntimeException('fiche_introuvable_apres_ingestion');
        }
        $companyId = (int) $companyId;
        $delta[$trouvee === null ? 'fiches_creees' : 'fiches_rattachees'] = 1;

        foreach (QualificationPresse::qualifier($companyId) as $cle => $n) {
            $delta[$cle] = ($delta[$cle] ?? 0) + $n;
        }
        $delta[$this->ecrireMedia($companyId, $l, $emailRedaction)] = 1;

        if ($j !== null) {
            $contactId = QualificationPresse::contactDe($companyId, $j['prenom'], $j['nom'], $j['email']);
            if ($contactId !== null) {
                QualificationPresse::completerContact($contactId, null, [
                    'rubrique' => $j['rubrique'],
                    'acces' => $j['acces'],
                    'email_type' => $j['email'] === null ? null : 'nominatif',
                ]);
            }
        }

        QualificationPresse::etiqueter($companyId);

        return $delta;
    }

    /**
     * Le titre de cette ligne existe-t-il déjà en base, sans SIREN ? Même nom
     * normalisé, même type, département compatible (égal, ou inconnu d'un
     * côté). Une seule fiche : on la rejoint. Plusieurs fiches, ou un titre
     * pas encore harmonisé (sans fiche) : DOUTE — la ligne est rejetée et
     * comptée, jamais une fiche parallèle.
     *
     * @param  array<string, mixed>  $l
     */
    private function rapprocher(array $l): ?\stdClass
    {
        $candidats = DB::table('media')->where('workspace_id', $this->workspaceId)->whereNull('deleted_at')
            ->whereRaw('normalize_name(name) = normalize_name(?)', [$l['nom']])
            ->where('media_type', $l['type'])
            ->when($l['departement'] !== null, static fn ($q) => $q->where(
                static fn ($d) => $d->whereNull('department_code')->orWhere('department_code', $l['departement']),
            ))
            ->get(['id', 'company_id']);
        if ($candidats->isEmpty()) {
            return null;
        }
        if ($candidats->contains(static fn (\stdClass $c): bool => $c->company_id === null)) {
            throw new InvalidArgumentException('titre_existant_non_harmonise');
        }
        $fiches = $candidats->pluck('company_id')->map(static fn ($v): int => (int) $v)->unique()->values();
        if ($fiches->count() > 1) {
            throw new InvalidArgumentException('rapprochement_ambigu');
        }
        $fiche = DB::table('companies')->where('workspace_id', $this->workspaceId)->where('id', $fiches->first())
            ->first(['id', 'deleted_at']);

        return $fiche instanceof \stdClass ? $fiche : null;
    }

    /**
     * Ce journaliste a-t-il été OPPOSÉ ou EFFACÉ dans la console presse ?
     * Même nom (normalisé comme la base) sur le même média — celui de la
     * fiche, ou un média de même nom —, ou même adresse.
     *
     * @param  array<string, mixed>  $l
     * @param  array<string, mixed>  $j
     */
    private function opposeEnConsole(array $l, array $j, ?int $ficheId): bool
    {
        return DB::table('journalists as jo')
            ->where('jo.workspace_id', $this->workspaceId)
            ->whereRaw('(jo.opt_out = true OR jo.deleted_at IS NOT NULL)')
            ->where(function ($q) use ($l, $j, $ficheId): void {
                $q->where(function ($parNom) use ($l, $j, $ficheId): void {
                    $parNom->whereRaw(
                        "normalize_name(coalesce(jo.first_name, '') || '_' || coalesce(jo.last_name, '')) = normalize_name(coalesce(?, '') || '_' || ?)",
                        [$j['prenom'], $j['nom']],
                    )->where(function ($media) use ($l, $ficheId): void {
                        $media->whereExists(function ($m) use ($l, $ficheId): void {
                            $m->selectRaw('1')->from('media as me')->whereColumn('me.id', 'jo.media_id')
                                ->where(function ($mm) use ($l, $ficheId): void {
                                    $mm->whereRaw('lower(me.name) = lower(?)', [$l['nom']]);
                                    if ($ficheId !== null) {
                                        $mm->orWhere('me.company_id', $ficheId);
                                    }
                                });
                        });
                        if ($ficheId !== null) {
                            $media->orWhere('jo.company_id', $ficheId);
                        }
                    });
                });
                if ($j['email'] !== null) {
                    $q->orWhere('jo.email', $j['email']);
                }
            })
            ->exists();
    }

    /**
     * La ligne `media` de la fiche : retrouvée par son nom (sans casse), sinon
     * créée. BACKFILL-ONLY.
     *
     * @param  array<string, mixed>  $l
     * @return string le compteur à incrémenter
     */
    private function ecrireMedia(int $companyId, array $l, ?string $email): string
    {
        $valeurs = [
            'media_type' => $l['type'],
            'diffusion_zone' => $l['zone'] === null ? null : EtiquettesMedia::zoneStockee($l['zone']),
            'editorial_theme' => $l['theme'],
            'department_code' => $l['departement'],
            'region_code' => Classement::regionDuDepartement($l['departement']),
            'city' => $l['ville'],
            'postcode' => $l['code_postal'],
            'website' => $l['site'],
            'email' => $email,
            'phone' => $l['telephone'],
        ];

        $media = DB::table('media')->where('workspace_id', $this->workspaceId)->where('company_id', $companyId)
            ->whereNull('deleted_at')->whereRaw('normalize_name(name) = normalize_name(?)', [$l['nom']])->orderBy('id')->first();

        if ($media === null) {
            DB::table('media')->insert(array_filter($valeurs, static fn ($v): bool => $v !== null) + [
                'workspace_id' => $this->workspaceId,
                'company_id' => $companyId,
                'name' => $l['nom'],
                'media_family' => 'editorial',
                'website_status' => $l['site'] !== null ? 'found' : 'pending',
                'enrich_status' => 'pending',
                'source' => self::SOURCE_MEDIA,
                'harmonise_le' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 'medias_crees';
        }

        $maj = [];
        foreach ($valeurs as $colonne => $valeur) {
            // Le type d'un média déjà connu reste le sien : ce n'est jamais un trou.
            if ($valeur === null || $colonne === 'media_type') {
                continue;
            }
            $actuel = $media->{$colonne} ?? null;
            if ($actuel === null || trim((string) $actuel) === '') {
                $maj[$colonne] = $valeur;
            }
        }
        if ($media->harmonise_le === null) {
            $maj['harmonise_le'] = now();
        }
        if ($maj === []) {
            return 'medias_inchanges';
        }
        DB::table('media')->where('id', $media->id)->update($maj + ['updated_at' => now()]);

        return array_keys($maj) === ['harmonise_le'] ? 'medias_inchanges' : 'medias_completes';
    }

    /**
     * @param  array<string, mixed>  $l
     */
    private function parAncre(array $l, bool $corbeilleComprise = false): Builder
    {
        $q = DB::table('companies')->where('workspace_id', $this->workspaceId)
            ->when(! $corbeilleComprise, static fn ($q) => $q->whereNull('deleted_at'));

        return $l['siren'] !== null
            ? $q->where('siren', $l['siren'])
            : $q->where('country_code', self::PAYS)->where('foreign_id', $l['identifiant']);
    }

    /**
     * @param  array<string, mixed>  $l
     * @return array{siren: ?string, pays: ?string, foreign_id: ?string}
     */
    private function ancreRegistre(array $l): array
    {
        return $l['siren'] !== null
            ? ['siren' => $l['siren'], 'pays' => null, 'foreign_id' => null]
            : ['siren' => null, 'pays' => self::PAYS, 'foreign_id' => $l['identifiant']];
    }

    // ── Lecture et validation d'une ligne ───────────────────────────────────

    /** @return array<string, mixed> */
    private function lire(string $ligne): array
    {
        try {
            $brut = json_decode($ligne, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('json_invalide');
        }
        if (! is_array($brut) || array_is_list($brut)) {
            throw new InvalidArgumentException('json_invalide');
        }
        if (array_diff(array_keys($brut), self::CLES) !== []) {
            throw new InvalidArgumentException('cle_inconnue');
        }

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

        $nom = $this->texte($brut, 'nom');
        if ($nom === null) {
            throw new InvalidArgumentException('champ_obligatoire_manquant');
        }
        $type = $this->texte($brut, 'type');
        if ($type === null || ! array_key_exists($type, Taxonomy::MEDIA_TYPE_VERS_ETIQUETTE) || in_array($type, self::TYPES_EXCLUS, true)) {
            throw new InvalidArgumentException('type_inconnu');
        }
        $zone = $this->texte($brut, 'zone');
        if ($zone !== null && (! array_key_exists($zone, Taxonomy::MEDIA_ZONES) || $zone === 'inconnue')) {
            throw new InvalidArgumentException('zone_inconnue');
        }

        $departement = $this->texte($brut, 'departement');
        $departement = $departement === null ? null : strtoupper($departement);
        if ($departement !== null && preg_match(self::MOTIF_DEPARTEMENT, $departement) !== 1) {
            $departement = null;
        }
        $codePostal = $this->texte($brut, 'code_postal');
        if ($codePostal !== null && preg_match('/^\d{5}$/', $codePostal) !== 1) {
            $codePostal = null;
        }
        $theme = $this->texte($brut, 'theme');
        if ($theme !== null && mb_strlen($theme) > 120) {
            throw new InvalidArgumentException('theme_trop_long');
        }

        $journaliste = null;
        if (array_key_exists('journaliste', $brut) && $brut['journaliste'] !== null) {
            $j = $brut['journaliste'];
            if (! is_array($j) || array_is_list($j) || array_diff(array_keys($j), self::CLES_JOURNALISTE) !== []) {
                throw new InvalidArgumentException('journaliste_invalide');
            }
            $nomJ = $this->texte($j, 'nom');
            if ($nomJ === null) {
                throw new InvalidArgumentException('journaliste_sans_nom');
            }
            $acces = $this->texte($j, 'acces');
            if ($acces !== null && ! in_array($acces, Taxonomy::ACCES_PRESSE, true)) {
                throw new InvalidArgumentException('acces_inconnu');
            }
            $journaliste = [
                'acces' => $acces,
                'prenom' => $this->texte($j, 'prenom'),
                'nom' => $nomJ,
                'fonction' => $this->texte($j, 'fonction'),
                'rubrique' => $this->texte($j, 'rubrique'),
                'email' => $this->email($this->texte($j, 'email')),
                'linkedin' => $this->lien($this->texte($j, 'linkedin')),
            ];
        }

        return [
            'siren' => $siren,
            'identifiant' => $identifiant,
            'nom' => mb_substr($nom, 0, 240),
            'type' => $type,
            'zone' => $zone,
            'departement' => $departement,
            'ville' => $this->texte($brut, 'ville'),
            'code_postal' => $codePostal,
            'site' => $this->lien($this->texte($brut, 'site')),
            'email_redaction' => $this->email($this->texte($brut, 'email_redaction')),
            'telephone' => $this->texte($brut, 'telephone'),
            'theme' => $theme,
            'journaliste' => $journaliste,
        ];
    }

    public static function identifiantValide(string $identifiant): bool
    {
        return strlen($identifiant) <= self::IDENTIFIANT_MAX
            && preg_match(self::MOTIF_IDENTIFIANT, $identifiant) === 1;
    }

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

    /** Une adresse sans « @ » n'est pas une adresse : écartée, pas rejetée. */
    private function email(?string $valeur): ?string
    {
        return $valeur === null || filter_var($valeur, FILTER_VALIDATE_EMAIL) === false ? null : mb_strtolower($valeur);
    }

    /** Seul un lien http(s) entre en base. */
    private function lien(?string $valeur): ?string
    {
        return $valeur !== null && preg_match('#^https?://#i', $valeur) === 1 ? $valeur : null;
    }
}
