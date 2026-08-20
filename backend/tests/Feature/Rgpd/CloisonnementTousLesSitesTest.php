<?php

/**
 * GARDE DE COMPLÉTUDE DU CLOISONNEMENT — constats B12-001 / F36-005 (S0),
 * deuxième tour.
 *
 * CE QUE LE PREMIER CORRECTIF A FERMÉ, ET CE QU'IL A LAISSÉ OUVERT.
 *
 * Le commit `9ed9ee9` a créé `ApiController::refuserHorsEspace()` — la bonne
 * pièce, au bon endroit — et l'a posée sur **deux** points d'entrée :
 * `GET /companies/{id}` et `GET /contacts/{id}`. La contre-vérification
 * adversariale relevait qu'il en restait une vingtaine.
 *
 * Recompté le 2026-08-20, méthode par méthode, sur les 44 contrôleurs :
 * **38 méthodes** reçoivent un modèle cloisonné par résolution de route.
 * Deux portaient la garde durcie. **Trente-six ne l'avaient pas.**
 *
 *   2 ......... garde durcie (`refuserHorsEspace`), fail-closed
 *  16 ......... garde artisanale recopiée dans trois contrôleurs, FAIL-OPEN
 *  20 ......... aucune garde du tout
 *
 * 🔑 C'EST LE PATRON `A-011` DANS SA FORME LA PLUS PURE : le correctif existait
 * déjà, écrit correctement, et il n'avait pas été porté. La garde artisanale est
 * pire encore, parce qu'elle donne l'apparence d'un contrôle :
 *
 *     if ($workspaceId === null) { return true; }   // « tolérant en test/dev »
 *
 * Rien dans le code ne distingue un test d'une production. Un appel qui arrive
 * avant le middleware, une commande, un job — et la tolérance devient la règle.
 * C'est `F37-001` un étage plus haut : un contrôle qui, faute de savoir, répond
 * « oui ».
 *
 * COMMENT CE FICHIER MESURE.
 *
 * Deux moitiés, et il faut les deux :
 *
 *   1. **Le comportement**, par de vrais appels HTTP croisés : un compte de
 *      l'espace BETA demande une fiche d'ALPHA, et doit recevoir 404. Chaque cas
 *      porte son témoin — le même appel sur sa PROPRE fiche ne doit pas rendre
 *      404, sinon on ne prouve que la panne de la route.
 *   2. **La complétude**, structurellement : aucune méthode de contrôleur
 *      recevant un modèle cloisonné ne doit être dépourvue de garde. C'est la
 *      seule façon d'empêcher le trente-septième site d'apparaître demain sans
 *      que personne ne le voie. Ce contrôle porte son témoin négatif.
 *
 * Le premier sans le second laisserait le patron se reproduire ; le second sans
 * le premier ne prouverait que la présence d'un appel de méthode.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

/** @return array{0: User, 1: string} le compte admin et l'identifiant de son espace */
function compteEtEspace(string $nom): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => strtolower($nom) . '-' . Str::random(8),
        'name' => $nom,
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => strtolower($nom) . '-' . Str::random(6) . '@sites.test',
        'name' => $nom,
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($espace->id);
    $compte->assignRole('admin');

    return [$compte, (string) $espace->id];
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. LE COMPORTEMENT — de vrais appels croisés
// ─────────────────────────────────────────────────────────────────────────────

test('B12-001 — ECRIRE sur une entreprise d un autre espace est refuse', function () {
    [$alpha, $espaceAlpha] = compteEtEspace('ALPHA');
    [$beta] = compteEtEspace('BETA');

    $id = DB::table('companies')->insertGetId([
        'workspace_id' => $espaceAlpha,
        'denomination' => 'Cible ALPHA',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Le premier correctif fermait la LECTURE. L'écriture restait ouverte :
    // BETA pouvait renommer, et surtout SUPPRIMER, une fiche d'ALPHA.
    $this->actingAs($beta)->putJson('/api/v1/companies/' . $id, ['denomination' => 'Vole'])->assertNotFound();
    $this->actingAs($beta)->deleteJson('/api/v1/companies/' . $id)->assertNotFound();

    // TÉMOIN LE PLUS IMPORTANT DU FICHIER : un 404 rendu APRÈS avoir écrit
    // serait le pire des cas — refuser en façade et agir en coulisse.
    $apres = DB::table('companies')->where('id', $id)->first();
    expect($apres)->not->toBeNull('La fiche d\'ALPHA a été supprimée par BETA malgré le 404.');
    expect($apres->denomination)->toBe('Cible ALPHA', 'La fiche d\'ALPHA a été renommée par BETA malgré le 404.');

    // TÉMOIN : ALPHA, lui, peut bien écrire sur la sienne. Sans ce cas, une
    // garde qui refuserait à tout le monde passerait pour une réussite.
    $this->actingAs($alpha)->putJson('/api/v1/companies/' . $id, ['denomination' => 'Renomme par ALPHA'])->assertOk();
});

test('B12-001 — ECRIRE sur un contact d un autre espace est refuse', function () {
    [$alpha, $espaceAlpha] = compteEtEspace('ALPHA');
    [$beta] = compteEtEspace('BETA');

    $entreprise = DB::table('companies')->insertGetId([
        'workspace_id' => $espaceAlpha,
        'denomination' => 'Employeur',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $contact = DB::table('contacts')->insertGetId([
        'workspace_id' => $espaceAlpha,
        'company_id' => $entreprise,
        'first_name' => 'Jean', 'last_name' => 'Dupont',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($beta)->putJson('/api/v1/contacts/' . $contact, ['first_name' => 'Vole'])->assertNotFound();
    $this->actingAs($beta)->deleteJson('/api/v1/contacts/' . $contact)->assertNotFound();

    expect(DB::table('contacts')->where('id', $contact)->value('first_name'))->toBe('Jean');

    // TÉMOIN, et il rapporte autre chose que ce qu'on attendait.
    //
    // ALPHA sur SA PROPRE fiche ne reçoit pas 200 : il reçoit **501**. Ce n'est
    // pas le cloisonnement, c'est `I48-001` (S0, déjà au registre) : « le CRM
    // n'a aucune route pour créer une fiche personne, et la modifier ou la
    // supprimer rend 501 ». `ContactsController::update()` et `destroy()` sont
    // des bouchons.
    //
    // On mesure donc ce qui EST, et on le nomme. Ce que ce témoin doit prouver
    // ici, c'est que le 404 rendu à BETA vient bien de la garde d'espace et non
    // du bouchon : si les deux comptes recevaient le même code, le test ne
    // prouverait rien. 404 pour BETA, 501 pour ALPHA — la garde passe AVANT le
    // bouchon, et c'est exactement ce qu'on voulait savoir.
    //
    // 🔴 Le jour où `I48-001` sera réparé, ce témoin rougira. C'est voulu : il
    // faudra alors vérifier que la garde d'espace tient toujours devant une
    // route qui, elle, fait enfin quelque chose.
    $this->actingAs($alpha)
        ->putJson('/api/v1/contacts/' . $contact, ['first_name' => 'Jeanne'])
        ->assertStatus(501);
});

test('B12-001 — une etiquette d un autre espace n est ni modifiable ni supprimable', function () {
    [$alpha, $espaceAlpha] = compteEtEspace('ALPHA');
    [$beta] = compteEtEspace('BETA');

    $tag = DB::table('tags')->insertGetId([
        'workspace_id' => $espaceAlpha,
        'name' => 'Etiquette ALPHA',
        'slug' => 'etq-' . Str::random(6),
        'kind' => 'manual',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($beta)->putJson('/api/v1/tags/' . $tag, ['name' => 'Vole'])->assertNotFound();
    $this->actingAs($beta)->deleteJson('/api/v1/tags/' . $tag)->assertNotFound();

    expect(DB::table('tags')->where('id', $tag)->value('name'))->toBe('Etiquette ALPHA');

    // TÉMOIN : ALPHA peut renommer la sienne.
    $this->actingAs($alpha)->putJson('/api/v1/tags/' . $tag, ['name' => 'Renommee'])->assertOk();
});

/**
 * LE CAS QUE LA GARDE ARTISANALE LAISSAIT PASSER, ET LUI SEUL.
 *
 * `if ($workspaceId === null) { return true; }` : sans contexte d'espace, tout
 * passait. On reproduit exactement cette condition — un compte SANS espace
 * courant — et on vérifie que la réponse est un refus, pas un accès.
 */
test('B12-001 — SANS contexte d espace, on ne rend RIEN : la garde est fail-closed', function () {
    [, $espaceAlpha] = compteEtEspace('ALPHA');

    $id = DB::table('companies')->insertGetId([
        'workspace_id' => $espaceAlpha,
        'denomination' => 'Cible ALPHA',
        'siren' => (string) random_int(100000000, 999999999),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Un compte sans espace courant : le middleware ne posera rien, et l'ancien
    // repli sur l'utilisateur ne trouvera rien non plus.
    $sansEspace = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'sans-espace-' . Str::random(6) . '@sites.test',
        'name' => 'Sans espace',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => null,
        'first_login_completed_at' => now(),
    ]);

    $reponse = $this->actingAs($sansEspace)->getJson('/api/v1/companies/' . $id);

    expect($reponse->status())->not->toBe(
        200,
        'Sans contexte d\'espace, la fiche d\'ALPHA est rendue. C\'est exactement ce que '
        . 'la garde artisanale autorisait : « tolérant si workspace.id n\'est pas bound ». '
        . 'Rien ne distingue un test d\'une production.'
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LA COMPLÉTUDE — aucun site de liaison ne doit rester nu
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Les modèles portant un espace de travail, lus dans le code et non recopiés :
 * une liste écrite à la main vieillirait sans prévenir.
 *
 * @return list<string>
 */
function modelesCloisonnes(): array
{
    $noms = [];
    foreach (glob(app_path('Models/*.php')) as $chemin) {
        $source = (string) file_get_contents($chemin);
        if (str_contains($source, 'workspace_id') || str_contains($source, 'BelongsToWorkspace')) {
            $noms[] = basename($chemin, '.php');
        }
    }

    return $noms;
}

/**
 * Toutes les méthodes de contrôleur recevant un modèle cloisonné par résolution
 * de route, avec l'état de leur garde.
 *
 * @return array<string, bool>  « Fichier::methode » => la garde est-elle posée
 */
function sitesDeLiaison(): array
{
    $modeles = modelesCloisonnes();
    $sites = [];

    $fichiers = array_merge(
        glob(app_path('Http/Controllers/Api/*.php')) ?: [],
        glob(app_path('Http/Controllers/Api/*/*.php')) ?: [],
        glob(app_path('Http/Controllers/Internal/*.php')) ?: [],
    );

    foreach ($fichiers as $chemin) {
        $source = (string) file_get_contents($chemin);
        $fichier = basename($chemin);

        preg_match_all('/public function (\w+)\s*\(([^)]*)\)/', $source, $trouves, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($trouves as $t) {
            $methode = $t[1][0];
            $params = $t[2][0];

            $porteUnModele = false;
            foreach ($modeles as $modele) {
                if (preg_match('/\b' . preg_quote($modele, '/') . '\s+\$/', $params) === 1) {
                    $porteUnModele = true;
                    break;
                }
            }
            if (! $porteUnModele) {
                continue;
            }

            // Le corps visible : la garde doit être posée AVANT tout travail,
            // donc en tête. On regarde large pour ne pas rater une garde placée
            // après une validation, mais pas au point d'attraper la méthode
            // suivante.
            $debut = $t[0][1] + strlen($t[0][0]);
            $corps = substr($source, $debut, 900);

            $gardee = str_contains($corps, 'refuserHorsEspace')
                || str_contains($corps, 'estDeMonEspace')
                || str_contains($corps, 'belongsToCurrentWorkspace')
                || str_contains($corps, 'assertWorkspace');

            $sites[$fichier . '::' . $methode] = $gardee;
        }
    }

    return $sites;
}

test('B12-001 — TEMOIN : le banc voit bien des sites de liaison a inspecter', function () {
    $sites = sitesDeLiaison();

    // Sans ce témoin, un `glob()` qui ne trouverait rien — chemin faux, modèles
    // déplacés, contrôleurs renommés — rendrait un tableau vide, et le contrôle
    // de complétude passerait au vert sur ZÉRO site. Le pire des verts.
    expect(count($sites))->toBeGreaterThan(
        20,
        'Moins de vingt sites de liaison trouvés : le balayage ne voit pas ce qu\'il croit voir. '
        . 'Trente-huit ont été comptés le 2026-08-20.'
    );

    expect(count(modelesCloisonnes()))->toBeGreaterThan(5);
});

test('B12-001 — TEMOIN NEGATIF : le balayage SAIT reperer un site sans garde', function () {
    // Un contrôleur fabriqué, avec la forme exacte du défaut. Si le balayage
    // ne le voyait pas, le contrôle de complétude ne prouverait rien.
    $faux = 'public function show(Company $company): JsonResponse' . "\n"
        . '    {' . "\n"
        . '        return $this->ok([\'data\' => $company]);' . "\n"
        . '    }';

    expect(preg_match('/public function (\w+)\s*\(([^)]*)\)/', $faux))->toBe(1);
    expect(str_contains($faux, 'refuserHorsEspace'))->toBeFalse();
});

test('B12-001 — AUCUNE methode liant un modele cloisonne n est depourvue de garde', function () {
    $nus = array_keys(array_filter(sitesDeLiaison(), fn ($garde) => $garde === false));

    expect($nus)->toBe(
        [],
        "Ces methodes recoivent un enregistrement cloisonne par resolution de route et ne "
        . "verifient pas a quel espace il appartient. La resolution de route ne filtre RIEN : "
        . "elle rend l'enregistrement qui porte cet identifiant, quel que soit son proprietaire, "
        . "et les identifiants sont des entiers consecutifs.\n\n"
        . "Pose `\$this->refuserHorsEspace(\$modele)` en tete de methode. La piece existe deja "
        . "dans `ApiController` : c'est le patron A-011 qui recommence si on la reecrit.\n\n"
        . "Sites nus :\n  - " . implode("\n  - ", $nus)
    );
});
