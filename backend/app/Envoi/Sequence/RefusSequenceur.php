<?php

namespace App\Envoi\Sequence;

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Envoi\Gardes\EnvoiAutorise;

/**
 * Table EXPLICITE refus de la porte `EnvoiAutorise` → REPORTER ou ARRÊTER.
 *
 * Une inscription arrêtée l'est pour toujours (la contrainte UNIQUE workspace/séquence/contact
 * interdit de la recréer) : on n'arrête donc que pour un fait lié à LA PERSONNE ou à sa fiche.
 * Une cause qui disparaît d'elle-même (liste noire levée, domaine qui vieillit, DNS vérifié demain)
 * ou un réglage de l'espace (domaine, entité, segment) REPORTE : l'inscription reste active,
 * l'échéance est repoussée, le message reste en base (« reporte » + motif).
 *
 * Un test de réflexion échoue si un code de refus est ajouté à la porte sans être classé ici.
 * `VERDICT_ABSENT` est classé « définitif » par défaut ; il n'est reporté que si le DERNIER verdict
 * de l'adresse est « valide » mais périmé (voir `Sequenceur::passager`).
 */
final class RefusSequenceur
{
    /** @var list<string> reporter */
    public const TEMPORAIRES = [
        EnvoiAutorise::DOMAINE_DNS_NON_VERIFIE,
        EnvoiAutorise::DOMAINE_LISTE_NOIRE,
        EnvoiAutorise::IP_LISTE_NOIRE,
        EnvoiAutorise::DOMAINE_TROP_JEUNE,
        EnvoiAutorise::DOMAINE_INCONNU,
        EnvoiAutorise::ENTITE_ABSENTE,
        EnvoiAutorise::SEGMENT_FERME,
    ];

    /** @var list<string> arrêter */
    public const DEFINITIFS = [
        EnvoiAutorise::PAYS_HORS_FRANCE,
        EnvoiAutorise::PAYS_INCONNU,
        EnvoiAutorise::WEBMAIL_PREMIER_CONTACT,
        EnvoiAutorise::VERDICT_ABSENT, // sauf verdict « valide » périmé : reporté par le séquenceur
        EnvoiAutorise::ART14_DEPASSE,
        EnvoiAutorise::ART14_DATE_INCONNUE,
        EnvoiAutorise::DATE_FUTURE,
        EnvoiAutorise::SEGMENT_INCONNU,
        EnvoiAutorise::SEGMENT_SENSIBLE,
        EligibiliteAdresse::NON_DIFFUSIBLE,
        EligibiliteAdresse::ENTREPRISE_FERMEE,
        EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE,
        EligibiliteAdresse::SITE_NON_VERIFIE,
        EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE,
        EligibiliteAdresse::SOURCE_OSM,
        EligibiliteAdresse::INVALIDE,
        EligibiliteAdresse::NON_VERIFIEE,
        EligibiliteAdresse::PERSONNELLE,
        EligibiliteAdresse::DEJA_INFORMEE,
        EligibiliteAdresse::OPPOSITION,
        EnvoiAutorise::OPPOSITION_REGISTRE, // R13 : domaine ou organisation au registre manuel, levée = décision humaine
        EligibiliteAdresse::ADRESSE_PARTAGEE,
        EligibiliteAdresse::FICHE_CONSOLE,
    ];

    public static function temporaire(string $motif): bool
    {
        return in_array($motif, self::TEMPORAIRES, true);
    }
}
