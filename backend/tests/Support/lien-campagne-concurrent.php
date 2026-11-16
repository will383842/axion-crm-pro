<?php

/**
 * Sous-processus du test de liaison concurrente campagne/séquence (lot X1-L1) : démarre l'application, se connecte avec le RÔLE
 * APPLICATIF (pgsql_app, espace posé), attend l'instant convenu, puis tente soit de poser sequence_id (« lier »), soit de ramener
 * la campagne à « a_valider ». Sortie 0 = accepté, 3 = refusé par la base (P0001, version périmée comprise), 4 = autre échec.
 * Usage : php lien-campagne-concurrent.php <espace> <campagne> <lier|a_valider> <sequence> <version-lue> <instant-unix-décimal>
 */

require __DIR__ . '/garde_environnement_ouvrier.php'; // première instruction : hors APP_ENV=testing, arrêt code 3

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $campagne, $geste, $sequence, $version, $instant] = $argv;
$c = DB::connection('pgsql_app');
$c->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
while (microtime(true) < (float) $instant) {
    usleep(200);
}
try {
    $maj = $geste === 'lier' ? ['sequence_id' => $sequence] : ['statut' => 'a_valider'];
    $n = $c->table('envoi_campagnes')->where('id', $campagne)->where('version', (int) $version)->update($maj + ['version' => (int) $version + 1]);
    exit($n === 1 ? 0 : 3);
} catch (QueryException $e) {
    exit($e->getCode() === 'P0001' ? 3 : 4);
}
