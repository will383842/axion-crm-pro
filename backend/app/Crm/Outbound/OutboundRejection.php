<?php

namespace App\Crm\Outbound;

use RuntimeException;

/**
 * Refus d'enregistrement d'un événement sortant CRM → site.
 *
 * Le refus le plus important est `origin_not_crm` : un événement dont le CRM
 * n'est PAS l'origine (il vient du site, ingéré par L2/L4) ne doit jamais être
 * mis en file vers le site. C'est un ÉCHEC BRUYANT et non un no-op silencieux,
 * pour la même raison que MissingWorkspaceContextException en L0 : une boucle
 * de synchronisation qui s'installe en silence est indétectable jusqu'à ce
 * qu'elle sature les deux systèmes.
 */
final class OutboundRejection extends RuntimeException
{
    public function __construct(
        // ⚠️ PAS `$code` : Exception::$code existe déjà et n'est pas readonly.
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function originNotCrm(string $origin): self
    {
        return new self(
            'origin_not_crm',
            sprintf(
                'Événement d\'origine « %s » : seules les oppositions NÉES dans le CRM partent vers le site. '
                . 'Un événement venu du site qui repart vers le site est une boucle de synchronisation.',
                $origin,
            ),
        );
    }

    public static function unknownEventType(string $eventType): self
    {
        return new self('unknown_event_type', sprintf('Type d\'événement sortant inconnu : « %s ».', $eventType));
    }

    public static function unknownScope(string $scope): self
    {
        return new self('unknown_scope', sprintf('Univers inconnu : « %s » (attendu : business | vivier).', $scope));
    }

    public static function missingEmailHash(): self
    {
        return new self(
            'missing_email_hash',
            'Aucun email_hash : le site n\'aurait aucun moyen de retrouver la personne visée par l\'opposition.',
        );
    }
}
