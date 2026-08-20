<?php

/**
 * GARDE — audit 360, B16-005 (S1) : l'empreinte du corps d'une requete de
 * connexion etait un ORACLE DE MOT DE PASSE.
 *
 * `AuditHashChainLogger` ecrivait, pour toute requete mutative :
 *
 *     payload_hash = sha256( json_encode($request->all()) )
 *
 * Sur `POST api/v1/auth/login`, ce corps est `{"email":"...","password":"..."}`.
 * Le condense stocke etait donc une fonction deterministe, SANS SEL et SANS
 * COUT, du mot de passe — dont l'autre entree (l'email) est connue de celui
 * qui lit la ligne. Verifier une hypothese de mot de passe hors ligne ne
 * demandait alors qu'un SHA-256 par essai, soit des milliards par seconde sur
 * une carte graphique, contre quelques milliers pour le bcrypt du compte.
 *
 * Ce n'est pas « le mot de passe en clair dans le journal » — et ce n'est pas
 * anodin non plus : c'est un banc d'essai de mots de passe, stocke dans la
 * piece meme qui est censee servir de preuve, et servi par une route HTTP.
 *
 * La propriete que ces gardes exigent est exactement celle-ci : DEUX MOTS DE
 * PASSE DIFFERENTS, MEME COMPTE, DOIVENT DONNER LA MEME EMPREINTE. Sans quoi
 * l'empreinte distingue les mots de passe, donc les verifie.
 */

use App\Http\Middleware\AuditHashChainLogger as Journalisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Note de mesure : `throttle:login` autorise 5 tentatives par minute et par IP
 * (`RouteServiceProvider`) — les deux connexions de chaque garde atteignent
 * donc bien le middleware d'audit.
 */

/** Les empreintes ecrites pour un chemin donne, dans l'ordre d'ecriture. */
function empreintesDuChemin(string $chemin): array
{
    return DB::table('audit_logs')
        ->where('path', $chemin)
        ->orderBy('id')
        ->pluck('payload_hash')
        ->all();
}

// ─────────────────────────────────────────────────────────────────────────────
// LE DEFAUT
// ─────────────────────────────────────────────────────────────────────────────

test('B16-005 — deux mots de passe differents laissent la MEME empreinte', function () {
    $courriel = 'cible-b16005@audit.test';

    $this->postJson('/api/v1/auth/login', [
        'email' => $courriel,
        'password' => 'HypotheseNumeroUn2026!',
    ]);
    $this->postJson('/api/v1/auth/login', [
        'email' => $courriel,
        'password' => 'HypotheseNumeroDeux2026!',
    ]);

    $empreintes = empreintesDuChemin('api/v1/auth/login');

    // TEMOIN 1 — la garde a bien vu passer les deux requetes. Sans cette
    // assertion, un middleware d'audit debranche (table vide, aucune ligne)
    // rendrait le test VERT en ne mesurant rien du tout.
    $this->assertCount(
        2,
        $empreintes,
        'Aucune ligne de journal pour la connexion : la garde ne mesure rien.'
    );

    // TEMOIN 2 — l'empreinte est bien un condense, pas une colonne vide ou une
    // constante nulle que l'on pourrait confondre avec un masquage reussi.
    foreach ($empreintes as $empreinte) {
        expect($empreinte)->toMatch('/^[0-9a-f]{64}$/');
    }

    // LE CONSTAT — sur le code casse, ces deux valeurs different : l'empreinte
    // distingue les deux mots de passe, donc elle permet de les tester.
    $this->assertSame(
        $empreintes[0],
        $empreintes[1],
        "L'empreinte du corps de connexion depend du mot de passe : elle permet "
        . 'de verifier une hypothese hors ligne (constat B16-005).'
    );
});

test('B16-005 — TEMOIN : l empreinte distingue toujours DEUX COMPTES differents', function () {
    // Le correctif ne doit pas etre « hacher une constante » : une empreinte qui
    // ne varie plus du tout ne journaliserait plus rien. Ce temoin echouerait
    // sur un masquage aveugle de tout le corps.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'compte-un@audit.test',
        'password' => 'MemeMotDePasse2026!',
    ]);
    $this->postJson('/api/v1/auth/login', [
        'email' => 'compte-deux@audit.test',
        'password' => 'MemeMotDePasse2026!',
    ]);

    $empreintes = empreintesDuChemin('api/v1/auth/login');

    $this->assertCount(2, $empreintes, 'La garde ne mesure rien.');
    $this->assertNotSame(
        $empreintes[0],
        $empreintes[1],
        "L'empreinte ne distingue plus deux comptes : le journal ne journalise plus rien."
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// LA MECANIQUE, MESUREE DIRECTEMENT
// ─────────────────────────────────────────────────────────────────────────────

test('B16-005 — le masquage tient sur un corps IMBRIQUE', function () {
    // Un masquage ecrit « a plat » laisserait passer ce cas, et une garde ecrite
    // a plat ne l'aurait pas vu.
    $un = Journalisateur::empreinteDuCorps(['utilisateur' => ['email' => 'a@b.c', 'password' => 'UnDeuxTrois']]);
    $deux = Journalisateur::empreinteDuCorps(['utilisateur' => ['email' => 'a@b.c', 'password' => 'QuatreCinqSix']]);

    $this->assertSame($un, $deux, 'Un mot de passe imbrique reste distinguable dans l empreinte.');
});

test('B16-005 — les variantes du champ mot de passe sont couvertes', function () {
    // `password_confirmation`, `current_password`, `new_password`, `_token`,
    // `reset_token` : autant de portes par lesquelles un secret entre.
    foreach (['password_confirmation', 'current_password', 'new_password', 'reset_token', 'otp'] as $champ) {
        $un = Journalisateur::empreinteDuCorps([$champ => 'valeur-A']);
        $deux = Journalisateur::empreinteDuCorps([$champ => 'valeur-B']);

        $this->assertSame($un, $deux, "Le champ « {$champ} » entre encore dans l empreinte.");
    }
});

test('B16-005 — TEMOIN : un champ metier ordinaire entre TOUJOURS dans l empreinte', function () {
    // Sans ce temoin, un correctif qui masquerait tout passerait pour une
    // reussite alors qu'il aurait supprime la colonne de fait.
    $un = Journalisateur::empreinteDuCorps(['name' => 'Societe A']);
    $deux = Journalisateur::empreinteDuCorps(['name' => 'Societe B']);

    $this->assertNotSame($un, $deux, "L'empreinte ne distingue plus rien : elle ne sert plus a rien.");
});

// ─────────────────────────────────────────────────────────────────────────────
// OU L'EMPREINTE EST-ELLE LISIBLE ? MESURE, PAS SUPPOSITION
// ─────────────────────────────────────────────────────────────────────────────

test('B16-005 — MESURE : la ligne de connexion n est PAS servie par la route du journal', function () {
    // Le constat annoncait l'empreinte « servie a tout compte authentifie ».
    // Mesure du 2026-08-20 : ce n'est plus exact depuis le correctif B16-004.
    // Une requete de connexion n'a pas de contexte d'espace — `workspace_id`
    // est donc NUL sur sa ligne — et `AuditLogsController::index()` filtre
    // desormais `where('workspace_id', <espace courant>)`, qu'aucune valeur
    // nulle ne satisfait.
    //
    // Cette garde FIGE ce constat : elle rougira le jour ou quelqu'un
    // assouplira le filtre (retour a `AuditLog::query()`, ou tolerance sur les
    // lignes sans espace). Elle ne remplace PAS le masquage : l'empreinte
    // reste lisible par une sauvegarde, une replique ou un `psql`, et c'est
    // pour cela que le correctif est a la source.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'mesure-exposition@audit.test',
        'password' => 'PeuImporte2026!',
    ]);

    $ligne = DB::table('audit_logs')->where('path', 'api/v1/auth/login')->first();

    // TEMOIN — la ligne existe bien, et son espace est nul : c'est CE fait qui
    // la soustrait a la route, et non l'absence de journalisation.
    $this->assertNotNull($ligne, 'La connexion n a pas ete journalisee : la garde ne mesure rien.');
    $this->assertNull($ligne->workspace_id, "La ligne de connexion porte un espace : la mesure ci-dessous ne vaut plus.");

    $espace = \App\Models\Workspace::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'slug' => 'expo-' . \Illuminate\Support\Str::random(6),
        'name' => 'Espace observateur',
    ]);
    $this->seed(\Database\Seeders\PermissionsAndRolesSeeder::class);
    $observateur = \App\Models\User::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'email' => 'obs-' . \Illuminate\Support\Str::random(6) . '@audit.test',
        'name' => 'Observateur',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace->id);
    $observateur->assignRole('admin');

    $reponse = $this->actingAs($observateur)->getJson('/api/v1/audit-logs');
    $reponse->assertOk();

    $cheminsServis = array_column((array) $reponse->json('data'), 'path');
    $this->assertNotContains(
        'api/v1/auth/login',
        $cheminsServis,
        "La ligne de connexion — donc son empreinte de corps — est servie par la route du journal."
    );
});

test('B16-005 — la reinitialisation de mot de passe est couverte, comme la connexion', function () {
    // SITE JUMEAU. Le meme middleware couvre toutes les routes mutatives : le
    // correctif ne doit pas etre une exception cousue pour `/auth/login`.
    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'reinit@audit.test',
        'token' => 'jeton-quelconque',
        'password' => 'PremiereHypothese2026!',
        'password_confirmation' => 'PremiereHypothese2026!',
    ]);
    $this->postJson('/api/v1/auth/password/reset', [
        'email' => 'reinit@audit.test',
        'token' => 'jeton-quelconque',
        'password' => 'SecondeHypothese2026!',
        'password_confirmation' => 'SecondeHypothese2026!',
    ]);

    $empreintes = empreintesDuChemin('api/v1/auth/password/reset');

    $this->assertCount(2, $empreintes, 'La garde ne mesure rien sur la reinitialisation.');
    $this->assertSame(
        $empreintes[0],
        $empreintes[1],
        "L'empreinte du corps de reinitialisation depend du mot de passe choisi."
    );
});
