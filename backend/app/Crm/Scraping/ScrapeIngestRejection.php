<?php

namespace App\Crm\Scraping;

use RuntimeException;

/**
 * Refus d'ingestion d'un enregistrement de collecte — porte un CODE stable et
 * le statut HTTP correspondant (même distinction que la synchro site, lot L2) :
 *
 *   - 422 = message définitivement invalide (contrat pivot, source inconnue).
 *     Le rejouer ne le rendra jamais valide.
 *   - 503 = refus TEMPORAIRE (funnel fermé par drapeau, source coupée au
 *     registre). Le producteur peut garder et rejouer.
 */
final class ScrapeIngestRejection extends RuntimeException
{
    public function __construct(
        // ⚠️ PAS `$code` : `Exception::$code` existe déjà et n'est pas readonly.
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        /** @var array<string, mixed> */
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>  $details */
    public static function invalid(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }

    public static function unavailable(string $code, string $message): self
    {
        return new self($code, $message, 503);
    }
}
