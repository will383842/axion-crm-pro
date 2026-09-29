<?php

namespace App\Console\Commands;

use App\Crm\Doublons\FusionFiches;
use App\Crm\Referentiels\Classement;
use App\Crm\Taxonomy;
use App\Services\Audit\AuditHashChain;
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
 * Importe les ÉVÉNEMENTS professionnels et les relie à leurs organisateurs.
 *
 * Les organisateurs (et leurs contacts) entrent AVANT, par la porte commune
 * `scraping:ingest-file evenements-pro …` : ce qui les protège (tag de
 * provenance, `FichesProtegees`), les oppositions et la dédup vivent là.
 * Cette commande ne crée AUCUNE fiche entreprise ni personne : un organisateur
 * absent est compté, jamais inventé.
 *
 * Une ligne JSON par événement (clés inconnues refusées) ; `organisateurs` est
 * une liste d'ancres `{"siren": "…"}` ou `{"country": "FR", "foreign_id": "…"}`.
 *
 * ⚠️ L'essai à blanc passe par le MÊME chemin que l'import réel, dans UNE
 * seule transaction annulée à la fin : un organisateur partagé par 26 lignes
 * y est compté comme en réel. (Une transaction par ligne l'aurait annoncé
 * « créé » 26 fois.)
 *
 * Un ré-import met à jour la description d'un événement, jamais la démarche
 * de Will (`participation`, `intervention`, relance, note).
 */
class CrmImportEvenements extends Command
{
    protected $signature = 'crm:import-evenements
                            {file : Fichier JSONL, une ligne par événement (hors dépôt)}
                            {--dry-run : Tout parcourir puis tout annuler, et afficher le bilan}';

    protected $description = 'Importe les événements professionnels et les relie aux organisateurs déjà en base.';

    /** Colonnes descriptives, mises à jour par un ré-import. */
    private const CHAMPS_TEXTE = [
        'nom', 'recurrence', 'heure', 'lieu', 'ville', 'departement_code', 'region',
        'public_vise', 'taille', 'prix', 'lien_evenement', 'lien_inscription',
        'source_url', 'notes',
    ];

    private const CHAMPS_DATE = ['date_debut', 'date_fin', 'appel_intervenants_limite'];

    private const CLES_AUTORISEES = [
        'external_ref', 'type', 'appel_intervenants', 'verifie', 'organisateurs',
        ...self::CHAMPS_TEXTE,
        ...self::CHAMPS_DATE,
    ];

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> motif => nombre */
    private array $rejets = [];

    public function handle(AuditHashChain $audit): int
    {
        $chemin = (string) $this->argument('file');
        if (! is_file($chemin) || ! is_readable($chemin)) {
            $this->error("Fichier illisible : {$chemin}");

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error("Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $workspaceId = (string) $workspaceId;
        $dryRun = (bool) $this->option('dry-run');

        $this->bilan = [
            'lignes' => 0, 'crees' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'rejetes' => 0,
            'liens_crees' => 0, 'liens_via_une_fusion' => 0, 'organisateurs_introuvables' => 0, 'sans_organisateur' => 0,
            'notes_expurgees' => 0, 'regions_inconnues' => 0,
        ];
        $this->rejets = [];

        WorkspaceContext::run($workspaceId, function () use ($chemin, $workspaceId, $dryRun): void {
            DB::beginTransaction();
            try {
                $this->importer($chemin, $workspaceId);
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }
            // À blanc : on annule APRÈS le parcours complet — le bilan est
            // celui qu'aurait produit l'import réel.
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
                'method' => 'IMPORT_EVENEMENTS',
                'path' => 'artisan crm:import-evenements',
                'status' => 200,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', json_encode($this->bilan, JSON_THROW_ON_ERROR)),
            ]);
        }

        // Une base qui refuse (RLS sans contexte, contrainte) ne doit JAMAIS
        // ressembler à un import réussi : chaque ligne serait rangée en
        // `erreur_base` et la sortie resterait au vert.
        $echec = ($this->rejets['erreur_base'] ?? 0) > 0
            || ($this->bilan['lignes'] > 0 && $this->bilan['rejetes'] === $this->bilan['lignes']);

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
            $this->warn('Lignes rejetées, par motif :');
            foreach ($this->rejets as $motif => $n) {
                $this->line("  {$motif} : {$n}");
            }
        }

        return $echec ? self::FAILURE : self::SUCCESS;
    }

    private function importer(string $chemin, string $workspaceId): void
    {
        $flux = fopen($chemin, 'rb');
        if ($flux === false) {
            throw new RuntimeException("Ouverture impossible : {$chemin}");
        }

        try {
            $numero = 0;
            while (($ligne = fgets($flux)) !== false) {
                $numero++;
                if (trim($ligne) === '') {
                    continue;
                }
                $this->bilan['lignes']++;

                try {
                    // Un point de sauvegarde par ligne : une ligne fautive est
                    // annulée seule, le reste de l'import continue. Ses
                    // compteurs ne sont reportés QUE si elle aboutit.
                    $delta = DB::transaction(fn (): array => $this->importerLigne($ligne, $workspaceId));
                    foreach ($delta as $compteur => $n) {
                        $this->bilan[$compteur] += $n;
                    }
                } catch (InvalidArgumentException $e) {
                    // Motif produit par ce fichier (jamais une valeur de la ligne).
                    $this->rejeter($e->getMessage(), $numero);
                } catch (QueryException $e) {
                    // Le message SQL peut citer une valeur de la ligne : seul le
                    // code d'état part au journal.
                    $this->rejeter('erreur_base', $numero);
                    Log::warning('crm:import-evenements : ligne refusee par la base', [
                        'ligne' => $numero,
                        'sqlstate' => $e->getCode(),
                    ]);
                }
            }
        } finally {
            fclose($flux);
        }
    }

    /** @return array<string, int> compteurs de CETTE ligne */
    private function importerLigne(string $ligne, string $workspaceId): array
    {
        $delta = [];
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

        $ref = $this->texte($brut, 'external_ref');
        $nom = $this->texte($brut, 'nom');
        if ($ref === null || $nom === null) {
            throw new InvalidArgumentException('champ_obligatoire_manquant');
        }

        $type = $this->texte($brut, 'type');
        if ($type === null || ! in_array($type, Taxonomy::EVENEMENT_TYPES, true)) {
            throw new InvalidArgumentException('type_inconnu');
        }

        $appel = $this->texte($brut, 'appel_intervenants') ?? 'inconnu';
        if (! in_array($appel, Taxonomy::EVENEMENT_APPELS_INTERVENANTS, true)) {
            throw new InvalidArgumentException('appel_intervenants_inconnu');
        }

        $verifie = $brut['verifie'] ?? false;
        if (! is_bool($verifie)) {
            // « "true" » ou « 1 » deviendraient `false` en silence, et un
            // ré-import retirerait une vérification.
            throw new InvalidArgumentException('type_de_valeur_invalide');
        }

        $valeurs = ['type' => $type, 'appel_intervenants' => $appel, 'verifie' => $verifie];
        foreach (self::CHAMPS_TEXTE as $champ) {
            $valeurs[$champ] = $this->texte($brut, $champ);
        }
        // Région : UN seul codage, le code INSEE (`AURA` → `84`), comme
        // `companies.region_code` — sinon on ne peut pas croiser « événements
        // et organisations d'une même région ». Une région absente se déduit
        // du département. Une région qui n'est pas française (« England »,
        // « California ») ne fait PAS rejeter l'événement : la colonne reste
        // vide (le CHECK `events_region_check` n'accepte que les codes) et la
        // valeur lue est recopiée dans les notes — exactement comme la
        // migration l'a fait pour les événements déjà en base.
        $regionLue = $this->texte($brut, 'region');
        $regionInconnue = null;
        if ($regionLue !== null) {
            $valeurs['region'] = Classement::region($regionLue);
            if ($valeurs['region'] === null) {
                $regionInconnue = $regionLue;
            }
        } else {
            $valeurs['region'] = Classement::regionDuDepartement($this->texte($brut, 'departement_code'));
        }
        $notesLues = $this->texte($brut, 'notes');
        $notes = $this->expurger($notesLues);
        if ($notes !== $notesLues) {
            $delta['notes_expurgees'] = 1;
        }
        if ($regionInconnue !== null) {
            // Recalculée depuis le FICHIER à chaque import : un réimport rend
            // la même note, donc « inchangé » — jamais une ligne de plus.
            $notes = Classement::noteRegionDOrigine($notes, (string) $this->expurger($regionInconnue));
            $delta['regions_inconnues'] = 1;
        }
        $valeurs['notes'] = $notes;
        foreach (self::CHAMPS_DATE as $champ) {
            $valeurs[$champ] = $this->date($brut, $champ);
        }
        if ($valeurs['date_debut'] !== null && $valeurs['date_fin'] !== null && $valeurs['date_fin'] < $valeurs['date_debut']) {
            throw new InvalidArgumentException('dates_inversees');
        }

        $ancres = $brut['organisateurs'] ?? [];
        if (! is_array($ancres)) {
            throw new InvalidArgumentException('organisateurs_invalides');
        }
        /** @var array<int, list<int>> $companyIds fiche => fusions suivies pour la trouver (chaîne) */
        $companyIds = [];
        foreach ($ancres as $ancre) {
            if (! is_array($ancre)) {
                throw new InvalidArgumentException('organisateurs_invalides');
            }
            $trouve = $this->organisateur($ancre, $workspaceId);
            if ($trouve === null) {
                $delta['organisateurs_introuvables'] = ($delta['organisateurs_introuvables'] ?? 0) + 1;

                continue;
            }
            $companyIds[$trouve['id']] = $trouve['fusions'];
        }

        $existant = DB::table('events')
            ->where('workspace_id', $workspaceId)
            ->where('external_ref', $ref)
            ->first();

        if ($existant === null) {
            $eventId = (int) DB::table('events')->insertGetId([
                'workspace_id' => $workspaceId,
                'external_ref' => $ref,
                'created_at' => now(),
                'updated_at' => now(),
            ] + $valeurs);
            $delta['crees'] = 1;
        } else {
            $eventId = (int) $existant->id;
            $change = false;
            foreach ($valeurs as $champ => $valeur) {
                $actuel = $existant->{$champ};
                if ($champ === 'verifie') {
                    $actuel = (bool) $actuel;
                }
                if ($actuel !== $valeur) {
                    $change = true;
                    break;
                }
            }
            if ($change) {
                // Seule la DESCRIPTION : la démarche de Will n'est jamais touchée.
                DB::table('events')->where('id', $eventId)->update($valeurs + ['updated_at' => now()]);
                $delta['mis_a_jour'] = 1;
            } else {
                $delta['inchanges'] = 1;
            }
        }

        if ($companyIds === []) {
            $delta['sans_organisateur'] = 1;

            return $delta;
        }

        $delta['liens_crees'] = 0;
        foreach ($companyIds as $companyId => $fusions) {
            $cree = DB::table('event_organizers')->insertOrIgnore([
                'event_id' => $eventId,
                'company_id' => $companyId,
                'workspace_id' => $workspaceId,
                'created_at' => now(),
            ]);
            $delta['liens_crees'] += $cree;
            // Posé sur une fiche GARDÉE en suivant l'ancre d'une fiche absorbée :
            // inscrit au journal de la fusion, que l'annulation rend à l'absorbée.
            if ($cree > 0 && $fusions !== []) {
                // Au journal de CHAQUE fusion de la chaîne (A→B puis B→C) :
                // annulées dans l'ordre inverse, elles ramènent le lien jusqu'à
                // la fiche d'origine.
                foreach ($fusions as $fusion) {
                    FusionFiches::noterRattachement($workspaceId, $fusion, 'event_organizers', $eventId);
                }
                $delta['liens_via_une_fusion'] = ($delta['liens_via_une_fusion'] ?? 0) + 1;
            }
        }

        return $delta;
    }

    /**
     * L'organisateur par son ancre. Une fiche à la corbeille n'est jamais
     * reliée — sauf si une FUSION l'a absorbée (chantier 5) : l'événement se
     * relie alors à la fiche gardée, et le lien est inscrit au journal de la
     * fusion (`fusions`), pour que l'annulation le défasse.
     *
     * @param  array<mixed>  $ancre
     * @return array{id: int, fusions: list<int>}|null
     */
    private function organisateur(array $ancre, string $workspaceId): ?array
    {
        // Corbeille comprise, SCIEMMENT : `deleted_at` est lu pour suivre une fusion.
        $requete = DB::table('companies')->where('workspace_id', $workspaceId)->select(['id', 'deleted_at']);

        $siren = $ancre['siren'] ?? null;
        $foreignId = $ancre['foreign_id'] ?? null;
        if (is_string($siren) && preg_match('/^\d{9}$/', $siren) === 1) {
            $requete->where('siren', $siren);
        } elseif (is_string($foreignId) && trim($foreignId) !== '') {
            $pays = is_string($ancre['country'] ?? null) ? strtoupper(trim($ancre['country'])) : '';
            if (preg_match('/^[A-Z]{2}$/', $pays) !== 1) {
                throw new InvalidArgumentException('organisateur_sans_pays');
            }
            $requete->where('country_code', $pays)->where('foreign_id', trim($foreignId));
        } else {
            throw new InvalidArgumentException('organisateur_sans_ancre');
        }

        $fiche = $requete->first();
        if ($fiche === null) {
            return null;
        }
        if ($fiche->deleted_at === null) {
            return ['id' => (int) $fiche->id, 'fusions' => []];
        }
        $renvoi = FusionFiches::gardeDe($workspaceId, (int) $fiche->id);
        if ($renvoi === null) {
            return null;
        }
        $vivante = DB::table('companies')->where('workspace_id', $workspaceId)
            ->where('id', $renvoi['garde'])->whereNull('deleted_at')->exists();

        return $vivante ? ['id' => $renvoi['garde'], 'fusions' => $renvoi['fusions']] : null;
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

    /**
     * `notes` est un extrait de page publique : « Contact : 06… / x@y » y est
     * courant. Aucune coordonnée n'y reste — elle échapperait à l'effacement
     * RGPD, qui cherche par personne. Les coordonnées vont dans `contacts`,
     * par la porte d'ingestion.
     */
    private function expurger(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $propre = (string) preg_replace(
            [
                '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
                '/(?:\+33\s?|0)[1-9](?:[\s.\-]?\d{2}){4}/u',
            ],
            '[coordonnée retirée]',
            $notes,
        );

        return $propre;
    }

    private function rejeter(string $motif, int $numero): void
    {
        $this->bilan['rejetes']++;
        $this->rejets[$motif] = ($this->rejets[$motif] ?? 0) + 1;
        if ($this->output->isVerbose()) {
            $this->line("  ligne {$numero} : {$motif}");
        }
    }
}
