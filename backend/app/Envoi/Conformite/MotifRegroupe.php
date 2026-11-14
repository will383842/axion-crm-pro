<?php

namespace App\Envoi\Conformite;

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Envoi\Sequence\LecteurFicheDb;

/**
 * Regroupe les refus détaillés de la porte d'envoi en quatre motifs codés. « Puis-je écrire à… ? » ne rend JAMAIS le
 * détail d'une garde : il révélerait l'état interne d'une fiche (opposition ou non diffusibilité comprises).
 */
final class MotifRegroupe
{
    public const OPPOSITION = 'opposition';

    public const NON_VERIFIEE = 'adresse_non_verifiee';

    public const CONTACT_ECARTE = 'contact_ecarte';

    public const CONDITIONS = 'conditions_envoi';

    public const TOUS = [self::OPPOSITION, self::NON_VERIFIEE, self::CONTACT_ECARTE, self::CONDITIONS];

    /** Refus de la porte (ou de la fiche) → motif regroupé ; null = autorisé. */
    public static function de(?string $refus): ?string
    {
        return match (true) {
            $refus === null => null,
            $refus === EligibiliteAdresse::OPPOSITION => self::OPPOSITION,
            in_array($refus, [EligibiliteAdresse::NON_VERIFIEE, EnvoiAutorise::VERDICT_ABSENT], true) => self::NON_VERIFIEE,
            in_array($refus, [
                EligibiliteAdresse::NON_DIFFUSIBLE, EligibiliteAdresse::ENTREPRISE_FERMEE, EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE,
                EligibiliteAdresse::SITE_NON_VERIFIE, EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE, EligibiliteAdresse::SOURCE_OSM,
                EligibiliteAdresse::INVALIDE, EligibiliteAdresse::PERSONNELLE, EligibiliteAdresse::ADRESSE_PARTAGEE,
                EligibiliteAdresse::FICHE_CONSOLE, LecteurFicheDb::EXCLUSION_PRESSE,
                EnvoiAutorise::WEBMAIL_PREMIER_CONTACT, EnvoiAutorise::SEGMENT_FERME, EnvoiAutorise::SEGMENT_INCONNU, EnvoiAutorise::SEGMENT_SENSIBLE,
            ], true) => self::CONTACT_ECARTE,
            default => self::CONDITIONS,
        };
    }
}
