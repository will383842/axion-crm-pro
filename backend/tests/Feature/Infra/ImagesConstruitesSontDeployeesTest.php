<?php

/**
 * GARDE : ON NE CONSTRUIT PAS UNE IMAGE QUE LA PILE N'EXECUTE PAS — constat H47-005 (S2).
 *
 * CE QUI ETAIT MESURE, ET C'EST UNE MESURE, PAS UNE OPINION.
 *
 * `.github/workflows/deploy-staging.yml` portait, dans la matrice de son job
 * `build-and-push`, une entree
 * `{ image: worker, dockerfile: Dockerfile.worker, target: prod }`. Elle
 * construisait et poussait `ghcr.io/.../worker:staging` a CHAQUE deploiement de
 * preproduction.
 *
 * En face, RIEN. Les workers Playwright ont ete retires de la pile le
 * 2026-08-14 : `docker-compose.yml` n'en garde qu'un commentaire (juste avant
 * le service `caddy`), aucun fichier compose ne declare de service `worker`, et
 * l'etape de deploiement nomme ses services un par un
 * (`docker compose up -d ... api app horizon scheduler`). L'image partait donc
 * au registre a chaque fois, sans jamais etre tiree — et l'analyse d'image lui
 * imputait 32 des 57 alertes, dont les 2 critiques.
 *
 * CE QUE CETTE GARDE MESURE.
 *
 * Toute image construite par un workflow de deploiement doit etre EXECUTABLE
 * par la pile que ce meme workflow deploie : le `dockerfile` de chaque entree
 * de matrice doit etre reference par le `build.dockerfile` d'au moins un
 * service declare dans les fichiers compose du deploiement. C'est mecanique :
 * la garde ne connait aucun nom d'image par avance, elle CONFRONTE les deux
 * cotes. Rajouter demain une image sans service la ferait rougir.
 *
 * CE QU'ELLE NE MESURE PAS. Elle ne dit rien de l'INVERSE (un service dont
 * l'image n'est pas construite), ni du contenu des images, ni des alertes de
 * l'analyse de vulnerabilites — c'est le travail de `security.yml`, qui
 * continue par ailleurs de scanner `Dockerfile.worker` puisque le CODE, lui,
 * est conserve.
 *
 * ⚠️ PIEGE DE BANC, DEJA PAYE DANS CE DEPOT (voir l'en-tete de
 * `DeploiementsDependentDeLaCiTest`) : dans le conteneur de tests, `.github` et
 * les `docker-compose*.yml` peuvent etre une COPIE et non un montage. Un vert
 * obtenu en local ne prouve rien tant qu'on n'a pas compare l'empreinte du
 * fichier lu a celle de l'hote. En CI (`actions/checkout`), la garde lit le
 * vrai arbre.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotH47(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les workflows qui CONSTRUISENT pour deployer, avec la pile qu'ils deploient.
 *
 * Nommes un par un plutot que ramasses au `glob` : un `glob` ferait rougir
 * demain un workflow de diagnostic ajoute par quelqu'un d'autre, et la pente
 * serait alors d'assouplir la garde.
 *
 * @return array<string, list<string>> workflow => fichiers compose du deploiement
 */
function pilesDeDeploiementH47(): array
{
    return [
        // `COMPOSE_FILE="docker-compose.yml:docker-compose.staging.yml"`, tel
        // qu'exporte par l'etape « Deploiement de la preproduction ».
        '.github/workflows/deploy-staging.yml' => [
            'docker-compose.yml',
            'docker-compose.staging.yml',
        ],
    ];
}

/**
 * Les `dockerfile` construits par les matrices d'un workflow.
 *
 * @return list<string>
 */
function dockerfilesConstruitsH47(string $cheminWorkflow): array
{
    /** @var array<string, mixed> $workflow */
    $workflow = (array) Yaml::parse((string) file_get_contents($cheminWorkflow));
    /** @var array<string, mixed> $jobs */
    $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

    $trouves = [];
    foreach ($jobs as $job) {
        $inclus = is_array($job) ? ($job['strategy']['matrix']['include'] ?? null) : null;
        if (! is_array($inclus)) {
            continue;
        }
        foreach ($inclus as $entree) {
            $fichier = is_array($entree) ? ($entree['dockerfile'] ?? null) : null;
            if (is_string($fichier) && $fichier !== '') {
                $trouves[] = $fichier;
            }
        }
    }

    return array_values(array_unique($trouves));
}

/**
 * Les `build.dockerfile` des services declares par une pile compose.
 *
 * @param  list<string>  $fichiersCompose
 * @return list<string>
 */
function dockerfilesExecutesH47(array $fichiersCompose): array
{
    $trouves = [];

    foreach ($fichiersCompose as $relatif) {
        $chemin = racineDepotH47().'/'.$relatif;
        if (! is_file($chemin)) {
            continue;
        }

        // `!override` (utilise par l'overlay de preproduction) n'est pas une
        // balise YAML connue de Symfony : sans ce nettoyage, le fichier ne se
        // parse pas du tout et la garde certifierait le vide.
        $texte = str_replace('!override', '', (string) file_get_contents($chemin));

        try {
            /** @var array<string, mixed> $pile */
            $pile = (array) Yaml::parse($texte);
        } catch (\Throwable $e) {
            // Une exception de parsing sortirait en ERREUR, sans dire quoi faire.
            // On la transforme en echec qui porte le geste.
            expect(false)->toBeTrue(
                "H47-005 : {$relatif} n'a pas pu etre lu en YAML (".$e->getMessage().'). '.
                "La garde ne mesure RIEN dans cet etat. GESTE : si le fichier a gagne une balise ".
                'Compose non standard (comme `!override`), la neutraliser dans '.
                'dockerfilesExecutesH47() avant le parsing.'
            );

            continue;
        }

        /** @var array<string, mixed> $services */
        $services = is_array($pile['services'] ?? null) ? $pile['services'] : [];

        foreach ($services as $service) {
            $fichier = is_array($service) ? ($service['build']['dockerfile'] ?? null) : null;
            if (is_string($fichier) && $fichier !== '') {
                $trouves[] = $fichier;
            }
        }
    }

    return array_values(array_unique($trouves));
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOIN — l'analyse sait-elle seulement voir le defaut ?
// ─────────────────────────────────────────────────────────────────────────────

test('H47-005 — TEMOIN : l analyse lit les matrices et les services, et sait les confronter', function () {
    $repertoire = sys_get_temp_dir().'/h47-'.bin2hex(random_bytes(6));
    mkdir($repertoire);

    $workflow = $repertoire.'/w.yml';
    file_put_contents($workflow, <<<'YAML'
    jobs:
      build-and-push:
        strategy:
          matrix:
            include:
              - { image: api,    dockerfile: Dockerfile.laravel, target: prod }
              - { image: worker, dockerfile: Dockerfile.worker,  target: prod }
    YAML);

    expect(dockerfilesConstruitsH47($workflow))
        ->toBe(['Dockerfile.laravel', 'Dockerfile.worker']);

    unlink($workflow);
    rmdir($repertoire);
});

// ─────────────────────────────────────────────────────────────────────────────
// LA MESURE
// ─────────────────────────────────────────────────────────────────────────────

test('H47-005 — toute image construite par un deploiement est executee par sa pile', function () {
    foreach (pilesDeDeploiementH47() as $relatifWorkflow => $fichiersCompose) {
        $cheminWorkflow = racineDepotH47().'/'.$relatifWorkflow;

        expect(is_file($cheminWorkflow))->toBeTrue(
            "H47-005 : {$relatifWorkflow} est introuvable depuis le banc. La garde ne mesure ".
            'RIEN dans cet etat. GESTE : verifier que le depot complet est monte (voir le '.
            "piege de banc en tete de ce fichier), ou corriger le chemin dans pilesDeDeploiementH47()."
        );

        $construits = dockerfilesConstruitsH47($cheminWorkflow);
        $executes = dockerfilesExecutesH47($fichiersCompose);

        // Sans ce garde-fou, un parsing casse rendrait deux tableaux vides et
        // le test serait vert en n'ayant rien inspecte.
        expect($construits)->not->toBeEmpty(
            "H47-005 : aucune entree de matrice lue dans {$relatifWorkflow} — l'analyse n'a rien ".
            'inspecte, elle ne prouve donc rien. GESTE : verifier la forme '.
            '`strategy.matrix.include` du job de construction.'
        );
        expect($executes)->not->toBeEmpty(
            'H47-005 : aucun `build.dockerfile` lu dans la pile compose du deploiement — '.
            "l'analyse n'a rien inspecte. GESTE : verifier les chemins listes dans ".
            'pilesDeDeploiementH47() et le parsing des `!override`.'
        );

        $orphelins = array_values(array_diff($construits, $executes));

        expect($orphelins)->toBe([], sprintf(
            'H47-005 : %s construit %s, mais AUCUN service des fichiers %s ne le fait tourner. '.
            'Une image poussee a chaque deploiement et jamais tiree coute du temps de runner, '.
            "occupe le registre et fait remonter des alertes d'analyse sur du code qui ne s'execute ".
            'nulle part — c est exactement le cas mesure le 2026-08-22 avec `Dockerfile.worker` '.
            '(32 des 57 alertes, dont les 2 critiques). GESTE : soit declarer le service dans la '.
            'pile, soit retirer la ligne de la matrice `build-and-push` — les deux vont ENSEMBLE, '.
            'et le commentaire de docker-compose.yml (juste avant le service `caddy`) le dit.',
            $relatifWorkflow,
            implode(', ', $orphelins),
            implode(' + ', $fichiersCompose),
        ));
    }
});
