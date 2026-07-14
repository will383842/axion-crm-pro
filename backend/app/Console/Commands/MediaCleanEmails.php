<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * media:clean-emails — purge des emails médias parasites ou « sur-partagés ».
 *
 * Deux fléaux repérés (mêmes que sur la base entreprises, qui a demandé 5 passes) :
 *  1. PARASITES : artefacts tracking/assets/parking (Sentry/Wix, hashs, domainmarket,
 *     readymag, unitedthemes…). Mêmes motifs que MediaEnrich::PARASITE_PATTERNS.
 *  2. SUR-PARTAGÉS : un même email présent sur > --threshold médias distincts = une
 *     PLATEFORME (DSN Sentry commun à tous les sites Wix, email d'un builder/registrar),
 *     jamais le vrai contact d'un média. Heuristique robuste (attrape la longue traîne
 *     sans blocklist exhaustive).
 *
 * Action : media.email → NULL, retrait de socials.contact_channels.emails, et
 * enrich_status → 'pending' si le média n'a plus aucun signal (ni email ni téléphone).
 * `--dry-run` compte sans écrire.
 */
class MediaCleanEmails extends Command
{
    protected $signature = 'media:clean-emails {--threshold=10 : Au-delà de N médias partageant le même email → plateforme} {--dry-run}';

    protected $description = 'Purge les emails médias parasites ou sur-partagés (plateformes/parking).';

    public function handle(): int
    {
        $threshold = max(2, (int) $this->option('threshold'));
        $dry = (bool) $this->option('dry-run');

        // 1) Emails sur-partagés (> threshold médias distincts).
        $shared = DB::table('media')
            ->select('email')
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->groupBy('email')
            ->havingRaw('count(*) > ?', [$threshold])
            ->pluck('email')
            ->all();

        // 2) Emails parasites (motifs). On les récupère pour les nuller aussi.
        $parasites = DB::table('media')
            ->select('email')
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->get()
            ->pluck('email')
            ->filter(fn ($e) => $this->isParasite((string) $e))
            ->unique()
            ->values()
            ->all();

        $toNull = array_values(array_unique(array_merge($shared, $parasites)));

        if (empty($toNull)) {
            $this->info('Aucun email parasite ni sur-partagé à purger.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d email(s) distinct(s) à purger (%d sur-partagés, %d parasites)%s.',
            count($toNull),
            count($shared),
            count($parasites),
            $dry ? ' [dry-run]' : ''
        ));

        if ($dry) {
            foreach (array_slice($toNull, 0, 30) as $e) {
                $this->line("  - {$e}");
            }

            return self::SUCCESS;
        }

        $nulled = 0;
        foreach (array_chunk($toNull, 500) as $chunk) {
            // media.email → NULL (+ enrich_status pending si plus aucun signal).
            $nulled += DB::table('media')
                ->whereIn('email', $chunk)
                ->update([
                    'email'         => null,
                    'enrich_status' => DB::raw("CASE WHEN phone IS NULL THEN 'pending' ELSE enrich_status END"),
                    'updated_at'    => now(),
                ]);
        }

        // Retrait des emails purgés de socials.contact_channels.emails (fiche par fiche).
        $toNullLower = array_map('strtolower', $toNull);
        $cleanedSocials = 0;
        DB::table('media')
            ->whereNull('deleted_at')
            ->whereRaw("(socials->'contact_channels'->>'emails') IS NOT NULL")
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($toNullLower, &$cleanedSocials) {
                foreach ($rows as $m) {
                    $socials = json_decode((string) $m->socials, true);
                    if (! is_array($socials) || empty($socials['contact_channels']['emails'])) {
                        continue;
                    }
                    $before = $socials['contact_channels']['emails'];
                    $after = array_values(array_filter(
                        $before,
                        fn ($e) => ! in_array(strtolower((string) $e), $toNullLower, true)
                    ));
                    if (count($after) !== count($before)) {
                        $socials['contact_channels']['emails'] = $after;
                        DB::table('media')->where('id', $m->id)->update(['socials' => json_encode($socials)]);
                        $cleanedSocials++;
                    }
                }
            });

        $this->info("Purge terminée : {$nulled} email principal nullifié(s), {$cleanedSocials} liste(s) socials nettoyée(s).");

        return self::SUCCESS;
    }

    private function isParasite(string $email): bool
    {
        foreach (MediaEnrich::PARASITE_PATTERNS as $rx) {
            if (preg_match($rx, $email) === 1) {
                return true;
            }
        }

        return false;
    }
}
