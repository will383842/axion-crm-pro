<?php

/*
 * Ouvrier de test de concurrence (processus séparé, connexion séparée).
 * Usage : php ouvrier_injection.php <workspace> <jour UTC Y-m-d> <cle> <plafondEspace> <plafondGlobal> <départ microtime>
 * Écrit sur la sortie standard une ligne JSON {"accepte":bool}. Transport : simulateur seulement.
 */

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ws, $jour, $cle, $plafond, $global, $depart] = $argv;
while (microtime(true) < (float) $depart) {
    usleep(500);
}

$passerelle = new PasserelleLocale(
    new FauxServeurEnvoi,
    new DepotSocleDb,
    true,
    (int) $plafond,
    fn () => new DateTimeImmutable($jour . ' 12:00:00', new DateTimeZone('UTC')),
    $ws,
    (int) $global,
    fn () => WorkspaceContext::current(),
);

$resultat = WorkspaceContext::run($ws, fn () => $passerelle->injecter(
    new MessageAEnvoyer($ws, 'temoin@example.test', 'from@example.test', 'S', 'C', $cle),
));
echo json_encode(['accepte' => $resultat->accepte]), "\n";
