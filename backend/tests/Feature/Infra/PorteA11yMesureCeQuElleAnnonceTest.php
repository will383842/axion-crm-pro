<?php

/**
 * GARDE : la porte accessibilite mesure-t-elle vraiment ce qu'elle annonce ?
 *
 * Elle ferme deux constats de l'audit 360, tous deux sur `.github/workflows/a11y.yml`.
 *
 * ── F38-014 / H44-008 (S3) — LE REPLI QUI AVALAIT SA PROPRE PORTE ───────────
 *
 * L'etape d'installation lisait, mesure du 2026-08-22 avant correctif :
 *
 *     run: pnpm install --frozen-lockfile || pnpm install
 *
 * Le `|| pnpm install` avale EXACTEMENT l'echec que `--frozen-lockfile` sert a
 * produire. Des que `frontend/pnpm-lock.yaml` diverge de `package.json`, la
 * porte reinstalle un arbre de dependances resolu a la volee — et c'est cet
 * arbre-la, que rien d'autre ne valide, qu'axe-core et Playwright mesurent
 * ensuite. Le job reste vert, et il ment sur ce qu'il a mesure.
 *
 * Retirer le repli etait sans risque, et voici la mesure qui le dit : le meme
 * repertoire `frontend/` est deja installe en `--frozen-lockfile` SEUL et
 * BLOQUANT par le job `frontend` de `ci.yml` (etape « Install (BLOQUANT —
 * lockfile fige) »). Un lock desynchronise faisait donc DEJA rougir la CI ; le
 * repli d'`a11y.yml` ne rattrapait aucun cas reel, il ne faisait que masquer
 * lequel des deux arbres etait sous les yeux d'axe.
 *
 * La garde est volontairement posee sur TOUS les workflows, pas seulement sur
 * `a11y.yml` : le defaut est un geste, pas un fichier, et il se recopie.
 *
 * ── F38-006 (S2) — L'ETAPE MUETTE, NOMMEE PLUTOT QUE SUBIE ──────────────────
 *
 * Le job `lighthouse` porte `continue-on-error: true` sur son unique etape de
 * mesure : il rend `success` quoi que fasse `lhci`, et aucune PR ne peut
 * rougir. Aucun `.lighthouserc*` n'existe par ailleurs dans le depot, donc
 * `lhci` n'a aucune assertion chiffree a verifier.
 *
 * ⚠️ CE CONSTAT N'EST PAS FERME PAR CETTE GARDE, ET ELLE NE PRETEND PAS LE
 * FERMER. Retirer le filet exige d'abord de verifier que
 * `https://staging.axion-crm-pro.com` est reellement servi — une verification
 * reseau + une decision de seuils qui appartiennent a un humain. Ce que la
 * garde fait, c'est empecher que la liste des etapes muettes s'allonge en
 * silence : toute NOUVELLE etouffee dans `a11y.yml` la fera rougir, et la
 * seule exception connue y est nommee et datee.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotA11yG3(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les workflows du depot, nommes par leur fichier.
 *
 * `glob` et non `scandir` recursif : `.github/workflows/` est plat par
 * construction chez GitHub, il n'y a pas de sous-repertoire a manquer.
 *
 * @return array<string,string> nom de fichier => chemin absolu
 */
function workflowsDuDepotA11yG3(): array
{
    $repertoire = racineDepotA11yG3() . '/.github/workflows';
    $fichiers = glob($repertoire . '/*.yml') ?: [];

    $carte = [];
    foreach ($fichiers as $chemin) {
        $carte[basename($chemin)] = $chemin;
    }
    ksort($carte);

    return $carte;
}

/**
 * Les etapes d'un workflow, aplaties, avec de quoi les designer dans un
 * message d'echec.
 *
 * @return list<array{job:string,index:int,nom:string,etape:array<mixed>}>
 */
function etapesDuWorkflowA11yG3(array $workflow): array
{
    $etapes = [];
    foreach ($workflow['jobs'] ?? [] as $idJob => $job) {
        if (! is_array($job)) {
            continue;
        }
        foreach ($job['steps'] ?? [] as $index => $etape) {
            if (! is_array($etape)) {
                continue;
            }
            $nom = $etape['name']
                ?? $etape['uses']
                ?? trim(strtok((string) ($etape['run'] ?? '(etape sans nom)'), "\n") ?: '');

            $etapes[] = [
                'job' => (string) $idJob,
                'index' => (int) $index,
                'nom' => (string) $nom,
                'etape' => $etape,
            ];
        }
    }

    return $etapes;
}

test('F38-014 / H44-008 : aucune installation pnpm ne se rattrape derriere un repli', function () {
    $workflows = workflowsDuDepotA11yG3();

    // Sans cette borne, un `.github` absent (c'est le cas dans certains
    // conteneurs de test) rendrait la garde verte sur ZERO fichier lu.
    expect(count($workflows) >= 10)->toBeTrue(
        'Seulement ' . count($workflows) . ' workflow(s) lu(s) dans '
        . racineDepotA11yG3() . '/.github/workflows — cette garde ne mesure alors rien. '
        . 'Geste : verifier que le depot est bien monte/copie a cet endroit avant de croire un vert.',
    );
    expect(array_key_exists('a11y.yml', $workflows))->toBeTrue(
        '`a11y.yml` introuvable dans les workflows lus. Geste : si le fichier a ete renomme, '
        . 'renommer aussi la reference ici et dans le commentaire F38-006 du workflow.',
    );

    $fautives = [];
    $installationsVues = 0;

    foreach ($workflows as $nomFichier => $chemin) {
        $workflow = Yaml::parseFile($chemin);
        if (! is_array($workflow)) {
            continue;
        }

        foreach (etapesDuWorkflowA11yG3($workflow) as $etape) {
            $script = (string) ($etape['etape']['run'] ?? '');
            if ($script === '') {
                continue;
            }

            foreach (preg_split('/\r?\n/', $script) ?: [] as $ligne) {
                $ligne = trim($ligne);
                // On ne regarde que l'installation des dependances du projet ;
                // `npm install -g <outil>` n'a pas de lockfile a figer.
                if (! str_contains($ligne, 'pnpm install')) {
                    continue;
                }
                $installationsVues++;

                $sansFilet = ! str_contains($ligne, '||') && ! str_contains($ligne, ' ; ');
                $fige = str_contains($ligne, '--frozen-lockfile');

                if (! $sansFilet || ! $fige) {
                    $fautives[] = $nomFichier . ' → job `' . $etape['job'] . '`, etape `'
                        . $etape['nom'] . '` : ' . $ligne;
                }
            }
        }
    }

    // Une garde qui n'a croise aucune installation certifierait ce qu'elle n'a
    // pas inspecte. Mesure du 2026-08-22 : cinq `pnpm install` dans l'arbre
    // (a11y, ci x2, e2e, release-tracking).
    expect($installationsVues >= 5)->toBeTrue(
        'Seulement ' . $installationsVues . ' ligne(s) `pnpm install` trouvee(s) dans les workflows, '
        . 'contre 5 a la mesure du 2026-08-22. Geste : si des jobs ont ete supprimes c\'est normal — '
        . 'abaisser ce plancher EN CITANT les jobs retires ; sinon, le parcours des etapes ne lit plus '
        . 'ce qu\'il croit lire.',
    );

    expect($fautives === [])->toBeTrue(
        "Une installation de dependances est rattrapee par un repli, ou n'est pas figee :\n  - "
        . implode("\n  - ", $fautives)
        . "\nUn `|| pnpm install` avale l'echec que `--frozen-lockfile` sert a produire : le job "
        . 'mesure alors un arbre de dependances resolu a la volee que rien ne valide (F38-014 / H44-008). '
        . 'Geste : ecrire `pnpm install --frozen-lockfile` seul, et remettre le lock a niveau '
        . '(`pnpm install --lockfile-only`) plutot que de reintroduire le filet.',
    );
});

test('F38-006 : les etapes muettes de a11y.yml sont nommees une par une', function () {
    $chemin = racineDepotA11yG3() . '/.github/workflows/a11y.yml';
    expect(is_file($chemin))->toBeTrue(
        "`a11y.yml` introuvable a {$chemin} — la garde ne mesurerait rien. "
        . 'Geste : verifier le montage du depot avant de croire un vert.',
    );

    $workflow = Yaml::parseFile($chemin);

    /**
     * LA seule etouffee connue, au 2026-08-22, et pourquoi elle est toleree :
     * le job `lighthouse` vise `https://staging.axion-crm-pro.com`, cible dont
     * rien ne prouve ici qu'elle est servie. Retirer le filet avant de l'avoir
     * verifie bloquerait toutes les PR (F38-006). Quand la cible sera verifiee
     * et un `.lighthouserc.json` pose, retirer la ligne du workflow ET cette
     * entree — la garde exige alors qu'il n'en reste aucune.
     */
    $exceptionsConnues = [
        'lighthouse → lhci autorun --upload.target=temporary-public-storage --collect.url=https://staging.axion-crm-pro.com',
    ];

    $muettes = [];
    foreach (etapesDuWorkflowA11yG3($workflow) as $etape) {
        if (($etape['etape']['continue-on-error'] ?? false) === true) {
            $muettes[] = $etape['job'] . ' → ' . $etape['nom'];
        }
    }
    // Un job entier peut aussi etre etouffe, pas seulement une etape.
    foreach ($workflow['jobs'] ?? [] as $idJob => $job) {
        if (is_array($job) && ($job['continue-on-error'] ?? false) === true) {
            $muettes[] = $idJob . ' → (job entier)';
        }
    }

    sort($muettes);
    sort($exceptionsConnues);

    $nouvelles = array_values(array_diff($muettes, $exceptionsConnues));
    $disparues = array_values(array_diff($exceptionsConnues, $muettes));

    expect($nouvelles === [])->toBeTrue(
        "Nouvelle(s) etape(s) de `a11y.yml` qui ne peuvent plus faire rougir une PR :\n  - "
        . implode("\n  - ", $nouvelles)
        . "\nUne porte en `continue-on-error: true` rend `success` quel que soit son code de sortie : "
        . "elle affiche une mesure sans jamais l'imposer (c'est le defaut F38-006). "
        . 'Geste : retirer le `continue-on-error`, ou — si la cible mesuree n\'est pas encore fiable — '
        . 'inscrire l\'etape dans $exceptionsConnues ci-dessus AVEC la date et la raison, '
        . 'et poser dans le workflow le commentaire qui dit comment la lever.',
    );

    expect($disparues === [])->toBeTrue(
        "Exception(s) inscrite(s) ici mais absente(s) du workflow :\n  - "
        . implode("\n  - ", $disparues)
        . "\nC'est une bonne nouvelle si le filet a ete retire : geste, supprimer la ligne "
        . 'correspondante de $exceptionsConnues pour que la garde exige desormais le silence zero. '
        . 'Sinon, l\'etape a ete renommee et la garde ne surveille plus rien.',
    );
});

/**
 * CI-F1 (#680) : UN SEUL build du frontend pour axe et e2e.
 *
 * `a11y.yml` construit une fois, publie `frontend-dist` avec une empreinte,
 * appelle `e2e.yml` (workflow_call). Axe et e2e recalculent l'empreinte et
 * échouent si le build est absent ou différent : jamais « vert sans mesure ».
 */
function etapesDuJobCiF1(string $fichier, string $job): array
{
    $wf = Yaml::parseFile(racineDepotA11yG3() . '/.github/workflows/' . $fichier);

    return $wf['jobs'][$job]['steps'] ?? [];
}

const EMPREINTE_CI_F1 = 'find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum';

test('CI-F1 : le build est produit UNE fois, par a11y.yml, avec une empreinte', function () {
    $a11y = Yaml::parseFile(racineDepotA11yG3() . '/.github/workflows/a11y.yml');
    $e2e = Yaml::parseFile(racineDepotA11yG3() . '/.github/workflows/e2e.yml');

    $builds = 0;
    foreach ([$a11y, $e2e] as $wf) {
        foreach (etapesDuWorkflowA11yG3($wf) as $e) {
            if (preg_match('/\bpnpm build\b/', (string) ($e['etape']['run'] ?? ''))) {
                $builds++;
                expect($e['job'])->toBe('build-frontend');
            }
        }
    }
    expect($builds)->toBe(1);

    $job = $a11y['jobs']['build-frontend'];
    expect($job['outputs']['empreinte'] ?? '')->toContain('steps.empreinte.outputs.empreinte');
    expect(collect($job['steps'])->pluck('run')->filter()->implode("\n"))->toContain(EMPREINTE_CI_F1);
    $upload = collect($job['steps'])->firstWhere('uses', 'actions/upload-artifact@v4');
    expect($upload['with']['name'])->toBe('frontend-dist')
        ->and($upload['with']['if-no-files-found'])->toBe('error')
        ->and($upload['with']['include-hidden-files'])->toBeTrue();
});

test('CI-F1 : la porte a11y mesure le build partagé (même empreinte), e2e aussi', function () {
    $a11y = Yaml::parseFile(racineDepotA11yG3() . '/.github/workflows/a11y.yml');
    expect($a11y['jobs']['axe-playwright']['needs'])->toBe('build-frontend');
    expect($a11y['jobs']['e2e']['uses'])->toBe('./.github/workflows/e2e.yml');
    expect($a11y['jobs']['e2e']['needs'])->toBe('build-frontend');
    expect($a11y['jobs']['e2e']['with']['empreinte'])->toContain('needs.build-frontend.outputs.empreinte');
    expect($a11y['jobs']['e2e']['permissions'])->toBe(['contents' => 'read']);

    foreach ([['a11y.yml', 'axe-playwright'], ['e2e.yml', 'suites-cablees']] as [$fichier, $job]) {
        $etapes = etapesDuJobCiF1($fichier, $job);
        $usesTous = collect($etapes)->pluck('uses')->filter()->all();
        expect($usesTous)->toContain('actions/download-artifact@v4');
        $dl = collect($etapes)->firstWhere('uses', 'actions/download-artifact@v4');
        expect($dl['with']['name'])->toBe('frontend-dist')->and($dl['with']['path'])->toBe('frontend/dist');
        $attendue = collect($etapes)->pluck('env.EMPREINTE_ATTENDUE')->filter()->implode('');
        expect($attendue)->toMatch('/needs\.build-frontend\.outputs\.empreinte|inputs\.empreinte/');
    }

    $e2e = Yaml::parseFile(racineDepotA11yG3() . '/.github/workflows/e2e.yml');
    $declencheurs = $e2e[true] ?? $e2e['on'];
    expect(array_keys($declencheurs))->toBe(['workflow_call']);
    expect($declencheurs['workflow_call']['inputs']['empreinte']['required'])->toBeTrue();
});

test('CI-F1 : un build absent ou différent fait ÉCHOUER la porte, avant toute mesure', function () {
    foreach ([['a11y.yml', 'axe-playwright', 'Run a11y E2E'], ['e2e.yml', 'suites-cablees', 'Suites e2e cablees (BLOQUANT)']] as [$fichier, $job, $mesure]) {
        $etapes = etapesDuJobCiF1($fichier, $job);
        $noms = array_map(fn ($e) => $e['name'] ?? '', $etapes);
        $iVerif = array_search('Vérifier le build unique (absent ou différent ⇒ ÉCHEC)', $noms, true);
        $iMesure = array_search($mesure, $noms, true);
        expect($iVerif)->not->toBeFalse($fichier)->and($iMesure)->not->toBeFalse($fichier);
        expect($iVerif < $iMesure)->toBeTrue("$fichier : la vérification doit précéder la mesure");

        $script = (string) $etapes[$iVerif]['run'];
        expect($script)->toContain('set -euo pipefail')
            ->toContain('frontend/dist/index.html')
            ->toContain(EMPREINTE_CI_F1)
            ->toContain('test -n "$EMPREINTE_ATTENDUE"')
            ->toContain('test "$calculee" = "$EMPREINTE_ATTENDUE"');
        expect(substr_count($script, 'exit 1'))->toBeGreaterThanOrEqual(3);
        expect($etapes[$iVerif])->not->toHaveKey('continue-on-error');
    }
});
