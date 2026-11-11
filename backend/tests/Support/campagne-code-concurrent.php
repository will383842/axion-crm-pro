<?php

/**
 * Sous-processus du test de consommation concurrente d'un code de campagne : démarre l'application, attend l'instant
 * convenu (pour que les processus partent ensemble), puis tente de consommer le code. Sortie 0 = consommé, 3 = refusé.
 * Usage : php campagne-code-concurrent.php <espace> <campagne> <code> <instant-unix-décimal>
 */

use App\Envoi\Campagnes\DepotCampagnes;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $campagne, $code, $instant] = $argv;
while (microtime(true) < (float) $instant) {
    usleep(200);
}
exit($app->make(DepotCampagnes::class)->consommerCode($espace, $campagne, $code) ? 0 : 3);
