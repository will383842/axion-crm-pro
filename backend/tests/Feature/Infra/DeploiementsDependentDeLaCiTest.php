<?php

/**
 * GARDE : AUCUN DEPLOIEMENT AUTOMATIQUE SANS LA CI DEVANT — constat H44-003 (S1).
 *
 * CE QUI ETAIT MESURE, ET C'EST UNE MESURE, PAS UNE OPINION.
 *
 * `.github/workflows/deploy-staging.yml` se declenchait sur `push: branches:
 * [main]` et enchainait `build-and-push` -> `deploy-staging` (SSH Hetzner,
 * `git reset --hard`, `docker compose up`, `migrate --force`). Aucun de ses
 * deux jobs ne portait `needs` vers la CI. Un commit qui casse Pest, PHPStan,
 * Pint, le typecheck du frontend ou le build des workers atterrissait donc sur
 * `staging.axion-crm-pro.com` sans qu'un seul test ait ete joue.
 *
 * La PRODUCTION, elle, etait deja cablee : `deploy-direct-ssh.yml` appelle
 * `ci.yml` en workflow reutilisable et son job `deploy` porte `needs: [ci]` +
 * `if: needs.ci.result == 'success'` depuis le 2026-08-13. **Le correctif
 * existait, il n'avait pas ete porte sur le second site.** C'est le defaut
 * A-011, caracteristique de ce depot : l'audit nomme UN site, il y en a deux.
 *
 * CE QUE CETTE GARDE MESURE.
 *
 * Pour chaque workflow qui DEPLOIE et qui se declenche tout seul sur `main` :
 * il doit exister un job qui appelle `./.github/workflows/ci.yml`, et TOUS les
 * autres jobs doivent en dependre, directement ou par transitivite. La
 * transitivite compte : dans `deploy-staging.yml`, seul `build-and-push` porte
 * `needs: [ci]`, et `deploy-staging` est couvert parce qu'il depend de
 * `build-and-push`. Une garde qui exigerait `needs: [ci]` sur chaque job serait
 * fausse.
 *
 * ⚠️ PIEGE DE BANC, MESURE LE 2026-08-20 — ET J'AI D'ABORD ECRIT L'INVERSE.
 *
 * Dans le conteneur de tests `a35r`, `/var/www/.github` n'est PAS un montage :
 * c'est une COPIE interne au conteneur. Trois mesures, dans cet ordre :
 *
 *   1. `docker inspect a35r` ne liste que `backend`, `infra`, `vendor` et les
 *      `docker-compose*.yml`. Pas `.github`.
 *   2. Un fichier cree dans `.github/workflows/` cote hote n'apparait PAS dans
 *      le conteneur (`zz-a35-probe.txt`, essai avec deux secondes d'attente).
 *   3. LA MESURE DECISIVE : un `docker cp` VERS `/var/www/.github/workflows/`
 *      n'a PAS modifie le fichier de l'hote (hote `6d2bcba…`, conteneur
 *      `a8634eb…` au meme instant). Un montage aurait ecrit des deux cotes.
 *
 * ⚠️ Et pourtant, entre (1) et (3), le conteneur a AFFICHE le contenu que je
 * venais d'ecrire sur l'hote. J'ai failli en conclure « c'est un montage » et
 * ecrire ce mensonge ici. C'etait une autre session qui avait recopie le
 * repertoire entre-temps : douze agents partagent ce conteneur, et plusieurs
 * travaillent sur ces memes workflows. **Cette copie peut donc etre a jour, ou
 * ne pas l'etre, sans que rien ne le dise.** Un vert obtenu ici ne prouve rien
 * tant qu'on n'a pas verifie l'empreinte du fichier lu.
 *
 * CONSEQUENCE PRATIQUE. En CI (`actions/checkout`), la garde lit le vrai arbre
 * et mesure ce qu'elle croit mesurer. En LOCAL, avant de croire un resultat :
 *   docker cp .github/workflows/<fichier> a35r:/var/www/.github/workflows/
 *   docker exec a35r sh -c 'md5sum /var/www/.github/workflows/<fichier>'
 * et comparer a l'empreinte de l'hote. `PileDeProductionSansOverlayTest`, qui
 * lit les memes chemins, vit exactement le meme decalage sans le dire.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotH44(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les workflows qui DEPLOIENT et partent tout seuls sur `main`.
 *
 * Nommes un par un plutot que ramasses au `glob` : un `glob` sur
 * `deploy-*.yml` ferait rougir demain un workflow de diagnostic ajoute par
 * quelqu'un d'autre, et la pente serait alors d'assouplir la garde.
 *
 * @return list<string>
 */
function workflowsDeDeploiementH44(): array
{
    return [
        '.github/workflows/deploy-direct-ssh.yml',   // PRODUCTION
        '.github/workflows/deploy-staging.yml',      // PREPRODUCTION (H44-003)
    ];
}

/**
 * Les jobs qui appellent la CI comme workflow reutilisable.
 *
 * @param  array<string, mixed>  $workflow
 * @return list<string>
 */
function appelantsDeLaCiH44(array $workflow): array
{
    $appelants = [];
    /** @var array<string, mixed> $jobs */
    $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

    foreach ($jobs as $nom => $job) {
        $uses = is_array($job) ? ($job['uses'] ?? null) : null;
        if (is_string($uses) && str_contains($uses, '.github/workflows/ci.yml')) {
            $appelants[] = (string) $nom;
        }
    }

    return $appelants;
}

/**
 * Les jobs qui n'ont AUCUN chemin de dependance vers un appel de la CI.
 *
 * Fermeture transitive : un job couvert est soit l'appel de la CI lui-meme,
 * soit un job dont au moins un `needs` est deja couvert.
 *
 * @param  array<string, mixed>  $workflow
 * @return list<string>
 */
function jobsSansGardeCiH44(array $workflow): array
{
    /** @var array<string, mixed> $jobs */
    $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];
    $noms = array_map('strval', array_keys($jobs));

    $couverts = appelantsDeLaCiH44($workflow);

    // Aucun appel de la CI : le workflow ENTIER est nu. C'etait l'etat de
    // `deploy-staging.yml` avant le 2026-08-20.
    if ($couverts === []) {
        return $noms;
    }

    while (true) {
        $avant = count($couverts);

        foreach ($jobs as $nom => $job) {
            $nom = (string) $nom;
            if (in_array($nom, $couverts, true)) {
                continue;
            }
            $needs = is_array($job) ? ($job['needs'] ?? []) : [];
            $needs = is_string($needs) ? [$needs] : (array) $needs;

            foreach ($needs as $dependance) {
                if (in_array((string) $dependance, $couverts, true)) {
                    $couverts[] = $nom;
                    break;
                }
            }
        }

        if (count($couverts) === $avant) {
            break;
        }
    }

    return array_values(array_diff($noms, $couverts));
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOIN — l'analyseur sait-il seulement voir le defaut ?
// ─────────────────────────────────────────────────────────────────────────────

test('H44-003 — TEMOIN : l analyse voit un deploiement nu, et ne crie pas sur un deploiement garde', function () {
    // Exactement la forme de `deploy-staging.yml` AVANT le correctif.
    $nu = Yaml::parse(<<<'YAML'
    jobs:
      build-and-push:
        runs-on: ubuntu-24.04
      deploy-staging:
        needs: build-and-push
        runs-on: ubuntu-24.04
    YAML);

    // Exactement la forme APRES : un seul job porte `needs: [ci]`, l'autre est
    // couvert par TRANSITIVITE. Une garde qui exigerait `needs: [ci]` partout
    // rougirait ici a tort.
    $garde = Yaml::parse(<<<'YAML'
    jobs:
      ci:
        uses: ./.github/workflows/ci.yml
      build-and-push:
        needs: [ci]
        runs-on: ubuntu-24.04
      deploy-staging:
        needs: build-and-push
        runs-on: ubuntu-24.04
    YAML);

    // Et le cas tordu : un job qui appelle un AUTRE workflow reutilisable ne
    // doit pas etre pris pour l'appel de la CI.
    $leurre = Yaml::parse(<<<'YAML'
    jobs:
      faux:
        uses: ./.github/workflows/a11y.yml
      deploy:
        needs: [faux]
        runs-on: ubuntu-24.04
    YAML);

    expect(jobsSansGardeCiH44((array) $nu))->toBe(['build-and-push', 'deploy-staging']);
    expect(jobsSansGardeCiH44((array) $garde))->toBe([]);
    expect(jobsSansGardeCiH44((array) $leurre))->toBe(['faux', 'deploy']);
});

// ─────────────────────────────────────────────────────────────────────────────
// LA GARDE
// ─────────────────────────────────────────────────────────────────────────────

test('H44-003 — tout deploiement automatique depuis main passe par la CI', function () {
    $racine = racineDepotH44();

    // TEMOIN DE NON-VACUITE. Sans lui, un chemin faux ou un repertoire non
    // monte rendrait ZERO fichier a inspecter et la garde passerait au vert
    // sans avoir rien lu -- le pire des verts, et le defaut exact trouve le
    // 19/08 sur les gardes de ce dossier.
    $manquants = [];
    foreach (workflowsDeDeploiementH44() as $relatif) {
        if (! is_file($racine . '/' . $relatif)) {
            $manquants[] = $relatif;
        }
    }
    $this->assertSame([], $manquants, sprintf(
        'Le banc ne voit pas ces workflows : %s. Une garde qui n a rien a '
        . 'inspecter passe au vert sans rien prouver. Racine lue : %s',
        implode(', ', $manquants),
        $racine,
    ));

    // La CI doit ETRE APPELABLE. `needs` ne franchit pas la frontiere d un
    // workflow : sans `workflow_call` dans `ci.yml`, les `uses:` ci-dessous ne
    // sont pas des gardes, ce sont des erreurs de syntaxe qui feraient
    // echouer le run -- ou pire, un jour, ne rien faire du tout.
    $ci = (array) Yaml::parseFile($racine . '/.github/workflows/ci.yml');
    $declencheurs = (array) ($ci['on'] ?? $ci[1] ?? $ci['true'] ?? []);
    $this->assertArrayHasKey('workflow_call', $declencheurs, sprintf(
        'ci.yml ne declare plus « workflow_call » : les jobs `uses: '
        . './.github/workflows/ci.yml` des workflows de deploiement ne peuvent '
        . 'plus l appeler. Declencheurs lus : %s',
        implode(', ', array_map('strval', array_keys($declencheurs))),
    ));

    $nus = [];
    $inspectes = 0;

    foreach (workflowsDeDeploiementH44() as $relatif) {
        $workflow = (array) Yaml::parseFile($racine . '/' . $relatif);
        $inspectes++;

        // Deuxieme temoin de non-vacuite : un fichier sans job rendrait une
        // liste vide de « jobs nus », donc un vert. On l exige non vide.
        $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];
        $this->assertNotEmpty($jobs, sprintf(
            '%s ne declare aucun job : la garde n aurait rien a mesurer.',
            $relatif,
        ));

        foreach (jobsSansGardeCiH44($workflow) as $job) {
            $nus[] = $relatif . ' -> job « ' . $job . ' »';
        }
    }

    $this->assertSame(count(workflowsDeDeploiementH44()), $inspectes);

    $this->assertSame([], $nus, sprintf(
        "Ces jobs de DEPLOIEMENT ne dependent d aucun appel a ci.yml :\n  - %s\n\n"
        . 'Ils partent donc sur une poussee dans main sans qu un seul test soit joue '
        . '(constat H44-003, S1). Un deploiement qui ne consulte pas la CI ne rend pas '
        . 'la CI inutile : il rend la CI MENSONGERE, parce qu on lit son vert comme si '
        . "elle avait garde la porte.\n\n"
        . 'Correctif, deja ecrit dans deploy-direct-ssh.yml -- on le PORTE, on ne le '
        . "reinvente pas :\n"
        . "  jobs:\n"
        . "    ci:\n"
        . "      uses: ./.github/workflows/ci.yml\n"
        . "      permissions: { contents: read, packages: read }\n"
        . "      secrets: inherit\n"
        . "    <premier job>:\n"
        . "      needs: [ci]\n"
        . "      if: \${{ needs.ci.result == 'success' }}\n",
        implode("\n  - ", $nus),
    ));
});
