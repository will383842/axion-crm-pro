<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Sequence\FenetreHoraire;
use App\Envoi\Sequence\JoursOuvres;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Étalement PUR des échéances d'une campagne : jours ouvrés d'Europe/Paris, un plafond par jour,
 * des créneaux irréguliers dans la fenêtre. Aucune base, aucune horloge interne, déterministe.
 */
final class EtalementEcheances
{
    public const HORIZON_JOURS_OUVRES = 20;

    public const MARGE_MINUTES = 5;

    public const ALEA_MIN = 0.10;

    public const ALEA_MAX = 0.90;

    /** Plafond absolu par jour (min(ENVOI_PLAFOND_JOUR, plafond de l'espace, 300) : le min des deux premiers est calculé par l'appelant). */
    public const PLAFOND_ABSOLU = 300;

    /**
     * @param  array<string,int>  $dejaParJour  'Y-m-d' (jour civil de Paris) => inscriptions actives déjà prévues
     * @param  list<string>  $cles  clés stables des destinataires
     * @return array<string, DateTimeImmutable> clé => échéance ; les clés hors horizon sont ABSENTES
     */
    public static function repartir(FenetreHoraire $fenetre, DateTimeImmutable $maintenant, DateTimeImmutable $auPlusTot, int $plafondJour, array $dejaParJour, array $cles, string $graine): array
    {
        $p = self::plafond($plafondJour);
        $seuil = self::seuil($maintenant, $auPlusTot);
        $resultat = [];
        $i = 0;
        $n = count($cles);
        foreach (self::jours($seuil) as $jour) {
            $deja = max(0, $dejaParJour[$jour->format('Y-m-d')] ?? 0);
            for ($k = $deja; $k < $p && $i < $n; $k++) {
                $echeance = self::creneau($fenetre, $jour, $k, $p, self::alea($graine, $cles[$i]));
                if ($echeance < $seuil) {
                    continue; // créneau passé : la place est perdue, la clé reste à placer
                }
                $resultat[$cles[$i]] = $echeance;
                $i++;
            }
            if ($i >= $n) {
                break;
            }
        }

        return $resultat;
    }

    /**
     * Places libres garanties sur l'horizon : un créneau du jour courant compte seulement s'il est valable pour toute clé.
     *
     * @param  array<string,int>  $dejaParJour
     */
    public static function capacite(FenetreHoraire $fenetre, DateTimeImmutable $maintenant, DateTimeImmutable $auPlusTot, int $plafondJour, array $dejaParJour): int
    {
        $p = self::plafond($plafondJour);
        $seuil = self::seuil($maintenant, $auPlusTot);
        $total = 0;
        foreach (self::jours($seuil) as $jour) {
            for ($k = max(0, $dejaParJour[$jour->format('Y-m-d')] ?? 0); $k < $p; $k++) {
                if (self::creneau($fenetre, $jour, $k, $p, self::ALEA_MIN) >= $seuil) {
                    $total++;
                }
            }
        }

        return $total;
    }

    private static function plafond(int $plafondJour): int
    {
        if ($plafondJour <= 0) {
            throw new InvalidArgumentException("Plafond par jour invalide : {$plafondJour}.");
        }

        return min($plafondJour, self::PLAFOND_ABSOLU);
    }

    private static function seuil(DateTimeImmutable $maintenant, DateTimeImmutable $auPlusTot): DateTimeImmutable
    {
        $marge = $maintenant->modify('+' . self::MARGE_MINUTES . ' minutes');

        return max($marge, $auPlusTot);
    }

    /** @return list<DateTimeImmutable> minuits (Paris) des jours ouvrés de l'horizon */
    private static function jours(DateTimeImmutable $seuil): array
    {
        $cur = $seuil->setTimezone(new DateTimeZone(JoursOuvres::FUSEAU))->setTime(0, 0);
        $jours = [];
        while (count($jours) < self::HORIZON_JOURS_OUVRES) {
            if (JoursOuvres::estOuvre($cur)) {
                $jours[] = $cur;
            }
            $cur = $cur->modify('+1 day');
        }

        return $jours;
    }

    private static function alea(string $graine, string $cle): float
    {
        $fraction = hexdec(substr(hash('sha256', $graine . '|' . $cle), 0, 12)) / (16 ** 12);

        return self::ALEA_MIN + (self::ALEA_MAX - self::ALEA_MIN) * $fraction;
    }

    private static function creneau(FenetreHoraire $fenetre, DateTimeImmutable $jour, int $k, int $p, float $u): DateTimeImmutable
    {
        $secondes = (int) floor(($fenetre->debut * 3600) + ($fenetre->fin - $fenetre->debut) * 3600 * ($k + $u) / $p);

        // Jamais pile sur l'heure ronde (cahier X1-L4-P2 : « jamais à l'ouverture pile », aucune échéance à HH:00:00).
        if ($secondes % 3600 === 0) {
            $secondes += 1;
        }

        // Heure locale (jamais d'addition de secondes absolues) : les changements d'heure ont lieu la nuit.
        return $jour->setTime(intdiv($secondes, 3600), intdiv($secondes % 3600, 60), $secondes % 60);
    }
}
