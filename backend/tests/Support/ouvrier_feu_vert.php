<?php

/*
 * Ouvrier du test de concurrence du feu vert final (processus séparé, connexion séparée).
 * Usage : php ouvrier_feu_vert.php <workspace> <empreinte lue> <départ microtime>
 * Liste SIMULÉE (tous les points verts, calcul volontairement lent pour élargir la fenêtre de course) : seule la
 * prise du verrou par espace dans DecisionFeuVert peut départager. Aucun envoi, aucune configuration modifiée.
 * Écrit {"statut":int}.
 */

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DecisionFeuVert;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Envoi\Sante\DepotSante;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/garde_environnement_ouvrier.php'; // avant tout : hors APP_ENV=testing, arrêt code 3
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ws, $empreinte, $depart] = $argv;
while (microtime(true) < (float) $depart) {
    usleep(500);
}

$liste = new class($app->make(DepotPreuves::class), $app->make(DepotSante::class)) extends ListeFeuVert
{
    public function points(string $ws): array
    {
        usleep(400000);
        $r = [];
        foreach (CatalogueManuels::POINTS as $code => $c) {
            $r[] = new PointFeuVert($code, $c['libelle'], str_starts_with($code, 'auto.'), true, 'simulé', $c['source'], $c['groupe'], PointFeuVert::ETAT_FAIT);
        }

        return $r;
    }
};

$res = (new DecisionFeuVert($liste))->decider($ws, 'accorde', 'Décision de test de concurrence, réf. exemple-01', 'responsable', $empreinte);
echo json_encode(['statut' => $res['statut']]), "\n";
