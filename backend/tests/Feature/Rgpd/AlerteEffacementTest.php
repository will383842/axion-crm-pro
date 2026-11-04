<?php

/**
 * L5-back — une demande d'effacement déclenche une alerte via la classe `Alerte` (#411),
 * sans aucune donnée de personne, sans rien supprimer, et sans jamais casser la mise à l'écart.
 */

use App\Crm\Rgpd\SignalementDemandeEffacement;
use App\Models\User;
use App\Models\Workspace;
use App\Socle\Alertes\AlerteMail;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function l5aEspaceAvecOwner(): array
{
    test()->seed(PermissionsAndRolesSeeder::class);
    $ws = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'l5a-' . Str::random(6), 'name' => 'L5A']);
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => 'will@example.test', 'name' => 'Will',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws->id, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws->id);
    $u->assignRole('owner');

    return [$ws, $u];
}

test('alertes éteintes : le filet existant écrit la notification de l’owner, rien ne part par e-mail', function () {
    [$ws, $owner] = l5aEspaceAvecOwner();
    config(['alertes.actif' => false]);
    Mail::fake();

    SignalementDemandeEffacement::signaler($ws->id, 'site');

    expect(DB::table('notifications')->where('user_id', (string) $owner->id)->where('type', SignalementDemandeEffacement::TYPE)->count())->toBe(1)
        ->and(DB::table('notifications')->where('action_url', '/rgpd/requests?statut=ouverte')->count())->toBe(1);
    Mail::assertNothingSent();
});

test('alertes allumées : une alerte crm/attention est journalisée, sans PII', function () {
    [$ws] = l5aEspaceAvecOwner();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'will@example.test']);
    Mail::fake();
    Log::spy();

    SignalementDemandeEffacement::signaler($ws->id, 'console');

    Log::shouldHaveReceived('critical')->withArgs(function ($message, $contexte = []) {
        $tout = $message . ' ' . json_encode($contexte);

        return str_contains($message, 'Demande d’effacement à traiter')
            && ! str_contains($tout, '@') && ! preg_match('/\b\d{3,}\b/', $message . ($contexte['corps'] ?? ''));
    })->once();
    expect(DB::table('notifications')->where('title', 'Demande d’effacement à traiter')->where('action_url', SignalementDemandeEffacement::LIEN)->exists())->toBeTrue();
});

test('alertes allumées : une cloche + un e-mail, et deux demandes en 5 min = une seule alerte', function () {
    [$ws] = l5aEspaceAvecOwner();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'will@example.test']);
    Mail::fake();

    SignalementDemandeEffacement::signaler($ws->id, 'site');
    SignalementDemandeEffacement::signaler($ws->id, 'site');

    Mail::assertSent(AlerteMail::class, 1);
});

test('si l’Alerte échoue (canal en panne), la mise à l’écart et le filet sont intacts et le journal reste sans PII', function () {
    [$ws] = l5aEspaceAvecOwner();
    config(['alertes.actif' => true, 'alertes.email.destinataire' => 'will@example.test']);
    // Le canal e-mail lève une exception dont le message cite l'adresse.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('boom will@example.test'));
    $vus = [];
    Log::listen(function ($e) use (&$vus): void {
        $vus[] = json_encode([$e->level, $e->message, $e->context], JSON_UNESCAPED_UNICODE);
    });

    SignalementDemandeEffacement::signaler($ws->id, 'site');

    expect(DB::table('notifications')->where('type', SignalementDemandeEffacement::TYPE)->count())->toBeGreaterThanOrEqual(1);
    expect(implode("\n", $vus))->not->toContain('will@example.test');
});

test('une demande reçue du site ne supprime aucune ligne', function () {
    [$ws] = l5aEspaceAvecOwner();
    DB::table('personnes')->insert([
        'workspace_id' => $ws->id, 'person_key' => 'pk1', 'email' => 'x@example.invalid', 'email_hash' => hash('sha256', 'x@example.invalid'),
        'premiere_source' => 'site', 'premiere_source_at' => now(), 'legal_basis' => 'consent',
    ]);
    $avant = DB::table('personnes')->count();

    SignalementDemandeEffacement::signaler($ws->id, 'site');

    expect(DB::table('personnes')->count())->toBe($avant);
});
