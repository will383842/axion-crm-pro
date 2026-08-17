<?php

use App\Jobs\RefreshAudienceChunkJob;
use App\Models\AudienceMember;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailAudience;
use App\Models\Tag;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ws = Workspace::create(['id' => Str::uuid()->toString(), 'name' => 'T', 'slug' => 'ws-' . uniqid()]);
    $this->service = new AudienceBuilderService;
});

function mkCompany(string $wsId, array $attrs = []): Company
{
    return Company::create(array_merge([
        'workspace_id' => $wsId,
        'siren' => str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
    ], $attrs));
}

/**
 * Compte les fiches que l'évaluateur EN MÉMOIRE (`evaluateForCompany`, chemin
 * waterfall step12) retiendrait pour ces critères.
 *
 * L'intérêt n'est pas le nombre : c'est la COMPARAISON avec `preview()`, qui
 * évalue les MÊMES critères en SQL. `buildPositive()` porte en commentaire
 * l'engagement que les deux restent « STRICTEMENT alignées ». Deux réponses
 * différentes pour un même critère, sur le composant qui décide À QUI part un
 * e-mail, c'est une audience dont le contenu dépend du chemin qui l'a calculée.
 */
function countInMemory(AudienceBuilderService $service, string $wsId, array $criteria): int
{
    $audience = EmailAudience::create([
        'workspace_id' => $wsId,
        'name' => 'sonde-in-memory-' . uniqid(),
        'criteria' => $criteria,
        'is_active' => true,
        'auto_refresh' => true,
    ]);

    $n = 0;
    foreach (Company::where('workspace_id', $wsId)->get() as $company) {
        if (in_array($audience->id, $service->evaluateForCompany($company), true)) {
            $n++;
        }
    }

    $audience->delete();

    return $n;
}

/** Insertion en masse — `Company::create()` 5 001 fois prendrait des minutes. */
function seedCompanies(string $wsId, int $n, array $attrs = []): void
{
    $now = now();
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = array_merge([
            'workspace_id' => $wsId,
            // `companies_identity_anchor_check` : toute fiche porte un SIREN
            // ou un `foreign_id`. Une entreprise sans ancre d'identité n'existe
            // pas — la contrainte le dit, le seed doit s'y plier.
            'siren' => str_pad((string) (100000000 + $i), 9, '0', STR_PAD_LEFT),
            'created_at' => $now,
            'updated_at' => $now,
        ], $attrs);
        if (count($rows) === 1000) {
            DB::table('companies')->insert($rows);
            $rows = [];
        }
    }
    if (! empty($rows)) {
        DB::table('companies')->insert($rows);
    }
}

function mkTag(string $wsId, string $slug): Tag
{
    return Tag::create([
        'workspace_id' => $wsId,
        'slug' => $slug,
        'name' => $slug,
        'kind' => 'manual',
        'category' => 'custom',
    ]);
}

it('preview returns count for simple criteria', function () {
    mkCompany($this->ws->id, ['department_code' => '75', 'prospection_status' => 'ready_for_outreach']);
    mkCompany($this->ws->id, ['department_code' => '75', 'prospection_status' => 'pending']);
    mkCompany($this->ws->id, ['department_code' => '92', 'prospection_status' => 'ready_for_outreach']);

    $preview = $this->service->preview($this->ws->id, [
        'all' => [
            ['field' => 'department_code', 'op' => 'eq', 'value' => '75'],
            ['field' => 'prospection_status', 'op' => 'in', 'value' => ['ready_for_outreach']],
        ],
    ]);

    expect($preview['companies'])->toBe(1);
});

it('preview uses any combinator (OR)', function () {
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id, ['department_code' => '92']);
    mkCompany($this->ws->id, ['department_code' => '69']);

    $preview = $this->service->preview($this->ws->id, [
        'any' => [
            ['field' => 'department_code', 'op' => 'eq', 'value' => '75'],
            ['field' => 'department_code', 'op' => 'eq', 'value' => '92'],
        ],
    ]);

    expect($preview['companies'])->toBe(2);
});

it('filters by quality_score range', function () {
    mkCompany($this->ws->id, ['quality_score' => 30]);
    mkCompany($this->ws->id, ['quality_score' => 60]);
    mkCompany($this->ws->id, ['quality_score' => 90]);

    $preview = $this->service->preview($this->ws->id, [
        'all' => [
            ['field' => 'quality_score', 'op' => 'gte', 'value' => 50],
            ['field' => 'quality_score', 'op' => 'lt', 'value' => 80],
        ],
    ]);

    expect($preview['companies'])->toBe(1);
});

it('filters by has_email contacts', function () {
    $cWithEmail = mkCompany($this->ws->id);
    Contact::create([
        'workspace_id' => $this->ws->id, 'company_id' => $cWithEmail->id,
        'last_name' => 'Doe', 'email' => 'a@b.fr', 'email_status' => 'valid',
    ]);
    mkCompany($this->ws->id);  // pas d'email

    $preview = $this->service->preview($this->ws->id, [
        'all' => [['field' => 'has_email', 'op' => 'eq', 'value' => true]],
    ]);
    expect($preview['companies'])->toBe(1);
});

it('refresh populates audience_members', function () {
    $c = mkCompany($this->ws->id, ['department_code' => '75']);
    Contact::create([
        'workspace_id' => $this->ws->id, 'company_id' => $c->id,
        'last_name' => 'Doe', 'email' => 'a@b.fr', 'email_status' => 'valid',
    ]);

    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'Test',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
    ]);

    $this->service->refresh($audience);

    $audience->refresh();
    expect($audience->member_count)->toBe(1);
    expect($audience->refreshed_at)->not->toBeNull();
});

it('refresh is idempotent (run twice = same count)', function () {
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id, ['department_code' => '75']);

    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'Test',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
    ]);

    $this->service->refresh($audience);
    $this->service->refresh($audience);

    expect($audience->fresh()->member_count)->toBe(2);
});

it('evaluateForCompany returns matching audience IDs', function () {
    $c = mkCompany($this->ws->id, ['department_code' => '75', 'size_category' => 'pme']);

    $a1 = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'IDF',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
    ]);
    $a2 = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'Other',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '92']]],
    ]);

    $matches = $this->service->evaluateForCompany($c);
    expect($matches)->toContain($a1->id)->not->toContain($a2->id);
});

it('skips invalid fields/ops silently', function () {
    mkCompany($this->ws->id);
    $preview = $this->service->preview($this->ws->id, [
        'all' => [
            ['field' => 'DROP TABLE users', 'op' => 'eq', 'value' => 'x'],
            ['field' => 'department_code', 'op' => 'INVALID', 'value' => '75'],
        ],
    ]);
    expect($preview['companies'])->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
// Combinateur `not` — signalé non testé le 2026-08-16, toujours non testé le
// 2026-08-17. C'est le combinateur d'EXCLUSION : il décide qui NE reçoit PAS.
// ═══════════════════════════════════════════════════════════════════════════

it('not exclut les fiches qui matchent la condition', function () {
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id, ['department_code' => '92']);

    $preview = $this->service->preview($this->ws->id, [
        'not' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']],
    ]);

    expect($preview['companies'])->toBe(1);
});

it('not sur un champ NULL garde la fiche : inconnu n est pas egal', function () {
    // Une fiche collectée dont le département n'est pas renseigné n'est PAS
    // « dans le 75 ». Une audience « tout sauf Paris » doit donc la garder.
    mkCompany($this->ws->id, ['department_code' => '92']);
    mkCompany($this->ws->id);                       // department_code = NULL
    mkCompany($this->ws->id, ['department_code' => '75']);

    $preview = $this->service->preview($this->ws->id, [
        'not' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']],
    ]);

    // SQL : « NOT (department_code = '75') » vaut UNKNOWN quand la colonne est
    // NULL, et UNKNOWN élimine la ligne. La fiche disparaît en silence.
    expect($preview['companies'])->toBe(2);
});

it('not : SQL et evaluateur en memoire donnent le MEME nombre (champ NULL)', function () {
    mkCompany($this->ws->id, ['department_code' => '92']);
    mkCompany($this->ws->id);                       // department_code = NULL

    $criteria = ['not' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]];

    $sql = $this->service->preview($this->ws->id, $criteria)['companies'];
    $inMemory = countInMemory($this->service, $this->ws->id, $criteria);

    expect($sql)->toBe($inMemory);
});

it('not + in sur un champ NULL garde la fiche, comme en memoire', function () {
    // Même piège que `eq`, avec l'opérateur de liste : une fiche sans région
    // n'est dans AUCUNE des régions listées, elle doit donc survivre à un
    // « not in ». C'est le classique `NOT IN` + NULL de SQL.
    mkCompany($this->ws->id, ['region_code' => '11']);
    mkCompany($this->ws->id, ['region_code' => '84']);
    mkCompany($this->ws->id);                       // region_code = NULL

    $criteria = ['not' => [['field' => 'region_code', 'op' => 'in', 'value' => ['11']]]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])
        ->toBe(2)
        ->toBe(countInMemory($this->service, $this->ws->id, $criteria));
});

it('not + neq sur un champ NULL exclut la fiche, des deux cotes', function () {
    // Contre-épreuve du cas précédent : ici les deux évaluateurs excluent
    // DÉJÀ la fiche NULL. Ce test garde cette symétrie-là intacte.
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id);                       // department_code = NULL

    $criteria = ['not' => [['field' => 'department_code', 'op' => 'neq', 'value' => '75']]];

    $sql = $this->service->preview($this->ws->id, $criteria)['companies'];
    expect($sql)->toBe(1)                            // seule la fiche « 75 » reste
        ->and($sql)->toBe(countInMemory($this->service, $this->ws->id, $criteria));
});

it('not : is_not_null garde les fiches dont le champ est NULL', function () {
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id);                       // NULL

    $criteria = ['not' => [['field' => 'department_code', 'op' => 'is_not_null']]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])
        ->toBe(1)
        ->toBe(countInMemory($this->service, $this->ws->id, $criteria));
});

it('neq garde les fiches dont le champ est NULL, comme en memoire', function () {
    // « tout ce qui n'est pas le 75 » : une fiche collectee sans departement
    // n'EST PAS dans le 75. En SQL, `departement != '75'` vaut UNKNOWN sur NULL
    // et la ligne disparait ; `evalCondition()` la garde. L'audience dependait
    // donc du chemin qui l'avait calculee.
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id, ['department_code' => '92']);
    mkCompany($this->ws->id);                       // department_code = NULL

    $criteria = ['all' => [['field' => 'department_code', 'op' => 'neq', 'value' => '75']]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])
        ->toBe(2)
        ->toBe(countInMemory($this->service, $this->ws->id, $criteria));
});

it('not_in garde les fiches dont le champ est NULL, comme en memoire', function () {
    // Le classique `NOT IN` + NULL : sans garde, la liste d'exclusion emporte
    // aussi tout ce qu'elle ne connait pas.
    mkCompany($this->ws->id, ['region_code' => '11']);
    mkCompany($this->ws->id, ['region_code' => '84']);
    mkCompany($this->ws->id);                       // region_code = NULL

    $criteria = ['all' => [['field' => 'region_code', 'op' => 'not_in', 'value' => ['11']]]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])
        ->toBe(2)
        ->toBe(countInMemory($this->service, $this->ws->id, $criteria));
});

it('neq sur un champ renseigne ne change pas de comportement', function () {
    // Contre-epreuve : l'elargissement ne concerne QUE les champs vides.
    mkCompany($this->ws->id, ['department_code' => '75']);
    mkCompany($this->ws->id, ['department_code' => '92']);

    $criteria = ['all' => [['field' => 'department_code', 'op' => 'neq', 'value' => '75']]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
// tags / contains_any — le SEUL opérateur admis sur le champ `tags`, et le
// seul qui passe par le pivot (whereHas) au lieu d'une colonne.
// ═══════════════════════════════════════════════════════════════════════════

it('tags contains_any ne retient que les fiches portant un des slugs', function () {
    $a = mkTag($this->ws->id, 'zz-cible');
    $b = mkTag($this->ws->id, 'zz-autre');

    $porteA = mkCompany($this->ws->id);
    $porteA->tags()->attach($a->id);
    $porteB = mkCompany($this->ws->id);
    $porteB->tags()->attach($b->id);
    mkCompany($this->ws->id);             // aucun tag

    $preview = $this->service->preview($this->ws->id, [
        'all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible']]],
    ]);

    expect($preview['companies'])->toBe(1);
});

it('tags contains_any accepte plusieurs slugs (union, pas intersection)', function () {
    $a = mkTag($this->ws->id, 'zz-a');
    $b = mkTag($this->ws->id, 'zz-b');

    $x = mkCompany($this->ws->id);
    $x->tags()->attach($a->id);
    $y = mkCompany($this->ws->id);
    $y->tags()->attach($b->id);
    mkCompany($this->ws->id);

    $preview = $this->service->preview($this->ws->id, [
        'all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-a', 'zz-b']]],
    ]);

    expect($preview['companies'])->toBe(2);
});

it('not + tags contains_any garde les fiches SANS le tag', function () {
    $a = mkTag($this->ws->id, 'zz-ne-pas-contacter');

    $exclue = mkCompany($this->ws->id);
    $exclue->tags()->attach($a->id);
    mkCompany($this->ws->id);
    mkCompany($this->ws->id);

    $criteria = ['not' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-ne-pas-contacter']]]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])
        ->toBe(2)
        ->toBe(countInMemory($this->service, $this->ws->id, $criteria));
});

it('tags : SQL et evaluateur en memoire donnent le MEME nombre', function () {
    $a = mkTag($this->ws->id, 'zz-cible');
    $x = mkCompany($this->ws->id);
    $x->tags()->attach($a->id);
    mkCompany($this->ws->id);

    $criteria = ['all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => ['zz-cible']]]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])
        ->toBe(countInMemory($this->service, $this->ws->id, $criteria));
});

it('tags contains_any avec une liste VIDE ne vise personne', function () {
    // Une audience « porte un de ces tags » dont la liste a été vidée ne
    // désigne personne. Si la condition est simplement IGNORÉE, la requête
    // rend TOUT le workspace : l'envoi part à tout le monde.
    mkCompany($this->ws->id);
    mkCompany($this->ws->id);
    mkCompany($this->ws->id);

    $preview = $this->service->preview($this->ws->id, [
        'all' => [['field' => 'tags', 'op' => 'contains_any', 'value' => []]],
    ]);

    expect($preview['companies'])->toBe(0);
});

it('tags avec un operateur non supporte ne vise personne', function () {
    // `eq` n'est pas un opérateur valide sur le pivot `tags` : l'évaluateur en
    // mémoire répond « aucune correspondance ». Le SQL doit dire pareil.
    $a = mkTag($this->ws->id, 'zz-cible');
    $x = mkCompany($this->ws->id);
    $x->tags()->attach($a->id);
    mkCompany($this->ws->id);

    $criteria = ['all' => [['field' => 'tags', 'op' => 'eq', 'value' => 'zz-cible']]];

    expect($this->service->preview($this->ws->id, $criteria)['companies'])
        ->toBe(0)
        ->toBe(countInMemory($this->service, $this->ws->id, $criteria));
});

// ═══════════════════════════════════════════════════════════════════════════
// Chemin Bus::batch — au-delà de 5 000 fiches, refresh() ne recalcule plus en
// ligne : il dispatche des jobs. Ce basculement n'était couvert par aucun
// test, alors qu'il décide du contenu réel des GROSSES audiences.
// ═══════════════════════════════════════════════════════════════════════════

it('refresh reste en ligne a exactement 5 000 fiches (le seuil est strict)', function () {
    Bus::fake();
    seedCompanies($this->ws->id, 5000, ['department_code' => '75', 'email_generic' => 'zz@example.invalid']);

    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'ZZ seuil',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
    ]);

    $this->service->refresh($audience);

    Bus::assertNothingBatched();
    expect($audience->fresh()->member_count)->toBe(5000)
        ->and($audience->fresh()->refreshed_at)->not->toBeNull();
})->group('slow');

it('refresh bascule en Bus::batch au-dela de 5 000 fiches', function () {
    Bus::fake();
    seedCompanies($this->ws->id, 5001, ['department_code' => '75', 'email_generic' => 'zz@example.invalid']);

    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'ZZ batch',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
    ]);

    $this->service->refresh($audience);

    Bus::assertBatched(function (PendingBatch $batch) use ($audience) {
        expect($batch->name)->toBe("audience-refresh-{$audience->id}")
            ->and($batch->options['queue'] ?? null)->toBe('audiences-refresh')
            ->and($batch->options['allowFailures'] ?? false)->toBeTrue()
            // ceil(5001 / 5000) = 2 lots — aucune fiche laissée derrière.
            ->and($batch->jobs)->toHaveCount(2)
            ->and($batch->jobs->first())->toBeInstanceOf(RefreshAudienceChunkJob::class);

        return true;
    });
})->group('slow');

it('le chemin batch remet l audience a zero AVANT de dispatcher', function () {
    Bus::fake();
    seedCompanies($this->ws->id, 5001, ['department_code' => '75', 'email_generic' => 'zz@example.invalid']);

    $audience = EmailAudience::create([
        'workspace_id' => $this->ws->id,
        'name' => 'ZZ batch reset',
        'criteria' => ['all' => [['field' => 'department_code', 'op' => 'eq', 'value' => '75']]],
        'member_count' => 999,
        'refreshed_at' => now()->subDay(),
    ]);
    // Un membre périmé, qui ne doit pas survivre au recalcul.
    AudienceMember::create([
        'audience_id' => $audience->id,
        'company_id' => Company::where('workspace_id', $this->ws->id)->value('id'),
        'workspace_id' => $this->ws->id,
        'added_at' => now(),
    ]);

    $this->service->refresh($audience);

    $audience->refresh();
    // Les jobs sont faux : le comptage final est leur travail. Ce qui doit
    // être vrai ICI, c'est que l'état d'avant a été effacé — sinon une
    // audience en cours de recalcul afficherait un effectif périmé.
    expect($audience->member_count)->toBe(0)
        ->and($audience->refreshed_at)->toBeNull()
        ->and(AudienceMember::where('audience_id', $audience->id)->count())->toBe(0);
})->group('slow');
