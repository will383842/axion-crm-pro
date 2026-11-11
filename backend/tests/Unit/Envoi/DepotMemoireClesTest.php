<?php

use Tests\Unit\Envoi\DepotMemoire;

/** L8-F14a (mineur 8) : le double suit le MÊME schéma de clés `<clé>~n` que DepotSocleDb après une libération. */
test('DepotMemoire : une réservation libérée repart sous « clé~2 », puis « clé~3 » ; le plafond se recompte', function () {
    $d = new DepotMemoire;
    $jour = new DateTimeImmutable('2026-11-17');
    expect($d->reserverInjection('ws', $jour, 'k', $jour))->toBeTrue()->and($d->cles)->toBe(['k']);
    expect($d->concluireInjection('ws', $jour, 'k', 'liberee'))->toBeTrue()->and($d->injectesLe('ws', $jour))->toBe(0)->and($d->etatInjection('ws', 'k'))->toBeNull();

    expect($d->reserverInjection('ws', $jour, 'k', $jour))->toBeTrue()->and($d->cles)->toBe(['k~2'])->and($d->etatInjection('ws', 'k'))->toBe('reservee');
    expect($d->concluireInjection('ws', $jour, 'k', 'liberee'))->toBeTrue();
    expect($d->reserverInjection('ws', $jour, 'k', $jour))->toBeTrue()->and($d->cles)->toBe(['k~3']);
    expect($d->concluireInjection('ws', $jour, 'k', 'a_verifier'))->toBeTrue()->and($d->etatInjection('ws', 'k'))->toBe('a_verifier')->and($d->injectesLe('ws', $jour))->toBe(1);
    // Une clé voisine (« k~abc ») ne décale pas « k » : seules les clés exactes « k » et « k~n » comptent.
    expect($d->reserverInjection('ws', $jour, 'k~abc', $jour))->toBeTrue()->and($d->cles)->toContain('k~abc');
    // Confirmation tardive : ajout, une seule fois.
    expect($d->concluireInjection('ws', $jour, 'k', 'acceptee'))->toBeTrue()->and($d->etatInjection('ws', 'k'))->toBe('acceptee')->and($d->concluireInjection('ws', $jour, 'k', 'acceptee'))->toBeFalse();
});
