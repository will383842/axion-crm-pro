<?php

/**
 * GARDE D'INFRASTRUCTURE — L5 suite, point 1 (audit #443).
 *
 * Le lien de désinscription d'un message (`/desinscription/{jeton}`) est servi par le
 * BACKEND. Le Caddyfile n'envoyait au backend que /api, /sanctum, /docs, /up et
 * /broadcasting : le reste partait vers le SPA, qui y servait une page « en attente ».
 * Un destinataire qui clique ne pouvait donc pas s'opposer. Chaque bloc « app » (local,
 * prod, préprod) doit router ce chemin vers le backend, et le SPA ne doit plus le masquer.
 */

use Tests\TestCase;

uses(TestCase::class);

function caddyfileDesinscription(): string
{
    $chemin = (realpath(base_path('..')) ?: base_path('..')) . '/infra/caddy/Caddyfile';
    expect(file_exists($chemin))->toBeTrue('Le banc ne voit pas infra/caddy/Caddyfile : une garde sans fichier passerait au vert.');

    return (string) file_get_contents($chemin);
}

test('/desinscription/* est routé au backend dans les trois blocs app (local, prod, préprod)', function () {
    preg_match_all('/^[ \t]*@api\s*\{\s*path\s+([^\n]+)\n/m', caddyfileDesinscription(), $m);
    expect($m[1])->toHaveCount(3);
    foreach ($m[1] as $filtre) {
        expect(preg_split('/\s+/', trim($filtre)))->toContain('/desinscription/*');
    }
});

test('le SPA ne déclare plus de route /desinscription/$jeton qui masquerait la route du backend', function () {
    $chemin = (realpath(base_path('..')) ?: base_path('..')) . '/frontend/src/app/routeTree.tsx';
    expect(file_exists($chemin))->toBeTrue();
    expect((string) file_get_contents($chemin))->not->toContain("'/desinscription/");
});
