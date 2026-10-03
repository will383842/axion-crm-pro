<?php

/**
 * LOT N2 — `crm:canaux:etat`, la sonde lue toutes les heures par
 * `.github/workflows/surveillance-canaux.yml`.
 *
 * Ce que ces gardes tiennent :
 *   1. le TÉMOIN : canaux sains → aucune alerte, code 0 (une sonde qui crierait
 *      toujours passerait pour une sonde qui marche) ;
 *   2. chaque alerte rougit dans son cas, et SEULEMENT dans son cas
 *      (file bloquée, abandon récent, site muet, refus de signature,
 *      planificateur arrêté) ; un canal fermé EXPRÈS ne rougit plus, le bruit
 *      d'Internet (POST sans aucun en-tête) non plus ;
 *   3. les refus de signature de `CanalSigneSite` alimentent bien le compteur
 *      (requêtes HTTP réelles, pas un appel direct) ;
 *   4. une mesure impossible vaut `null` + alerte, JAMAIS zéro ;
 *   5. la sortie ne contient aucune donnée personnelle (elle finit dans des
 *      issues d'un dépôt PUBLIC).
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Support\BattementPlanificateur;
use App\Support\CompteurRefusCanal;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

define('N2_SECRET', 'secret-de-test-n2-' . str_repeat('c3d4', 12));

beforeEach(function () {
    Cache::flush();
    config([
        'crm.ingest.enabled' => false,
        'crm.ingest.hmac_secret' => N2_SECRET,
        'crm.ingest.max_clock_skew_seconds' => 300,
        'crm.ingest.replay_store' => 'array',
    ]);
    Cache::store('array')->flush();
    // Le planificateur tourne (sauf dans les tests qui disent le contraire).
    BattementPlanificateur::battre();
});

function n2Espace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-n2-' . substr(str_replace('-', '', $id), 0, 8), 'name' => 'ZZ canaux',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** Une activité reçue du site, il y a `$heures` heures. */
function n2Reception(string $espace, int $heures): void
{
    $quand = now()->subHours($heures);
    DB::table('activities')->insert([
        'workspace_id' => $espace, 'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => $quand, 'person_key' => hash('sha256', Str::random(12)),
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'Formulaire — ZZ', 'payload' => '{}', 'created_at' => $quand,
    ]);
}

/** Une ligne de la file sortante, créée il y a `$creeH` heures, mise à jour il y a `$majMin` minutes. */
function n2Sortant(string $statut, int $creeH, int $majMin = 0): void
{
    DB::table('crm_outbound_events')->insert([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'consent_optout',
        'email_hash' => hash('sha256', 'zz-' . Str::random(8) . '@example.invalid'),
        'scope' => 'business',
        'origin' => 'crm',
        'payload' => '{}',
        'status' => $statut,
        'attempts' => 0,
        'created_at' => now()->subHours($creeH),
        'updated_at' => now()->subMinutes($majMin),
    ]);
}

/**
 * @param  array<string, mixed>  $options
 * @return array{code: int, etat: array<string, mixed>, brut: string}
 */
function n2Lancer(array $options = []): array
{
    $code = Artisan::call('crm:canaux:etat', $options);
    $brut = trim(Artisan::output());
    $lignes = array_values(array_filter(explode("\n", $brut), static fn (string $l): bool => str_starts_with($l, '{')));
    expect($lignes)->toHaveCount(1);

    /** @var array<string, mixed> $etat */
    $etat = json_decode($lignes[0], true, 512, JSON_THROW_ON_ERROR);

    return ['code' => $code, 'etat' => $etat, 'brut' => $brut];
}

/**
 * @param  array<string, mixed>  $etat
 * @return list<string>
 */
function n2Types(array $etat): array
{
    /** @var list<array{type: string}> $alertes */
    $alertes = $etat['alertes'];

    return array_values(array_unique(array_map(static fn (array $a): string => $a['type'], $alertes)));
}

test('TÉMOIN : canaux sains → aucune alerte, code 0, chiffres à zéro (et non null)', function () {
    n2Reception(n2Espace(), 3);
    n2Sortant('sent', 5);
    n2Sortant('pending', 1); // récent : en cours, pas bloqué

    $r = n2Lancer();

    expect($r['code'])->toBe(0)
        ->and($r['etat']['alertes'])->toBe([])
        ->and($r['etat']['file_sortante']['pending'])->toBe(1)
        ->and($r['etat']['file_sortante']['en_retard'])->toBe(0)
        ->and($r['etat']['file_sortante']['gave_up_recents'])->toBe(0)
        ->and($r['etat']['reception_site']['recus_dans_la_fenetre'])->toBe(1)
        ->and($r['etat']['reception_site']['derniere_reception_a'])->toBeString()
        ->and($r['etat']['refus_signature']['disponible'])->toBeTrue()
        ->and($r['etat']['refus_signature']['total'])->toBe(0);
});

test('file sortante BLOQUÉE : une ligne pending/failed plus vieille que le seuil rougit', function () {
    n2Reception(n2Espace(), 1);
    n2Sortant('failed', 3);

    $r = n2Lancer();

    expect($r['code'])->toBe(1)
        ->and(n2Types($r['etat']))->toBe(['file_sortante_bloquee'])
        ->and($r['etat']['file_sortante']['en_retard'])->toBe(1)
        ->and($r['etat']['file_sortante']['plus_ancienne_en_attente_a'])->toBeString();

    // Même ligne, seuil relevé à 4 h : plus d'alerte.
    expect(n2Lancer(['--seuil-age-h' => 4])['etat']['alertes'])->toBe([]);
});

test('ABANDON récent (gave_up) rougit ; un abandon ancien est compté mais ne rougit plus', function () {
    n2Reception(n2Espace(), 1);
    n2Sortant('gave_up', 40, 30 * 60); // abandonné il y a 30 h (hors fenêtre de 26 h)

    $calme = n2Lancer();
    expect($calme['etat']['alertes'])->toBe([])
        ->and($calme['etat']['file_sortante']['gave_up_total'])->toBe(1);

    n2Sortant('gave_up', 4, 20); // abandonné il y a 20 min

    $r = n2Lancer();
    expect($r['code'])->toBe(1)
        ->and(n2Types($r['etat']))->toBe(['file_sortante_abandon'])
        ->and($r['etat']['file_sortante']['gave_up_recents'])->toBe(1)
        ->and($r['etat']['file_sortante']['gave_up_total'])->toBe(2);
});

test('ABANDON vu même si GitHub saute des passages : fenêtre de 26 h, et les lignes sont nommées par leur numéro', function () {
    n2Reception(n2Espace(), 1);
    n2Sortant('gave_up', 30, 20 * 60); // abandonné il y a 20 h

    $r = n2Lancer();

    expect($r['code'])->toBe(1)
        ->and(n2Types($r['etat']))->toBe(['file_sortante_abandon'])
        ->and($r['etat']['file_sortante']['fenetre_abandon_min'])->toBe(1560)
        ->and($r['etat']['file_sortante']['gave_up_recents_ids'])->toHaveCount(1)
        ->and($r['etat']['file_sortante']['gave_up_recents_ids'][0])->toBeInt();
});

test('CANAL FERMÉ EXPRÈS : site_muet et file_sortante_bloquee neutralisés, le JSON le dit ; gave_up jamais', function () {
    n2Espace(); // aucune réception
    n2Sortant('pending', 5); // file bloquée
    n2Sortant('gave_up', 5, 10);
    config(['crm.ingest.enabled' => false, 'crm.outbound_enabled' => false]);

    $r = n2Lancer(['--fermes-expres' => 'site-vers-crm,crm-vers-site']);

    expect(n2Types($r['etat']))->toBe(['file_sortante_abandon'])
        ->and($r['etat']['reception_site']['ferme_expres'])->toBeTrue()
        ->and($r['etat']['file_sortante']['ferme_expres'])->toBeTrue()
        ->and($r['etat']['canaux_fermes_expres'])->toBe(['crm-vers-site', 'site-vers-crm']);

    // TÉMOIN : sans la déclaration, les deux alertes reviennent.
    expect(n2Types(n2Lancer()['etat']))->toBe(['file_sortante_bloquee', 'file_sortante_abandon', 'site_muet']);
});

test('CANAL déclaré fermé exprès mais drapeau OUVERT : l’alerte reste (une variable oubliée ne masque rien)', function () {
    n2Espace();
    config(['crm.ingest.enabled' => true]);

    $r = n2Lancer(['--fermes-expres' => 'site-vers-crm']);

    /** @var list<array{type: string, message: string}> $alertes */
    $alertes = $r['etat']['alertes'];
    expect(n2Types($r['etat']))->toBe(['site_muet'])
        ->and($r['etat']['reception_site']['ferme_expres'])->toBeFalse()
        ->and($alertes[0]['message'])->toContain('déclaré fermé exprès');
});

test('PLANIFICATEUR ARRÊTÉ : aucun battement depuis plus de 15 min rougit', function () {
    n2Reception(n2Espace(), 1);

    expect(n2Lancer()['etat']['alertes'])->toBe([]);

    test()->travel(16)->minutes();
    $r = n2Lancer();
    test()->travelBack();

    expect($r['code'])->toBe(1)
        ->and(n2Types($r['etat']))->toBe(['planificateur_arrete'])
        ->and($r['etat']['planificateur']['dernier_battement_a'])->toBeString();

    // Jamais battu (cache vidé) : alerte aussi, « jamais » dit en clair.
    Cache::flush();
    $jamais = n2Lancer();
    expect(n2Types($jamais['etat']))->toBe(['planificateur_arrete'])
        ->and($jamais['etat']['planificateur']['dernier_battement_a'])->toBeNull();
});

test('le battement est posé par une tâche planifiée CHAQUE MINUTE, qui écrit bien le cache', function () {
    Cache::flush();
    // Force le chargement paresseux de `routes/console.php` (même idiome que
    // VerrousDuPlanificateurTest).
    Artisan::call('list', ['--format' => 'txt']);
    $taches = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($e): bool => $e->description === BattementPlanificateur::NOM_TACHE,
    ));

    expect($taches)->toHaveCount(1)
        ->and($taches[0])->toBeInstanceOf(CallbackEvent::class)
        ->and($taches[0]->expression)->toBe('* * * * *')
        ->and(BattementPlanificateur::dernier())->toBeNull();

    $taches[0]->run(app());

    expect(BattementPlanificateur::dernier())->toBeInt();
});

test('SITE MUET : rien reçu dans la fenêtre rougit, avec la date de dernière réception', function () {
    n2Reception(n2Espace(), 72);

    $r = n2Lancer();

    expect($r['code'])->toBe(1)
        ->and(n2Types($r['etat']))->toBe(['site_muet'])
        ->and($r['etat']['reception_site']['recus_dans_la_fenetre'])->toBe(0)
        ->and($r['etat']['reception_site']['derniere_reception_a'])->toBeString();

    // Le seuil est un réglage : à 96 h, la même réception suffit.
    expect(n2Lancer(['--seuil-silence-h' => 96])['etat']['alertes'])->toBe([]);
});

test('SITE MUET : une base sans aucune réception dit « jamais », et le message nomme le drapeau fermé', function () {
    $r = n2Lancer();

    /** @var list<array{type: string, message: string}> $alertes */
    $alertes = $r['etat']['alertes'];
    expect(n2Types($r['etat']))->toBe(['site_muet'])
        ->and($r['etat']['reception_site']['derniere_reception_a'])->toBeNull()
        ->and($alertes[0]['message'])->toContain('jamais')
        ->and($alertes[0]['message'])->toContain('CRM_INGEST_ENABLED');
});

test('les réceptions de TOUS les espaces comptent (chaque espace est lu sous son contexte)', function () {
    n2Reception(n2Espace(), 2);
    n2Reception(n2Espace(), 5);

    expect(n2Lancer()['etat']['reception_site']['recus_dans_la_fenetre'])->toBe(2);
});

test('les REFUS DE SIGNATURE de CanalSigneSite alimentent le compteur (requêtes HTTP réelles)', function () {
    n2Reception(n2Espace(), 1);
    $corps = '{"event_id":"00000000-0000-4000-8000-000000000001","event_type":"form_submission"}';
    $entetes = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    // 4 signatures fausses sur site-sync…
    for ($i = 0; $i < 4; $i++) {
        test()->call('POST', '/api/internal/site-sync', [], [], [], $entetes + [
            'HTTP_X_SITE_TIMESTAMP' => (string) time(),
            'HTTP_X_SITE_SIGNATURE' => str_repeat('0', 64),
        ], $corps)->assertStatus(401)->assertJsonPath('error', 'bad_signature');
    }
    // … et 2 horodatages périmés (mais présents) sur le canal RGPD.
    for ($i = 0; $i < 2; $i++) {
        test()->call('POST', '/api/internal/site-sync/gdpr', [], [], [], $entetes + [
            'HTTP_X_SITE_TIMESTAMP' => (string) (time() - 3600),
        ], '{}')->assertStatus(401)->assertJsonPath('error', 'stale_signature');
    }

    $r = n2Lancer(['--seuil-bad-signature' => 10]);

    expect($r['etat']['refus_signature']['total'])->toBe(6)
        ->and($r['etat']['refus_signature']['par_motif'])->toBe([
            'bad_signature' => 4, 'stale_signature' => 2, 'replay_guard_unavailable' => 0, 'sans_entete' => 0,
        ])
        ->and($r['etat']['refus_signature']['par_canal'])->toBe(['site-sync' => 4, 'site-sync/gdpr' => 2])
        ->and(n2Types($r['etat']))->toBe(['refus_signature']);

    // Seuil à 6 : « au-delà » du seuil seulement.
    expect(n2Lancer(['--seuil-refus' => 6, '--seuil-bad-signature' => 10])['etat']['alertes'])->toBe([]);
});

test('BRUIT D’INTERNET : un POST sans AUCUN en-tête est compté à part (sans_entete) et ne rougit pas ; la réponse ne change pas', function () {
    n2Reception(n2Espace(), 1);
    $entetes = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    // Un scanner : 20 POST anonymes sur les deux routes publiques.
    foreach (['/api/internal/site-sync', '/api/internal/site-sync/gdpr'] as $route) {
        for ($i = 0; $i < 10; $i++) {
            $reponse = test()->call('POST', $route, [], [], [], $entetes, '{}');
            // Réponse STRICTEMENT identique à celle d'avant : même code, même corps.
            $reponse->assertStatus(401);
            expect($reponse->getContent())->toBe('{"error":"stale_signature"}');
        }
    }

    $r = n2Lancer();

    expect($r['code'])->toBe(0)
        ->and($r['etat']['alertes'])->toBe([])
        ->and($r['etat']['refus_signature']['total'])->toBe(0)
        ->and($r['etat']['refus_signature']['par_motif']['sans_entete'])->toBe(20)
        ->and($r['etat']['refus_signature']['par_motif']['stale_signature'])->toBe(0);

    // TÉMOIN : un horodatage présent mais périmé reste un `stale_signature` compté.
    test()->call('POST', '/api/internal/site-sync', [], [], [], $entetes + [
        'HTTP_X_SITE_TIMESTAMP' => (string) (time() - 3600),
    ], '{}')->assertStatus(401)->assertJsonPath('error', 'stale_signature');
    // Et une signature seule (sans horodatage) n'est pas « sans en-tête ».
    test()->call('POST', '/api/internal/site-sync', [], [], [], $entetes + [
        'HTTP_X_SITE_SIGNATURE' => str_repeat('0', 64),
    ], '{}')->assertStatus(401)->assertJsonPath('error', 'stale_signature');

    expect(n2Lancer()['etat']['refus_signature']['par_motif']['stale_signature'])->toBe(2);
});

test('SECRET DÉSALIGNÉ à faible trafic : deux bad_signature sur la fenêtre de 120 min suffisent', function () {
    n2Reception(n2Espace(), 1);

    // Le backoff du site : une tentative il y a 100 min, la suivante maintenant.
    test()->travel(-100)->minutes();
    CompteurRefusCanal::incrementer('site-sync', 'bad_signature');
    test()->travelBack();

    $une = n2Lancer();
    expect($une['etat']['alertes'])->toBe([])
        ->and($une['etat']['refus_signature']['fenetre_min'])->toBe(120)
        ->and($une['etat']['refus_signature']['par_motif']['bad_signature'])->toBe(1);

    CompteurRefusCanal::incrementer('site-sync', 'bad_signature');

    $r = n2Lancer();
    expect($r['code'])->toBe(1)
        ->and(n2Types($r['etat']))->toBe(['refus_signature'])
        ->and($r['etat']['refus_signature']['seuil_bad_signature'])->toBe(1);

    // Le seuil est un réglage.
    expect(n2Lancer(['--seuil-bad-signature' => 2])['etat']['alertes'])->toBe([]);
});

test('le compteur garde ses tranches au moins le temps de la fenêtre de lecture', function () {
    expect(CompteurRefusCanal::TTL_SECONDES)->toBeGreaterThanOrEqual(120 * 60 + CompteurRefusCanal::TRANCHE_SECONDES);
});

test('une requête correctement signée n’incrémente RIEN', function () {
    $corps = '{"event_id":"00000000-0000-4000-8000-000000000002","event_type":"form_submission"}';
    $ts = (string) time();
    test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $ts,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $corps, N2_SECRET),
    ], $corps)->assertStatus(503);

    expect(CompteurRefusCanal::lire(['site-sync', 'site-sync/gdpr'])['total'])->toBe(0);
});

test('compteur ILLISIBLE : alerte, jamais un zéro rassurant ; et l’incrément ne casse pas le refus', function () {
    n2Reception(n2Espace(), 1);
    config(['cache.stores.n2_casse' => ['driver' => 'redis', 'connection' => 'n2-inexistante']]);
    config(['crm.ingest.replay_store' => 'n2_casse']);

    // Le refus est toujours rendu, même si le compteur ne peut pas écrire.
    test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], '{}')->assertStatus(401)->assertJsonPath('error', 'stale_signature');

    $r = n2Lancer();

    expect($r['code'])->toBe(1)
        ->and($r['etat']['refus_signature']['disponible'])->toBeFalse()
        ->and(n2Types($r['etat']))->toBe(['refus_signature']);
});

test('une mesure IMPOSSIBLE vaut null et lève controle_impossible — jamais zéro', function () {
    // DDL transactionnelle sous Postgres : RefreshDatabase la défait.
    DB::statement('ALTER TABLE crm_outbound_events RENAME TO crm_outbound_events_n2');

    $r = n2Lancer();

    expect($r['code'])->toBe(1)
        ->and(n2Types($r['etat']))->toContain('controle_impossible')
        ->and($r['etat']['file_sortante']['pending'])->toBeNull()
        ->and($r['etat']['file_sortante']['en_retard'])->toBeNull();
});

test('la sortie ne contient AUCUNE donnée personnelle (ni adresse, ni IP, ni empreinte)', function () {
    $espace = n2Espace();
    n2Reception($espace, 1);
    n2Sortant('failed', 5);
    n2Sortant('gave_up', 5, 1);
    test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '203.0.113.7',
    ], '{}')->assertStatus(401);

    $brut = n2Lancer()['brut'];

    expect($brut)->not->toContain('@')
        ->and($brut)->not->toContain('203.0.113.7')
        ->and($brut)->not->toContain($espace)
        ->and(preg_match('/[0-9a-f]{64}/', $brut))->toBe(0);
});
