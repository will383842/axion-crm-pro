<?php

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

function espaceCatalogueV4(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'v4-' . substr($id, 0, 8), 'settings' => json_encode(['produits' => ['envoi' => 'actif']]), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function pointCatalogueV4(string $ws, string $code): PointFeuVert
{
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        if ($p->code === $code) {
            return $p;
        }
    }
    throw new RuntimeException($code);
}

test('K14-CAT : catalogue v5 (K17-FV : trois points auto.* de plus), 48 points dont 29 manuels', function () {
    expect(CatalogueManuels::VERSION)->toBe(5)
        ->and(CatalogueManuels::codes())->toHaveCount(48)
        ->and(CatalogueManuels::codesManuels())->toHaveCount(29);
});

test('K14-CAT : manuel.yahoo_cfl existe, source « 30 A11 », exige le vrai serveur, bloquant, groupe conformité', function () {
    $ws = espaceCatalogueV4();
    expect(CatalogueManuels::estManuel('manuel.yahoo_cfl'))->toBeTrue();
    $p = pointCatalogueV4($ws, 'manuel.yahoo_cfl');
    expect($p->source)->toBe('30 A11')
        ->and(CatalogueManuels::POINTS['manuel.yahoo_cfl']['serveur_reel'])->toBeTrue()
        ->and($p->libelle)->toBe('Inscription Yahoo, boucle de plaintes CFL')
        ->and($p->automatique)->toBeFalse()->and($p->fait)->toBeFalse()->and($p->bloquant)->toBeTrue()
        ->and($p->etat)->toBe('attend_serveur_reel');
    app(DepotPreuves::class)->ajouter($ws, 'manuel.yahoo_cfl', 'Inscription déposée, réf. exemple-11', 'auteur');
    expect(pointCatalogueV4($ws, 'manuel.yahoo_cfl'))->fait->toBeFalse()->etat->toBe('attend_serveur_reel');
});

test('K14-CAT : le libellé Orange ne parle plus de CFL ; le code ne change pas', function () {
    $ws = espaceCatalogueV4();
    $p = pointCatalogueV4($ws, 'manuel.orange_cfl');
    expect($p->libelle)->toBe('Orange : prise de contact délivrabilité')
        ->and(mb_strtoupper($p->libelle))->not->toContain('CFL')
        ->and($p->source)->toBe('30 A11');
});

test('K14-CAT : garde avec témoin : une preuve déjà posée sur manuel.orange_cfl reste lue (même code, même point)', function () {
    $ws = espaceCatalogueV4();
    // Preuve posée « avant » v4, directement en base (même forme que DepotPreuves).
    app(DepotPreuves::class)->ajouter($ws, 'manuel.orange_cfl', 'Contact délivrabilité écrit, réf. exemple-12', 'auteur');
    $p = pointCatalogueV4($ws, 'manuel.orange_cfl');
    // Point qui exige le vrai serveur : la preuve est lue, l'état reste « attend_serveur_reel » comme avant v4.
    expect($p->preuve)->toBe('Contact délivrabilité écrit, réf. exemple-12')
        ->and($p->etat)->toBe('attend_serveur_reel')
        ->and(DB::table('envoi_feu_vert_preuves')->where('workspace_id', $ws)->where('code', 'manuel.orange_cfl')->count())->toBe(1);
});
