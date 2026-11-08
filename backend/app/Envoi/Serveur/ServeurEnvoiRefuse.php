<?php

namespace App\Envoi\Serveur;

use RuntimeException;

/** Refus local ou réponse inacceptable du serveur d'envoi. Le message ne contient JAMAIS d'extrait de réponse. */
final class ServeurEnvoiRefuse extends RuntimeException
{
    public const CAUSE_HORLOGE = 'horloge';

    public const CAUSE_CONFIGURATION = 'configuration';

    public ?string $cause = null;

    public static function motif(string $motif): self
    {
        return new self('serveur d’envoi : ' . $motif);
    }

    /** Refus de la passerelle pour écart d'horloge (requête hors fenêtre de 300 s) : à ne pas confondre avec un serveur muet. */
    public static function horloge(): self
    {
        $e = new self('serveur d’envoi : horloge hors fenêtre (écart supérieur à 300 s), requête refusée par la passerelle.');
        $e->cause = self::CAUSE_HORLOGE;

        return $e;
    }

    /** Refus local : la source est active mais sa configuration (secret, adresse) manque. Ce n'est pas un silence du serveur. */
    public static function configuration(string $motif): self
    {
        $e = new self('serveur d’envoi : ' . $motif);
        $e->cause = self::CAUSE_CONFIGURATION;

        return $e;
    }

    public function estConfiguration(): bool
    {
        return $this->cause === self::CAUSE_CONFIGURATION;
    }

    public function estHorloge(): bool
    {
        return $this->cause === self::CAUSE_HORLOGE;
    }
}
