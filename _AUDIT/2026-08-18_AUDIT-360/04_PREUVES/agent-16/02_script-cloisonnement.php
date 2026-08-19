<?php
/** AGENT 16 — preuve 2 : cloisonnement d'audit_logs + secret de chaine. Base JETABLE axion_crm_a16. */
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\AuditLog;

echo "BASE : " . DB::connection()->getDatabaseName() . "\n\n";

echo "===== A. SECRET DE CHAINE — valeur reelle rendue par env() =====\n";
$v = env('AUDIT_HASH_CHAIN_SECRET', 'dev-only-secret-change-me');
echo "type      = " . gettype($v) . "\n";
echo "longueur  = " . strlen((string) $v) . "\n";
echo "var_export= " . var_export($v, true) . "\n";
echo "=> le secret concatene au SHA-256 est " . (strlen((string) $v) === 0 ? "LA CHAINE VIDE (aucun secret)" : "non vide") . "\n";
echo "ligne .env : ";
foreach (file('/var/www/html/.env') as $l) { if (str_starts_with($l, 'AUDIT_HASH_CHAIN_SECRET')) echo trim($l) . "\n"; }

echo "\n===== B. CLOISONNEMENT — deux espaces, un seul journal =====\n";
DB::statement('TRUNCATE audit_logs');
$wsA = (string) Illuminate\Support\Str::uuid();
$wsB = (string) Illuminate\Support\Str::uuid();
DB::table('workspaces')->insert([
    ['id' => $wsA, 'name' => 'Espace A', 'slug' => 'a16-espace-a'],
    ['id' => $wsB, 'name' => 'Espace B', 'slug' => 'a16-espace-b'],
]);
echo "espace A = $wsA\nespace B = $wsB\n";

$chain = app(App\Services\Audit\AuditHashChain::class);
$chain->record(['workspace_id' => $wsA, 'method' => 'DELETE', 'path' => 'api/v1/companies/SECRET-DE-A', 'status' => 204, 'ip' => '1.1.1.1', 'payload_hash' => 'hash-A']);
$chain->record(['workspace_id' => $wsB, 'method' => 'POST', 'path' => 'api/v1/companies', 'status' => 201, 'ip' => '2.2.2.2', 'payload_hash' => 'hash-B']);

// L'utilisateur de l'espace B pose son contexte, exactement comme SetCurrentWorkspace.
App\Support\WorkspaceContext::set($wsB);
echo "contexte pose : app.current_workspace_id = " . DB::selectOne("SELECT current_setting('app.current_workspace_id', true) AS v")->v . "\n";

echo "\n-- ce que la requete du controleur (AuditLog::query()->orderByDesc('id')->paginate(50)) rend a B :\n";
foreach (AuditLog::query()->orderByDesc('id')->paginate(50)->items() as $r) {
    echo "  id={$r->id} workspace={$r->workspace_id} " . ($r->workspace_id === $wsA ? '<<< ESPACE A, VU PAR B' : '(espace B)') . " path={$r->path}\n";
}
echo "scopes globaux du modele AuditLog : " . json_encode(array_keys((new AuditLog)->getGlobalScopes())) . "\n";
echo "politiques RLS sur audit_logs*    : " . count(DB::select("SELECT 1 FROM pg_policies WHERE tablename LIKE 'audit_logs%'")) . "\n";
echo "role applicatif BYPASSRLS ?       : " . json_encode(DB::selectOne("SELECT current_user AS u, rolbypassrls, rolsuper FROM pg_roles WHERE rolname = current_user")) . "\n";

echo "\n-- le controleur appelle-t-il authorize() ? (AuditLogPolicy::viewAny exige le role owner)\n";
$src = file_get_contents('/var/www/html/app/Http/Controllers/Api/AuditLogsController.php');
echo "occurrences de 'authorize'  : " . substr_count($src, 'authorize') . "\n";
echo "occurrences de 'workspace'  : " . substr_count($src, 'workspace') . "\n";
echo "occurrences de 'Gate'       : " . substr_count($src, 'Gate') . "\n";

echo "\n===== C. payload_hash d'une tentative de connexion =====\n";
$corps = ['email' => 'will@axion-ia.fr', 'password' => 'MotDePasse123!'];
echo "le middleware stocke payload_hash = sha256(json_encode(\$request->all()))\n";
echo "  corps      = " . json_encode($corps) . "\n";
echo "  empreinte  = " . hash('sha256', json_encode($corps)) . "\n";
echo "=> non sale, deterministe : qui lit audit_logs peut tester un dictionnaire de mots de passe\n";
echo "   hors ligne pour une adresse connue. Verification : re-calcul identique = ";
echo (hash('sha256', json_encode($corps)) === hash('sha256', json_encode(['email' => 'will@axion-ia.fr', 'password' => 'MotDePasse123!'])) ? "OUI\n" : "NON\n");

echo "\n===== D. RETENTION — pg_partman sur cette base =====\n";
$pc = DB::select("SELECT parent_table, retention, retention_keep_table, partition_interval FROM part_config WHERE parent_table LIKE '%audit_logs%'");
echo empty($pc) ? "part_config : AUCUNE ligne pour audit_logs\n" : json_encode($pc, JSON_PRETTY_PRINT) . "\n";
echo "partitions   : " . count(DB::select("SELECT 1 FROM pg_inherits WHERE inhparent='audit_logs'::regclass")) . "\n";

echo "\n===== E. NETTOYAGE =====\n";
DB::statement('TRUNCATE audit_logs');
DB::table('workspaces')->whereIn('id', [$wsA, $wsB])->delete();
echo "fait\n===== FIN =====\n";
