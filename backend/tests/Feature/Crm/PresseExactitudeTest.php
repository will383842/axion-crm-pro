<?php

/**
 * HARMONISATION DE LA PRESSE — EXACTITUDE (relecture de #264, 2026-09-30).
 *
 *  - repasser ne change RIEN (fiches, contacts, `scraper_runs`, activités,
 *    compteurs) — une émission n'apporte jamais son site ni son adresse à la
 *    fiche de sa chaîne, au premier passage comme aux suivants ;
 *  - une chaîne est harmonisée une fois par passage, avec ses émissions dans
 *    le même paquet : l'essai à blanc compte comme le réel ;
 *  - `media:sync-from-companies` n'écrase plus le site propre d'un média ;
 *  - deux journalistes homonymes ne fusionnent pas en un contact ;
 *  - l'import rejoint un titre déjà en base sans SIREN, ou rejette en cas de doute ;
 *  - l'annulation ne touche jamais une qualification posée après ;
 *  - la migration rejouée rouvre sa source.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Presse\QualificationPresse;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.scrape_funnel.validate_mx' => false, 'crm.ingest.business_workspace' => 'axion-ia']);
    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $this->seed(ScrapingSourcesSeeder::class);
});

/** @param  array<string, mixed>  $valeurs */
function pxMedia(string $espace, array $valeurs): int
{
    return (int) DB::table('media')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'name' => 'ZZ media', 'media_type' => 'presse_quotidien', 'media_family' => 'editorial',
        'source' => 'cppap', 'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param  array<string, mixed>  $valeurs */
function pxJournaliste(string $espace, int $media, array $valeurs): int
{
    return (int) DB::table('journalists')->insertGetId($valeurs + [
        'workspace_id' => $espace, 'media_id' => $media, 'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return array{code: int, sortie: string} */
function pxHarmoniser(array $options = []): array
{
    $code = Artisan::call('crm:presse:harmoniser', $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

/** @return array<string, int> les compteurs de la sortie */
function pxCompteurs(string $sortie): array
{
    preg_match_all('/\|\s*([a-z_]+)\s*\|\s*(\d+)\s*\|/', $sortie, $m, PREG_SET_ORDER);
    $c = [];
    foreach ($m as $l) {
        $c[$l[1]] = (int) $l[2];
    }
    expect(count($c))->toBeGreaterThan(10);

    return $c;
}

/** @return array<string, mixed> tout ce qui ne doit pas bouger au repassage */
function pxEtat(): array
{
    $e = [];
    foreach (['companies', 'contacts', 'media', 'journalists', 'tags', 'company_tag', 'scraper_runs', 'activities', 'contacts_retires'] as $t) {
        $e[$t] = DB::table($t)->count();
    }
    $e['fiches'] = DB::table('companies')->orderBy('id')
        ->get(['id', 'entity_nature', 'relation_type', 'email_generic', 'website', 'phone', 'updated_at', 'metadata'])->toArray();
    $e['medias'] = DB::table('media')->orderBy('id')->get(['id', 'company_id', 'website', 'email', 'harmonise_le'])->toArray();
    $e['contacts'] = DB::table('contacts')->orderBy('id')->get(['id', 'company_id', 'email', 'external_ref', 'metadata'])->toArray();

    return $e;
}

test('une chaine et ses emissions : harmonisees une fois par passage, a blanc = reel, et repasser ne change RIEN, compteurs compris', function () {
    $chaine = pxMedia($this->espace, ['name' => 'ZZ TV fictive', 'media_type' => 'tv', 'diffusion_zone' => 'national', 'source' => 'arcom',
        'website' => 'https://zz-tv.example.invalid']);
    foreach (['ZZ Journal du soir', 'ZZ Magazine du dimanche'] as $i => $nom) {
        $e = pxMedia($this->espace, ['name' => $nom, 'media_type' => 'tv_emission', 'parent_media_id' => $chaine, 'source' => 'wikidata',
            'website' => 'https://zz-emission-' . $i . '.example.invalid', 'email' => 'emission' . $i . '@zz-emission.example.invalid',
            'phone' => '01 00 00 00 0' . $i]);
        pxJournaliste($this->espace, $e, ['first_name' => 'Zoe' . $i, 'last_name' => 'ZZPRESENTATRICE' . $i]);
    }
    $avant = pxEtat();

    // À blanc, une tête par paquet : la chaîne et ses deux émissions sont dans le même.
    $blanc = pxCompteurs(pxHarmoniser(['--dry-run' => true, '--paquet' => 1])['sortie']);
    expect(pxEtat())->toEqual($avant);

    $premier = pxCompteurs(pxHarmoniser(['--paquet' => 1])['sortie']);
    expect($premier)->toBe($blanc)
        ->and($premier['fiches_creees'])->toBe(1)
        ->and($premier['emissions_sur_la_chaine'])->toBe(2)
        ->and($premier['fiches_existantes'])->toBe(0)
        ->and($premier['journalistes_convertis'])->toBe(2);

    $fiche = (int) DB::table('media')->where('id', $chaine)->value('company_id');
    $apresPremier = pxEtat();
    $deuxieme = pxCompteurs(pxHarmoniser(['--paquet' => 1])['sortie']);
    $troisieme = pxCompteurs(pxHarmoniser(['--paquet' => 1])['sortie']);

    $f = DB::table('companies')->where('id', $fiche)->first();
    expect(pxEtat())->toEqual($apresPremier)
        ->and($troisieme)->toBe($deuxieme)
        ->and($deuxieme['fiches_existantes'])->toBe(1)
        ->and($deuxieme['emissions_deja_sur_la_chaine'])->toBe(2)
        ->and($deuxieme['fiches_creees'] + $deuxieme['emissions_sur_la_chaine'] + $deuxieme['contacts_crees'] + $deuxieme['journalistes_convertis'])->toBe(0)
        ->and($deuxieme['journalistes_deja_harmonises'])->toBe(2)
        // Les coordonnées des émissions ne sont jamais celles de la chaîne.
        ->and($f->website)->toBe('https://zz-tv.example.invalid')
        ->and($f->email_generic)->toBeNull()
        ->and($f->phone)->toBeNull();
});

test('media:sync-from-companies : un titre rattache a son EDITEUR suit le site de la fiche ; un titre rattache par l harmonisation garde le sien ; une emission n herite rien', function () {
    $editeur = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000901', 'denomination' => 'ZZ EDITEUR', 'website' => 'https://zz-editeur.example.invalid',
        'email_generic' => 'contact@zz-editeur.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $provisoire = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'country_code' => 'FR', 'foreign_id' => 'media:999999', 'denomination' => 'ZZ TITRE PROVISOIRE',
        'website' => 'https://zz-provisoire-fiche.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Rattaché à son éditeur (SIREN, extraction NAF) : suit la correction du site de la fiche.
    $titreEditeur = pxMedia($this->espace, ['company_id' => $editeur, 'name' => 'ZZ Titre editeur', 'website' => 'https://zz-ancien-site.example.invalid', 'source' => 'naf-extract']);
    // Rattachés par l'harmonisation (fiche provisoire, liste de diffusion) : gardent le leur.
    $titreProvisoire = pxMedia($this->espace, ['company_id' => $provisoire, 'name' => 'ZZ Titre provisoire', 'website' => 'https://zz-titre.example.invalid']);
    $titreListe = pxMedia($this->espace, ['company_id' => $editeur, 'name' => 'ZZ Titre liste', 'website' => 'https://zz-liste.example.invalid', 'source' => 'liste-presse']);
    $sansSite = pxMedia($this->espace, ['company_id' => $provisoire, 'name' => 'ZZ Sans site', 'media_type' => 'tv']);
    $emission = pxMedia($this->espace, ['company_id' => $provisoire, 'name' => 'ZZ Emission', 'media_type' => 'tv_emission', 'parent_media_id' => $sansSite]);

    Artisan::call('media:sync-from-companies');

    $site = static fn (int $id): mixed => DB::table('media')->where('id', $id)->value('website');
    expect($site($titreEditeur))->toBe('https://zz-editeur.example.invalid')
        ->and($site($titreProvisoire))->toBe('https://zz-titre.example.invalid')
        ->and($site($titreListe))->toBe('https://zz-liste.example.invalid')
        // Le trou est comblé, même rattaché par l'harmonisation.
        ->and($site($sansSite))->toBe('https://zz-provisoire-fiche.example.invalid')
        ->and($site($emission))->toBeNull()
        ->and(DB::table('media')->where('id', $emission)->value('email'))->toBeNull();
});

test('deux journalistes HOMONYMES sur une meme fiche ne deviennent jamais un seul contact : le second est ecarte et compte', function () {
    $chaine = pxMedia($this->espace, ['name' => 'ZZ TV', 'media_type' => 'tv']);
    $e1 = pxMedia($this->espace, ['name' => 'ZZ Emission 1', 'media_type' => 'tv_emission', 'parent_media_id' => $chaine]);
    $e2 = pxMedia($this->espace, ['name' => 'ZZ Emission 2', 'media_type' => 'tv_emission', 'parent_media_id' => $chaine]);
    $j1 = pxJournaliste($this->espace, $e1, ['first_name' => 'Zoe', 'last_name' => 'ZZHOMONYME', 'role' => 'Présentatrice']);
    $j2 = pxJournaliste($this->espace, $e2, ['first_name' => 'Zoé', 'last_name' => 'zzhomonyme', 'role' => 'Productrice']);
    // Et deux homonymes sur le MÊME média.
    $titre = pxMedia($this->espace, ['name' => 'ZZ Titre']);
    $j3 = pxJournaliste($this->espace, $titre, ['first_name' => 'Max', 'last_name' => 'ZZDOUBLE']);
    // (L'index `journalists_dedup_uidx` interdit deux lignes au nom IDENTIQUE
    // sur un même média ; la casse suffit à en faire deux lignes.)
    $j4 = pxJournaliste($this->espace, $titre, ['first_name' => 'Max', 'last_name' => 'zzdouble']);

    $r = pxCompteurs(pxHarmoniser()['sortie']);

    $c1 = DB::table('contacts')->where('external_ref', 'journaliste:' . $j1)->first();
    expect($c1)->not->toBeNull()
        ->and($c1->role)->toBe('Présentatrice')
        ->and(DB::table('journalists')->where('id', $j2)->value('contact_id'))->toBeNull()
        ->and(DB::table('journalists')->where('id', $j3)->value('contact_id'))->not->toBeNull()
        ->and(DB::table('journalists')->where('id', $j4)->value('contact_id'))->toBeNull()
        ->and(DB::table('contacts')->where('last_name', 'ILIKE', 'zzhomonyme')->count())->toBe(1)
        ->and($r['journalistes_homonymes_ecartes'])->toBe(2);

    // Stable au repassage.
    $r2 = pxCompteurs(pxHarmoniser()['sortie']);
    expect($r2['journalistes_homonymes_ecartes'])->toBe(2)
        ->and($r2['contacts_crees'])->toBe(0);
});

test('l import REJOINT un titre deja en base sans SIREN, et rejette quand il doute (ambigu, titre pas encore harmonise)', function () {
    $hebdo = pxMedia($this->espace, ['name' => 'ZZ Hébdo Local', 'media_type' => 'presse_hebdo', 'department_code' => '38']);
    pxHarmoniser();
    $fiche = (int) DB::table('media')->where('id', $hebdo)->value('company_id');
    // Deux fiches pour un même titre : ambigu.
    foreach ([1, 2] as $i) {
        $f = (int) DB::table('companies')->insertGetId(['workspace_id' => $this->espace, 'siren' => '90000080' . $i,
            'denomination' => 'ZZ DOUBLE ' . $i, 'created_at' => now(), 'updated_at' => now()]);
        pxMedia($this->espace, ['name' => 'ZZ Ambigu', 'media_type' => 'radio', 'company_id' => $f]);
    }
    pxMedia($this->espace, ['name' => 'ZZ Pas Encore', 'media_type' => 'radio', 'deleted_at' => null]);
    $fiches = DB::table('companies')->count();

    $fichier = (string) tempnam(sys_get_temp_dir(), 'zz-px-');
    file_put_contents($fichier, implode("\n", array_map('json_encode', [
        ['identifiant' => 'presse:zz:hebdo', 'nom' => 'zz hebdo local', 'type' => 'presse_hebdo', 'departement' => '38',
            'journaliste' => ['prenom' => 'Ana', 'nom' => 'ZZLOCALE']],
        ['identifiant' => 'presse:zz:ambigu', 'nom' => 'ZZ Ambigu', 'type' => 'radio'],
        ['identifiant' => 'presse:zz:pas-encore', 'nom' => 'ZZ Pas Encore', 'type' => 'radio'],
    ])) . "\n");
    Artisan::call('crm:presse:importer', ['file' => $fichier]);
    $sortie = Artisan::output();
    @unlink($fichier);

    expect(DB::table('companies')->count())->toBe($fiches)
        ->and(DB::table('companies')->where('foreign_id', 'presse:zz:hebdo')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $fiche)->where('last_name', 'ZZLOCALE')->exists())->toBeTrue()
        ->and(DB::table('media')->where('company_id', $fiche)->count())->toBe(1)
        ->and($sortie)->toContain('rapprochement_ambigu : 1')
        ->and($sortie)->toContain('titre_existant_non_harmonise : 1');
    preg_match('/\|\s*titres_rapproches\s*\|\s*(\d+)/', $sortie, $m);
    expect((int) ($m[1] ?? -1))->toBe(1);
});

test('l annulation rend l etat d avant, SAUF une relation ou une nature changee depuis', function () {
    $fiches = [];
    foreach (['intacte', 'client_depuis', 'manuelle_depuis'] as $cas) {
        $fiches[$cas] = (int) DB::table('companies')->insertGetId(['workspace_id' => $this->espace,
            'siren' => '9000007' . str_pad((string) count($fiches), 2, '0', STR_PAD_LEFT), 'denomination' => 'ZZ ' . $cas,
            'entity_nature' => 'entreprise', 'created_at' => now(), 'updated_at' => now()]);
        pxMedia($this->espace, ['name' => 'ZZ media ' . $cas, 'company_id' => $fiches[$cas]]);
    }
    pxHarmoniser();
    DB::table('companies')->where('id', $fiches['client_depuis'])->update(['relation_type' => 'client', 'entity_nature' => 'association']);
    DB::table('companies')->where('id', $fiches['manuelle_depuis'])->update(['relation_saisie_manuelle_at' => now()]);

    foreach (QualificationPresse::SQL_ANNULATION as $sql) {
        DB::statement($sql);
    }

    $f = fn (string $cas) => DB::table('companies')->where('id', $fiches[$cas])->first(['relation_type', 'entity_nature']);
    expect($f('intacte')->relation_type)->toBe('prospect')
        ->and($f('intacte')->entity_nature)->toBe('entreprise')
        ->and($f('client_depuis')->relation_type)->toBe('client')
        ->and($f('client_depuis')->entity_nature)->toBe('association')
        ->and($f('manuelle_depuis')->relation_type)->toBe('presse_media');
});

test('la migration rejouee apres une coupure de la source la ROUVRE', function () {
    DB::table('scraping_sources')->where('slug', QualificationPresse::SOURCE)->update(['enabled' => false]);

    (require database_path('migrations/2026_10_01_000010_presse_harmonisee.php'))->up();

    expect((bool) DB::table('scraping_sources')->where('slug', QualificationPresse::SOURCE)->value('enabled'))->toBeTrue();
});
