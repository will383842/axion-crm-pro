<?php
/**
 * Agent 12 — verifications de suivi :
 *  a) la ligne supprimee par le VIEWER existe-t-elle encore ?
 *  b) la RLS etait-elle bien ARMEE pendant la sonde d etancheite ?
 *  c) le scope global `WorkspaceScope` est-il actif ?
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config(['app.debug' => false]);
config(['database.connections.pgsql.database' => 'axion_crm_a12']);

use Illuminate\Support\Facades\DB;

DB::purge('pgsql');

echo "===== VERIFICATIONS DE SUIVI — AGENT 12 =====\n";
echo 'base : ' . DB::connection()->getDatabaseName() . "\n\n";

echo "----- a) la fiche 400005 (cible du DELETE par le viewer) -----------\n";
$row = DB::selectOne('SELECT id, deleted_at FROM companies WHERE id = 400005');
echo '    ligne 400005 en base : ' . ($row === null ? 'DISPARUE — suppression DEFINITIVE' : 'presente, deleted_at=' . var_export($row->deleted_at, true)) . "\n";
echo '    le modele Company utilise-t-il SoftDeletes ? '
    . (in_array(Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(App\Models\Company::class), true) ? 'OUI' : 'NON') . "\n";
echo '    total companies restant : ' . DB::table('companies')->count() . "\n\n";

echo "----- b) etat de la RLS sur `companies` dans cette base ------------\n";
foreach (DB::select("SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND relname IN ('companies','contacts','candidates')") as $r) {
    echo "    {$r->relname} : rowsecurity=" . var_export($r->relrowsecurity, true) . ' force=' . var_export($r->relforcerowsecurity, true) . "\n";
}
echo "    politiques sur companies :\n";
foreach (DB::select("SELECT polname, pg_get_expr(polqual, polrelid) AS expr FROM pg_policy WHERE polrelid = 'public.companies'::regclass") as $r) {
    echo "      - {$r->polname} : {$r->expr}\n";
}
echo '    role de connexion : ' . DB::selectOne('SELECT current_user AS u')->u . "\n";
$su = DB::selectOne("SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user");
echo '    rolsuper=' . var_export($su->rolsuper, true) . '  rolbypassrls=' . var_export($su->rolbypassrls, true) . "\n";
echo '    proprietaire de la table companies : ' . DB::selectOne("SELECT pg_get_userbyid(relowner) AS o FROM pg_class WHERE oid='public.companies'::regclass")->o . "\n\n";

echo "----- c) le variable de session et le scope global ------------------\n";
echo '    crm.strict_workspace_scope = ' . var_export(config('crm.strict_workspace_scope'), true) . "\n";
echo '    app.current_workspace_id (hors requete) = ' . var_export(DB::selectOne("SELECT current_setting('app.current_workspace_id', true) AS v")->v, true) . "\n";

echo "    pose de la variable puis lecture croisee :\n";
DB::statement("SELECT set_config('app.current_workspace_id', '20cd81e4-de5d-4875-a759-07d64fe1f168', false)");
echo '      variable posee = ' . var_export(DB::selectOne("SELECT current_setting('app.current_workspace_id', true) AS v")->v, true) . "\n";
$n = DB::selectOne("SELECT count(*) AS c FROM companies WHERE workspace_id = '95cbe9b3-378e-4c9a-87cf-1d0faa629643'")->c;
echo "      lignes de l'AUTRE univers encore visibles en SQL direct : $n\n";
echo '      VERDICT RLS : ' . ((int) $n === 0 ? 'la RLS filtre' : 'la RLS NE FILTRE PAS pour ce role') . "\n\n";

echo "===== FIN =====\n";
