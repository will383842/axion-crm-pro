<?php

/**
 * IMPORTER UNE LISTE DE DIFFUSION PRESSE (2026-09-30) — `crm:presse:importer`.
 *
 * Même contrat que `crm:import-federations` : essai à blanc honnête, paquets,
 * idempotent, rejets comptés par motif et jamais une valeur de ligne en
 * sortie. Chaque ligne : un média (→ fiche + ligne `media`) et au plus un
 * journaliste (→ contact de la fiche).
 *
 * Fixtures FICTIVES et ANONYMISÉES uniquement (dépôt PUBLIC) : identifiants
 * `presse:zz:…`, SIREN en 9xxxxxxxx, noms « ZZ », domaines `.example.invalid`.
 * Le vrai fichier vit HORS du dépôt.
 */

use App\Crm\FichesProtegees;
use App\Crm\Presse\QualificationPresse;
use App\Support\ListeSuppression;
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

afterEach(function () {
    foreach ($GLOBALS['zz_pi_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['zz_pi_fichiers'] = [];
});

/**
 * @param  array<string, mixed>  $surcharge
 * @return array<string, mixed>
 */
function piLigne(array $surcharge = []): array
{
    return array_replace([
        'identifiant' => 'presse:zz:quotidien-1',
        'nom' => 'ZZ Quotidien fictif',
        'type' => 'presse_quotidien',
        'zone' => 'regional',
        'departement' => '69',
        'ville' => 'ZZVILLE',
        'code_postal' => '69001',
        'site' => 'https://zz-quotidien.example.invalid',
        'email_redaction' => 'redaction@zz-quotidien.example.invalid',
        'telephone' => '04 00 00 00 01',
        'theme' => 'Économie',
        'journaliste' => [
            'prenom' => 'Zoe', 'nom' => 'ZZREDACTRICE', 'fonction' => 'Rédactrice en chef', 'rubrique' => 'Économie',
            'email' => 'zoe.zz@zz-quotidien.example.invalid', 'acces' => 'email_redaction', 'linkedin' => 'https://www.linkedin.com/in/zz-fictif',
        ],
    ], $surcharge);
}

/** @param  list<array<string, mixed>|string>  $lignes */
function piFichier(array $lignes): string
{
    $chemin = (string) tempnam(sys_get_temp_dir(), 'zz-pi-');
    $GLOBALS['zz_pi_fichiers'][] = $chemin;
    file_put_contents($chemin, implode("\n", array_map(
        static fn ($l): string => is_string($l) ? $l : (string) json_encode($l, JSON_UNESCAPED_UNICODE),
        $lignes,
    )) . "\n");

    return $chemin;
}

/**
 * @param  list<array<string, mixed>|string>  $lignes
 * @param  array<string, mixed>  $options
 * @return array{code: int, sortie: string}
 */
function piImporter(array $lignes, array $options = []): array
{
    $code = Artisan::call('crm:presse:importer', ['file' => piFichier($lignes)] + $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function piCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

/** @return list<string> */
function piSlugs(int $companyId): array
{
    return DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->orderBy('tags.slug')->pluck('tags.slug')->all();
}

/** @return array<string, int> */
function piVolumes(): array
{
    $v = [];
    foreach (['companies', 'contacts', 'media', 'tags', 'company_tag', 'activities', 'scraper_runs', 'contacts_retires'] as $t) {
        $v[$t] = DB::table($t)->count();
    }

    return $v;
}

test('une ligne cree la fiche (FR, identifiant), sa ligne media et le contact du journaliste — protegee, etiquetee', function () {
    $r = piImporter([piLigne()]);

    $fiche = DB::table('companies')->where('country_code', 'FR')->where('foreign_id', 'presse:zz:quotidien-1')->first();
    $media = DB::table('media')->where('company_id', $fiche?->id)->first();
    $contact = DB::table('contacts')->where('company_id', $fiche?->id)->first();
    expect($r['code'])->toBe(0)
        ->and($fiche)->not->toBeNull()
        ->and($fiche->entity_nature)->toBe('media')
        ->and($fiche->relation_type)->toBe('presse_media')
        ->and($fiche->email_generic)->toBe('redaction@zz-quotidien.example.invalid')
        ->and($fiche->department_code)->toBe('69')
        ->and($media->name)->toBe('ZZ Quotidien fictif')
        ->and($media->media_type)->toBe('presse_quotidien')
        ->and($media->diffusion_zone)->toBe('régional')
        ->and($media->source)->toBe('liste-presse')
        ->and($media->harmonise_le)->not->toBeNull()
        ->and($contact->last_name)->toBe('ZZREDACTRICE')
        ->and($contact->role)->toBe('Rédactrice en chef')
        ->and($contact->email)->toBe('zoe.zz@zz-quotidien.example.invalid')
        ->and($contact->linkedin_url)->toBe('https://www.linkedin.com/in/zz-fictif')
        ->and($contact->legal_basis)->toBe('legitimate_interest_b2b')
        ->and(json_decode($contact->metadata, true))->toMatchArray(['rubrique' => 'Économie', 'email_type' => 'nominatif'])
        ->and(piSlugs((int) $fiche->id))->toContain(FichesProtegees::TAG_PRESSE, 'media-type:presse-quotidienne', 'media-zone:regional', 'media-theme:economie', 'nature-media', 'dept-69')
        ->and(piCompteur($r['sortie'], 'fiches_creees'))->toBe(1)
        ->and(piCompteur($r['sortie'], 'medias_crees'))->toBe(1)
        ->and(piCompteur($r['sortie'], 'contacts_crees'))->toBe(1);
});

test('deux lignes du meme media : UNE fiche, UNE ligne media, deux contacts ; rejouer le fichier ne cree rien', function () {
    $lignes = [
        piLigne(),
        piLigne(['journaliste' => ['prenom' => 'Max', 'nom' => 'ZZREPORTER', 'fonction' => 'Reporter', 'rubrique' => 'Sport', 'email' => null]]),
    ];

    piImporter($lignes);
    $volumes = piVolumes();
    $r2 = piImporter($lignes);

    $fiche = (int) DB::table('companies')->where('foreign_id', 'presse:zz:quotidien-1')->value('id');
    expect(DB::table('media')->where('company_id', $fiche)->count())->toBe(1)
        ->and(DB::table('contacts')->where('company_id', $fiche)->count())->toBe(2)
        ->and(piVolumes())->toBe($volumes)
        ->and(piCompteur($r2['sortie'], 'fiches_creees'))->toBe(0)
        ->and(piCompteur($r2['sortie'], 'contacts_crees'))->toBe(0);
});

test('lignes invalides : rejetees et comptees par motif, sans aucune valeur en sortie ; le temoin entre', function () {
    $r = piImporter([
        '{pas du json',
        piLigne(['inconnue' => 'x']),
        piLigne(['type' => 'production_audiovisuelle']),
        piLigne(['type' => 'journal_fictif']),
        piLigne(['zone' => 'inconnue']),
        piLigne(['identifiant' => 'evt:zz:1']),
        piLigne(['identifiant' => null, 'siren' => '12345']),
        piLigne(['identifiant' => null]),
        piLigne(['nom' => '  ']),
        piLigne(['journaliste' => ['prenom' => 'Sans', 'nom' => null]]),
        piLigne(['identifiant' => 'presse:zz:temoin', 'nom' => 'ZZ Temoin valide']),
    ]);

    expect($r['code'])->toBe(0)
        ->and(piCompteur($r['sortie'], 'lignes'))->toBe(11)
        ->and(piCompteur($r['sortie'], 'rejetees'))->toBe(10)
        ->and($r['sortie'])->toContain('json_invalide : 1')
        ->and($r['sortie'])->toContain('cle_inconnue : 1')
        ->and($r['sortie'])->toContain('type_inconnu : 2')
        ->and($r['sortie'])->toContain('zone_inconnue : 1')
        ->and($r['sortie'])->toContain('identifiant_invalide : 1')
        ->and($r['sortie'])->toContain('siren_invalide : 1')
        ->and($r['sortie'])->toContain('siren_ou_identifiant_manquant : 1')
        ->and($r['sortie'])->toContain('champ_obligatoire_manquant : 1')
        ->and($r['sortie'])->toContain('journaliste_sans_nom : 1')
        ->and($r['sortie'])->not->toContain('ZZREDACTRICE')
        ->and($r['sortie'])->not->toContain('zz-quotidien')
        ->and(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('companies')->where('foreign_id', 'presse:zz:temoin')->exists())->toBeTrue();
});

test('une fiche existante (SIREN) est rattachee : relation client gardee, media existant COMPLETE sans rien ecraser', function () {
    $fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000777', 'denomination' => 'ZZ EDITIONS FICTIVES',
        'entity_nature' => 'entreprise', 'relation_type' => 'client', 'lifecycle_stage' => 'client',
        'email_generic' => 'contact@zz-editions.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $media = (int) DB::table('media')->insertGetId([
        'workspace_id' => $this->espace, 'company_id' => $fiche, 'name' => 'ZZ QUOTIDIEN FICTIF', 'media_type' => 'presse_journal',
        'media_family' => 'editorial', 'source' => 'naf-extract', 'enrich_status' => 'pending', 'website' => 'https://zz-ancien.example.invalid',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = piImporter([piLigne(['identifiant' => null, 'siren' => '900000777'])]);

    $f = DB::table('companies')->where('id', $fiche)->first();
    $m = DB::table('media')->where('id', $media)->first();
    expect($r['code'])->toBe(0)
        ->and($f->relation_type)->toBe('client')
        ->and($f->entity_nature)->toBe('media')
        ->and($f->email_generic)->toBe('contact@zz-editions.example.invalid')
        ->and(DB::table('media')->where('company_id', $fiche)->count())->toBe(1)
        // Le type et le site connus restent ; la zone et le département manquants sont posés.
        ->and($m->media_type)->toBe('presse_journal')
        ->and($m->website)->toBe('https://zz-ancien.example.invalid')
        ->and($m->diffusion_zone)->toBe('régional')
        ->and($m->department_code)->toBe('69')
        ->and(piCompteur($r['sortie'], 'fiches_rattachees'))->toBe(1)
        ->and(piCompteur($r['sortie'], 'medias_completes'))->toBe(1)
        ->and(piCompteur($r['sortie'], 'relations_conservees'))->toBe(1);
});

test('une personne retiree ne revient pas, une fiche a la corbeille n est pas ressuscitee ; le temoin entre', function () {
    piImporter([piLigne()]);
    $fiche = (int) DB::table('companies')->where('foreign_id', 'presse:zz:quotidien-1')->value('id');
    DB::table('contacts')->where('company_id', $fiche)->where('last_name', 'ZZREDACTRICE')->delete();
    expect(DB::table('contacts_retires')->where('foreign_id', 'presse:zz:quotidien-1')->count())->toBe(1);

    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'country_code' => 'FR', 'foreign_id' => 'presse:zz:corbeille', 'denomination' => 'ZZ CORBEILLE',
        'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Le contenu CHANGE (nouveau téléphone) : ce n'est pas l'idempotence qui l'écarte.
    $r = piImporter([
        piLigne(['telephone' => '04 00 00 00 02']),
        piLigne(['identifiant' => 'presse:zz:corbeille', 'nom' => 'ZZ Corbeille']),
        piLigne(['journaliste' => ['prenom' => 'Tim', 'nom' => 'ZZTEMOIN']]),
    ]);

    expect(DB::table('contacts')->where('last_name', 'ZZREDACTRICE')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $fiche)->where('last_name', 'ZZTEMOIN')->exists())->toBeTrue()
        ->and(piCompteur($r['sortie'], 'personnes_retirees_ignorees'))->toBe(1)
        ->and($r['sortie'])->toContain('fiche_a_la_corbeille : 1')
        ->and(DB::table('companies')->where('foreign_id', 'presse:zz:corbeille')->whereNull('deleted_at')->exists())->toBeFalse();
});

test('une adresse de redaction grand public ou OPPOSEE ne devient ni celle de la fiche ni celle du media', function () {
    DB::table('opt_out')->insert([
        'scope' => 'business',
        'email_hash' => ListeSuppression::empreinte('redaction@zz-oppose.example.invalid'),
        'source' => 'test', 'created_at' => now(),
    ]);

    $r = piImporter([
        piLigne(['identifiant' => 'presse:zz:gp', 'email_redaction' => 'zz.fictif@gmail.com', 'journaliste' => null]),
        piLigne(['identifiant' => 'presse:zz:op', 'email_redaction' => 'redaction@zz-oppose.example.invalid', 'journaliste' => null]),
    ]);

    foreach (['presse:zz:gp', 'presse:zz:op'] as $id) {
        $fiche = (int) DB::table('companies')->where('foreign_id', $id)->value('id');
        expect(DB::table('companies')->where('id', $fiche)->value('email_generic'))->toBeNull()
            ->and(DB::table('media')->where('company_id', $fiche)->value('email'))->toBeNull();
    }
    expect(piCompteur($r['sortie'], 'emails_redaction_non_poses'))->toBe(2);
});

test('a blanc : rien n est ecrit, et les compteurs sont ceux du reel', function () {
    $lignes = [piLigne(), piLigne(['identifiant' => 'presse:zz:radio', 'nom' => 'ZZ Radio', 'type' => 'radio', 'journaliste' => null])];
    $avant = piVolumes();

    $blanc = piImporter($lignes, ['--dry-run' => true, '--paquet' => 1]);
    expect($blanc['code'])->toBe(0)
        ->and($blanc['sortie'])->toContain('[À BLANC]')
        ->and(piVolumes())->toBe($avant);

    $reel = piImporter($lignes, ['--paquet' => 1]);
    foreach (['lignes', 'paquets', 'fiches_creees', 'medias_crees', 'contacts_crees', 'natures_posees', 'relations_posees'] as $cle) {
        expect(piCompteur($blanc['sortie'], $cle))->toBe(piCompteur($reel['sortie'], $cle), "compteur {$cle}");
    }
    expect(piCompteur($reel['sortie'], 'paquets'))->toBe(2)
        ->and(DB::table('companies')->count())->toBe($avant['companies'] + 2);
});

test('options et fichier controles ; --compteurs-seulement ne cite aucune valeur', function () {
    expect(Artisan::call('crm:presse:importer', ['file' => sys_get_temp_dir() . '/zz-absent-' . Str::random(8)]))->toBe(1)
        ->and(piImporter([piLigne()], ['--paquet' => 0])['code'])->toBe(1)
        ->and(piImporter([piLigne()], ['--limite' => 'x'])['code'])->toBe(1);

    $r = piImporter([piLigne(['type' => 'inconnu'])], ['--compteurs-seulement' => true, '-v' => true]);
    expect($r['sortie'])->not->toContain('ligne 1')
        ->and($r['sortie'])->not->toContain('ZZ Quotidien');

    // La source est la même que l'harmonisation : un seul tag, une seule protection.
    expect(QualificationPresse::SOURCE)->toBe('presse-2026');
});

test('porte d acces : l adresse n est posee QUE si la porte vaut email_redaction ; sans porte ou autre porte, le contact nait sans adresse', function () {
    $j = static fn (string $nom, ?string $acces): array => array_filter([
        'prenom' => 'Zed', 'nom' => $nom, 'email' => strtolower($nom) . '@zz-quotidien.example.invalid', 'acces' => $acces,
    ], static fn ($v): bool => $v !== null);

    $r = piImporter([
        piLigne(['journaliste' => $j('ZZSANSPORTE', null)]),
        piLigne(['journaliste' => $j('ZZLINKEDIN', 'linkedin_direct')]),
        piLigne(['journaliste' => $j('ZZAQUALIFIER', 'a_qualifier')]),
        piLigne(['journaliste' => $j('ZZREDACTION', 'email_redaction')]),
        piLigne(['journaliste' => $j('ZZPORTEINVENTEE', 'pigeon_voyageur')]),
    ]);

    $email = static fn (string $nom): mixed => DB::table('contacts')->where('last_name', $nom)->value('email');
    expect($email('ZZSANSPORTE'))->toBeNull()
        ->and($email('ZZLINKEDIN'))->toBeNull()
        ->and($email('ZZAQUALIFIER'))->toBeNull()
        ->and(DB::table('contacts')->where('last_name', 'ZZSANSPORTE')->exists())->toBeTrue()
        ->and($email('ZZREDACTION'))->toBe('zzredaction@zz-quotidien.example.invalid')
        ->and(json_decode((string) DB::table('contacts')->where('last_name', 'ZZLINKEDIN')->value('metadata'), true)['acces'])->toBe('linkedin_direct')
        ->and(piCompteur($r['sortie'], 'emails_journalistes_retenus_par_acces'))->toBe(3)
        ->and($r['sortie'])->toContain('acces_inconnu : 1')
        ->and(DB::table('contacts')->where('last_name', 'ZZPORTEINVENTEE')->exists())->toBeFalse();
});

test('un journaliste OPPOSE ou EFFACE dans la console n est jamais recree : par nom et media, ou par adresse ; le temoin entre', function () {
    $media = (int) DB::table('media')->insertGetId([
        // Autre type que la ligne importée : pas de rapprochement de titre, la
        // ligne crée sa fiche ; l'opposition se lit par le NOM du média.
        'workspace_id' => $this->espace, 'name' => 'ZZ Quotidien fictif', 'media_type' => 'presse_hebdo',
        'media_family' => 'editorial', 'source' => 'cppap', 'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ligne = static fn (array $v): array => $v + ['workspace_id' => test()->espace, 'source' => 'wikidata', 'created_at' => now(), 'updated_at' => now()];
    // Opposée, même nom (accents et casse près), même média.
    DB::table('journalists')->insert($ligne(['media_id' => $media, 'first_name' => 'Zoé', 'last_name' => 'zzredactrice', 'opt_out' => true]));
    // Effacée (corbeille, coordonnées vidées), même nom, même média.
    DB::table('journalists')->insert($ligne(['media_id' => $media, 'first_name' => 'Eva', 'last_name' => 'ZZEFFACEE', 'opt_out' => true, 'deleted_at' => now()]));
    // Opposée, autre nom, mais même adresse.
    DB::table('journalists')->insert($ligne(['first_name' => 'Ali', 'last_name' => 'ZZAILLEURS', 'email' => 'ali.zz@zz-quotidien.example.invalid', 'opt_out' => true]));
    $opposes = DB::table('journalists')->where('opt_out', true)->count();

    $r = piImporter([
        piLigne(),
        piLigne(['journaliste' => ['prenom' => 'Eva', 'nom' => 'ZZEFFACEE']]),
        piLigne(['journaliste' => ['prenom' => 'Alain', 'nom' => 'ZZAUTRENOM', 'email' => 'ali.zz@zz-quotidien.example.invalid', 'acces' => 'email_redaction']]),
        piLigne(['journaliste' => ['prenom' => 'Tim', 'nom' => 'ZZTEMOIN']]),
    ]);

    expect(DB::table('contacts')->whereIn('last_name', ['ZZREDACTRICE', 'ZZEFFACEE', 'ZZAUTRENOM'])->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('last_name', 'ZZTEMOIN')->exists())->toBeTrue()
        ->and(piCompteur($r['sortie'], 'journalistes_opposes'))->toBe(3)
        // Ni réactivée, ni touchée : la console garde son opposition.
        ->and(DB::table('journalists')->where('opt_out', true)->count())->toBe($opposes);
});

test('source DECLARATIVE : une fiche existante hors presse est rejetee intacte ; une fiche de presse existante ne recoit pas presse_media ; une fiche creee, si', function () {
    $ordinaire = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000881', 'denomination' => 'ZZ PROSPECT ORDINAIRE',
        'entity_nature' => 'entreprise', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $presse = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000882', 'denomination' => 'ZZ EDITEUR PAS ENCORE HARMONISE',
        'entity_nature' => 'entreprise', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('media')->insert([
        'workspace_id' => $this->espace, 'company_id' => $presse, 'name' => 'ZZ Titre existant', 'media_type' => 'presse_journal',
        'media_family' => 'editorial', 'source' => 'naf-extract', 'enrich_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = piImporter([
        piLigne(['identifiant' => null, 'siren' => '900000881', 'nom' => 'ZZ Pretendu media']),
        piLigne(['identifiant' => null, 'siren' => '900000882', 'nom' => 'ZZ Titre existant', 'journaliste' => null]),
        piLigne(['identifiant' => 'presse:zz:nouveau', 'nom' => 'ZZ Nouveau titre', 'journaliste' => null]),
    ]);

    expect($r['sortie'])->toContain('fiche_existante_hors_presse : 1')
        ->and(DB::table('companies')->where('id', $ordinaire)->value('relation_type'))->toBe('prospect')
        ->and(piSlugs($ordinaire))->not->toContain(FichesProtegees::TAG_PRESSE)
        ->and(DB::table('contacts')->where('company_id', $ordinaire)->exists())->toBeFalse()
        ->and(DB::table('media')->where('company_id', $ordinaire)->exists())->toBeFalse()
        // Fiche de presse existante : la relation déclarée n'est pas imposée.
        ->and(DB::table('companies')->where('id', $presse)->value('relation_type'))->toBe('prospect')
        ->and(DB::table('companies')->where('id', $presse)->value('lifecycle_stage'))->toBe('nouveau')
        // Fiche créée par la ligne : presse.
        ->and(DB::table('companies')->where('foreign_id', 'presse:zz:nouveau')->value('relation_type'))->toBe('presse_media');
});

test('🔴 rattrapage : rejouer un fichier DÉJÀ importé pose la provenance « liste presse » des adresses de rédaction, sans rien créer d autre', function () {
    $lignes = [piLigne(), piLigne(['identifiant' => 'presse:zz:hebdo-2', 'nom' => 'ZZ Hebdo fictif', 'type' => 'presse_hebdo',
        'site' => null, 'email_redaction' => 'redaction@zz-hebdo.example.invalid', 'journaliste' => null])];
    piImporter($lignes);
    // L'état des fiches importées AVANT la règle de provenance : pas de trace.
    DB::statement("UPDATE companies SET metadata = metadata - 'emails_liste_presse'");
    $volumes = piVolumes();

    $r = piImporter($lignes);

    $listes = DB::table('companies')->whereIn('foreign_id', ['presse:zz:quotidien-1', 'presse:zz:hebdo-2'])->orderBy('foreign_id')
        ->pluck('metadata')->map(static fn ($m): mixed => json_decode((string) $m, true)['emails_liste_presse'] ?? null)->all();
    expect($r['code'])->toBe(0)
        ->and($listes)->toBe([['redaction@zz-hebdo.example.invalid'], ['redaction@zz-quotidien.example.invalid']])
        ->and(piCompteur($r['sortie'], 'provenances_liste_retenues'))->toBe(2)
        ->and(piVolumes())->toBe($volumes)
        ->and(piCompteur($r['sortie'], 'fiches_creees'))->toBe(0)
        ->and(piCompteur($r['sortie'], 'contacts_crees'))->toBe(0);

    // Idempotent : un troisième passage ne pose plus rien.
    $r3 = piImporter($lignes);
    expect(piCompteur($r3['sortie'], 'provenances_liste_retenues'))->toBe(0)
        ->and(piVolumes())->toBe($volumes);
});

test('🔴 A09 — --provenance-seulement : ne pose QUE la provenance « liste presse », ne modifie rien d autre, ne crée rien', function () {
    $lignes = [piLigne()];
    piImporter($lignes);
    DB::statement("UPDATE companies SET metadata = metadata - 'emails_liste_presse'");
    // Un trou que le rejeu ORDINAIRE comblerait : le rattrapage, non.
    DB::table('media')->update(['phone' => null]);
    $instantane = static fn (): array => [
        'companies' => DB::table('companies')->orderBy('id')->get()->map(static function ($c): array {
            $c = (array) $c;
            $meta = json_decode((string) $c['metadata'], true);
            unset($meta['emails_liste_presse'], $c['metadata']);

            return $c + ['meta' => $meta];
        })->all(),
        'media' => DB::table('media')->orderBy('id')->get()->map(static fn ($m): array => (array) $m)->all(),
        'contacts' => DB::table('contacts')->orderBy('id')->get()->map(static fn ($m): array => (array) $m)->all(),
        'company_tag' => DB::table('company_tag')->orderBy('company_id')->orderBy('tag_id')->get()->map(static fn ($m): array => (array) $m)->all(),
        'volumes' => piVolumes(),
    ];
    $avant = $instantane();

    $r = piImporter([...$lignes, piLigne(['identifiant' => 'presse:zz:jamais-importe', 'nom' => 'ZZ Jamais importe'])], ['--provenance-seulement' => true]);

    $meta = json_decode((string) DB::table('companies')->where('foreign_id', 'presse:zz:quotidien-1')->value('metadata'), true);
    expect($r['code'])->toBe(0)
        ->and($meta['emails_liste_presse'] ?? null)->toBe(['redaction@zz-quotidien.example.invalid'])
        ->and(piCompteur($r['sortie'], 'provenances_liste_retenues'))->toBe(1)
        ->and(piCompteur($r['sortie'], 'rejetees'))->toBe(1)
        ->and($instantane())->toBe($avant);
});

test('🔴 A09 — --provenance-seulement : une ancre qui désigne une fiche À LA CORBEILLE rejette la ligne, sans repli sur le rapprochement', function () {
    piImporter([piLigne()]);
    $fiche = (int) DB::table('companies')->where('foreign_id', 'presse:zz:quotidien-1')->value('id');
    DB::statement("UPDATE companies SET metadata = metadata - 'emails_liste_presse', deleted_at = now() WHERE id = ?", [$fiche]);
    $volumes = piVolumes();

    $r = piImporter([piLigne()], ['--provenance-seulement' => true]);

    $meta = json_decode((string) DB::table('companies')->where('id', $fiche)->value('metadata'), true);
    // Toutes les lignes rejetées : la commande échoue (règle commune de l'import).
    expect($r['code'])->toBe(1)
        ->and($r['sortie'])->toContain('fiche_a_la_corbeille')
        ->and(piCompteur($r['sortie'], 'rejetees'))->toBe(1)
        ->and(piCompteur($r['sortie'], 'provenances_liste_retenues'))->toBe(0)
        ->and($meta['emails_liste_presse'] ?? null)->toBeNull()
        ->and(piVolumes())->toBe($volumes);
});
