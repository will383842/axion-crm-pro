<?php

/**
 * DOUBLONS — CHAQUE REQUÊTE PASSE PAR UN INDEX (chantier 5).
 *
 * `companies` compte 4,34 M de lignes. La détection, la fusion et son
 * annulation émettent des requêtes REJOUÉES ici par `EXPLAIN` : aucune ne doit
 * parcourir une table (`Seq Scan`), ni lire un index EN ENTIER (sans
 * condition), ni le lire pour TOUT L'ESPACE (condition sur `workspace_id`
 * seul : sur la production, c'est lire 4,3 M de lignes), sur les tables
 * qu'elles touchent. Du volume est semé pour que le planificateur choisisse
 * comme il le ferait en vrai (même méthode que `EffacementServiParDesIndexTest`).
 *
 * ⚠️ `SET LOCAL enable_seqscan = off`, ET IL FAUT LE DIRE : la base de test
 * est minuscule, et le planificateur y préfère — à raison — un parcours. Le
 * réglage ne l'interdit pas quand AUCUN index ne peut servir : la garde mesure
 * donc « un index PEUT servir cette requête » (même méthode que
 * `EffacementServiParDesIndexTest`).
 *
 * TÉMOINS : chaque index sur lequel repose le chantier apparaît au moins une
 * fois (sinon la garde mesurerait le néant) ; et une requête NON indexée
 * connue est refusée par la même sonde.
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\FichesProtegees;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Les tables dont une requête du chantier ne doit jamais faire le tour. */
const DSI_TABLES = [
    'companies', 'contacts', 'activities', 'company_tag', 'scraper_runs', 'audience_members', 'event_organizers',
    'deals', 'duplicate_flags', 'federations', 'media', 'journalists', 'health_practitioners', 'personnes',
    'fusions_fiches',
];
// `adresses_partagees` n'y est pas : table DÉRIVÉE de quelques dizaines de
// milliers de lignes, que le plafond du ménage compte en entier (à dessein).

const DSI_VOLUME = 3000;

/**
 * Les index sur lesquels le chantier repose : chaque groupe doit servir au
 * moins une fois (un groupe = des index équivalents pour la même recherche :
 * l'égalité sur le nom normalisé est servie par le btree OU par le trigramme).
 */
const DSI_INDEX_ATTENDUS = [
    ['idx_companies_denom_btree', 'idx_companies_denomination_trgm'],
    ['idx_companies_email_generic_minuscules'],
    ['idx_activities_sujet_fiche'],
    ['idx_deals_company'],
    ['contacts_workspace_id_normalized_hash_key'],
    ['idx_dup_flags_file_fusion_auto'],
    ['idx_fusions_fiches_flag'],
];

/** @return list<array{sql: string, bindings: array<int, mixed>}> */
function dsiCapturer(callable $travail): array
{
    $vus = [];
    DB::listen(function ($requete) use (&$vus): void {
        $vus[] = ['sql' => $requete->sql, 'bindings' => $requete->bindings];
    });
    try {
        $travail();
    } finally {
        DB::connection()->unsetEventDispatcher();
        DB::connection()->setEventDispatcher(app('events'));
    }

    return $vus;
}

/** @return array<string, mixed> */
function dsiPlan(string $sql, array $bindings): array
{
    $ligne = (array) DB::selectOne('EXPLAIN (FORMAT JSON) ' . $sql, $bindings);

    return (array) json_decode((string) reset($ligne), true)[0]['Plan'];
}

/**
 * @param  array<string, mixed>  $noeud
 * @param  array<string, true>  $vus
 * @param  list<string>  $defauts
 */
function dsiVerifier(array $noeud, array &$vus, array &$defauts): void
{
    $table = $noeud['Relation Name'] ?? null;
    $type = (string) ($noeud['Node Type'] ?? '');
    $index = (string) ($noeud['Index Name'] ?? '');
    if ($index !== '') {
        $vus[$index] = true;
    }
    if (is_string($table) && in_array($table, DSI_TABLES, true)) {
        if ($type === 'Seq Scan') {
            $defauts[] = "parcours complet de {$table}";
        } elseif (in_array($type, ['Index Scan', 'Index Only Scan'], true) && ! isset($noeud['Index Cond'])) {
            $defauts[] = "index {$index} lu EN ENTIER sur {$table}";
        } elseif (in_array($type, ['Index Scan', 'Index Only Scan'], true) && dsiEspaceSeul((string) $noeud['Index Cond'])) {
            $defauts[] = "index {$index} lu pour TOUT L ESPACE sur {$table}";
        }
    }
    if ($type === 'Bitmap Heap Scan' && ! dsiBitmapServi((array) ($noeud['Plans'][0] ?? []))) {
        $defauts[] = 'bitmap non servi par une condition d index sur ' . (is_string($table) ? $table : '?');
    }
    foreach ((array) ($noeud['Plans'] ?? []) as $enfant) {
        dsiVerifier((array) $enfant, $vus, $defauts);
    }
}

/**
 * Une condition d'index qui ne porte QUE sur l'espace (chaque terme cite
 * `workspace_id`) lit tout l'espace : sur la production, 4,3 M de fiches.
 */
function dsiEspaceSeul(string $condition): bool
{
    foreach (explode(' AND ', $condition) as $terme) {
        if (! str_contains($terme, 'workspace_id')) {
            return false;
        }
    }

    return true;
}

/**
 * Un bitmap est-il servi par une CONDITION d'index ? `BitmapAnd` : au moins une
 * branche (les autres ne font que restreindre) ; `BitmapOr` : toutes (une
 * branche sans condition lirait tout). Même règle que `EffacementServiParDesIndexTest`.
 *
 * @param  array<string, mixed>  $noeud
 */
function dsiBitmapServi(array $noeud): bool
{
    $type = (string) ($noeud['Node Type'] ?? '');
    if ($type === 'Bitmap Index Scan') {
        return isset($noeud['Index Cond']) && ! dsiEspaceSeul((string) $noeud['Index Cond']);
    }
    $servis = array_map(static fn (mixed $e): bool => dsiBitmapServi((array) $e), (array) ($noeud['Plans'] ?? []));
    if ($servis === []) {
        return false;
    }

    return $type === 'BitmapOr' ? ! in_array(false, $servis, true) : in_array(true, $servis, true);
}

/**
 * @param  list<array{sql: string, bindings: array<int, mixed>}>  $requetes
 * @param  array<string, true>  $vus
 * @return list<string>
 */
function dsiDefauts(array $requetes, array &$vus): array
{
    $defauts = [];
    foreach ($requetes as $r) {
        if (preg_match('/^(select|update|delete)\b/i', ltrim($r['sql'])) !== 1) {
            continue;
        }
        $concerne = false;
        foreach (DSI_TABLES as $t) {
            if (preg_match('/\b' . $t . '\b/', $r['sql']) === 1) {
                $concerne = true;
            }
        }
        if (! $concerne) {
            continue;
        }
        $trouves = [];
        dsiVerifier(dsiPlan($r['sql'], $r['bindings']), $vus, $trouves);
        foreach ($trouves as $d) {
            $defauts[] = $d . ' — ' . preg_replace('/\s+/', ' ', $r['sql']);
        }
    }

    return $defauts;
}

/**
 * `DSI_VOLUME` fiches ordinaires, et une ligne par fiche dans chaque table que
 * la fusion rattache — pour que le planificateur choisisse comme il le ferait
 * en production.
 */
function dsiSemerVolume(string $ws): void
{
    DB::statement("
        INSERT INTO companies (workspace_id, siren, denomination, discovery_source, postcode, created_at, updated_at)
        SELECT ?, '93' || lpad(g::text, 7, '0'), 'ZZ Volume ' || g::text, 'insee', '69' || lpad((g % 900)::text, 3, '0'), now(), now()
        FROM generate_series(1, ?) g
    ", [$ws, DSI_VOLUME]);
    $volume = "FROM companies c WHERE c.workspace_id = ? AND c.siren LIKE '93%'";
    $tag = F::tag($ws, 'zz-volume');
    $audience = (int) DB::table('email_audiences')->insertGetId(['workspace_id' => $ws, 'name' => 'ZZ Volume']);
    $evenement = (int) DB::table('events')->insertGetId(['workspace_id' => $ws, 'external_ref' => 'zz-volume', 'nom' => 'ZZ Volume', 'type' => 'salon', 'created_at' => now(), 'updated_at' => now()]);
    $pipeline = (int) DB::table('crm_pipelines')->insertGetId(['workspace_id' => $ws, 'name' => 'ZZ Volume', 'slug' => 'zz-volume']);
    $etape = (int) DB::table('pipeline_stages')->insertGetId(['workspace_id' => $ws, 'pipeline_id' => $pipeline, 'slug' => 'zz-volume', 'name' => 'ZZ Volume']);

    foreach ([
        "INSERT INTO contacts (workspace_id, company_id, first_name, last_name, created_at, updated_at)
         SELECT c.workspace_id, c.id, 'Zz', 'ZZVOLUME' || c.id::text, now(), now() {$volume}",
        "INSERT INTO activities (workspace_id, type, kind, subject_type, subject_id, created_at)
         SELECT c.workspace_id, 'note', 'scraped', 'company', c.id, now() {$volume}",
        "INSERT INTO company_tag (company_id, tag_id, workspace_id, assigned_at, assigned_by)
         SELECT c.id, {$tag}, c.workspace_id, now(), 'auto-rule' {$volume}",
        "INSERT INTO scraper_runs (workspace_id, company_id, source, status)
         SELECT c.workspace_id, c.id, 'zz', 'success' {$volume}",
        "INSERT INTO audience_members (workspace_id, audience_id, company_id)
         SELECT c.workspace_id, {$audience}, c.id {$volume}",
        "INSERT INTO event_organizers (event_id, company_id, workspace_id, created_at)
         SELECT {$evenement}, c.id, c.workspace_id, now() {$volume}",
        "INSERT INTO deals (workspace_id, company_id, pipeline_id, stage_id)
         SELECT c.workspace_id, c.id, {$pipeline}, {$etape} {$volume}",
        "INSERT INTO federations (company_id, workspace_id, famille, niveau, pertinence, contactabilite)
         SELECT c.id, c.workspace_id, 'ordre', 'national', 'haute', 'aucun_contact' {$volume}",
        "INSERT INTO media (workspace_id, name, media_type, company_id)
         SELECT c.workspace_id, 'ZZ Media ' || c.id::text, 'blog', c.id {$volume}",
        "INSERT INTO health_practitioners (workspace_id, company_id, nom, rpps)
         SELECT c.workspace_id, c.id, 'ZZ Praticien ' || c.id::text, '8' || lpad(c.id::text, 10, '0') {$volume}",
        "INSERT INTO personnes (workspace_id, company_id, person_key, premiere_source, premiere_source_at, legal_basis)
         SELECT c.workspace_id, c.id, encode(digest('zz-volume-' || c.id::text, 'sha256'), 'hex'), 'newsletter', now(), 'consent' {$volume}",
        // Des paires DÉJÀ traitées et des fusions annulées : la file et le
        // journal ont du volume, sans rien proposer.
        "INSERT INTO duplicate_flags (workspace_id, entity_type, entity_a_id, entity_b_id, similarity, motif, reviewed_at, resolution)
         SELECT c.workspace_id, 'company', c.id, c.id + 1000000000, 0.5, 'nom_cp', now(), 'keep_both' {$volume}",
    ] as $sql) {
        DB::statement($sql, [$ws]);
    }
    DB::statement("
        INSERT INTO journalists (workspace_id, media_id, company_id, last_name)
        SELECT m.workspace_id, m.id, m.company_id, 'ZZ Journaliste ' || m.id::text FROM media m WHERE m.workspace_id = ?
    ", [$ws]);
    DB::statement("
        INSERT INTO fusions_fiches (workspace_id, flag_id, garde_id, absorbee_id, motif, mode, absorbee_supprimee_le, annulee_at)
        SELECT d.workspace_id, d.id, d.entity_a_id, d.entity_b_id, 'nom_cp', 'manuel', now(), now()
        FROM duplicate_flags d WHERE d.workspace_id = ? AND d.entity_b_id > 1000000000
    ", [$ws]);
    foreach (['companies', 'contacts', 'activities', 'company_tag', 'scraper_runs', 'audience_members', 'event_organizers',
        'deals', 'federations', 'media', 'journalists', 'health_practitioners', 'personnes', 'duplicate_flags', 'fusions_fiches'] as $table) {
        DB::statement("ANALYZE {$table}");
    }
}

test('détection, fusion et annulation : chaque requête est servie par un index', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $ws = F::espace('zz-doublons-index');
    // Une paire certaine, riche de tout ce qu'une fusion rattache.
    $garde = F::fiche($ws, 'ZZ Index', ['postcode' => '69400', 'website' => 'https://zz-index.example.invalid']);
    $absorbee = F::sansSiren($ws, 'ZZ Index', ['postcode' => '69400', 'website' => 'https://zz-index.example.invalid', 'email_generic' => 'contact@zz-index.example.invalid']);
    F::fiche($ws, 'ZZ Index voisin', ['email_generic' => 'contact@zz-index.example.invalid']);
    F::proteger($ws, $absorbee, FichesProtegees::TAG_ORGANISATEURS);
    F::contact($ws, $absorbee, 'Zoe', 'ZZINDEX');
    F::contact($ws, $garde, 'Zed', 'ZZJUMEAU');
    F::contact($ws, $absorbee, 'Zed', 'ZZJUMEAU', ['email' => 'zed@zz-index.example.invalid']);
    DB::table('activities')->insert(['workspace_id' => $ws, 'type' => 'note', 'kind' => 'scraped', 'subject_type' => 'company', 'subject_id' => $absorbee, 'created_at' => now()]);
    // Du volume, dans le MÊME espace et dans CHAQUE table touchée : sans lui,
    // un index lu pour tout l'espace coûte aussi peu que le bon, et le
    // planificateur hésite.
    dsiSemerVolume($ws);

    $requetes = dsiCapturer(function () use ($ws): void {
        // Des lots de 100 sur 3 000 fiches : la proportion d'un lot sur la
        // production (5 000 sur 4,3 M) n'est pas reproductible, celle-ci s'en approche.
        Artisan::call('crm:doublons:detecter', ['--workspace' => $ws, '--lot' => 100]);
        Artisan::call('crm:doublons:fusionner', ['--workspace' => $ws]);
    });
    $fusion = (int) DB::table('fusions_fiches')->where('workspace_id', $ws)->value('id');
    expect($fusion)->toBeGreaterThan(0);
    $requetes = array_merge($requetes, dsiCapturer(fn () => WorkspaceContext::run($ws, fn () => app(FusionFiches::class)->annuler($ws, $fusion, 'test'))));

    DB::statement('SET LOCAL enable_seqscan = off');
    $vus = [];
    $defauts = dsiDefauts($requetes, $vus);

    expect($defauts)->toBe([]);
    foreach (DSI_INDEX_ATTENDUS as $groupe) {
        expect(array_intersect($groupe, array_keys($vus)))->not->toBe([], 'aucun de ces index n a servi : ' . implode(', ', $groupe));
    }
});

test('TÉMOIN — la sonde refuse un index lu pour TOUT L ESPACE', function () {
    $ws = F::espace('zz-doublons-temoin-espace');
    F::fiche($ws, 'ZZ Temoin', ['website' => 'https://zz-temoin.example.invalid']);
    DB::statement('SET LOCAL enable_seqscan = off');
    $vus = [];

    $defauts = dsiDefauts([[
        'sql' => 'select id from companies where workspace_id = ? and website = ?',
        'bindings' => [$ws, 'https://zz-temoin.example.invalid'],
    ]], $vus);

    expect($defauts)->not->toBe([]);
});

test('TÉMOIN — la sonde refuse une recherche non indexée (le domaine du site, calculé)', function () {
    F::fiche(F::espace('zz-doublons-temoin'), 'ZZ Temoin', ['website' => 'https://zz-temoin.example.invalid']);
    DB::statement('SET LOCAL enable_seqscan = off');
    $vus = [];

    $defauts = dsiDefauts([[
        'sql' => "select id from companies where split_part(regexp_replace(website, '^[a-z]+://', ''), '/', 1) = ?",
        'bindings' => ['zz-temoin.example.invalid'],
    ]], $vus);

    expect($defauts)->not->toBe([]);
});
