<?php

/**
 * FÉDÉRATIONS SANS SIREN (2026-09-29) — `crm:import-federations` accepte les
 * organismes sans personnalité juridique propre (unions départementales,
 * conseils départementaux d'ordres, antennes de confédérations) : `"siren":
 * null` et un `identifiant` stable (`section:<réseau>:<code>`). Leur fiche
 * s'ancre sur (`country_code`, `foreign_id`), comme les organisateurs
 * d'événements sans SIREN.
 *
 * Chaque garde est prouvée par son EFFET, face à un TÉMOIN quand l'absence
 * d'effet pourrait passer pour une réussite. Et une ligne AVEC SIREN garde
 * EXACTEMENT son comportement : son `run_id` est celui que la commande
 * d'avant produisait (valeur relevée sur le code d'avant, pas recalculée ici).
 *
 * Fixtures FICTIVES uniquement : le dépôt est PUBLIC (SIREN en 9xxxxxxxx,
 * identifiants `section:zz-…`, domaines `.example.invalid`, noms « ZZ »).
 */

use App\Crm\FichesProtegees;
use App\Crm\Rgpd\EffacementCoordonneesFiches;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const FSS_ID = 'section:zz-reseau:69';

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
    foreach ($GLOBALS['zz_fss_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['zz_fss_fichiers'] = [];
});

/**
 * Une ligne d'organisme SANS SIREN, fictive.
 *
 * @param  array<string, mixed>  $surcharge
 * @return array<string, mixed>
 */
function fssLigne(array $surcharge = []): array
{
    return array_replace([
        'siren' => null,
        'identifiant' => FSS_ID,
        'nom' => 'ZZ UNION DEPARTEMENTALE FICTIVE',
        'famille' => 'confederation',
        'niveau' => 'departemental',
        'secteurs' => ['interprofessionnel'],
        'tailles_adherents' => ['tpe'],
        'pertinence' => 'haute',
        'contactabilite' => 'email_verifie',
        'departement' => '69',
        'email_generique' => 'contact@zz-ud69.example.invalid',
        'personnes' => [['prenom' => 'Zoe', 'nom' => 'ZZSECRETAIRE', 'fonction' => 'Secrétaire générale', 'email' => 'zoe.zzsecretaire@zz-ud69.example.invalid', 'linkedin' => null]],
        'tete_de_reseau' => null,
    ], $surcharge);
}

/**
 * @param  list<array<string, mixed>|string>  $lignes
 */
function fssFichier(array $lignes): string
{
    $chemin = (string) tempnam(sys_get_temp_dir(), 'zz-fss-');
    $GLOBALS['zz_fss_fichiers'][] = $chemin;
    file_put_contents($chemin, implode("\n", array_map(
        static fn ($l): string => is_string($l) ? $l : (string) json_encode($l, JSON_UNESCAPED_UNICODE),
        $lignes,
    )) . "\n");

    return $chemin;
}

/**
 * @param  list<array<string, mixed>|string>  $lignes
 * @return array{code: int, sortie: string}
 */
function fssImporter(array $lignes): array
{
    $code = Artisan::call('crm:import-federations', ['file' => fssFichier($lignes)]);

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function fssCompteur(string $sortie, string $cle): int
{
    preg_match('/\|\s*' . preg_quote($cle, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m);
    expect($m)->not->toBeEmpty("Compteur « {$cle} » absent de la sortie.");

    return (int) $m[1];
}

function fssFiche(string $identifiant, string $pays = 'FR'): ?object
{
    return DB::table('companies')->where('country_code', $pays)->where('foreign_id', $identifiant)->first();
}

/** @return list<string> */
function fssSlugs(int $companyId): array
{
    return DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $companyId)->orderBy('tags.slug')->pluck('tags.slug')->all();
}

/** @return array<string, int> */
function fssVolumes(): array
{
    $v = [];
    foreach (['companies', 'federations', 'contacts', 'tags', 'company_tag', 'activities', 'scraper_runs', 'contacts_retires'] as $t) {
        $v[$t] = DB::table($t)->count();
    }

    return $v;
}

/** @return list<array<string, mixed>> */
function fssDestinataires(): array
{
    // La liste ne retient que des adresses VÉRIFIÉES valides : on vérifie
    // d'abord, avec un DNS simulé où tout domaine reçoit.
    ResolveurDnsSimule::toutVerifier();
    $sortie = (string) tempnam(sys_get_temp_dir(), 'zz-fss-dest-');
    $GLOBALS['zz_fss_fichiers'][] = $sortie;
    Artisan::call('crm:campagne:destinataires', ['segment' => 'federations', 'sortie' => $sortie]);

    return array_values(array_filter(array_map(
        fn ($l) => json_decode($l, true),
        file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    )));
}

// ── L'import ────────────────────────────────────────────────────────────────

test('une ligne SANS SIREN est importee : fiche ancree sur (FR, identifiant), ligne federations, contact, etiquettes, protection', function () {
    $r = fssImporter([fssLigne()]);

    $fiche = fssFiche(FSS_ID);
    expect($r['code'])->toBe(0)
        ->and(fssCompteur($r['sortie'], 'fiches_creees'))->toBe(1)
        ->and(fssCompteur($r['sortie'], 'rejetees'))->toBe(0)
        ->and($fiche)->not->toBeNull()
        ->and($fiche->siren)->toBeNull()
        ->and($fiche->entity_nature)->toBe('federation')
        ->and($fiche->email_generic)->toBe('contact@zz-ud69.example.invalid')
        ->and(DB::table('federations')->where('company_id', $fiche->id)->value('niveau'))->toBe('departemental')
        ->and(DB::table('contacts')->where('company_id', $fiche->id)->value('last_name'))->toBe('ZZSECRETAIRE')
        ->and(fssSlugs((int) $fiche->id))->toContain(
            FichesProtegees::TAG_FEDERATIONS,
            'famille:confederation',
            'niveau:departemental',
            'secteur:interprofessionnel',
            'pertinence:haute',
        )
        ->and(FichesProtegees::estProtegee((int) $fiche->id))->toBeTrue();

    // Protégée pour de bon : la base refuse sa suppression physique.
    $refusee = false;
    try {
        DB::transaction(fn () => DB::table('companies')->where('id', $fiche->id)->delete());
    } catch (QueryException) {
        $refusee = true;
    }
    expect($refusee)->toBeTrue()
        ->and(DB::table('companies')->where('id', $fiche->id)->exists())->toBeTrue();
});

test('rejouer une ligne sans SIREN ne cree rien ; son run_id porte l identifiant', function () {
    fssImporter([fssLigne()]);
    $apres = fssVolumes();

    $r = fssImporter([fssLigne()]);

    expect(fssVolumes())->toBe($apres)
        ->and(fssCompteur($r['sortie'], 'fiches_creees'))->toBe(0)
        ->and(fssCompteur($r['sortie'], 'federations_inchangees'))->toBe(1)
        ->and(DB::table('companies')->where('foreign_id', FSS_ID)->count())->toBe(1)
        ->and(DB::table('scraper_runs')->where('dedup_key', 'like', 'pivot:federations-2026:federations-2026:' . FSS_ID . ':%')->count())->toBe(1);
});

test('une fiche deja presente avec le MEME identifiant est rattachee ; celle d un autre pays ne l est pas', function () {
    // Même identifiant, AUTRE pays : ce n'est pas le même organisme. Insérée
    // EN PREMIER : une recherche qui oublierait le pays la trouverait d'abord.
    $etrangere = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'country_code' => 'BE', 'foreign_id' => FSS_ID,
        'denomination' => 'ZZ HOMONYME BELGE', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $existante = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'country_code' => 'FR', 'foreign_id' => FSS_ID, 'entity_nature' => 'reseau',
        'denomination' => 'ZZ NOM DEJA CONNU', 'relation_type' => 'partenaire', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = fssImporter([fssLigne()]);

    expect(fssCompteur($r['sortie'], 'fiches_rattachees'))->toBe(1)
        ->and(fssCompteur($r['sortie'], 'fiches_creees'))->toBe(0)
        ->and(DB::table('companies')->where('foreign_id', FSS_ID)->count())->toBe(2)
        ->and(DB::table('federations')->where('company_id', $existante)->exists())->toBeTrue()
        ->and(DB::table('companies')->where('id', $existante)->value('entity_nature'))->toBe('reseau')
        ->and(DB::table('companies')->where('id', $existante)->value('relation_type'))->toBe('partenaire')
        ->and(DB::table('federations')->where('company_id', $etrangere)->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $etrangere)->exists())->toBeFalse();
});

test('seul un homonyme d un AUTRE pays porte l identifiant : il n est pas rattache, une fiche francaise nait', function () {
    $etrangere = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'country_code' => 'BE', 'foreign_id' => FSS_ID,
        'denomination' => 'ZZ HOMONYME BELGE', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = fssImporter([fssLigne()]);

    expect(fssCompteur($r['sortie'], 'fiches_creees'))->toBe(1)
        ->and(fssCompteur($r['sortie'], 'fiches_rattachees'))->toBe(0)
        ->and(fssFiche(FSS_ID))->not->toBeNull()
        ->and(DB::table('federations')->where('company_id', fssFiche(FSS_ID)->id)->exists())->toBeTrue()
        ->and(DB::table('federations')->where('company_id', $etrangere)->exists())->toBeFalse()
        ->and(DB::table('companies')->where('id', $etrangere)->value('denomination'))->toBe('ZZ HOMONYME BELGE');
});

test('une fiche sans SIREN a la corbeille n est pas ressuscitee ; le temoin est importe', function () {
    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'country_code' => 'FR', 'foreign_id' => FSS_ID, 'denomination' => 'ZZ CORBEILLE',
        'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = fssImporter([fssLigne(), fssLigne(['identifiant' => 'section:zz-reseau:38', 'nom' => 'ZZ TEMOIN'])]);

    expect($r['sortie'])->toContain('fiche_a_la_corbeille : 1')
        ->and(fssFiche(FSS_ID)->deleted_at)->not->toBeNull()
        ->and(DB::table('federations')->where('company_id', fssFiche(FSS_ID)->id)->exists())->toBeFalse()
        ->and(DB::table('federations')->where('company_id', fssFiche('section:zz-reseau:38')->id)->exists())->toBeTrue();
});

test('lignes invalides : ni SIREN ni identifiant, identifiant mal forme, tete invalide — rejetees par motif, sans valeur', function () {
    $r = fssImporter([
        fssLigne(['identifiant' => null]),
        fssLigne(['identifiant' => '900000901']),               // neuf chiffres : pas un identifiant
        fssLigne(['identifiant' => 'Section:ZZ:69']),           // espace de noms en majuscules
        fssLigne(['identifiant' => 'section zz 69']),
        fssLigne(['identifiant' => 'section:' . str_repeat('z', 120)]),
        fssLigne(['identifiant' => 'section:zz-reseau:01', 'tete_de_reseau' => 'section:zz-reseau:01']),
        fssLigne(['identifiant' => 'section:zz-reseau:02', 'tete_de_reseau' => 'pas une ancre']),
        // Un AUTRE espace de noms : jamais, même bien formé (S2).
        fssLigne(['identifiant' => 'evt:zz-club-affaires']),
        fssLigne(['identifiant' => 'section:zz-reseau:04', 'tete_de_reseau' => 'evt:zz-club-affaires']),
        fssLigne(['identifiant' => 'section:zz-reseau:03']),    // témoin
    ]);

    expect(fssCompteur($r['sortie'], 'rejetees'))->toBe(9)
        ->and($r['sortie'])->toContain('siren_ou_identifiant_manquant : 1', 'identifiant_invalide : 5', 'tete_de_reseau_invalide : 3')
        ->and(fssFiche('section:zz-reseau:03'))->not->toBeNull()
        ->and(DB::table('federations')->count())->toBe(1)
        ->and($r['sortie'])->not->toContain('ZZSECRETAIRE')
        ->and($r['sortie'])->not->toContain('zz-ud69.example.invalid');
});

test('une ligne AVEC SIREN garde exactement son message : run_id d avant, identifiant ignore', function () {
    $ligne = [
        'siren' => '900000801', 'nom' => 'ZZ FEDE RUN ID', 'famille' => 'federation_syndicat_pro', 'niveau' => 'national',
        'secteurs' => ['btp'], 'pertinence' => 'haute', 'contactabilite' => 'email_verifie',
        'email_generique' => 'contact@zz-runid.example.invalid', 'personnes' => [],
    ];

    fssImporter([$ligne]);

    // Relevé en exécutant la commande d'AVANT (6a88264) sur cette ligne : le
    // même contenu doit rester le même run, sinon un ré-import des fiches
    // déjà en base ne serait plus reconnu.
    expect(DB::table('scraper_runs')->where('dedup_key', 'pivot:federations-2026:federations-2026:900000801:e0a1bf9da07e8022')->exists())->toBeTrue()
        ->and(DB::table('companies')->where('siren', '900000801')->value('foreign_id'))->toBeNull();

    // La même ligne, avec un identifiant EN PLUS : le SIREN reste la seule
    // clé — même run (rien de nouveau), aucune ancre posée, aucune fiche de plus.
    $avant = fssVolumes();
    $r = fssImporter([$ligne + ['identifiant' => 'section:zz-reseau:01']]);

    expect(fssVolumes())->toBe($avant)
        ->and(fssCompteur($r['sortie'], 'federations_inchangees'))->toBe(1)
        ->and(DB::table('companies')->where('siren', '900000801')->value('foreign_id'))->toBeNull()
        ->and(fssFiche('section:zz-reseau:01'))->toBeNull();
});

// ── Têtes de réseau ─────────────────────────────────────────────────────────

test('tetes de reseau : une tete peut etre un SIREN ou un identifiant, dans les deux sens ; absente, elle est comptee', function () {
    $r = fssImporter([
        // Section → tête nationale À SIREN.
        fssLigne(['tete_de_reseau' => '900000802']),
        ['siren' => '900000802', 'nom' => 'ZZ CONFEDERATION NATIONALE', 'famille' => 'confederation', 'niveau' => 'national',
            'secteurs' => ['interprofessionnel'], 'pertinence' => 'haute', 'contactabilite' => 'email_verifie', 'personnes' => []],
        // Antenne À SIREN → tête SANS SIREN.
        ['siren' => '900000803', 'nom' => 'ZZ ANTENNE LOCALE', 'famille' => 'confederation', 'niveau' => 'local',
            'secteurs' => ['interprofessionnel'], 'pertinence' => 'moyenne', 'contactabilite' => 'email_verifie', 'personnes' => [],
            'tete_de_reseau' => FSS_ID],
        // Section → section.
        fssLigne(['identifiant' => 'section:zz-reseau:69-lyon', 'nom' => 'ZZ UNION LOCALE', 'niveau' => 'local',
            'email_generique' => null, 'personnes' => [], 'tete_de_reseau' => FSS_ID]),
        // Tête introuvable.
        fssLigne(['identifiant' => 'section:zz-reseau:01', 'nom' => 'ZZ ORPHELINE', 'email_generique' => null, 'personnes' => [],
            'tete_de_reseau' => 'section:zz-reseau:absente']),
    ]);

    $section = (int) fssFiche(FSS_ID)->id;
    $parent = static fn (int $id): ?int => DB::table('federations')->where('company_id', $id)->value('parent_company_id');
    expect($r['code'])->toBe(0)
        ->and(fssCompteur($r['sortie'], 'tetes_liees'))->toBe(3)
        ->and(fssCompteur($r['sortie'], 'tetes_introuvables'))->toBe(1)
        ->and($parent($section))->toBe((int) DB::table('companies')->where('siren', '900000802')->value('id'))
        ->and($parent((int) DB::table('companies')->where('siren', '900000803')->value('id')))->toBe($section)
        ->and($parent((int) fssFiche('section:zz-reseau:69-lyon')->id))->toBe($section)
        ->and($parent((int) fssFiche('section:zz-reseau:01')->id))->toBeNull();
});

// ── Registre des retraits ───────────────────────────────────────────────────

test('une personne supprimee d une fiche SANS SIREN ne revient pas ; son homonyme d une autre section entre (temoin)', function () {
    $personne = ['prenom' => 'Zed', 'nom' => 'ZZRETIRE', 'fonction' => 'Président', 'email' => null, 'linkedin' => null];
    fssImporter([fssLigne(['personnes' => [$personne]])]);
    $fiche = (int) fssFiche(FSS_ID)->id;
    DB::table('contacts')->where('company_id', $fiche)->where('last_name', 'ZZRETIRE')->delete();

    $registre = DB::table('contacts_retires')->where('company_id', $fiche)->first();
    expect($registre)->not->toBeNull()
        ->and($registre->siren)->toBeNull()
        ->and($registre->country_code)->toBe('FR')
        ->and($registre->foreign_id)->toBe(FSS_ID)
        ->and(json_encode($registre))->not->toContain('ZZRETIRE');

    // La ligne change (nouveau run_id) et une autre section porte un homonyme.
    $r = fssImporter([
        fssLigne(['telephone' => '01 00 00 00 69', 'personnes' => [$personne, ['prenom' => 'Zia', 'nom' => 'ZZNOUVELLE', 'fonction' => 'Trésorière', 'email' => null, 'linkedin' => null]]]),
        fssLigne(['identifiant' => 'section:zz-reseau:38', 'nom' => 'ZZ AUTRE SECTION', 'email_generique' => null, 'personnes' => [$personne]]),
    ]);

    expect(DB::table('contacts')->where('company_id', $fiche)->where('last_name', 'ZZRETIRE')->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $fiche)->where('last_name', 'ZZNOUVELLE')->exists())->toBeTrue()
        ->and(fssCompteur($r['sortie'], 'personnes_retirees_ignorees'))->toBe(1)
        ->and(DB::table('contacts')->where('company_id', fssFiche('section:zz-reseau:38')->id)->where('last_name', 'ZZRETIRE')->exists())->toBeTrue();
});

test('une fiche SANS SIREN supprimee en cascade inscrit son ancre ; un second retrait de la meme personne ne double pas la ligne', function () {
    $personne = ['prenom' => 'Zed', 'nom' => 'ZZCASCADE', 'fonction' => 'Président', 'email' => null, 'linkedin' => null];
    fssImporter([fssLigne(['personnes' => [$personne]])]);
    $fiche = (int) fssFiche(FSS_ID)->id;

    // Retirée une première fois, recréée à la main, retirée encore : une ligne.
    $recreer = fn () => DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $fiche, 'first_name' => 'Zed', 'last_name' => 'ZZCASCADE',
        'sources' => json_encode(['federations-2026']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->where('company_id', $fiche)->delete();
    $recreer();
    DB::table('contacts')->where('company_id', $fiche)->delete();
    expect(DB::table('contacts_retires')->where('foreign_id', FSS_ID)->count())->toBe(1);

    // Recréée encore, puis la fiche supprimée EN CASCADE : le déclencheur de
    // `companies` l'inscrit avec l'ancre de la fiche — la même ligne, pas une
    // seconde sans ancre.
    $recreer();

    DB::transaction(function () use ($fiche): void {
        // Levée volontaire documentée : la fiche est protégée.
        DB::statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        DB::table('companies')->where('id', $fiche)->delete();
    });

    $lignes = DB::table('contacts_retires')->where('company_id', $fiche)->get();
    expect($lignes)->toHaveCount(1)
        ->and($lignes[0]->foreign_id)->toBe(FSS_ID)
        ->and($lignes[0]->country_code)->toBe('FR');
});

// ── Segment et effacement ───────────────────────────────────────────────────

test('une fiche sans SIREN est dans le segment federations, et l effacement RGPD l atteint ; le temoin reste', function () {
    fssImporter([
        fssLigne(),
        fssLigne(['identifiant' => 'section:zz-reseau:38', 'nom' => 'ZZ TEMOIN', 'email_generique' => 'contact@zz-ud38.example.invalid',
            'personnes' => [['prenom' => 'Zia', 'nom' => 'ZZTEMOIN', 'fonction' => 'Présidente', 'email' => 'zia.zztemoin@zz-ud38.example.invalid', 'linkedin' => null]]]),
    ]);

    $emails = collect(fssDestinataires())->pluck('email')->all();
    expect($emails)->toContain('contact@zz-ud69.example.invalid', 'zoe.zzsecretaire@zz-ud69.example.invalid', 'contact@zz-ud38.example.invalid');

    // Effacement de la secrétaire générale ET de l'adresse générique qu'elle
    // tient : la fiche survit (protégée), la coordonnée part.
    $fiche = (int) fssFiche(FSS_ID)->id;
    EffacementCoordonneesFiches::effacer('zoe.zzsecretaire@zz-ud69.example.invalid', [], [], $this->espace);
    EffacementCoordonneesFiches::effacer('contact@zz-ud69.example.invalid', [], [], $this->espace);

    $temoin = fssFiche('section:zz-reseau:38');
    expect(DB::table('contacts')->where('company_id', $fiche)->where('last_name', 'ZZSECRETAIRE')->exists())->toBeFalse()
        ->and(DB::table('companies')->where('id', $fiche)->value('email_generic'))->toBeNull()
        ->and(DB::table('companies')->where('id', $fiche)->exists())->toBeTrue()
        ->and($temoin->email_generic)->toBe('contact@zz-ud38.example.invalid')
        ->and(DB::table('contacts')->where('company_id', $temoin->id)->where('last_name', 'ZZTEMOIN')->exists())->toBeTrue()
        // L'effacement a laissé une empreinte au registre, par l'ancre.
        ->and(DB::table('contacts_retires')->where('foreign_id', FSS_ID)->count())->toBe(1);
});

test('la question par ancre est SECURITY DEFINER, a search_path fixe, et refusee a PUBLIC', function () {
    $f = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef AS definer,
               array_to_string(p.proconfig, ',') AS config,
               p.proacl IS NULL AS droits_par_defaut,
               EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE') AS public_execute
        FROM   pg_proc p
        JOIN   pg_namespace n ON n.oid = p.pronamespace
        WHERE  n.nspname = 'public' AND p.proname = 'contacts_retires_contient_ancre'
    SQL);

    expect($f)->not->toBeNull()
        ->and((bool) $f->definer)->toBeTrue()
        ->and((string) $f->config)->toContain('search_path=')
        // Droits par défaut = EXECUTE accordé à PUBLIC : le REVOKE aurait disparu.
        ->and((bool) $f->droits_par_defaut)->toBeFalse()
        ->and((bool) $f->public_execute)->toBeFalse();
});

// ── Relecture de la PR #256 ─────────────────────────────────────────────────

test('S2 — une ligne evt: ne se rattache jamais a un organisateur d evenements', function () {
    $organisateur = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'country_code' => 'FR', 'foreign_id' => 'evt:zz-club-affaires',
        'denomination' => 'ZZ CLUB D AFFAIRES', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = fssImporter([fssLigne(['identifiant' => 'evt:zz-club-affaires']), fssLigne()]);

    expect($r['sortie'])->toContain('identifiant_invalide : 1')
        ->and(DB::table('federations')->where('company_id', $organisateur)->exists())->toBeFalse()
        ->and(DB::table('contacts')->where('company_id', $organisateur)->exists())->toBeFalse()
        ->and(fssFiche(FSS_ID))->not->toBeNull();
});

test('S1 — les deux questions au registre ne repondent QUE dans l espace du contexte', function () {
    $b = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $b, 'slug' => 'zz-fss-espace-b-' . substr($b, 0, 8), 'name' => 'ZZ espace B', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Une personne retirée d'une fiche À SIREN et d'une fiche SANS SIREN, dans B.
    foreach ([['900000901', null, null], [null, 'FR', FSS_ID]] as [$siren, $pays, $foreign]) {
        DB::table('contacts_retires')->insert([
            'workspace_id' => $b, 'siren' => $siren, 'country_code' => $pays, 'foreign_id' => $foreign,
            'cle_nom' => (string) DB::selectOne("SELECT contacts_retires_empreinte('Zed', 'ZZESPACE') AS h")->h,
        ]);
    }
    $demander = static function (string $contexte) use ($b): array {
        DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $contexte]);
        $parSiren = DB::selectOne('SELECT contacts_retires_contient(?::uuid, ?, ?, ?) AS e', [$b, '900000901', 'Zed', 'ZZESPACE'])->e;
        $parAncre = DB::selectOne('SELECT contacts_retires_contient_ancre(?::uuid, ?, ?, ?, ?) AS e', [$b, 'FR', FSS_ID, 'Zed', 'ZZESPACE'])->e;
        DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

        return [(bool) $parSiren, (bool) $parAncre];
    };

    // Sous le contexte A, la question sur B est refusée (réponse « non ») ;
    // sous le contexte B, le registre répond — le témoin que la ligne existe.
    expect($demander($this->espace))->toBe([false, false])
        ->and($demander($b))->toBe([true, true]);
});

test('E1 — essai a blanc = reel quand la tete est designee par un IDENTIFIANT, a travers les paquets', function () {
    $lignes = [];
    // Six sections locales AVANT leur tête (section régionale) : paquets 1 et 2.
    for ($i = 1; $i <= 6; $i++) {
        $lignes[] = fssLigne(['identifiant' => 'section:zz-reseau:69-' . $i, 'nom' => 'ZZ LOCALE ' . $i, 'niveau' => 'local',
            'email_generique' => null, 'personnes' => [], 'tete_de_reseau' => 'section:zz-reseau:region']);
    }
    $lignes[] = fssLigne(['identifiant' => 'section:zz-reseau:region', 'nom' => 'ZZ REGIONALE', 'niveau' => 'regional',
        'email_generique' => null, 'personnes' => [], 'tete_de_reseau' => '900000950']);
    $lignes[] = ['siren' => '900000950', 'nom' => 'ZZ NATIONALE', 'famille' => 'confederation', 'niveau' => 'national',
        'secteurs' => ['interprofessionnel'], 'pertinence' => 'haute', 'contactabilite' => 'aucun_contact', 'personnes' => []];
    $lignes[] = fssLigne(['identifiant' => 'section:zz-reseau:orpheline', 'nom' => 'ZZ ORPHELINE', 'email_generique' => null,
        'personnes' => [], 'tete_de_reseau' => 'section:zz-reseau:absente']);

    $fichier = fssFichier($lignes);
    Artisan::call('crm:import-federations', ['file' => $fichier, '--paquet' => '4', '--dry-run' => true]);
    $blanc = Artisan::output();
    Artisan::call('crm:import-federations', ['file' => $fichier, '--paquet' => '4']);
    $reel = Artisan::output();

    preg_match_all('/^\|.*\|$/m', $blanc, $b);
    preg_match_all('/^\|.*\|$/m', $reel, $r);
    $region = (int) fssFiche('section:zz-reseau:region')->id;
    expect($b[0])->toBe($r[0])
        ->and(fssCompteur($reel, 'tetes_liees'))->toBe(7)
        ->and(fssCompteur($reel, 'tetes_introuvables'))->toBe(1)
        ->and(DB::table('federations')->where('parent_company_id', $region)->count())->toBe(6);
});
