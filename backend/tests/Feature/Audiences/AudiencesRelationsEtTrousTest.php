<?php

/**
 * AUDIENCES — cibler / exclure par relation, étape, pays, taille non
 * renseignée et joignabilité (chantiers B, C, D) ; le défaut « hors
 * relations établies » des audiences de prospection.
 *
 * Chaque critère est joué par le chemin SQL (`refresh`) ET par le chemin en
 * mémoire (`evaluateForCompany`, waterfall step12) : les deux doivent
 * retenir exactement les mêmes fiches, sous `all`, `any` et `not`.
 */

use App\Crm\Relations\RelationsProspection;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Database\Seeders\DefaultAudiencesSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-aud-relations', 'name' => 'WS', 'settings' => [],
    ]);
    $this->service = app(AudienceBuilderService::class);
    $jeu = [
        // repère          relation        étape          pays  taille  nature        joignabilité
        'prospect' => ['prospect', 'nouveau', 'FR', 'pme', 'entreprise', 'email_valide'],
        'client' => ['client', 'client', 'FR', 'pme', 'entreprise', 'email_valide'],
        'partenaire' => ['partenaire', 'qualifie', 'FR', null, 'association', 'email_invalide'],
        'presse' => ['presse_media', 'opportunite', 'RO', null, 'media', null],
        'newsletter' => ['newsletter', 'qualifie', 'FR', null, 'entreprise', 'sans_contact'],
    ];
    $this->ids = [];
    foreach ($jeu as $repere => [$relation, $etape, $pays, $taille, $nature, $joignabilite]) {
        $fiche = Company::create([
            'workspace_id' => $this->workspace->id,
            'siren' => $pays === 'FR' ? (string) random_int(941800000, 941899999) : null,
            'foreign_id' => $pays === 'FR' ? null : 'zz-' . $repere,
            'country_code' => $pays,
            'denomination' => 'ZZ ' . $repere,
            'size_category' => $taille,
            'entity_nature' => $nature,
            'prospection_status' => 'ready_for_outreach',
            'signals' => [],
            'metadata' => [],
        ]);
        $fiche->forceFill(['relation_type' => $relation, 'lifecycle_stage' => $etape, 'joignabilite' => $joignabilite])->save();
        $this->ids[$repere] = (int) $fiche->id;
    }
});

/** @return list<string> */
function arSql(AudienceBuilderService $service, string $ws, array $criteres, array $ids): array
{
    $retenus = array_map('intval', $service->buildPublicQuery($ws, $criteres)->pluck('id')->all());

    return arReperes($retenus, $ids);
}

/** @return list<string> */
function arMemoire(AudienceBuilderService $service, string $ws, array $criteres, array $ids): array
{
    $audience = EmailAudience::create([
        'workspace_id' => $ws, 'name' => 'sonde-' . Str::random(6), 'criteria' => $criteres, 'is_active' => true, 'auto_refresh' => true,
    ]);
    $retenus = [];
    foreach ($ids as $id) {
        if (in_array($audience->id, $service->evaluateForCompany(Company::findOrFail($id)), true)) {
            $retenus[] = $id;
        }
    }
    $audience->forceDelete();

    return arReperes($retenus, $ids);
}

/**
 * @param  list<int>  $retenus
 * @param  array<string, int>  $ids
 * @return list<string>
 */
function arReperes(array $retenus, array $ids): array
{
    $reperes = array_keys(array_filter($ids, static fn (int $id): bool => in_array($id, $retenus, true)));
    sort($reperes);

    return $reperes;
}

test('relation, etape, pays, taille non renseignee, joignabilite : SQL et memoire retiennent les memes fiches', function (array $criteres, array $attendus) {
    sort($attendus);
    expect(arSql($this->service, $this->workspace->id, $criteres, $this->ids))->toBe($attendus)
        ->and(arMemoire($this->service, $this->workspace->id, $criteres, $this->ids))->toBe($attendus);
})->with([
    'exclure les relations etablies (not)' => [
        ['not' => [RelationsProspection::conditionExclusion()]],
        ['prospect', 'newsletter'],
    ],
    'seulement les clients (all)' => [
        ['all' => [['field' => 'relation_type', 'op' => 'eq', 'value' => 'client']]],
        ['client'],
    ],
    'partenaire OU presse (any)' => [
        ['any' => [['field' => 'relation_type', 'op' => 'eq', 'value' => 'partenaire'], ['field' => 'relation_type', 'op' => 'eq', 'value' => 'presse_media']]],
        ['partenaire', 'presse'],
    ],
    'etape qualifie ou plus, sauf clients' => [
        ['all' => [['field' => 'lifecycle_stage', 'op' => 'in', 'value' => ['qualifie', 'opportunite']]], 'not' => [['field' => 'relation_type', 'op' => 'eq', 'value' => 'client']]],
        ['partenaire', 'presse', 'newsletter'],
    ],
    'etrangeres' => [
        ['all' => [['field' => 'country_code', 'op' => 'neq', 'value' => 'FR']]],
        ['presse'],
    ],
    'taille non renseignee' => [
        ['all' => [['field' => 'size_category', 'op' => 'is_null', 'value' => null]]],
        ['partenaire', 'presse', 'newsletter'],
    ],
    'entreprises a effectif inconnu' => [
        ['all' => [['field' => 'size_category', 'op' => 'is_null', 'value' => null], ['field' => 'entity_nature', 'op' => 'eq', 'value' => 'entreprise']]],
        ['newsletter'],
    ],
    'joignables par e-mail valide' => [
        ['all' => [['field' => 'joignabilite', 'op' => 'eq', 'value' => 'email_valide']]],
        ['client', 'prospect'],
    ],
    'sauf e-mail invalide (non calculee gardee)' => [
        ['not' => [['field' => 'joignabilite', 'op' => 'in', 'value' => ['email_invalide', 'email_interdit']]]],
        ['client', 'prospect', 'presse', 'newsletter'],
    ],
]);

test('les audiences par defaut excluent les relations etablies — et gardent tous les prospects', function () {
    Company::whereKey($this->ids['client'])->update(['email_generic' => 'achat@zz-client.example']);
    Company::whereKey($this->ids['prospect'])->update(['email_generic' => 'achat@zz-prospect.example']);
    $this->seed(DefaultAudiencesSeeder::class);

    $audience = EmailAudience::where('workspace_id', $this->workspace->id)->where('name', 'Prospects contactables')->firstOrFail();
    $ids = $this->service->buildPublicQuery($this->workspace->id, $audience->criteria ?? [])->pluck('id')->map(fn ($i) => (int) $i)->all();

    expect($audience->criteria['not'] ?? null)->toEqual([RelationsProspection::conditionExclusion()])
        ->and($ids)->toBe([$this->ids['prospect']]);
});

test('la migration ajoute l exclusion aux audiences par defaut INTACTES, jamais a une audience reecrite', function () {
    $origine = ['all' => [
        ['field' => 'has_email', 'op' => 'eq', 'value' => true],
        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
    ]];
    $intacte = EmailAudience::create(['workspace_id' => $this->workspace->id, 'name' => 'Prospects contactables', 'criteria' => $origine]);
    $reecrite = EmailAudience::create([
        'workspace_id' => $this->workspace->id, 'name' => 'Confiance email A (domaine = site)',
        'criteria' => ['all' => [['field' => 'region_code', 'op' => 'eq', 'value' => '84']]],
    ]);
    $autre = EmailAudience::create(['workspace_id' => $this->workspace->id, 'name' => 'ZZ a moi', 'criteria' => $origine]);

    $migration = require database_path('migrations/2026_10_01_000021_audiences_par_defaut_hors_relations.php');
    $migration->up();
    $migration->up();

    expect($intacte->fresh()?->criteria)->toEqual($origine + ['not' => [RelationsProspection::conditionExclusion()]])
        ->and($reecrite->fresh()?->criteria)->toEqual(['all' => [['field' => 'region_code', 'op' => 'eq', 'value' => '84']]])
        ->and($autre->fresh()?->criteria)->toEqual($origine);

    $migration->down();
    expect($intacte->fresh()?->criteria)->toEqual($origine);
});

test('l API accepte les nouveaux champs et refuse un champ inconnu', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'op-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ op',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->workspace->id, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->workspace->id);
    $user->assignRole('operator');
    $this->actingAs($user);

    $criteres = ['all' => [['field' => 'joignabilite', 'op' => 'eq', 'value' => 'email_valide']], 'not' => [RelationsProspection::conditionExclusion()]];
    $r = $this->postJson('/api/v1/audiences', ['name' => 'ZZ prospection', 'criteria' => $criteres]);
    $r->assertCreated();
    expect(EmailAudience::where('name', 'ZZ prospection')->firstOrFail()->criteria)->toEqual($criteres);

    $apercu = $this->postJson('/api/v1/audiences/preview', ['criteria' => $criteres])->assertOk();
    expect($apercu->json('companies'))->toBe(1);

    $this->postJson('/api/v1/audiences', ['name' => 'ZZ faute', 'criteria' => ['all' => [['field' => 'relation', 'op' => 'eq', 'value' => 'client']]]])
        ->assertStatus(422);
});

test('R7 — la migration compare STRICTEMENT : un 11 entier ou un 1 a la place de true n est pas « le seeder »', function () {
    $presque = EmailAudience::create(['workspace_id' => $this->workspace->id, 'name' => 'Prospects contactables — Île-de-France', 'criteria' => ['all' => [
        ['field' => 'has_email', 'op' => 'eq', 'value' => true],
        ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'],
        ['field' => 'region_code', 'op' => 'eq', 'value' => 11],
    ]]]);
    $unAuLieuDeVrai = EmailAudience::create(['workspace_id' => $this->workspace->id, 'name' => 'Confiance email A (domaine = site)', 'criteria' => ['all' => [
        ['field' => 'has_email', 'op' => 'eq', 'value' => 1],
        ['field' => 'best_email_confidence', 'op' => 'eq', 'value' => 'A'],
    ]]]);

    (require database_path('migrations/2026_10_01_000021_audiences_par_defaut_hors_relations.php'))->up();

    expect($presque->fresh()?->criteria)->not->toHaveKey('not')
        ->and($unAuLieuDeVrai->fresh()?->criteria)->not->toHaveKey('not');
});
