<?php

/**
 * D3 — UNE SUSPENSION POSÉE EN CONSOLE N'EST JAMAIS ÉCRASÉE PAR `PUT /workspace`.
 *
 * `WorkspaceController::update` lisait `settings` SANS verrou puis réécrivait `produits` depuis cette copie :
 * une suspension validée entre la lecture et l'écriture repassait le produit à « actif ».
 *
 * La course est JOUÉE : un second processus PHP (la console) ouvre une transaction, verrouille la ligne, pose
 * « suspendu », signale par un verrou consultatif, attend, puis valide. Pendant ce temps l'écran enregistre.
 * Sans `RefreshDatabase` : les deux sessions doivent voir des données validées. Espace propre au test, nettoyé.
 * Fixtures FICTIVES (dépôt public).
 */

use App\Http\Middleware\AuditHashChainLogger;
use App\Models\User;
use App\Models\Workspace;
use App\Socle\Produits\ProduitsEspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('envoi');

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    if (isset($this->espaceId)) {
        DB::table('model_has_roles')->where('model_id', $this->userId)->delete();
        DB::table('users')->where('id', $this->userId)->delete();
        DB::table('workspaces')->where('id', $this->espaceId)->delete();
    }
});

test('PUT /workspace attend le verrou de ligne et conserve la suspension validée entre-temps', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->espaceId = (string) Str::uuid();
    $this->userId = (string) Str::uuid();
    Workspace::create(['id' => $this->espaceId, 'slug' => 'zz-d3-' . Str::random(6), 'name' => 'Espace D3', 'settings' => ['produits' => ['envoi' => 'actif'], 'theme' => 'a']]);
    $user = User::create([
        'id' => $this->userId, 'email' => 'd3-' . Str::random(6) . '@exemple.test', 'name' => 'Compte D3',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $this->espaceId, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->espaceId);
    $user->assignRole('owner');

    $c = config('database.connections.pgsql');
    $signal = 7300303;
    $script = (string) tempnam(sys_get_temp_dir(), 'zz-d3-');
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']);
    $code = <<<'PHP'
        <?php
        $pdo = new PDO(%s, %s, %s, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $pdo->exec("SELECT 1 FROM workspaces WHERE id = '%s' FOR UPDATE");
        $pdo->exec("UPDATE workspaces SET settings = jsonb_set(settings, '{produits,envoi}', '\"suspendu\"') WHERE id = '%s'");
        $pdo->query('SELECT pg_advisory_xact_lock(%d)');
        usleep(2500000);
        $pdo->commit();
        echo "VALIDE";
        PHP;
    file_put_contents($script, sprintf($code, var_export($dsn, true), var_export((string) $c['username'], true), var_export((string) $c['password'], true), $this->espaceId, $this->espaceId, $signal));
    $tubes = [];
    $processus = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
    expect($processus)->not->toBeFalse();

    $tient = false;
    for ($i = 0; $i < 200 && ! $tient; $i++) {
        $tient = (int) DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND objid = ? AND granted AND pid <> pg_backend_pid()", [$signal])->n > 0;
        if (! $tient) {
            usleep(50000);
        }
    }
    expect($tient)->toBeTrue('Le second processus n a pas pris le verrou.');

    $avant = microtime(true);
    // Requête VALIDÉE (pas de RefreshDatabase) : le journal d'audit chaîné n'est pas l'objet du test et une ligne
    // restante fausserait les tests de chaîne qui attendent un journal vide.
    $this->withoutMiddleware(AuditHashChainLogger::class);
    $this->actingAs($user)->putJson('/api/v1/workspace', ['settings' => ['theme' => 'b']])->assertOk();
    $duree = microtime(true) - $avant;

    $fin = trim((string) stream_get_contents($tubes[1]));
    $erreurs = (string) stream_get_contents($tubes[2]);
    fclose($tubes[1]);
    fclose($tubes[2]);
    proc_close($processus);
    @unlink($script);

    expect($erreurs)->toBe('')->and($fin)->toBe('VALIDE');
    expect($duree)->toBeGreaterThan(1.0, 'L écran n a pas attendu le verrou de ligne.');
    expect(ProduitsEspace::etat($this->espaceId, 'envoi'))->toBe('suspendu');
    expect(json_decode((string) DB::table('workspaces')->where('id', $this->espaceId)->value('settings'), true)['theme'])->toBe('b');
});
