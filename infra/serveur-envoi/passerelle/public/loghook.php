<?php

declare(strict_types=1);

// Point d'entrée LOCAL du log hook (K7) : 127.0.0.1 seulement (contrôlé aussi par PointEntree et par nginx).
require __DIR__.'/../vendor/autoload.php';

use Passerelle\Evenements\Ingestion;
use Passerelle\Evenements\JetonsNonDisponibles;
use Passerelle\Evenements\PointEntree;
use Passerelle\Stockage;

ini_set('display_errors', '0');
[$statut, $corps] = ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ? [405, '{"erreur":"methode"}'] : (static function (): array {
    try {
        $s = new Stockage(getenv('PASSERELLE_BASE') ?: '/var/lib/passerelle/passerelle.sqlite', static fn (): int => time());
        $alerte = static function (string $code, array $ctx): void { error_log('passerelle: ALERTE derive '.$code.' '.json_encode($ctx)); };

        // DETTE K7-D1 : JetonsNonDisponibles tant que K6a n'est pas fusionné.
        return (new PointEntree(new Ingestion($s, new JetonsNonDisponibles, $alerte)))->traiter((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) file_get_contents('php://input'));
    } catch (\Throwable) {
        return [503, '{"erreur":"indisponible"}'];
    }
})();
http_response_code($statut);
header('Content-Type: application/json');
echo $corps;
