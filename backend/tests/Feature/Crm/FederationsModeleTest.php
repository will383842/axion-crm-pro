<?php

/**
 * LE MODÈLE DES FÉDÉRATIONS (chantier 3, 2026-09-29) : la garde anti-cycle de
 * la tête de réseau, les listes fermées, les étiquettes dérivées et le
 * segment de campagne. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Taxonomy;
use App\Models\Company;
use App\Models\Workspace;
use App\Services\Tags\AutoTaggerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-fedm-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ fédérations modèle']);
});

afterEach(function () {
    foreach ($GLOBALS['zz_fedm_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['zz_fedm_fichiers'] = [];
});

/** @param  array<string, mixed>  $federation */
function fedmFiche(string $espace, string $nom, array $federation = [], array $fiche = []): int
{
    static $seq = 0;
    $seq++;
    $id = (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => str_pad((string) (930000000 + $seq), 9, '0', STR_PAD_LEFT),
        'entity_nature' => 'federation',
        'denomination' => $nom,
        'created_at' => now(), 'updated_at' => now(),
    ], $fiche));
    DB::table('federations')->insert(array_merge([
        'company_id' => $id, 'workspace_id' => $espace, 'famille' => 'federation_syndicat_pro',
        'niveau' => 'national', 'pertinence' => 'haute', 'contactabilite' => 'email_verifie',
    ], $federation));

    return $id;
}

function fedmTete(int $antenne, ?int $tete): void
{
    DB::table('federations')->where('company_id', $antenne)->update(['parent_company_id' => $tete]);
}

/** Exécute sous point de reprise : une erreur SQL ne contamine pas la suite du test. */
function fedmRefuse(callable $ecriture): bool
{
    try {
        DB::transaction(fn () => $ecriture());

        return false;
    } catch (QueryException) {
        return true;
    }
}

// ── Tête de réseau : garde anti-cycle ──────────────────────────────────────

test('une chaine antenne -> region -> nationale est acceptee (temoin)', function () {
    $nationale = fedmFiche($this->espace, 'ZZ Nationale');
    $region = fedmFiche($this->espace, 'ZZ Region', ['niveau' => 'regional']);
    $dept = fedmFiche($this->espace, 'ZZ Dept', ['niveau' => 'departemental']);

    fedmTete($region, $nationale);
    fedmTete($dept, $region);

    expect(DB::table('federations')->where('company_id', $dept)->value('parent_company_id'))->toBe($region);
});

test('la base refuse une fiche tete de reseau d elle-meme', function () {
    $a = fedmFiche($this->espace, 'ZZ A');

    expect(fedmRefuse(fn () => fedmTete($a, $a)))->toBeTrue()
        ->and(DB::table('federations')->where('company_id', $a)->value('parent_company_id'))->toBeNull();
});

test('la base refuse une boucle, a deux comme a trois', function () {
    $a = fedmFiche($this->espace, 'ZZ A');
    $b = fedmFiche($this->espace, 'ZZ B');
    $c = fedmFiche($this->espace, 'ZZ C');
    fedmTete($b, $a);
    fedmTete($c, $b);

    expect(fedmRefuse(fn () => fedmTete($a, $b)))->toBeTrue()
        ->and(fedmRefuse(fn () => fedmTete($a, $c)))->toBeTrue()
        ->and(DB::table('federations')->where('company_id', $a)->value('parent_company_id'))->toBeNull();
});

test('la base refuse une tete de reseau d un autre espace', function () {
    $autre = (string) Str::uuid();
    Workspace::create(['id' => $autre, 'slug' => 'zz-fedm-autre-' . Str::random(6), 'name' => 'ZZ autre']);
    $ailleurs = fedmFiche($autre, 'ZZ Ailleurs');
    $ici = fedmFiche($this->espace, 'ZZ Ici');

    expect(fedmRefuse(fn () => fedmTete($ici, $ailleurs)))->toBeTrue();
});

// ── Listes fermées ─────────────────────────────────────────────────────────

test('la base refuse un secteur represente hors referentiel, non_classe, ou plus de trois', function () {
    $a = fedmFiche($this->espace, 'ZZ A');
    $ecrire = fn (string $secteurs) => fn () => DB::table('federations')->where('company_id', $a)->update(['secteurs' => $secteurs]);

    expect(fedmRefuse($ecrire('{non_classe}')))->toBeTrue()
        ->and(fedmRefuse($ecrire('{kermesse}')))->toBeTrue()
        ->and(fedmRefuse($ecrire('{btp,sante,droit,industrie}')))->toBeTrue()
        ->and(fedmRefuse($ecrire('{btp,interprofessionnel,sante}')))->toBeFalse();
});

test('la nature federation est acceptee par la base', function () {
    expect(array_key_exists('federation', Taxonomy::ENTITY_NATURES))->toBeTrue()
        ->and(fedmRefuse(fn () => DB::table('companies')->insert([
            'workspace_id' => $this->espace, 'siren' => '939999999', 'entity_nature' => 'federation',
            'created_at' => now(), 'updated_at' => now(),
        ])))->toBeFalse();
});

// ── Étiquettes dérivées ────────────────────────────────────────────────────

test('la resynchro automatique pose les etiquettes de la federation, et aucune sur une entreprise ordinaire', function () {
    $fede = fedmFiche($this->espace, 'ZZ Fede', ['secteurs' => '{btp,immobilier}', 'tailles_adherents' => '{tpe}', 'niveau' => 'regional']);
    $ordinaire = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '938888888', 'denomination' => 'ZZ Ordinaire',
        'sector_main' => 'btp', 'size_category' => 'pme', 'region_code' => '84', 'department_code' => '69',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $tagger = new AutoTaggerService;
    $tagger->syncTags(Company::findOrFail($fede));
    $tagger->syncTags(Company::findOrFail($ordinaire));

    $slugs = fn (int $id) => DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $id)->pluck('tags.slug')->sort()->values()->all();

    expect($slugs($fede))->toContain('famille:federation-syndicat-pro', 'niveau:regional', 'secteur:btp', 'secteur:immobilier', 'taille-adherents:tpe', 'pertinence:haute', 'contactabilite:email-verifie', 'nature-federation')
        // Témoin : les étiquettes d'une entreprise ne changent pas de forme.
        ->and($slugs($ordinaire))->toBe(['dept-69', 'region-84', 'sector-btp', 'size-pme']);

    // Une deuxième resynchro ne retire rien (elles sont DÉSIRÉES, pas orphelines).
    $tagger->syncTags(Company::findOrFail($fede));
    expect($slugs($fede))->toContain('famille:federation-syndicat-pro', 'secteur:immobilier');

    // Et elles suivent la ligne : un niveau changé remplace l'étiquette.
    DB::table('federations')->where('company_id', $fede)->update(['niveau' => 'local']);
    $tagger->syncTags(Company::findOrFail($fede));
    expect($slugs($fede))->toContain('niveau:local')->not->toContain('niveau:regional');
});

test('chaque etiquette de federation appartient a un namespace gouverne, de la bonne categorie', function () {
    $fede = fedmFiche($this->espace, 'ZZ Fede', ['secteurs' => '{btp}', 'tailles_adherents' => '{pme}', 'certitude' => 'moyenne']);
    (new AutoTaggerService)->syncTags(Company::findOrFail($fede));

    $tags = DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $fede)->where('tags.slug', 'like', '%:%')->get(['tags.slug', 'tags.category']);

    expect($tags)->not->toBeEmpty();
    foreach ($tags as $t) {
        $ns = explode(':', (string) $t->slug, 2)[0];
        expect(Taxonomy::TAG_NAMESPACES)->toHaveKey($ns)
            ->and($t->category)->toBe(Taxonomy::TAG_NAMESPACES[$ns]);
    }
});

// ── Segment de campagne « federations » ────────────────────────────────────

function fedmSortie(): string
{
    $chemin = (string) tempnam(sys_get_temp_dir(), 'zz-fedm-');
    $GLOBALS['zz_fedm_fichiers'][] = $chemin;

    return $chemin;
}

/** @return list<array<string, mixed>> */
function fedmDestinataires(string $segment, array $options = []): array
{
    // La liste ne retient que des adresses VÉRIFIÉES valides : on vérifie
    // d'abord, avec un DNS simulé où tout domaine reçoit.
    ResolveurDnsSimule::toutVerifier();
    $sortie = fedmSortie();
    Artisan::call('crm:campagne:destinataires', ['segment' => $segment, 'sortie' => $sortie] + $options);

    return array_values(array_filter(array_map(
        fn ($l) => json_decode($l, true),
        file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    )));
}

function fedmTaguer(string $espace, int $companyId, string $slug): void
{
    $tag = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id')
        ?? DB::table('tags')->insertGetId([
            'workspace_id' => $espace, 'slug' => $slug, 'name' => $slug, 'category' => 'intent',
            'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    DB::table('company_tag')->insert([
        'company_id' => $companyId, 'tag_id' => (int) $tag, 'workspace_id' => $espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);
}

test('le segment federations vise les federations, pas les organisateurs ; la pertinence faible est ecartee par defaut', function () {
    config(['crm.ingest.business_workspace' => $this->slug]);

    $nationale = fedmFiche($this->espace, 'ZZ Nationale', [], ['email_generic' => 'contact@zz-nationale.example.invalid']);
    $antenne = fedmFiche($this->espace, 'ZZ Antenne', ['niveau' => 'departemental'], ['email_generic' => 'contact@zz-antenne.example.invalid']);
    fedmTete($antenne, $nationale);
    $faible = fedmFiche($this->espace, 'ZZ Faible', ['pertinence' => 'faible'], ['email_generic' => 'contact@zz-faible.example.invalid']);
    foreach ([$nationale, $antenne, $faible] as $id) {
        fedmTaguer($this->espace, $id, 'src:scraping-federations-2026');
    }
    $orga = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => null, 'country_code' => 'FR', 'foreign_id' => 'evt:zz-club-fedm',
        'entity_nature' => 'reseau', 'denomination' => 'ZZ Club', 'email_generic' => 'bureau@zz-club.example.invalid',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    fedmTaguer($this->espace, $orga, 'src:scraping-evenements-pro');

    $emails = fn (array $lignes) => collect($lignes)->pluck('email')->sort()->values()->all();

    $parDefaut = fedmDestinataires('federations');
    expect($emails($parDefaut))->toBe(['contact@zz-antenne.example.invalid', 'contact@zz-nationale.example.invalid'])
        ->and(collect($parDefaut)->firstWhere('email', 'contact@zz-antenne.example.invalid')['federation'])
        ->toBe(['famille' => 'federation_syndicat_pro', 'niveau' => 'departemental', 'secteurs' => [], 'tete_de_reseau' => 'ZZ Nationale']);

    expect($emails(fedmDestinataires('federations', ['--avec-pertinence-faible' => true])))
        ->toContain('contact@zz-faible.example.invalid');

    // Les organisateurs n'y sont pas, et les fédérations ne sont pas dans le leur.
    expect($emails(fedmDestinataires('organisateurs-evenements')))->toBe(['bureau@zz-club.example.invalid']);
});

test('--avec-pertinence-faible est refuse hors du segment federations', function () {
    config(['crm.ingest.business_workspace' => $this->slug]);

    $code = Artisan::call('crm:campagne:destinataires', [
        'segment' => 'organisateurs-evenements', 'sortie' => fedmSortie(), '--avec-pertinence-faible' => true,
    ]);

    expect($code)->toBe(1);
});
