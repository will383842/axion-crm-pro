<?php

/**
 * Sous-processus du test de course entre gestes de campagne : démarre l'application, attend l'instant convenu, puis joue UN geste
 * (pause ou reprise) via GestesCampagne. Sortie 0 = appliqué, 3 = refusé proprement (CampagneRefusee), 1 = erreur inattendue.
 * Usage : php campagne-geste-concurrent.php <espace> <campagne> <pause|reprise> <instant-unix-décimal>
 */

use App\Envoi\Campagnes\GestesCampagne;
use App\Envoi\Exceptions\CampagneRefusee;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $campagne, $geste, $instant] = $argv;
while (microtime(true) < (float) $instant) {
    usleep(200);
}
try {
    $gestes = $app->make(GestesCampagne::class);
    $geste === 'pause' ? $gestes->pause($espace, $campagne, 'processus', null) : $gestes->reprendre($espace, $campagne, 'processus', null);
    exit(0);
} catch (CampagneRefusee) {
    exit(3);
}
