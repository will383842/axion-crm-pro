<?php

/** L7 — le calcul SQL des agrégats ne porte aucune donnée de personne. */

use App\Crm\Usine\AgregatsOrigines;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

/**
 * Insère une personne ayant une origine ; `$origines` = liste d'origines (compte brut, mot…).
 *
 * @param  list<array<string, string>>  $origines
 */
function personneAvecOrigines(string $ws, int $n, array $origines): void
{
    DB::table('personnes')->insert([
        'workspace_id' => $ws, 'person_key' => 'agr' . $n, 'email' => "agr{$n}@example.invalid",
        'email_hash' => hash('sha256', "agr{$n}@example.invalid"), 'premiere_source' => 'site',
        'premiere_source_at' => '2026-11-03 10:00:00', 'legal_basis' => 'consent',
        'origines' => json_encode(array_map(static fn (array $o): array => $o + [
            'reseau' => 'instagram', 'mot' => 'guide', 'guide' => 'g1', 'date' => '2026-11-03T10:00:00Z',
        ], $origines)),
    ]);
}

function espaceAgregats(): string
{
    return (string) Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion'])->id;
}

test('deux graphies du même compte donnent UNE ligne, personnes distinctes comptées une fois', function () {
    $ws = espaceAgregats();
    // 1 personne sous les deux graphies, 2 sous « Axion IA », 2 sous « axion_ia » : 5 distinctes.
    personneAvecOrigines($ws, 1, [['compte' => 'Axion IA'], ['compte' => 'axion_ia']]);
    foreach ([2, 3] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => 'Axion IA']]);
    }
    foreach ([4, 5] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => 'axion_ia']]);
    }

    $lignes = AgregatsOrigines::masquer(AgregatsOrigines::pourJour($ws, '2026-11-03'));

    expect($lignes)->toHaveCount(1)
        ->and($lignes[0]['compte'])->toBe('axion_ia')
        ->and($lignes[0]['demandes'])->toBe(5);
});

test('« 123abc » et « c123abc » ne donnent pas deux lignes identiques', function () {
    $ws = espaceAgregats();
    foreach ([1, 2] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => '123abc']]);
    }
    foreach ([3, 4] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => 'c123abc']]);
    }

    $lignes = AgregatsOrigines::masquer(AgregatsOrigines::pourJour($ws, '2026-11-03'));
    $cles = array_map(static fn (array $l): string => $l['compte'] . '|' . $l['mot'], $lignes);

    expect(count($cles))->toBe(count(array_unique($cles)))
        ->and($lignes)->toHaveCount(1)
        ->and($lignes[0]['compte'])->toBe('c123abc')
        ->and($lignes[0]['demandes'])->toBe(4);
});

test('deux comptes de plus de 40 caractères au même début donnent une seule ligne valide', function () {
    $ws = espaceAgregats();
    $debut = str_repeat('a', 40);
    personneAvecOrigines($ws, 1, [['compte' => $debut . 'xx']]);
    foreach ([2, 3] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => $debut . 'yy']]);
    }

    $lignes = AgregatsOrigines::masquer(AgregatsOrigines::pourJour($ws, '2026-11-03'));

    expect($lignes)->toHaveCount(1)
        ->and($lignes[0]['compte'])->toBe($debut)
        ->and($lignes[0]['compte'])->toMatch('/^[a-z][a-z0-9_]{1,39}$/')
        ->and($lignes[0]['demandes'])->toBe(3);
});

test('un mot invalide est exclu du push', function () {
    $ws = espaceAgregats();
    foreach ([1, 2, 3] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => 'axion', 'mot' => 'mot invalide!']]);
    }
    foreach ([4, 5, 6] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => 'axion', 'mot' => 'guide']]);
    }

    $lignes = AgregatsOrigines::masquer(AgregatsOrigines::pourJour($ws, '2026-11-03'));

    expect(array_column($lignes, 'mot'))->toBe(['guide']);
});

test('501 lignes : 500 envoyées, avertissement journalisé sans donnée de personne', function () {
    $lignes = [];
    for ($i = 1; $i <= 501; $i++) {
        $lignes[] = [
            'jour' => '2026-11-03', 'reseau' => 'instagram', 'compte' => sprintf('compte%03d', $i),
            'mot' => 'guide', 'demandes' => 5, 'guides' => 5, 'accords' => 5, 'mises_a_l_ecart' => 5,
        ];
    }
    shuffle($lignes);
    Log::spy();

    $sortie = AgregatsOrigines::masquer($lignes);

    expect($sortie)->toHaveCount(500)
        ->and($sortie[0]['compte'])->toBe('compte001')
        ->and($sortie[499]['compte'])->toBe('compte500');
    Log::shouldHaveReceived('warning')->once()->withArgs(
        static fn (string $msg, array $ctx = []): bool => ! str_contains(json_encode($ctx), 'compte') && ! str_contains($msg, 'compte'),
    );
});

test('un compte sans caractère [a-z0-9] est exclu au lieu de fusionner en « c__ »', function () {
    $ws = espaceAgregats();
    foreach ([1, 2, 3] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => '@@']]);
    }
    foreach ([4, 5, 6] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => '__']]);
    }
    foreach ([7, 8, 9] as $i) {
        personneAvecOrigines($ws, $i, [['compte' => 'Axion']]);
    }

    $brut = AgregatsOrigines::pourJour($ws, '2026-11-03');

    expect(array_column($brut, 'compte'))->toBe(['axion']);
});

test('le trim SQL du compte est aligné sur PHP (espace, tabulation, saut de ligne)', function () {
    $ws = espaceAgregats();
    personneAvecOrigines($ws, 1, [['compte' => "\tAxion \n"]]);
    personneAvecOrigines($ws, 2, [['compte' => 'axion']]);

    $brut = AgregatsOrigines::pourJour($ws, '2026-11-03');

    expect($brut)->toHaveCount(1)->and($brut[0]['compte'])->toBe('axion')->and($brut[0]['demandes'])->toBe(2);
});

test('un réseau hors format est exclu côté CRM', function () {
    $ws = espaceAgregats();
    personneAvecOrigines($ws, 1, [['compte' => 'axion', 'reseau' => 'Insta gram!']]);
    personneAvecOrigines($ws, 2, [['compte' => 'axion', 'reseau' => 'instagram']]);

    $brut = AgregatsOrigines::pourJour($ws, '2026-11-03');

    expect(array_column($brut, 'reseau'))->toBe(['instagram']);
});
