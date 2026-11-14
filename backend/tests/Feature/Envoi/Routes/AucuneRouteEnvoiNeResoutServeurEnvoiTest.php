<?php

namespace Tests\Feature\Envoi\Routes;

uses(BaseRoutesEnvoi::class)->group('envoi');

test('aucune route GET /envoi/* ne résout ServeurEnvoi (lié à un faux qui lève une exception)', function () {
    $user = $this->compte($this->espace(true), 'owner');
    $vues = 0;
    foreach ($this->routesEnvoi() as [$methode, $uri]) {
        if ($methode !== 'GET') {
            continue;
        }
        $vues++;
        $statut = $this->actingAs($user)->getJson($uri)->getStatusCode();
        expect($statut)->toBeLessThan(500, $uri);
    }
    expect($vues)->toBeGreaterThan(0)->and($this->resolutions)->toBe(0);
});
