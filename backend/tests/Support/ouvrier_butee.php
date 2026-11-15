<?php

/*
 * Ouvrier du test de concurrence de la butée en base (processus séparé, connexion séparée).
 * Usage : php ouvrier_butee.php <workspace> <jour UTC Y-m-d> <cle> <départ microtime>
 * Plafonds PHP à 300 : seule la butée en base (envoi_compteur_espace) peut refuser. Simulateur seulement.
 * Écrit {"accepte":bool}.
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

[, $ws, $jour, $cle, $depart] = $argv;
while (microtime(true) < (float) $depart) {
    usleep(500);
}

$passerelle = new PasserelleLocale(
    new FauxServeurEnvoi,
    new DepotSocleDb,
    true,
    300,
    fn () => new DateTimeImmutable($jour . ' 12:00:00', new DateTimeZone('UTC')),
    $ws,
    300,
    fn () => WorkspaceContext::current(),
);

$resultat = WorkspaceContext::run($ws, fn () => $passerelle->injecter(
    new MessageAEnvoyer($ws, 'temoin@example.test', 'from@example.test', 'S', 'C', $cle),
));
echo json_encode(['accepte' => $resultat->accepte]), "\n";
