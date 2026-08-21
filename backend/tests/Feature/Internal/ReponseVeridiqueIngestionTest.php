<?php

/**
 * GARDE C18-001 (S1) — « l'endpoint repond "ingested: true" sans rien ingerer ».
 *
 * MESURE DU DEFAUT (depot, 2026-08-20, avant correctif) : dans
 * `ScraperResultController::store()`, branche « funnel ferme » — quand
 * `crm.scrape_funnel.enabled` est FAUX, et c'est LA VALEUR PAR DEFAUT posee par
 * `config/crm.php` (bloc `scrape_funnel`) — le controleur journalisait trois
 * champs et rendait `200 {"ingested": true}`. Aucune ligne n'est ecrite : ni
 * `companies`, ni `scraper_runs`, ni `activities`. Le producteur recevait donc
 * une confirmation d'ingestion pour un message qui n'a JAMAIS ete ingere.
 *
 * Rouge de reference obtenu sur `axion_crm_test_lot6` avant correctif :
 *   « La reponse affirme encore une ingestion qui n'a pas eu lieu (C18-001).
 *     Failed asserting that true is false » — le `expect(status)->toBe(200)`
 *   qui la precede etant, lui, deja vert.
 *
 * C'est la lecon IndexNow que le depot cite lui-meme : un job vert qui ne relaie
 * rien est le pire des etats, parce qu'il consomme le seul signal dont dispose
 * l'exploitant. Ici c'etait pire encore : le mensonge etait ecrit dans l'en-tete
 * du controleur (« RIEN n'etait ingere ») ET dans `config/crm.php`
 * (« log-only, 200 `ingested: true` ») — le defaut etait DOCUMENTE, donc assume,
 * sans que personne n'ait constate que la reponse, elle, mentait. Une garde du
 * depot, `tests/Feature/Crm/ScrapedIngestTest.php`, l'EXIGEAIT meme
 * (`assertJsonPath('ingested', true)`) trois lignes avant de verifier que les
 * trois tables etaient a zero. Elle a ete rectifiee avec ce lot.
 *
 * CE QUE CE LOT NE FAIT PAS : ouvrir le funnel. Le drapeau ferme est un CHOIX DE
 * CONCEPTION (fusion sans risque). Le correctif porte sur la seule chose fausse :
 * la REPONSE. Elle doit dire `{"ingested": false, "raison": "funnel_ferme"}`.
 *
 * COMPATIBILITE DU PRODUCTEUR — verifiee avant d'ecrire une ligne.
 * `workers/src/bridge/result-sender.ts` (34 lignes) fait `await axios.post(...)`
 * et JETTE la valeur de retour : aucune lecture de `.data`, aucune occurrence de
 * « ingested ». Son appelant `workers/src/scrapers/base.ts` l. 178 fait
 * `await envoyer({...})`, dont le type de retour est `Promise<void>`. Seul le
 * STATUT HTTP compte pour lui : axios leve sur tout ce qui n'est pas 2xx, et
 * `base.ts` l. 185-201 traite cette levee comme un echec de job (remise en file,
 * puis abandon en `status: 'failed'`). Changer le CORPS est donc sans effet sur
 * lui ; changer le STATUT casserait les workers. La garde ci-dessous epingle les
 * deux : le corps devient veridique, le 200 ne bouge pas.
 * La contrepartie cote Node vit dans `workers/tests/reponse-ingestion-veridique.test.ts`
 * — ce depot-ci n'a pas `workers/` monte dans le conteneur de test.
 */

use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Le secret du canal, pose sur les TROIS canaux de variables ET dans la
 * configuration resolue.
 *
 * Sans la ligne `config([...])`, ces gardes rendraient 503 : depuis P5-HMAC-002
 * le controleur lit `config('services.worker_internal.hmac_secret')`, et
 * `config/` est resolu UNE FOIS a l'amorcage. Le meme piege a deja fait passer
 * pour vertes des gardes qui signaient avec la chaine vide.
 */
const SECRET_C18 = 'secret-c18-du-canal-interne-2026';

function poserSecretC18(): string
{
    $_SERVER['WORKER_INTERNAL_HMAC_SECRET'] = SECRET_C18;
    $_ENV['WORKER_INTERNAL_HMAC_SECRET'] = SECRET_C18;
    putenv('WORKER_INTERNAL_HMAC_SECRET=' . SECRET_C18);
    config(['services.worker_internal.hmac_secret' => SECRET_C18]);

    return SECRET_C18;
}

function appelC18(string $corps)
{
    return test()->call('POST', '/api/internal/scraper-result', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WORKER_SIGNATURE' => hash_hmac('sha256', $corps, poserSecretC18()),
    ], $corps);
}

/** L'espace de destination du funnel (`crm.ingest.business_workspace`). */
function espaceC18(): string
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

/**
 * Le MEME message dans les deux moities de la garde. C'est deliberé : la seule
 * variable entre le rouge et le temoin doit etre le DRAPEAU, jamais le corps.
 *
 * @return array<string, mixed>
 */
function messageC18(string $runId): array
{
    return [
        'schema_version' => 1,
        'source' => 'mentions-legales',
        'run_id' => $runId,
        'fetched_at' => '2026-08-20T09:00:00+02:00',
        'status' => 'success',
        'company' => [
            'siren' => '900000318',
            'fields' => ['denomination' => 'ZZ C18 SARL', 'city' => 'Grenoble'],
        ],
    ];
}

beforeEach(function () {
    // Jamais d'appel DNS reel dans la suite (regle MOCK_*).
    config([
        'crm.scrape_funnel.validate_mx' => false,
        'crm.ingest.business_workspace' => 'axion-ia',
    ]);
});

afterEach(function () {
    unset($_SERVER['WORKER_INTERNAL_HMAC_SECRET'], $_ENV['WORKER_INTERNAL_HMAC_SECRET']);
    putenv('WORKER_INTERNAL_HMAC_SECRET');
    config(['services.worker_internal.hmac_secret' => '']);
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. LE DEFAUT — funnel FERME, la reponse doit dire la verite
// ─────────────────────────────────────────────────────────────────────────────

test('C18-001 — funnel FERME : la reponse ne dit plus « ingested: true »', function () {
    config(['crm.scrape_funnel.enabled' => false]);

    $corps = json_encode(messageC18('c18-ferme'), JSON_THROW_ON_ERROR);
    $reponse = appelC18($corps);

    // 1. Le statut ne bouge PAS : axios leve sur tout ce qui n'est pas 2xx, et
    //    `base.ts` traite cette levee comme un echec de job. Un 202 ou un 4xx
    //    ici ferait rejouer puis PERDRE les resultats de collecte.
    expect($reponse->status())->toBe(200);

    // 2. Le corps dit la verite.
    expect($reponse->json('ingested'))->toBeFalse(
        'La reponse affirme encore une ingestion qui n\'a pas eu lieu (C18-001).',
    );
    expect($reponse->json('raison'))->toBe(
        'funnel_ferme',
        'La reponse ne dit pas POURQUOI rien n\'est ingere : le producteur ne peut pas distinguer un refus de conception d\'une panne.',
    );

    // 3. TEMOIN DE VERACITE — « false » n'est pas une prudence, c'est un fait :
    //    on prouve au MEME instant qu'aucune des trois tables du funnel n'a
    //    bouge. Sans ce bloc, un correctif qui repondrait toujours « false »
    //    passerait pour bon meme si l'ingestion marchait.
    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('scraper_runs')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0);
});

test('C18-001 — TEMOIN : la garde ne passe pas au vert sur une reponse VIDE', function () {
    config(['crm.scrape_funnel.enabled' => false]);

    $corps = json_encode(messageC18('c18-vide'), JSON_THROW_ON_ERROR);
    $reponse = appelC18($corps);

    // `->json('ingested')` rend `null` sur un corps vide, une cle absente ou un
    // 204 — et `null` n'est pas `false`. On l'epingle explicitement : la cle
    // doit EXISTER et le corps ne doit pas etre vide. C'est exactement le piege
    // que ce lot poursuit : une garde qui mesure autre chose que ce qu'elle dit.
    $decode = $reponse->json();

    expect($reponse->getContent())->not->toBe('');
    expect(is_array($decode))->toBeTrue('Le corps de reponse n\'est pas un objet JSON.');
    expect(array_key_exists('ingested', $decode))->toBeTrue(
        'La cle « ingested » a disparu de la reponse : le producteur ne recoit plus aucun verdict.',
    );
    expect(array_key_exists('raison', $decode))->toBeTrue(
        'La cle « raison » est absente.',
    );
    expect($decode['ingested'])->not->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. TEMOIN D'OPPOSITION — funnel OUVERT, le MEME message, la MEME seconde
// ─────────────────────────────────────────────────────────────────────────────

test('C18-001 — TEMOIN : funnel OUVERT, le MEME message rend « ingested: true » ET ecrit la fiche', function () {
    // Une garde qui n'aurait que la moitie « fermee » serait satisfaite par un
    // controleur qui repond `false` en toutes circonstances. On oppose donc les
    // deux etats du drapeau sur le MEME corps : c'est la seule facon de prouver
    // que « ingested » suit la realite et n'est pas devenu une constante.
    espaceC18();
    $this->seed(ScrapingSourcesSeeder::class);
    config(['crm.scrape_funnel.enabled' => true]);

    $corps = json_encode(messageC18('c18-ouvert'), JSON_THROW_ON_ERROR);
    $reponse = appelC18($corps);

    expect($reponse->status())->toBe(200);
    expect($reponse->json('ingested'))->toBeTrue();
    expect($reponse->json('result.status'))->toBe('created');

    // Le fait, pas la parole : la fiche existe.
    expect(DB::table('companies')->where('siren', '900000318')->exists())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. TEMOIN DE NON-VACUITE — la garde sait qu'elle a joue
// ─────────────────────────────────────────────────────────────────────────────

test('C18-001 — TEMOIN : les trois tables du funnel EXISTENT (une table absente ne vaut pas un zero)', function () {
    // Regle 3 du mandat : un `count() === 0` sur une table ABSENTE ne prouve
    // rien — la requete leverait, ou pire, une garde ecrite avec un `try` la
    // rendrait verte. On verifie donc que les trois tables sont bien la avant
    // de tirer une conclusion de leurs compteurs.
    foreach (['companies', 'scraper_runs', 'activities', 'scraping_sources'] as $table) {
        expect(DB::getSchemaBuilder()->hasTable($table))->toBeTrue(
            "Table « {$table} » absente : le zero mesure par la garde ne veut rien dire.",
        );
    }
});
