<?php

/**
 * G4-API (D14) — statut d'opposition lisible par le CRM : `opposition: {opposee, motifs}`.
 *
 * Lecture seule : aucune écriture, aucun événement vers le site, ni empreinte, ni date, ni contenu de registre.
 * Adresses en `*.example.test` uniquement (dépôt PUBLIC).
 */

use App\Crm\Opposition\StatutOpposition;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => 'axion-ia']);
    $this->ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'axion-ia', 'name' => 'A', 'settings' => []]);
    $this->autre = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'autre', 'name' => 'B', 'settings' => []]);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'g4@example.test', 'name' => 'G4',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->ws->id, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($user->current_workspace_id);
    $user->assignRole('admin');
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id, 'workspace_id' => $this->ws->id, 'role_slug' => 'owner',
        'invited_at' => now(), 'joined_at' => now(),
    ]);
    $this->actingAs($user);
});

function g4Hash(string $v): string
{
    return hash('sha256', mb_strtolower(trim($v)));
}

function g4Statut(string $ws, ?string $email, ?string $siren = null): array
{
    return (new StatutOpposition)->pourLignes($ws, ['x' => ['email' => $email, 'siren' => $siren]])['x'];
}

function g4Registre(string $ws, string $portee, string $valeur): void
{
    DB::table('envoi_oppositions_registre')->insert([
        'workspace_id' => $ws, 'portee' => $portee, 'empreinte' => g4Hash($valeur),
        'valeur' => $portee === 'adresse' ? null : $valeur,
        'valeur_chiffree' => $portee === 'adresse' ? Crypt::encryptString($valeur) : null,
        'motif' => 'test', 'auteur' => 'test',
    ]);
}

function g4Personne(string $ws, string $email): int
{
    return (int) DB::table('personnes')->insertGetId([
        'workspace_id' => $ws, 'person_key' => hash('sha256', 'g4|' . $email), 'email' => $email,
        'email_hash' => g4Hash($email), 'email_nature' => 'pro', 'premiere_source' => 'newsletter',
        'premiere_source_at' => now(), 'derniere_interaction_at' => now(), 'legal_basis' => 'consent',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('une adresse propre n\'est pas opposée', function () {
    expect(g4Statut($this->ws->id, 'propre@clair.example.test'))->toBe(['opposee' => false, 'motifs' => []]);
});

test('STOP (lien de désinscription) ⇒ opposée, motif stop', function () {
    $e = 'stop@clair.example.test';
    $lien = DB::table('envoi_liens_desinscription')->insertGetId([
        'workspace_id' => $this->ws->id, 'empreinte_adresse' => g4Hash($e), 'portee' => 'business',
    ]);
    DB::table('envoi_oppositions')->insert([
        'workspace_id' => $this->ws->id, 'lien_id' => $lien, 'empreinte_adresse' => g4Hash($e), 'canal' => 'one_click',
    ]);

    expect(g4Statut($this->ws->id, strtoupper($e)))->toBe(['opposee' => true, 'motifs' => ['stop']]);
});

test('opposition art. 21 ⇒ opposée, motif art21 (opt_out et demande RGPD)', function () {
    $e = 'art21@clair.example.test';
    DB::table('opt_out')->insert(['email_hash' => g4Hash($e), 'scope' => 'business', 'source' => 'gdpr_art21_console', 'reason' => 'gdpr_art21']);
    expect(g4Statut($this->ws->id, $e))->toBe(['opposee' => true, 'motifs' => ['art21']]);

    $d = 'demande@clair.example.test';
    DB::table('rgpd_requests')->insert(['workspace_id' => $this->ws->id, 'type' => 'opposition', 'status' => 'pending', 'subject_email' => $d]);
    expect(g4Statut($this->ws->id, $d))->toBe(['opposee' => true, 'motifs' => ['art21']]);

    // Une demande d'un AUTRE type, ou refusée, n'oppose personne.
    $a = 'acces@clair.example.test';
    DB::table('rgpd_requests')->insert(['workspace_id' => $this->ws->id, 'type' => 'access', 'status' => 'pending', 'subject_email' => $a]);
    expect(g4Statut($this->ws->id, $a)['opposee'])->toBeFalse();
});

test('liste de suppression, adresse du registre, domaine (et sous-domaine) et SIREN ⇒ liste_refus', function () {
    DB::table('email_suppressions')->insert(['scope' => 'business', 'email_hash' => g4Hash('rebond@clair.example.test'), 'reason' => 'hard_bounce', 'source' => 'test', 'occurrences' => 1]);
    g4Registre($this->ws->id, 'adresse', 'registre@clair.example.test');
    g4Registre($this->ws->id, 'domaine', 'bloque.example.test');
    g4Registre($this->ws->id, 'organisation', '552100554');

    $attendu = ['opposee' => true, 'motifs' => ['liste_refus']];
    expect(g4Statut($this->ws->id, 'rebond@clair.example.test'))->toBe($attendu)
        ->and(g4Statut($this->ws->id, 'registre@clair.example.test'))->toBe($attendu)
        ->and(g4Statut($this->ws->id, 'x@bloque.example.test'))->toBe($attendu)
        ->and(g4Statut($this->ws->id, 'x@equipe.bloque.example.test'))->toBe($attendu)
        ->and(g4Statut($this->ws->id, 'x@autre.example.test', '552 100 554'))->toBe($attendu)
        ->and(g4Statut($this->ws->id, 'x@bloque.example.test.autre.test')['opposee'])->toBeFalse();
});

test('un autre espace ne voit pas le registre, les STOP ni les demandes de cet espace', function () {
    $e = 'prive@clair.example.test';
    g4Registre($this->ws->id, 'adresse', $e);
    g4Registre($this->ws->id, 'domaine', 'prive.example.test');
    DB::table('rgpd_requests')->insert(['workspace_id' => $this->ws->id, 'type' => 'opposition', 'status' => 'done', 'subject_email' => $e]);

    expect(g4Statut($this->autre->id, $e)['opposee'])->toBeFalse()
        ->and(g4Statut($this->autre->id, 'z@prive.example.test')['opposee'])->toBeFalse();
});

test('nombre de requêtes identique pour 1 et 50 lignes (aucun N+1)', function () {
    $compter = function (int $n): int {
        $lignes = [];
        for ($i = 0; $i < $n; $i++) {
            $lignes[$i] = ['email' => "p{$i}@lot.example.test", 'siren' => '55210055' . ($i % 10)];
        }
        $requetes = 0;
        DB::listen(function () use (&$requetes): void {
            $requetes++;
        });
        (new StatutOpposition)->pourLignes($this->ws->id, $lignes);

        return $requetes;
    };

    expect($compter(50))->toBe($compter(1));
});

test('aucune écriture et aucun événement vers le site', function () {
    $e = 'ecriture@clair.example.test';
    DB::table('opt_out')->insert(['email_hash' => g4Hash($e), 'scope' => 'business', 'source' => 'site-sync:x', 'reason' => 'gdpr_art21']);
    g4Registre($this->ws->id, 'domaine', 'ecriture.example.test');
    $avant = DB::table('crm_outbound_events')->count();

    $ecritures = [];
    DB::listen(function ($q) use (&$ecritures): void {
        if (preg_match('/^\s*(insert|update|delete|truncate)\b/i', $q->sql) === 1) {
            $ecritures[] = $q->sql;
        }
    });
    g4Statut($this->ws->id, $e);
    $this->getJson('/api/v1/crm/personnes')->assertOk();

    expect($ecritures)->toBe([])->and(DB::table('crm_outbound_events')->count())->toBe($avant);
});

test('la liste et la fiche des personnes portent opposition, sans empreinte ni date ni registre', function () {
    $oppose = g4Personne($this->ws->id, 'oppose@ecran.example.test');
    $propre = g4Personne($this->ws->id, 'propre@ecran.example.test');
    DB::table('opt_out')->insert(['email_hash' => g4Hash('oppose@ecran.example.test'), 'scope' => 'business', 'source' => 'gdpr_art21_console', 'reason' => 'gdpr_art21', 'created_at' => '2020-01-02 03:04:05']);

    $liste = collect($this->getJson('/api/v1/crm/personnes')->assertOk()->json('data'))->keyBy('id');
    expect($liste[$oppose]['opposition'])->toBe(['opposee' => true, 'motifs' => ['art21']])
        ->and($liste[$propre]['opposition'])->toBe(['opposee' => false, 'motifs' => []]);

    $fiche = $this->getJson("/api/v1/crm/personnes/{$oppose}")->assertOk();
    expect($fiche->json('personne.opposition'))->toBe(['opposee' => true, 'motifs' => ['art21']]);

    $brut = $fiche->getContent() . $this->getJson('/api/v1/crm/personnes')->getContent();
    expect($brut)->not->toContain(g4Hash('oppose@ecran.example.test'))->not->toContain('2020-01-02');
});

test('la liste des contacts porte opposition', function () {
    $societe = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->ws->id, 'siren' => '552100554', 'denomination' => 'Société G4',
        'signals' => '{}', 'metadata' => '{}',
    ]);
    foreach (['bloque@contact.example.test' => true, 'libre@contact.example.test' => false] as $email => $oppose) {
        DB::table('contacts')->insert([
            'workspace_id' => $this->ws->id, 'company_id' => $societe, 'first_name' => $oppose ? 'Bloque' : 'Libre', 'last_name' => 'Dupont', 'email' => $email,
            'sources' => '[]', 'metadata' => '[]',
        ]);
    }
    g4Registre($this->ws->id, 'adresse', 'bloque@contact.example.test');

    $lignes = collect($this->getJson('/api/v1/contacts')->assertOk()->json('data'))->keyBy('email');
    expect($lignes['bloque@contact.example.test']['opposition'])->toBe(['opposee' => true, 'motifs' => ['liste_refus']])
        ->and($lignes['libre@contact.example.test']['opposition'])->toBe(['opposee' => false, 'motifs' => []]);
});

// ── X7 / M5 : une même adresse sous une autre forme est encore opposée ───────────────────────────────────

function x7Art21(string $adresse): void
{
    DB::table('opt_out')->insert(['email_hash' => g4Hash($adresse), 'scope' => 'business', 'source' => 'gdpr_art21_console', 'reason' => 'gdpr_art21']);
}

test('M5 — opposition sur nom@exemple.test : Nom@Exemple.test., nom+x@ et espace insécable ressortent opposées', function () {
    x7Art21('nom@exemple.test');
    $attendu = ['opposee' => true, 'motifs' => ['art21']];

    foreach (['nom@exemple.test', 'Nom@Exemple.test.', 'nom+x@exemple.test', "nom@exemple.test\u{00A0}", "nom@exemple.test\u{200B}", ' NOM+a+b@EXEMPLE.test. '] as $forme) {
        expect(g4Statut($this->ws->id, $forme))->toBe($attendu, json_encode($forme));
    }
    expect(g4Statut($this->ws->id, 'autre@exemple.test')['opposee'])->toBeFalse();
});

test('M5 — STOP : la forme avec point final ou alias est opposée (motif stop)', function () {
    $e = 'stop@forme.example.test';
    $lien = DB::table('envoi_liens_desinscription')->insertGetId(['workspace_id' => $this->ws->id, 'empreinte_adresse' => g4Hash($e), 'portee' => 'business']);
    DB::table('envoi_oppositions')->insert(['workspace_id' => $this->ws->id, 'lien_id' => $lien, 'empreinte_adresse' => g4Hash($e), 'canal' => 'one_click']);

    expect(g4Statut($this->ws->id, 'stop+promo@forme.example.test.'))->toBe(['opposee' => true, 'motifs' => ['stop']]);
});

test('M5 — domaine IDN accentué face à sa forme xn--, dans les deux sens', function () {
    $ascii = idn_to_ascii('exemplé.test', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    x7Art21("nom@{$ascii}");
    expect(g4Statut($this->ws->id, 'nom@exemplé.test')['opposee'])->toBeTrue();

    x7Art21('autre@exemplé.test');
    expect(g4Statut($this->ws->id, "autre@{$ascii}")['opposee'])->toBeTrue();
});

test('M5 — registre de domaine atteint par le domaine avec point final, la forme IDN et ses parents', function () {
    g4Registre($this->ws->id, 'domaine', 'bloque.example.test');
    $ascii = idn_to_ascii('bloqué.example.test', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
    g4Registre($this->ws->id, 'domaine', $ascii);
    $attendu = ['opposee' => true, 'motifs' => ['liste_refus']];

    expect(g4Statut($this->ws->id, 'x@bloque.example.test.'))->toBe($attendu)
        ->and(g4Statut($this->ws->id, 'x@equipe.bloque.example.test.'))->toBe($attendu)
        ->and(g4Statut($this->ws->id, 'x@bloqué.example.test'))->toBe($attendu)
        ->and(g4Statut($this->ws->id, 'x@bloque.example.test.autre.test')['opposee'])->toBeFalse();
});

test('M5 — adresse du registre atteinte par variante', function () {
    g4Registre($this->ws->id, 'adresse', 'reg@forme.example.test');
    expect(g4Statut($this->ws->id, 'Reg+x@forme.example.test.'))->toBe(['opposee' => true, 'motifs' => ['liste_refus']]);
});

test('M5 — rgpd_requests atteint par variante, sans fuite entre espaces', function () {
    DB::table('rgpd_requests')->insert(['workspace_id' => $this->ws->id, 'type' => 'opposition', 'status' => 'processing', 'subject_email' => 'dem@forme.example.test']);

    expect(g4Statut($this->ws->id, "Dem+x@Forme.example.test.\u{00A0}"))->toBe(['opposee' => true, 'motifs' => ['art21']])
        ->and(g4Statut($this->autre->id, 'dem+x@forme.example.test.')['opposee'])->toBeFalse();
});

test('M5 — les clés de l\'appelant et la forme de sortie ne changent pas', function () {
    x7Art21('cle@forme.example.test');
    $sortie = (new StatutOpposition)->pourLignes($this->ws->id, [
        'a' => ['email' => 'Cle+1@forme.example.test.', 'siren' => null],
        7 => ['email' => 'propre@forme.example.test', 'siren' => null],
    ]);

    expect($sortie)->toBe(['a' => ['opposee' => true, 'motifs' => ['art21']], 7 => ['opposee' => false, 'motifs' => []]]);
});

test('M5 — un lot de 1000 lignes reste sous la limite de 65 535 paramètres par requête', function () {
    $lignes = [];
    for ($i = 0; $i < 1000; $i++) {
        $lignes[$i] = ['email' => "Pers+{$i}@Sous.Dom{$i}.exemplé.test.", 'siren' => '55210055' . ($i % 10)];
    }
    $max = 0;
    DB::listen(function ($q) use (&$max): void {
        $max = max($max, count($q->bindings));
    });
    (new StatutOpposition)->pourLignes($this->ws->id, $lignes);

    expect($max)->toBeGreaterThan(1000)->and($max)->toBeLessThan(65535);
});
