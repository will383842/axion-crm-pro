<?php

/**
 * RECLASSER LES FICHES PROTÉGÉES — `crm:referentiels:reclasser --inclure-protegees`
 * (2026-09-29).
 *
 * Le reclassement de masse du 28/09 (#254) a exclu les fiches protégées
 * (organisateurs d'événements, fédérations). L'option les reclasse AUSSI —
 * colonnes de classement et étiquettes `sector-`/`size-`/`region-` SEULEMENT.
 * Will a interdit de supprimer leurs contacts (27/09) : chaque test qui écrit
 * vérifie aussi que contacts, coordonnées et fiches sont intacts.
 *
 * Chaque garde face à un TÉMOIN, prouvée par son EFFET en base.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 94xxxxxxx, noms « ZZ », adresses
 * en `.invalid`.
 */

use App\Console\Commands\CrmImportFederations;
use App\Crm\FichesProtegees;
use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\EtiquettesClassement;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-protegees-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ reclassement protegees']);
    // L'espace doit porter au moins une fiche INSEE ordinaire (garde B6).
    $this->temoin = rpFiche($this->espace, ['naf' => '52.1D', 'sector_main' => 'transport', 'department_code' => '38']);
});

/** @param  array<string, mixed>  $attrs */
function rpFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (940000000 + $seq),
        'denomination' => 'ZZ protegee ' . $seq,
        'discovery_source' => 'insee',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

/** @param  array<string, mixed>  $attrs */
function rpTag(string $espace, string $slug, array $attrs = []): int
{
    $existant = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id');
    if ($existant !== null) {
        return (int) $existant;
    }

    return (int) DB::table('tags')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'slug' => $slug,
        'name' => $slug,
        'category' => 'custom',
        'kind' => 'auto',
        'rules' => '[]',
        'is_locked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function rpLier(string $espace, int $companyId, int $tagId, string $par = 'auto-rule'): void
{
    DB::table('company_tag')->insert([
        'company_id' => $companyId,
        'tag_id' => $tagId,
        'workspace_id' => $espace,
        'assigned_at' => now(),
        'assigned_by' => $par,
    ]);
}

/** Pose le tag de protection, verrouillé, comme l'ingestion. */
function rpProteger(string $espace, int $companyId, string $tag): void
{
    rpLier($espace, $companyId, rpTag($espace, $tag, ['is_locked' => true, 'category' => 'intent']));
}

/**
 * Une fiche protégée qui porte coordonnées et contacts, comme en production.
 *
 * @param  array<string, mixed>  $attrs
 */
function rpProtegeeAvecContacts(string $espace, string $tag, array $attrs = []): int
{
    $id = rpFiche($espace, array_merge([
        'email_generic' => 'contact@zz-organisation.invalid',
        'phone' => '+33 1 00 00 00 00',
        'website' => 'https://zz-organisation.invalid',
        'signals' => '{"contact_channels":{"emails":["accueil@zz-organisation.invalid"]}}',
    ], $attrs));
    rpProteger($espace, $id, $tag);
    foreach (['ZZ Premiere', 'ZZ Seconde'] as $nom) {
        DB::table('contacts')->insert([
            'workspace_id' => $espace, 'company_id' => $id,
            'first_name' => 'ZZ', 'last_name' => $nom,
            'email' => strtolower(str_replace(' ', '.', $nom)) . '.' . $id . '@zz-organisation.invalid',
            'phone' => '+33 6 00 00 00 00',
            'legal_basis' => 'legitimate_interest_b2b',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

/** @return list<string> */
function rpSlugs(int $companyId): array
{
    $slugs = DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->pluck('tags.slug')->all();
    sort($slugs);

    return array_values(array_map('strval', $slugs));
}

function rpCompteur(string $sortie, string $compteur): ?int
{
    return preg_match('/\|\s*' . preg_quote($compteur, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m) === 1 ? (int) $m[1] : null;
}

/** @param  list<string>  $sauf  colonnes exclues de la photographie */
function rpPhotoFiches(string $espace, array $sauf = []): string
{
    $lignes = DB::table('companies')->where('workspace_id', $espace)->orderBy('id')->get()
        ->map(static function (object $l) use ($sauf): array {
            $l = (array) $l;
            foreach ($sauf as $c) {
                unset($l[$c]);
            }

            return $l;
        })->all();

    return json_encode($lignes, JSON_THROW_ON_ERROR);
}

function rpPhotoContacts(string $espace): string
{
    return json_encode(DB::table('contacts')->where('workspace_id', $espace)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR);
}

function rpPhotoTout(string $espace): string
{
    return json_encode([
        rpPhotoFiches($espace),
        rpPhotoContacts($espace),
        DB::table('company_tag')->where('workspace_id', $espace)->orderBy('company_id')->orderBy('tag_id')->get()->all(),
        DB::table('tags')->where('workspace_id', $espace)->orderBy('id')->get(['id', 'slug', 'name'])->all(),
    ], JSON_THROW_ON_ERROR);
}

/** Les six colonnes de classement : les SEULES que l'option peut réécrire. */
const RP_CLASSEMENT = ['sector_main', 'size_category', 'entity_nature', 'region_code', 'naf_nomenclature', 'naf_rev2'];

// ── Sans l'option : rien ne change ────────────────────────────────────────

test('sans l option, les fiches protegees sont exclues comme avant, le temoin est reclasse', function () {
    $organisateur = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_ORGANISATEURS, [
        'naf' => '82.30Z', 'size_category' => 'micro', 'department_code' => '69', 'region_code' => '82',
        'entity_nature' => 'reseau', 'discovery_source' => 'scraping',
    ]);
    rpLier($this->espace, $organisateur, rpTag($this->espace, 'sector-transport', ['category' => 'sector']));
    $federation = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, [
        'naf' => '52.1D', 'sector_main' => 'transport', 'entity_nature' => 'federation',
    ]);

    $protegees = static fn (): string => json_encode([
        DB::table('companies')->whereIn('id', [$organisateur, $federation])->orderBy('id')->get()->all(),
        rpSlugs($organisateur), rpSlugs($federation),
    ], JSON_THROW_ON_ERROR);
    $avant = $protegees();
    $contacts = rpPhotoContacts($this->espace);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug]);
    $sortie = Artisan::output();

    expect($protegees())->toBe($avant)
        ->and(rpPhotoContacts($this->espace))->toBe($contacts)
        ->and(rpCompteur($sortie, 'fiches_protegees_exclues'))->toBe(2)
        ->and(rpCompteur($sortie, 'fiches_protegees_incluses'))->toBe(0)
        ->and(rpCompteur($sortie, 'fiches_lues'))->toBe(1)
        // Témoin : la commande a bien tourné.
        ->and(DB::table('companies')->where('id', $this->temoin)->value('sector_main'))->toBe('commerce_detail');
});

// ── Avec l'option : classées, et rien d'autre ─────────────────────────────

test('avec l option, la fiche protegee est classee ; contacts, coordonnees et fiches intacts', function () {
    $organisateur = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_ORGANISATEURS, [
        'naf' => '82.30Z', 'effectif_range' => '12', 'size_category' => 'micro',
        'department_code' => '69', 'region_code' => '82',
        'entity_nature' => 'reseau', 'discovery_source' => 'scraping',
    ]);
    // Étiquette automatique obsolète : retirée. Les autres : intouchables.
    rpLier($this->espace, $organisateur, rpTag($this->espace, 'sector-transport', ['category' => 'sector']));
    rpLier($this->espace, $organisateur, rpTag($this->espace, 'sector-it-saas', ['category' => 'sector', 'kind' => 'manual']));
    rpLier($this->espace, $organisateur, rpTag($this->espace, 'region-99', ['category' => 'geo']), 'user');

    $fiches = rpPhotoFiches($this->espace, RP_CLASSEMENT);
    $contacts = rpPhotoContacts($this->espace);
    $nbFiches = DB::table('companies')->where('workspace_id', $this->espace)->count();
    $nbContacts = DB::table('contacts')->where('workspace_id', $this->espace)->count();

    $code = Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--inclure-protegees' => true]);
    $sortie = Artisan::output();

    $secteur = Classement::secteur('82.30Z');
    $f = DB::table('companies')->where('id', $organisateur)->first();
    $attendues = [
        'region-99', 'sector-it-saas', FichesProtegees::TAG_ORGANISATEURS,
        EtiquettesClassement::slugRegion('84'), EtiquettesClassement::slugSecteur($secteur),
        EtiquettesClassement::slugTaille('pme'),
    ];
    sort($attendues);

    expect($code)->toBe(0)
        ->and($secteur)->not->toBe('transport')
        ->and($f->sector_main)->toBe($secteur)
        ->and($f->size_category)->toBe('pme')
        ->and($f->region_code)->toBe('84')
        ->and($f->naf_nomenclature)->toBe('naf_rev2')
        // Nature d'organisateur conservée.
        ->and($f->entity_nature)->toBe('reseau')
        ->and(rpSlugs($organisateur))->toBe($attendues)
        // RIEN d'autre que les six colonnes : coordonnées, notes, signaux,
        // `updated_at` compris.
        ->and(rpPhotoFiches($this->espace, RP_CLASSEMENT))->toBe($fiches)
        ->and(rpPhotoContacts($this->espace))->toBe($contacts)
        ->and(DB::table('companies')->where('workspace_id', $this->espace)->count())->toBe($nbFiches)
        ->and(DB::table('contacts')->where('workspace_id', $this->espace)->count())->toBe($nbContacts)
        ->and(rpCompteur($sortie, 'fiches_protegees_incluses'))->toBe(1)
        ->and(rpCompteur($sortie, 'fiches_protegees_exclues'))->toBe(0)
        ->and(rpCompteur($sortie, 'fiches_modifiees_entre_temps'))->toBe(0);
});

test('avec l option, une fiche protegee deja juste n est pas reecrite (idempotence)', function () {
    $id = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, [
        'naf' => '52.1D', 'sector_main' => 'transport', 'entity_nature' => 'federation',
    ]);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--inclure-protegees' => true]);
    $apres = rpPhotoTout($this->espace);
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--inclure-protegees' => true]);
    $second = Artisan::output();

    expect(DB::table('companies')->where('id', $id)->value('sector_main'))->toBe('commerce_detail')
        ->and(rpCompteur($second, 'fiches_a_modifier'))->toBe(0)
        ->and(rpCompteur($second, 'etiquettes_a_ajouter'))->toBe(0)
        ->and(rpPhotoTout($this->espace))->toBe($apres);
});

// ── B4 : le secteur posé autrement ne s'écrase pas ────────────────────────

test('B4 — avec l option, le secteur choisi par l import des federations n est jamais ecrase', function () {
    $origine = json_encode(['sector_main' => CrmImportFederations::ORIGINE_SECTEUR], JSON_THROW_ON_ERROR);
    $parImport = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, [
        'naf' => '70.22Z', 'sector_main' => 'btp', 'entity_nature' => 'federation', 'field_origins' => $origine,
    ]);
    // TÉMOIN : même fiche, secteur NON posé par l'import — le NAF décide.
    $sansOrigine = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, [
        'naf' => '70.22Z', 'sector_main' => 'btp', 'entity_nature' => 'federation',
    ]);
    // Posé par l'import, mais hors référentiel : la règle ne protège qu'un secteur VALIDE.
    $invalide = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, [
        'naf' => '70.22Z', 'sector_main' => 'it_saas', 'entity_nature' => 'federation', 'field_origins' => $origine,
    ]);
    $interpro = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, [
        'naf' => '94.11Z', 'sector_main' => 'interprofessionnel', 'entity_nature' => 'federation',
    ]);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--inclure-protegees' => true]);
    $sortie = Artisan::output();

    $parNaf = Classement::secteur('70.22Z');
    $secteur = static fn (int $id): ?string => DB::table('companies')->where('id', $id)->value('sector_main');
    expect($parNaf)->not->toBe('btp')
        ->and($secteur($parImport))->toBe('btp')
        ->and(rpSlugs($parImport))->toContain(EtiquettesClassement::slugSecteur('btp'))
        ->and($secteur($sansOrigine))->toBe($parNaf)
        ->and($secteur($invalide))->toBe($parNaf)
        ->and($secteur($interpro))->toBe('interprofessionnel')
        ->and(rpCompteur($sortie, 'secteurs_import_conserves'))->toBe(1)
        // Le champ d'origine n'est pas réécrit.
        ->and(json_decode((string) DB::table('companies')->where('id', $parImport)->value('field_origins'), true))
        ->toBe(['sector_main' => CrmImportFederations::ORIGINE_SECTEUR]);
});

// ── La nature ─────────────────────────────────────────────────────────────

test('avec l option, la nature renseignee ne bouge pas, et celle d une protegee n est jamais devinee', function () {
    $reseau = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_ORGANISATEURS, ['naf' => '82.30Z', 'entity_nature' => 'reseau']);
    $cci = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_ORGANISATEURS, ['naf' => '94.11Z', 'entity_nature' => 'cci']);
    $federation = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, ['naf' => '94.12Z', 'entity_nature' => 'federation']);
    $association = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, ['naf' => '94.99Z', 'entity_nature' => 'association']);
    // Protégée, fiche INSEE, sans nature : reste sans nature.
    $sansNature = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_ORGANISATEURS, ['naf' => '82.30Z']);
    // TÉMOIN : la même, NON protégée, devient `entreprise` (règle de #254).
    $ordinaire = rpFiche($this->espace, ['naf' => '82.30Z']);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--inclure-protegees' => true]);
    $sortie = Artisan::output();

    $nature = static fn (int $id): ?string => DB::table('companies')->where('id', $id)->value('entity_nature');
    expect($nature($reseau))->toBe('reseau')
        ->and($nature($cci))->toBe('cci')
        ->and($nature($federation))->toBe('federation')
        ->and($nature($association))->toBe('association')
        ->and($nature($sansNature))->toBeNull()
        ->and($nature($ordinaire))->toBe('entreprise')
        ->and(rpCompteur($sortie, 'natures_protegees_non_devinees'))->toBe(1)
        // Classée quand même, par ailleurs.
        ->and(DB::table('companies')->where('id', $sansNature)->value('sector_main'))->toBe(Classement::secteur('82.30Z'));
});

// ── L'essai à blanc ne ment pas ───────────────────────────────────────────

test('avec l option, l essai a blanc n ecrit RIEN et annonce ce que l execution realise', function () {
    $organisateur = rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_ORGANISATEURS, [
        'naf' => '82.30Z', 'size_category' => 'micro', 'department_code' => '69', 'entity_nature' => 'reseau',
    ]);
    // `size-micro` et `sector-transport` ne sont portées QUE par la protégée :
    // sous l'option, elles en sont retirées, puis supprimées comme obsolètes —
    // l'essai doit les compter.
    rpLier($this->espace, $organisateur, rpTag($this->espace, 'size-micro', ['category' => 'size']));
    rpLier($this->espace, $organisateur, rpTag($this->espace, 'sector-transport', ['category' => 'sector']));
    rpProtegeeAvecContacts($this->espace, FichesProtegees::TAG_FEDERATIONS, ['naf' => '52.1D', 'entity_nature' => 'federation']);

    // Sans l'option, l'essai n'en voit pas une (témoin de la bascule).
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true]);
    $sansOption = Artisan::output();

    $avant = rpPhotoTout($this->espace);
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--dry-run' => true, '--inclure-protegees' => true]);
    $aBlanc = Artisan::output();

    expect(rpPhotoTout($this->espace))->toBe($avant)
        ->and($aBlanc)->toContain('[À BLANC]')
        ->and($aBlanc)->toContain('fiches PROTÉGÉES comprises')
        ->and(rpCompteur($sansOption, 'fiches_lues'))->toBe(1)
        ->and(rpCompteur($sansOption, 'etiquettes_obsoletes_a_supprimer'))->toBe(0)
        ->and(rpCompteur($aBlanc, 'fiches_lues'))->toBe(3)
        ->and(rpCompteur($aBlanc, 'fiches_a_modifier'))->toBe(3)
        // `size-micro` et `sector-transport`, hors référentiel.
        ->and(rpCompteur($aBlanc, 'etiquettes_obsoletes_a_supprimer'))->toBe(2);

    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $this->slug, '--inclure-protegees' => true]);
    $reel = Artisan::output();

    expect(rpCompteur($reel, 'fiches_modifiees'))->toBe(rpCompteur($aBlanc, 'fiches_a_modifier'))
        ->and(rpCompteur($reel, 'etiquettes_ajoutees'))->toBe(rpCompteur($aBlanc, 'etiquettes_a_ajouter'))
        ->and(rpCompteur($reel, 'etiquettes_retirees'))->toBe(rpCompteur($aBlanc, 'etiquettes_a_retirer'))
        ->and(rpCompteur($reel, 'etiquettes_retirees'))->toBe(2)
        ->and(rpCompteur($reel, 'etiquettes_obsoletes_supprimees'))->toBe(rpCompteur($aBlanc, 'etiquettes_obsoletes_a_supprimer'))
        ->and(DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'size-micro')->exists())->toBeFalse();
});
