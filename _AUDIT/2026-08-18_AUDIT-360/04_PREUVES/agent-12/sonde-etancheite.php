<?php
/**
 * Agent 12 — points 2, 3, 4 et 14 de la grille, mesures sur requetes REELLES.
 *
 *   - un utilisateur de l'univers A obtient-il 0 ligne de l'univers B ?
 *   - un compte VIEWER (lecture seule) voit-il des coordonnees masquees
 *     sur la LISTE et sur la FICHE ?
 *   - un compte VIEWER peut-il SUPPRIMER une entreprise (aucune policy
 *     n'est appelee nulle part dans les 42 controleurs) ?
 *
 * Base : `axion_crm_a12`, CLONE jetable de `axion_crm_perf` cree pour cet
 * audit (`CREATE DATABASE ... TEMPLATE axion_crm_perf`). Aucune ecriture dans
 * `axion_crm`, ni en preproduction, ni en production.
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

config(['app.debug' => false]);
config(['database.connections.pgsql.database' => 'axion_crm_a12']);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

DB::purge('pgsql');
echo "===== ETANCHEITE / MASQUAGE / AUTORISATION — AGENT 12 =====\n";
echo 'date : ' . gmdate('c') . "\n";
echo 'base : ' . DB::connection()->getDatabaseName() . "\n\n";

$wsA = '20cd81e4-de5d-4875-a759-07d64fe1f168'; // Axion-IA (business, 300 000 fiches)
$wsB = '95cbe9b3-378e-4c9a-87cf-1d0faa629643'; // Vivier candidats

// ---------------------------------------------------------------- fixtures
// 1) une entreprise dans l'univers B, avec des coordonnees reconnaissables
DB::statement("SET LOCAL ROLE NONE");
$idB = DB::table('companies')->where('workspace_id', $wsB)->value('id');
if ($idB === null) {
    DB::table('companies')->insert([
        'workspace_id' => $wsB,
        'siren' => '999000111',
        'denomination' => 'FICHE-UNIVERS-B-AGENT12',
        'email_generic' => 'secret-univers-b@exemple.test',
        'phone' => '+33600000042',
        'discovery_source' => 'audit',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $idB = DB::table('companies')->where('workspace_id', $wsB)->value('id');
}
// 2) une entreprise de l'univers A avec des coordonnees reconnaissables
$idA = DB::table('companies')->where('workspace_id', $wsA)->orderBy('id')->value('id');
DB::table('companies')->where('id', $idA)->update([
    'email_generic' => 'contact-univers-a@exemple.test',
    'phone' => '+33611223344',
]);
// 3) un contact rattache a cette entreprise (la fiche detaillee charge `contacts`)
$idContact = DB::table('contacts')->where('company_id', $idA)->value('id');
if ($idContact === null) {
    DB::table('contacts')->insert([
        'workspace_id' => $wsA,
        'company_id' => $idA,
        'first_name' => 'Pierre',
        'last_name' => 'DURAND',
        'email' => 'pierre.durand@exemple.test',
        'phone' => '+33699887766',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

echo "entreprise univers A : id=$idA\n";
echo "entreprise univers B : id=$idB\n\n";

// 4) deux utilisateurs : un OWNER de A, un VIEWER de A
$owner = DB::table('users')->where('email', 'console-locale@axion-ia.test')->first();

$viewerId = DB::table('users')->where('email', 'viewer-agent12@exemple.test')->value('id');
if ($viewerId === null) {
    $viewerId = (string) \Illuminate\Support\Str::uuid();
    DB::table('users')->insert([
        'id' => $viewerId,
        'email' => 'viewer-agent12@exemple.test',
        'name' => 'Lecteur Agent12',
        'password_hash' => \Illuminate\Support\Facades\Hash::make('MotDePasseAudit2026!!'),
        'current_workspace_id' => $wsA,
        'first_login_completed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('model_has_roles')->insertOrIgnore([
        'role_id' => 4, // viewer
        'model_type' => 'App\\Models\\User',
        'model_id' => $viewerId,
        'team_id' => $wsA,
    ]);
}
echo "owner  : {$owner->email} ({$owner->id})\n";
echo "viewer : viewer-agent12@exemple.test ($viewerId)\n\n";

// ------------------------------------------------------------------ moteur
function joue(string $titre, \App\Models\User $user, string $methode, string $uri): array
{
    global $kernel;
    $server = [
        'REQUEST_METHOD' => $methode,
        'REQUEST_URI' => $uri,
        'HTTP_HOST' => 'api.localhost',
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ];
    $url = parse_url($uri);
    $query = [];
    if (isset($url['query'])) {
        parse_str($url['query'], $query);
    }
    $request = Request::create($uri, $methode, $query, [], [], $server, null);
    $request->setUserResolver(static fn () => $user);

    // Le garde `sanctum` a besoin d'une requete liee au conteneur pour se
    // construire : on la lie AVANT de lui poser l'utilisateur. C'est le garde
    // que `auth:sanctum` interroge, et celui qui devient le garde par defaut
    // une fois l'authentification reussie (donc celui que `auth()->user()`
    // rend dans `MasquageCoordonnees`).
    app()->instance('request', $request);
    Auth::guard('sanctum')->setUser($user);
    Auth::shouldUse('sanctum');

    $response = $kernel->handle($request);
    $code = $response->getStatusCode();
    $contenu = (string) $response->getContent();

    echo "### $titre\n    $methode $uri  (compte : {$user->email})\n    -> HTTP $code\n";

    return [$code, $contenu];
}

/** @var \App\Models\User $userOwner */
$userOwner = \App\Models\User::query()->find($owner->id);
/** @var \App\Models\User $userViewer */
$userViewer = \App\Models\User::query()->find($viewerId);

app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($wsA);
echo "roles owner  : " . $userOwner->getRoleNames()->implode(',') . "\n";
echo "roles viewer : " . $userViewer->getRoleNames()->implode(',') . "\n";
echo "viewer a-t-il contacts.view_pii ? " . var_export($userViewer->can('contacts.view_pii'), true) . "\n";
echo "viewer a-t-il companies.update ? " . var_export($userViewer->can('companies.update'), true) . "\n\n";

echo "----- 4. un compte de l'univers A atteint-il une fiche de B ? ------\n";
[$c, $b] = joue("fiche de l'univers B demandee par un compte de A", $userOwner, 'GET', "/api/v1/companies/$idB");
echo '    ' . mb_substr(str_replace("\n", ' ', $b), 0, 200) . "\n";
echo '    VERDICT : ' . ($c === 200 ? 'FUITE — la fiche de l autre univers est rendue' : 'etanche (' . $c . ')') . "\n\n";

echo "----- TEMOIN POSITIF : la meme requete sur une fiche de SON univers -\n";
[$c2, $b2] = joue('fiche du propre univers', $userOwner, 'GET', "/api/v1/companies/$idA");
echo '    ' . mb_substr(str_replace("\n", ' ', $b2), 0, 200) . "\n";
echo '    (si celle-ci ne rend pas 200, la sonde ci-dessus ne prouve rien)' . "\n\n";

echo "----- 14. masquage des coordonnees : LISTE vs FICHE ----------------\n";
[$c3, $b3] = joue('LISTE (index) vue par le VIEWER', $userViewer, 'GET', '/api/v1/companies?per_page=1&filter[denomination]=' . rawurlencode((string) DB::table('companies')->where('id', $idA)->value('denomination')));
$masqueListe = str_contains($b3, 'contact-univers-a@exemple.test');
echo '    email en clair dans la LISTE ? ' . ($masqueListe ? 'OUI' : 'NON (masque)') . "\n";
echo '    ' . mb_substr(str_replace("\n", ' ', $b3), 0, 400) . "\n\n";

[$c4, $b4] = joue('FICHE (show) vue par le MEME VIEWER', $userViewer, 'GET', "/api/v1/companies/$idA");
$clairFiche = str_contains($b4, 'contact-univers-a@exemple.test');
$clairContact = str_contains($b4, 'pierre.durand@exemple.test');
echo '    email entreprise en clair dans la FICHE ? ' . ($clairFiche ? 'OUI' : 'NON') . "\n";
echo '    email du CONTACT en clair dans la FICHE ? ' . ($clairContact ? 'OUI' : 'NON') . "\n";
echo '    ' . mb_substr(str_replace("\n", ' ', $b4), 0, 500) . "\n\n";

echo "----- 2. autorisation : un VIEWER peut-il SUPPRIMER une fiche ? ----\n";
$idCible = DB::table('companies')->where('workspace_id', $wsA)->orderByDesc('id')->value('id');
echo "    cible : companies.id=$idCible\n";
[$c5, $b5] = joue('DELETE par le VIEWER', $userViewer, 'DELETE', "/api/v1/companies/$idCible");
$supprimee = DB::table('companies')->where('id', $idCible)->value('deleted_at');
echo '    deleted_at apres appel : ' . var_export($supprimee, true) . "\n";
echo '    VERDICT : ' . ($supprimee !== null ? 'LE LECTEUR SEUL A SUPPRIME LA FICHE' : 'refuse') . "\n\n";

echo "----- 2bis. TEMOIN POSITIF : la garde `permission:` fonctionne ------\n";
[$c6, $b6] = joue('GET /companies/export par le VIEWER (permission:data.export)', $userViewer, 'GET', '/api/v1/companies/export');
echo '    VERDICT : ' . ($c6 === 403 ? '403 — la garde `permission:` rougit bien quand elle existe' : 'code ' . $c6) . "\n\n";

echo "===== FIN =====\n";
