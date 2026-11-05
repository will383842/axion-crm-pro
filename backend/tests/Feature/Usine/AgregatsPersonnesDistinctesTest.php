<?php

/** L7 (B1) — chaque métrique compte des PERSONNES DISTINCTES, jamais des entrées. */

use App\Crm\Usine\AgregatsOrigines;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function l7Personne(string $ws, int $i, array $origines, ?array $consentements = null): void
{
    DB::table('personnes')->insert([
        'workspace_id' => $ws, 'person_key' => 'pd' . $i, 'email' => "pd{$i}@example.invalid",
        'email_hash' => hash('sha256', "pd{$i}@example.invalid"), 'premiere_source' => 'site',
        'premiere_source_at' => '2026-11-03 10:00:00', 'legal_basis' => 'consent',
        'origines' => json_encode($origines),
        'consentements' => $consentements === null ? null : json_encode($consentements),
    ]);
}

function l7Entree(array $en = []): array
{
    return $en + ['mot' => 'guide', 'reseau' => 'instagram', 'compte' => 'axion', 'guide' => 'g1', 'masque_le' => '2026-11-03T12:00:00Z', 'date' => '2026-11-03T10:00:00Z'];
}

test('1 personne × 3 entrées le même jour → compte 1 par métrique, donc aucune ligne (masquée)', function () {
    $ws = (string) Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion'])->id;
    l7Personne($ws, 1, [l7Entree(), l7Entree(), l7Entree()], [['nature' => 'optin']]);

    $brut = AgregatsOrigines::pourJour($ws, '2026-11-03');

    expect($brut)->toHaveCount(1)
        ->and($brut[0]['demandes'])->toBe(1)->and($brut[0]['guides'])->toBe(1)
        ->and($brut[0]['accords'])->toBe(1)->and($brut[0]['mises_a_l_ecart'])->toBe(1)
        ->and(AgregatsOrigines::masquer($brut, 3))->toBe([]);
});

test('3 personnes distinctes → 3 ; « accords » = consentements non vide (même définition que PR-A)', function () {
    $ws = (string) Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion'])->id;
    l7Personne($ws, 1, [l7Entree(), l7Entree()], [['nature' => 'autre']]);   // non vide, nature quelconque → accord
    l7Personne($ws, 2, [l7Entree()], [['nature' => 'optin']]);
    l7Personne($ws, 3, [l7Entree(['guide' => ''])], []);                      // vide → pas d'accord
    l7Personne($ws, 4, [l7Entree(['masque_le' => ''])], null);                // null → pas d'accord

    $l = AgregatsOrigines::pourJour($ws, '2026-11-03')[0];

    expect($l['demandes'])->toBe(4)->and($l['accords'])->toBe(2)
        ->and($l['guides'])->toBe(3)->and($l['mises_a_l_ecart'])->toBe(3);
});
