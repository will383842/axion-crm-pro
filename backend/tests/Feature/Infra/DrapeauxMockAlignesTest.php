<?php

/**
 * GARDE : LA SUITE LOCALE ET LA CI MESURENT LES MEMES SIMULACRES
 * — constat H45-005 (S2).
 *
 * CE QUI ETAIT MESURE, LE 2026-08-22.
 *
 * `.github/workflows/ci.yml` pose DIX drapeaux `MOCK_*` sur le pas Pest
 * (MOCK_MODE, MOCK_LLM, MOCK_PROXIES, MOCK_SCRAPERS, MOCK_SMTP, MOCK_INSEE,
 * MOCK_ANNUAIRE_ENTREPRISES, MOCK_BODACC, MOCK_BAN, MOCK_FRANCE_TRAVAIL).
 * `phpunit.xml` et `phpunit-ci.xml` n en declaraient que DEUX. **Huit
 * manquaient**, et le sens du manque n est pas anodin : `.env.example` pose
 * MOCK_INSEE, MOCK_ANNUAIRE_ENTREPRISES, MOCK_BODACC, MOCK_BAN et
 * MOCK_FRANCE_TRAVAIL a `false`. En local, ces cinq services partaient donc en
 * HTTP REEL pendant la suite, la ou la CI les simulait.
 *
 * Le precedent est ecrit dans `phpunit.xml` : MOCK_INSEE avait deja ete
 * rapatrie le 2026-08-20 apres que `POST /api/v1/coverage/launch` a rougi en
 * 500 en local et verdi en CI. Un vert qui depend d une variable absente du
 * fichier de configuration est un vert de CI, pas un vert de la suite.
 *
 * POURQUOI LA GARDE EXIGE AUSSI `force="true"`. Sans `force`, un `<env>` de
 * PHPUnit n ecrase PAS une variable deja presente dans l environnement du
 * PROCESSUS — et `docker-compose.yml` en pose une par drapeau via
 * `env_file: .env`. Dans le conteneur `api` (c est ce que documente
 * `make test-backend`), un drapeau sans `force` reste donc decoratif : c est
 * exactement le piege deja paye sur `DB_DATABASE`, ou la suite visait la base
 * de DEVELOPPEMENT.
 *
 * CE QUE CETTE GARDE N AUTORISE PAS A CONCLURE. Elle ne prouve pas qu aucun
 * appel reseau ne part : elle prouve que les deux bancs declarent les memes
 * bascules. L absence de simulacre EN PRODUCTION est gardee ailleurs
 * (`AucunSimulacreEnProductionTest`, C18-016/F37-002).
 *
 * ⚠️ PIEGE DE BANC, deja documente par `DeploiementsDependentDeLaCiTest` : dans
 * le conteneur de tests, `/var/www/.github` est une COPIE, pas un montage.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotH45(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/** Rend une valeur YAML/XML sous la forme textuelle « true » / « false ». */
function valeurTexteH45(mixed $valeur): string
{
    return is_bool($valeur) ? ($valeur ? 'true' : 'false') : (string) $valeur;
}

/**
 * Les drapeaux MOCK_* poses par un workflow, avec leur valeur.
 *
 * On ramasse l `env:` des jobs ET celui des pas : un drapeau pose a l un ou
 * l autre niveau agit pareil sur le processus PHP.
 *
 * @param  array<string, mixed>  $workflow
 * @return array<string, string>
 */
function drapeauxMockDuWorkflowH45(array $workflow): array
{
    $trouves = [];

    $ramasser = static function (mixed $env) use (&$trouves): void {
        if (! is_array($env)) {
            return;
        }
        foreach ($env as $cle => $valeur) {
            if (str_starts_with((string) $cle, 'MOCK_')) {
                $trouves[(string) $cle] = valeurTexteH45($valeur);
            }
        }
    };

    /** @var array<string, mixed> $jobs */
    $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

    foreach ($jobs as $job) {
        if (! is_array($job)) {
            continue;
        }
        $ramasser($job['env'] ?? null);

        $pas = is_array($job['steps'] ?? null) ? $job['steps'] : [];
        foreach ($pas as $etape) {
            $ramasser(is_array($etape) ? ($etape['env'] ?? null) : null);
        }
    }

    ksort($trouves);

    return $trouves;
}

/**
 * Les `<env>` d un fichier PHPUnit : nom => ['valeur' => …, 'force' => bool].
 *
 * @return array<string, array{valeur: string, force: bool}>
 */
function envDuFichierPhpunitH45(string $chemin): array
{
    $xml = simplexml_load_file($chemin);
    if ($xml === false) {
        return [];
    }

    $trouves = [];
    foreach ($xml->xpath('//php/env') ?: [] as $noeud) {
        $nom = (string) $noeud['name'];
        $trouves[$nom] = [
            'valeur' => (string) $noeud['value'],
            'force' => filter_var((string) $noeud['force'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    return $trouves;
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOIN — l analyse voit-elle le manque, et le voit-elle a tous les niveaux ?
// ─────────────────────────────────────────────────────────────────────────────

test('H45-005 — TEMOIN : les drapeaux sont vus sur un job comme sur un pas, et rien d autre ne l est', function () {
    $workflow = Yaml::parse(<<<'YAML'
    jobs:
      un:
        env:
          MOCK_MODE: 'true'
          DB_HOST: 127.0.0.1
        steps:
          - name: Pest
            env:
              MOCK_BODACC: 'true'
              CACHE_STORE: array
      deux:
        steps:
          - name: autre
            env:
              MOCK_BAN: 'false'
    YAML);

    expect(drapeauxMockDuWorkflowH45((array) $workflow))->toBe([
        'MOCK_BAN' => 'false',
        'MOCK_BODACC' => 'true',
        'MOCK_MODE' => 'true',
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// LA GARDE
// ─────────────────────────────────────────────────────────────────────────────

test('H45-005 — tout drapeau MOCK_* de la CI est epingle, avec la meme valeur et force, dans les deux fichiers PHPUnit', function () {
    $racine = racineDepotH45();
    $cheminCi = $racine . '/.github/workflows/ci.yml';

    $this->assertFileExists($cheminCi, 'ci.yml introuvable : la garde H45-005 n aurait rien a comparer. Racine lue : ' . $racine);

    $attendus = drapeauxMockDuWorkflowH45((array) Yaml::parseFile($cheminCi));

    // TEMOIN DE NON-VACUITE. Si la CI ne posait plus AUCUN drapeau, la
    // comparaison serait vide et la garde verte sans avoir rien compare — le
    // pire des verts. La mesure du 2026-08-22 en denombrait dix ; on exige au
    // moins le maitre, faute de quoi c est le fichier lu qui est en cause.
    $this->assertArrayHasKey('MOCK_MODE', $attendus, sprintf(
        'Aucun drapeau MOCK_MODE dans %s : la garde H45-005 n a rien compare. Dans le '
        . 'conteneur de tests, .github est une COPIE qui peut etre perimee — verifier '
        . 'l empreinte du fichier lu avant de croire ce resultat.',
        $cheminCi,
    ));

    $ecarts = [];

    foreach (['phpunit.xml', 'phpunit-ci.xml'] as $fichier) {
        $chemin = base_path($fichier);
        $this->assertFileExists($chemin, sprintf('%s introuvable : la garde H45-005 ne peut pas le comparer a la CI.', $fichier));

        $declares = envDuFichierPhpunitH45($chemin);

        foreach ($attendus as $nom => $valeur) {
            if (! array_key_exists($nom, $declares)) {
                $ecarts[] = sprintf('%s : %s ABSENT (la CI le pose a « %s »)', $fichier, $nom, $valeur);

                continue;
            }
            if ($declares[$nom]['valeur'] !== $valeur) {
                $ecarts[] = sprintf(
                    '%s : %s vaut « %s » alors que la CI le pose a « %s »',
                    $fichier,
                    $nom,
                    $declares[$nom]['valeur'],
                    $valeur,
                );
            }
            if (! $declares[$nom]['force']) {
                $ecarts[] = sprintf('%s : %s sans force="true" — inoperant dans le conteneur, ou env_file pose deja la variable', $fichier, $nom);
            }
        }
    }

    $this->assertSame([], $ecarts, sprintf(
        "La suite locale et la CI ne declarent pas les memes simulacres :\n  - %s\n\n"
        . 'Un drapeau qui ne vit que dans ci.yml donne un vert de CI, pas un vert de la suite '
        . '(constat H45-005). Le cas deja paye le 2026-08-20 : MOCK_INSEE manquait ici, '
        . '`POST /api/v1/coverage/launch` rougissait en 500 en local et verdissait en CI — la '
        . 'file etant en `sync`, le job partait en ligne et atteignait HttpInseeClient::token(). '
        . 'Pire dans l autre sens : `.env.example` pose cinq de ces drapeaux a `false`, donc la '
        . "suite locale appelait pour de vrai des services que la CI simulait.\n\n"
        . 'GESTE : recopier la ligne manquante dans backend/phpunit.xml ET '
        . "backend/phpunit-ci.xml, avec la MEME valeur que la CI et `force=\"true\"` :\n"
        . "  <env name=\"MOCK_XXX\" value=\"true\" force=\"true\"/>\n\n"
        . '`force` n est pas decoratif : sans lui, la variable posee par `env_file: .env` de '
        . 'docker-compose.yml gagne, et le drapeau ne fait rien dans le conteneur.',
        implode("\n  - ", $ecarts),
    ));
});
