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
     * @param  array<string, int>  $personsSkipped  motif => nombre de personnes écartées
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
        // C18-002 — CE COMPTEUR N'EXISTAIT PAS, ET C'EST POUR ÇA QU'ON NE VOYAIT
        // RIEN. `upsertContact()` rend `skipped` dans trois cas (personne sans
        // nom de famille, insertion refusée, aucune donnée nouvelle à apporter),
        // et le `match` de l'ingestion faisait tomber ces trois cas dans un
        // `default => null`. Une personne collectée pouvait donc disparaître de
        // bout en bout sans qu'AUCUN chiffre du rapport ne bouge : le run
        // s'affichait « created » avec 0 contact, et rien ne disait pourquoi.
        //
        // Le compteur est ventilé PAR MOTIF, pas agrégé : « 12 personnes
        // écartées » ne dit pas s'il faut corriger le collecteur (noms absents)
        // ou se réjouir (rien de neuf à écrire). Les deux exigent des gestes
        // opposés.
        public readonly array $personsSkipped = [],
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
            // C18-002 — deux clés AJOUTÉES au contrat de sortie (jamais de clé
            // retirée ni renommée : le worker Node et la vue `ScraperRunsPage`
            // lisent ce payload par clé, un ajout leur est transparent).
            // `persons_skipped` est le total, pour qu'un lecteur pressé voie
            // qu'il manque du monde sans avoir à additionner lui-même.
            'persons_skipped' => array_sum($this->personsSkipped),
            'persons_skipped_reasons' => $this->personsSkipped,
            'company_fields_written' => $this->companyFieldsWritten,
            'tags' => $this->tags,
            'activity_id' => $this->activityId,
        ];
    }
}
