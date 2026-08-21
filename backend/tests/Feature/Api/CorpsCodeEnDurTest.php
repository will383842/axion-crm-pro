<?php

/**
 * GARDE DES CORPS CODES EN DUR — constats B12-007 (S1) et C19-008 (S1).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CE QUE L'AUDIT A MESURE, ET POURQUOI C'EST LE PIRE DES ETATS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * `B12-007` enumere DIX routes qui rendent `200` avec un corps ECRIT DANS LE
 * CODE. Trois ont ete refermees ailleurs dans la journee du 2026-08-20
 * (`/search` par I48-002, `/dashboard/stats` par P6-UI-001, `/ai-act/register`
 * par B16-007). SEPT restaient, mesurees sur ce depot :
 *
 *   GET  /v1/llm/usage ....................... `{"data":[]}`                   en dur
 *   GET  /v1/llm/usage/summary ............... `{"summary":{"total_eur":0}}`   en dur
 *   GET  /v1/rotations ....................... `{"data":[]}`                   en dur
 *   GET  /v1/notifications ................... `{"data":[]}`                   en dur
 *   GET  /v1/saved-views ..................... `{"data":[]}`                   en dur
 *   GET  /v1/llm/use-cases/{u}/prompts ....... `{"versions":[]}`               en dur
 *   POST /v1/proxy-providers/{p}/test ........ `{"healthy":true}`              en dur
 *
 * Un `200` avec un corps invente n'est pas une fonctionnalite manquante : c'est
 * une AFFIRMATION. « Aucun appel LLM », « 0 EUR depense », « aucune rotation »,
 * « ce mandataire fonctionne » — l'ecran ne peut pas distinguer ces phrases
 * d'une mesure. Un `501` se voit ; un `200` vide se confond avec la verite.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * COMMENT CETTE GARDE MESURE — ET POURQUOI PAS AU `grep`
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * On ne cherche PAS `return $this->ok(['data' => []])` dans le code : la meme
 * ligne est parfaitement legitime dans une branche de repli (`Schema::hasTable`
 * absente, exception rattrapee), et les six controleurs voisins deja repares en
 * portent une. Un motif de recherche ne distingue pas un repli d'un mensonge.
 *
 * On SEME donc une ligne reelle en base, on appelle la route, et l'on regarde si
 * elle ressort. **Une methode qui rend un corps ecrit en dur ne peut pas
 * inventer un marqueur aleatoire semé une milliseconde plus tôt.** C'est la
 * seule mesure qu'aucune indirection ne trompe — meme raisonnement que
 * `CloisonnementDesListesTest`, et meme conclusion.
 *
 * Chaque cas seme AUSSI une ligne pour un SECOND espace de travail et exige
 * qu'elle n'apparaisse pas : la reparation de B12-007 consiste a brancher ces
 * routes sur la base, et brancher une liste sur la base SANS filtre d'espace
 * rouvrirait `P6-API-001` (fuite par la liste) le jour meme ou l'on ferme
 * B12-007. Les deux exigences se tiennent dans le meme appel.
 *
 * ⚠️ `assertContains()` / `assertNotContains()`, JAMAIS `expect()->toContain()`
 * avec un message : ce dernier est VARIADIQUE, le message y devient une seconde
 * valeur a chercher, et la garde rougit eternellement en cherchant sa propre
 * phrase d'explication dans la reponse HTTP. Piege referme cinq fois dans ce
 * depot ; on ne l'ecrit plus, jamais.
 */

use App\Models\ProxyProvider;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * Un compte admin dans un espace de travail neuf.
 *
 * Le nom est prefixe `b12` : Pest declare ces fonctions au niveau GLOBAL, et
 * deux fichiers de test qui nomment leur helper pareil se terminent en
 * « Cannot redeclare function » — pas en echec de test, en fatale.
 *
 * @return array{0: User, 1: string}
 */
function compteB12(string $nom): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => strtolower($nom) . '-b12-' . Str::random(8),
        'name' => $nom,
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => strtolower($nom) . '-' . Str::random(6) . '@corps-en-dur.test',
        'name' => $nom,
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($espace->id);
    $compte->assignRole('admin');

    return [$compte, (string) $espace->id];
}

/**
 * Toutes les valeurs textuelles d'une reponse JSON, a plat.
 *
 * On ne suppose RIEN de la forme : `data`, `data.data`, enveloppe de
 * pagination, ressource. Une garde qui devine la forme rate la regression des
 * que la forme change.
 *
 * @return list<string>
 */
function aplatirB12(mixed $noeud): array
{
    if (is_scalar($noeud)) {
        return [(string) $noeud];
    }
    if (! is_array($noeud)) {
        return [];
    }

    $out = [];
    foreach ($noeud as $v) {
        $out = array_merge($out, aplatirB12($v));
    }

    return $out;
}

/**
 * Le coeur de la garde.
 *
 * @param  string  $table  table qui DOIT exister sur le banc — une table absente
 *                         rendrait la garde muette, donc on echoue plutot que
 *                         d'ignorer (un test ignore est un vert deguise).
 */
function laRouteRegardeVraimentLaBase(
    string $route,
    string $table,
    string $marqueurAMoi,
    string $marqueurDuVoisin,
    User $moi,
): void {
    test()->assertTrue(
        Schema::hasTable($table),
        "La table {$table} est absente du banc : cette garde ne mesurerait RIEN. "
        . 'Un test ignore est un vert deguise.',
    );

    $reponse = test()->actingAs($moi)->getJson($route);

    test()->assertSame(
        200,
        $reponse->status(),
        "{$route} rend {$reponse->status()} : on n'inspecte pas le corps d'une route cassee.",
    );

    $valeurs = aplatirB12($reponse->json());

    // 🔴 L'ASSERTION QUI FAIT ROUGIR B12-007. Le marqueur a ete ecrit en base
    // juste avant l'appel ; un corps code en dur ne peut pas le contenir.
    test()->assertContains(
        $marqueurAMoi,
        $valeurs,
        "🔴 {$route} ne rend PAS la ligne semee en base ({$marqueurAMoi}).\n\n"
        . "C'est le constat B12-007 : la methode rend un corps ECRIT DANS LE CODE, avec un 200 "
        . "qui la rend indiscernable d'une fonctionnalite qui marche. L'ecran lit « rien a "
        . "montrer » la ou la base porte une ligne.\n\n"
        . 'Soit la route interroge la base, soit elle rend 501 via `notImplemented()` — un 200 '
        . "invente n'est pas une troisieme option.",
    );

    // Garde solidaire : brancher une liste sur la base SANS filtre d'espace
    // rouvrirait P6-API-001 le jour meme ou l'on ferme B12-007.
    test()->assertNotContains(
        $marqueurDuVoisin,
        $valeurs,
        "🔴 {$route} rend une ligne d'un AUTRE espace de travail ({$marqueurDuVoisin}).\n\n"
        . 'Une fuite par la LISTE est pire qu\'une fuite par la fiche : la fiche demande de '
        . 'deviner un identifiant, la liste les donne tous.',
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOINS : la garde sait trouver, et sait ne pas trouver.
// ─────────────────────────────────────────────────────────────────────────────

test('B12-007 TEMOIN — l aplatissement retrouve une valeur enfouie et pas une absente', function () {
    // Sans ce cas, `aplatirB12()` pourrait rendre un tableau vide sur toutes les
    // formes de reponse, et TOUTES les gardes de ce fichier passeraient au vert
    // sans rien inspecter. C'est le pire des verts.
    $reponse = ['data' => [['id' => 7, 'imbrique' => ['slug' => 'marqueur-enfoui']]], 'meta' => ['total' => 1]];

    expect(aplatirB12($reponse))->toContain('marqueur-enfoui');
    expect(aplatirB12($reponse))->not->toContain('marqueur-absent');
});

test('B12-007 TEMOIN — la garde ROUGIT sur un corps code en dur', function () {
    // On rejoue mecaniquement ce que faisaient les sept methodes : un corps
    // figé, sans regard vers la base. La garde DOIT le refuser — sinon elle ne
    // prouverait rien de ce qu'elle affirme prouver.
    $corpsEnDur = ['data' => []];
    $valeurs = aplatirB12($corpsEnDur);

    expect($valeurs)->not->toContain('marqueur-seme-en-base');

    // Et le meme corps, une fois branche sur une ligne reelle, passe.
    $corpsBranche = ['data' => [['slug' => 'marqueur-seme-en-base']]];
    expect(aplatirB12($corpsBranche))->toContain('marqueur-seme-en-base');
});

// ─────────────────────────────────────────────────────────────────────────────
// B12-007 — les six lectures
// ─────────────────────────────────────────────────────────────────────────────

test('B12-007 — GET /llm/usage rend les appels LLM reellement enregistres', function () {
    [$moi, $mien] = compteB12('ALPHA');
    [, $voisin] = compteB12('BETA');

    foreach ([[$mien, 'usage-a-moi-b12'], [$voisin, 'usage-du-voisin-b12']] as [$ws, $slug]) {
        DB::table('llm_usage')->insert([
            'workspace_id' => $ws,
            'use_case_slug' => $slug,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'tokens_input' => 1200,
            'tokens_output' => 340,
            'cost_eur' => 1.5,
            'created_at' => now(),
        ]);
    }

    laRouteRegardeVraimentLaBase(
        '/api/v1/llm/usage',
        'llm_usage',
        'usage-a-moi-b12',
        'usage-du-voisin-b12',
        $moi,
    );
});

test('B12-007 — GET /llm/usage/summary chiffre le cout REEL, pas zero', function () {
    expect(Schema::hasTable('llm_usage'))->toBeTrue();

    [$moi, $mien] = compteB12('ALPHA');
    [, $voisin] = compteB12('BETA');

    // Deux appels a moi : 1,50 + 2,25 = 3,75 EUR. Un appel enorme chez le
    // voisin : s'il fuit, `total_eur` vaudra 102,75 et l'ecart se verra.
    DB::table('llm_usage')->insert([
        ['workspace_id' => $mien, 'use_case_slug' => 'resume-b12', 'provider' => 'anthropic',
            'model' => 'claude-sonnet', 'tokens_input' => 1000, 'tokens_output' => 200,
            'cost_eur' => 1.5, 'created_at' => now()],
        ['workspace_id' => $mien, 'use_case_slug' => 'resume-b12', 'provider' => 'mistral',
            'model' => 'mistral-small', 'tokens_input' => 500, 'tokens_output' => 100,
            'cost_eur' => 2.25, 'created_at' => now()],
        ['workspace_id' => $voisin, 'use_case_slug' => 'secret-du-voisin-b12', 'provider' => 'openai',
            'model' => 'gpt', 'tokens_input' => 9, 'tokens_output' => 9,
            'cost_eur' => 99, 'created_at' => now()],
    ]);

    $reponse = $this->actingAs($moi)->getJson('/api/v1/llm/usage/summary');
    $this->assertSame(200, $reponse->status());

    $resume = $reponse->json('summary');

    // 🔴 L'assertion qui fait rougir : `{"summary":{"total_eur":0}}` en dur.
    $this->assertSame(
        3.75,
        round((float) ($resume['total_eur'] ?? 0), 2),
        '🔴 GET /llm/usage/summary ne chiffre RIEN : 3,75 EUR sont enregistres en base '
        . 'et la route en annonce ' . round((float) ($resume['total_eur'] ?? 0), 2) . '. '
        . 'Un « 0 EUR depense » ecrit dans le code est une affirmation, pas une mesure.',
    );

    // Le cloisonnement se lit dans le chiffre lui-meme : 99 EUR du voisin
    // feraient 102,75. L'assertion ci-dessus le couvre deja, on nomme la cle
    // pour que l'echec soit lisible.
    $this->assertArrayNotHasKey(
        'secret-du-voisin-b12',
        (array) ($resume['by_use_case'] ?? []),
        'GET /llm/usage/summary agrege les appels d un AUTRE espace de travail.',
    );

    $this->assertArrayHasKey(
        'resume-b12',
        (array) ($resume['by_use_case'] ?? []),
        'GET /llm/usage/summary ne ventile pas par cas d usage : le contrat attendu par '
        . 'LlmRouterPage.tsx (`by_use_case`) n est pas rendu.',
    );
});

test('B12-007 — GET /rotations rend les rotations reellement configurees', function () {
    [$moi, $mien] = compteB12('ALPHA');
    [, $voisin] = compteB12('BETA');

    foreach ([[$mien, 'rotation-a-moi-b12'], [$voisin, 'rotation-du-voisin-b12']] as [$ws, $slug]) {
        DB::table('rotations')->insert([
            'workspace_id' => $ws,
            'dimension' => 'proxy',
            'slug' => $slug,
            'weight' => 3,
            'cooldown_seconds' => 30,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    laRouteRegardeVraimentLaBase(
        '/api/v1/rotations',
        'rotations',
        'rotation-a-moi-b12',
        'rotation-du-voisin-b12',
        $moi,
    );
});

test('B12-007 — GET /notifications rend les notifications reellement deposees', function () {
    [$moi, $mien] = compteB12('ALPHA');
    [$lui, $voisin] = compteB12('BETA');

    foreach ([[$mien, $moi->id, 'notification-a-moi-b12'], [$voisin, $lui->id, 'notification-du-voisin-b12']] as [$ws, $uid, $titre]) {
        DB::table('notifications')->insert([
            'workspace_id' => $ws,
            'user_id' => $uid,
            'type' => 'scraper.done',
            'title' => $titre,
            'created_at' => now(),
        ]);
    }

    laRouteRegardeVraimentLaBase(
        '/api/v1/notifications',
        'notifications',
        'notification-a-moi-b12',
        'notification-du-voisin-b12',
        $moi,
    );
});

test('B12-007 — GET /saved-views rend les vues reellement enregistrees', function () {
    [$moi, $mien] = compteB12('ALPHA');
    [$lui, $voisin] = compteB12('BETA');

    foreach ([[$mien, $moi->id, 'vue-a-moi-b12'], [$voisin, $lui->id, 'vue-du-voisin-b12']] as [$ws, $uid, $nom]) {
        DB::table('saved_views')->insert([
            'workspace_id' => $ws,
            'user_id' => $uid,
            'entity' => 'companies',
            'name' => $nom,
            'filters' => json_encode(['quality_tier' => 'complete']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    laRouteRegardeVraimentLaBase(
        '/api/v1/saved-views',
        'saved_views',
        'vue-a-moi-b12',
        'vue-du-voisin-b12',
        $moi,
    );
});

test('B12-007 — GET /llm/use-cases/{u}/prompts rend les versions reellement versionnees', function () {
    expect(Schema::hasTable('prompt_template_versions'))->toBeTrue();

    [$moi, $mien] = compteB12('ALPHA');
    [, $voisin] = compteB12('BETA');

    $poser = function (string $ws, string $cle, string $contenu): int {
        $useCase = DB::table('llm_use_cases')->insertGetId([
            'workspace_id' => $ws,
            'slug' => $cle,
            'primary_provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $gabarit = DB::table('prompt_templates')->insertGetId([
            'use_case_id' => $useCase,
            'slug' => $cle . '-tpl',
            'created_at' => now(),
        ]);

        DB::table('prompt_template_versions')->insert([
            'prompt_template_id' => $gabarit,
            'version' => 1,
            'content' => $contenu,
            'created_at' => now(),
        ]);

        return (int) $useCase;
    };

    $aMoi = $poser($mien, 'prompts-a-moi-b12', 'contenu-a-moi-b12');
    $duVoisin = $poser($voisin, 'prompts-du-voisin-b12', 'contenu-du-voisin-b12');

    laRouteRegardeVraimentLaBase(
        "/api/v1/llm/use-cases/{$aMoi}/prompts",
        'prompt_template_versions',
        'contenu-a-moi-b12',
        'contenu-du-voisin-b12',
        $moi,
    );

    // Et le cas d'usage du voisin n'est meme pas atteignable : 404, pas 200 vide
    // — c'est `refuserHorsEspace()`, deja pose sur cette methode.
    $this->actingAs($moi)->getJson("/api/v1/llm/use-cases/{$duVoisin}/prompts")->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// C19-008 — le controle de sante qui dit toujours « en bonne sante »
// ─────────────────────────────────────────────────────────────────────────────

test('C19-008 — POST /proxy-providers/{p}/test ne repond plus « healthy » en dur', function () {
    $table = (new ProxyProvider)->getTable();

    $this->assertTrue(
        Schema::hasTable($table),
        "La table {$table} est absente du banc : cette garde ne mesurerait rien.",
    );

    [$moi, $mien] = compteB12('ALPHA');

    $id = DB::table($table)->insertGetId([
        'workspace_id' => $mien,
        'slug' => 'fournisseur-b12',
        'type' => 'datacenter',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reponse = $this->actingAs($moi)->postJson("/api/v1/proxy-providers/{$id}/test");

    // 🔴 L'ASSERTION QUI FAIT ROUGIR C19-008.
    //
    // La route est documentee « Health check live d'un provider » et rendait
    // `{"healthy":true}` SANS contacter quoi que ce soit. Identifiants absents,
    // quota epuise, fournisseur injoignable : tout repondait « sain ».
    //
    // Elle ne peut pas etre branchee honnetement aujourd'hui : le conteneur ne
    // lie QU'UN seul `App\Contracts\ProxyProvider`, et sous `MOCK_PROXIES=true`
    // (le defaut de ce depot) c'est `MockProxyProvider`, dont `healthCheck()`
    // rend `true` en dur — brancher la route reproduirait EXACTEMENT le meme
    // mensonge, une couche plus bas. Le seul etat honnete est 501.
    $this->assertSame(
        501,
        $reponse->status(),
        "🔴 POST /proxy-providers/{p}/test rend {$reponse->status()} au lieu de 501.\n\n"
        . "C'est le constat C19-008 : le SEUL bouton de diagnostic du sous-systeme mandataire "
        . 'repond « en bonne sante » sans rien contacter, et ne rougira jamais — y compris le '
        . 'jour ou le mandataire EST la cause de la panne.',
    );

    $this->assertNotSame(
        true,
        $reponse->json('healthy'),
        'La reponse porte encore `healthy: true` : le mensonge subsiste sous un autre code.',
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// NON-REGRESSION — brancher une route sur la base ne doit pas la casser
// ─────────────────────────────────────────────────────────────────────────────

/**
 * ⚠️ POURQUOI CES DEUX CAS SONT ICI, ET PAS DANS LE FICHIER PREVU POUR EUX.
 *
 * `tests/Feature/Controllers/Sprint189NoFiveHundredTest.php` couvre exactement
 * cela — « aucun GET /api/v1/* ne rend 500, meme sur base partiellement semee »,
 * et son jeu de donnees nomme les six routes de ce lot.
 *
 * **Il ne s'execute pas.** `php -l` sur ce fichier rend, sur le depot tel qu'il
 * est et sans que ce lot y ait touche :
 *
 *     PHP Parse error: syntax error, unexpected token "*", expecting end of file
 *     in tests/Feature/Controllers/Sprint189NoFiveHundredTest.php on line 134
 *
 * La ligne 134 cite le type MIME du client nu — etoile, barre oblique, etoile —
 * DANS un commentaire de bloc. Cette sequence de deux caracteres REFERME le
 * commentaire, et tout le reste du fichier part en code PHP invalide. Un
 * commentaire ne peut pas contenir sa propre marque de fin ; il faut l'ecrire
 * autrement, comme cette phrase-ci le fait. Les 24 jeux x 2 tests, plus la garde
 * `A-001`, ne tournent donc PAS — et un fichier qui ne s'analyse meme pas est
 * pire qu'un test ignore : il ne laisse pas trace de son absence.
 *
 * Ce fichier est hors du perimetre de ce lot ; on ne le repare pas, on le
 * signale. Mais on ne peut pas non plus livrer six routes nouvellement branchees
 * sur la base sans la mesure qu'il etait cense apporter. On la refait ici, sur
 * les six seules routes concernees.
 */
dataset('routes_b12_rebranchees', [
    'llm usage' => ['/api/v1/llm/usage', 'data'],
    'llm usage summary' => ['/api/v1/llm/usage/summary', 'summary'],
    'rotations' => ['/api/v1/rotations', 'data'],
    'notifications' => ['/api/v1/notifications', 'data'],
    'saved views' => ['/api/v1/saved-views', 'data'],
]);

test('B12-007 NON-REGRESSION — {0} rend 200 et sa cle sur une base NUE', function (string $url, string $cle) {
    // Aucune ligne semee : c'est le cas qui exerce les branches de repli
    // (`Schema::hasTable`, espace absent, agregation sur zero ligne). Une
    // requete SQL ecrite trop vite y rend 500, et l'ecran se vide entierement.
    [$moi] = compteB12('ALPHA');

    $reponse = $this->actingAs($moi)->getJson($url);

    expect($reponse->status())->toBe(200);
    $this->assertArrayHasKey(
        $cle,
        (array) $reponse->json(),
        "{$url} ne porte plus la cle `{$cle}` : le contrat lu par le frontend est rompu.",
    );
})->with('routes_b12_rebranchees');

test('B12-007 NON-REGRESSION — {0} sans authentification rend 401, jamais 500', function (string $url, string $cle) {
    // TEMOIN : si la route rendait 200 sans compte, tout le cloisonnement
    // mesure plus haut serait contourne par la porte d'entree. Et 401 EXACTEMENT,
    // pas « moins que 500 » : un 404 signalerait une route disparue, et une
    // garde qui accepte 404 passe au vert sur ce qu'on vient de supprimer.
    $reponse = $this->getJson($url);

    expect($reponse->status())->toBe(401);
    expect($cle)->not->toBe('');
})->with('routes_b12_rebranchees');

test('C19-008 TEMOIN — la garde ne confond pas 501 avec une route absente', function () {
    // Une route qui n'existe pas rend 404, pas 501 : sans ce temoin, un
    // `assertSame(501)` pourrait etre satisfait par un routage casse et l'on
    // croirait avoir repare ce qu'on aurait en fait supprime.
    [$moi] = compteB12('ALPHA');

    $this->actingAs($moi)
        ->postJson('/api/v1/proxy-providers/999999/test')
        ->assertNotFound();
});
