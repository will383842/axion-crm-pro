<?php

/**
 * GARDE — LE DÉPLOIEMENT N'ENVOIE PLUS D'ALERTE TELEGRAM (lot S9).
 *
 * Avant S9, une étape du workflow de production prévenait par une messagerie
 * tierce quand le déploiement échouait. Décision de Will : les alertes passent
 * par la cloche de la console et par l'e-mail seulement ; l'étape est retirée
 * et remplacée par rien (le battement externe prévient quand le CRM se tait).
 * Cette garde empêche le retour de l'étape ET de ses secrets.
 *
 * Filet modifié : l'ancienne garde exigeait la PRÉSENCE de l'étape ; elle
 * exige désormais son ABSENCE (retournement voulu par le plan S9).
 */

use Tests\TestCase;

uses(TestCase::class);

function alerteDeploiementSource(): string
{
    $chemin = (realpath(base_path('..')) ?: base_path('..'))
        . '/.github/workflows/deploy-direct-ssh.yml';

    // Témoin de présence : une garde qui lit un fichier absent passerait au vert sans rien mesurer.
    expect(file_exists($chemin))->toBeTrue("Le banc ne voit pas {$chemin}.");

    return (string) file_get_contents($chemin);
}

test('ALERTE-DEPLOIEMENT — plus aucune etape ni secret de messagerie tierce dans le deploiement', function () {
    $source = strtolower(alerteDeploiementSource());

    expect($source)->not->toContain('telegram');
});

test('ALERTE-DEPLOIEMENT — TEMOIN : le workflow est bien lu (smoke test et fin de deploiement presents)', function () {
    $source = alerteDeploiementSource();

    expect($source)->toContain('Deploy OK')
        ->and($source)->toContain('App URL failed');
});
