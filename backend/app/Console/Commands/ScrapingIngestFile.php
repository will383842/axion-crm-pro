<?php

namespace App\Console\Commands;

use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestRejection;
use Illuminate\Console\Command;
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

                $outcome = $ingest->ingest(ScrapedRecord::fromArray($decoded), $dryRun);
                $counts[$outcome->status] = ($counts[$outcome->status] ?? 0) + 1;
            } catch (ScrapeIngestRejection $e) {
                $errors++;
                $this->warn("ligne {$line} : {$e->errorCode} — {$e->getMessage()}");
            } catch (Throwable $e) {
                $errors++;
                $this->warn("ligne {$line} : " . $e->getMessage());
            }
        }
        fclose($handle);

        $this->info(($dryRun ? '[DRY-RUN — rien n\'est écrit] ' : '') . 'Terminé.');
        foreach ($counts as $status => $count) {
            $this->line("  {$status} : {$count}");
        }
        if ($errors > 0) {
            $this->warn("  refusées : {$errors}");
        }

        // Un import dont TOUTES les lignes sont refusées est un échec, pas un
        // succès silencieux.
        return $counts === [] && $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
