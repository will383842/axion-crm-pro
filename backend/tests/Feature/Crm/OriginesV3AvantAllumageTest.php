<?php

use App\Crm\Ingest\ContenusAccords;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Rgpd\GdprPortabilityService;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Contrat v3 de l'origine — garde-fous À POSER AVANT D'ALLUMER l'interrupteur
 * (ADR-CONTRAT-V3-ORIGINE : I3, I4, I5). Tout est derrière `origines.v3_enabled`
 * (éteint par défaut). Aucune suppression de personne ni de donnée de personne.
 * Adresses en `.test` uniquement (dépôt PUBLIC).
 */
const OAA_SECRET = 'secret-de-test-oaa-' . 'f1e2d3c4b5a69788796a5b4c3d2e1f00';

const OAA_EMAIL = 'zz.avant-allumage@exemple.test';

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    Cache::flush();
    config([
        'crm.console_v2' => true,
        'crm.ingest.enabled' => true,
        'crm.ingest.personnes_enabled' => true,
        'crm.ingest.dossier_enabled' => true,
        'crm.ingest.hmac_secret' => OAA_SECRET,
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
        'origines.v3_enabled' => true,
    ]);

    $this->workspace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => []]);
});

function oaaEntree(array $sur = []): array
{
    return array_replace([
        'mot' => 'guide', 'reseau' => 'instagram', 'guide' => 'guide-ia', 'date' => '2026-11-01T10:00:00Z',
        'post_ref' => 'post_ref_test_01', 'canal' => 'commentaire', 'famille' => 'guide_reseau', 'detail' => 'guide_ia',
        'compte' => 'page_principale',
    ], $sur);
}

function oaaEvenement(array $origines, string $email = OAA_EMAIL): array
{
    return [
        'schema_version' => 3,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'lead_magnet_requested',
        'occurred_at' => '2026-11-01T10:00:00Z',
        'subject_ref' => 'site:guide-reseau:' . Str::uuid(),
        'person' => ['person_key' => hash('sha256', 'oaa|' . $email), 'email' => $email],
        'origines' => $origines,
    ];
}

function oaaPost(array $event): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();

    return test()->call('POST', '/api/internal/site-sync', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $timestamp,
        'HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', $timestamp . '.' . $body, OAA_SECRET),
    ], $body);
}

function oaaPersonne(string $email = OAA_EMAIL): ?object
{
    return DB::table('personnes')->where('person_key', hash('sha256', 'oaa|' . $email))->first();
}

/** Crée une personne directement (sans passer par l'ingestion) et rend sa ligne. */
function oaaCreerPersonne(string $workspaceId, string $email = OAA_EMAIL, ?array $origines = null): object
{
    $id = DB::table('personnes')->insertGetId([
        'workspace_id' => $workspaceId,
        'person_key' => hash('sha256', 'oaa|' . $email),
        'email' => $email,
        'email_hash' => hash('sha256', $email),
        'origines' => $origines === null ? null : json_encode($origines, JSON_UNESCAPED_UNICODE),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return DB::table('personnes')->where('id', $id)->first();
}

// ── (1) BLOQUANT : dossier_client + origines ────────────────────────────────

test('OAA 1 — dossier_client v3 avec « origines » à la racine, interrupteur allumé : 503 origines_v3_non_ecrivables, rien d\'acquitté', function () {
    $dossier = [
        'schema_version' => 3,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'dossier_client_synchro',
        'occurred_at' => '2026-10-20T10:00:00+02:00',
        'subject_ref' => 'site:client:0b5c3a9e-1f2d-4c6b-8a7e-9d0c1b2a3f4e',
        'company' => ['siren' => '900000777', 'name' => 'ZZ CLIENT CONSOLE SAS', 'postcode' => '75011', 'city' => 'Paris'],
        'payload' => ['dossier' => [
            'version' => 1, 'statut' => 'prospect', 'facture_emise' => false,
            'rue' => '12 rue des Tests', 'telephone' => '+33140000001', 'axi_cli' => 'AXI-CLI-004',
            'effectif_tranche' => '10-19',
            'url_console' => 'https://axion-ia.example.invalid/console/clients/0b5c3a9e-1f2d-4c6b-8a7e-9d0c1b2a3f4e',
            'questionnaire' => ['envoye_le' => '2026-10-18T09:00:00+02:00', 'recu_le' => null, 'nb_reponses_equipe' => 2],
            'contacts' => [['ref' => 'c-1', 'prenom' => 'Zoé', 'nom' => 'ZZ DIRIGEANTE', 'email' => 'zz.dirigeante@client.example.invalid', 'telephone' => '+33600000011', 'fonction' => 'Gérante']],
        ]],
        'origines' => [oaaEntree()],
    ];

    $r = oaaPost($dossier);

    $r->assertStatus(503)->assertJsonPath('error', 'origines_v3_non_ecrivables');
    expect(DB::table('activities')->where('external_ref', 'site:event:' . $dossier['event_id'])->count())->toBe(0)
        ->and(DB::table('companies')->where('siren', '900000777')->count())->toBe(0);
});

test('OAA 1b — la même charge sans « origines » reste acceptée (rien ne change pour le dossier client)', function () {
    $e = [
        'schema_version' => 1,
        'event_id' => (string) Str::uuid(),
        'event_type' => 'dossier_client_synchro',
        'occurred_at' => '2026-10-20T10:00:00+02:00',
        'subject_ref' => 'site:client:0b5c3a9e-1f2d-4c6b-8a7e-9d0c1b2a3f4e',
        'company' => ['siren' => '900000777', 'name' => 'ZZ CLIENT CONSOLE SAS', 'postcode' => '75011', 'city' => 'Paris'],
        'payload' => ['dossier' => [
            'version' => 1, 'statut' => 'prospect', 'facture_emise' => false,
            'rue' => '12 rue des Tests', 'telephone' => '+33140000001', 'axi_cli' => 'AXI-CLI-004',
            'effectif_tranche' => '10-19',
            'url_console' => 'https://axion-ia.example.invalid/console/clients/0b5c3a9e-1f2d-4c6b-8a7e-9d0c1b2a3f4e',
            'questionnaire' => ['envoye_le' => '2026-10-18T09:00:00+02:00', 'recu_le' => null, 'nb_reponses_equipe' => 2],
            'contacts' => [['ref' => 'c-1', 'prenom' => 'Zoé', 'nom' => 'ZZ DIRIGEANTE', 'email' => 'zz.dirigeante@client.example.invalid', 'telephone' => '+33600000011', 'fonction' => 'Gérante']],
        ]],
    ];

    oaaPost($e)->assertOk();
});

// ── (2) I3 : alerte quand l'archive dépasse 200 entrées ──────────────────────

test('OAA 2 — l\'archive d\'une personne passe 200 entrées : le propriétaire est alerté (une fois), rien n\'est supprimé', function () {
    $proprietaire = User::create([
        'id' => (string) Str::uuid(), 'email' => 'zz.proprio@exemple.test', 'name' => 'Propriétaire factice',
        'password_hash' => Hash::make('MotDePasseDeTest12345!'), 'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->workspace->id);
    $proprietaire->assignRole('owner');

    $personne = oaaCreerPersonne($this->workspace->id);
    // 200 entrées déjà archivées : une de plus fait franchir le seuil.
    $lignes = [];
    for ($i = 0; $i < 200; $i++) {
        $o = oaaEntree(['post_ref' => 'archive_ref_' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'date' => '2025-01-01T00:00:00Z']);
        $lignes[] = [
            'workspace_id' => $this->workspace->id, 'personne_id' => $personne->id,
            'cle' => ContenusAccords::cleOrigine($o), 'origine' => json_encode($o), 'archive_le' => now(),
        ];
    }
    DB::table('personne_origines_archive')->insert($lignes);
    // Le tableau est plein (50) : la prochaine entrée déplace la plus ancienne vers l'archive.
    $plein = [];
    for ($i = 0; $i < 50; $i++) {
        $plein[] = oaaEntree(['post_ref' => 'courant_ref_' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'date' => sprintf('2026-10-%02dT10:00:00Z', 1 + intdiv($i, 2))]);
    }
    DB::table('personnes')->where('id', $personne->id)->update(['origines' => json_encode($plein)]);

    oaaPost(oaaEvenement([oaaEntree(['post_ref' => 'nouveau_ref_0001', 'date' => '2026-11-02T10:00:00Z'])]))->assertOk();

    expect(DB::table('personne_origines_archive')->where('personne_id', $personne->id)->count())->toBe(201)
        ->and(DB::table('notifications')->where('type', 'origines.archive_volumineuse')->where('user_id', (string) $proprietaire->id)->count())->toBe(1);
    $corps = (string) DB::table('notifications')->where('type', 'origines.archive_volumineuse')->value('body');
    expect($corps)->not->toContain(OAA_EMAIL);

    // Une entrée de plus : l'archive grossit, mais on n'alerte pas à chaque charge.
    oaaPost(oaaEvenement([oaaEntree(['post_ref' => 'nouveau_ref_0002', 'date' => '2026-11-03T10:00:00Z'])]))->assertOk();
    expect(DB::table('personne_origines_archive')->where('personne_id', $personne->id)->count())->toBe(202)
        ->and(DB::table('notifications')->where('type', 'origines.archive_volumineuse')->count())->toBe(1);
});

test('OAA 2b — archive sous le seuil : aucune alerte', function () {
    oaaPost(oaaEvenement([oaaEntree()]))->assertOk();

    expect(DB::table('notifications')->where('type', 'origines.archive_volumineuse')->count())->toBe(0);
});

// ── (3) I5 : limite de débit par (post_ref, canal) ───────────────────────────

test('OAA 3 — au-delà de la limite par (post_ref, canal) : 429, rien n\'est écrit ; un autre canal ou un autre post passe', function () {
    config(['origines.limite_par_post_canal' => ['max' => 3, 'fenetre_secondes' => 3600]]);

    for ($i = 0; $i < 3; $i++) {
        oaaPost(oaaEvenement([oaaEntree(['date' => sprintf('2026-11-01T10:00:%02dZ', $i)])], "zz.lim{$i}@exemple.test"))->assertOk();
    }

    $refuse = oaaPost(oaaEvenement([oaaEntree(['date' => '2026-11-01T10:01:00Z'])], 'zz.lim-refusee@exemple.test'));
    $refuse->assertStatus(429)->assertJsonPath('error', 'origines_trop_de_charges');
    expect(oaaPersonne('zz.lim-refusee@exemple.test'))->toBeNull();

    // Autre canal, même post : compteur distinct.
    oaaPost(oaaEvenement([oaaEntree(['canal' => 'message_prive'])], 'zz.lim-autre-canal@exemple.test'))->assertOk();
    // Autre post, même canal : compteur distinct.
    oaaPost(oaaEvenement([oaaEntree(['post_ref' => 'post_ref_test_02'])], 'zz.lim-autre-post@exemple.test'))->assertOk();
});

test('OAA 3b — le rejeu d\'une charge déjà reçue ne consomme pas la limite', function () {
    config(['origines.limite_par_post_canal' => ['max' => 1, 'fenetre_secondes' => 3600]]);
    $e = oaaEvenement([oaaEntree()]);

    oaaPost($e)->assertOk();
    oaaPost($e)->assertOk();
});

test('OAA 3c — les valeurs par défaut de la limite sont en config/origines.php', function () {
    $l = config('origines.limite_par_post_canal');

    expect($l['max'])->toBeInt()->toBeGreaterThan(0)
        ->and($l['fenetre_secondes'])->toBeInt()->toBeGreaterThan(0);
});

// ── (4) I5 : masquage de commentaire_ref après 12 mois ───────────────────────

test('OAA 4 — interrupteur de masquage éteint (défaut) : la commande ne touche à rien', function () {
    config(['origines.masquage_commentaire_ref_enabled' => false]);
    $p = oaaCreerPersonne($this->workspace->id, OAA_EMAIL, [oaaEntree(['date' => '2024-01-01T00:00:00Z', 'commentaire_ref' => str_repeat('a', 64)])]);

    expect(Artisan::call('origines:masquer-commentaire-ref'))->toBe(0);

    expect(json_decode((string) DB::table('personnes')->where('id', $p->id)->value('origines'), true)[0]['commentaire_ref'])->toBe(str_repeat('a', 64));
});

test('OAA 4b — au bout de 12 mois, commentaire_ref passe à null ; le reste de l\'entrée et les entrées récentes sont intacts, rien n\'est supprimé', function () {
    config(['origines.masquage_commentaire_ref_enabled' => true]);
    $vieille = oaaEntree(['post_ref' => 'post_vieux_0001', 'date' => now()->subMonths(13)->utc()->format('Y-m-d\TH:i:s\Z'), 'commentaire_ref' => str_repeat('b', 64)]);
    $recente = oaaEntree(['post_ref' => 'post_recent_0001', 'date' => now()->subMonths(2)->utc()->format('Y-m-d\TH:i:s\Z'), 'commentaire_ref' => str_repeat('c', 64)]);
    $p = oaaCreerPersonne($this->workspace->id, OAA_EMAIL, [$vieille, $recente]);
    $autre = oaaCreerPersonne($this->workspace->id, 'zz.autre@exemple.test', null);
    $avant = DB::table('personnes')->count();

    expect(Artisan::call('origines:masquer-commentaire-ref'))->toBe(0);

    $apres = json_decode((string) DB::table('personnes')->where('id', $p->id)->value('origines'), true);
    expect($apres)->toHaveCount(2)
        ->and($apres[0]['commentaire_ref'])->toBeNull()
        ->and($apres[0]['post_ref'])->toBe('post_vieux_0001')
        ->and($apres[0]['famille'])->toBe('guide_reseau')
        ->and($apres[1]['commentaire_ref'])->toBe(str_repeat('c', 64))
        ->and(DB::table('personnes')->count())->toBe($avant)
        ->and(DB::table('personnes')->where('id', $autre->id)->value('origines'))->toBeNull();
});

test('OAA 4c — la commande est idempotente et la tâche est planifiée, sans aucun DELETE dans son code', function () {
    $evenements = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'origines:masquer-commentaire-ref'));
    expect($evenements)->toHaveCount(1);

    $source = (string) file_get_contents(base_path('app/Console/Commands/OriginesMasquerCommentaireRef.php'));
    expect(preg_match('/->delete\(|DELETE\s+FROM|TRUNCATE|forceDelete|->truncate\(/i', $source))->toBe(0);

    config(['origines.masquage_commentaire_ref_enabled' => true]);
    $p = oaaCreerPersonne($this->workspace->id, OAA_EMAIL, [oaaEntree(['date' => '2024-01-01T00:00:00Z', 'commentaire_ref' => str_repeat('d', 64)])]);
    Artisan::call('origines:masquer-commentaire-ref');
    $un = DB::table('personnes')->where('id', $p->id)->value('origines');
    Artisan::call('origines:masquer-commentaire-ref');

    expect(DB::table('personnes')->where('id', $p->id)->value('origines'))->toBe($un);
});

// ── (5) Export RGPD art. 15 : l'archive d'origines y figure ──────────────────

test('OAA 5 — l\'export art. 15 contient l\'archive d\'origines de la personne (et garde l\'existant)', function () {
    Storage::fake('local');
    $p = oaaCreerPersonne($this->workspace->id, OAA_EMAIL, [oaaEntree()]);
    $archivee = oaaEntree(['post_ref' => 'archive_ref_0001', 'date' => '2025-01-01T00:00:00Z']);
    DB::table('personne_origines_archive')->insert([
        'workspace_id' => $this->workspace->id, 'personne_id' => $p->id,
        'cle' => ContenusAccords::cleOrigine($archivee), 'origine' => json_encode($archivee), 'archive_le' => now(),
    ]);
    // L'archive d'une AUTRE personne ne sort pas.
    $autre = oaaCreerPersonne($this->workspace->id, 'zz.autre-export@exemple.test');
    DB::table('personne_origines_archive')->insert([
        'workspace_id' => $this->workspace->id, 'personne_id' => $autre->id,
        'cle' => 'x', 'origine' => json_encode(oaaEntree(['post_ref' => 'autre_ref_000001'])), 'archive_le' => now(),
    ]);

    $r = app(GdprPortabilityService::class)->export(OAA_EMAIL);
    $contenu = json_decode(Crypt::decryptString(Storage::disk('local')->get('gdpr-exports/' . $r['token'] . '.enc')), true, 512, JSON_THROW_ON_ERROR);

    expect($contenu)->toHaveKeys(['personnes', 'abonnements', 'contacts', 'activities', 'personne_origines_archive'])
        ->and($contenu['personnes'])->toHaveCount(1)
        ->and($contenu['personne_origines_archive'])->toHaveCount(1)
        ->and(json_encode($contenu['personne_origines_archive']))->toContain('archive_ref_0001')
        ->and(json_encode($contenu['personne_origines_archive']))->not->toContain('autre_ref_000001');
});
