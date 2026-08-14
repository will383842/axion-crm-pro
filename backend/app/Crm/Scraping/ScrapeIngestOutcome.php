<?php

namespace App\Crm\Scraping;

/**
 * Résultat d'une ingestion de collecte, renvoyé au producteur (worker Node,
 * commande d'import) et journalisé dans `scraper_runs.response_payload`.
 *
 * `status` est un contrat :
 *   - `created` / `updated`  : la fiche entreprise a été créée / enrichie ;
 *   - `noop_idempotent`      : ce run avait déjà été ingéré, rien n'a bougé ;
 *   - `pending_match`        : pas de SIREN — RIEN n'est créé, l'événement part
 *                              en file d'arbitrage (activité `scraped` portant
 *                              le match_hint) ;
 *   - `skipped_failed`       : le run est en échec côté collecteur — consigné
 *                              dans scraper_runs, aucune donnée à écrire.
 */
final class ScrapeIngestOutcome
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const IDEMPOTENT = 'noop_idempotent';

    public const PENDING_MATCH = 'pending_match';

    public const SKIPPED_FAILED = 'skipped_failed';

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $companyFieldsWritten
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $companyId = null,
        public readonly int $contactsCreated = 0,
        public readonly int $contactsUpdated = 0,
        public readonly int $personsSkippedOptOut = 0,
        public readonly int $emailsRejectedMx = 0,
        public readonly array $companyFieldsWritten = [],
        public readonly array $tags = [],
        public readonly ?int $activityId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'company_id' => $this->companyId,
            'contacts_created' => $this->contactsCreated,
            'contacts_updated' => $this->contactsUpdated,
            'persons_skipped_opt_out' => $this->personsSkippedOptOut,
            'emails_rejected_mx' => $this->emailsRejectedMx,
            'company_fields_written' => $this->companyFieldsWritten,
            'tags' => $this->tags,
            'activity_id' => $this->activityId,
        ];
    }
}
