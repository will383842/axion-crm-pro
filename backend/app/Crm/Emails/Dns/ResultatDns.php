<?php

namespace App\Crm\Emails\Dns;

use InvalidArgumentException;

/**
 * Ce que le DNS a dit d'UN domaine : reçoit-il du courrier ?
 *
 * Six verdicts, et un seul qui ne conclut rien :
 *  - `mx`            un enregistrement MX utilisable ;
 *  - `a`             pas de MX, mais une adresse (A, puis AAAA) : RFC 5321
 *                    §5.1, le « MX implicite » — le domaine reçoit ;
 *  - `mx_nul`        un MX « . » (RFC 7505) : le domaine DÉCLARE ne rien recevoir ;
 *  - `sans_courrier` le domaine existe, mais ni MX, ni A, ni AAAA ;
 *  - `inexistant`    NXDOMAIN : le domaine n'existe pas ;
 *  - `indetermine`   délai dépassé, SERVFAIL, REFUSED, réponse illisible.
 *
 * 🔴 `indetermine` n'est JAMAIS un « ne reçoit pas » : une panne du résolveur
 * ne doit pas faire passer une adresse à `invalide`. Il n'est pas mis en cache
 * comme une réponse (`CacheDomaines::fraiches` le redemande à chaque passage).
 */
final class ResultatDns
{
    public const MX = 'mx';

    public const A = 'a';

    public const MX_NUL = 'mx_nul';

    public const SANS_COURRIER = 'sans_courrier';

    public const INEXISTANT = 'inexistant';

    public const INDETERMINE = 'indetermine';

    /** @var list<string> */
    public const VERDICTS = [self::MX, self::A, self::MX_NUL, self::SANS_COURRIER, self::INEXISTANT, self::INDETERMINE];

    public function __construct(
        public readonly string $verdict,
        public readonly ?string $mx = null,
    ) {
        if (! in_array($verdict, self::VERDICTS, true)) {
            throw new InvalidArgumentException("Verdict DNS inconnu : « {$verdict} ».");
        }
    }

    /** true : reçoit ; false : ne reçoit pas ; null : on ne sait pas. */
    public function recoit(): ?bool
    {
        return match ($this->verdict) {
            self::MX, self::A => true,
            self::INDETERMINE => null,
            default => false,
        };
    }
}
