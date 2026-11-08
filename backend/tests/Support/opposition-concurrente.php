<?php

/**
 * Sous-processus des tests de concurrence réelle (désinscription L5) : démarre
 * l'application, attend l'instant convenu (pour que deux processus partent
 * ensemble), puis enregistre l'opposition. Code de sortie 0 = réponse « connue ».
 * Usage : php opposition-concurrente.php <espace> <référence> <instant-unix-décimal> [<fichier-des-effets>]
 * Si un fichier est donné, chaque effet aval déclenché (événement OppositionEnregistree) y ajoute une ligne :
 * le test compte ainsi les effets, ce qui prouve le verrou de ligne (et pas seulement le dédoublonnage en base).
 */

use App\Envoi\Desinscription\EnregistrerOpposition;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $reference, $instant] = $argv;
if (isset($argv[4])) {
    $fichierEffets = $argv[4];
    Illuminate\Support\Facades\Event::listen(\App\Envoi\Desinscription\OppositionEnregistree::class, static function () use ($fichierEffets): void {
        file_put_contents($fichierEffets, "effet\n", FILE_APPEND | LOCK_EX);
    });
}
while (microtime(true) < (float) $instant) {
    usleep(200);
}
$connu = $app->make(EnregistrerOpposition::class)->executer($espace, $reference, EnregistrerOpposition::CANAL_ONE_CLICK);
exit($connu ? 0 : 3);
