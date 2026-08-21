<?php

use App\Models\Journalist;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Les roles doivent exister AVANT toute attribution.
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * LE CANAL SITE ↔ CRM — CE QUI NE TRAVERSE PAS (lot 3 de la reprise A-35).
 *
 * Trois constats, trois familles de gardes :
 *
 *   B14-005 (S1) — « le site ne renvoie JAMAIS 503 : la garde ne consomme pas
 *     d'essai est une branche morte, et une panne de trois heures perd
 *     DEFINITIVEMENT l'opposition. »
 *     Mesure : `CrmFlushOutbound::dispatchOne()` ne reconnaissait qu'UN SEUL
 *     visage de l'indisponibilite, le 503 applicatif — celui que le site
 *     n'emet que s'il est DEBOUT et refuse volontairement. Les visages REELS
 *     d'un site tombe (connexion refusee, 502/504 de la passerelle, 429 d'un
 *     limiteur) tombaient dans `consumeAttempt()`. Avec `max_attempts = 8` et
 *     le backoff plafonne (1, 2, 4, 8, 16, 32, 60, 60), il faut
 *     2+4+8+16+32+60+60 = 182 minutes — TROIS HEURES DEUX — pour qu'une
 *     opposition passe en `gave_up`, etat TERMINAL que rien ne rejoue.
 *     Une opposition perdue, c'est une personne qu'on recontacte apres
 *     qu'elle a demande a ne plus l'etre.
 *
 *   B14-010 (S1) — « l'effacement d'un journaliste n'emet rien, dans le
 *     controleur MEME qui emet pour l'opposition. » Patron A-011 du depot :
 *     le correctif existait deja a deux methodes de la.
 *
 *   B14-013 (S1) — asymetrie des deux sens du canal. Ce qui est verifiable
 *     ICI, c'est le code livre : les DEFAUTS, et ce qu'il faut poser pour
 *     ouvrir chaque sens. La production n'est pas observable depuis ce depot.
 */
const CANAL_URL = 'https://site.test/api/internal/crm-webhook';

define('CANAL_SECRET', 'secret-canal-' . str_repeat('7a9b', 12));

function canalHash(string $email): string
{
    return hash('sha256', mb_strtolower(trim($email)));
}

function canalOuvrir(): void
{
    config([
        'crm.outbound_enabled' => true,
        'crm.outbound.site_webhook_url' => CANAL_URL,
        'crm.ingest.hmac_secret' => CANAL_SECRET,
    ]);
}

/**
 * Pose une ligne d'outbox DUE. `$creePar` permet de vieillir la ligne sans
 * voyager dans le temps (l'alerte de report prolonge se juge sur l'age).
 */
function canalSemer(int $attempts = 0, string $status = 'pending', ?int $creeIlYAHeures = null): string
{
    $eventId = (string) Str::uuid();
    $cree = $creeIlYAHeures === null ? now() : now()->subHours($creeIlYAHeures);

    DB::table('crm_outbound_events')->insert([
        'event_id' => $eventId,
        'event_type' => 'consent_optout',
        'person_key' => null,
        'email_hash' => canalHash('opposee@example.test'),
        'scope' => 'business',
        'origin' => 'crm',
        'payload' => '{}',
        'status' => $status,
        'attempts' => $attempts,
        'next_attempt_at' => now()->subMinute(),
        'created_at' => $cree,
        'updated_at' => $cree,
    ]);

    return $eventId;
}

/** @return object La ligne, dont l'existence est ELLE-MEME verifiee. */
function canalLigne(string $eventId): object
{
    $row = DB::table('crm_outbound_events')->where('event_id', $eventId)->first();

    // TEMOIN D'ANTI-VIDE : sans cette assertion, tout ce fichier passerait au
    // vert sur une table absente ou une ligne jamais ecrite (`$row === null`
    // rendrait `$row->status` fatal, mais `expect(null)->toBeNull()` — la
    // forme fautive classique — verdirait).
    expect($row)->not->toBeNull();

    return (object) $row;
}

/** Un espace + un compte qui le porte : le contexte que `refuserHorsEspace` lit. */
function canalCompte(string $slug): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => $slug, 'name' => 'WS ' . $slug, 'settings' => [],
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $slug . '@example.test',
        'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    // ⚠️ LE ROLE EST OBLIGATOIRE DEPUIS QUE F36-001 EST BRANCHE.
    //
    // Cette suite mesure le METIER, pas les droits, et son utilisateur n'en
    // avait aucun. Tant qu'aucune route ne portait `permission:`, cela ne se
    // voyait pas ; depuis, elle recevait 403. On lui donne `admin` : le geste
    // teste ICI est celui d'un administrateur, et le lui refuser reviendrait a
    // mesurer la garde au lieu du produit. Les droits sont mesures a leur
    // place : `tests/Feature/Rgpd/CoucheAutorisationBrancheeTest.php`.
    setPermissionsTeamId($user->current_workspace_id);
    $user->assignRole('admin');

    return [$workspace, $user];
}

function canalJournaliste(string $workspaceId, ?string $email): int
{
    return (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $workspaceId,
        'last_name' => 'Durand',
        'email' => $email,
        'source' => 'ours',
        'opt_out' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
// B14-005 — LES VISAGES REELS DE L'INDISPONIBILITE
// ═══════════════════════════════════════════════════════════════════════════

test('B14-005 : un site INJOIGNABLE (connexion refusee) ne consomme aucun essai', function () {
    canalOuvrir();

    // C'est CE cas, et non le 503, qui se produit quand le conteneur du site
    // est arrete : la requete n'atteint aucun serveur HTTP.
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to site.test port 443'));

    $eventId = canalSemer(attempts: 3);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $row = canalLigne($eventId);

    expect((int) $row->attempts)->toBe(3)
        ->and($row->status)->toBe('pending')
        ->and($row->next_attempt_at)->not->toBeNull();

    // La cause reste ECRITE : un report muet serait indistinguable d'un envoi.
    $this->assertStringContainsString('injoignable', (string) $row->last_error);
});

test('B14-005 : 502 et 504 (passerelle) ne consomment aucun essai', function (int $statut) {
    canalOuvrir();
    Http::fake([CANAL_URL => Http::response('<html>Bad Gateway</html>', $statut)]);

    $eventId = canalSemer(attempts: 5);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $row = canalLigne($eventId);

    // Un reverse-proxy debout devant une application tombee rend 502 ou 504,
    // JAMAIS le 503 applicatif : c'est le site qui est absent, pas le proxy.
    expect((int) $row->attempts)->toBe(5)
        ->and($row->status)->toBe('pending');
})->with([502, 504]);

test('B14-005 : un 429 (le site nous freine) ne consomme aucun essai', function () {
    canalOuvrir();
    Http::fake([CANAL_URL => Http::response('slow down', 429)]);

    $eventId = canalSemer(attempts: 6);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    expect((int) canalLigne($eventId)->attempts)->toBe(6);
});

test('B14-005 : trois heures de panne ne perdent PAS l\'opposition', function () {
    canalOuvrir();

    // Le compteur est tenu DANS le faux : `Http::assertSentCount()` ne voit
    // rien quand la fausse reponse leve — Laravel n'enregistre que les couples
    // requete/reponse aboutis. Mesure faite en corrigeant ce test.
    $tentatives = 0;
    Http::fake(function () use (&$tentatives) {
        $tentatives++;
        throw new ConnectionException('cURL error 7: Failed to connect');
    });

    $eventId = canalSemer();

    // Le planificateur passe toutes les 5 minutes (routes/console.php:232).
    // 48 passages = 4 heures : au-dela des 182 minutes qu'il fallait a
    // l'ancien code pour bruler les 8 essais et abandonner definitivement.
    for ($passage = 0; $passage < 48; $passage++) {
        $this->travel(5)->minutes();
        $this->artisan('crm:flush-outbound')->assertExitCode(0);
    }

    $row = canalLigne($eventId);

    expect($row->status)->not->toBe('gave_up')
        ->and((int) $row->attempts)->toBe(0)
        ->and($row->next_attempt_at)->not->toBeNull()
        ->and($row->sent_at)->toBeNull();

    // TEMOIN : la panne a bien ete VECUE — 48 emissions ont ete tentees. Sans
    // cela, un code qui n'emettrait plus rien du tout (ligne jamais relue,
    // `next_attempt_at` repousse a l'infini) passerait aussi au vert.
    expect($tentatives)->toBe(48);
});

test('B14-005 : le report PROLONGE laisse une trace datee (jamais de silence)', function () {
    canalOuvrir();
    Http::fake([CANAL_URL => Http::response('down', 502)]);

    // Une ligne nee il y a 9 heures et toujours pas partie : ce n'est plus une
    // maintenance, c'est une panne qu'un exploitant doit voir. Le prix d'un
    // essai non consomme, c'est de ne jamais s'arreter — donc de devoir crier.
    $vieille = canalSemer(creeIlYAHeures: 9);

    $journal = Log::spy();
    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $journal->shouldHaveReceived('error')->withArgs(
        fn ($message) => str_contains((string) $message, 'crm.outbound.deferred_long'),
    );
});

test('B14-005 : une ligne FRAICHE reportee n\'alerte pas (une alerte permanente n\'est plus une alerte)', function () {
    canalOuvrir();
    Http::fake([CANAL_URL => Http::response('down', 502)]);

    canalSemer();

    $journal = Log::spy();
    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    // TEMOIN de la garde precedente : si l'alerte partait a chaque report,
    // le test d'au-dessus verdirait sur un code qui hurle en permanence.
    $journal->shouldNotHaveReceived('error');
});

test('B14-005 : un 500 consomme TOUJOURS un essai (ce n\'est pas une indisponibilite)', function () {
    canalOuvrir();
    Http::fake([CANAL_URL => Http::response('boom', 500)]);

    $eventId = canalSemer(attempts: 1);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    // GARDE CONTRE L'ELARGISSEMENT ABUSIF du correctif : un 500 peut etre le
    // site qui casse sur CE message precis. Le rejouer eternellement
    // masquerait un defaut au lieu de le faire remonter en `gave_up`.
    expect((int) canalLigne($eventId)->attempts)->toBe(2)
        ->and(canalLigne($eventId)->status)->toBe('failed');
});

test('B14-005 : un 422 abandonne toujours immediatement', function () {
    canalOuvrir();
    Http::fake([CANAL_URL => Http::response(['error' => 'invalid'], 422)]);

    $eventId = canalSemer();

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    expect(canalLigne($eventId)->status)->toBe('gave_up');
});

// ═══════════════════════════════════════════════════════════════════════════
// B14-010 — L'EFFACEMENT N'EMETTAIT RIEN
// ═══════════════════════════════════════════════════════════════════════════

test('B14-010 : effacer un journaliste met un evenement erasure en file', function () {
    [$workspace, $user] = canalCompte('ws-effacement');
    $this->actingAs($user);

    $id = canalJournaliste($workspace->id, 'a-effacer@example.test');

    $this->deleteJson("/api/v1/journalists/{$id}")->assertStatus(204);

    // TEMOIN D'ANTI-VIDE : l'effacement a REELLEMENT eu lieu. Sans lui, une
    // route qui rendrait 404 sans rien faire laisserait aussi zero ligne.
    expect(Journalist::withTrashed()->find($id)?->deleted_at)->not->toBeNull();

    $row = DB::table('crm_outbound_events')->first();

    expect($row)->not->toBeNull()
        ->and($row->event_type)->toBe('erasure')
        ->and($row->origin)->toBe('crm')
        ->and($row->scope)->toBe('business')
        ->and($row->email_hash)->toBe(canalHash('a-effacer@example.test'));
});

test('B14-010 : un journaliste SANS email s\'efface quand meme, sans message inexploitable', function () {
    [$workspace, $user] = canalCompte('ws-sans-email');
    $this->actingAs($user);

    $id = canalJournaliste($workspace->id, null);

    $this->deleteJson("/api/v1/journalists/{$id}")->assertStatus(204);

    // Sans email il n'y a pas de hash, donc rien que le site puisse
    // rapprocher : mettre en file un message que personne ne peut appliquer
    // ferait grossir un backlog qui ne convergera jamais.
    expect(Journalist::withTrashed()->find($id)?->deleted_at)->not->toBeNull()
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('B14-010 : un effacement HORS ESPACE (404) n\'emet rien', function () {
    [$autreEspace] = canalCompte('ws-voisin');
    [, $user] = canalCompte('ws-appelant');
    $this->actingAs($user);

    $id = canalJournaliste($autreEspace->id, 'pas-a-moi@example.test');

    $this->deleteJson("/api/v1/journalists/{$id}")->assertNotFound();

    // Emettre AVANT la garde d'espace transformerait une tentative refusee en
    // opposition reelle chez le voisin.
    expect(Journalist::withTrashed()->find($id)?->deleted_at)->toBeNull()
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════════════════════
// B14-013 — LES DEUX SENS DU CANAL
// ═══════════════════════════════════════════════════════════════════════════

test('B14-013 : les DEUX sens du canal sont fermes par defaut, symetriquement', function () {
    // On relit la configuration LIVREE, pas celle du `beforeEach` ni celle du
    // poste. Meme precaution que `SiteSyncIngestTest.php:169` : le depot de
    // variables de Laravel interroge `$_SERVER` en premier, et un poste dont
    // le `.env` porte `CRM_INGEST_ENABLED=true` ferait repondre ce test selon
    // LA MACHINE et non selon le code livre.
    $cles = ['CRM_INGEST_ENABLED', 'CRM_INGEST_CANDIDATES_ENABLED', 'CRM_OUTBOUND_ENABLED', 'SITE_CRM_WEBHOOK_URL'];
    $avant = [];
    foreach ($cles as $cle) {
        $avant[$cle] = [$_SERVER[$cle] ?? null, $_ENV[$cle] ?? null, getenv($cle)];
        unset($_SERVER[$cle], $_ENV[$cle]);
        putenv($cle);
    }

    try {
        $defauts = require config_path('crm.php');

        // LE point du constat : aucun des deux sens n'est ouvert SANS l'autre.
        // Si un jour quelqu'un ouvre l'ingestion par defaut en laissant
        // l'emission fermee, c'est ici que ca doit rougir — c'est exactement
        // l'asymetrie que B14-013 decrit.
        expect($defauts['ingest']['enabled'])->toBeFalse()
            ->and($defauts['ingest']['candidates_enabled'])->toBeFalse()
            ->and($defauts['outbound_enabled'])->toBeFalse();

        expect((bool) $defauts['ingest']['enabled'])->toBe((bool) $defauts['outbound_enabled']);

        // TEMOIN D'ANTI-VIDE : le fichier lu est bien CELUI du canal, et non un
        // tableau vide (`$defauts['x'] ?? null` serait faux-vert).
        expect($defauts)->toHaveKeys(['ingest', 'outbound_enabled', 'outbound']);
    } finally {
        foreach ($cles as $cle) {
            [$serveur, $env, $getenv] = $avant[$cle];
            if ($serveur !== null) {
                $_SERVER[$cle] = $serveur;
            }
            if ($env !== null) {
                $_ENV[$cle] = $env;
            }
            if ($getenv !== false) {
                putenv("{$cle}={$getenv}");
            }
        }
    }
});

test('B14-013 : canal a MOITIE ouvert (drapeau ON, destination absente) : le refus est JOURNALISE', function () {
    Http::fake();
    config([
        'crm.outbound_enabled' => true,
        'crm.outbound.site_webhook_url' => '',
        'crm.ingest.hmac_secret' => CANAL_SECRET,
    ]);

    canalSemer();

    $journal = Log::spy();
    $this->artisan('crm:flush-outbound')->assertExitCode(1);

    // Le planificateur EXECUTE la commande des que le drapeau est ON (le
    // `skip()` ne retient plus rien) et jette sa sortie standard. Sans trace
    // journalisee, le canal reste ferme dans le sens CRM -> site pendant que
    // le sens site -> CRM coule, et personne ne le voit.
    $journal->shouldHaveReceived('error')->withArgs(
        fn ($message) => str_contains((string) $message, 'crm.outbound.destination_absente'),
    );

    Http::assertNothingSent();
});

test('B14-013 : drapeau OFF : aucune alerte (l\'inertie est l\'etat NOMINAL avant bascule)', function () {
    Http::fake();
    config([
        'crm.outbound_enabled' => false,
        'crm.outbound.site_webhook_url' => '',
        'crm.ingest.hmac_secret' => '',
    ]);

    $journal = Log::spy();
    $this->artisan('crm:flush-outbound')->assertExitCode(1);

    // TEMOIN de la garde precedente : le drapeau ferme est une DECISION, pas
    // une panne. Alerter ici noierait l'alerte du canal a moitie ouvert.
    $journal->shouldNotHaveReceived('error');
});
