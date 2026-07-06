<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Synchronise les médias LIÉS à une entreprise depuis leur entreprise
 * (source de vérité pour les infos d'entreprise). Évite la divergence entre
 * l'enrichissement « entreprise » et l'enrichissement « média » du MÊME entité :
 * un média rattaché à une company n'invente rien, il HÉRITE.
 *
 *  - website : miroir du site de l'entreprise dès qu'elle en a un (autoritaire)
 *  - email / phone : hérités si le média ne les a pas encore
 *
 * Idempotent (ne met à jour que ce qui diffère). Les médias AUTONOMES (sans
 * company_id : titres CPPAP, agences) ne sont pas touchés — ils s'enrichissent
 * seuls via media:find-websites.
 */
class MediaSyncFromCompanies extends Command
{
    protected $signature = 'media:sync-from-companies';

    protected $description = 'Aligne les médias liés à une entreprise sur les infos de leur entreprise (anti-divergence).';

    public function handle(): int
    {
        // 1) Site web : l'entreprise est autoritaire → miroir dès qu'elle en a un.
        $siteSynced = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET website = c.website,
                website_status = 'found',
                website_method = COALESCE(m.website_method, 'company-sync'),
                website_checked_at = now(),
                updated_at = now()
            FROM companies c
            WHERE m.company_id = c.id
              AND c.website IS NOT NULL
              AND m.website IS DISTINCT FROM c.website
        SQL);

        // 2) Email / téléphone : hérités uniquement si le média ne les a pas.
        $contactSynced = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET email = COALESCE(NULLIF(m.email, ''), c.email_generic),
                phone = COALESCE(NULLIF(m.phone, ''), c.phone),
                updated_at = now()
            FROM companies c
            WHERE m.company_id = c.id
              AND (
                    (m.email IS NULL AND c.email_generic IS NOT NULL)
                 OR (m.phone IS NULL AND c.phone IS NOT NULL)
              )
        SQL);

        $this->info("✓ Sync média←entreprise : {$siteSynced} sites alignés, {$contactSynced} contacts hérités.");

        return self::SUCCESS;
    }
}
