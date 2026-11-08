<?php

use App\Envoi\Chauffe\DecisionFrein;
use App\Envoi\Chauffe\Freins;

test('K8 reports > 20 % sur 1 h : pause de CE fournisseur seulement (jamais globale)', function () {
    $d = Freins::reportsEnHausse('gmail', 100, 21);

    expect($d)->not->toBeNull()
        ->and($d->motif)->toBe(DecisionFrein::REPORTS_EN_HAUSSE)
        ->and($d->fournisseur)->toBe('gmail');
});

test('K8 reports à 20 % pile ou en dessous : aucune pause', function () {
    expect(Freins::reportsEnHausse('gmail', 100, 20))->toBeNull()
        ->and(Freins::reportsEnHausse('gmail', 100, 0))->toBeNull()
        ->and(Freins::reportsEnHausse('microsoft', 50, 10))->toBeNull();
});

test('K8 échantillon trop petit sur l\'heure : on ne conclut pas', function () {
    expect(Freins::reportsEnHausse('gmail', 4, 4))->toBeNull();
});
