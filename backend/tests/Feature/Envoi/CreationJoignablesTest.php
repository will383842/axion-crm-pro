<?php

namespace Tests\Feature\Envoi;

use App\Crm\FichesProtegees;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Creation\AssistantCreation;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;

/**
 * B8-C — estimation « joignables » vraie, lecture seule, sans DNS ni verdict écrit. Préfixe `cjo`. Adresses `*.example.test`.
 * Chaque test est joué sous le rôle applicatif (RLS) ET sous le propriétaire.
 */
uses(BaseRoutesEnvoi::class)->group('envoi');

const CJO_BROUILLONS = '/api/v1/envoi/brouillons';

beforeEach(function () {
    $this->ws = $this->espace(true);
    $this->owner = $this->compte($this->ws, 'owner');
});

function cjoSiren(): string
{
    static $n = 40000000;
    $b = str_pad((string) ++$n, 8, '0', STR_PAD_LEFT);
    for ($cle = 0; $cle <= 9; $cle++) {
        $somme = 0;
        foreach (str_split($b . $cle) as $i => $d) {
            $x = (int) $d * ($i % 2 === 1 ? 2 : 1);
            $somme += $x > 9 ? $x - 9 : $x;
        }
        if ($somme % 10 === 0) {
            return $b . $cle;
        }
    }
    throw new \LogicException('inatteignable');
}

/** Une entreprise, avec un contact nominatif si $email ; rend [entreprise, contact|null]. */
function cjoEntreprise(string $ws, ?string $email, array $plus = []): array
{
    $co = (int) DB::table('companies')->insertGetId($plus + ['workspace_id' => $ws, 'siren' => cjoSiren(), 'denomination' => 'Société fictive']);
    $ct = $email === null ? null : (int) DB::table('contacts')->insertGetId(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $co, 'email' => $email]);

    return [$co, $ct];
}

function cjoListe(string $ws, array $entreprises): int
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(5)]);
    DB::table('listes_manuelles_membres')->insert(array_map(static fn (int $co): array => ['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $co, 'origine' => 'coche'], $entreprises));

    return $liste;
}

function cjoOppose(string $ws, string $adresse): void
{
    $h = ListeSuppression::empreinte($adresse);
    $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $ws, 'empreinte_adresse' => $h, 'portee' => 'business']);
    DB::table('envoi_oppositions')->insert(['workspace_id' => $ws, 'lien_id' => $lien, 'empreinte_adresse' => $h, 'canal' => 'one_click']);
}

/** Brouillon dont la cible enregistrée est la liste ; rend l'id. */
function cjoBrouillon($t, int $liste): string
{
    $r = $t->actingAs($t->owner)->postJson(CJO_BROUILLONS, ['nom' => 'Joignables'])->assertCreated();
    $id = $r->json('id');
    $t->actingAs($t->owner)->putJson(CJO_BROUILLONS . "/{$id}/etapes/1", ['cible' => ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Liste']], ['If-Match' => (string) $r->json('version')])->assertOk();

    return $id;
}

/** Joue $f sous le mode demandé (« app » : rôle applicatif + espace ; « proprio » : propriétaire). */
function cjoSous(string $mode, string $ws, \Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

function cjoEstimer($t, string $mode, string $id, int $liste): TestResponse
{
    return cjoSous($mode, $t->ws->id, fn () => $t->actingAs($t->owner)->postJson(CJO_BROUILLONS . "/{$id}/cible/estimation", ['type' => 'liste', 'reference' => (string) $liste]));
}

/** 10 entreprises : 2 opposées, 1 presse, 1 sans contact nominatif ⇒ 6 restantes. Rend [liste, contacts propres]. */
function cjoDix($t): array
{
    $ws = $t->ws->id;
    $co = [];
    $propres = [];
    for ($i = 1; $i <= 6; $i++) {
        [$e, $c] = cjoEntreprise($ws, "propre{$i}@cjo.example.test");
        $co[] = $e;
        $propres[] = $c;
    }
    foreach ([1, 2] as $i) {
        [$e] = cjoEntreprise($ws, "oppose{$i}@cjo.example.test");
        cjoOppose($ws, "oppose{$i}@cjo.example.test");
        $co[] = $e;
    }
    [$presse] = cjoEntreprise($ws, 'presse@cjo.example.test');
    $tag = (int) DB::table('tags')->insertGetId(['workspace_id' => $ws, 'slug' => FichesProtegees::TAG_PRESSE, 'name' => 'presse']);
    DB::table('company_tag')->insert(['company_id' => $presse, 'tag_id' => $tag, 'workspace_id' => $ws]);
    $co[] = $presse;
    [$vide] = cjoEntreprise($ws, null);
    $co[] = $vide;

    return [cjoListe($ws, $co), $propres];
}

test('cjo 1 : 10 entreprises dont 2 opposées, 1 presse, 1 sans contact ⇒ restant 6, motifs comptés, complet', function (string $mode) {
    [$liste] = cjoDix($this);
    $id = cjoBrouillon($this, $liste);
    $r = cjoEstimer($this, $mode, $id, $liste)->assertOk();

    expect($r->json('total'))->toBe(10)->and($r->json('restant'))->toBe(6)->and($r->json('exclus'))->toBe(4)
        ->and($r->json('exclusions.opposition'))->toBe(2)->and($r->json('exclusions.presse'))->toBe(1)->and($r->json('exclusions.sans_contact'))->toBe(1)
        ->and($r->json('exclusions_evaluees'))->toBeTrue()->and($r->json('complet'))->toBeTrue();
})->with(['app', 'proprio']);

test('cjo 2 : verdict invalide existant ⇒ adresse_invalide 1, restant 5 ; aucun verdict écrit, aucun DNS', function (string $mode) {
    [$liste, $propres] = cjoDix($this);
    $id = cjoBrouillon($this, $liste);
    $email = DB::table('contacts')->where('id', $propres[0])->value('email');
    DB::table('envoi_verdicts_adresse')->insert(['workspace_id' => $this->ws->id, 'empreinte_adresse' => ListeSuppression::empreinte($email), 'verdict' => 'invalide', 'verifie_le' => now(), 'source' => 'test']);
    $avant = DB::table('envoi_verdicts_adresse')->count();
    Http::preventStrayRequests();

    $r = cjoEstimer($this, $mode, $id, $liste)->assertOk();

    expect($r->json('exclusions.adresse_invalide'))->toBe(1)->and($r->json('restant'))->toBe(5)
        ->and(DB::table('envoi_verdicts_adresse')->count())->toBe($avant);
})->with(['app', 'proprio']);

test('cjo 3 : horloge dépassée au 2e lot ⇒ complet faux, chiffres du 1er lot seulement', function (string $mode) {
    $ws = $this->ws->id;
    DB::statement('SET session_replication_role = replica');
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Grande']);
    for ($k = 0; $k < 501; $k++) {
        $co = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => cjoSiren(), 'denomination' => 'S']);
        DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $co, 'first_name' => 'P', 'last_name' => "N{$co}", 'email' => "g{$co}@cjo.example.test"]);
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $co, 'origine' => 'coche']);
    }
    DB::statement('SET session_replication_role = origin');
    $id = cjoBrouillon($this, $liste);
    $appels = 0;
    // Seule l'horloge injectée décide de l'arrêt au 2e lot. Le délai SQL réel (3 s, `SET LOCAL statement_timeout`) est élargi ici :
    // sinon un runner de CI ralenti plus de 3 s rendait 503 `estimation_trop_longue` (test instable). Ce délai est prouvé ailleurs.
    $this->app->bind(AssistantCreation::class, fn ($app) => new AssistantCreation($app->make(DepotCampagnes::class), function () use (&$appels) {
        return ++$appels === 1 ? 0 : 4_000_000_000;
    }, selection: new SelectionDestinataires(delaiSqlMs: 600_000)));

    $r = cjoEstimer($this, $mode, $id, $liste)->assertOk();

    expect($r->json('complet'))->toBeFalse()->and($r->json('total'))->toBe(500)->and($r->json('restant'))->toBe(500);
})->with(['app', 'proprio']);

test('cjo 4 : lecture seule, aucune instruction INSERT, UPDATE ni DELETE', function (string $mode) {
    [$liste] = cjoDix($this);
    $id = cjoBrouillon($this, $liste);
    $ecritures = [];
    DB::listen(function ($q) use (&$ecritures) {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
            $ecritures[] = $q->sql;
        }
    });

    cjoEstimer($this, $mode, $id, $liste)->assertOk();

    // la route journalise éventuellement ailleurs : on exclut l'authentification, on exige l'absence d'écriture sur les tables d'envoi
    $envoi = array_filter($ecritures, static fn (string $s): bool => str_contains($s, 'envoi_'));
    expect($envoi)->toBe([]);
})->with(['app', 'proprio']);

test('cjo 5 : la liste des cibles ne dit jamais joignables = total (nul partout)', function (string $mode) {
    [$liste] = cjoDix($this);
    DB::statement('SET session_replication_role = replica');
    DB::table('email_audiences')->insert(['workspace_id' => $this->ws->id, 'name' => 'Audience cjo', 'member_count' => 7]);
    DB::statement('SET session_replication_role = origin');
    [$e] = cjoEntreprise($this->ws->id, 'carnet@cjo.example.test');
    $r = cjoSous($mode, $this->ws->id, fn () => $this->actingAs($this->owner)->getJson('/api/v1/envoi/creation/cibles?entreprise=' . $e))->assertOk();

    $cibles = $r->json('data');
    expect($cibles)->not->toBe([])->and(array_unique(array_column($cibles, 'joignables')))->toBe([null])
        ->and(collect($cibles)->firstWhere('type', 'liste')['total'])->toBe(10);
})->with(['app', 'proprio']);

test('cjo 6 : brouillon d\'un autre espace estimé ⇒ 404', function (string $mode) {
    [$liste] = cjoDix($this);
    $id = cjoBrouillon($this, $liste);
    $autre = $this->espace(true);
    $intrus = $this->compte($autre, 'owner');
    $r = cjoSous($mode, $autre->id, fn () => $this->actingAs($intrus)->postJson(CJO_BROUILLONS . "/{$id}/cible/estimation", ['type' => 'liste', 'reference' => (string) $liste]));

    $r->assertNotFound();
})->with(['app', 'proprio']);
