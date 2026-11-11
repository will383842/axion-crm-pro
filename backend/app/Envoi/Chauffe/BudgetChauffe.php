<?php

namespace App\Envoi\Chauffe;

use App\Envoi\PasserelleLocale;
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

    /** Goulots autres que les deux ressources. Une boîte n'est PAS une ressource de chauffe (ADR E2) : elle ne fait que plafonner. */
    public const GOULOT_BOITES = 'boites';

    public const GOULOT_SERVEUR = 'serveur';

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
     * Jours effectifs d'une RESSOURCE : un jour compte à partir de 50 % du budget de la ressource (10 pour l'IP en P1).
     *
     * @param  array<string, int>  $volumes  'Y-m-d' (Paris) => messages envoyés par la ressource ce jour-là
     *                                       `$reculs` : voir {@see CourbeChauffe::joursEffectifs()} (null = ancienne règle ; liste = rejeu P1-P8 avec reculs).
     * @param  list<DateTimeImmutable>|null  $reculs
     * @return list<DateTimeImmutable>
     */
    public static function joursEffectifs(array $volumes, float $part = Seuils::JOUR_EFFECTIF_PART, ?array $reculs = null): array
    {
        return CourbeChauffe::joursEffectifs($volumes, $part, true, $reculs);
    }

    /**
     * @param  list<DateTimeImmutable>  $joursEffectifsIp  jours effectifs de l'IP (vide = IP neuve)
     * @param  list<DateTimeImmutable>  $joursEffectifsDomaine  jours effectifs du domaine
     */
    public static function duJour(array $joursEffectifsIp, array $joursEffectifsDomaine, DateTimeImmutable $jour): self
    {
        // Budget de la RESSOURCE (total de l'IP / du domaine), pas le quota d'une boîte : P1 = 20 par jour (26 § 4.2).
        $ip = CourbeChauffe::quotaRessource(CourbeChauffe::jourDeChauffe($joursEffectifsIp, $jour));
        $domaine = CourbeChauffe::quotaRessource(CourbeChauffe::jourDeChauffe($joursEffectifsDomaine, $jour));

        return new self(min($ip, $domaine), $ip, $domaine, $ip <= $domaine ? self::RESSOURCE_IP : self::RESSOURCE_DOMAINE);
    }

    /**
     * L8-F07a — budget du jour = min(courbe IP, courbe domaine, boîtes × 150, plafond serveur 300, capacité de réponse).
     * Les ressources sont l'IP et le domaine, chacune avec SES jours effectifs et SES reculs (table P1-P8). Une IP en P3
     * bridée par un domaine en P1 est voulu : le minimum, affiché comme goulot, sans aucun alignement en cascade.
     * Égalité : le premier terme dans l'ordre serveur, boites, ip, domaine, reponse est nommé (un plafond dur atteint par la courbe est le plus parlant).
     *
     * @param  list<DateTimeImmutable>  $joursIp  jours effectifs de l'IP
     * @param  list<DateTimeImmutable>  $reculsIp  dates des reculs de l'IP
     * @param  list<DateTimeImmutable>  $joursDomaine
     * @param  list<DateTimeImmutable>  $reculsDomaine
     * @param  ?int  $capaciteReponse  null tant que le serveur ne la calcule pas
     */
    public static function duJourComplet(array $joursIp, array $reculsIp, array $joursDomaine, array $reculsDomaine, DateTimeImmutable $jour, int $nbBoites, ?int $capaciteReponse = null): BudgetJour
    {
        $ip = CourbeChauffe::quotaRessourceEtendu(CourbeChauffe::jourDeChauffeAvecReculs($joursIp, $reculsIp, $jour));
        $domaine = CourbeChauffe::quotaRessourceEtendu(CourbeChauffe::jourDeChauffeAvecReculs($joursDomaine, $reculsDomaine, $jour));
        $boites = max(0, $nbBoites) * CourbeChauffe::PLAFOND_BOITE_JOUR;
        $serveur = PasserelleLocale::PLAFOND_MAX;
        /** @var array<string, int> $termes */
        $termes = [self::GOULOT_SERVEUR => $serveur, self::GOULOT_BOITES => $boites, self::RESSOURCE_IP => $ip, self::RESSOURCE_DOMAINE => $domaine];
        if ($capaciteReponse !== null) {
            $termes['reponse'] = max(0, $capaciteReponse);
        }
        $goulot = self::GOULOT_SERVEUR;
        $budget = $serveur;
        foreach ($termes as $nom => $valeur) {
            if ($valeur < $budget) {
                $goulot = (string) $nom;
                $budget = $valeur;
            }
        }

        return new BudgetJour($budget, $goulot, $ip, $domaine, $boites, $serveur, $capaciteReponse === null ? null : max(0, $capaciteReponse));
    }
}
