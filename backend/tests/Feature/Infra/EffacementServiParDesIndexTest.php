<?php

/**
 * LE CHEMIN SYNCHRONE DE L'EFFACEMENT N'EMPLOIE QUE DES INDEX (relecture P1,
 * 2026-09-29).
 *
 * Le site appelle l'effacement et l'export (`POST /internal/site-sync/gdpr`)
 * et coupe sa requête à 10 s (`axionia/src/server/crm-sync/config.ts`). La
 * console les appelle aussi. `companies` porte 4,3 M de fiches : une seule
 * recherche non indexée sur ce chemin, et la demande d'une personne expire.
 *
 * La garde rejoue les QUATRE chemins synchrones (effacement et export du site,
 * effacement console, portabilité), capture chaque requête qu'ils émettent sur
 * `companies` et `contacts`, et fait `EXPLAIN` de chacune :
 *
 *  - aucune ne doit parcourir la table (`Seq Scan on companies|contacts`) ;
 *  - chacune doit employer un index de SA liste (clé primaire ou index de
 *    l'effacement) — un index qui ne filtre que l'espace (`workspace_id`)
 *    rendrait un plan « indexé » qui lit pourtant tout l'espace.
 *
 * ⚠️ `SET LOCAL enable_seqscan = off`, ET IL FAUT LE DIRE : la base de test ne
 * porte que quelques milliers de lignes, et sur une table si petite le
 * planificateur préfère — à raison — un parcours. Le réglage ne l'interdit pas
 * (un parcours reste choisi quand AUCUN index ne peut servir la requête) : il
 * le rend seulement plus cher que tout index utilisable. La garde mesure donc
 * « un index PEUT servir cette requête », pas le choix du planificateur sur
 * 4,3 M de lignes.
 *
 * La preuve différée (`VerifierEffacementRgpd`) parcourt, elle, des tables
 * entières : elle est mise en file (`Queue::fake()` ici), et la garde vérifie
 * qu'elle l'a bien été.
 *
 * TÉMOINS : la couverture (chaque index de l'effacement apparaît au moins une
 * fois — sinon la garde mesurerait le néant) ; et une requête NON indexée
 * connue, qui doit être refusée par la même sonde.
 */

use App\Crm\Rgpd\SiteGdprService;
use App\Jobs\VerifierEffacementRgpd;
use App\Services\Audit\AuditHashChain;
use App\Services\Rgpd\GdprErasureService;
use App\Services\Rgpd\GdprPortabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const ESI_EMAIL = 'zz.cible@zz-index.example.invalid';
const ESI_MOBILE = '06 00 00 00 77';
const ESI_VOLUME = 3000;

/** @var array<string, list<string>> les index admis, par table */
const ESI_INDEX = [
    'companies' => [
        'companies_pkey',
        'idx_companies_email_generic_minuscules',
        'idx_companies_telephone_chiffres',
        'idx_companies_canaux_emails',
        'idx_companies_canaux_telephones',
    ],
    'contacts' => [
        'contacts_pkey',
        'idx_contacts_email',
        'idx_contacts_workspace_person_key',
        'idx_contacts_telephone_chiffres',
    ],
];

function esiPeupler(): string
{
    config(['crm.ingest.business_workspace' => 'axion-ia']);
    $espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Du volume, avec les colonnes cherchées REMPLIES (un index partiel sur
    // une colonne vide ne serait jamais choisi) — une personne par fiche
    // (déclencheur de score, cf. `IndexEmailRgpdServentLesRequetesTest`).
    DB::statement("
        INSERT INTO companies (workspace_id, denomination, siren, email_generic, phone, signals, created_at, updated_at)
        SELECT ?, 'ZZ Fiche ' || g::text, lpad((800000000 + g)::text, 9, '0'),
               'contact' || g::text || '@zz-volume.example.invalid', '01' || lpad(g::text, 8, '0'),
               jsonb_build_object('contact_channels', jsonb_build_object(
                   'emails', jsonb_build_array('info' || g::text || '@zz-volume.example.invalid'),
                   'phones', jsonb_build_array('02' || lpad(g::text, 8, '0')))),
               now(), now()
          FROM generate_series(1, ?) g
    ", [$espace, ESI_VOLUME]);
    DB::statement("
        INSERT INTO contacts (workspace_id, company_id, first_name, last_name, email, phone, person_key, created_at, updated_at)
        SELECT c.workspace_id, c.id, 'Zz', 'ZZVOLUME' || g::text, 'p' || g::text || '@zz-volume.example.invalid',
               '03' || lpad(g::text, 8, '0'), 'zz-pk-' || g::text, now(), now()
          FROM generate_series(1, ?) g
          JOIN companies c ON c.workspace_id = ? AND c.siren = lpad((800000000 + g)::text, 9, '0')
    ", [ESI_VOLUME, $espace]);

    // La personne cherchée, à tous ses emplacements.
    $fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace, 'siren' => '899999999', 'denomination' => 'ZZ Fiche cible',
        'email_generic' => ESI_EMAIL, 'phone' => ESI_MOBILE,
        'signals' => json_encode(['contact_channels' => ['emails' => [ESI_EMAIL], 'phones' => ['+33 6 00 00 00 77']]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $fiche, 'first_name' => 'Zoe', 'last_name' => 'ZZCIBLE',
        'email' => ESI_EMAIL, 'phone' => ESI_MOBILE, 'person_key' => 'zz-pk-cible', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::statement('ANALYZE companies');
    DB::statement('ANALYZE contacts');

    return $espace;
}

/**
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function esiCapturer(callable $travail): array
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

/** Une lecture, mise à jour ou suppression qui touche `companies` ou `contacts`. */
function esiConcerne(string $sql): bool
{
    return preg_match('/^(select|update|delete)\b/i', ltrim($sql)) === 1
        && preg_match('/"(companies|contacts)"/', $sql) === 1;
}

/** @return array<string, mixed> le plan (racine), en JSON */
function esiPlan(string $sql, array $bindings): array
{
    $ligne = (array) DB::selectOne('EXPLAIN (FORMAT JSON) ' . $sql, $bindings);

    return (array) json_decode((string) reset($ligne), true)[0]['Plan'];
}

/**
 * Un nœud bitmap est-il servi par un index de la liste ? `BitmapOr` : TOUTES
 * ses branches (une branche non servie lit tout) ; `BitmapAnd` : au moins une.
 *
 * @param  array<string, mixed>  $noeud
 * @param  list<string>  $admis
 * @param  array<string, true>  $vus
 */
function esiBitmapServi(array $noeud, array $admis, array &$vus): bool
{
    $enfants = (array) ($noeud['Plans'] ?? []);

    return match ($noeud['Node Type'] ?? '') {
        // Une condition d'index est EXIGÉE : un index partiel lu sans condition
        // (pour son seul prédicat) est un parcours de toutes les fiches qu'il couvre.
        'Bitmap Index Scan' => isset($noeud['Index Cond']) && in_array($noeud['Index Name'] ?? '', $admis, true) && ($vus[$noeud['Index Name']] = true),
        'BitmapOr' => $enfants !== [] && array_reduce($enfants, fn (bool $ok, array $e): bool => esiBitmapServi($e, $admis, $vus) && $ok, true),
        'BitmapAnd' => array_reduce($enfants, fn (bool $ok, array $e): bool => esiBitmapServi($e, $admis, $vus) || $ok, false),
        default => false,
    };
}

/**
 * Les défauts d'un plan : tout accès à `companies` ou `contacts` qui n'est pas
 * une recherche PAR un index de sa liste — parcours complet, index lu en
 * entier (sans condition), ou index étranger à l'effacement.
 *
 * @param  array<string, mixed>  $noeud
 * @param  array<string, true>  $vus
 * @param  list<string>  $defauts
 */
function esiVerifier(array $noeud, array &$vus, array &$defauts): void
{
    $table = $noeud['Relation Name'] ?? null;
    $type = $noeud['Node Type'] ?? '';
    if (is_string($table) && isset(ESI_INDEX[$table])) {
        $admis = ESI_INDEX[$table];
        if ($type === 'Seq Scan') {
            $defauts[] = "parcours complet de {$table}";
        } elseif (in_array($type, ['Index Scan', 'Index Only Scan'], true)) {
            $index = (string) ($noeud['Index Name'] ?? '');
            if (! isset($noeud['Index Cond'])) {
                $defauts[] = "index {$index} lu EN ENTIER sur {$table}";
            } elseif (! in_array($index, $admis, true)) {
                $defauts[] = "index {$index} étranger à l'effacement sur {$table}";
            } else {
                $vus[$index] = true;
            }
        } elseif ($type === 'Bitmap Heap Scan' && ! esiBitmapServi((array) ($noeud['Plans'][0] ?? []), $admis, $vus)) {
            $defauts[] = "bitmap non servi par un index de l'effacement sur {$table}";
        }
    }
    foreach ((array) ($noeud['Plans'] ?? []) as $enfant) {
        esiVerifier((array) $enfant, $vus, $defauts);
    }
}

/**
 * @param  list<array{sql: string, bindings: array<int, mixed>}>  $requetes
 * @param  array<string, true>  $vus
 * @return list<string>
 */
function esiDefauts(array $requetes, array &$vus): array
{
    $defauts = [];
    foreach ($requetes as $r) {
        if (! esiConcerne($r['sql'])) {
            continue;
        }
        $trouves = [];
        esiVerifier(esiPlan($r['sql'], $r['bindings']), $vus, $trouves);
        foreach ($trouves as $d) {
            $defauts[] = $d . ' — ' . $r['sql'];
        }
    }

    return $defauts;
}

test('P1 — effacement et export, site ET console : chaque requete sur companies et contacts est servie par un index', function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    Queue::fake();
    esiPeupler();

    // Les exports d'abord : l'effacement vide les données qu'ils lisent.
    $requetes = array_merge(
        esiCapturer(fn () => app(SiteGdprService::class)->export('zz-pk-cible', ESI_EMAIL)),
        esiCapturer(fn () => app(GdprPortabilityService::class)->export(ESI_EMAIL)),
        esiCapturer(fn () => app(SiteGdprService::class)->erase('zz-pk-cible', ESI_EMAIL, 'business')),
    );
    // La porte console, sur une seconde personne (la première est effacée).
    DB::table('contacts')->where('last_name', 'ZZVOLUME1')->update(['phone' => '06 00 00 00 88']);
    $requetes = array_merge($requetes, esiCapturer(fn () => app(GdprErasureService::class)->erase('p1@zz-volume.example.invalid')));

    // La preuve lourde est partie en file, pas dans la requête.
    Queue::assertPushed(VerifierEffacementRgpd::class);

    DB::statement('SET LOCAL enable_seqscan = off');
    $vus = [];
    $defauts = esiDefauts($requetes, $vus);

    expect($defauts)->toBe([]);
    // TÉMOIN DE COUVERTURE : chaque index de l'effacement a servi au moins une fois.
    foreach ([
        'idx_contacts_email', 'idx_contacts_telephone_chiffres', 'idx_companies_email_generic_minuscules',
        'idx_companies_telephone_chiffres', 'idx_companies_canaux_emails', 'idx_companies_canaux_telephones',
    ] as $index) {
        expect($vus)->toHaveKey($index);
    }
});

test('P1 — temoin : la sonde refuse une recherche non indexee (l ancienne recherche dans les canaux)', function () {
    esiPeupler();
    DB::statement('SET LOCAL enable_seqscan = off');

    $vus = [];
    $defauts = esiDefauts([[
        'sql' => "select \"id\", \"signals\" from \"companies\" where jsonb_exists(signals, 'contact_channels') and (signals->'contact_channels')::text ILIKE ?",
        'bindings' => ['%' . ESI_EMAIL . '%'],
    ]], $vus);

    expect($defauts)->toHaveCount(1);
});
