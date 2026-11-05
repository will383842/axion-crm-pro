<?php

/**
 * L2-back — l'historique d'origines rejoué SOUS le rôle de production
 * (`axion_app`, RLS forcée) : rien d'un autre espace, même en connaissant l'id.
 *
 * Tout se passe dans la transaction du test (`SET LOCAL ROLE`), annulée à la
 * fin : rien n'est commité, rien n'est supprimé.
 */

use App\Crm\Origines\RequetesOrigines;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('OLR 1 — sous axion_app, l’historique d’une personne d’un autre espace est vide', function () {
    $a = OriginesJeu::espace('zz-olr-a');
    $b = OriginesJeu::espace('zz-olr-b');
    $pa = OriginesJeu::personne($a->id, 'olr.a@example.invalid', [OriginesJeu::entree(['post_ref' => 'post_ref_olr_a'])]);
    $pb = OriginesJeu::personne($b->id, 'olr.b@example.invalid', [OriginesJeu::entree(['post_ref' => 'post_ref_olr_b'])]);
    OriginesJeu::archive($a->id, $pa, OriginesJeu::entree(['post_ref' => 'post_ref_arch_a', 'date' => '2026-10-01T10:00:00Z']));
    OriginesJeu::archive($b->id, $pb, OriginesJeu::entree(['post_ref' => 'post_ref_arch_b', 'date' => '2026-10-01T10:00:00Z']));

    DB::statement('SET LOCAL ROLE axion_app');
    try {
        $propre = WorkspaceContext::run($a->id, fn () => RequetesOrigines::historique($a->id, $pa, null, 50));
        $etranger = WorkspaceContext::run($a->id, fn () => RequetesOrigines::historique($a->id, $pb, null, 50));
        $agregat = WorkspaceContext::run($a->id, fn () => RequetesOrigines::apercu($a->id, RequetesOrigines::filtresVides()));
    } finally {
        DB::statement('RESET ROLE');
    }

    expect($propre['total'])->toBe(2)
        ->and($etranger['total'])->toBe(0)
        ->and($agregat['demandes'])->toBe(2);
});
