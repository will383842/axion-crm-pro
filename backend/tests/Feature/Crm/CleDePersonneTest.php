<?php

/**
 * GARDE — A05-001 (S1) : « la fiche 360° et le rapprochement par person_key
 * sont INATTEIGNABLES : 0 contact sur 1 319 567 porte une cle ».
 *
 * ── L'ETAT MESURE (audit 360, grille agent-05, ligne L2-4) ─────────────────
 *
 *     SELECT count(*), count(person_key), count(email) FROM contacts;
 *       1 319 567 | 0 | 410 481
 *
 * `contacts.person_key` etait ecrite par UN SEUL chemin, `ContactUpserter`
 * (ingestion du site), qui n'a jamais cree la moindre fiche en production (3
 * evenements recus, 3 partis en arbitrage). Le chemin qui a fabrique les 1,3 M
 * de fiches — la COLLECTE, `ScrapedRecordIngestService` — ne posait rien. Et
 * `ContactsHubPage.tsx` n'affiche le lien vers la fiche 360° que
 * `si contact.person_key !== null` : l'ecran existe, il n'est offert a
 * personne.
 *
 * ── CE QU'EST REELLEMENT LA CLE, VERIFIE DANS LE DEPOT DU SITE ────────────
 *
 * `axionia/src/server/crm-sync/index.ts` l. 24 : « `person_key` =
 * `hashEmailForLookup(email)` ». Et `axionia/src/lib/security/email-hash.ts`
 * l. 81 :
 *
 *     createHmac("sha256", key).update(`${DOMAIN}:${normalized}`).digest("hex")
 *     DOMAIN     = "submission-email-index-v1"
 *     normalized = email.trim().toLowerCase()
 *     key        = process.env.PII_ENCRYPTION_KEY
 *
 * Ce n'est donc PAS un sel incalculable : c'est un HMAC-SHA256 dont la formule
 * est publique et dont le SECRET vit cote site. Le CRM peut reproduire la
 * formule au bit pres — il lui faut le secret, et rien d'autre.
 *
 * ── LA REGLE QUE CE FICHIER FIGE ──────────────────────────────────────────
 *
 * Le CRM n'INVENTE JAMAIS de sel. Une cle calculee avec un autre secret serait
 * un faux : elle rendrait la fiche 360° cliquable tout en rendant impossible
 * le rapprochement avec le site — et surtout, l'endpoint RGPD du site
 * (`/internal/site-sync/gdpr`, qui interroge `contacts.person_key`) cesserait
 * de retrouver les fiches, c'est-a-dire un export art. 15 et un effacement
 * art. 17 muets. Sans secret, le remplissage REFUSE.
 */

use App\Crm\Identite\CleDePersonne;
use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * VECTEUR DE REFERENCE, calcule par le code du SITE lui-meme (Node), pas par
 * une reimplementation :
 *
 *   $ node -e "const{createHmac}=require('crypto');
 *       console.log(createHmac('sha256','SECRET-DE-TEST')
 *         .update('submission-email-index-v1:'+' Jean.Dupont@Acme.FR '.trim().toLowerCase())
 *         .digest('hex'))"
 *     99cb0a72b610ede71128411d2beea0af53b799aefdf07bb65b3917f65fa72388
 *
 * Si un jour PHP, SQL et cette constante cessent d'etre d'accord, le
 * rapprochement des personnes est casse — silencieusement, comme il l'etait.
 */
const CLE_SECRET_DE_TEST = 'SECRET-DE-TEST';
const CLE_EMAIL_DE_TEST = ' Jean.Dupont@Acme.FR ';
const CLE_ATTENDUE_DU_SITE = '99cb0a72b610ede71128411d2beea0af53b799aefdf07bb65b3917f65fa72388';

function cleWorkspace(): string
{
    $existant = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
    if ($existant !== null) {
        return (string) $existant;
    }

    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'axion-ia',
        'name' => 'Axion-IA',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function cleCompagnie(string $workspaceId, string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => 'ZZ CLE ' . $siren,
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Cree un contact SANS person_key — l'etat des 1,3 M de fiches de production. */
function cleContactSansCle(string $workspaceId, int $companyId, ?string $email, string $nom): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $workspaceId,
        'company_id' => $companyId,
        'first_name' => 'Jean',
        'last_name' => $nom,
        'email' => $email,
        'discovery_source' => 'scraping',
        'sources' => '["scraping"]',
        'metadata' => '{}',
        'field_origins' => '{}',
        'legal_basis' => 'legitimate_interest_b2b',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    config([
        'crm.person_key.secret' => CLE_SECRET_DE_TEST,
        'crm.scrape_funnel.enabled' => true,
        'crm.scrape_funnel.validate_mx' => false,
        'crm.ingest.business_workspace' => 'axion-ia',
    ]);

    cleWorkspace();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. PARITE AVEC LE SITE — la seule chose qui rende la cle utile
// ─────────────────────────────────────────────────────────────────────────────

test('la derivation PHP reproduit au bit pres celle du site', function () {
    expect(CleDePersonne::pour(CLE_EMAIL_DE_TEST))->toBe(CLE_ATTENDUE_DU_SITE);
});

test('la derivation SQL du remplissage rend la MEME cle que PHP et que le site', function () {
    // Le remplissage de 1,3 M de lignes se fait en SQL ensembliste : si son
    // expression divergeait d'un caractere de celle de PHP, le stock et le flux
    // porteraient deux cles differentes pour la meme personne — c'est-a-dire
    // exactement la divergence que le lot demande d'empecher.
    $sql = DB::selectOne(
        'SELECT ' . CleDePersonne::expressionSql('?') . ' AS cle',
        [CLE_EMAIL_DE_TEST, CleDePersonne::secret()],
    );

    expect((string) $sql->cle)->toBe(CLE_ATTENDUE_DU_SITE);
});

test('la normalisation est celle du site : espaces rognes, casse abaissee', function () {
    expect(CleDePersonne::pour('JEAN.DUPONT@ACME.FR'))
        ->toBe(CleDePersonne::pour('  jean.dupont@acme.fr  '));
});

test('sans secret configure, AUCUNE cle n est fabriquee — le CRM n invente pas de sel', function () {
    config(['crm.person_key.secret' => '']);

    expect(CleDePersonne::estConfiguree())->toBeFalse();
    expect(CleDePersonne::pour(CLE_EMAIL_DE_TEST))->toBeNull();
});

test('sans email, pas de cle : on ne fabrique pas une identite depuis autre chose', function () {
    expect(CleDePersonne::pour(null))->toBeNull();
    expect(CleDePersonne::pour('   '))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LE CHEMIN D'ECRITURE — sinon le remplissage re-diverge des la 1re collecte
// ─────────────────────────────────────────────────────────────────────────────

test('la COLLECTE pose desormais la cle sur la fiche personne qu elle cree', function () {
    $this->seed(ScrapingSourcesSeeder::class);

    $service = app(ScrapedRecordIngestService::class);
    $service->ingest(ScrapedRecord::fromArray([
        'schema_version' => 1,
        'source' => 'mentions-legales',
        'run_id' => (string) Str::uuid(),
        'fetched_at' => '2026-08-20T11:00:00+02:00',
        'status' => 'success',
        'company' => [
            'siren' => '900000909',
            'fields' => ['denomination' => 'ZZ CLE SARL', 'city' => 'Grenoble'],
        ],
        'persons' => [[
            'first_name' => 'Jean',
            'last_name' => 'ZZ CLE',
            'email' => 'jean.dupont@acme.fr',
            'kind' => 'person',
        ]],
        'evidence' => ['url' => 'https://zz-cle.example.invalid/mentions-legales'],
        'confidence' => 90,
    ]), false);

    $cle = DB::table('contacts')->where('last_name', 'ZZ CLE')->value('person_key');

    expect($cle)->not->toBeNull();
    expect((string) $cle)->toBe(CleDePersonne::pour('jean.dupont@acme.fr'));
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. LE REMPLISSAGE DU STOCK
// ─────────────────────────────────────────────────────────────────────────────

test('le remplissage pose la cle sur le stock, par lots, et est rejouable', function () {
    $ws = cleWorkspace();
    $entreprise = cleCompagnie($ws, '900001001');

    $avecEmail = [];
    for ($i = 0; $i < 7; $i++) {
        $avecEmail[] = cleContactSansCle($ws, $entreprise, "zz.stock{$i}@acme.fr", "ZZ STOCK {$i}");
    }
    $sansEmail = cleContactSansCle($ws, $entreprise, null, 'ZZ SANS EMAIL');

    // ANTI-VERT-A-VIDE : on constate l'etat de depart, celui du constat.
    expect(DB::table('contacts')->whereIn('id', $avecEmail)->whereNotNull('person_key')->count())->toBe(0);

    // Taille de lot volontairement plus PETITE que le nombre de lignes : si le
    // remplissage n'etait pas reellement iteratif (un seul UPDATE deguise), il
    // s'arreterait a 3 lignes et ce test rougirait.
    $code = Artisan::call('crm:remplir-cle-personne', ['--taille-lot' => 3]);
    expect($code)->toBe(0, Artisan::output());

    foreach ($avecEmail as $rang => $id) {
        $ligne = DB::table('contacts')->where('id', $id)->first();
        expect((string) $ligne->person_key)->toBe(CleDePersonne::pour("zz.stock{$rang}@acme.fr"));
    }

    // Une fiche sans adresse reste sans cle : la cle EST l'empreinte de
    // l'adresse, il n'y a rien a fabriquer.
    expect(DB::table('contacts')->where('id', $sansEmail)->value('person_key'))->toBeNull();

    // REJOUABLE : deuxieme passage, zero ligne touchee et toujours vert.
    $code2 = Artisan::call('crm:remplir-cle-personne', ['--taille-lot' => 3]);
    $sortie2 = Artisan::output();
    expect($code2)->toBe(0, $sortie2);
    $this->assertStringContainsString('0 fiche', $sortie2);
});

test('sans secret, le remplissage REFUSE et n ecrit rien', function () {
    $ws = cleWorkspace();
    $entreprise = cleCompagnie($ws, '900001002');
    $id = cleContactSansCle($ws, $entreprise, 'zz.refus@acme.fr', 'ZZ REFUS');

    config(['crm.person_key.secret' => '']);

    $code = Artisan::call('crm:remplir-cle-personne');
    $sortie = Artisan::output();

    expect($code)->not->toBe(0);
    // Sous-chaine SANS ACCENT (regle du depot : un controle sur du texte
    // francais ne se joue jamais sur une lettre accentuee).
    $this->assertStringContainsString('CRM_PERSON_KEY_SECRET', $sortie);
    expect(DB::table('contacts')->where('id', $id)->value('person_key'))->toBeNull();
});

test('la migration de remplissage remplit le stock, et se TAIT sans faire rougir si le secret manque', function () {
    $ws = cleWorkspace();
    $entreprise = cleCompagnie($ws, '900001003');
    $id = cleContactSansCle($ws, $entreprise, 'zz.migration@acme.fr', 'ZZ MIGRATION');

    $fichier = database_path('migrations/2026_08_20_141000_remplir_cle_personne_sur_le_stock_contacts.php');
    expect(file_exists($fichier))->toBeTrue();

    // 1. SANS SECRET : la migration ne fabrique rien et ne leve pas — un
    //    deploiement ne doit pas rougir sur un secret qui n'est pas encore la.
    config(['crm.person_key.secret' => '']);
    (require $fichier)->up();
    expect(DB::table('contacts')->where('id', $id)->value('person_key'))->toBeNull();

    // 2. AVEC SECRET : elle remplit.
    config(['crm.person_key.secret' => CLE_SECRET_DE_TEST]);
    (require $fichier)->up();

    expect((string) DB::table('contacts')->where('id', $id)->value('person_key'))
        ->toBe(CleDePersonne::pour('zz.migration@acme.fr'));
});
