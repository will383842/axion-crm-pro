<?php

namespace App\Envoi\Chauffe;

use DateTimeImmutable;

/**
 * K8 — la chauffe de l'IP est SÉPARÉE de celle du domaine (ADR-K0 § 10).
 *
 * Chaque ressource a sa propre histoire de jours effectifs, donc son propre palier : une IP neuve repart
 * du palier le plus bas même si le domaine est ancien (et inversement). Le budget du jour est le MINIMUM
 * des deux, jamais plus : la ressource la plus jeune freine l'autre.
 */
final class BudgetChauffe
{
    public const RESSOURCE_IP = 'ip';

    public const RESSOURCE_DOMAINE = 'domaine';

    /** Valeurs permises de `envoi_chauffe_jours.ressource_type`. */
    public const TYPES = [self::RESSOURCE_IP, self::RESSOURCE_DOMAINE];

    private function __construct(
        public readonly int $budget,
        public readonly int $budgetIp,
        public readonly int $budgetDomaine,
        public readonly string $limitant,
    ) {}

    public static function typeValide(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @param  list<DateTimeImmutable>  $joursEffectifsIp  jours effectifs de l'IP (vide = IP neuve)
     * @param  list<DateTimeImmutable>  $joursEffectifsDomaine  jours effectifs du domaine
     */
    public static function duJour(array $joursEffectifsIp, array $joursEffectifsDomaine, DateTimeImmutable $jour): self
    {
        $ip = CourbeChauffe::quotaBoite(CourbeChauffe::jourDeChauffe($joursEffectifsIp, $jour));
        $domaine = CourbeChauffe::quotaBoite(CourbeChauffe::jourDeChauffe($joursEffectifsDomaine, $jour));

        return new self(min($ip, $domaine), $ip, $domaine, $ip <= $domaine ? self::RESSOURCE_IP : self::RESSOURCE_DOMAINE);
    }
}
