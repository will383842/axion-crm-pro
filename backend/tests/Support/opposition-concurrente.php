<?php

/**
 * Sous-processus des tests de concurrence réelle (désinscription L5) : démarre
 * l'application, attend l'instant convenu (pour que deux processus partent
 * ensemble), puis enregistre l'opposition. Code de sortie 0 = réponse « connue ».
 * Usage : php opposition-concurrente.php <espace> <référence> <instant-unix-décimal>
 */

use App\Envoi\Desinscription\EnregistrerOpposition;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $reference, $instant] = $argv;
while (microtime(true) < (float) $instant) {
    usleep(200);
}
$connu = $app->make(EnregistrerOpposition::class)->executer($espace, $reference, EnregistrerOpposition::CANAL_ONE_CLICK);
exit($connu ? 0 : 3);
