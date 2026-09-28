<?php

namespace App\Console\Commands;

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
 * La clé est le SIREN. Une fiche déjà présente (un organisateur d'événement,
 * une CCI) est RATTACHÉE : elle reçoit sa ligne `federations`, garde sa nature,
 * sa démarche (`events.participation/intervention`, `relation_type`,
 * `lifecycle_stage`) et ses étiquettes. Une fiche à la corbeille n'est pas
 * ressuscitée : la ligne est rejetée.
 *
 * ── Idempotente ───────────────────────────────────────────────────────────
 *
 * Rejouer le même fichier ne crée rien : le funnel reconnaît le même contenu
 * (`run_id` = SIREN + empreinte de la ligne), la ligne `federations` identique
 * est comptée « inchangée ». Un ré-import ne touche JAMAIS la démarche de Will
 * (`partenariat`, relance, note). Une tête de réseau absente du fichier ne
 * retire pas celle qui est posée.
 *
 * ── Essai à blanc ─────────────────────────────────────────────────────────
 *
 * `--dry-run` passe par le MÊME chemin que l'import réel, dans UNE transaction
 * annulée à la fin : la deuxième passe voit les fiches que la première aurait
 * créées, un contact partagé est compté une fois, et le bilan est celui que
 * l'import réel produira. `--limite=N` ne traite que les N premières lignes
 * (import par étapes : 10 fiches, puis tout).
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
                            {--limite= : Ne traiter que les N premières lignes (import par étapes)}';

    protected $description = 'Importe les fédérations et organisations professionnelles, et relie les têtes de réseau.';

    private const CLES_AUTORISEES = [
        'siren', 'nom', 'nom_developpe', 'sigle', 'nature',
        'naf', 'forme_juridique', 'effectif', 'date_creation', 'nb_etablissements',
        'adresse', 'code_postal', 'commune', 'departement', 'region',
        'famille', 'niveau', 'secteurs', 'tailles_adherents', 'certitude', 'pertinence',
        'contactabilite', 'origine_classement',
        'email_generique', 'emails_autres', 'telephone', 'telephones_autres', 'site', 'linkedin',
        'personnes', 'tete_de_reseau',
    ];

    private const CLES_PERSONNE = ['prenom', 'nom', 'fonction', 'email', 'linkedin'];

    /** Département accepté par le schéma pivot (`ScrapedRecord`). */
    private const MOTIF_DEPARTEMENT = '/^(0[1-9]|1\d|2[1-9AB]|[3-8]\d|9[0-5]|97[1-6])$/';

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> motif => nombre */
    private array $rejets = [];

    /** @var array<int, string> company_id => SIREN de la tête de réseau */
    private array $tetes = [];

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
            'lignes', 'rejetees',
            'fiches_creees', 'fiches_rattachees', 'federations_mises_a_jour', 'federations_inchangees',
            'contacts_crees', 'contacts_completes', 'personnes_sans_changement', 'personnes_ecartees',
            'personnes_opposees', 'emails_refuses_mx',
            'secteurs_poses', 'secteurs_conserves', 'departements_ignores',
            'tetes_liees', 'tetes_inchangees', 'tetes_introuvables', 'tetes_refusees_cycle',
        ], 0);
        $this->rejets = [];
        $this->tetes = [];

        WorkspaceContext::run($workspaceId, function () use ($chemin, $workspaceId, $dryRun, $limite): void {
            DB::beginTransaction();
            try {
                $this->premierePasse($chemin, $workspaceId, $limite);
                $this->deuxiemePasse($workspaceId);
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }
            // À blanc : on annule APRÈS les deux passes — le bilan est celui
            // qu'aurait produit l'import réel.
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        });

        if (! $dryRun) {
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

    private function premierePasse(string $chemin, string $workspaceId, ?int $limite): void
    {
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new RuntimeException('Ouverture impossible du fichier.');
        }

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

                try {
                    // Un point de sauvegarde par ligne : une ligne fautive est
                    // annulée seule. Ses compteurs ne sont reportés QUE si elle
                    // aboutit.
                    [$delta, $companyId, $tete] = DB::transaction(fn (): array => $this->importerLigne($ligne, $workspaceId));
                    foreach ($delta as $compteur => $n) {
                        $this->bilan[$compteur] += $n;
                    }
                    if ($tete !== null) {
                        $this->tetes[$companyId] = $tete;
                    }
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
            }
        } finally {
            fclose($flux);
        }
    }

    /**
     * @return array{0: array<string, int>, 1: int, 2: ?string} compteurs de CETTE ligne, fiche, SIREN de sa tête
     */
    private function importerLigne(string $ligne, string $workspaceId): array
    {
        $l = $this->lire($ligne);
        $delta = [];

        $avant = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->where('siren', $l['siren'])
            ->first(['id', 'deleted_at']);
        if ($avant !== null && $avant->deleted_at !== null) {
            // Mise à la corbeille par Will : un import ne la ressuscite pas.
            throw new InvalidArgumentException('fiche_a_la_corbeille');
        }
        $ligneAvant = $avant === null ? null : EtiquettesFederation::ligne((int) $avant->id);

        if ($l['departement_ignore']) {
            $delta['departements_ignores'] = 1;
        }

        $outcome = $this->funnel->ingest(ScrapedRecord::fromArray($this->pivot($l)), false);
        if (! in_array($outcome->status, [ScrapeIngestOutcome::CREATED, ScrapeIngestOutcome::UPDATED, ScrapeIngestOutcome::IDEMPOTENT], true)) {
            throw new InvalidArgumentException('pivot_statut_inattendu');
        }
        $delta['contacts_crees'] = $outcome->contactsCreated;
        $delta['contacts_completes'] = $outcome->contactsUpdated;
        $delta['personnes_opposees'] = $outcome->personsSkippedOptOut;
        $delta['emails_refuses_mx'] = $outcome->emailsRejectedMx;
        $delta['personnes_sans_changement'] = $outcome->personsSkipped['skipped_no_change'] ?? 0;
        $delta['personnes_ecartees'] = (int) array_sum($outcome->personsSkipped) - $delta['personnes_sans_changement'];

        $fiche = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->where('siren', $l['siren'])
            ->first();
        if ($fiche === null) {
            throw new RuntimeException('fiche_introuvable_apres_ingestion');
        }
        $companyId = (int) $fiche->id;

        $delta += $this->completerFiche($fiche, $l);

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

        return [$delta, $companyId, $l['tete_de_reseau']];
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

        $principal = $l['secteurs'][0] ?? null;
        if ($principal !== null) {
            $actuel = $fiche->sector_main ?? null;
            $utile = is_string($actuel) && $actuel !== Taxonomy::SECTEUR_NON_CLASSE && array_key_exists($actuel, Taxonomy::SECTEURS);
            if (! $utile) {
                $maj['sector_main'] = $principal;
                $origines['sector_main'] = 'collected';
                $delta['secteurs_poses'] = 1;
            } elseif ($actuel !== $principal) {
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

    private function deuxiemePasse(string $workspaceId): void
    {
        foreach ($this->tetes as $companyId => $sirenTete) {
            $teteId = DB::table('companies')
                ->where('workspace_id', $workspaceId)
                ->where('siren', $sirenTete)
                ->whereNull('deleted_at')
                ->value('id');
            if ($teteId === null) {
                $this->bilan['tetes_introuvables']++;

                continue;
            }
            $teteId = (int) $teteId;

            $actuelle = DB::table('federations')->where('company_id', $companyId)->value('parent_company_id');
            if ($actuelle !== null && (int) $actuelle === $teteId) {
                $this->bilan['tetes_inchangees']++;

                continue;
            }

            try {
                // Point de sauvegarde : un cycle refusé par la base n'annule que ce lien.
                DB::transaction(function () use ($companyId, $teteId): void {
                    DB::table('federations')->where('company_id', $companyId)
                        ->update(['parent_company_id' => $teteId, 'updated_at' => now()]);
                });
                $this->bilan['tetes_liees']++;
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'federation_cycle')) {
                    throw $e;
                }
                $this->bilan['tetes_refusees_cycle']++;
            }
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

        $siren = $this->texte($brut, 'siren');
        if ($siren === null || preg_match('/^\d{9}$/', $siren) !== 1) {
            throw new InvalidArgumentException('siren_invalide');
        }
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
        if ($tete !== null && (preg_match('/^\d{9}$/', $tete) !== 1 || $tete === $siren)) {
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
                'linkedin_url' => $this->lien($this->texte($p, 'linkedin')),
            ];
        }

        return [
            'siren' => $siren,
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
            'emails_autres' => array_values(array_filter(array_map(fn (string $e): ?string => $this->email($e), $this->chaines($brut, 'emails_autres')))),
            'telephone' => $this->texte($brut, 'telephone'),
            'telephones_autres' => $this->chaines($brut, 'telephones_autres'),
            'site' => $this->lien($this->texte($brut, 'site')),
            'linkedin' => $this->lien($this->texte($brut, 'linkedin')),
            'personnes' => $propres,
            'tete_de_reseau' => $tete,
        ];
    }

    /**
     * Le message du schéma pivot commun (`ScrapedRecord`) pour cette ligne.
     *
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    private function pivot(array $l): array
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
            $personnes[] = array_filter($p + ['kind' => 'person'], static fn (mixed $v): bool => $v !== null);
        }

        $message = [
            'schema_version' => ScrapedRecord::SCHEMA_VERSION,
            'source' => self::SOURCE,
            'status' => 'success',
            'company' => [
                'siren' => $l['siren'],
                'country' => 'FR',
                'nature' => $l['nature'],
                'fields' => $champs,
            ],
            'persons' => $personnes,
            'channels' => [
                'emails' => $l['emails_autres'],
                'phones' => $l['telephones_autres'],
            ],
        ];

        // Le MÊME contenu rejoué = le même run : le funnel le reconnaît et
        // n'écrit rien (idempotence). Un contenu corrigé = un nouveau run, qui
        // complète la fiche (backfill-only).
        $message['run_id'] = self::SOURCE . ':' . $l['siren'] . ':'
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

    private function rejeter(string $motif, int $numero): void
    {
        $this->bilan['rejetees']++;
        $this->rejets[$motif] = ($this->rejets[$motif] ?? 0) + 1;
        if ($this->output->isVerbose()) {
            $this->line("  ligne {$numero} : {$motif}");
        }
    }
}
