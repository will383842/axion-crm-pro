<?php

namespace App\Crm\Opco;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * LA FENÊTRE DE `crm:enrichir-opco` (lot O14 ; décision du 04/10/2026) :
 * TOUS LES JOURS, dimanche et lundi compris, de 08:00 à 19:00 heure de Paris
 * — les tâches planifiées lourdes tournent la nuit (02:00-05:30), la journée
 * est libre. Hors de la fenêtre, la commande REFUSE de partir — elle n'est
 * pas planifiée : elle se lance à la main.
 */
final class FenetreOpco
{
    public const FUSEAU = 'Europe/Paris';

    /**
     * Le motif du refus, ou null si l'instant est dans la fenêtre.
     */
    public static function refus(CarbonInterface $instant): ?string
    {
        $t = CarbonImmutable::instance($instant)->setTimezone(self::FUSEAU);
        // `locale()` est typé `static|string` : l'instance française est
        // vérifiée avant d'être formatée (PHPStan).
        $fr = $t->locale('fr');
        $quand = ($fr instanceof CarbonImmutable ? $fr : $t)->isoFormat('dddd D MMMM YYYY à HH:mm');

        if ($t->hour < 8 || $t->hour >= 19) {
            return "Refusé : la commande ne tourne qu'entre 08:00 et 19:00, heure de Paris (nous sommes le {$quand}).";
        }

        return null;
    }
}
