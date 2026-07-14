<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Héritage ÉMISSION → CHAÎNE parente.
 *
 * Une émission TV/radio (media_type='tv_emission', rattachée via parent_media_id
 * à sa chaîne) n'a en général ni email, ni site, ni téléphone propres : ces infos
 * appartiennent à la RÉDACTION de la chaîne diffuseuse. Plutôt que d'inventer ou
 * de scraper à part, l'émission HÉRITE de sa chaîne parente — exactement comme un
 * média rattaché à une entreprise hérite de son entreprise (media:sync-from-companies).
 *
 *  - email   ← chaîne parente si l'émission n'en a pas encore
 *  - website ← chaîne parente si l'émission n'en a pas encore
 *  - phone   ← chaîne parente si l'émission n'en a pas encore
 *
 * ⚠️ N'ÉCRASE JAMAIS un champ déjà rempli sur l'émission (COALESCE + NULLIF) et
 * n'agit que si la chaîne parente porte réellement l'info (parent non-null). Le
 * garde-fou `IS DISTINCT FROM` rend la commande IDEMPOTENTE (un 2e run n'écrit rien).
 *
 * `--dry-run` compte les héritages potentiels sans écrire.
 */
class MediaSyncEmissionsFromParent extends Command
{
    protected $signature = 'media:sync-emissions-from-parent {--dry-run : Compte les héritages sans écrire en base}';

    protected $description = 'Fait hériter les émissions TV/radio (email/site/tél) de leur chaîne parente (anti-divergence).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN : aucune écriture en base.');

            $emails = $this->countInheritable('email');
            $sites = $this->countInheritable('website');
            $phones = $this->countInheritable('phone');

            $this->info("→ Hériteraient : {$emails} emails, {$sites} sites, {$phones} téléphones.");

            return self::SUCCESS;
        }

        // Email : hérité uniquement si l'émission n'en a pas et que la chaîne en a un.
        $emailSynced = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET email = COALESCE(NULLIF(m.email, ''), p.email),
                enrich_status = 'enriched',
                enriched_at = COALESCE(m.enriched_at, now()),
                updated_at = now()
            FROM media p
            WHERE m.media_type = 'tv_emission'
              AND m.parent_media_id = p.id
              AND m.deleted_at IS NULL
              AND (m.email IS NULL OR m.email = '')
              AND p.email IS NOT NULL AND p.email <> ''
              AND m.email IS DISTINCT FROM p.email
        SQL);

        // Site web : hérité uniquement si l'émission n'en a pas et que la chaîne en a un.
        $siteSynced = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET website = COALESCE(NULLIF(m.website, ''), p.website),
                website_status = COALESCE(m.website_status, p.website_status, 'found'),
                website_method = COALESCE(m.website_method, 'parent-sync'),
                enrich_status = 'enriched',
                enriched_at = COALESCE(m.enriched_at, now()),
                updated_at = now()
            FROM media p
            WHERE m.media_type = 'tv_emission'
              AND m.parent_media_id = p.id
              AND m.deleted_at IS NULL
              AND (m.website IS NULL OR m.website = '')
              AND p.website IS NOT NULL AND p.website <> ''
              AND m.website IS DISTINCT FROM p.website
        SQL);

        // Téléphone : hérité uniquement si l'émission n'en a pas et que la chaîne en a un.
        $phoneSynced = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET phone = COALESCE(NULLIF(m.phone, ''), p.phone),
                updated_at = now()
            FROM media p
            WHERE m.media_type = 'tv_emission'
              AND m.parent_media_id = p.id
              AND m.deleted_at IS NULL
              AND (m.phone IS NULL OR m.phone = '')
              AND p.phone IS NOT NULL AND p.phone <> ''
              AND m.phone IS DISTINCT FROM p.phone
        SQL);

        $this->info("✓ Héritage émission←chaîne : {$emailSynced} emails, {$siteSynced} sites, {$phoneSynced} téléphones hérités.");

        return self::SUCCESS;
    }

    /**
     * Compte (dry-run) les émissions qui hériteraient de la colonne donnée,
     * sans rien écrire. Miroir exact des conditions de l'UPDATE.
     */
    private function countInheritable(string $column): int
    {
        return (int) DB::table('media as m')
            ->join('media as p', 'm.parent_media_id', '=', 'p.id')
            ->where('m.media_type', 'tv_emission')
            ->whereNull('m.deleted_at')
            ->where(function ($q) use ($column) {
                $q->whereNull("m.{$column}")->orWhere("m.{$column}", '');
            })
            ->whereNotNull("p.{$column}")
            ->where("p.{$column}", '<>', '')
            ->count();
    }
}
