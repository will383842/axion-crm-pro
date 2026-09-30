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
 *  - website : hérité si le média n'en a pas encore ;
 *  - email / phone : hérités si le média ne les a pas encore.
 *
 * 🔴 2026-09-30 (relecture de #264) — le site était un MIROIR AUTORITAIRE :
 * dès que la fiche avait un site, il ÉCRASAIT celui du média, sans trace.
 * Depuis l'harmonisation de la presse, un titre autonome ou une émission
 * reçoit un `company_id` : son site propre (celui du titre, de l'émission)
 * aurait été remplacé chaque nuit par celui de la fiche. Rien ne doit être
 * perdu : le site n'est plus qu'HÉRITÉ quand le média n'en a pas, comme
 * l'e-mail et le téléphone (même règle que `media:sync-emissions-from-parent`).
 *
 * Une ÉMISSION portée par la fiche de sa CHAÎNE (même `company_id` que son
 * média parent) n'hérite de RIEN : les coordonnées de la chaîne ne sont pas
 * celles de l'émission.
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
        // 1) Site web : hérité SEULEMENT si le média n'en a pas (jamais écrasé).
        $siteSynced = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET website = c.website,
                website_status = 'found',
                website_method = COALESCE(m.website_method, 'company-sync'),
                website_checked_at = now(),
                enrich_status = 'enriched',
                enriched_at = COALESCE(m.enriched_at, now()),
                updated_at = now()
            FROM companies c
            WHERE m.company_id = c.id
              AND c.website IS NOT NULL
              AND NULLIF(m.website, '') IS NULL
              AND m.deleted_at IS NULL
              AND NOT EXISTS (
                    SELECT 1 FROM media p
                    WHERE m.media_type = 'tv_emission' AND p.id = m.parent_media_id AND p.company_id = m.company_id
              )
        SQL);

        // 2) Email / téléphone : hérités uniquement si le média ne les a pas.
        $contactSynced = DB::affectingStatement(<<<'SQL'
            UPDATE media m
            SET email = COALESCE(NULLIF(m.email, ''), c.email_generic),
                phone = COALESCE(NULLIF(m.phone, ''), c.phone),
                -- Un email posé (hérité ou déjà présent) ⇒ média enrichi. Le
                -- téléphone seul ne suffit pas (cohérent avec le backfill migration).
                enrich_status = CASE
                    WHEN COALESCE(NULLIF(m.email, ''), c.email_generic) IS NOT NULL THEN 'enriched'
                    ELSE m.enrich_status END,
                enriched_at = CASE
                    WHEN COALESCE(NULLIF(m.email, ''), c.email_generic) IS NOT NULL THEN COALESCE(m.enriched_at, now())
                    ELSE m.enriched_at END,
                updated_at = now()
            FROM companies c
            WHERE m.company_id = c.id
              AND m.deleted_at IS NULL
              AND NOT EXISTS (
                    SELECT 1 FROM media p
                    WHERE m.media_type = 'tv_emission' AND p.id = m.parent_media_id AND p.company_id = m.company_id
              )
              AND (
                    (m.email IS NULL AND c.email_generic IS NOT NULL)
                 OR (m.phone IS NULL AND c.phone IS NOT NULL)
              )
        SQL);

        $this->info("✓ Sync média←entreprise : {$siteSynced} sites alignés, {$contactSynced} contacts hérités.");

        return self::SUCCESS;
    }
}
