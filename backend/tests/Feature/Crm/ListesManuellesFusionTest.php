<?php

/**
 * LISTES MANUELLES × FUSION DE DOUBLONS (chantier 5) — 2026-09-30.
 *
 * Une organisation cochée dans une liste reste dans la liste quand elle est
 * absorbée par une fusion (la ligne suit la fiche gardée), et y revient quand
 * la fusion est annulée. Si la fiche gardée y était déjà, rien n'est
 * dupliqué ni perdu. Fixtures FICTIVES.
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Listes\ListesManuelles;
use App\Models\ListeManuelle;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-listes-fusion');
    $this->garde = F::fiche($this->ws, 'ZZ Sigma', ['postcode' => '69001', 'website' => 'https://zz-sigma.example.invalid']);
    $this->absorbee = F::sansSiren($this->ws, 'ZZ Sigma', ['postcode' => '69001', 'website' => 'https://zz-sigma.example.invalid']);
    $this->seule = ListeManuelle::create(['workspace_id' => $this->ws, 'nom' => 'ZZ Absorbée seule']);
    $this->deux = ListeManuelle::create(['workspace_id' => $this->ws, 'nom' => 'ZZ Les deux']);
    ListesManuelles::ajouter($this->seule, [$this->absorbee], [], null, 'coche');
    ListesManuelles::ajouter($this->deux, [$this->absorbee, $this->garde], [], null, 'coche');
});

function lfMembres(int $liste): array
{
    $ids = DB::table('listes_manuelles_membres')->where('liste_id', $liste)->whereNull('retire_le')->pluck('company_id')->map(static fn (mixed $v): int => (int) $v)->all();
    sort($ids);

    return $ids;
}

test('la fusion rattache l appartenance à la fiche gardée, sans doublon ; l annulation la rend', function () {
    $fusion = WorkspaceContext::run($this->ws, fn (): int => app(FusionFiches::class)->fusionner(
        $this->ws,
        $this->garde,
        $this->absorbee,
        Rapprochement::NOM_CP_SITE,
        FusionFiches::MODE_MANUEL,
        null,
        null,
        'test',
    ));

    expect($fusion)->toBeGreaterThan(0)
        ->and(lfMembres($this->seule->id))->toBe([$this->garde])
        // La fiche gardée y était déjà : la ligne de l'absorbée reste, avec elle.
        ->and(lfMembres($this->deux->id))->toBe([$this->garde, $this->absorbee]);

    WorkspaceContext::run($this->ws, fn (): array => app(FusionFiches::class)->annuler($this->ws, $fusion, 'test'));

    expect(lfMembres($this->seule->id))->toBe([$this->absorbee])
        ->and(lfMembres($this->deux->id))->toBe([$this->garde, $this->absorbee]);
});
