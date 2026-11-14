<?php

/**
 * Sous-processus des tests « demande de code concurrente » (R11) : démarre l'application, attend l'instant convenu, puis
 * demande un code pour la campagne. Sortie 0 = code émis, 3 = refus métier (ErreurCreation), 1 = autre erreur.
 * Usage : php campagne-code-demande-concurrent.php <espace> <auteur> <campagne> <instant-unix-décimal>
 */

use App\Envoi\Creation\ErreurCreation;
use App\Envoi\Creation\ValidationCampagne;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $auteur, $campagne, $instant] = $argv;
while (microtime(true) < (float) $instant) {
    usleep(200);
}
try {
    $app->make(ValidationCampagne::class)->demanderCode($espace, $auteur, 'auteur@exemple.test', $campagne, null);
    exit(0);
} catch (ErreurCreation) {
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ' ' . $e->getMessage());
    exit(1);
}
