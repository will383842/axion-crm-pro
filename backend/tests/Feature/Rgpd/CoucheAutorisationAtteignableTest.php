<?php

/**
 * GARDE : LA COUCHE D'AUTORISATION EST-ELLE SEULEMENT ATTEIGNABLE ?
 * Constats F36-002 (S0, la cause) et F36-001 (S0, l'effet).
 *
 * CE QUE L'AUDIT A TROUVÉ, ET L'ORDRE DES DEUX CONSTATS COMPTE.
 *
 * `F36-001` dit : *« aucune des policies n'est jamais invoquée : la couche d'autorisation du
 * produit est du code mort »*. Dix policies sont enregistrées dans `AuthServiceProvider`,
 * écrites, testables.
 *
 * `F36-002` en donne la cause **mécanique** : `ApiController` étend
 * `Illuminate\Routing\Controller` — celle du framework, qui ne porte pas `AuthorizesRequests`
 * depuis Laravel 11. La classe de base du dépôt la porte, mais aucun contrôleur d'API ne
 * l'étend. `$this->authorize()` levait donc **« Call to undefined method »**, et le dépôt n'en
 * contenait **aucune occurrence**.
 *
 * *Une porte fermée à clef dont la serrure n'est reliée à rien.*
 *
 * ⚠️ CE QUE CETTE GARDE PROUVE, ET CE QU'ELLE NE PROUVE PAS.
 *
 * Elle prouve que la couche est **atteignable** : la méthode existe, et une policy appelée à
 * travers elle est réellement exécutée. C'est `F36-002`.
 *
 * Elle NE prouve PAS que les routes l'appellent — **`F36-001` reste ouvert**, et le dernier
 * test de ce fichier le FIGE en le comptant. Câbler les 37 contrôleurs change le comportement
 * de ~110 points d'API : c'est un choix de conception, pas un correctif.
 *
 * *Un constat qu'on laisse ouvert doit être compté, sinon il se perd.*
 */

use App\Http\Controllers\Api\ApiController;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('F36-002 — `authorize()` EXISTE sur les controleurs d API', function () {
    // Le defaut n'etait pas subtil : la methode n'existait pas. Un appel la
    // trouvait « undefined » et rendait 500.
    expect(method_exists(ApiController::class, 'authorize'))->toBeTrue(
        "`\$this->authorize()` n'existe pas sur ApiController. Les 37 controleurs d'API en "
        . "heritent : tout appel a la couche d'autorisation y est un appel FATAL, et c'est la "
        . "cause mecanique de F36-001 (« la couche d'autorisation est du code mort »)."
    );

    expect(in_array(AuthorizesRequests::class, class_uses_recursive(ApiController::class), true))
        ->toBeTrue();
});

test('F36-002 — TEMOIN NEGATIF : le controle SAIT reperer une classe sans le trait', function () {
    // Sans ce cas, le test ci-dessus pourrait passer sur n'importe quoi : il faut
    // prouver que `class_uses_recursive` distingue vraiment.
    $sansTrait = new class {};

    expect(in_array(AuthorizesRequests::class, class_uses_recursive($sansTrait), true))
        ->toBeFalse();
    expect(method_exists($sansTrait, 'authorize'))->toBeFalse();
});

test('F36-002 — une policy appelee a travers la couche est REELLEMENT executee', function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    $espace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'pol-' . Str::random(6), 'name' => 'Policies',
    ]);
    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'pol-' . Str::random(6) . '@policy.test',
        'name' => 'Policy',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace->id);
    $compte->assignRole('admin');

    $entreprise = Company::create([
        'workspace_id' => $espace->id,
        'denomination' => 'Cible policy',
        'siren' => (string) random_int(100000000, 999999999),
    ]);

    // On REMPLACE la policy le temps du test, pour prouver qu'elle est bien
    // consultee : si Gate ne l'invoquait pas, la valeur sentinelle ne remonterait
    // jamais. C'est le seul moyen de distinguer « la policy dit oui » de
    // « personne n'a demande a la policy ».
    $temoin = new class {
        public bool $appelee = false;

        public function view($user, $modele): bool
        {
            $this->appelee = true;

            return false;
        }
    };
    Gate::policy(Company::class, get_class($temoin));
    app()->instance(get_class($temoin), $temoin);

    $verdict = Gate::forUser($compte)->allows('view', $entreprise);

    expect($temoin->appelee)->toBeTrue(
        "La policy n'a pas ete consultee : Gate ne la resout pas. Une couche d'autorisation "
        . "qu'on peut appeler mais qui n'interroge personne ne vaut pas mieux qu'une couche "
        . 'absente — elle est pire, parce qu'
        . "elle rassure."
    );
    expect($verdict)->toBeFalse();
});

/**
 * F36-001 RESTE OUVERT, ET CE TEST LE COMPTE.
 *
 * Rendre `authorize()` appelable ne le fait pas appeler. Ce test mesure le nombre de contrôleurs
 * d'API qui l'invoquent réellement — aujourd'hui **zéro** — et il est écrit pour **rougir le jour
 * où quelqu'un commencera à câbler**, afin qu'on mette alors le vrai chiffre et qu'on décide
 * consciemment de la suite.
 *
 * *Un constat laissé ouvert sans compteur se perd. Celui-ci ne peut plus.*
 */
test('F36-001 — combien de controleurs invoquent REELLEMENT la couche : le compte est fige', function () {
    $racine = app_path('Http/Controllers');
    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));

    $inspectes = 0;
    $avecAppel = [];

    foreach ($fichiers as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }
        $inspectes++;
        $source = (string) file_get_contents($fichier->getPathname());

        // ⚠️ ON RETIRE LES COMMENTAIRES AVANT DE COMPTER, et c'est la finesse du test.
        //
        // La premiere version comptait la source brute -- et signalait ApiController,
        // parce que le docbloc qui EXPLIQUE le correctif contient `$this->authorize()`.
        // Un compteur d'appels qui compte la prose ne compte pas des appels.
        //
        // `token_get_all()` plutot qu'une expression reguliere : le tokeniseur de PHP
        // sait exactement ou finit un commentaire, une regex ne le sait pas.
        $code = '';
        foreach (token_get_all($source) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        if (str_contains($code, '$this->authorize(') || str_contains($code, 'Gate::authorize(')) {
            $avecAppel[] = str_replace($racine . DIRECTORY_SEPARATOR, '', $fichier->getPathname());
        }
    }

    // TEMOIN DE COUVERTURE : sans lui, un chemin faux rendrait zero fichier et le
    // compte « zero appel » serait vrai pour la mauvaise raison. Le pire des verts.
    expect($inspectes)->toBeGreaterThan(
        30,
        "Seulement {$inspectes} controleurs inspectes : le balayage ne voit pas ce qu'il croit voir."
    );

    expect($avecAppel)->toBe(
        [],
        "🟢 BONNE NOUVELLE, ET IL FAUT AGIR EN CONSEQUENCE : des controleurs invoquent desormais "
        . "la couche d'autorisation, alors que le compte fige ici est ZERO.\n\n"
        . "Ce test n'est pas une interdiction : c'est un COMPTEUR. Le constat F36-001 (« aucune "
        . "policy n'est jamais invoquee ») etait vrai au 2026-08-20 ; il ne l'est plus.\n\n"
        . "Mets a jour ce test avec le nouveau compte, et surtout : verifie que chaque appel "
        . "ajoute est accompagne de sa garde. Cabler une policy sans mesurer ce qu'elle refuse, "
        . "c'est refaire B16-004 — une permission posee, une fuite qui reste.\n\n"
        . "Controleurs concernes :\n  - " . implode("\n  - ", $avecAppel)
    );
});
