<?php

/**
 * Une adresse ajoutée depuis OpenStreetMap (`metadata.email_source` = `osm`) n'est JAMAIS destinataire d'une
 * campagne tant que le propriétaire ne l'a pas validée, même vérifiée `valide`. Fixtures fictives (.test).
 */

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Emails\VerificationEmail;

it('refuse une adresse dont la source est osm, même vérifiée valide', function () {
    $valide = ['verification' => VerificationEmail::VALIDE];

    expect(EligibiliteAdresse::motif('info@zz.test', [$valide + ['email_source' => 'osm']]))->toBe(EligibiliteAdresse::SOURCE_OSM)
        // Une seule occurrence osm suffit à écarter l'adresse.
        ->and(EligibiliteAdresse::motif('info@zz.test', [$valide, $valide + ['email_source' => 'osm']]))->toBe(EligibiliteAdresse::SOURCE_OSM)
        ->and(EligibiliteAdresse::motif('info@zz.test', [$valide + ['email_source' => 'site']]))->toBeNull()
        ->and(EligibiliteAdresse::motif('info@zz.test', [$valide]))->toBeNull()
        ->and(EligibiliteAdresse::MOTIFS)->toContain(EligibiliteAdresse::SOURCE_OSM);
});
