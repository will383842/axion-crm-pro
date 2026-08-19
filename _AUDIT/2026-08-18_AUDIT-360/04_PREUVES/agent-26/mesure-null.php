<?php
/**
 * AGENT 26 — mesure du comportement `neq` / `not_in` sur colonne NULL.
 *
 * LECTURE SEULE. Base `axion_crm_perf` (300 000 companies, sector_main NULL partout).
 * Joue le VRAI service App\Services\Audiences\AudienceBuilderService — pas une
 * reimplementation — via buildPublicQuery() (public) et companyMatchesCriteria()
 * (privee, atteinte par reflexion). Aucun INSERT / UPDATE / DELETE.
 *
 * Lancement : docker exec axion-crm-api php /tmp/mesure-null.php
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Support\Facades\DB;

// On epingle la base explicitement : la mesure doit dire sur quoi elle porte.
config(['database.connections.pgsql.database' => 'axion_crm_perf']);
DB::purge('pgsql');

$ws = '20cd81e4-de5d-4875-a759-07d64fe1f168';
$svc = app(AudienceBuilderService::class);

$ref = new ReflectionClass(AudienceBuilderService::class);
$matchM = $ref->getMethod('companyMatchesCriteria');
$matchM->setAccessible(true);

echo "=== CONTEXTE ===\n";
echo 'base            : '.DB::connection()->getDatabaseName()."\n";
echo 'companies (ws)  : '.Company::where('workspace_id', $ws)->count()."\n";
echo 'sector_main NULL: '.Company::where('workspace_id', $ws)->whereNull('sector_main')->count()."\n";
$one = Company::where('workspace_id', $ws)->first();
echo 'fiche temoin    : id='.$one->id.'  sector_main='.var_export($one->sector_main, true)."\n\n";

$cases = [
    'A  neq btp'        => [['field' => 'sector_main',   'op' => 'neq',    'value' => 'btp']],
    'B  not_in [btp]'   => [['field' => 'sector_main',   'op' => 'not_in', 'value' => ['btp']]],
    'C  eq btp'         => [['field' => 'sector_main',   'op' => 'eq',     'value' => 'btp']],
    'D  gte score 50'   => [['field' => 'quality_score', 'op' => 'gte',    'value' => 50]],
    'E  champ inconnu'  => [['field' => 'champ_qui_nexiste_pas', 'op' => 'eq', 'value' => 'x']],
    'F  has_email neq'  => [['field' => 'has_email',     'op' => 'neq',    'value' => true]],
    'G  in non-tableau' => [['field' => 'sector_main',   'op' => 'in',     'value' => 'btp']],
    'H  op inconnu'     => [['field' => 'sector_main',   'op' => 'regex',  'value' => 'b.*']],
];

echo "=== LES DEUX EVALUATEURS DU MEME CRITERE, COTE A COTE ===\n";
echo "(SQL = refresh()/preview() ; MEMOIRE = evaluateForCompany(), chemin waterfall step12)\n\n";
printf("%-17s | %9s | %-7s | %s\n", 'CAS', 'SQL', 'MEMOIRE', 'PREDICAT SQL GENERE');
echo str_repeat('-', 132)."\n";
foreach ($cases as $label => $conds) {
    $c = ['all' => $conds];
    $q = $svc->buildPublicQuery($ws, $c);
    $n = $q->count();
    $mem = $matchM->invoke($svc, $one, $c) ? 'GARDE' : 'EXCLUE';
    $sql = preg_replace('/^select \* from "companies" where /', '', $q->toSql());
    printf("%-17s | %9d | %-7s | %s\n", $label, $n, $mem, $sql);
}

echo "\n=== TEMOIN NEGATIF : le SQL naif (semantique d'avant le correctif) ===\n";
echo "Si le controle est capable de distinguer, ces deux lignes doivent rendre 0\n";
echo "la ou A et B rendent 300000.\n";
echo "  where sector_main != 'btp'       -> ".DB::table('companies')->where('workspace_id', $ws)->where('sector_main', '!=', 'btp')->count()."\n";
echo "  where sector_main not in ('btp') -> ".DB::table('companies')->where('workspace_id', $ws)->whereNotIn('sector_main', ['btp'])->count()."\n";

echo "\n=== TEMOIN NEGATIF 2 : sur une valeur RENSEIGNEE, neq doit exclure ===\n";
$sect = DB::table('companies')->where('workspace_id', $ws)->whereNotNull('sector_main')->count();
echo "  companies avec sector_main renseigne : $sect\n";
echo "  (si 0, ce temoin est non concluant et je le dis)\n";

echo "\n=== COMBINATEUR `not` (doit rester symetrique) ===\n";
foreach ([['neq', 'btp'], ['eq', 'btp']] as [$op, $v]) {
    $c = ['not' => [['field' => 'sector_main', 'op' => $op, 'value' => $v]]];
    $q = $svc->buildPublicQuery($ws, $c);
    printf("  not{ sector_main %-4s btp } -> SQL=%7d | MEMOIRE=%s\n", $op, $q->count(), $matchM->invoke($svc, $one, $c) ? 'GARDE' : 'EXCLUE');
}

echo "\n=== CRITERES VIDES (l'audience qui ne filtre rien) ===\n";
printf("  criteria {}          -> SQL=%d\n", $svc->buildPublicQuery($ws, [])->count());
printf("  criteria {all:[]}    -> SQL=%d\n", $svc->buildPublicQuery($ws, ['all' => []])->count());
