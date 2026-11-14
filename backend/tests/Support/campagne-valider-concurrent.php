<?php

/**
 * Sous-processus du test « deux valider concurrents avec le même code » (R11) : démarre l'application, attend l'instant
 * convenu, puis valide la campagne. Sortie 0 = validée, 3 = refusée (ErreurCreation).
 * Usage : php campagne-valider-concurrent.php <espace> <auteur> <campagne> <code> <instant-unix-décimal>
 */

use App\Envoi\Creation\ErreurCreation;
use App\Envoi\Creation\ValidationCampagne;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $auteur, $campagne, $code, $instant] = $argv;
while (microtime(true) < (float) $instant) {
    usleep(200);
}
try {
    $app->make(ValidationCampagne::class)->valider($espace, $auteur, $campagne, $code, null);
    exit(0);
} catch (ErreurCreation) {
    exit(3);
}
