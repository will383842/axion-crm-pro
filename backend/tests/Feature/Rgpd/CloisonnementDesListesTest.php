<?php

/**
 * GARDE DU CLOISONNEMENT DES **LISTES** — constats P6-API-001 et P6-API-002 (S0).
 *
 * POURQUOI CE FICHIER EXISTE, ET C'EST UN AVEU.
 *
 * Le 2026-08-20, le cloisonnement a été porté sur **36 sites de liaison de
 * route** (`show`, `update`, `destroy`, …), et un contrôle de complétude a été
 * écrit pour empêcher qu'un trente-neuvième apparaisse sans garde.
 *
 * 🔴 **Ce contrôle énumérait les méthodes qui REÇOIVENT un modèle par
 * résolution de route.** Un `index()` n'en reçoit aucun. Les listes étaient donc
 * **structurellement invisibles** à la garde censée prouver la complétude — et
 * elle était verte.
 *
 * Le résultat, mesuré par une passe à regard neuf quelques heures plus tard :
 * `refuserHorsEspace` couvrait **20 méthodes unitaires et ZÉRO liste**.
 *
 *   GET /v1/journalists ....... `Journalist::query()->whereNull('deleted_at')`.
 *                               L'unique `where('workspace_id')` du fichier est
 *                               dans `export()`, pas dans la liste.
 *   GET /v1/rgpd/requests ..... `RgpdRequest::query()->when(status)->paginate()`,
 *                               et il rend `subject_email` EN CLAIR.
 *
 * *Une fuite par la liste est pire qu'une fuite par la fiche : la fiche demande
 * de deviner un identifiant, la liste les donne tous.*
 *
 * COMMENT CETTE GARDE MESURE, ET POURQUOI PAS AU `grep`.
 *
 * Trois balayages statiques successifs ont rendu trois comptes différents — 20,
 * 5, 7 — parce qu'un contrôleur peut filtrer dans un helper privé, par un scope,
 * ou sous un autre nom de colonne (`current_workspace_id`). **Un motif de
 * recherche ne suit pas l'indirection.**
 *
 * On ne cherche donc plus la garde dans le code : **on regarde ce que la route
 * rend**. Deux espaces, deux enregistrements, un appel, et l'on inspecte le
 * corps. C'est la seule mesure qu'aucune indirection ne trompe.
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

/** @return array{0: User, 1: string} */
function compteListe(string $nom): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => strtolower($nom) . '-' . Str::random(8),
        'name' => $nom,
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => strtolower($nom) . '-' . Str::random(6) . '@listes.test',
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
 * Toutes les valeurs textuelles d'une réponse JSON, à plat.
 *
 * On ne suppose RIEN de la forme de la réponse : `data`, `data.data`, une
 * enveloppe de pagination, une ressource. On aplatit et on cherche le marqueur.
 * Une garde qui devine la forme rate la fuite dès qu'elle change.
 *
 * @return list<string>
 */
function valeursAPlat(mixed $noeud): array
{
    if (is_scalar($noeud)) {
        return [(string) $noeud];
    }
    if (! is_array($noeud)) {
        return [];
    }

    $out = [];
    foreach ($noeud as $v) {
        $out = array_merge($out, valeursAPlat($v));
    }

    return $out;
}

/**
 * Le cœur de la garde : la liste de B ne doit jamais contenir le marqueur de A.
 */
function listeNeFuitPas(string $route, string $marqueurDeA, string $marqueurDeB, User $comptB): void
{
    $reponse = test()->actingAs($comptB)->getJson($route);

    // Un 403 ou un 404 est un cloisonnement acceptable ; un 500 ne l'est pas,
    // et un 200 doit être inspecté.
    expect($reponse->status())->not->toBe(
        500,
        "{$route} rend 500 : on ne peut RIEN conclure du cloisonnement sur une route cassee.",
    );

    if ($reponse->status() !== 200) {
        return;
    }

    $valeurs = valeursAPlat($reponse->json());

    // TÉMOIN INTÉGRÉ : sans lui, une route qui rend une liste VIDE passerait
    // pour cloisonnée. On exige que B voie SA propre ligne.
    //
    // ⚠️ `assertContains()` et NON `expect()->toContain()`. Ce dernier est
    // VARIADIQUE : un message passé en second argument y devient une DEUXIÈME
    // valeur à chercher, et la garde rougit éternellement en cherchant sa
    // propre phrase d'explication dans la réponse HTTP.
    //
    // C'est la QUATRIÈME fois de la journée que ce piège se referme — après
    // `ServeurHttpDeProductionTest` (six fois), `JournalAuditCloisonneTest` et
    // `PatronHmacDeReferenceTest`. Il s'est refermé ici APRÈS avoir été
    // documenté dans les trois autres. Le savoir ne suffit pas : il faut la
    // règle mécanique — **aucun message dans un `toContain`, jamais.**
    test()->assertContains(
        $marqueurDeB,
        $valeurs,
        "{$route} ne rend pas la ligne de B : ce vert ne prouverait pas le cloisonnement, "
        . 'seulement que la route est muette ou cassee.',
    );

    test()->assertNotContains(
        $marqueurDeA,
        $valeurs,
        "🔴 {$route} rend une ligne d'un AUTRE espace de travail.\n\n"
        . "Une fuite par la LISTE est pire qu'une fuite par la fiche : la fiche demande de "
        . "deviner un identifiant, la liste les donne tous.\n\n"
        . 'Le correctif de cloisonnement du 2026-08-20 a ete porte sur 36 methodes unitaires '
        . '(show/update/destroy) et sur AUCUNE liste : le controle de completude enumerait les '
        . "methodes qui recoivent un modele par resolution de route, et un `index()` n'en "
        . 'recoit aucun.',
    );
}

test('P6-API — TEMOIN NEGATIF : l aplatissement retrouve bien une valeur enfouie', function () {
    // Sans ce cas, `valeursAPlat()` pourrait rendre un tableau vide sur toutes
    // les formes de reponse, et TOUTES les gardes ci-dessous passeraient au vert
    // sans rien inspecter. C'est le pire des verts.
    $reponse = ['data' => [['id' => 1, 'nested' => ['email' => 'cible@example.test']]], 'meta' => ['total' => 1]];

    expect(valeursAPlat($reponse))->toContain('cible@example.test');
    expect(valeursAPlat($reponse))->not->toContain('absent@example.test');
});

test('P6-API-002 — GET /rgpd/requests ne rend PAS les demandes d un autre espace', function () {
    [, $espaceA] = compteListe('ALPHA');
    [$b, $espaceB] = compteListe('BETA');

    foreach ([[$espaceA, 'alpha-secret@rgpd.test'], [$espaceB, 'beta-a-moi@rgpd.test']] as [$ws, $mail]) {
        DB::table('rgpd_requests')->insert([
            'workspace_id' => $ws,
            'type' => 'access',
            'subject_email' => $mail,
            'status' => 'pending',
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // `subject_email` est une donnee personnelle, rendue EN CLAIR par cette route.
    listeNeFuitPas('/api/v1/rgpd/requests', 'alpha-secret@rgpd.test', 'beta-a-moi@rgpd.test', $b);
});

test('P6-API-001 — GET /journalists ne rend PAS les journalistes d un autre espace', function () {
    if (! Schema::hasTable('journalists')) {
        test()->markTestSkipped('table journalists absente de ce banc');
    }

    [, $espaceA] = compteListe('ALPHA');
    [$b, $espaceB] = compteListe('BETA');

    foreach ([[$espaceA, 'Alpha Secret'], [$espaceB, 'Beta A Moi']] as [$ws, $nom]) {
        DB::table('journalists')->insert([
            'workspace_id' => $ws,
            'last_name' => $nom,
            // `email` est une donnee personnelle : c'est elle qui fait la gravite
            // de la fuite, pas le nom. On la pose donc, et on la cherche.
            'email' => strtolower(str_replace(' ', '-', $nom)) . '@presse.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    listeNeFuitPas('/api/v1/journalists', 'alpha-secret@presse.test', 'beta-a-moi@presse.test', $b);
});

test('G41-010 — GET /media ne rend PAS les medias d un autre espace', function () {
    // 🔴 CONSTAT G41-010 (S2). Le meme defaut que P6-API-001 ci-dessus, sur le
    // controleur d'a cote, est reste ouvert deux jours de plus :
    // `JournalistsController` a ete repare le 2026-08-20, `MediaController` ne
    // l'a ete que le 2026-08-22. Deux `buildFilteredQuery()` jumelles, un seul
    // correctif — le motif A-011, et la raison pour laquelle cette garde MESURE
    // la seconde au lieu de se fier a la ressemblance des deux fichiers.
    //
    // La liste rendait `media.email` et `media.phone` — les coordonnees de
    // redaction de TOUS les espaces — a tout compte habilite.
    if (! Schema::hasTable('media')) {
        test()->markTestSkipped('table media absente de ce banc');
    }

    [, $espaceA] = compteListe('ALPHA');
    [$b, $espaceB] = compteListe('BETA');

    foreach ([[$espaceA, 'Alpha Media Secret'], [$espaceB, 'Beta Media A Moi']] as [$ws, $nom]) {
        DB::table('media')->insert([
            'workspace_id' => $ws,
            // `name` est le marqueur, et ce choix n'est pas un gout :
            // `MasquageCoordonnees::masquerSiRequis()` peut caviarder `email`
            // selon le role. Chercher un marqueur caviarde ferait verdir la
            // garde pour la mauvaise raison — on aurait mesure un masquage en
            // croyant mesurer un cloisonnement.
            'name' => $nom,
            'media_type' => 'presse_quotidien',
            // Posee quand meme : le jour ou le masquage saute, la fuite portera
            // sur cette colonne-la, et l'aplatissement la verra.
            'email' => strtolower(str_replace(' ', '-', $nom)) . '@redaction.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    listeNeFuitPas('/api/v1/media', 'Alpha Media Secret', 'Beta Media A Moi', $b);
});

test('P6-API — GET /proxy-providers ne rend PAS les fournisseurs d un autre espace', function () {
    // ⚠️ La table s'appelle `proxy_providers_config`, pas `proxy_providers` :
    // c'est `ProxyProvider::$table` qui le dit. Une premiere version de ce test
    // interrogeait le mauvais nom, se declarait IGNOREE, et ne prouvait donc
    // RIEN -- un test ignore est un vert deguise. Le nom est lu dans le modele
    // plutot que recopie.
    $table = (new ProxyProvider)->getTable();
    expect(Schema::hasTable($table))->toBeTrue(
        "La table {$table} est absente du banc : cette garde ne mesurerait rien.",
    );

    [, $espaceA] = compteListe('ALPHA');
    [$b, $espaceB] = compteListe('BETA');

    foreach ([[$espaceA, 'alpha-proxy-secret'], [$espaceB, 'beta-proxy-a-moi']] as [$ws, $nom]) {
        DB::table($table)->insert([
            'workspace_id' => $ws,
            'slug' => $nom,
            'type' => 'residential',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    listeNeFuitPas('/api/v1/proxy-providers', 'alpha-proxy-secret', 'beta-proxy-a-moi', $b);
});

test('P6-API — GET /llm/use-cases ne rend PAS les cas d usage d un autre espace', function () {
    if (! Schema::hasTable('llm_use_cases')) {
        test()->markTestSkipped('table llm_use_cases absente de ce banc');
    }

    [, $espaceA] = compteListe('ALPHA');
    [$b, $espaceB] = compteListe('BETA');

    foreach ([[$espaceA, 'alpha-usecase-secret'], [$espaceB, 'beta-usecase-a-moi']] as [$ws, $cle]) {
        DB::table('llm_use_cases')->insert([
            'workspace_id' => $ws,
            'slug' => $cle,
            'primary_provider' => 'mistral',
            'model' => 'mistral-small',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    listeNeFuitPas('/api/v1/llm/use-cases', 'alpha-usecase-secret', 'beta-usecase-a-moi', $b);
});
