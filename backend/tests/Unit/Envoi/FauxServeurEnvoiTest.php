<?php

use App\Envoi\Simulateur\FauxServeurEnvoi;

require_once __DIR__ . '/../../Envoi/KitConformiteServeurEnvoi.php';

kitConformiteServeurEnvoi(fn () => new FauxServeurEnvoi);

test('simulateur : mode témoins reflète le réglage de construction', function () {
    expect((new FauxServeurEnvoi(false))->modeTemoins())->toBeFalse();
});
