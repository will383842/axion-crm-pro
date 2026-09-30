<?php

/**
 * VISER ET EXCLURE PAR RELATION ET PAR ÉTIQUETTES PRESSE (2026-09-30).
 *
 * Harmonisation des contacts : une campagne de PROSPECTION doit pouvoir
 * EXCLURE la presse et les clients (`not` → `relation_type in [presse_media,
 * client]`) ; une campagne peut viser une relation, une nature, une taille, et
 * un type ou une zone de média par ses étiquettes (`media-type:`,
 * `media-zone:`).
 *
 * Mêmes exigences que `SymetrieEvaluateursTest` : le chemin SQL (`refresh()`)
 * et le chemin EN MÉMOIRE (waterfall, `evaluateForCompany()`) rendent le MÊME
 * ensemble, sous `all` comme sous `not`. Et une fiche PROTÉGÉE (la presse
 * harmonisée) n'entre dans AUCUNE audience générale, même visée par sa
 * relation — elle part par son segment, fermé tant que Will ne l'ouvre pas.
 *
 * Fixtures FICTIVES.
 */

use App\Crm\FichesProtegees;
use App\Http\Requests\StoreEmailAudienceRequest;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-relation-presse',
        'name' => 'WS relation presse',
        'settings' => [],
    ]);
    $this->service = app(AudienceBuilderService::class);

    $fiches = [
        // repère          relation        nature        taille  étiquettes
        'prospect-pme' => ['prospect', 'entreprise', 'PME', []],
        'prospect-tpe' => ['prospect', 'entreprise', 'TPE', []],
        'client' => ['client', 'entreprise', 'PME', []],
        'partenaire' => ['partenaire', 'association', null, []],
        'studio' => ['prospect', 'entreprise', 'TPE', ['media-type:production', 'media-zone:inconnue']],
        'radio-libre' => ['presse_media', 'media', null, ['media-type:radio', 'media-zone:regional']],
        'presse-protegee' => ['presse_media', 'media', null, ['media-type:presse-quotidienne', 'media-zone:national', FichesProtegees::TAG_PRESSE]],
    ];
    $this->ids = [];
    foreach ($fiches as $repere => [$relation, $nature, $taille, $slugs]) {
        $id = (int) DB::table('companies')->insertGetId([
            'workspace_id' => $this->workspace->id,
            'siren' => (string) random_int(900000000, 999999999),
            'denomination' => 'ZZ ' . $repere,
            'relation_type' => $relation,
            'entity_nature' => $nature,
            'size_category' => $taille,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ids[$repere] = $id;
        foreach ($slugs as $slug) {
            $tagId = DB::table('tags')->where('workspace_id', $this->workspace->id)->where('slug', $slug)->value('id')
                ?? DB::table('tags')->insertGetId([
                    'workspace_id' => $this->workspace->id, 'slug' => $slug, 'name' => $slug,
                    'category' => str_starts_with($slug, 'media-zone') ? 'geo' : (str_starts_with($slug, 'src:') ? 'intent' : 'custom'),
                    'kind' => 'auto', 'rules' => '{}', 'is_locked' => str_starts_with($slug, 'src:'),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tagId, 'workspace_id' => $this->workspace->id,
                'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
        }
    }
});

/**
 * @param  array<string, mixed>  $criteria
 * @return list<string> repères retenus par le chemin SQL
 */
function paSql(AudienceBuilderService $service, string $ws, array $criteria, array $ids): array
{
    $retenus = array_map('intval', $service->buildPublicQuery($ws, $criteria)->pluck('id')->all());
    $reperes = array_keys(array_filter($ids, static fn (int $id): bool => in_array($id, $retenus, true)));
    sort($reperes);

    return $reperes;
}

/**
 * @param  array<string, mixed>  $criteria
 * @param  array<string, int>  $ids
 * @return list<string> repères retenus par le chemin en mémoire (hors fiches protégées, gardées par le waterfall)
 */
function paMemoire(AudienceBuilderService $service, string $ws, array $criteria, array $ids): array
{
    $audience = EmailAudience::create([
        'workspace_id' => $ws, 'name' => 'sonde-' . Str::random(8), 'criteria' => $criteria,
        'is_active' => true, 'auto_refresh' => true,
    ]);
    $retenus = [];
    foreach ($ids as $repere => $id) {
        if (FichesProtegees::estProtegee($id)) {
            continue;
        }
        if (in_array($audience->id, $service->evaluateForCompany(Company::findOrFail($id)), true)) {
            $retenus[] = $repere;
        }
    }
    $audience->forceDelete();
    sort($retenus);

    return $retenus;
}

test('relation_type est un champ d audience : la requete de creation le garde, avec sa valeur', function () {
    $charge = ['name' => 'Prospection', 'criteria' => [
        'all' => [['field' => 'size_category', 'op' => 'in', 'value' => ['PME']]],
        'not' => [['field' => 'relation_type', 'op' => 'in', 'value' => ['presse_media', 'client']]],
    ]];
    $v = Validator::make($charge, (new StoreEmailAudienceRequest)->rules());

    expect($v->fails())->toBeFalse()
        ->and($v->validated()['criteria']['not'][0])->toBe(['field' => 'relation_type', 'op' => 'in', 'value' => ['presse_media', 'client']])
        ->and(AudienceBuilderService::WHITELIST_FIELDS)->toContain('relation_type', 'entity_nature', 'size_category', 'tags');
});

test('prospection : exclure la presse et les clients — SQL et memoire rendent le meme ensemble', function () {
    $criteria = [
        'all' => [['field' => 'entity_nature', 'op' => 'in', 'value' => ['entreprise', 'association', 'media']]],
        'not' => [['field' => 'relation_type', 'op' => 'in', 'value' => ['presse_media', 'client']]],
    ];

    $sql = paSql($this->service, $this->workspace->id, $criteria, $this->ids);
    expect($sql)->toBe(['partenaire', 'prospect-pme', 'prospect-tpe', 'studio'])
        ->and(paMemoire($this->service, $this->workspace->id, $criteria, $this->ids))->toBe($sql);
});

test('viser une relation, une taille, un type et une zone de media ; exclure un type — symetrique', function () {
    $cas = [
        [['all' => [['field' => 'relation_type', 'op' => 'eq', 'value' => 'client']]], ['client']],
        [['all' => [['field' => 'relation_type', 'op' => 'in', 'value' => ['presse_media']]]], ['radio-libre']],
        [['all' => [['field' => 'size_category', 'op' => 'in', 'value' => ['TPE']], ['field' => 'relation_type', 'op' => 'eq', 'value' => 'prospect']]], ['prospect-tpe', 'studio']],
        [['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['media-type:radio', 'media-type:production']]]], ['radio-libre', 'studio']],
        [['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['media-zone:regional']]]], ['radio-libre']],
        [['all' => [['field' => 'relation_type', 'op' => 'eq', 'value' => 'prospect']], 'not' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['media-type:production']]]], ['prospect-pme', 'prospect-tpe']],
        [['any' => [['field' => 'relation_type', 'op' => 'eq', 'value' => 'partenaire'], ['field' => 'entity_nature', 'op' => 'eq', 'value' => 'media']]], ['partenaire', 'radio-libre']],
    ];

    foreach ($cas as $i => [$criteria, $attendus]) {
        $sql = paSql($this->service, $this->workspace->id, $criteria, $this->ids);
        expect($sql)->toBe($attendus, "cas {$i} (SQL)")
            ->and(paMemoire($this->service, $this->workspace->id, $criteria, $this->ids))->toBe($sql, "cas {$i} (mémoire)");
    }
});

test('une fiche de presse PROTEGEE n entre dans aucune audience generale, meme visee par sa relation ; le temoin non protege oui', function () {
    $criteria = ['all' => [['field' => 'relation_type', 'op' => 'eq', 'value' => 'presse_media']]];

    expect(paSql($this->service, $this->workspace->id, $criteria, $this->ids))->toBe(['radio-libre'])
        ->and(paSql($this->service, $this->workspace->id, ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => [FichesProtegees::TAG_PRESSE]]]], $this->ids))->toBe([]);
});
