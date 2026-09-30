<?php

/**
 * `crm:referentiels:combler-trous` — nature et région des fiches qui n'en
 * ont pas, SANS RIEN INVENTER (chantier C) ; et la nature des participants
 * GOFAB sous `crm:referentiels:reclasser --inclure-protegees`.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 942xxxxxx, noms « ZZ ».
 */

use App\Crm\FichesProtegees;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-trous-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ trous']);
});

/** @param  array<string, mixed>  $attrs */
function ctFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (942000000 + $seq),
        'denomination' => 'ZZ trou ' . $seq,
        'discovery_source' => 'insee',
        'entity_nature' => 'entreprise',
        'region_code' => '84',
        'department_code' => '38',
        'size_category' => 'pme',
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ], $attrs));
}

function ctProteger(string $espace, int $id, string $tag): void
{
    $tagId = DB::table('tags')->where('workspace_id', $espace)->where('slug', $tag)->value('id')
        ?? DB::table('tags')->insertGetId([
            'workspace_id' => $espace, 'slug' => $tag, 'name' => $tag, 'category' => 'intent', 'kind' => 'auto',
            'rules' => '[]', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tagId, 'workspace_id' => $espace, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);
}

function ctCommande(string $slug, array $options = []): array
{
    $code = Artisan::call('crm:referentiels:combler-trous', array_merge(['--workspace' => $slug], $options));

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function ctCompteur(string $sortie, string $compteur): ?int
{
    return preg_match('/\|\s*' . preg_quote($compteur, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m) === 1 ? (int) $m[1] : null;
}

function ctLire(int $id): object
{
    return DB::table('companies')->where('id', $id)->first(['entity_nature', 'region_code', 'department_code', 'size_category', 'updated_at']);
}

test('la nature se deduit sans rien inventer ; la region vient du departement ou du code postal', function () {
    $asso = ctFiche($this->espace, ['entity_nature' => null, 'legal_form' => '9220', 'discovery_source' => 'gofab-2026']);
    $federation = ctFiche($this->espace, ['entity_nature' => null, 'naf' => '94.12Z', 'discovery_source' => 'campaign']);
    $parSiren = ctFiche($this->espace, ['entity_nature' => null, 'discovery_source' => 'gofab-2026']);
    $publique = ctFiche($this->espace, ['entity_nature' => null, 'legal_form' => '7389', 'discovery_source' => 'campaign']);
    $sansRien = ctFiche($this->espace, ['entity_nature' => null, 'siren' => null, 'foreign_id' => 'zz-sans-rien', 'discovery_source' => 'campaign']);
    $parDept = ctFiche($this->espace, ['region_code' => null, 'department_code' => '69']);
    $parCp = ctFiche($this->espace, ['region_code' => null, 'department_code' => null, 'postcode' => '33000']);
    $sansGeo = ctFiche($this->espace, ['region_code' => null, 'department_code' => null, 'postcode' => null]);
    $etrangere = ctFiche($this->espace, ['region_code' => null, 'department_code' => '01', 'country_code' => 'RO', 'siren' => null, 'foreign_id' => 'zz-ro-1']);
    $tailleInconnue = ctFiche($this->espace, ['size_category' => null]);
    $organisation = ctFiche($this->espace, ['size_category' => null, 'entity_nature' => 'association']);
    $avant = ctLire($parDept)->updated_at;

    $r = ctCommande($this->slug);

    expect($r['code'])->toBe(0)
        ->and(ctLire($asso)->entity_nature)->toBe('association')
        ->and(ctLire($federation)->entity_nature)->toBe('federation')
        ->and(ctLire($parSiren)->entity_nature)->toBe('entreprise')
        ->and(ctLire($publique)->entity_nature)->toBeNull()
        ->and(ctLire($sansRien)->entity_nature)->toBeNull()
        ->and(ctLire($parDept)->region_code)->toBe('84')
        ->and(ctLire($parCp)->region_code)->toBe('75')
        // Le département lu dans le code postal n'est PAS écrit.
        ->and(ctLire($parCp)->department_code)->toBeNull()
        ->and(ctLire($sansGeo)->region_code)->toBeNull()
        // Une étrangère : son « 01 » roumain n'est pas l'Ain, rien n'est écrit.
        ->and(ctLire($etrangere)->region_code)->toBeNull()
        // Aucune taille devinée.
        ->and(ctLire($tailleInconnue)->size_category)->toBeNull()
        ->and(ctLire($organisation)->size_category)->toBeNull()
        // Classer n'est pas modifier.
        ->and(ctLire($parDept)->updated_at)->toBe($avant)
        ->and(ctCompteur($r['sortie'], 'natures_deduites_forme_juridique'))->toBe(1)
        ->and(ctCompteur($r['sortie'], 'natures_deduites_naf'))->toBe(1)
        ->and(ctCompteur($r['sortie'], 'natures_deduites_siren'))->toBe(1)
        ->and(ctCompteur($r['sortie'], 'natures_laissees_vides'))->toBe(2)
        ->and(ctCompteur($r['sortie'], 'regions_par_departement'))->toBe(1)
        ->and(ctCompteur($r['sortie'], 'regions_par_code_postal'))->toBe(1)
        ->and(ctCompteur($r['sortie'], 'regions_fr_sans_donnee_geographique'))->toBe(1)
        ->and(ctCompteur($r['sortie'], 'etrangeres_sans_region'))->toBe(1)
        ->and(ctCompteur($r['sortie'], 'entreprises_sans_taille'))->toBe(1)
        ->and(ctCompteur($r['sortie'], 'organisations_sans_taille'))->toBe(1)
        ->and(DB::table('audit_logs')->where('event_type', 'COMBLER_TROUS_LOT')->count())->toBeGreaterThanOrEqual(1);
});

test('l essai a blanc n ecrit RIEN et annonce ce que l execution realise', function () {
    ctFiche($this->espace, ['entity_nature' => null, 'legal_form' => '5710']);
    ctFiche($this->espace, ['region_code' => null, 'department_code' => '13']);
    $photo = static fn (): array => DB::table('companies')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();
    $avant = $photo();
    $audits = DB::table('audit_logs')->count();

    $blanc = ctCommande($this->slug, ['--dry-run' => true]);
    expect($photo())->toBe($avant)
        ->and(DB::table('audit_logs')->count())->toBe($audits);

    $reel = ctCommande($this->slug);
    $relance = ctCommande($this->slug);

    expect(ctCompteur($blanc['sortie'], 'fiches_a_modifier'))->toBe(2)
        ->and(ctCompteur($reel['sortie'], 'fiches_modifiees'))->toBe(2)
        ->and(ctCompteur($relance['sortie'], 'fiches_a_modifier'))->toBe(0);
});

test('protegees : exclues par defaut ; avec l option, un GOFAB recoit sa nature, jamais un organisateur ni une federation', function () {
    $gofab = ctFiche($this->espace, ['entity_nature' => null, 'discovery_source' => 'gofab-2026']);
    ctProteger($this->espace, $gofab, FichesProtegees::TAG_GOFAB);
    $organisateur = ctFiche($this->espace, ['entity_nature' => null, 'discovery_source' => 'evenements-pro', 'region_code' => null]);
    ctProteger($this->espace, $organisateur, FichesProtegees::TAG_ORGANISATEURS);
    $federation = ctFiche($this->espace, ['entity_nature' => null, 'legal_form' => '9220']);
    ctProteger($this->espace, $federation, FichesProtegees::TAG_FEDERATIONS);

    ctCommande($this->slug);
    expect(ctLire($gofab)->entity_nature)->toBeNull()
        ->and(ctLire($organisateur)->region_code)->toBeNull();

    $r = ctCommande($this->slug, ['--inclure-protegees' => true]);
    expect(ctLire($gofab)->entity_nature)->toBe('entreprise')
        ->and(ctLire($organisateur)->entity_nature)->toBeNull()
        // La RÉGION d'un organisateur, elle, est du classement : elle est posée.
        ->and(ctLire($organisateur)->region_code)->toBe('84')
        ->and(ctLire($federation)->entity_nature)->toBeNull()
        ->and(ctCompteur($r['sortie'], 'natures_protegees_non_deduites'))->toBe(2)
        // Rien n'est supprimé : les liens de protection sont intacts.
        ->and(DB::table('company_tag')->count())->toBe(3);
});

test('une fiche modifiee entre la lecture et l ecriture n est pas ecrasee', function () {
    $id = ctFiche($this->espace, ['region_code' => null, 'department_code' => '38']);
    // On simule la course : la fiche change de département APRÈS la lecture
    // du lot, en rejouant l'écriture avec une garde périmée.
    $commande = new App\Console\Commands\CrmReferentielsComblerTrous;
    $ecrire = (new ReflectionClass($commande))->getMethod('ecrire');
    $fiche = DB::selectOne('SELECT id, entity_nature, region_code, department_code, postcode, country_code, legal_form, naf, naf_rev2, siren FROM companies WHERE id = ?', [$id]);
    DB::table('companies')->where('id', $id)->update(['department_code' => '75']);

    $ecrites = $ecrire->invoke($commande, $this->espace, [['fiche' => $fiche, 'nature' => 'entreprise', 'region' => '84']]);

    expect($ecrites)->toBe(0)
        ->and(ctLire($id)->region_code)->toBeNull();
});

test('reclasser --inclure-protegees pose desormais la nature d un GOFAB venu de l INSEE, jamais celle d un organisateur', function () {
    // L'espace doit porter une fiche INSEE ordinaire (garde B6).
    ctFiche($this->espace);
    $gofab = ctFiche($this->espace, ['entity_nature' => null, 'naf' => '25.62B']);
    ctProteger($this->espace, $gofab, FichesProtegees::TAG_GOFAB);
    $organisateur = ctFiche($this->espace, ['entity_nature' => null, 'naf' => '82.30Z']);
    ctProteger($this->espace, $organisateur, FichesProtegees::TAG_ORGANISATEURS);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--inclure-protegees' => true, '--sans-etiquettes' => true]);

    expect(ctLire($gofab)->entity_nature)->toBe('entreprise')
        ->and(ctLire($organisateur)->entity_nature)->toBeNull();
});
