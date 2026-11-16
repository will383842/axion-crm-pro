<?php

/**
 * Sous-processus du test de matérialisation concurrente (lot X1-L4-B) : démarre l'application, se connecte avec le RÔLE APPLICATIF
 * (pgsql_app, espace posé), attend l'instant convenu, puis joue UN `materialiser`. Sortie 0 = résultat rendu, 3 = refus
 * (le motif est écrit sur STDOUT), 4 = autre échec (STDERR).
 * Usage : php materialisation-concurrente.php <espace> <campagne> <instant-unix-décimal> (lots de 2 destinataires, jusqu'à 5 lots par appel : les lots s'entrelacent)
 */

require __DIR__ . '/garde_environnement_ouvrier.php'; // première instruction : hors APP_ENV=testing, arrêt code 3

use App\Envoi\Campagnes\ConstructionRefusee;
use App\Envoi\Campagnes\ConstructionSequence;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Campagnes\MaterialisationRefusee;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Depot\DepotSocle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $campagne, $instant] = $argv;
DB::setDefaultConnection('pgsql_app');
DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
while (microtime(true) < (float) $instant) {
    usleep(200);
}
try {
    (new MaterialisationCampagne($app->make(DepotCampagnes::class), $app->make(SelectionDestinataires::class), $app->make(ConstructionSequence::class), $app->make(DepotSocle::class), 2, 5))->materialiser($espace, $campagne, new DateTimeImmutable('2026-11-16 07:00:00', new DateTimeZone('Europe/Paris')));
    exit(0);
} catch (MaterialisationRefusee|ConstructionRefusee $e) {
    fwrite(STDOUT, $e->motif);
    exit(3);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ' : ' . $e->getMessage());
    exit(4);
}
