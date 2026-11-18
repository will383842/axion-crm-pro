<?php

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Envoi\Conformite\MotifRegroupe;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Envoi\Sequence\LecteurFicheDb;

test('MotifRegroupe::de couvre chaque famille', function () {
    expect(MotifRegroupe::de(null))->toBeNull()
        ->and(MotifRegroupe::de(EligibiliteAdresse::OPPOSITION))->toBe(MotifRegroupe::OPPOSITION)
        ->and(MotifRegroupe::de(EligibiliteAdresse::NON_VERIFIEE))->toBe(MotifRegroupe::NON_VERIFIEE)
        ->and(MotifRegroupe::de(EnvoiAutorise::VERDICT_ABSENT))->toBe(MotifRegroupe::NON_VERIFIEE)
        ->and(MotifRegroupe::de(EligibiliteAdresse::INVALIDE))->toBe(MotifRegroupe::CONTACT_ECARTE)
        ->and(MotifRegroupe::de(EnvoiAutorise::SEGMENT_FERME))->toBe(MotifRegroupe::CONTACT_ECARTE)
        ->and(MotifRegroupe::de('autre_refus_inconnu'))->toBe(MotifRegroupe::CONDITIONS);
});

test('X7-LIB : les trois motifs d\'opposition répondent « opposition »', function () {
    foreach ([EnvoiAutorise::OPPOSITION_ART21, EnvoiAutorise::OPPOSITION_STOP, EnvoiAutorise::OPPOSITION_LISTE_REFUS] as $refus) {
        expect(MotifRegroupe::de($refus))->toBe(MotifRegroupe::OPPOSITION);
    }
});

test('X7-LIB : un motif opposition_* inconnu tombe sur conditions_envoi sans erreur', function () {
    expect(MotifRegroupe::de('opposition_inconnue'))->toBe(MotifRegroupe::CONDITIONS);
});

test('X7-LIB : table complète des autres motifs inchangée', function () {
    $attendu = [
        EligibiliteAdresse::OPPOSITION => MotifRegroupe::OPPOSITION,
        EnvoiAutorise::OPPOSITION_REGISTRE => MotifRegroupe::OPPOSITION,
        EligibiliteAdresse::NON_VERIFIEE => MotifRegroupe::NON_VERIFIEE,
        EnvoiAutorise::VERDICT_ABSENT => MotifRegroupe::NON_VERIFIEE,
        EligibiliteAdresse::NON_DIFFUSIBLE => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::ENTREPRISE_FERMEE => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::ENTREPRISE_INDIVIDUELLE => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::SITE_NON_VERIFIE => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::INFORMATION_TIERS_INSUFFISANTE => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::SOURCE_OSM => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::INVALIDE => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::PERSONNELLE => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::ADRESSE_PARTAGEE => MotifRegroupe::CONTACT_ECARTE,
        EligibiliteAdresse::FICHE_CONSOLE => MotifRegroupe::CONTACT_ECARTE,
        LecteurFicheDb::EXCLUSION_PRESSE => MotifRegroupe::CONTACT_ECARTE,
        EnvoiAutorise::WEBMAIL_PREMIER_CONTACT => MotifRegroupe::CONTACT_ECARTE,
        EnvoiAutorise::SEGMENT_FERME => MotifRegroupe::CONTACT_ECARTE,
        EnvoiAutorise::SEGMENT_INCONNU => MotifRegroupe::CONTACT_ECARTE,
        EnvoiAutorise::SEGMENT_SENSIBLE => MotifRegroupe::CONTACT_ECARTE,
        'autre_refus_inconnu' => MotifRegroupe::CONDITIONS,
    ];
    foreach ($attendu as $refus => $motif) {
        expect(MotifRegroupe::de($refus))->toBe($motif, $refus);
    }
});
