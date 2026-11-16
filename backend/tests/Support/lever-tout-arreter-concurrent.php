<?php

/**
 * Sous-processus des tests « levée / armement de Tout arrêter concurrents » (T-SOCLE point a) : démarre l'application,
 * attend l'instant convenu, puis appelle `DepotSocleDb::leverToutArreter` ou `armerToutArreter` (auto-commit, comme en service).
 * Avec un délai de verrou (secondes, 0 = aucun), `lock_timeout` est posé sur la session : une attente trop longue échoue
 * proprement. Écrit UNE ligne JSON sur STDOUT : {"resultat": ..., "sqlstate": ..., "duree": ...}. Aucune exception brute
 * n'est imprimée (ni message SQL, ni requête) : seulement l'état SQL à cinq caractères.
 * Usage : php lever-tout-arreter-concurrent.php <espace> <lever|armer> <instant-unix-décimal> <délai-de-verrou-en-secondes>
 */

require __DIR__ . '/garde_environnement_ouvrier.php'; // première instruction : hors APP_ENV=testing, arrêt code 3

use App\Envoi\Depot\DepotSocleDb;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $action, $instant, $delai] = $argv;
WorkspaceContext::set($espace);
if ((int) $delai > 0) {
    DB::statement('SET lock_timeout = ' . ((int) $delai * 1000)); // millisecondes, entier : aucune donnée externe dans le SQL
}
while (microtime(true) < (float) $instant) {
    usleep(200);
}
$debut = microtime(true);
$sortie = ['resultat' => null, 'sqlstate' => null];
try {
    $depot = new DepotSocleDb;
    if ($action === 'armer') {
        $depot->armerToutArreter($espace, 'sous-processus');
        $sortie['resultat'] = true;
    } else {
        $sortie['resultat'] = $depot->leverToutArreter($espace, 'sous-processus');
    }
} catch (QueryException $e) {
    $sortie['sqlstate'] = (string) $e->getCode(); // jamais le message : il porte la requête
}
$sortie['duree'] = round(microtime(true) - $debut, 3);
echo json_encode($sortie), "\n";
exit(0);
