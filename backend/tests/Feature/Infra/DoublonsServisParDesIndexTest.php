<?php

/**
 * DOUBLONS — CHAQUE REQUÊTE PASSE PAR UN INDEX (chantier 5).
 *
 * `companies` compte 4,34 M de lignes. La détection, la fusion et son
 * annulation émettent des requêtes REJOUÉES ici par `EXPLAIN` : aucune ne doit
 * parcourir une table (`Seq Scan`), ni lire un index EN ENTIER (sans
 * condition), sur les tables qu'elles touchent.
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
    'fusions_fiches', 'adresses_partagees',
];

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
        return isset($noeud['Index Cond']);
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
    DB::statement('ANALYZE companies');

    $requetes = dsiCapturer(function () use ($ws): void {
        Artisan::call('crm:doublons:detecter', ['--workspace' => $ws]);
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
