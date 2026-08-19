<?php
/**
 * AGENT 16 — preuve d'immuabilité de la chaîne d'audit.
 * Base JETABLE : axion_crm_a16. Aucun fichier produit modifié.
 */
require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use App\Services\Audit\AuditHashChain;

function titre(string $t): void { echo "\n========== $t ==========\n"; }

echo "BASE   : " . DB::connection()->getDatabaseName() . "\n";
echo "SECRET : " . (env('AUDIT_HASH_CHAIN_SECRET') ? 'defini (' . strlen((string) env('AUDIT_HASH_CHAIN_SECRET')) . ' car.)' : 'ABSENT -> defaut dev-only-secret-change-me') . "\n";

function verifie(string $etiquette): void {
    $sortie = new Symfony\Component\Console\Output\BufferedOutput();
    $code = Artisan::call('audit:verify-chain', [], $sortie);
    echo "[$etiquette] audit:verify-chain -> code de sortie = $code\n";
    echo "[$etiquette] sortie :\n" . $sortie->fetch() . "\n";
}

$chain = app(AuditHashChain::class);

titre('1. ETAT INITIAL (base vide)');
echo "lignes = " . DB::table('audit_logs')->count() . "\n";
verifie('vide');

titre('2. ECRITURE DE 3 MAILLONS');
foreach ([
    ['method' => 'POST',   'path' => 'api/v1/users',   'status' => 201, 'ip' => '10.0.0.1'],
    ['method' => 'DELETE', 'path' => 'api/v1/users/7', 'status' => 204, 'ip' => '10.0.0.2'],
    ['method' => 'PUT',    'path' => 'api/v1/workspace','status' => 200, 'ip' => '10.0.0.3'],
] as $r) {
    $id = $chain->record($r + ['user_agent' => 'agent-16', 'payload_hash' => hash('sha256', $r['path'])]);
    echo "insere id=$id  {$r['method']} {$r['path']}\n";
}
foreach (DB::select('SELECT id, event_type, path, status_code, ip, user_agent, left(prev_hash,16) AS prev16, left(current_hash,16) AS cur16 FROM audit_logs ORDER BY id') as $l) {
    echo json_encode($l, JSON_UNESCAPED_SLASHES) . "\n";
}

titre('3. TEMOIN POSITIF — chaine intacte');
verifie('intacte');

titre('4. ALTERATION SQL DIRECTE : status_code 204 -> 200 sur la ligne du milieu');
$ids = array_column(DB::select('SELECT id FROM audit_logs ORDER BY id'), 'id');
$cible = $ids[1];
$avant = DB::selectOne('SELECT status_code FROM audit_logs WHERE id = ?', [$cible]);
echo "cible id=$cible  status_code avant = {$avant->status_code}\n";
DB::statement('UPDATE audit_logs SET status_code = 200 WHERE id = ?', [$cible]);
echo "status_code apres = " . DB::selectOne('SELECT status_code FROM audit_logs WHERE id = ?', [$cible])->status_code . "\n";

titre('5. TEMOIN NEGATIF — la chaine doit ROUGIR');
verifie('alteree-status');

titre('6. REMISE EN ETAT');
DB::statement('UPDATE audit_logs SET status_code = ? WHERE id = ?', [$avant->status_code, $cible]);
verifie('restauree');

titre('7. ALTERATION DU user_agent (colonne STOCKEE mais HORS canonical())');
$uaAvant = DB::selectOne('SELECT user_agent FROM audit_logs WHERE id = ?', [$cible])->user_agent;
echo "user_agent avant = $uaAvant\n";
DB::statement("UPDATE audit_logs SET user_agent = 'FALSIFIE-PAR-AGENT-16' WHERE id = ?", [$cible]);
echo "user_agent apres = " . DB::selectOne('SELECT user_agent FROM audit_logs WHERE id = ?', [$cible])->user_agent . "\n";
verifie('alteree-user-agent');
DB::statement('UPDATE audit_logs SET user_agent = ? WHERE id = ?', [$uaAvant, $cible]);

titre('8. ALTERATION DU created_at (colonne STOCKEE mais HORS canonical())');
$dAvant = DB::selectOne('SELECT created_at FROM audit_logs WHERE id = ?', [$cible])->created_at;
echo "created_at avant = $dAvant\n";
DB::statement("UPDATE audit_logs SET created_at = '2019-01-01 00:00:00+00' WHERE id = ?", [$cible]);
echo "created_at apres = " . DB::selectOne('SELECT created_at FROM audit_logs WHERE id = ?', [$cible])->created_at . "\n";
verifie('alteree-created-at');
DB::statement('UPDATE audit_logs SET created_at = ? WHERE id = ?', [$dAvant, $cible]);

titre('9. SUPPRESSION DE LA DERNIERE LIGNE (troncature de queue)');
$dernier = DB::selectOne('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1');
DB::statement('DELETE FROM audit_logs WHERE id = ?', [$dernier->id]);
echo "supprime id={$dernier->id} ; reste " . DB::table('audit_logs')->count() . " lignes\n";
verifie('queue-tronquee');

titre('10. INSERT SANS prev_hash — le DEFAUT SQL repeat(0,64) est-il detecte ?');
DB::statement("INSERT INTO audit_logs (event_type, path, status_code, current_hash, created_at) VALUES ('POST','api/v1/injecte-a-la-main',200,'deadbeef',now())");
$inj = DB::selectOne("SELECT id, prev_hash, current_hash FROM audit_logs WHERE path='api/v1/injecte-a-la-main'");
echo "ligne injectee id={$inj->id} prev_hash={$inj->prev_hash} current_hash={$inj->current_hash}\n";
verifie('insert-sans-prev-hash');

titre('11. SUPPRESSION DE LA PREMIERE LIGNE (ce que ferait une purge de retention)');
DB::statement('DELETE FROM audit_logs WHERE path = ?', ['api/v1/injecte-a-la-main']);
$premier = DB::selectOne('SELECT id FROM audit_logs ORDER BY id LIMIT 1');
DB::statement('DELETE FROM audit_logs WHERE id = ?', [$premier->id]);
echo "supprime la premiere ligne id={$premier->id} ; reste " . DB::table('audit_logs')->count() . " lignes\n";
verifie('tete-purgee');

titre('12. QUOI QUE FASSE verify-chain, OU VA LA SORTIE ?');
echo "config logging default = " . config('logging.default') . "\n";
echo "Schedule audit:verify-chain — hooks declares :\n";
$sched = app(Illuminate\Console\Scheduling\Schedule::class);
foreach ($sched->events() as $e) {
    if (str_contains($e->command ?? '', 'audit:verify-chain')) {
        $ref = new ReflectionObject($e);
        foreach (['afterCallbacks','beforeCallbacks','output','shouldAppendOutput','onOneServer','emailOutputTo'] as $p) {
            if ($ref->hasProperty($p)) { $pr = $ref->getProperty($p); $pr->setAccessible(true);
                $v = $pr->getValue($e);
                echo "  $p = " . (is_array($v) ? count($v) . ' element(s)' : var_export($v, true)) . "\n";
            }
        }
        echo "  expression = {$e->expression}\n";
    }
}

titre('13. CLOISONNEMENT — RLS sur audit_logs ?');
foreach (DB::select("SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname LIKE 'audit_logs%'") as $l) {
    echo json_encode($l) . "\n";
}
echo "policies : " . count(DB::select("SELECT 1 FROM pg_policies WHERE tablename LIKE 'audit_logs%'")) . "\n";
echo "AuditLog global scopes : " . json_encode(array_keys((new App\Models\AuditLog)->getGlobalScopes())) . "\n";

titre('14. REGISTRE AI ACT — que contient la base ?');
foreach (['ai_act_register','ai_act_registry','ai_systems','llm_usage','llm_usages','llm_use_cases','business_events'] as $t) {
    $existe = DB::selectOne("SELECT to_regclass(?) AS r", ["public.$t"])->r;
    echo str_pad($t, 22) . ' : ' . ($existe ? DB::table($t)->count() . ' ligne(s)' : 'TABLE ABSENTE') . "\n";
}
echo "\nToutes les tables contenant 'ai' ou 'llm' :\n";
foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' AND (tablename LIKE '%ai%' OR tablename LIKE '%llm%') ORDER BY tablename") as $l) {
    echo "  {$l->tablename} = " . DB::table($l->tablename)->count() . "\n";
}

echo "\n===== FIN =====\n";
