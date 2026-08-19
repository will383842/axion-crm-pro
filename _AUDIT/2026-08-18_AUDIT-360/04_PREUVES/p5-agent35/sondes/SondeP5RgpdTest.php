<?php

/**
 * SONDE P5 — ce que 46848d4 laisse ouvert.
 */

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function compteP5(string $role): User
{
    $ws = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'p5r-' . Str::random(8),
        'name' => 'Espace P5',
    ]);
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@p5r.test',
        'name' => ucfirst($role),
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $ws->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws->id);
    $u->assignRole($role);

    return $u;
}

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

test('P5-F — le journal d audit reste-t-il inter-espaces pour un ADMIN ?', function () {
    $adminA = compteP5('admin');
    $adminB = compteP5('admin');

    // Une ligne de journal appartenant a l espace de B, que A ne doit pas voir.
    AuditLog::create([
        'workspace_id' => $adminB->current_workspace_id,
        'user_id' => $adminB->id,
        'event_type' => 'SECRET.DE.L.ESPACE.B',
        'path' => '/api/v1/secret-de-b',
        'status_code' => 200,
        'payload_hash' => str_repeat('a', 64),
        'prev_hash' => str_repeat('0', 64),
        'current_hash' => str_repeat('b', 64),
    ]);
    // Temoin : une ligne de l espace de A, que A DOIT voir.
    AuditLog::create([
        'workspace_id' => $adminA->current_workspace_id,
        'user_id' => $adminA->id,
        'event_type' => 'LIGNE.DE.L.ESPACE.A',
        'path' => '/api/v1/chez-a',
        'status_code' => 200,
        'payload_hash' => str_repeat('c', 64),
        'prev_hash' => str_repeat('b', 64),
        'current_hash' => str_repeat('d', 64),
    ]);

    $r = $this->actingAs($adminA)->getJson('/api/v1/audit-logs');
    $corps = (string) $r->getContent();

    fwrite(STDERR, sprintf(
        "\n[P5-F] admin de l espace A lit GET /audit-logs -> statut=%d\n"
        . "       voit la ligne de SON espace ?      %s   (temoin positif)\n"
        . "       voit la ligne de l espace VOISIN ? %s   <<< si OUI, la fuite inter-espaces demeure\n",
        $r->status(),
        str_contains($corps, 'LIGNE.DE.L.ESPACE.A') ? 'OUI' : 'NON',
        str_contains($corps, 'SECRET.DE.L.ESPACE.B') ? 'OUI' : 'NON',
    ));

    expect($r->status())->toBeInt();
});

test('P5-G — routes voisines laissees sans permission', function () {
    $viewer = compteP5('viewer');

    $verif = $this->actingAs($viewer)->getJson('/api/v1/audit-logs/verify-chain');
    $aiGet = $this->actingAs($viewer)->getJson('/api/v1/ai-act/register');
    $aiPost = $this->actingAs($viewer)->postJson('/api/v1/ai-act/register', [
        'system_name' => 'sonde-p5',
        'risk_level' => 'limited',
    ]);

    fwrite(STDERR, sprintf(
        "\n[P5-G] compte VIEWER (lecture seule), apres 46848d4 :\n"
        . "       GET  /audit-logs              -> %d  (temoin : doit etre 403)\n"
        . "       GET  /audit-logs/verify-chain -> %d\n"
        . "       GET  /ai-act/register         -> %d\n"
        . "       POST /ai-act/register         -> %d\n",
        $this->actingAs($viewer)->getJson('/api/v1/audit-logs')->status(),
        $verif->status(),
        $aiGet->status(),
        $aiPost->status(),
    ));

    expect($verif->status())->toBeInt();
});
