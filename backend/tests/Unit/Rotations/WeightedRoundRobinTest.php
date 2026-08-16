<?php

use App\Services\Rotations\WeightedRoundRobin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedRotation(string $workspaceId, string $dimension, int $weight = 1, array $extra = []): int
{
    // `rotations.workspace_id` porte une FK vers `workspaces(id)` : sans ligne
    // workspace, l'insert est rejeté par `rotations_workspace_id_fkey`.
    DB::table('workspaces')->insertOrIgnore([
        'id' => $workspaceId,
        'slug' => 'wrr-' . substr($workspaceId, 0, 8),
        'name' => 'WRR test workspace',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) DB::table('rotations')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'dimension' => $dimension,
        // La colonne réelle est `slug` (migration 2026_05_16_000004, contrainte
        // UNIQUE (workspace_id, dimension, slug)). `identifier` n'a jamais
        // existé dans ce schéma : c'était la fixture qui inventait la colonne.
        'slug' => 'item-' . Str::random(6),
        'enabled' => true,
        'weight' => $weight,
        'cooldown_seconds' => 0,
        'metadata' => json_encode(['count' => 0]),
        'created_at' => now(),
        'updated_at' => now(),
    ], $extra));
}

test('WeightedRoundRobin pick retourne null si aucun item', function () {
    $rr = new WeightedRoundRobin;
    $ws = (string) Str::uuid();
    expect($rr->pick($ws, 'nonexistent'))->toBeNull();
});

test('WeightedRoundRobin pick retourne un item disponible', function () {
    $rr = new WeightedRoundRobin;
    $ws = (string) Str::uuid();
    $id = seedRotation($ws, 'proxy', 10);

    $picked = $rr->pick($ws, 'proxy');
    expect($picked)->not->toBeNull();
    expect((int) $picked['id'])->toBe($id);
});

test('WeightedRoundRobin incrémente le compteur metadata.count', function () {
    $rr = new WeightedRoundRobin;
    $ws = (string) Str::uuid();
    $id = seedRotation($ws, 'proxy');

    $rr->pick($ws, 'proxy');
    $row = DB::table('rotations')->find($id);
    $metadata = json_decode($row->metadata, true);
    expect((int) $metadata['count'])->toBe(1);
});

test('WeightedRoundRobin skip items disabled', function () {
    $rr = new WeightedRoundRobin;
    $ws = (string) Str::uuid();
    seedRotation($ws, 'proxy', 1, ['enabled' => false]);

    expect($rr->pick($ws, 'proxy'))->toBeNull();
});

test('WeightedRoundRobin respecte cooldown', function () {
    $rr = new WeightedRoundRobin;
    $ws = (string) Str::uuid();
    seedRotation($ws, 'proxy', 1, [
        'cooldown_seconds' => 3600,
        'last_used_at' => now(),  // utilisé à l'instant
    ]);

    expect($rr->pick($ws, 'proxy'))->toBeNull();
});

test('WeightedRoundRobin pondère par weight (1 vs 10)', function () {
    $rr = new WeightedRoundRobin;
    $ws = (string) Str::uuid();
    $highWeight = seedRotation($ws, 'proxy', 10);
    $lowWeight = seedRotation($ws, 'proxy', 1);

    // En picks successifs, l'item à high weight devrait être pick plus souvent
    $counts = [$highWeight => 0, $lowWeight => 0];
    for ($i = 0; $i < 11; $i++) {
        $picked = $rr->pick($ws, 'proxy');
        if ($picked) {
            $counts[(int) $picked['id']]++;
        }
    }
    expect($counts[$highWeight])->toBeGreaterThan($counts[$lowWeight]);
});

test('WeightedRoundRobin pick ne croise pas les workspaces', function () {
    $rr = new WeightedRoundRobin;
    $ws1 = (string) Str::uuid();
    $ws2 = (string) Str::uuid();
    seedRotation($ws1, 'proxy', 1);

    expect($rr->pick($ws2, 'proxy'))->toBeNull();
});
