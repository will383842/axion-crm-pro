<?php

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Envoi\Conformite\MotifRegroupe;
use App\Envoi\Gardes\EnvoiAutorise;

test('MotifRegroupe::de couvre chaque famille', function () {
    expect(MotifRegroupe::de(null))->toBeNull()
        ->and(MotifRegroupe::de(EligibiliteAdresse::OPPOSITION))->toBe(MotifRegroupe::OPPOSITION)
        ->and(MotifRegroupe::de(EligibiliteAdresse::NON_VERIFIEE))->toBe(MotifRegroupe::NON_VERIFIEE)
        ->and(MotifRegroupe::de(EnvoiAutorise::VERDICT_ABSENT))->toBe(MotifRegroupe::NON_VERIFIEE)
        ->and(MotifRegroupe::de(EligibiliteAdresse::INVALIDE))->toBe(MotifRegroupe::CONTACT_ECARTE)
        ->and(MotifRegroupe::de(EnvoiAutorise::SEGMENT_FERME))->toBe(MotifRegroupe::CONTACT_ECARTE)
        ->and(MotifRegroupe::de('autre_refus_inconnu'))->toBe(MotifRegroupe::CONDITIONS);
});
