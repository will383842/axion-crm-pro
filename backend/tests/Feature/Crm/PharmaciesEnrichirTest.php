<?php

/**
 * `crm:pharmacies:enrichir` et `crm:pharmacies:rapport-enrichissement`.
 * Chaque garde face à un témoin, prouvée par son EFFET en base.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 95xxxxxxx, noms « ZZ ».
 */

use App\Crm\Pharmacies\Groupement;
use App\Crm\Pharmacies\SelectionEnrichissement;
use App\Jobs\EnrichCompanyJob;
use App\Jobs\EnrichirSiteOfficineJob;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-pharma-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ pharmacies']);
    Queue::fake();
});

/** @param  array<string, mixed>  $attrs */
function pePharmacie(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;
    if (isset($attrs['metadata']) && is_array($attrs['metadata'])) {
        $attrs['metadata'] = json_encode($attrs['metadata']);
    }

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (950000000 + $seq),
        'denomination' => 'ZZ officine ' . $seq,
        'naf' => '47.73Z',
        'legal_form' => '5499',
        'discovery_source' => 'insee',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function peLancer(object $test, array $options = []): string
{
    Artisan::call('crm:pharmacies:enrichir', array_merge(['--workspace' => $test->slug], $options));

    return Artisan::output();
}

it('par défaut (dry-run) ne met rien en file et n écrit rien', function () {
    $id = pePharmacie($this->espace, ['enseigne' => 'Giphar ZZ']);

    $sortie = peLancer($this);

    Queue::assertNothingPushed();
    expect($sortie)->toContain('ESSAI À BLANC')
        ->and(json_decode((string) DB::table('companies')->where('id', $id)->value('metadata'), true))->toBe([]);
});

it('apply met en file les ouvertes, grandes d abord, sans payant', function () {
    $petite = pePharmacie($this->espace, ['effectif_max' => 2]);
    $grande = pePharmacie($this->espace, ['effectif_max' => 49]);
    $moyenne = pePharmacie($this->espace, ['effectif_max' => 9]);

    peLancer($this, ['--apply' => true, '--pause' => 0]);

    $ordre = Queue::pushed(EnrichCompanyJob::class)->map(fn ($j) => $j->companyId)->values()->all();
    expect($ordre)->toBe([$grande, $moyenne, $petite]);
    Queue::pushed(EnrichCompanyJob::class)->each(fn ($j) => expect($j->sansPayant)->toBeTrue());
    $meta = json_decode((string) DB::table('companies')->where('id', $grande)->value('metadata'), true);
    expect($meta['enrichissement_pharmacie']['sources'])->not->toBeEmpty();
});

it('écarte fermées, supprimées, non-officines, écartées, mauvais NAF et complètes', function () {
    $ok = pePharmacie($this->espace);
    pePharmacie($this->espace, ['insee_ferme_le' => '2024-01-01']);
    pePharmacie($this->espace, ['deleted_at' => now()]);
    pePharmacie($this->espace, ['metadata' => ['type_etablissement' => 'non_officine']]);
    pePharmacie($this->espace, ['metadata' => ['ecartee_motif' => 'zz']]);
    pePharmacie($this->espace, ['naf' => '47.11F']);
    pePharmacie($this->espace, ['phone' => '0100000000', 'website' => 'https://zz.example', 'email_generic' => 'contact@zz.example', 'address' => '1 rue ZZ', 'lat' => 48.8, 'lon' => 2.3]);

    peLancer($this, ['--apply' => true, '--pause' => 0]);

    expect(Queue::pushed(EnrichCompanyJob::class)->map(fn ($j) => $j->companyId)->all())->toBe([$ok]);
});

it('est idempotente : un second passage ne remet rien en file', function () {
    pePharmacie($this->espace);

    peLancer($this, ['--apply' => true, '--pause' => 0]);
    Queue::assertPushed(EnrichCompanyJob::class, 1);

    peLancer($this, ['--apply' => true, '--pause' => 0]);
    Queue::assertPushed(EnrichCompanyJob::class, 1);
});

it('respecte --limite', function () {
    foreach (range(1, 4) as $_) {
        pePharmacie($this->espace);
    }

    peLancer($this, ['--apply' => true, '--limite' => 2, '--lot' => 1, '--pause' => 0]);

    Queue::assertPushed(EnrichCompanyJob::class, 2);
});

it('envoie une personne physique vers le job restreint, jamais vers le waterfall', function () {
    $id = pePharmacie($this->espace, ['metadata' => ['personne_physique' => true]]);

    peLancer($this, ['--apply' => true, '--pause' => 0]);

    Queue::assertNotPushed(EnrichCompanyJob::class);
    expect(Queue::pushed(EnrichirSiteOfficineJob::class)->map(fn ($j) => $j->companyId)->all())->toBe([$id]);
});

it('envoie une pharmacie de forme 1000 sans aucune metadata vers le job restreint', function () {
    $id = pePharmacie($this->espace, ['legal_form' => '1000']);
    $morale = pePharmacie($this->espace, ['legal_form' => ' 5499 ']);

    peLancer($this, ['--apply' => true, '--pause' => 0]);

    expect(Queue::pushed(EnrichirSiteOfficineJob::class)->map(fn ($j) => $j->companyId)->all())->toBe([$id])
        ->and(Queue::pushed(EnrichCompanyJob::class)->map(fn ($j) => $j->companyId)->all())->toBe([$morale]);
});

it('le critère personne physique tolère les espaces autour de la forme juridique', function () {
    expect(SelectionEnrichissement::estPersonnePhysique(null, ' 1000'))->toBeTrue()
        ->and(SelectionEnrichissement::estPersonnePhysique(null, '5499'))->toBeFalse()
        ->and(SelectionEnrichissement::estPersonnePhysique(null, null))->toBeTrue()
        ->and(SelectionEnrichissement::estPersonnePhysique(null, '  '))->toBeTrue()
        ->and(SelectionEnrichissement::estPersonnePhysique('{"personne_physique":true}', null))->toBeTrue();
});

it('marque le passage après la mise en file', function () {
    $id = pePharmacie($this->espace);

    peLancer($this, ['--apply' => true, '--pause' => 0]);

    Queue::assertPushed(EnrichCompanyJob::class, 1);
    $meta = json_decode((string) DB::table('companies')->where('id', $id)->value('metadata'), true);
    expect($meta)->toHaveKey('enrichissement_pharmacie');
});

it('Groupement : les mots courts exigent des limites de mots', function () {
    expect(Groupement::detecter('Pharmacie Giphar ZZ', null))->toBe('Giphar')
        ->and(Groupement::detecter(null, 'https://www.giphar-zz.example'))->toBe('Giphar')
        ->and(Groupement::detecter('Pharmacie Pharmavie', null))->toBe('Pharmavie')
        ->and(Groupement::detecter('Pharmacie Aprium', null))->toBe('Aprium')
        ->and(Groupement::detecter('Pharmacie Rue Gipharzz', null))->toBeNull()
        ->and(Groupement::detecter('Pharmacie Anapriumx', null))->toBeNull()
        ->and(Groupement::detecter('Pharmacie Lafayette', null))->toBe('Pharmacie Lafayette');
});

it('pose le groupement sans jamais écraser une valeur existante', function () {
    $vide = pePharmacie($this->espace, ['enseigne' => 'Pharmacie Lafayette ZZ']);
    $deja = pePharmacie($this->espace, ['enseigne' => 'Giphar ZZ', 'metadata' => ['groupement' => 'Saisi à la main']]);
    $site = pePharmacie($this->espace, ['website' => 'https://www.pluspharmacie-zz.example']);

    peLancer($this, ['--apply' => true, '--pause' => 0]);

    $groupement = fn (int $id) => json_decode((string) DB::table('companies')->where('id', $id)->value('metadata'), true)['groupement'] ?? null;
    expect($groupement($vide))->toBe('Pharmacie Lafayette')
        ->and($groupement($deja))->toBe('Saisi à la main')
        ->and($groupement($site))->toBe('Plus Pharmacie');
});

it('le rapport ne donne que des totaux, sans nom ni adresse', function () {
    pePharmacie($this->espace, ['denomination' => 'ZZ NOM UNIQUE', 'address' => '99 rue ZZ SECRETE', 'size_category' => 'tpe', 'department_code' => '75', 'phone' => '0100000000']);

    Artisan::call('crm:pharmacies:rapport-enrichissement', ['--workspace' => $this->slug]);
    $sortie = Artisan::output();

    expect($sortie)->toContain('Par taille')->toContain('Par département')->toContain('tpe')->toContain('75')
        ->not->toContain('ZZ NOM UNIQUE')->not->toContain('SECRETE')->not->toContain('0100000000');
});

it('une forme juridique vide ou NULL est traitée comme personne physique (job restreint)', function () {
    $nulle = pePharmacie($this->espace, ['legal_form' => null]);
    $vide = pePharmacie($this->espace, ['legal_form' => '  ']);
    $morale = pePharmacie($this->espace, ['legal_form' => '5499']);

    peLancer($this, ['--apply' => true, '--pause' => 0]);

    $restreints = Queue::pushed(EnrichirSiteOfficineJob::class)->map(fn ($j) => $j->companyId)->sort()->values()->all();
    expect($restreints)->toBe(collect([$nulle, $vide])->sort()->values()->all())
        ->and(Queue::pushed(EnrichCompanyJob::class)->map(fn ($j) => $j->companyId)->all())->toBe([$morale]);
});

it('pour une forme 1xxx (ou vide), seuls le site et le téléphone comptent comme manquants', function () {
    $base = ['phone' => '0100000001', 'website' => 'https://zz-site.example/'];
    $physique = pePharmacie($this->espace, $base + ['legal_form' => '1000']);
    $vide = pePharmacie($this->espace, $base + ['legal_form' => null]);
    $marquee = pePharmacie($this->espace, $base + ['metadata' => ['personne_physique' => true]]);
    $morale = pePharmacie($this->espace, $base + ['legal_form' => '5499']);
    $sansSite = pePharmacie($this->espace, ['phone' => '0100000002', 'legal_form' => '1000']);

    $ids = SelectionEnrichissement::aEnrichir($this->espace, 30)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();

    expect($ids)->toBe(collect([$morale, $sansSite])->sort()->values()->all())
        ->and($ids)->not->toContain($physique)->not->toContain($vide)->not->toContain($marquee);
});

it('Groupement : santepharma est cherché comme mot entier', function () {
    expect(Groupement::detecter('Santé Pharma', null))->toBe('Santé Pharma')
        ->and(Groupement::detecter(null, 'https://www.santepharma.fr/'))->toBe('Santé Pharma')
        ->and(Groupement::detecter('Centre Santé Pharmaceutique', null))->toBeNull()
        ->and(Groupement::detecter(null, 'https://unesantepharmacie.example/'))->toBeNull();
});
