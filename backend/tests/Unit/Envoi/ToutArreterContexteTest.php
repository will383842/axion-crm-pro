<?php

use App\Envoi\Chauffe\ToutArreter;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Socle\Alertes\Alerte;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Log;
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

test('reprendre() : serveur injoignable => l\'alerte « levé » part quand même, l\'échec est relancé, le drapeau reste levé', function () {
    $ws = (string) Str::uuid();
    $m = Mockery::mock(ServeurEnvoi::class);
    $m->shouldReceive('reprise')->once()->andThrow(new ServeurEnvoiRefuse('serveur injoignable'));
    $depot = new DepotMemoire;
    $alerte = app(Alerte::class);
    Log::spy();
    $arret = new ToutArreter($depot, $m, $alerte);
    $depot->armerToutArreter($ws, 'auteur-test');

    expect(fn () => $arret->reprendre($ws, 'auteur-test'))->toThrow(ServeurEnvoiRefuse::class, 'serveur injoignable');

    Log::shouldHaveReceived('critical')->withArgs(fn ($t) => $t === '[ALERTE] Tout arrêter levé')->once();
    expect($arret->reprendre($ws, 'auteur-test'))->toBeFalse(); // drapeau bien levé
});
