<?php

/*
 * PREUVE PAR LA ROUGEUR — 2026-08-13, Gate 0 de l'autopilot CRM.
 *
 * Ce fichier est VOLONTAIREMENT faux. Il sert à vérifier en conditions réelles
 * que la CI durcie rougit bien sur un test qui échoue — et il est retiré au
 * commit suivant. Si vous le lisez sur `main`, c'est qu'il n'a pas été retiré :
 * supprimez-le.
 */

it('preuve de rougeur : ce test DOIT faire échouer la CI', function () {
    expect(1 + 1)->toBe(3);
});
