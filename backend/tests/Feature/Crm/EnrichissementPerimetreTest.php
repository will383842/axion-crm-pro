<?php

/**
 * Périmètre et ordre des lots de la recherche de site (PR 3a), garde disque.
 * Lecture seule : aucune écriture dans les fiches.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 943xxxxxx, noms « ZZ ».
 */

use App\Crm\FichesProtegees;
use App\Crm\Sites\GardeDisque;
use App\Crm\Sites\PerimetreEnrichissement as P;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-perim-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ périmètre']);
});

/** @param  array<string, mixed>  $attrs */
function epFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (943000000 + $seq),
        'denomination' => 'ZZ perimetre ' . $seq,
        'discovery_source' => 'insee',
        'effectif_range' => '11',
        'region_code' => '52',
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ], $attrs));
}

/** @return list<int> */
function epIds(string $espace, int $lot, int $limite = 100): array
{
    [$sql, $liaisons] = P::selectionSql($espace, $lot, $limite);

    return array_map(static fn ($l): int => (int) $l->id, DB::select($sql, $liaisons));
}

it('garde une fiche ordinaire dans le périmètre', function () {
    $id = epFiche($this->espace);

    expect(epIds($this->espace, 1))->toBe([$id]);
});

it('un téléphone seul n\'exclut pas', function () {
    $id = epFiche($this->espace, ['phone' => '0100000000']);

    expect(epIds($this->espace, 1))->toBe([$id]);
});

it('chaque exclusion retire la fiche', function (string $cas) {
    $attrs = match ($cas) {
        'enriched_at' => ['enriched_at' => now()],
        'website deviné' => ['website' => 'https://zz-exemple.invalid', 'website_method' => 'guess'],
        'email_generic' => ['email_generic' => 'contact@zz-exemple.invalid'],
        'non diffusible' => ['insee_non_diffusible_le' => '2026-01-01'],
        'fermée' => ['insee_ferme_le' => '2026-01-01'],
        'radiée' => ['archive_reason' => 'entreprise_radiee'],
        'supprimée' => ['deleted_at' => now()],
        'déjà marquée' => ['metadata' => json_encode(['recherche_site' => ['etape' => 'fini', 'resultat' => 'aucun', 'lot' => 1, 'le' => '2026-11-01', 'v' => 1]])],
        default => [],
    };
    $id = epFiche($this->espace, $attrs);
    $garde = epFiche($this->espace);

    if ($cas === 'e-mail d\'une personne') {
        DB::table('contacts')->insert(['workspace_id' => $this->espace, 'company_id' => $id, 'last_name' => 'ZZ', 'email' => 'zz@exemple.invalid']);
    }
    if ($cas === 'protégée') {
        $tag = DB::table('tags')->insertGetId([
            'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAGS[0], 'name' => 'zz', 'category' => 'intent', 'kind' => 'auto',
            'rules' => '[]', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $this->espace, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
    }

    expect(epIds($this->espace, 1))->toBe([$garde]);
})->with([
    'enriched_at', 'website deviné', 'email_generic', 'e-mail d\'une personne', 'non diffusible',
    'protégée', 'fermée', 'radiée', 'supprimée', 'déjà marquée',
]);

it('lot 1 trié par tranche exacte 53, 52, … 11', function () {
    $ids = [];
    foreach (['11', '21', '53', '31', '12', '52', '41', '22', '51', '32', '42'] as $t) {
        $ids[$t] = epFiche($this->espace, ['effectif_range' => $t]);
    }
    epFiche($this->espace, ['effectif_range' => '03']); // lot 2, pas ici

    $attendu = array_map(static fn (string $t): int => $ids[$t], ['53', '52', '51', '42', '41', '32', '31', '22', '21', '12', '11']);
    expect(epIds($this->espace, 1))->toBe($attendu);
});

it('dans une tranche : région 84, puis 11, puis le reste', function () {
    $reste = epFiche($this->espace, ['region_code' => '93']);
    $paris = epFiche($this->espace, ['region_code' => '11']);
    $aura = epFiche($this->espace, ['region_code' => '84']);
    $sans = epFiche($this->espace, ['region_code' => null]);

    expect(epIds($this->espace, 1))->toBe([$aura, $paris, $reste, $sans]);
});

it('les lots 1 à 7 sont dans l\'ordre et ne se recoupent pas', function () {
    $par = [];
    foreach ([1 => '11', 2 => '03', 3 => '02', 4 => '01', 5 => '00', 6 => 'NN', 7 => null] as $lot => $t) {
        $par[$lot] = epFiche($this->espace, ['effectif_range' => $t]);
    }

    expect(P::LOTS)->toBe([1, 2, 3, 4, 5, 6, 7]);
    foreach ($par as $lot => $id) {
        expect(epIds($this->espace, $lot))->toBe([$id]);
    }
});

it('une fiche d\'un autre espace n\'est jamais prise', function () {
    $autre = (string) Str::uuid();
    Workspace::create(['id' => $autre, 'slug' => 'zz-autre-' . Str::random(6), 'name' => 'ZZ autre']);
    epFiche($autre);

    expect(epIds($this->espace, 1))->toBe([]);
});

it('la commande compte par lot sans rien écrire', function () {
    config(['crm.ingest.business_workspace' => $this->slug]);
    epFiche($this->espace);
    epFiche($this->espace, ['effectif_range' => '03']);
    epFiche($this->espace, ['enriched_at' => now()]);
    $avant = DB::table('companies')->where('workspace_id', $this->espace)->orderBy('id')->get()->toJson();

    expect(Artisan::call('crm:enrichissement:perimetre'))->toBe(0);
    $sortie = Artisan::output();

    expect($sortie)->toMatch('/\|\s*1\s*\|\s*1\s*\|/')->toMatch('/\|\s*2\s*\|\s*1\s*\|/');
    expect(DB::table('companies')->where('workspace_id', $this->espace)->orderBy('id')->get()->toJson())->toBe($avant);
});

it('garde disque : 86 % = arrêt, 84 % = continue', function () {
    $disque = static fn (int $pourcent): GardeDisque => new GardeDisque('/', static fn (): float => 100.0 - $pourcent, static fn (): float => 100.0);

    expect($disque(86)->doitArreter())->toBeTrue()
        ->and($disque(84)->doitArreter())->toBeFalse()
        ->and($disque(85)->doitArreter())->toBeFalse();
});
