<?php

/**
 * Sous-processus du test de course L5-B : démarre l'application, se connecte avec le RÔLE APPLICATIF (pgsql_app, espace posé), attend
 * l'instant convenu, puis joue UN geste (arreter, terminer) ou UNE clôture seule (clore). Sortie 0 = appliqué, 3 = refusé proprement
 * (CampagneRefusee ou LogicException de la garde de statut), 1 = erreur inattendue (STDERR).
 * Usage : php cloture-concurrente.php <espace> <campagne> <arreter|terminer|clore> <instant-unix-décimal>
 */

require __DIR__ . '/garde_environnement_ouvrier.php'; // première instruction : hors APP_ENV=testing, arrêt code 3

use App\Envoi\Campagnes\ClotureInscriptionsCampagne;
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
    match ($geste) {
        'arreter' => $app->make(GestesCampagne::class)->arreter($espace, $campagne, 'processus', null),
        'terminer' => $app->make(GestesCampagne::class)->terminer($espace, $campagne, 'processus', null),
        default => $app->make(ClotureInscriptionsCampagne::class)->clore($espace, $campagne, new DateTimeImmutable('now')),
    };
    exit(0);
} catch (CampagneRefusee|LogicException) {
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ' : ' . $e->getMessage());
    exit(1);
}
