<?php

/**
 * Sous-processus du test « deux arrêts généraux concurrents » (T-SOCLE) : démarre l'application, attend l'instant convenu,
 * puis appelle la méthode `arretGeneral` du contrôleur. Les alertes sont écrites dans un fichier de journal dédié.
 * Usage : php arret-general-concurrent.php <espace> <fichier-journal> <instant-unix-décimal>
 */

require __DIR__ . '/garde_environnement_ouvrier.php'; // première instruction : hors APP_ENV=testing, arrêt code 3

use App\Http\Controllers\Api\EnvoiSocleController;
use App\Models\User;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $espace, $fichier, $instant] = $argv;
config(['logging.default' => 'arret', 'logging.channels.arret' => ['driver' => 'single', 'path' => $fichier, 'level' => 'debug']]);
while (microtime(true) < (float) $instant) {
    usleep(200);
}
$requete = Request::create('/api/v1/envoi/arret-general', 'POST');
$requete->setUserResolver(fn () => new User(['name' => 'Compte Test']));
WorkspaceContext::set($espace);
$reponse = $app->call([$app->make(EnvoiSocleController::class), 'arretGeneral'], ['request' => $requete]);
exit($reponse->getStatusCode() === 200 ? 0 : 3);
