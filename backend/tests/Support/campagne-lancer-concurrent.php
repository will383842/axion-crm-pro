<?php

/**
 * Sous-processus du test de course L5-A : démarre l'application, se connecte avec le RÔLE APPLICATIF (pgsql_app, espace posé), attend
 * l'instant convenu, puis joue UN geste (lancer, pause ou reprise) via GestesCampagne. Sortie 0 = appliqué, 3 = refusé proprement
 * (CampagneRefusee), 1 = erreur inattendue (STDERR).
 * Usage : php campagne-lancer-concurrent.php <espace> <campagne> <lancer|pause|reprise> <instant-unix-décimal>
 */

require __DIR__ . '/garde_environnement_ouvrier.php'; // première instruction : hors APP_ENV=testing, arrêt code 3

use App\Envoi\Campagnes\GestesCampagne;
use App\Envoi\Exceptions\CampagneRefusee;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $campagne, $geste, $instant] = $argv;
DB::setDefaultConnection('pgsql_app');
DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
while (microtime(true) < (float) $instant) {
    usleep(200);
}
try {
    $gestes = $app->make(GestesCampagne::class);
    match ($geste) {
        'lancer' => $gestes->lancer($espace, $campagne, 'processus', null),
        'pause' => $gestes->pause($espace, $campagne, 'processus', null),
        default => $gestes->reprendre($espace, $campagne, 'processus', null),
    };
    exit(0);
} catch (CampagneRefusee) {
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ' : ' . $e->getMessage());
    exit(1);
}
