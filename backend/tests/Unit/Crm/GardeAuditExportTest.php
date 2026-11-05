<?php

use App\Crm\Origines\GardeAuditExport;


test('arrêt brutal : une seule ligne, marquée interrompue, lignes lues bornées au prévu', function () {
    $appels = [];
    $etat = (object) ['lues' => 7];
    $garde = new GardeAuditExport(function (int $n, bool $i) use (&$appels) { $appels[] = [$n, $i]; }, $etat, 5);

    $garde();   // la fonction d'arrêt
    $garde();   // un second appel ne réécrit rien
    $garde->terminer();

    expect($appels)->toBe([[5, true]]);
});

test('fin normale : la fonction d\'arrêt n\'écrit plus rien', function () {
    $appels = [];
    $garde = new GardeAuditExport(function (int $n, bool $i) use (&$appels) { $appels[] = [$n, $i]; }, (object) ['lues' => 3], 10);

    $garde->terminer();
    $garde();

    expect($appels)->toBe([[3, false]]);
});
