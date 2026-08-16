<?php

use App\Services\Rgpd\GdprErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 🔴 DONNÉES DE SANTÉ (RGPD art. 9) — LONGTEMPS HORS DE TOUT EFFACEMENT.
 *
 * `health_practitioners` porte `nom`, `prenom`, `specialite`, `phone`, `email`,
 * `address`, `postcode`, `city`, `rpps`. Sa propre migration l'annonce :
 * « ⚠️ Donnée nominative de SANTÉ (RGPD art. 9, catégorie particulière) ».
 *
 * Elle n'était visée par AUCUN des deux services d'effacement, AUCUNE purge,
 * AUCUNE politique de rétention (constaté le 2026-08-16). Et le modèle utilise
 * `SoftDeletes` : un « effacement » y aurait de toute façon laissé la ligne
 * entière.
 *
 * 🔑 Pourquoi une suppression FERME et non une anonymisation, contrairement à
 * `journalists` : nullifier l'email et le téléphone laisserait `nom` +
 * `prenom` + `specialite` + `address`. C'est-à-dire une personne identifiable
 * ET sa donnée de santé. L'anonymisation n'anonymise rien ici.
 */
function praticienDeSante(string $email, ?string $phone = null): int
{
    $workspaceId = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $workspaceId,
        'slug' => 'sante-' . Str::random(6),
        'name' => 'WS Sante',
        'settings' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) DB::table('health_practitioners')->insertGetId([
        'workspace_id' => $workspaceId,
        'nom' => 'Durand',
        'prenom' => 'Camille',
        'specialite' => 'Cardiologie',
        'phone' => $phone,
        'email' => $email,
        'address' => '12 rue des Lilas',
        'postcode' => '38000',
        'city' => 'Grenoble',
        'rpps' => '10001234567',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('l’effacement console SUPPRIME le praticien de santé, il ne l’anonymise pas', function () {
    $email = 'dr.durand@example.com';
    praticienDeSante($email);

    expect(DB::table('health_practitioners')->count())->toBe(1);

    $resultat = app(GdprErasureService::class)->erase($email);

    expect($resultat['deleted']['health_practitioners'])->toBe(1)
        // Suppression FERME : plus aucune ligne, pas même en soft-delete.
        ->and(DB::table('health_practitioners')->count())->toBe(0);
});

test('l’effacement atteint aussi le praticien retrouvé par son TÉLÉPHONE', function () {
    $telephone = '+33612345678';
    praticienDeSante('autre-adresse@example.com', $telephone);

    app(GdprErasureService::class)->erase('sujet@example.com', $telephone);

    expect(DB::table('health_practitioners')->count())->toBe(0);
});

test('un praticien SANS rapport avec le sujet n’est jamais touché', function () {
    praticienDeSante('confrere@example.com');

    app(GdprErasureService::class)->erase('quelquun-dautre@example.com');

    expect(DB::table('health_practitioners')->count())->toBe(1);
});

test('aucune trace identifiante ne subsiste après effacement', function () {
    $email = 'dr.effacee@example.com';
    praticienDeSante($email);

    app(GdprErasureService::class)->erase($email);

    // On cherche ce qui rendrait la personne identifiable même sans son email.
    $restes = DB::table('health_practitioners')
        ->where('nom', 'Durand')
        ->orWhere('rpps', '10001234567')
        ->orWhere('specialite', 'Cardiologie')
        ->count();

    expect($restes)->toBe(0);
});
