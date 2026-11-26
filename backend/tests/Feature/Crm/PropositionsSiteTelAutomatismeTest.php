<?php

/**
 * PR 5a : un automatisme peut proposer `website` et `phone` (et plus
 * seulement `email_generic`). Rien n'est écrit sur la fiche : la valeur
 * entre dans la file, le propriétaire décide.
 *
 * Fixtures FICTIVES : noms « ZZ », `.example.invalid`, numéros en 01 99.
 */

use App\Crm\Propositions\Propositions;
use App\Services\Audit\AuditHashChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
});

test('un automatisme propose un site différent : proposition, fiche intacte', function () {
    $ws = F::espace('zz-p5a-site');
    $id = F::fiche($ws, 'ZZ Site', ['website' => 'https://ancien.example.invalid']);

    $r = app(Propositions::class)->proposerAutomatisme($ws, 'entreprise', $id, 'website', 'https://nouveau.example.invalid', 'site-verifie');

    expect($r)->toBe(Propositions::PROPOSEE)
        ->and(DB::table('companies')->where('id', $id)->value('website'))->toBe('https://ancien.example.invalid')
        ->and(DB::table('propositions_champs')->where('entite_id', $id)->where('champ', 'website')->where('origine', 'site-verifie')->count())->toBe(1);
});

test('un automatisme propose un téléphone en conflit : proposition, fiche intacte', function () {
    $ws = F::espace('zz-p5a-tel');
    $id = F::fiche($ws, 'ZZ Tel', ['phone' => '0199000001']);

    $r = app(Propositions::class)->proposerAutomatisme($ws, 'entreprise', $id, 'phone', '0199000002', 'site-verifie');

    expect($r)->toBe(Propositions::PROPOSEE)
        ->and(DB::table('companies')->where('id', $id)->value('phone'))->toBe('0199000001');
});

test('website et phone sont admis pour un automatisme, pas pour un tiers', function () {
    expect(Propositions::champAdmis('entreprise', 'website', 'site-verifie'))->toBeTrue()
        ->and(Propositions::champAdmis('entreprise', 'phone', 'site-verifie'))->toBeTrue()
        ->and(Propositions::libelleChamp('entreprise', 'website'))->not->toBe('website');
});

test('un champ hors liste reste refusé pour un automatisme', function () {
    $ws = F::espace('zz-p5a-refus');
    $id = F::fiche($ws, 'ZZ Refus');

    app(Propositions::class)->proposerAutomatisme($ws, 'entreprise', $id, 'city', 'Lyon', 'site-verifie');
})->throws(InvalidArgumentException::class);
