<?php

use App\Envoi\ListesNoires\DepotControlesDb;
use App\Envoi\ListesNoires\Ressource;
use App\Envoi\ListesNoires\StatutListe;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceListes(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'T', 'slug' => 'k13-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

test('pause enregistrée, reportée, isolée par espace, ajout seul', function () {
    $ws = espaceListes();
    $autre = espaceListes();
    $depot = new DepotControlesDb;
    $r = new Ressource($ws, Ressource::DOMAINE, 'a.example');
    $t = new DateTimeImmutable('2026-11-11 10:00:00');

    $depot->enregistrer($r, StatutListe::Liste, 'dbl.spamhaus.org', '127.0.1.2', true, $t, $t);
    expect($depot->enPause($r))->toBeTrue();
    // un contrôle « non listé » ultérieur ne lève PAS la pause (décision humaine)
    $depot->enregistrer($r, StatutListe::NonListe, '*', null, false, $t->modify('+1 minute'));
    expect($depot->enPause($r))->toBeTrue();
    expect($depot->enPause(new Ressource($autre, Ressource::DOMAINE, 'a.example')))->toBeFalse();

    WorkspaceContext::run($ws, function () {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_controles_listes')->update(['zone' => 'x'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_controles_listes')->delete()))->toThrow(QueryException::class);
    });
});
