<?php

use App\Envoi\Chauffe\ToutArreter;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Support\Str;
use Tests\Unit\Envoi\DepotMemoire;

/** Serveur espion : enregistre le contexte d'espace vu pendant pause()/reprise(). */
function serveurEspionContexte(array &$vus): ServeurEnvoi
{
    $m = Mockery::mock(ServeurEnvoi::class);
    $m->shouldReceive('pause')->andReturnUsing(function () use (&$vus): void {
        $vus[] = ['pause', WorkspaceContext::current()];
    });
    $m->shouldReceive('reprise')->andReturnUsing(function () use (&$vus): void {
        $vus[] = ['reprise', WorkspaceContext::current()];
    });

    return $m;
}

test('E2 : armer() et reprendre() appellent le serveur DANS le contexte de l\'espace', function () {
    $ws = (string) Str::uuid();
    WorkspaceContext::clear();
    $vus = [];
    $arret = new ToutArreter(new DepotMemoire, serveurEspionContexte($vus));

    $arret->armer($ws, 'auteur-test');
    expect($arret->reprendre($ws, 'auteur-test'))->toBeTrue();

    expect($vus)->toBe([['pause', $ws], ['reprise', $ws]]);
    expect(WorkspaceContext::current())->toBeNull(); // le contexte est restauré
});
