<?php

declare(strict_types=1);

// Routeur de la passerelle LOCALE DE TEST (php -S). FauxKumo : aucun envoi réel. Ne sert QUE le kit de conformité.
require __DIR__.'/../../passerelle/vendor/autoload.php';

use Passerelle\Configuration;
use Passerelle\FauxKumo;
use Passerelle\Passerelle;
use Passerelle\Requete;
use Passerelle\Stockage;

$entetes = [];
foreach (getallheaders() ?: [] as $k => $v) {
    $entetes[strtolower($k)][] = $v;
}
$base = (string) getenv('PASSERELLE_BASE');
$rep = (new Passerelle(static fn (): Stockage => new Stockage($base, static fn (): int => time()), new FauxKumo, Configuration::cles()))
    ->traiter(new Requete($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', (string) file_get_contents('php://input'), $entetes));
http_response_code($rep->statut);
foreach ($rep->entetes as $k => $v) {
    header("$k: $v");
}
echo $rep->corps;
