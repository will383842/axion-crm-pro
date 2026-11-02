<?php

/**
 * LOT S9 — ALERTES CONSOLE (CLOCHE) + E-MAIL, SANS TELEGRAM.
 *
 * Remplace l'ancienne garde Telegram. Elle défend, dans l'ordre :
 *   1. une anomalie crée une ligne `notifications` ET un e-mail, jamais d'appel réseau Telegram ;
 *   2. `read` et `read-all` sont cloisonnés par personne et par espace ;
 *   3. dix alertes identiques en 15 min = un seul e-mail ;
 *   4. l'alerte de test part sur les deux canaux, vers les deux adresses ;
 *   5. drapeau éteint par défaut : rien ne part, mais le journal garde la trace ;
 *   6. plus aucune référence active à Telegram.
 *
 * Aucun e-mail réel : `Mail::fake()`.
 */

use App\Models\User;
use App\Models\Workspace;
use App\Socle\Alertes\Alerte;
use App\Socle\Alertes\AlerteMail;
use App\Socle\Alertes\NotificationCreee;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const S9_ADRESSE = 'alertes@example.test';
const S9_SECONDE = 'veille@example.test';

function s9Espace(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 's9-ws-' . Str::random(6),
        'name' => 'Espace S9',
    ]);
}

function s9Utilisateur(Workspace $espace, ?string $email = null, string $role = 'admin'): User
{
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => $email ?? ('s9-' . Str::random(6) . '@example.test'),
        'name' => 'Personne S9',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace->id);
    $u->assignRole($role);

    return $u;
}

function s9Notification(Workspace $espace, User $u, string $titre): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id,
        'workspace_id' => $espace->id,
        'user_id' => $u->id,
        'type' => 'alerte',
        'title' => $titre,
        'created_at' => now(),
    ]);

    return $id;
}

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    Mail::fake();
    Event::fake([NotificationCreee::class]);
    Cache::flush();

    $this->espace = s9Espace();
    $this->admin = s9Utilisateur($this->espace, S9_ADRESSE);

    config([
        'alertes.actif' => true,
        'alertes.email.destinataire' => S9_ADRESSE,
        'alertes.email.seconde_adresse' => S9_SECONDE,
        'alertes.regroupement_minutes' => 15,
    ]);
});

// ── 1. UNE ANOMALIE : CLOCHE + E-MAIL, JAMAIS TELEGRAM ─────────────────────

test('S9 — une anomalie detectee ecrit dans la cloche ET envoie un e-mail, sans appel reseau', function () {
    Http::fake();
    DB::table('activities')->insert([
        'workspace_id' => $this->espace->id,
        'type' => 'newsletter_optin',
        'kind' => 'newsletter_optin',
        'occurred_at' => now(),
        'person_key' => hash('sha256', 's9-sentinelle'),
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'newsletter optin',
        'payload' => '{}',
        'created_at' => now(),
    ]);
    config(['crm.ingest.personnes_enabled' => true, 'crm.ingest.business_workspace' => $this->espace->slug]);

    expect(Artisan::call('crm:sonde-personnes'))->toBe(1);

    $ligne = DB::table('notifications')->where('user_id', $this->admin->id)->first();
    expect($ligne)->not->toBeNull()
        ->and($ligne->produit)->toBe('crm')
        ->and($ligne->gravite)->toBe('critique')
        ->and($ligne->workspace_id)->toBe($this->espace->id)
        ->and($ligne->read_at)->toBeNull();

    Mail::assertSent(AlerteMail::class, 1);
    Mail::assertSent(AlerteMail::class, fn (AlerteMail $m): bool => $m->hasTo(S9_ADRESSE));
    Event::assertDispatched(NotificationCreee::class);
    Http::assertNothingSent();
});

test('S9 — le Notifier remplit produit, gravite et lien', function () {
    app(Alerte::class)->envoyer('Titre', 'Corps', 'envoi', 'attention', '/envoi/serveurs');

    $ligne = DB::table('notifications')->where('title', 'Titre')->first();
    expect($ligne->produit)->toBe('envoi')
        ->and($ligne->gravite)->toBe('attention')
        ->and($ligne->action_url)->toBe('/envoi/serveurs');
});

// ── 2. LECTURE : PAR PERSONNE ET PAR ESPACE ────────────────────────────────

test('S9 — `read` marque UNE notification, la mienne, et refuse celle d un collegue ou d un autre espace', function () {
    $collegue = s9Utilisateur($this->espace);
    $autreEspace = s9Espace();
    $etranger = s9Utilisateur($autreEspace);

    $mienne = s9Notification($this->espace, $this->admin, 'Mienne');
    $leur = s9Notification($this->espace, $collegue, 'Collegue');
    $ailleurs = s9Notification($autreEspace, $etranger, 'Ailleurs');

    $this->actingAs($this->admin)->postJson("/api/v1/notifications/{$mienne}/read")
        ->assertOk()->assertJson(['read' => true]);
    $this->actingAs($this->admin)->postJson("/api/v1/notifications/{$leur}/read")->assertNotFound();
    $this->actingAs($this->admin)->postJson("/api/v1/notifications/{$ailleurs}/read")->assertNotFound();

    expect(DB::table('notifications')->where('id', $mienne)->value('read_at'))->not->toBeNull()
        ->and(DB::table('notifications')->where('id', $leur)->value('read_at'))->toBeNull()
        ->and(DB::table('notifications')->where('id', $ailleurs)->value('read_at'))->toBeNull();
});

test('S9 — `read-all` ne touche que MES notifications dans MON espace', function () {
    $collegue = s9Utilisateur($this->espace);
    $autreEspace = s9Espace();
    $etranger = s9Utilisateur($autreEspace);

    s9Notification($this->espace, $this->admin, 'Une');
    s9Notification($this->espace, $this->admin, 'Deux');
    $leur = s9Notification($this->espace, $collegue, 'Collegue');
    $ailleurs = s9Notification($autreEspace, $etranger, 'Ailleurs');

    $this->actingAs($this->admin)->postJson('/api/v1/notifications/read-all')
        ->assertOk()->assertJson(['marked' => 2]);

    expect(DB::table('notifications')->where('user_id', $this->admin->id)->whereNull('read_at')->count())->toBe(0)
        ->and(DB::table('notifications')->where('id', $leur)->value('read_at'))->toBeNull()
        ->and(DB::table('notifications')->where('id', $ailleurs)->value('read_at'))->toBeNull();
});

test('S9 — la liste rend produit, lien et niveau pour la cloche', function () {
    app(Alerte::class)->envoyer('Serveur muet', 'Corps', 'envoi', 'critique', '/envoi/serveurs');

    $reponse = $this->actingAs($this->admin)->getJson('/api/v1/notifications')->assertOk();

    expect($reponse->json('data.0.produit'))->toBe('envoi')
        ->and($reponse->json('data.0.lien'))->toBe('/envoi/serveurs')
        ->and($reponse->json('data.0.niveau'))->toBe('a_faire');
});

// ── 3. REGROUPEMENT ANTI-BRUIT ─────────────────────────────────────────────

test('S9 — dix alertes identiques en 15 minutes donnent UN seul e-mail', function () {
    for ($i = 0; $i < 10; $i++) {
        app(Alerte::class)->envoyer('Meme alerte', 'Meme corps', 'crm', 'critique');
    }

    Mail::assertSent(AlerteMail::class, 1);
    expect(DB::table('notifications')->where('title', 'Meme alerte')->count())->toBe(1);
});

test('S9 — une alerte DIFFERENTE n est pas absorbee par le regroupement', function () {
    app(Alerte::class)->envoyer('Alerte A', 'Corps', 'crm', 'critique');
    app(Alerte::class)->envoyer('Alerte B', 'Corps', 'crm', 'critique');

    Mail::assertSent(AlerteMail::class, 2);
});

test('S9 — passe la fenetre de 15 minutes, la meme alerte repart', function () {
    app(Alerte::class)->envoyer('Meme alerte', 'Corps', 'crm', 'critique');
    $this->travel(16)->minutes();
    app(Alerte::class)->envoyer('Meme alerte', 'Corps', 'crm', 'critique');

    Mail::assertSent(AlerteMail::class, 2);
});

// ── 4. ALERTE DE TEST ──────────────────────────────────────────────────────

test('S9 — l alerte de test arrive par les DEUX canaux et vers les DEUX adresses', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/notifications/test-alerte')->assertOk();

    Mail::assertSent(AlerteMail::class, fn (AlerteMail $m): bool => $m->hasTo(S9_ADRESSE) && $m->hasTo(S9_SECONDE));
    expect(DB::table('notifications')->where('user_id', $this->admin->id)->count())->toBe(1);
});

test('S9 — l alerte de test sans connexion est refusee', function () {
    $this->postJson('/api/v1/notifications/test-alerte')->assertUnauthorized();
    Mail::assertNothingSent();
});

test('S9 — l alerte de test n est PAS absorbee par le regroupement (on veut la voir a chaque clic)', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/notifications/test-alerte')->assertOk();
    $this->actingAs($this->admin)->postJson('/api/v1/notifications/test-alerte')->assertOk();

    Mail::assertSent(AlerteMail::class, 2);
});

// ── 5. DRAPEAU ETEINT PAR DEFAUT ───────────────────────────────────────────

test('S9 — drapeau eteint : rien dans la cloche, aucun e-mail', function () {
    config(['alertes.actif' => false]);

    expect(app(Alerte::class)->envoyer('Titre', 'Corps'))->toBeFalse();

    Mail::assertNothingSent();
    expect(DB::table('notifications')->count())->toBe(0);
});

test('S9 — le drapeau est eteint dans la configuration livree', function () {
    $source = (string) file_get_contents(config_path('alertes.php'));

    expect($source)->toContain("env('ALERTES_CONSOLE_EMAIL_ACTIVES', false)");
});

// ── 6. PLUS AUCUNE REFERENCE ACTIVE A TELEGRAM ─────────────────────────────

test('S9 — Telegram n existe plus : ni configuration, ni appel, ni etape de deploiement', function () {
    expect(config('alertes'))->not->toHaveKey('telegram');

    $racine = realpath(base_path('..')) ?: base_path('..');
    $workflow = (string) file_get_contents($racine . '/.github/workflows/deploy-direct-ssh.yml');
    expect($workflow)->not->toContain('api.telegram.org')
        ->and($workflow)->not->toContain('TELEGRAM_BOT_TOKEN');

    foreach (['Console/Commands/AnomalyDetect.php', 'Console/Commands/CrmSondePersonnes.php', 'Console/Commands/CrmSondeNonDiffusibles.php'] as $f) {
        $source = (string) file_get_contents(app_path($f));
        expect($source)->not->toContain('AlerteTelegram')
            ->and(strtolower($source))->not->toContain('telegram');
    }

    $alias = (string) file_get_contents(app_path('Services/Alertes/AlerteTelegram.php'));
    expect($alias)->not->toContain('api.telegram.org')->and($alias)->not->toContain('Http::');
});
