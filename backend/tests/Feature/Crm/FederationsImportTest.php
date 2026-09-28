<?php

/**
 * IMPORT DES FÉDÉRATIONS (chantier 3, 2026-09-29) — `crm:import-federations`.
 *
 * Chaque garde prouvée par son EFFET, face à un témoin quand l'absence d'effet
 * pourrait passer pour une réussite. Fixtures FICTIVES uniquement : le dépôt
 * est PUBLIC (SIREN en 9xxxxxxxx, domaines `.example.invalid`, noms « ZZ »).
 */

use App\Crm\FichesProtegees;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'crm.scrape_funnel.validate_mx' => false,
        'crm.ingest.business_workspace' => 'axion-ia',
    ]);

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
    foreach ($GLOBALS['zz_fed_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['zz_fed_fichiers'] = [];
});

/**
 * Une ligne d'import FICTIVE.
 *
 * @param  array<string, mixed>  $surcharge
 * @return array<string, mixed>
 */
function fedLigne(array $surcharge = []): array
{
    return array_replace([
        'siren' => '900000001',
        'nom' => 'ZZ FEDERATION FICTIVE DU BATIMENT',
        'nom_developpe' => null,
        'sigle' => 'ZZFFB',
        'nature' => 'federation',
        'naf' => '94.11Z',
        'forme_juridique' => '9220',
        'effectif' => '11',
        'date_creation' => '1990-01-01',
        'nb_etablissements' => 2,
        'adresse' => '1 RUE FICTIVE 75001 PARIS',
        'code_postal' => '75001',
        'commune' => 'PARIS',
        'departement' => '75',
        'region' => '11',
        'famille' => 'federation_syndicat_pro',
        'niveau' => 'national',
        'secteurs' => ['btp'],
        'tailles_adherents' => ['tpe', 'pme'],
        'certitude' => 'haute',
        'pertinence' => 'haute',
        'contactabilite' => 'email_verifie',
        'origine_classement' => 'examen',
        'email_generique' => 'contact@zz-fede.example.invalid',
        'emails_autres' => [],
        'telephone' => null,
        'telephones_autres' => [],
        'site' => 'https://zz-fede.example.invalid',
        'linkedin' => null,
        'personnes' => [['prenom' => 'Zoe', 'nom' => 'ZZPRESIDENTE', 'fonction' => 'Présidente', 'email' => null, 'linkedin' => null]],
        'tete_de_reseau' => null,
    ], $surcharge);
}

/** @param  list<array<string, mixed>|string>  $lignes */
function fedFichier(array $lignes): string
{
    $chemin = (string) tempnam(sys_get_temp_dir(), 'zz-fed-');
    $GLOBALS['zz_fed_fichiers'][] = $chemin;
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
function fedImporter(array $lignes, array $options = []): array
{
    $code = Artisan::call('crm:import-federations', ['file' => fedFichier($lignes)] + $options);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function fedCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

/** Le tableau du bilan, sans la ligne d'en-tête qui dit « à blanc » ou non. */
function fedBilan(string $sortie): string
{
    preg_match_all('/^\|.*\|$/m', $sortie, $m);

    return implode("\n", $m[0]);
}

function fedFiche(string $siren): ?object
{
    return DB::table('companies')->where('siren', $siren)->first();
}

/** @return list<string> */
function fedSlugs(int $companyId): array
{
    return DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->orderBy('tags.slug')->pluck('tags.slug')->all();
}

/** @return array<string, int> */
function fedVolumes(): array
{
    $v = [];
    foreach (['companies', 'federations', 'contacts', 'tags', 'company_tag', 'activities', 'scraper_runs', 'business_events', 'opt_out'] as $t) {
        $v[$t] = DB::table($t)->count();
    }

    return $v;
}

test('l import cree la fiche protegee, sa ligne federations, ses contacts (art. 14 a faire) et ses etiquettes', function () {
    $r = fedImporter([fedLigne()]);
    expect($r['code'])->toBe(0);

    $fiche = fedFiche('900000001');
    expect($fiche)->not->toBeNull()
        ->and($fiche->entity_nature)->toBe('federation')
        ->and($fiche->sector_main)->toBe('btp')
        ->and($fiche->naf)->toBe('94.11Z')
        ->and($fiche->legal_form)->toBe('9220')
        ->and($fiche->region_code)->toBe('11')
        ->and($fiche->department_code)->toBe('75')
        ->and($fiche->email_generic)->toBe('contact@zz-fede.example.invalid');

    $f = DB::table('federations')->where('company_id', $fiche->id)->first();
    expect($f->famille)->toBe('federation_syndicat_pro')
        ->and($f->niveau)->toBe('national')
        ->and($f->secteurs)->toBe('{btp}')
        ->and($f->tailles_adherents)->toBe('{tpe,pme}')
        ->and($f->partenariat)->toBe('aucun')
        ->and($f->sigle)->toBe('ZZFFB');

    $contact = DB::table('contacts')->where('company_id', $fiche->id)->first();
    expect($contact->last_name)->toBe('ZZPRESIDENTE')
        ->and($contact->role)->toBe('Présidente')
        ->and($contact->first_info_at)->toBeNull()
        ->and($contact->legal_basis)->toBe('legitimate_interest_b2b');

    expect(fedSlugs((int) $fiche->id))->toContain(
        FichesProtegees::TAG_FEDERATIONS,
        'famille:federation-syndicat-pro',
        'niveau:national',
        'secteur:btp',
        'taille-adherents:tpe',
        'taille-adherents:pme',
        'pertinence:haute',
        'contactabilite:email-verifie',
    );
    expect((bool) DB::table('tags')->where('slug', FichesProtegees::TAG_FEDERATIONS)->value('is_locked'))->toBeTrue()
        ->and(FichesProtegees::estProtegee((int) $fiche->id))->toBeTrue();
});

test('l essai a blanc n ecrit rien et annonce exactement le bilan de l import reel', function () {
    $lignes = [
        // L'antenne AVANT sa tête : la deuxième passe doit la relier quand même,
        // y compris à blanc (les fiches de la première passe y sont visibles).
        fedLigne(['siren' => '900000002', 'nom' => 'ZZ FEDERATION FICTIVE RHONE', 'niveau' => 'departemental', 'tete_de_reseau' => '900000001', 'email_generique' => null]),
        fedLigne(),
        fedLigne(['siren' => 'pas-un-siren']),
    ];
    $avant = fedVolumes();

    $blanc = fedImporter($lignes, ['--dry-run' => true]);

    expect(fedVolumes())->toBe($avant)
        ->and($blanc['sortie'])->toContain('[À BLANC]')
        ->and(fedCompteur($blanc['sortie'], 'fiches_creees'))->toBe(2)
        ->and(fedCompteur($blanc['sortie'], 'tetes_liees'))->toBe(1)
        ->and(fedCompteur($blanc['sortie'], 'contacts_crees'))->toBe(2)
        ->and(fedCompteur($blanc['sortie'], 'rejetees'))->toBe(1);

    $reel = fedImporter($lignes);

    expect(fedBilan($reel['sortie']))->toBe(fedBilan($blanc['sortie']))
        ->and(DB::table('federations')->where('company_id', fedFiche('900000002')->id)->value('parent_company_id'))
        ->toBe((int) fedFiche('900000001')->id);
});

test('l import est idempotent : rejouer le meme fichier ne cree ni ne change rien', function () {
    $lignes = [fedLigne(), fedLigne(['siren' => '900000002', 'nom' => 'ZZ ANTENNE', 'niveau' => 'regional', 'tete_de_reseau' => '900000001'])];
    fedImporter($lignes);
    $apresPremier = fedVolumes();

    $r = fedImporter($lignes);

    expect(fedVolumes())->toBe($apresPremier)
        ->and(fedCompteur($r['sortie'], 'fiches_creees'))->toBe(0)
        ->and(fedCompteur($r['sortie'], 'federations_inchangees'))->toBe(2)
        ->and(fedCompteur($r['sortie'], 'contacts_crees'))->toBe(0)
        ->and(fedCompteur($r['sortie'], 'tetes_inchangees'))->toBe(1)
        ->and(fedCompteur($r['sortie'], 'tetes_liees'))->toBe(0);
});

test('une fiche deja presente (organisateur d evenement) est RATTACHEE, jamais dupliquee, sa demarche intacte', function () {
    $tagOrga = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAG_ORGANISATEURS, 'name' => 'Organisateurs',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $orga = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000001', 'entity_nature' => 'reseau',
        'denomination' => 'ZZ NOM DEJA CONNU', 'relation_type' => 'partenaire', 'lifecycle_stage' => 'qualifie',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('company_tag')->insert([
        'company_id' => $orga, 'tag_id' => $tagOrga, 'workspace_id' => $this->espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);
    $evenement = (int) DB::table('events')->insertGetId([
        'workspace_id' => $this->espace, 'external_ref' => 'zz-ag-fictive', 'nom' => 'ZZ Assemblée',
        'type' => 'conference', 'intervention' => 'acceptee', 'participation' => 'inscrit',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('event_organizers')->insert([
        'event_id' => $evenement, 'company_id' => $orga, 'workspace_id' => $this->espace, 'created_at' => now(),
    ]);

    $r = fedImporter([fedLigne()]);

    $fiche = DB::table('companies')->where('id', $orga)->first();
    expect(DB::table('companies')->where('siren', '900000001')->count())->toBe(1)
        ->and(fedCompteur($r['sortie'], 'fiches_rattachees'))->toBe(1)
        ->and(fedCompteur($r['sortie'], 'fiches_creees'))->toBe(0)
        ->and($fiche->entity_nature)->toBe('reseau')
        ->and($fiche->denomination)->toBe('ZZ NOM DEJA CONNU')
        ->and($fiche->relation_type)->toBe('partenaire')
        ->and($fiche->lifecycle_stage)->toBe('qualifie')
        ->and(DB::table('events')->where('id', $evenement)->value('intervention'))->toBe('acceptee')
        ->and(DB::table('events')->where('id', $evenement)->value('participation'))->toBe('inscrit')
        ->and(DB::table('federations')->where('company_id', $orga)->exists())->toBeTrue()
        ->and(fedSlugs($orga))->toContain(FichesProtegees::TAG_ORGANISATEURS, FichesProtegees::TAG_FEDERATIONS);
});

test('une fiche a la corbeille n est pas ressuscitee, le temoin est importe', function () {
    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'siren' => '900000001', 'denomination' => 'ZZ CORBEILLE',
        'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = fedImporter([fedLigne(), fedLigne(['siren' => '900000003', 'nom' => 'ZZ TEMOIN'])]);

    expect($r['sortie'])->toContain('fiche_a_la_corbeille : 1')
        ->and(fedFiche('900000001')->deleted_at)->not->toBeNull()
        ->and(DB::table('federations')->where('company_id', fedFiche('900000001')->id)->exists())->toBeFalse()
        ->and(DB::table('federations')->where('company_id', fedFiche('900000003')->id)->exists())->toBeTrue();
});

test('la liste d opposition s applique a l import : e-mail generique, telephone, personne ; le temoin entre', function () {
    foreach ([['bureau@zz-oppose.example.invalid', null], [null, '0600000009'], ['zz.personne@zz-oppose.example.invalid', null]] as [$email, $tel]) {
        DB::table('opt_out')->insert([
            'email' => null, 'email_hash' => $email !== null ? hash('sha256', $email) : null, 'phone' => $tel,
            'scope' => 'business', 'source' => 'test', 'created_at' => now(),
        ]);
    }

    $r = fedImporter([
        fedLigne([
            'siren' => '900000004', 'email_generique' => 'bureau@zz-oppose.example.invalid', 'telephone' => '+33 6 00 00 00 09',
            'personnes' => [['prenom' => 'Zed', 'nom' => 'ZZOPPOSE', 'fonction' => 'Président', 'email' => 'zz.personne@zz-oppose.example.invalid', 'linkedin' => null]],
        ]),
        fedLigne(['siren' => '900000005', 'email_generique' => 'bureau@zz-temoin.example.invalid', 'telephone' => '06 00 00 00 08']),
    ]);

    $opposee = fedFiche('900000004');
    $temoin = fedFiche('900000005');
    expect($opposee->email_generic)->toBeNull()
        ->and($opposee->phone)->toBeNull()
        ->and(DB::table('contacts')->where('company_id', $opposee->id)->count())->toBe(0)
        ->and(fedCompteur($r['sortie'], 'personnes_opposees'))->toBe(1)
        ->and($temoin->email_generic)->toBe('bureau@zz-temoin.example.invalid')
        ->and($temoin->phone)->toBe('06 00 00 00 08')
        ->and(DB::table('contacts')->where('company_id', $temoin->id)->count())->toBe(1);
});

test('tetes de reseau : liees en deuxieme passe, tete absente comptee, boucle refusee sans casser l import', function () {
    $r = fedImporter([
        fedLigne(['siren' => '900000002', 'nom' => 'ZZ ANTENNE A', 'niveau' => 'departemental', 'tete_de_reseau' => '900000001']),
        fedLigne(),
        fedLigne(['siren' => '900000006', 'nom' => 'ZZ ANTENNE ORPHELINE', 'niveau' => 'local', 'tete_de_reseau' => '900000099']),
    ]);
    expect(fedCompteur($r['sortie'], 'tetes_liees'))->toBe(1)
        ->and(fedCompteur($r['sortie'], 'tetes_introuvables'))->toBe(1);

    // La tête nationale déclarée antenne de sa propre antenne : A → B → A.
    $boucle = fedImporter([fedLigne(['tete_de_reseau' => '900000002'])]);

    expect($boucle['code'])->toBe(0)
        ->and(fedCompteur($boucle['sortie'], 'tetes_refusees_cycle'))->toBe(1)
        ->and(DB::table('federations')->where('company_id', fedFiche('900000001')->id)->value('parent_company_id'))->toBeNull()
        ->and(DB::table('federations')->where('company_id', fedFiche('900000002')->id)->value('parent_company_id'))->toBe((int) fedFiche('900000001')->id);
});

test('re-import : la demarche partenariat n est jamais touchee ; les etiquettes suivent ; src, verrouillees et manuelles restent', function () {
    fedImporter([fedLigne()]);
    $id = (int) fedFiche('900000001')->id;
    DB::table('federations')->where('company_id', $id)->update([
        'partenariat' => 'accepte', 'partenariat_note' => 'ZZ note', 'partenariat_relance_at' => now()->addDays(3),
    ]);
    $manuelle = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => 'famille:ordre', 'name' => 'Posée à la main',
        'category' => 'custom', 'kind' => 'manual', 'rules' => '{}', 'is_locked' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $verrouillee = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => 'pertinence:faible', 'name' => 'Verrouillée',
        'category' => 'custom', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([$manuelle, $verrouillee] as $tag) {
        DB::table('company_tag')->insert([
            'company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $this->espace,
            'assigned_at' => now(), 'assigned_by' => $tag === $manuelle ? 'user' : 'auto-rule',
        ]);
    }

    $r = fedImporter([fedLigne(['niveau' => 'regional', 'pertinence' => 'moyenne', 'secteurs' => ['btp', 'immobilier']])]);

    $f = DB::table('federations')->where('company_id', $id)->first();
    $slugs = fedSlugs($id);
    expect(fedCompteur($r['sortie'], 'federations_mises_a_jour'))->toBe(1)
        ->and($f->partenariat)->toBe('accepte')
        ->and($f->partenariat_note)->toBe('ZZ note')
        ->and($f->partenariat_relance_at)->not->toBeNull()
        ->and($f->niveau)->toBe('regional')
        ->and($slugs)->toContain('niveau:regional', 'pertinence:moyenne', 'secteur:immobilier')
        ->and($slugs)->not->toContain('niveau:national')
        ->and($slugs)->not->toContain('pertinence:haute')
        // Jamais retirées : la manuelle, la verrouillée, la provenance.
        ->and($slugs)->toContain('famille:ordre', 'pertinence:faible', FichesProtegees::TAG_FEDERATIONS);
});

test('le secteur represente remplace un secteur vide ou non classe, jamais un secteur utile', function () {
    DB::table('companies')->insert([
        ['workspace_id' => $this->espace, 'siren' => '900000007', 'denomination' => 'ZZ NON CLASSE', 'sector_main' => 'non_classe', 'created_at' => now(), 'updated_at' => now()],
        ['workspace_id' => $this->espace, 'siren' => '900000008', 'denomination' => 'ZZ AGRICOLE', 'sector_main' => 'agriculture', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $r = fedImporter([
        fedLigne(['siren' => '900000007', 'secteurs' => ['sante']]),
        fedLigne(['siren' => '900000008', 'secteurs' => ['interprofessionnel']]),
    ]);

    expect(fedFiche('900000007')->sector_main)->toBe('sante')
        ->and(fedFiche('900000008')->sector_main)->toBe('agriculture')
        ->and(fedCompteur($r['sortie'], 'secteurs_poses'))->toBe(1)
        ->and(fedCompteur($r['sortie'], 'secteurs_conserves'))->toBe(1);
});

test('les lignes invalides sont rejetees par motif, et la sortie ne cite aucune valeur de ligne', function () {
    $r = fedImporter([
        fedLigne(['siren' => '900000010', 'inconnue' => 'x']),
        fedLigne(['siren' => '900000011', 'famille' => 'club_de_foot']),
        fedLigne(['siren' => '900000012', 'secteurs' => ['btp', 'sante', 'droit', 'industrie']]),
        fedLigne(['siren' => '900000013', 'secteurs' => ['non_classe']]),
        fedLigne(['siren' => '900000014', 'tete_de_reseau' => '900000014']),
        fedLigne(['siren' => '900000015', 'tailles_adherents' => ['micro']]),
        '{pas du json',
        fedLigne(['siren' => '900000016']),
    ]);

    expect(fedCompteur($r['sortie'], 'rejetees'))->toBe(7)
        ->and($r['sortie'])->toContain('cle_inconnue : 1', 'famille_inconnue : 1', 'trop_de_secteurs : 1', 'secteur_inconnu : 1', 'tete_de_reseau_invalide : 1', 'taille_inconnue : 1', 'json_invalide : 1')
        ->and(DB::table('federations')->count())->toBe(1)
        // Aucune valeur nominative ni coordonnée dans la sortie (journaux).
        ->and($r['sortie'])->not->toContain('ZZPRESIDENTE')
        ->and($r['sortie'])->not->toContain('zz-fede.example.invalid')
        ->and($r['sortie'])->not->toContain('ZZ FEDERATION');
});

test('toutes les lignes rejetees : la commande echoue au lieu de passer pour un import reussi', function () {
    $r = fedImporter([fedLigne(['famille' => 'inconnue'])]);

    expect($r['code'])->toBe(1)->and($r['sortie'])->toContain('ÉCHEC');
});

test('--limite ne traite que les N premieres lignes (import par etapes)', function () {
    $r = fedImporter([fedLigne(), fedLigne(['siren' => '900000002', 'nom' => 'ZZ DEUX'])], ['--limite' => '1']);

    expect(fedCompteur($r['sortie'], 'lignes'))->toBe(1)
        ->and(fedFiche('900000001'))->not->toBeNull()
        ->and(fedFiche('900000002'))->toBeNull();
});

test('un departement d outre-mer hors schema est ignore sans rejeter la fiche', function () {
    $r = fedImporter([fedLigne(['departement' => '988', 'region' => null])]);

    expect($r['code'])->toBe(0)
        ->and(fedCompteur($r['sortie'], 'departements_ignores'))->toBe(1)
        ->and(fedFiche('900000001')->department_code)->toBeNull();
});
