<?php

/**
 * RÉSERVE B (#260) — L'EFFACEMENT NE PERD PAS UN LIEN POSÉ EN MÊME TEMPS.
 *
 * `doublons_effacer` relisait le journal d'une fusion SANS verrou puis le
 * réécrivait depuis cette copie : un déplacement ajouté pendant ce temps par
 * un import d'événements (`noterRattachement`) était écrasé, et l'annulation
 * ne rendait plus le lien. Désormais l'effacement ne touche plus le journal :
 * il supprime des lignes de `fusions_empreintes`.
 *
 * La course est JOUÉE, pas simulée : un second processus PHP ouvre une
 * transaction, ajoute un déplacement au journal (verrou tenu), le signale,
 * attend, puis valide ; pendant ce temps, ce processus-ci efface. Sans
 * `RefreshDatabase` : les deux sessions doivent voir des données validées.
 * Espace propre au test, nettoyé à la fin ; la chaîne d'audit est simulée.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-doublons-course');
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $ws = $this->ws;
    DB::transaction(function () use ($ws): void {
        DB::statement("SET LOCAL app.autoriser_suppression_absorbee = 'on'");
        DB::statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        foreach (['fusions_empreintes', 'fusions_fiches', 'company_tag', 'contacts', 'activities'] as $table) {
            DB::table($table)->where('workspace_id', $ws)->delete();
        }
        DB::table('companies')->where('workspace_id', $ws)->delete();
        DB::table('contacts_retires')->where('workspace_id', $ws)->delete();
        DB::table('workspaces')->where('id', $ws)->delete();
    });
});

test('un déplacement ajouté au journal PENDANT un effacement n est pas perdu', function () {
    $email = 'zoe.course@zz-course.example.invalid';
    $garde = F::fiche($this->ws, 'ZZ Course', ['postcode' => '69070']);
    $absorbee = F::sansSiren($this->ws, 'ZZ Course', ['postcode' => '69070', 'email_generic' => $email]);
    $fusion = WorkspaceContext::run($this->ws, fn (): int => app(FusionFiches::class)->fusionner(
        $this->ws,
        $garde,
        $absorbee,
        Rapprochement::NOM_CP,
        FusionFiches::MODE_MANUEL,
        null,
        null,
        'test',
    ));
    expect(DB::table('fusions_empreintes')->where('fusion_id', $fusion)->where('chemin', 'champs.email_generic')->exists())->toBeTrue();

    // Le second processus : l'import qui pose un lien d'événement au journal.
    $c = config('database.connections.pgsql');
    $script = (string) tempnam(sys_get_temp_dir(), 'zz-course-');
    file_put_contents($script, '<?php
        $pdo = new PDO(' . var_export(sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['database']), true) . ', '
        . var_export((string) $c['username'], true) . ', ' . var_export((string) $c['password'], true) . ', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET app.current_workspace_id = ' . "'" . $this->ws . "'" . '");
        $pdo->beginTransaction();
        $pdo->exec("UPDATE fusions_fiches SET journal = jsonb_set(journal, \'{deplacements,event_organizers}\', COALESCE(journal->\'deplacements\'->\'event_organizers\', \'[]\'::jsonb) || \'987654\'::jsonb) WHERE id = ' . $fusion . '");
        fwrite(STDOUT, "VERROU\n");
        fflush(STDOUT);
        usleep(1500000);
        $pdo->commit();
        fwrite(STDOUT, "VALIDE\n");
    ', );
    $tubes = [];
    $processus = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes);
    expect($processus)->not->toBeFalse();
    $premiere = fgets($tubes[1]);
    expect(trim((string) $premiere))->toBe('VERROU', 'Le second processus n a pas pris le verrou : ' . stream_get_contents($tubes[2]));

    // L'effacement, pendant que le second processus tient la ligne.
    $bilan = WorkspaceContext::run($this->ws, fn (): mixed => DB::selectOne(
        'SELECT public.doublons_effacer(?::uuid, ?, ?::jsonb) AS n',
        [$this->ws, $email, '[]'],
    ));

    $fin = trim((string) stream_get_contents($tubes[1]));
    fclose($tubes[1]);
    fclose($tubes[2]);
    proc_close($processus);
    @unlink($script);

    $journal = json_decode((string) DB::table('fusions_fiches')->where('id', $fusion)->value('journal'), true);
    expect($fin)->toBe('VALIDE')
        ->and((int) $bilan->n)->toBeGreaterThan(0)
        // L'empreinte effacée est partie…
        ->and(DB::table('fusions_empreintes')->where('fusion_id', $fusion)->where('chemin', 'champs.email_generic')->exists())->toBeFalse()
        // … et le lien posé en même temps est toujours au journal.
        ->and($journal['deplacements']['event_organizers'] ?? [])->toContain(987654);
});
