<?php

namespace App\Crm\Ingest;

use RuntimeException;

/**
 * Refus d'ingestion — porte un CODE stable (contrat avec l'outbox du site) et
 * le statut HTTP correspondant.
 *
 * Distinction volontaire :
 *   - 422 = le message est définitivement invalide (contrat, consentement,
 *     taxonomie). L'outbox du site NE DOIT PAS le rejouer : il échouera
 *     éternellement. Elle le marque `gave_up` et alerte.
 *   - 503 = le CRM refuse TEMPORAIREMENT (drapeau à OFF). L'outbox garde la
 *     ligne en attente : le jour où le drapeau passe à ON, le rattrapage
 *     l'ingère. C'est ce qui rend la bascule finale non destructrice.
 */
final class SiteSyncRejection extends RuntimeException
{
    public function __construct(
        // ⚠️ PAS `$code` : `Exception::$code` existe déjà et n'est pas readonly
        // (« Cannot redeclare non-readonly property … as readonly »).
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
