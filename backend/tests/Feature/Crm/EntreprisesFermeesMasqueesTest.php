<?php

/**
 * ENTREPRISES FERMÉES SELON L'INSEE : MASQUÉES PAR DÉFAUT DANS LA CONSOLE
 * (décision du propriétaire, 04/10/2026 — `App\Support\EntreprisesFermees`).
 *
 *   - la liste et la recherche globale ne les rendent plus, sauf
 *     `fermees=inclure` (toutes) ou `fermees=seules` (elles seules) ;
 *   - les compteurs (total de la liste, indicateurs, accueil) ne les comptent
 *     plus, et restent cohérents avec la liste ;
 *   - la FICHE reste accessible par son lien direct, avec sa date de
 *     fermeture ;
 *   - rien n'est supprimé ni modifié en base.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN tirés au hasard avec une clé de
 * Luhn volontairement FAUSSE, donc attribués à personne.
 */

use App\Models\User;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Un SIREN fictif : 9 chiffres dont la clé de Luhn est FAUSSE. */
function fermSiren(): string
{
    $corps = (string) random_int(10000000, 99999999);
    for ($cle = 0; $cle <= 9; $cle++) {
        $somme = 0;
        foreach (str_split(strrev($corps . $cle)) as $i => $c) {
            $d = (int) $c * ($i % 2 === 1 ? 2 : 1);
            $somme += $d > 9 ? $d - 9 : $d;
        }
        if ($somme % 10 === 0) {
            return $corps . (($cle + 1) % 10);
        }
    }

    return $corps . '1';
}

function fermEspace(string $nom): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-ferm-' . substr(str_replace('-', '', $id), 0, 8), 'name' => $nom,
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function fermFiche(string $espace, array $champs = []): int
{
    return (int) DB::table('companies')->insertGetId($champs + [
        'workspace_id' => $espace,
        'siren' => fermSiren(),
        'denomination' => 'ZZ FERM ' . Str::random(6),
        'discovery_source' => 'site', 'quality_score' => 40, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'created_at' => now()->subDays(10), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    Cache::flush();
    $this->espace = fermEspace('ZZ fermées');
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->espace);
    $this->user->assignRole('owner');
    $this->actingAs($this->user);

    $this->ouverte = fermFiche($this->espace, [
        'denomination' => 'ZZ FERM OUVERTE', 'size_category' => 'tpe', 'sector_main' => 'btp', 'enriched_at' => now()->subHours(2),
    ]);
    $this->sirenFermee = fermSiren();
    $this->fermee = fermFiche($this->espace, [
        'siren' => $this->sirenFermee, 'denomination' => 'ZZ FERM FERMEE', 'size_category' => 'pme', 'sector_main' => 'btp',
        'enriched_at' => now()->subHours(2), 'insee_ferme_le' => '2026-09-15',
        'prospection_status' => 'archived_no_email', 'archive_reason' => 'entreprise_radiee',
    ]);
});

/** @return list<int> */
function fermIds(TestResponse $r): array
{
    return array_map('intval', array_column($r->json('data'), 'id'));
}

test('liste par défaut : la fermée est masquée, le total ne la compte pas', function () {
    $r = $this->getJson('/api/v1/companies?vue=liste&per_page=50')->assertOk();

    expect(fermIds($r))->toBe([$this->ouverte])
        ->and($r->json('meta.total'))->toBe(1);

    // RIEN n'a bougé en base : la fiche est toujours là, telle quelle.
    $ligne = DB::table('companies')->where('id', $this->fermee)->first();
    expect($ligne->deleted_at)->toBeNull()
        ->and((string) $ligne->insee_ferme_le)->toBe('2026-09-15');
});

test('fermees=inclure : les deux, avec la date de fermeture dans la ligne', function () {
    $r = $this->getJson('/api/v1/companies?vue=liste&per_page=50&fermees=inclure')->assertOk();

    expect(fermIds($r))->toEqualCanonicalizing([$this->ouverte, $this->fermee])
        ->and($r->json('meta.total'))->toBe(2);

    $parId = collect($r->json('data'))->keyBy('id');
    expect((string) $parId[$this->fermee]['insee_ferme_le'])->toStartWith('2026-09-15')
        ->and($parId[$this->ouverte]['insee_ferme_le'])->toBeNull();
});

test('fermees=seules : uniquement la fermée', function () {
    $r = $this->getJson('/api/v1/companies?vue=liste&per_page=50&fermees=seules')->assertOk();

    expect(fermIds($r))->toBe([$this->fermee])
        ->and($r->json('meta.total'))->toBe(1);
});

test('une valeur inconnue de fermees masque (une adresse bricolée ne les montre pas)', function () {
    $r = $this->getJson('/api/v1/companies?vue=liste&per_page=50&fermees=n-importe-quoi')->assertOk();

    expect(fermIds($r))->toBe([$this->ouverte])
        ->and($r->json('meta.total'))->toBe(1);
});

test('les autres filtres se combinent : masquées par défaut, visibles sur demande', function () {
    $q = '/api/v1/companies?vue=liste&per_page=50&filter[sector_main]=btp';

    expect($this->getJson($q)->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson($q . '&fermees=inclure')->assertOk()->json('meta.total'))->toBe(2)
        ->and($this->getJson($q . '&fermees=seules')->assertOk()->json('meta.total'))->toBe(1);
});

test('la fiche d une entreprise fermée reste accessible par son lien direct, avec sa date', function () {
    $r = $this->getJson('/api/v1/companies/' . $this->fermee)->assertOk();

    expect((string) ($r->json('data.insee_ferme_le') ?? $r->json('insee_ferme_le')))->toStartWith('2026-09-15');
});

test('compteurs de la liste (/companies/stats) : la fermée ne compte pas', function () {
    $s = $this->getJson('/api/v1/companies/stats')->assertOk();

    expect($s->json('total'))->toBe(1)
        ->and($s->json('enrichies_pct'))->toBe(100)
        ->and($s->json('top_taille.code'))->toBe('tpe')
        ->and($s->json('top_taille.n'))->toBe(1)
        ->and($s->json('top_secteur.n'))->toBe(1);

    // Cohérent avec le total de la liste par défaut.
    expect($this->getJson('/api/v1/companies?vue=liste')->json('meta.total'))->toBe($s->json('total'));
});

test('accueil (/dashboard/stats) : total, enrichies, tailles sans la fermée', function () {
    $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($r->json('companies_total'))->toBe(1)
        ->and($r->json('companies_enriched'))->toBe(1)
        ->and($r->json('companies_enriched_24h'))->toBe(1)
        ->and($r->json('size_distribution.tpe'))->toBe(1)
        ->and($r->json('size_distribution.pme'))->toBe(0);

    // Cohérent avec la liste par défaut.
    expect($this->getJson('/api/v1/companies?vue=liste')->json('meta.total'))->toBe($r->json('companies_total'));
});

test('recherche globale : la fermée masquée par défaut, retrouvée avec fermees=inclure', function () {
    $parDefaut = $this->getJson('/api/v1/search?q=' . $this->sirenFermee)->assertOk();
    $inclure = $this->getJson('/api/v1/search?q=' . $this->sirenFermee . '&fermees=inclure')->assertOk();

    expect(array_column($parDefaut->json('companies'), 'id'))->not->toContain($this->fermee)
        ->and(array_map('intval', array_column($inclure->json('companies'), 'id')))->toContain($this->fermee);
});

test('index partiel idx_companies_ws_fermees présent et valide après migration', function () {
    $index = DB::selectOne(
        'SELECT i.indisvalid AS valide, pg_get_indexdef(i.indexrelid) AS def
           FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
          WHERE c.relname = ?',
        ['idx_companies_ws_fermees'],
    );

    expect($index)->not->toBeNull()
        ->and((bool) $index->valide)->toBeTrue()
        ->and($index->def)->toContain('insee_ferme_le IS NOT NULL');
});
