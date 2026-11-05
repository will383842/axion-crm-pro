<?php

/** L7 — le calcul SQL des agrégats ne porte aucune donnée de personne. */

use App\Crm\Usine\AgregatsOrigines;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('le calcul ne contient ni adresse, ni post_ref, ni identifiant de personne', function () {
    $ws = (string) Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion'])->id;
    foreach (range(1, 4) as $i) {
        DB::table('personnes')->insert([
            'workspace_id' => $ws, 'person_key' => 'pk' . $i, 'email' => "personne{$i}@example.invalid",
            'email_hash' => hash('sha256', "personne{$i}@example.invalid"), 'premiere_source' => 'site',
            'premiere_source_at' => '2026-11-03 10:00:00', 'legal_basis' => 'consent',
            'origines' => json_encode([[
                'mot' => 'guide', 'reseau' => 'instagram', 'compte' => 'axion', 'guide' => 'g1',
                'date' => '2026-11-03T10:00:00Z', 'post_ref' => 'POSTREF' . $i . 'abcdef', 'commentaire_ref' => 'empreinte' . $i,
            ]]),
        ]);
    }

    $lignes = AgregatsOrigines::pourJour($ws, '2026-11-03');
    $json = json_encode($lignes, JSON_THROW_ON_ERROR);

    expect($lignes)->not->toBe([])
        ->and($lignes[0]['demandes'])->toBe(4)
        ->and($json)->not->toContain('@')->not->toContain('POSTREF')->not->toContain('empreinte')->not->toContain('personne');
});
