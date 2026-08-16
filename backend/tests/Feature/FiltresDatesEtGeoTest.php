<?php

/**
 * FILTRES PAR DATE ET RÉFÉRENTIELS GÉOGRAPHIQUES.
 *
 * Deux manques constatés en relisant l'écran Entreprises :
 *   - `region_code` était DÉCLARÉ dans l'état du composant mais n'avait aucun
 *     contrôle : un filtre qu'on ne peut pas régler est du code mort ;
 *   - aucun filtre par DATE n'existait, ni à l'écran ni côté serveur — « les
 *     fiches arrivées depuis lundi » était impossible à demander.
 *
 * Le département se saisissait à la main (« Dept (75… ) »), ce qui suppose de
 * connaître les codes par cœur et laisse passer les fautes de frappe sans rien
 * dire : une saisie invalide rend une liste vide qui se lit comme « aucun
 * résultat ».
 */

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-filtres', 'name' => 'WS', 'settings' => [],
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'f@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->actingAs($this->user);
});

function ficheDatee(string $workspaceId, string $nom, string $date, array $extra = []): Company
{
    $c = Company::create(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => $nom,
        'signals' => [], 'metadata' => [],
    ], $extra));

    // `created_at` est posé par Eloquent : on le force pour situer la fiche
    // dans le temps.
    $c->forceFill(['created_at' => $date])->save();

    return $c;
}

function denominations(TestResponse $r): array
{
    return array_column($r->assertOk()->json('data'), 'denomination');
}

test('filtre « créées après » — la borne basse inclut le jour demandé', function () {
    ficheDatee($this->workspace->id, 'ANCIENNE', '2026-01-10 08:00:00');
    ficheDatee($this->workspace->id, 'PILE', '2026-06-01 09:30:00');
    ficheDatee($this->workspace->id, 'RECENTE', '2026-07-20 12:00:00');

    $noms = denominations($this->getJson('/api/v1/companies?filter[cree_apres]=2026-06-01'));

    // « depuis le 1er juin » inclut le 1er juin — l'exclure surprendrait et
    // ferait disparaître les fiches du jour demandé.
    sort($noms);
    expect($noms)->toBe(['PILE', 'RECENTE']);
});

test('filtre « créées avant » — la borne haute couvre la JOURNÉE entière', function () {
    ficheDatee($this->workspace->id, 'MATIN', '2026-06-15 08:00:00');
    ficheDatee($this->workspace->id, 'SOIR', '2026-06-15 23:45:00');
    ficheDatee($this->workspace->id, 'APRES', '2026-06-16 00:30:00');

    $noms = denominations($this->getJson('/api/v1/companies?filter[cree_avant]=2026-06-15'));

    // ⬇️ Le piège classique : une borne haute prise à minuit ferait
    // disparaître tout ce qui est arrivé dans la journée demandée.
    sort($noms);
    expect($noms)->toBe(['MATIN', 'SOIR']);
});

test('les deux bornes se combinent en fenêtre', function () {
    ficheDatee($this->workspace->id, 'AVANT', '2026-05-31 23:00:00');
    ficheDatee($this->workspace->id, 'DEDANS', '2026-06-10 10:00:00');
    ficheDatee($this->workspace->id, 'APRES', '2026-07-01 01:00:00');

    $noms = denominations(
        $this->getJson('/api/v1/companies?filter[cree_apres]=2026-06-01&filter[cree_avant]=2026-06-30'),
    );

    expect($noms)->toBe(['DEDANS']);
});

test('une date ABÎMÉE ne filtre pas au hasard et ne casse rien', function () {
    ficheDatee($this->workspace->id, 'A', '2026-06-01 10:00:00');
    ficheDatee($this->workspace->id, 'B', '2026-07-01 10:00:00');

    // Ces filtres viennent de l'URL : copier-coller, lien partagé, robot. Une
    // valeur invalide doit rendre la liste NON filtrée — jamais une 500, et
    // jamais un filtrage approximatif qui ferait croire à un résultat.
    $r = $this->getJson('/api/v1/companies?filter[cree_apres]=pas-une-date');

    expect($r->status())->toBe(200);
    expect(denominations($r))->toHaveCount(2);
});

test('filtre par région et par département', function () {
    ficheDatee($this->workspace->id, 'PARIS', '2026-06-01 10:00:00', [
        'region_code' => '11', 'department_code' => '75',
    ]);
    ficheDatee($this->workspace->id, 'LYON', '2026-06-01 10:00:00', [
        'region_code' => '84', 'department_code' => '69',
    ]);

    expect(denominations($this->getJson('/api/v1/companies?filter[region_code]=11')))->toBe(['PARIS']);
    expect(denominations($this->getJson('/api/v1/companies?filter[department_code]=69')))->toBe(['LYON']);
});

test('le référentiel géographique sert les valeurs de la BASE', function () {
    // `regions.country_code` porte une clé étrangère : le pays doit exister
    // d'abord. Une fixture qui ignore les contraintes ne teste pas le schéma
    // réel.
    DB::table('countries')->insertOrIgnore([
        'code_iso2' => 'FR', 'code_iso3' => 'FRA', 'name_fr' => 'France',
        'name_en' => 'France', 'created_at' => now(),
    ]);
    DB::table('regions')->insert(['code' => '11', 'country_code' => 'FR', 'name' => 'Île-de-France', 'created_at' => now()]);
    DB::table('departments')->insert(['code' => '75', 'name' => 'Paris', 'region_code' => '11', 'created_at' => now()]);

    $reponse = $this->getJson('/api/v1/referentiels/geo')->assertOk();

    // Recopier 102 départements dans le frontend aurait garanti la divergence
    // avec la base — et c'est la base qui décide ce qu'un filtre peut trouver.
    expect($reponse->json('regions'))->toContain(['code' => '11', 'name' => 'Île-de-France']);
    expect($reponse->json('departments'))->toContain(['code' => '75', 'name' => 'Paris']);
});

test('le filtre par tag reste utilisable — segment de campagne', function () {
    $paris = ficheDatee($this->workspace->id, 'TAGGEE', '2026-06-01 10:00:00');
    ficheDatee($this->workspace->id, 'SANS TAG', '2026-06-01 10:00:00');

    // La colonne s'appelle `name`, pas `label` — et `rules`, `category`,
    // `kind` sont NOT NULL. Le schéma réel décide, pas l'habitude.
    $tagId = DB::table('tags')->insertGetId([
        'workspace_id' => $this->workspace->id, 'slug' => 'campagne-test',
        // Vocabulaires FERMÉS par CHECK : `category` et `kind` n'acceptent que
        // les valeurs du référentiel — inventer « campagne » ou « manuel »
        // fait échouer l'insertion.
        'name' => 'Campagne test', 'rules' => '[]', 'category' => 'custom',
        'kind' => 'manual', 'is_locked' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert([
        // `company_tag` n'a pas d'horodatage : sa clé primaire est
        // (company_id, tag_id), l'association existe ou n'existe pas.
        // `assigned_by` a lui aussi un vocabulaire fermé : auto-rule, llm, user,
        // backfill-src. « manual » n'en fait pas partie.
        'company_id' => $paris->id, 'tag_id' => $tagId, 'assigned_by' => 'user',
    ]);

    expect(denominations($this->getJson('/api/v1/companies?filter[tag]=campagne-test')))->toBe(['TAGGEE']);
});
