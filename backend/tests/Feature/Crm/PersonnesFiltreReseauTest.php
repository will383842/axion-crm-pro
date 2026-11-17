<?php

/**
 * PO-L — liste des personnes : `reseaux` de chaque personne et filtre
 * `origine_reseau`, derrière LA garde `FiltreOrigine::actif()`. Chaque cas
 * tourne sous le rôle propriétaire ET sous `axion_app` (RLS armée).
 * Adresses en `@example.invalid`.
 */

use App\Envoi\Campagnes\FiltreOrigine;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    OriginesJeu::allumer();
    $this->axion = OriginesJeu::espace('axion-ia');
    OriginesJeu::ouvrir($this->axion->id);
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'admin', 'pol.admin@example.invalid'));
    $ws = $this->axion->id;

    $this->p1 = OriginesJeu::personne($ws, 'pol.un@example.invalid', [
        OriginesJeu::entree(['reseau' => 'linkedin', 'post_ref' => 'post_ref_pol_01']),
        OriginesJeu::entree(['reseau' => 'instagram', 'post_ref' => 'post_ref_pol_02']),
        OriginesJeu::entree(['reseau' => 'linkedin', 'post_ref' => 'post_ref_pol_03']),
    ]);
    // Seule l'entrée tiktok est masquée : la personne n'a PAS ce réseau.
    $this->p2 = OriginesJeu::personne($ws, 'pol.deux@example.invalid', [
        OriginesJeu::entree(['reseau' => 'tiktok', 'post_ref' => 'post_ref_pol_04', 'masque_le' => '2026-11-04T00:00:00Z']),
        OriginesJeu::entree(['reseau' => 'instagram', 'post_ref' => 'post_ref_pol_05']),
    ]);
    $this->p3 = OriginesJeu::personne($ws, 'pol.trois@example.invalid', []);

    $b = OriginesJeu::espace('zz-pol-b');
    OriginesJeu::ouvrir($b->id);
    $this->pb = OriginesJeu::personne($b->id, 'pol.b@example.invalid', [OriginesJeu::entree(['reseau' => 'linkedin', 'post_ref' => 'post_ref_pol_b'])]);
});

/** Rejoue le corps sous le propriétaire puis sous axion_app (transaction du test). */
function pol_sous_deux_roles(Closure $corps): void
{
    foreach ([null, 'axion_app'] as $role) {
        if ($role !== null) {
            DB::statement("SET LOCAL ROLE {$role}");
        }
        try {
            $corps($role ?? 'proprietaire');
        } finally {
            if ($role !== null) {
                DB::statement('RESET ROLE');
            }
        }
    }
}

function pol_ids(array $json): array
{
    $ids = array_map('intval', array_column($json['data'], 'id'));
    sort($ids);

    return $ids;
}

test('PL-L 1 — porte fermée : sans paramètre 200 et aucune clé reseaux ; avec paramètre 422', function () {
    config(['origines.lecture_enabled' => false]);
    expect(FiltreOrigine::actif($this->axion->id))->toBeFalse();

    pol_sous_deux_roles(function (string $role) {
        $r = $this->getJson('/api/v1/crm/personnes')->assertOk()->json();
        expect($r['data'])->not->toBeEmpty();
        foreach ($r['data'] as $ligne) {
            expect(array_key_exists('reseaux', $ligne))->toBeFalse("{$role}: reseaux présent porte fermée");
        }
        $this->getJson('/api/v1/crm/personnes?origine_reseau=linkedin')->assertStatus(422)
            ->assertJsonPath('errors.origine_reseau.0', 'origine_lecture_inactive');
        $this->get('/api/v1/crm/personnes/export?origine_reseau=linkedin')->assertStatus(422);
    });
});

test('PL-L 2 — porte ouverte : filtre exact, entrée masquée exclue, reseaux trié et dédoublonné', function () {
    pol_sous_deux_roles(function (string $role) {
        $linkedin = $this->getJson('/api/v1/crm/personnes?origine_reseau=linkedin')->assertOk()->json();
        expect(pol_ids($linkedin))->toBe([$this->p1], $role);

        $insta = $this->getJson('/api/v1/crm/personnes?origine_reseau=instagram')->assertOk()->json();
        $attendu = [$this->p1, $this->p2];
        sort($attendu);
        expect(pol_ids($insta))->toBe($attendu, $role);

        // tiktok n'existe que dans une entrée masquée : personne.
        expect($this->getJson('/api/v1/crm/personnes?origine_reseau=tiktok')->assertOk()->json('data'))->toBe([]);

        $tous = collect($this->getJson('/api/v1/crm/personnes')->assertOk()->json('data'))->keyBy('id');
        expect($tous[$this->p1]['reseaux'])->toBe(['instagram', 'linkedin'])
            ->and($tous[$this->p2]['reseaux'])->toBe(['instagram'])
            ->and($tous[$this->p3]['reseaux'])->toBe([]);
    });
});

test('PL-L 3 — jeton invalide : 422 validation', function () {
    foreach (['LinkedIn', 'a b', "x\ny", '-x', str_repeat('a', 65)] as $mauvais) {
        $this->getJson('/api/v1/crm/personnes?origine_reseau=' . rawurlencode($mauvais))->assertStatus(422);
    }
    $this->getJson('/api/v1/crm/personnes?origine_reseau[]=linkedin')->assertStatus(422);
});

test('PL-L 4 — export filtré = liste filtrée', function () {
    pol_sous_deux_roles(function (string $role) {
        $csv = $this->get('/api/v1/crm/personnes/export?inclure_non_prospectables=oui&origine_reseau=linkedin')->assertOk()->streamedContent();
        expect($csv)->toContain('pol.un@example.invalid')
            ->not->toContain('pol.deux@example.invalid')
            ->not->toContain('pol.trois@example.invalid')
            ->not->toContain('pol.b@example.invalid');
    });
});

test('PL-L 5 — espace B : ni vu ni filtrable depuis A', function () {
    pol_sous_deux_roles(function (string $role) {
        $r = $this->getJson('/api/v1/crm/personnes?origine_reseau=linkedin')->assertOk()->json();
        expect(pol_ids($r))->not->toContain($this->pb);
        $tous = $this->getJson('/api/v1/crm/personnes')->assertOk()->json();
        expect(pol_ids($tous))->not->toContain($this->pb);
    });
});

test('PL-L 6 — masquage des coordonnées inchangé pour un viewer', function () {
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'viewer', 'pol.viewer@example.invalid'));
    $r = $this->getJson('/api/v1/crm/personnes?origine_reseau=linkedin')->assertOk();
    expect($r->getContent())->not->toContain('pol.un@example.invalid');
    expect($r->json('data.0.reseaux'))->toBe(['instagram', 'linkedin']);
});

test('PL-L 7 — l’écran Origines ignore origine_reseau : liste identique, aucune clé reseaux', function () {
    $sans = $this->getJson('/api/v1/crm/origines/personnes')->assertOk()->json();
    $avec = $this->getJson('/api/v1/crm/origines/personnes?origine_reseau=linkedin')->assertOk()->json();
    expect($avec['data'])->toBe($sans['data']);
});
