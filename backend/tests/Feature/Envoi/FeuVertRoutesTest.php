<?php

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DepotPreuves;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ContratOpenApiEnvoi;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

/** Compte fictif (exemple.test) d'un rôle donné, dans un espace neuf ou celui passé. */
function fvCompte(string $role, ?string $espace = null, string $nom = 'Responsable Test'): User
{
    $espace ??= Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'fvr-' . Str::random(8), 'name' => 'Espace feu vert', 'settings' => ['produits' => ['envoi' => 'actif']]])->id;
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@exemple.test',
        'name' => $nom,
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace);
    $user->assignRole($role);

    return $user;
}

const FV_PREUVE = 'Courriel de l’hébergeur du 10 novembre, port 25 ouvert (réf. exemple-0042).';

test('GET /envoi/feu-vert : 401 sans session', function () {
    $this->getJson('/api/v1/envoi/feu-vert')->assertUnauthorized();
});

test('POST /envoi/feu-vert/{code}/fait : 401 sans session', function () {
    $this->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertUnauthorized();
});

test('GET : la réponse suit le contrat pilotage.yaml (31 points, verrous en lecture seule)', function () {
    $u = fvCompte('viewer');
    $r = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'PointFeuVert', $r->json('points.0')))->toBe([]);
    foreach ($r->json('points') as $p) {
        expect(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'PointFeuVert', $p))->toBe([], $p['code']);
    }
    expect(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'VerrousFeuVert', $r->json('verrous')))->toBe([]);
    expect($r->json('points'))->toHaveCount(31)->and($r->json('total'))->toBe(31)
        ->and($r->json('bloquants_restants'))->toBe(count(array_filter($r->json('points'), fn ($p) => ! $p['fait'])))
        ->and($r->json('faits'))->toBe(31 - $r->json('bloquants_restants'))
        ->and($r->json('verrous.envoi_reel_actif'))->toBeFalse()
        ->and($r->json('verrous.fournisseurs_temoins_requis'))->toBe(6);
    // Un point `fait` l'est exactement quand son état vaut `fait`.
    foreach ($r->json('points') as $p) {
        expect($p['fait'])->toBe($p['etat'] === 'fait');
    }
});

test('POST : un propriétaire dépose une preuve ; la réponse suit le contrat et `par` est un nom affiché', function () {
    $u = fvCompte('owner');
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'PointFeuVert', $r->json()))->toBe([]);
    expect($r->json('code'))->toBe('manuel.port25')
        ->and($r->json('fait'))->toBeFalse()->and($r->json('bloquant'))->toBeTrue()->and($r->json('etat'))->toBe('attend_serveur_reel')
        ->and($r->json('preuve.texte'))->toBe(FV_PREUVE)
        ->and($r->json('preuve.par'))->toBe('Responsable Test')
        ->and($r->json('preuve.par'))->not->toContain('@');
    $liste = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    expect(collect($liste->json('points'))->firstWhere('code', 'manuel.port25')['preuve']['texte'])->toBe(FV_PREUVE);
});

test('POST : `par` ne vaut jamais une adresse e-mail (nom absent ou égal à l’e-mail → « responsable »)', function (string $nom) {
    $u = fvCompte('owner', null, $nom);
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertOk();
    expect($r->json('preuve.par'))->toBe('responsable');
    expect(DB::table('envoi_feu_vert_preuves')->where('auteur', 'like', '%@%')->count())->toBe(0);
})->with(['nom = e-mail' => ['jean@exemple.test'], 'nom vide' => ['   '], 'nom avec arobase pleine chasse' => ["jean\u{FF20}exemple.test"], 'nom avec caractère de format' => ["Respon\u{200B}sable"]]);

test('POST : `fait` à false défait le point, `fait` absent vaut true', function () {
    $u = fvCompte('owner');
    $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.capacite_reponses/fait', ['preuve' => FV_PREUVE])->assertOk()->assertJsonPath('fait', true)->assertJsonPath('etat', 'fait');
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.capacite_reponses/fait', ['preuve' => 'Preuve périmée, à refaire.', 'fait' => false])->assertOk();
    expect($r->json('fait'))->toBeFalse()->and($r->json('preuve'))->toBeNull();
    expect(DB::table('envoi_feu_vert_preuves')->where('code', 'manuel.capacite_reponses')->count())->toBe(2);
});

test('POST : défaire un point jamais fait → 422', function () {
    $this->actingAs(fvCompte('owner'))->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => 'Rien à défaire ici.', 'fait' => false])->assertStatus(422);
});

test('POST : 403 hors permission workspaces.manage, et rien n’est écrit', function (string $role) {
    $u = fvCompte($role);
    $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertForbidden();
    expect(DB::table('envoi_feu_vert_preuves')->count())->toBe(0);
})->with(['viewer', 'operator', 'admin']);

test('POST : 404 pour un code inconnu sans préfixe auto. (code_inconnu, manuel.* inexistant)', function (string $code) {
    $this->actingAs(fvCompte('owner'))->postJson('/api/v1/envoi/feu-vert/' . $code . '/fait', ['preuve' => FV_PREUVE])->assertNotFound();
})->with(['code_inconnu', 'manuel.n_existe_pas']);

test('POST : tout code commençant par auto. → 422 AVANT le catalogue (existant ou non : on ne peut pas lister les points automatiques)', function (string $code) {
    $r = $this->actingAs(fvCompte('owner'))->postJson('/api/v1/envoi/feu-vert/' . $code . '/fait', ['preuve' => FV_PREUVE])->assertStatus(422);
    expect($r->json('error'))->toBe('validation')->and($r->json('message'))->toBe('Ce point est calculé par le système : il ne reçoit jamais de preuve.');
    expect(DB::table('envoi_feu_vert_preuves')->count())->toBe(0);
})->with(['auto.n_existe_pas', 'auto.', 'auto.reel_actif_faux', 'auto.TEMOINS_3_JOURS']);

test('POST : la réponse à un code auto. est identique qu’il existe ou non', function () {
    $u = fvCompte('owner');
    $a = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/auto.n_existe_pas/fait', ['preuve' => FV_PREUVE]);
    $b = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/auto.reel_actif_faux/fait', ['preuve' => FV_PREUVE]);
    expect($a->status())->toBe($b->status())->and($a->json())->toBe($b->json());
});

test('POST : 422 pour tout code auto.* du catalogue, sans écriture', function () {
    $u = fvCompte('owner');
    foreach (CatalogueManuels::codes() as $code) {
        if (str_starts_with($code, 'auto.')) {
            $this->actingAs($u)->postJson("/api/v1/envoi/feu-vert/{$code}/fait", ['preuve' => FV_PREUVE])->assertStatus(422);
        }
    }
    expect(DB::table('envoi_feu_vert_preuves')->count())->toBe(0);
});

test('POST : 422 pour une preuve absente, vide, courte, générique, trop longue, avec e-mail ou caractère de format', function (mixed $corps) {
    $u = fvCompte('owner');
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', $corps)->assertStatus(422);
    expect($r->json('message'))->toBeString()->not->toBe('');
    expect(DB::table('envoi_feu_vert_preuves')->count())->toBe(0);
})->with([
    'absente' => [[]],
    'vide' => [['preuve' => '']],
    'blancs' => [['preuve' => '          ']],
    'courte (9)' => [['preuve' => 'abcdefghi']],
    'phrase fixe de l’écran' => [['preuve' => 'Fait, constaté depuis l\'écran.']],
    'phrase fixe, apostrophe typographique' => [['preuve' => 'Fait, constaté depuis l’écran.']],
    'trop longue' => [['preuve' => str_repeat('a', 501)]],
    'non texte' => [['preuve' => ['x']]],
    'e-mail' => [['preuve' => 'Voir jean.dupont@exemple.test pour la preuve']],
    'e-mail, espaces autour de @' => [['preuve' => 'Voir jean.dupont @ exemple.test pour la preuve']],
    'arobase pleine chasse' => [['preuve' => "Voir jean.dupont\u{FF20}exemple.test pour la preuve"]],
    'chez, forme épelée' => [['preuve' => 'Voir jean.dupont chez exemple point test pour la preuve']],
    'arobase en toutes lettres' => [['preuve' => 'Voir jean.dupont arobase exemple point test pour la preuve']],
    'espace de largeur nulle' => [['preuve' => "Courrier de l’hébergeur\u{200B} reçu hier"]],
    'inversion de sens' => [['preuve' => "Courrier de l’hébergeur\u{202E} reçu hier"]],
    'fait non booléen' => [['preuve' => FV_PREUVE, 'fait' => 'peut-être']],
]);

test('POST : un UTF-8 invalide est refusé en 422 (jamais 500)', function () {
    $u = fvCompte('owner');
    $this->actingAs($u)->call('POST', '/api/v1/envoi/feu-vert/manuel.port25/fait', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], "{\"preuve\":\"Courrier \xC3\x28 de l'hébergeur\"}")
        ->assertStatus(422);
    expect(DB::table('envoi_feu_vert_preuves')->count())->toBe(0);
});

test('POST : le 403 suit commun.yaml#Erreur ({error, message} en français) et le contrat', function (string $role) {
    $u = fvCompte($role);
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertForbidden();
    expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'Erreur', $r->json()))->toBe([]);
    expect($r->json('error'))->toBe('interdit')->and($r->json('message'))->toBeString()->not->toBe('')
        ->and($r->json('message'))->not->toContain('User does not have');
})->with(['viewer', 'operator', 'admin']);

test('le rendu du 403 en français ne touche pas les routes hors /api/*/envoi/*', function () {
    $u = fvCompte('viewer');
    // Une route hors envoi protégée par permission garde le rendu par défaut (pas la forme {error, message}).
    $r = $this->actingAs($u)->getJson('/api/v1/envoi/sante');
    $r->assertForbidden();
    expect($r->json('error'))->toBe('interdit');
    $autre = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => ! str_contains($route->uri(), '/envoi/') && str_contains(implode(' ', $route->gatherMiddleware()), 'permission:') && in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{'));
    expect($autre)->not->toBeNull();
    $r2 = $this->actingAs($u)->getJson('/' . $autre->uri());
    if ($r2->status() === 403) {
        expect($r2->json('error'))->not->toBe('interdit');
    }
});

test('POST : un point qui attend le vrai serveur n’est PAS fait (fait = etat === fait), la preuve reste affichée', function () {
    $u = fvCompte('owner');
    $avant = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertOk();
    expect($r->json('etat'))->toBe('attend_serveur_reel')->and($r->json('fait'))->toBeFalse()->and($r->json('bloquant'))->toBeTrue()
        ->and($r->json('preuve.texte'))->toBe(FV_PREUVE);
    $apres = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    expect($apres->json('bloquants_restants'))->toBe($avant->json('bloquants_restants'))->and($apres->json('faits'))->toBe($avant->json('faits'));
    $p = collect($apres->json('points'))->firstWhere('code', 'manuel.port25');
    expect($p['fait'])->toBe($p['etat'] === 'fait')->and($p['preuve']['texte'])->toBe(FV_PREUVE);
    expect(ContratOpenApiEnvoi::ecartsListeFeuVert($apres->json()))->toBe([]);
});

test('POST : un point manuel sans exigence de serveur réel devient fait et fait baisser les bloquants', function () {
    $u = fvCompte('owner');
    $avant = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.capacite_reponses/fait', ['preuve' => FV_PREUVE])->assertOk();
    expect($r->json('etat'))->toBe('fait')->and($r->json('fait'))->toBeTrue()->and($r->json('bloquant'))->toBeFalse();
    $apres = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    expect($apres->json('bloquants_restants'))->toBe($avant->json('bloquants_restants') - 1)->and($apres->json('faits'))->toBe($avant->json('faits') + 1);
    expect(ContratOpenApiEnvoi::ecartsListeFeuVert($apres->json()))->toBe([]);
});

test('le validateur croisé refuse une liste incohérente (fait ≠ etat, compteurs faux)', function () {
    $u = fvCompte('owner');
    $liste = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk()->json();
    $faux = $liste;
    $i = array_search('manuel.port25', array_column($faux['points'], 'code'), true);
    $faux['points'][$i]['fait'] = true;
    expect(ContratOpenApiEnvoi::ecartsListeFeuVert($faux))->not->toBe([]);
    $faux = $liste;
    $faux['bloquants_restants']--;
    expect(ContratOpenApiEnvoi::ecartsListeFeuVert($faux))->not->toBe([]);
});

test('POST : la preuve vient du corps JSON seulement (jamais de la query string ni d’un formulaire)', function () {
    $u = fvCompte('owner');
    $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait?preuve=' . urlencode(FV_PREUVE))->assertStatus(422);
    $this->actingAs($u)->post('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE], ['Accept' => 'application/json'])->assertStatus(422);
    $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait?fait=false', ['preuve' => FV_PREUVE])->assertOk()->assertJsonPath('preuve.texte', FV_PREUVE);
    expect(DB::table('envoi_feu_vert_preuves')->where('fait', false)->count())->toBe(0);
});

test('POST : current_workspace_id vide → jamais 500 (404 de la garde produit : sans espace, pas de produit)', function () {
    $u = fvCompte('owner');
    DB::table('users')->where('id', $u->id)->update(['current_workspace_id' => null]);
    $u->refresh();
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE]);
    expect($r->status())->toBe(404);
    expect($r->json('message'))->toBeString();
    $g = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert');
    expect($g->status())->toBe(404);
    expect(DB::table('envoi_feu_vert_preuves')->count())->toBe(0);
});

test('POST : preuve acceptée avec « chez » dans une phrase ordinaire (hébergeur, nom de domaine)', function (string $texte) {
    $u = fvCompte('owner');
    $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => $texte])->assertOk()->assertJsonPath('preuve.texte', $texte);
})->with(['Ticket ouvert chez ovh.com', 'Port 25 ouvert chez Scaleway']);

test('isolement : un espace ne voit ni ne coche les preuves d’un autre', function () {
    $a = fvCompte('owner');
    $b = fvCompte('owner');
    $this->actingAs($a)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertOk();

    $vueB = $this->actingAs($b)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    $pB = collect($vueB->json('points'))->firstWhere('code', 'manuel.port25');
    expect($pB['preuve'])->toBeNull();

    // B défait « son » point : refusé (jamais fait chez lui) et la preuve de A reste intacte.
    $this->actingAs($b)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => 'Tentative de défaire chez A.', 'fait' => false])->assertStatus(422);
    $vueA = $this->actingAs($a)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    expect(collect($vueA->json('points'))->firstWhere('code', 'manuel.port25')['preuve']['texte'])->toBe(FV_PREUVE);
    expect(app(DepotPreuves::class)->dernieres($b->current_workspace_id))->toBe([]);
});

test('aucune route n’ouvre un verrou, ne signe, ni n’écrit envoi_drapeaux ; l’envoi réel reste éteint', function () {
    $u = fvCompte('owner');
    DB::table('envoi_drapeaux')->updateOrInsert(['workspace_id' => $u->current_workspace_id], ['mode_temoins' => true, 'feu_vert_signature' => null]);
    $avant = DB::table('envoi_drapeaux')->where('workspace_id', $u->current_workspace_id)->first();
    $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertOk();
    $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    expect(DB::table('envoi_drapeaux')->where('workspace_id', $u->current_workspace_id)->first())->toEqual($avant);
    expect(config('envoi.reel_actif'))->toBeFalse();

    $routes = [];
    foreach (Route::getRoutes() as $route) {
        if (str_contains($route->uri(), 'feu-vert')) {
            $routes[] = implode('|', array_diff($route->methods(), ['HEAD'])) . ' ' . $route->uri();
        }
    }
    sort($routes);
    expect($routes)->toBe(['GET api/v1/envoi/feu-vert', 'POST api/v1/envoi/feu-vert/{code}/fait']);
    foreach (Route::getRoutes() as $route) {
        $t = strtolower($route->uri() . ' ' . $route->getActionName());
        if (str_contains($t, 'feu-vert') || str_contains($t, 'feuvert')) {
            expect($t)->not->toContain('verrou')->not->toContain('signature')->not->toContain('drapeaux')->not->toContain('temoin')->not->toContain('tout-arreter');
        }
    }
});

test('l’écriture exige la permission workspaces.manage (middleware présent sur la route)', function () {
    $route = Route::getRoutes()->match(Request::create('/api/v1/envoi/feu-vert/manuel.port25/fait', 'POST'));
    expect(implode(' ', $route->gatherMiddleware()))->toContain('permission:workspaces.manage');
});

test('B1 : un point qui attend le vrai serveur n’est pas fait, reste bloquant, et la preuve reste affichée', function () {
    $u = fvCompte('owner');
    $avant = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    $r = $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => FV_PREUVE])->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('pilotage.yaml', 'PointFeuVert', $r->json()))->toBe([])
        ->and($r->json('fait'))->toBe($r->json('etat') === 'fait')->and($r->json('fait'))->toBeFalse()
        ->and($r->json('bloquant'))->toBeTrue()->and($r->json('preuve.texte'))->toBe(FV_PREUVE);

    $apres = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    expect(ContratOpenApiEnvoi::croiserListe($apres->json()))->toBe([])
        ->and($apres->json('bloquants_restants'))->toBe($avant->json('bloquants_restants'))
        ->and($apres->json('faits'))->toBe($avant->json('faits'));
    foreach ($apres->json('points') as $p) {
        expect($p['fait'])->toBe($p['etat'] === 'fait', $p['code']);
        if ($p['etat'] === 'attend_serveur_reel') {
            expect($p['fait'])->toBeFalse()->and($p['bloquant'])->toBeTrue();
        }
    }

    // Un point manuel qui n'attend pas le serveur réel devient fait : les compteurs bougent, eux.
    $this->actingAs($u)->postJson('/api/v1/envoi/feu-vert/manuel.capacite_reponses/fait', ['preuve' => FV_PREUVE])->assertOk()->assertJsonPath('fait', true)->assertJsonPath('bloquant', false);
    $fin = $this->actingAs($u)->getJson('/api/v1/envoi/feu-vert')->assertOk();
    expect($fin->json('bloquants_restants'))->toBe($avant->json('bloquants_restants') - 1)->and(ContratOpenApiEnvoi::croiserListe($fin->json()))->toBe([]);
});
