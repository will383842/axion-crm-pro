<?php

namespace App\Envoi;

use DateTimeImmutable;
use DateTimeZone;

/** Le « jour » du plafond : toujours le jour civil d'Europe/Paris, jamais celui du serveur. */
final class JourEnvoi
{
    public const FUSEAU = 'Europe/Paris';

    public static function de(?DateTimeImmutable $instant = null): DateTimeImmutable
    {
        return ($instant ?? new DateTimeImmutable('now'))
            ->setTimezone(new DateTimeZone(self::FUSEAU))
            ->setTime(0, 0);
    }
}
