<?php

/**
 * LE CRITÈRE « MEMBRE D'UNE LISTE MANUELLE » dans le constructeur d'audiences
 * (2026-09-30). Fixtures FICTIVES (dépôt public).
 *
 *  - « membres de la liste X » (`all`, `in`), « sauf liste Y » (`not_in`, ou
 *    bloc `not`), combinables avec les autres critères ;
 *  - une organisation est membre si elle est cochée OU si l'une de ses
 *    personnes l'est ; un membre retiré ne l'est plus ;
 *  - SQL et mémoire (waterfall step12) rendent le MÊME ensemble ;
 *  - une liste inconnue, d'un autre espace ou à la corbeille est REFUSÉE ;
 *  - les fiches protégées n'entrent que par une liste EXIGÉE, jamais par un
 *    critère général, ni par `any`.
 */

use App\Crm\FichesProtegees;
use App\Crm\Listes\ListesManuelles;
use App\Http\Requests\StoreEmailAudienceRequest;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Models\ListeManuelle;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Audiences\CritereAudienceInvalide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->ws = F::espace('zz-critere-liste');
    $this->service = app(AudienceBuilderService::class);

    $this->a = F::fiche($this->ws, 'ZZ A', ['sector_main' => 'industrie']);
    $this->b = F::fiche($this->ws, 'ZZ B', ['sector_main' => 'industrie']);
    $this->c = F::fiche($this->ws, 'ZZ C', ['sector_main' => 'commerce_detail']);
    $this->d = F::fiche($this->ws, 'ZZ D', ['sector_main' => 'industrie']);
    $this->personneDeC = F::contact($this->ws, $this->c, 'Zed', 'ZZC');

    $this->x = clmListe($this->ws, 'ZZ X');
    $this->y = clmListe($this->ws, 'ZZ Y');
    // X : A cochée, et la PERSONNE de C cochée (C est donc membre par elle).
    ListesManuelles::ajouter($this->x, [$this->a], [$this->personneDeC], null, 'coche');
    // Y : B cochée.
    ListesManuelles::ajouter($this->y, [$this->b], [], null, 'coche');
});

function clmListe(string $ws, string $nom): ListeManuelle
{
    return ListeManuelle::create(['workspace_id' => $ws, 'nom' => $nom . ' ' . Str::random(4)]);
}

/** @return list<int> */
function clmSql(object $t, array $criteres): array
{
    $ids = array_map('intval', $t->service->buildPublicQuery($t->ws, $criteres)->pluck('id')->all());
    sort($ids);

    return $ids;
}

/** @return list<int> le chemin EN MÉMOIRE réellement emprunté (evaluateForCompany) */
function clmMemoire(object $t, array $criteres, array $ids): array
{
    $audience = EmailAudience::create([
        'workspace_id' => $t->ws, 'name' => 'sonde-' . Str::random(6), 'criteria' => $criteres, 'is_active' => true, 'auto_refresh' => true,
    ]);
    $retenus = [];
    foreach ($ids as $id) {
        if (in_array($audience->id, $t->service->evaluateForCompany(Company::findOrFail($id)), true)) {
            $retenus[] = $id;
        }
    }
    $audience->forceDelete();
    sort($retenus);

    return $retenus;
}

test('« membres de la liste X » : la fiche cochée, et l organisation d une personne cochée', function () {
    $critere = ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id]]]];

    expect(clmSql($this, $critere))->toBe([$this->a, $this->c]);
});

test('« sauf liste Y », combiné à un critère ordinaire (ET)', function () {
    $critere = ['all' => [
        ['field' => 'sector_main', 'op' => 'eq', 'value' => 'industrie'],
        ['field' => 'liste_manuelle', 'op' => 'not_in', 'value' => [$this->y->id]],
    ]];

    expect(clmSql($this, $critere))->toBe([$this->a, $this->d]);
    // Même chose par le bloc `not`.
    expect(clmSql($this, [
        'all' => [['field' => 'sector_main', 'op' => 'eq', 'value' => 'industrie']],
        'not' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->y->id]]],
    ]))->toBe([$this->a, $this->d]);
});

test('plusieurs listes (OU) et le bloc any', function () {
    expect(clmSql($this, ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id, $this->y->id]]]]))
        ->toBe([$this->a, $this->b, $this->c])
        ->and(clmSql($this, ['any' => [
            ['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->y->id]],
            ['field' => 'sector_main', 'op' => 'eq', 'value' => 'commerce_detail'],
        ]]))->toBe([$this->b, $this->c]);
});

test('🔴 SQL et mémoire rendent le MÊME ensemble (in, not_in, not)', function () {
    $tous = [$this->a, $this->b, $this->c, $this->d];
    $cas = [
        ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id]]]],
        ['all' => [['field' => 'liste_manuelle', 'op' => 'not_in', 'value' => [$this->x->id]]]],
        ['not' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->y->id]]]],
        ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id, $this->y->id]]]],
    ];
    foreach ($cas as $critere) {
        expect(clmMemoire($this, $critere, $tous))->toBe(clmSql($this, $critere), json_encode($critere));
    }
    // TÉMOIN : le jeu discrimine (ni tout, ni rien).
    expect(clmSql($this, $cas[0]))->not->toBe($tous)->not->toBe([]);
});

test('un membre RETIRÉ n appartient plus à la liste ; une personne supprimée non plus', function () {
    ListesManuelles::retirer($this->x, [$this->a], [], null);
    DB::table('contacts')->where('id', $this->personneDeC)->update(['deleted_at' => now()]);

    expect(clmSql($this, ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id]]]]))->toBe([]);
});

test('une liste inconnue, d un autre espace ou à la corbeille est REFUSÉE, jamais ignorée', function () {
    $ailleurs = clmListe(F::espace('zz-critere-ailleurs'), 'ZZ Ailleurs');
    $corbeille = clmListe($this->ws, 'ZZ Corbeille');
    $corbeille->delete();

    foreach ([999999, $ailleurs->id, $corbeille->id] as $id) {
        expect(fn () => clmSql($this, ['not' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$id]]]]))
            ->toThrow(CritereAudienceInvalide::class, 'inconnue');
    }
});

test('une valeur mal formée est refusée : opérateur, liste vide, identifiant non entier', function () {
    foreach ([
        ['field' => 'liste_manuelle', 'op' => 'eq', 'value' => [1]],
        ['field' => 'liste_manuelle', 'op' => 'in', 'value' => []],
        ['field' => 'liste_manuelle', 'op' => 'in', 'value' => ['x']],
        ['field' => 'liste_manuelle', 'op' => 'in', 'value' => [0]],
        ['field' => 'liste_manuelle', 'op' => 'in', 'value' => range(1, 51)],
    ] as $cond) {
        expect(fn () => AudienceBuilderService::validerCriteres(['all' => [$cond]]))->toThrow(CritereAudienceInvalide::class);
    }
});

test('les FICHES PROTÉGÉES : jamais par un critère général, oui par une liste EXIGÉE, jamais par any', function () {
    F::proteger($this->ws, $this->a, FichesProtegees::TAG_GOFAB);

    // Critère général : A (protégée) sort.
    expect(clmSql($this, ['all' => [['field' => 'sector_main', 'op' => 'eq', 'value' => 'industrie']]]))->toBe([$this->b, $this->d]);
    // Liste EXIGÉE où A est cochée : A entre.
    expect(clmSql($this, ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id]]]]))->toBe([$this->a, $this->c]);
    // La même liste en `any` (OU) n'ouvre PAS la porte.
    expect(clmSql($this, ['any' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id]]]]))->toBe([$this->c]);
    // Une liste exigée n'ouvre la porte qu'à SES membres protégés.
    F::proteger($this->ws, $this->d, FichesProtegees::TAG_GOFAB);
    expect(clmSql($this, ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id, $this->y->id]]]]))
        ->toBe([$this->a, $this->b, $this->c]);
});

test('la valeur du critère survit à la validation de création (X39-024)', function () {
    $v = Validator::make([
        'name' => 'ZZ', 'criteria' => ['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$this->x->id]]]],
        'destinataires_mode' => 'generique', 'destinataires_fonctions' => ['Président', 'Délégué général'],
    ], (new StoreEmailAudienceRequest)->rules());

    expect($v->fails())->toBeFalse()
        ->and($v->validated()['criteria']['all'][0]['value'])->toBe([$this->x->id]);

    // Une fonction qui porterait une virgule ou une accolade est refusée.
    $critere = ['all' => [['field' => 'sector_main', 'op' => 'eq', 'value' => 'industrie']]];
    // TÉMOIN : la même charge, avec une fonction propre, passe.
    expect(Validator::make(['name' => 'ZZ', 'criteria' => $critere, 'destinataires_fonctions' => ['Gérant']], (new StoreEmailAudienceRequest)->rules())->fails())
        ->toBeFalse();
    expect(Validator::make(['name' => 'ZZ', 'criteria' => $critere, 'destinataires_fonctions' => ['a,b']], (new StoreEmailAudienceRequest)->rules())->fails())
        ->toBeTrue();
});
