<?php

/**
 * Sous-processus du test de construction concurrente (lot X1-L3) : démarre l'application, se connecte avec le RÔLE APPLICATIF
 * (pgsql_app, espace posé), attend l'instant convenu, puis joue UN geste : « construire » (ConstructionSequence) ou « date »
 * (GestesCampagne::changerDate). Sortie 0 = construite (ou date changée), 2 = déjà faite, 3 = ConstructionRefusee / CampagneRefusee,
 * 4 = autre échec. Le motif de refus est écrit sur STDOUT.
 * Usage : php construction-sequence-concurrente.php <espace> <campagne> <construire|date> <instant-unix-décimal>
 */

require __DIR__ . '/garde_environnement_ouvrier.php'; // première instruction : hors APP_ENV=testing, arrêt code 3

use App\Envoi\Campagnes\ConstructionRefusee;
use App\Envoi\Campagnes\ConstructionSequence;
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
    if ($geste === 'date') {
        $app->make(GestesCampagne::class)->changerDate($espace, $campagne, new DateTimeImmutable('+3 days'), 'processus', null);
        exit(0);
    }
    $r = $app->make(ConstructionSequence::class)->construire($espace, $campagne);
    exit($r->dejaFaite ? 2 : 0);
} catch (ConstructionRefusee $e) {
    fwrite(STDOUT, $e->motif);
    exit(3);
} catch (CampagneRefusee $e) {
    fwrite(STDOUT, $e->codeErreur);
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ' : ' . $e->getMessage());
    exit(4);
}
