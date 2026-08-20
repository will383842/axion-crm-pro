<?php

/**
 * GARDE : AUCUN SERVICE SIMULÉ EN PRODUCTION — constat C18-016 / F37-002 (S0).
 *
 * LE DÉFAUT, ET IL ÉTAIT DANS LE MAUVAIS SENS.
 *
 * `MockServicesProvider` commençait par `$master = (bool) env('MOCK_MODE', true);`.
 * **Le défaut était `true`.** Une variable absente du conteneur, mal orthographiée, ou perdue
 * lors d'un `docker compose restart` — qui ne relit pas `env_file`, constat A07-003 — et les six
 * services externes basculaient sur des simulacres. En production. Sans que rien ne le signale.
 *
 * 🔴 Le pire des six est le modèle de langage : `MockLLMClient` écrit des classifications
 * **fabriquées** dans la base, sur des fiches de personnes réelles. *Un simulacre qui remplit un
 * écran se voit ; un simulacre qui remplit une base ne se voit jamais.*
 *
 * CE QUE CETTE GARDE MESURE.
 *
 * Pas la présence d'un `if` : **ce que le conteneur d'injection résout réellement**. On demande
 * l'implémentation au conteneur, dans chaque environnement, et on regarde la classe obtenue.
 * Un `if` mal placé passerait un contrôle statique et échouerait ici.
 *
 * Les trois cas comptent, et il faut les trois :
 *   1. en `production`, un simulacre EXPLICITEMENT demandé est refusé ;
 *   2. en `production` sans aucune variable, on obtient le service réel — c'est le défaut ;
 *   3. en `testing`, les simulacres se branchent toujours — sinon la suite entière tomberait,
 *      et le correctif serait pire que le défaut.
 */

use App\Contracts\BanGeocoder;
use App\Contracts\InseeClient;
use App\Contracts\LLMClient;
use App\Services\Ban\HttpBanGeocoder;
use App\Services\Ban\Mocks\MockBanGeocoder;
use App\Services\Insee\HttpInseeClient;
use App\Services\Insee\Mocks\MockInseeClient;
use App\Services\LLM\LLMRouterService;
use App\Services\LLM\Mocks\MockLLMClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Rejoue l'enregistrement du provider dans un environnement donné, et rend la classe
 * que le conteneur résout pour un contrat.
 *
 * ⚠️ PIÈGE PAYÉ, ET IL EST INSTRUCTIF. La première version faisait
 * `config(['app.env' => …])`. Ça ne marche pas : `Application::environment()` ne lit PAS
 * `config('app.env')`, il lit la liaison **`env` du conteneur**, posée une fois à l'amorçage.
 * La garde changeait donc une valeur que le provider ne consultait jamais, et rougissait sur
 * un correctif qui fonctionnait. C'est le témoin qui l'a révélé : trois cas rouges et le cas
 * « valeur ambiguë » vert, alors qu'ils dépendent du même code — incohérence impossible si la
 * mesure était bonne.
 *
 * ⚠️ SECOND PIÈGE, ET IL TOUCHE AU CŒUR DU CONSTAT. `phpunit.xml` impose
 * `MOCK_MODE=true` à toute la suite. Passer `[]` ne simule donc PAS l'absence de variable —
 * ça simule « la valeur que le banc a posée ». Or l'absence est précisément ce que le constat
 * décrit : une variable perdue par un redéploiement.
 *
 * Une valeur `null` RETIRE donc la variable des trois canaux. Sans cela, la garde aurait
 * affirmé mesurer l'absence tout en mesurant une présence — le défaut même qu'elle poursuit.
 *
 * @param  array<string, string|null>  $variables
 */
function classeResolue(string $environnement, string $contrat, array $variables = []): string
{
    $app = app();
    $anciennes = [];

    foreach ($variables as $cle => $valeur) {
        $anciennes[$cle] = $_SERVER[$cle] ?? null;
        if ($valeur === null) {
            unset($_SERVER[$cle], $_ENV[$cle]);
            putenv($cle);
            continue;
        }
        $_SERVER[$cle] = $valeur;
        $_ENV[$cle] = $valeur;
        putenv("{$cle}={$valeur}");
    }

    $ancienEnv = $app['env'];
    $app->instance('env', $environnement);
    config(['app.env' => $environnement]);

    (new App\Providers\MockServicesProvider($app))->register();
    $classe = get_class($app->make($contrat));

    $app->instance('env', $ancienEnv);
    config(['app.env' => $ancienEnv]);
    foreach ($anciennes as $cle => $valeur) {
        if ($valeur === null) {
            unset($_SERVER[$cle], $_ENV[$cle]);
            putenv($cle);
        } else {
            $_SERVER[$cle] = $valeur;
            $_ENV[$cle] = $valeur;
            putenv("{$cle}={$valeur}");
        }
    }

    return $classe;
}

test('C18-016 — TEMOIN : en TEST, les simulacres se branchent bien', function () {
    // Sans ce cas, un correctif qui interdirait les simulacres PARTOUT passerait les
    // assertions suivantes -- et ferait tomber toute la suite du depot. C'est le temoin
    // qui distingue « refuse en production » de « casse pour tout le monde ».
    expect(classeResolue('testing', LLMClient::class, ['MOCK_MODE' => 'true']))
        ->toBe(MockLLMClient::class);
    expect(classeResolue('testing', InseeClient::class, ['MOCK_MODE' => 'true']))
        ->toBe(MockInseeClient::class);
});

test('C18-016 — en PRODUCTION, un simulacre explicitement demande est REFUSE', function () {
    $classe = classeResolue('production', LLMClient::class, [
        'MOCK_MODE' => 'true',
        'MOCK_LLM' => 'true',
    ]);

    expect($classe)->toBe(
        LLMRouterService::class,
        "Le conteneur resout {$classe} en production alors que le service reel est "
        . 'LLMRouterService. `MockLLMClient` ecrit des classifications FABRIQUEES en base, sur '
        . "des fiches de personnes reelles. Il n'existe aucune raison legitime de servir des "
        . 'donnees inventees a des utilisateurs reels : le refus ne doit pas etre contournable '
        . 'par une variable, sinon on reconstruit le defaut qu on repare.'
    );
});

test('C18-016 — en PRODUCTION sans AUCUNE variable, le defaut est le service REEL', function () {
    // C'est le coeur du constat : la variable ABSENTE. Elle l'est des qu'un
    // `docker compose restart` a remplace un `up -d` (constat A07-003), ou qu'un
    // redeploiement a perdu une ligne d'`env_file`.
    foreach ([
        [LLMClient::class, LLMRouterService::class],
        [InseeClient::class, HttpInseeClient::class],
        [BanGeocoder::class, HttpBanGeocoder::class],
    ] as [$contrat, $attendu]) {
        $classe = classeResolue('production', $contrat, [
            'MOCK_MODE' => null, 'MOCK_LLM' => null, 'MOCK_INSEE' => null, 'MOCK_BAN' => null,
        ]);

        expect($classe)->toBe(
            $attendu,
            "Sans aucune variable, le conteneur resout {$classe} au lieu de {$attendu}. "
            . "L'ancien code faisait `env('MOCK_MODE', true)` : le DEFAUT etait le simulacre. "
            . 'Une variable absente suffisait a mettre la production sur des donnees fabriquees.'
        );
    }
});

test('C18-016 — en PREPRODUCTION aussi, le defaut est le service REEL', function () {
    // La preproduction est une repetition de la production : elle doit se comporter
    // comme elle. Un simulacre y masquerait exactement les pannes qu'on veut y decouvrir.
    expect(classeResolue('staging', InseeClient::class, ['MOCK_MODE' => null, 'MOCK_INSEE' => null]))
        ->toBe(HttpInseeClient::class);
});

test('C18-016 — une valeur AMBIGUE ne rebranche pas un simulacre en production', function () {
    // `(bool) "false"` vaut TRUE en PHP, comme `(bool) "off"` et `(bool) "0.0"`. L'ancien code
    // employait `(bool)` : un operateur qui posait `MOCK_LLM=off` en croyant desactiver le
    // simulacre l'ACTIVAIT. Le validateur booleen, lui, reconnait ces formes.
    foreach (['off', 'false', '0', 'no', ''] as $valeur) {
        expect(classeResolue('testing', LLMClient::class, ['MOCK_MODE' => 'true', 'MOCK_LLM' => $valeur]))
            ->toBe(
                LLMRouterService::class,
                "MOCK_LLM=« {$valeur} » devrait DESACTIVER le simulacre. Avec un cast `(bool)`, "
                . 'cette valeur l activait.'
            );
    }
});
