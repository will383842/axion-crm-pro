<?php

declare(strict_types=1);

// Prépare la base de la passerelle locale : 3 événements purgés (vieux de 40 jours) + 3 récents. Affiche la borne de purge.
require __DIR__.'/../../passerelle/vendor/autoload.php';

use Passerelle\Stockage;

[, $fichier, $espace] = $argv + [null, '', ''];
$maintenant = time();
$horloge = $maintenant - 40 * 86400;
$s = new Stockage($fichier, static function () use (&$horloge): int {
    return $horloge;
}, true);
for ($i = 0; $i < 3; $i++) {
    $s->ajouterEvenement($espace, 'delivery', ['id_injection' => 'vieux-'.$i]);
}
$horloge = $maintenant;
for ($i = 0; $i < 3; $i++) {
    $s->ajouterEvenement($espace, 'delivery', ['id_injection' => 'recent-'.$i]);
}
$s->purger();
echo $s->dernierPurge($espace), "\n";
