<?php

use Tests\Unit\Envoi\DepotMemoire;

beforeEach(fn () => config(['envoi.cle_empreinte' => 'cle-de-test-seulement']));

test('m7 : DepotMemoire peut naître avec un témoin initial, listé et reconnu, sans journal', function () {
    $depot = new DepotMemoire('temoin-1@exemple.test', 'yahoo');
    expect($depot->estTemoin('ws', 'Temoin-1@exemple.test'))->toBeTrue()
        ->and($depot->listerTemoins('ws'))->toHaveCount(1)
        ->and($depot->listerTemoins('ws')[0]['fournisseur'])->toBe('yahoo')
        ->and($depot->journal)->toBe([]);
});

test('m7 : sans argument, le double garde son comportement historique', function () {
    $depot = new DepotMemoire;
    expect($depot->estTemoin('ws', 'temoin@example.test'))->toBeTrue()->and($depot->listerTemoins('ws'))->toBe([]);
});
