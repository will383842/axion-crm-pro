<?php

namespace App\Console\Commands;

use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestRejection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import one-shot de `ScrapedRecord` depuis un fichier JSONL (lot L3, audit
 * §C.3 porte n°3) — le schéma pivot sert AUSSI de format d'import batch :
 * un CSV acheté, une liste de salon, un export LinkedIn light se convertissent
 * en JSONL du pivot et passent par le MÊME funnel que tout le monde (dédup,
 * opt-out, MX, backfill-only, tags, timeline). Aucun import ne touche la base
 * directement.
 *
 * `--dry-run` parcourt le vrai funnel puis ROLLBACK : ce qui est annoncé est
 * exactement ce qu'un run réel ferait.
 */
class ScrapingIngestFile extends Command
{
    protected $signature = 'scraping:ingest-file
        {source : Slug du registre scraping_sources (doit exister et être enabled)}
        {file : Chemin du fichier JSONL (un ScrapedRecord par ligne)}
        {--dry-run : Parcourt le funnel puis annule tout (aucune écriture)}';

    protected $description = 'Ingère un fichier JSONL de ScrapedRecord par le funnel unique de collecte';

    public function handle(ScrapedRecordIngestService $ingest): int
    {
        $source = (string) $this->argument('source');
        $file = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_readable($file)) {
            $this->error("Fichier illisible : {$file}");

            return self::FAILURE;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            $this->error("Ouverture impossible : {$file}");

            return self::FAILURE;
        }

        $line = 0;
        $counts = [];
        $errors = 0;
        /** @var array<string, int> $personnes */
        $personnes = [];

        // ESSAI À BLANC FIDÈLE (2026-09-27). Le service annulait CHAQUE ligne
        // dans sa propre transaction : un organisateur présent sur 26 lignes y
        // était annoncé « créé » 26 fois, et aucune personne n'était comptée.
        // Désormais : UNE transaction pour tout le fichier, le service écrit
        // « pour de vrai » dedans, et tout est annulé à la fin — le bilan est
        // exactement celui de l'import réel.
        // ⚠️ Pendant un essai à blanc, les lignes touchées restent verrouillées
        // jusqu'à la fin du fichier : à lancer hors des heures d'écriture. Une
        // erreur de CONCURRENCE (interblocage) dans une ligne laisse la
        // transaction globale avortée — les lignes suivantes sont alors
        // comptées refusées : relancer l'essai.
        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            while (($raw = fgets($handle)) !== false) {
                $line++;
                $raw = trim($raw);
                if ($raw === '') {
                    continue;
                }

                try {
                    $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($decoded)) {
                        throw new \JsonException('la ligne n\'est pas un objet');
                    }

                    // La source de la ligne DOIT être celle annoncée : un fichier
                    // ne peut pas mélanger les provenances en silence.
                    if (($decoded['source'] ?? null) !== $source) {
                        throw ScrapeIngestRejection::invalid(
                            'source_mismatch',
                            'source de la ligne (' . var_export($decoded['source'] ?? null, true) . ") ≠ source annoncée ({$source}).",
                        );
                    }

                    $outcome = $ingest->ingest(ScrapedRecord::fromArray($decoded), false);
                    $counts[$outcome->status] = ($counts[$outcome->status] ?? 0) + 1;

                    $cumul = [
                        'contacts_crees' => $outcome->contactsCreated,
                        'contacts_completes' => $outcome->contactsUpdated,
                        'personnes_opposees' => $outcome->personsSkippedOptOut,
                        'emails_sans_serveur' => $outcome->emailsRejectedMx,
                        'chaines_de_fusion_tronquees' => $outcome->chainesFusionTronquees,
                    ];
                    foreach ($outcome->personsSkipped as $motif => $n) {
                        $cumul['personnes_' . $motif] = $n;
                    }
                    foreach ($cumul as $cle => $n) {
                        $personnes[$cle] = ($personnes[$cle] ?? 0) + $n;
                    }
                } catch (ScrapeIngestRejection $e) {
                    $errors++;
                    $this->warn("ligne {$line} : {$e->errorCode} — {$e->getMessage()}");
                } catch (Throwable $e) {
                    $errors++;
                    // Le message d'une erreur SQL peut citer une valeur de la
                    // ligne (une adresse) : seule la classe est affichée.
                    $this->warn("ligne {$line} : erreur " . $e::class);
                }
            }
        } finally {
            fclose($handle);
            if ($dryRun) {
                DB::rollBack();
            }
        }

        $this->info(($dryRun ? '[DRY-RUN — rien n\'est écrit] ' : '') . 'Terminé.');
        foreach ($counts as $status => $count) {
            $this->line("  {$status} : {$count}");
        }
        foreach ($personnes as $cle => $n) {
            $this->line("  {$cle} : {$n}");
        }
        if ($errors > 0) {
            $this->warn("  refusées : {$errors}");
        }

        // Un import dont TOUTES les lignes sont refusées est un échec, pas un
        // succès silencieux.
        return $counts === [] && $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
