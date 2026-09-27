<?php

namespace App\Console\Commands;

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
            'liens_crees' => 0, 'organisateurs_introuvables' => 0, 'sans_organisateur' => 0,
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

        $this->info($dryRun ? '[À BLANC] rien n\'a été écrit.' : 'Import appliqué.');
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

        return self::SUCCESS;
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
                    // annulée seule, le reste de l'import continue.
                    DB::transaction(fn () => $this->importerLigne($ligne, $workspaceId));
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

    private function importerLigne(string $ligne, string $workspaceId): void
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

        $valeurs = ['type' => $type, 'appel_intervenants' => $appel, 'verifie' => ($brut['verifie'] ?? false) === true];
        foreach (self::CHAMPS_TEXTE as $champ) {
            $valeurs[$champ] = $this->texte($brut, $champ);
        }
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
        $companyIds = [];
        foreach ($ancres as $ancre) {
            if (! is_array($ancre)) {
                throw new InvalidArgumentException('organisateurs_invalides');
            }
            $id = $this->organisateur($ancre, $workspaceId);
            if ($id === null) {
                $this->bilan['organisateurs_introuvables']++;

                continue;
            }
            $companyIds[$id] = true;
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
            $this->bilan['crees']++;
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
                $this->bilan['mis_a_jour']++;
            } else {
                $this->bilan['inchanges']++;
            }
        }

        if ($companyIds === []) {
            $this->bilan['sans_organisateur']++;

            return;
        }

        foreach (array_keys($companyIds) as $companyId) {
            $this->bilan['liens_crees'] += DB::table('event_organizers')->insertOrIgnore([
                'event_id' => $eventId,
                'company_id' => $companyId,
                'workspace_id' => $workspaceId,
                'created_at' => now(),
            ]);
        }
    }

    /** @param  array<mixed>  $ancre */
    private function organisateur(array $ancre, string $workspaceId): ?int
    {
        $requete = DB::table('companies')->where('workspace_id', $workspaceId)->whereNull('deleted_at');

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

        $id = $requete->value('id');

        return $id === null ? null : (int) $id;
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

    private function rejeter(string $motif, int $numero): void
    {
        $this->bilan['rejetes']++;
        $this->rejets[$motif] = ($this->rejets[$motif] ?? 0) + 1;
        if ($this->output->isVerbose()) {
            $this->line("  ligne {$numero} : {$motif}");
        }
    }
}
