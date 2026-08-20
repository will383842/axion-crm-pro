<?php

/**
 * GARDE DE L'OVERLAY DE PRODUCTION — constat F38-007 (S0).
 *
 * CE QUI S'EST PASSÉ LE 19 AOÛT.
 *
 * `docker-compose.yml` publie Postgres sur `55432` et Redis sur `56379` — c'est
 * le confort du poste de développement. L'overlay `docker-compose.prod.yml`
 * retire ces publications avec `ports: !override []`, et le tag `!override` est
 * indispensable : **Compose FUSIONNE les listes entre fichiers, il ne les
 * remplace pas.**
 *
 * Ces deux ports ont été trouvés **ouverts depuis l'extérieur** sur la
 * production le 2026-08-19. Redis n'a aucun mot de passe : `FLUSHALL`, lecture
 * des jetons, `CONFIG SET`. Le correctif a refermé la porte.
 *
 * 🔴 MAIS IL RESTAIT LE MOYEN DE LA ROUVRIR, ET IL TENAIT EN UNE COMMANDE.
 *
 * Un `docker compose up -d` lancé sur le serveur **sans** l'overlay repart du
 * seul `docker-compose.yml` : Compose voit une configuration différente de
 * celle des conteneurs en place, et il les **recrée** — avec les ports publiés.
 *
 * Trois chemins menaient là, et le premier est à un clic :
 *
 *   1. `.github/workflows/diag-website-status.yml` — un `workflow_dispatch`,
 *      donc déclenchable depuis l'interface GitHub, qui fait `docker compose
 *      up -d` sur l'hôte de production. **Sans overlay. Toute la pile.**
 *   2. `infra/scripts/configure-prod-env.sh` — `docker compose up -d api
 *      horizon scheduler`. Il ne nomme que trois services, mais les trois
 *      portent `depends_on: [postgres, redis]` : **sans `--no-deps`, Compose
 *      monte aussi les dépendances**, depuis le fichier de base.
 *   3. `infra/scripts/setup-hetzner-cpx22.sh` — la même commande, imprimée
 *      comme instruction à l'opérateur. *Un runbook qui prescrit le défaut le
 *      reproduira aussi sûrement qu'un script qui l'exécute.*
 *
 * CE QUE CETTE GARDE MESURE.
 *
 * Toute invocation de `docker compose up` visant la production doit désigner
 * l'overlay — par `COMPOSE_FILE` exporté avant elle, ou par des `-f` explicites.
 * Elle porte son témoin de montage et son témoin négatif : un contrôle qui rend
 * zéro ne vaut rien tant qu'on n'a pas prouvé qu'il sait rendre un.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * La racine du dépôt.
 *
 * ⚠️ `base_path('..')` et NON `base_path()` : les fichiers inspectés ici vivent
 * réellement au-dessus de l'application (`.github/`, `infra/`). C'est l'inverse
 * du piège payé sur `PatronHmacDeReferenceTest`, où des fichiers de `backend/`
 * étaient cherchés au-dessus. Les deux erreurs sont symétriques et donnent la
 * même chose : une garde rouge d'un côté, verte de l'autre.
 */
function racineDepotOverlay(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les fichiers qui pilotent la PRODUCTION.
 *
 * Volontairement nommés un par un plutôt que ramassés au `glob` : un `glob` qui
 * élargirait le périmètre demain ferait rougir cette garde sur un script de
 * poste de développement, et la pente serait alors de l'assouplir.
 *
 * @return list<string>
 */
function fichiersDeProduction(): array
{
    return [
        '.github/workflows/diag-website-status.yml',
        '.github/workflows/deploy-direct-ssh.yml',
        'infra/scripts/configure-prod-env.sh',
        'infra/scripts/setup-hetzner-cpx22.sh',

        // 🔴 AJOUTES LE 2026-08-20, APRES COUP, ET C'EST LA LECON.
        //
        // La premiere version de cette garde ne couvrait que les workflows et
        // les scripts. Les RUNBOOKS n'y etaient pas -- et ils portaient SEPT
        // invocations nues de plus, dont celle de la reprise apres sinistre.
        //
        // Le defaut avait ete repare une premiere fois sur une autre branche,
        // et n'avait jamais ete porte ici. J'ai moi-meme corrige les trois
        // exemplaires que j'avais sous les yeux sans balayer leurs freres :
        // c'est exactement le patron A-011, commis dans le correctif qui le
        // poursuit.
        //
        // Un runbook est du CODE que l'operateur execute a la main, en urgence.
        // Le 04 est joue sur une machine NEUVE, ou aucun shell ne porte
        // l'export : c'est le pire moment pour rouvrir la faille, et le moment
        // ou personne ne regarde.
        'infra/runbooks/01-restart-workers.md',
        'infra/runbooks/02-disk-full.md',
        'infra/runbooks/03-site-down.md',
        'infra/runbooks/04-restore-dr.md',
        'infra/runbooks/05-rotate-secrets.md',
    ];
}

/**
 * Les invocations de `docker compose up` d'un fichier, avec leur contexte.
 *
 * @return list<array{ligne: int, texte: string, couvert: bool}>
 */
function invocationsComposeUp(string $contenu): array
{
    $lignes = explode("\n", $contenu);
    $trouvees = [];

    // L'overlay peut être posé bien avant la commande, par un export qui vaut
    // pour tout le script. On suit donc l'état au fil de la lecture.
    $overlayPose = false;

    foreach ($lignes as $i => $ligne) {
        if (preg_match('/COMPOSE_FILE\s*=\s*["\']?[^"\'\s]*docker-compose\.(prod|staging)\.yml/', $ligne) === 1) {
            $overlayPose = true;
        }

        if (preg_match('/docker[\s-]compose\s+.*\bup\b/', $ligne) !== 1) {
            continue;
        }

        // Un `-f …prod.yml` sur la ligne même compte aussi.
        $surLaLigne = preg_match('/-f\s+\S*docker-compose\.(prod|staging)\.yml/', $ligne) === 1;

        // `--no-deps` ne suffit PAS à lui seul : il empêche de monter postgres
        // et redis, mais l'overlay porte bien d'autres choses (politiques de
        // redémarrage, healthchecks, ports de reverb). On ne l'accepte donc
        // pas comme substitut, et c'est délibéré.
        $trouvees[] = [
            'ligne' => $i + 1,
            'texte' => trim($ligne),
            'couvert' => $overlayPose || $surLaLigne,
        ];
    }

    return $trouvees;
}

test('F38-007 — TEMOIN : le banc voit bien les fichiers de pilotage de la production', function () {
    $manquants = [];

    foreach (fichiersDeProduction() as $relatif) {
        $chemin = racineDepotOverlay() . '/' . $relatif;
        if (! is_file($chemin)) {
            $manquants[] = $relatif;
        }
    }

    // Sans ce témoin, un chemin faux ou un répertoire non monté ferait passer
    // la garde au vert sur ZÉRO fichier — le pire des verts, et le défaut exact
    // trouvé le 19/08 sur les gardes de ce dossier.
    expect($manquants)->toBe(
        [],
        'Le banc ne voit pas ces fichiers : ' . implode(', ', $manquants)
        . '. Une garde qui n\'a rien a inspecter passe au vert sans rien prouver. '
        . '⚠️ Le conteneur de mesure ne monte pas `.github/` : `docker cp .github a35r:/var/www/.github`.'
    );
});

test('F38-007 — TEMOIN NEGATIF : le balayage SAIT reperer une invocation nue', function () {
    $fabrique = "cd /opt/axion-crm-pro\ndocker compose up -d\n";
    $trouvees = invocationsComposeUp($fabrique);

    expect($trouvees)->toHaveCount(1);
    expect($trouvees[0]['couvert'])->toBeFalse(
        'Le balayage ne reconnait pas une invocation nue fabriquee : la garde ci-dessous '
        . 'passerait au vert sur n\'importe quoi.'
    );

    // Et il doit reconnaître la forme CORRECTE comme correcte, sinon il rougirait
    // sur le correctif lui-même.
    $correcte = "export COMPOSE_FILE=\"docker-compose.yml:docker-compose.prod.yml\"\ndocker compose up -d\n";
    expect(invocationsComposeUp($correcte)[0]['couvert'])->toBeTrue();
});

test('F38-007 — aucune commande visant la production ne recree la pile SANS l overlay', function () {
    $nues = [];

    foreach (fichiersDeProduction() as $relatif) {
        $chemin = racineDepotOverlay() . '/' . $relatif;
        if (! is_file($chemin)) {
            continue; // le témoin ci-dessus l'a déjà dit
        }

        foreach (invocationsComposeUp((string) file_get_contents($chemin)) as $inv) {
            if (! $inv['couvert']) {
                $nues[] = "{$relatif}:{$inv['ligne']}  →  {$inv['texte']}";
            }
        }
    }

    expect($nues)->toBe(
        [],
        "Ces commandes recreent la pile de PRODUCTION sans `docker-compose.prod.yml`.\n\n"
        . "Compose repart alors du seul `docker-compose.yml`, qui PUBLIE Postgres sur 55432 et "
        . "Redis sur 56379. Il voit une configuration differente de celle des conteneurs en "
        . "place et il les RECREE, ports compris. C'est la faille du 2026-08-19, reouverte.\n\n"
        . "Redis n'a aucun mot de passe : FLUSHALL, lecture des jetons, CONFIG SET.\n\n"
        . "Correctif : `export COMPOSE_FILE=\"docker-compose.yml:docker-compose.prod.yml\"` avant "
        . "la commande, comme le fait deja `deploy-direct-ssh.yml`.\n\n"
        . "Commandes nues :\n  - " . implode("\n  - ", $nues)
    );
});

/**
 * LE PIÈGE DANS LE PIÈGE — `depends_on` monte ce qu'on n'a pas nommé.
 *
 * `configure-prod-env.sh` ne nommait que `api horizon scheduler`. On pourrait
 * croire que Postgres et Redis ne sont pas concernés. Ils le sont : les trois
 * services portent `depends_on: [postgres, redis]`, et Compose monte les
 * dépendances sauf si on lui dit `--no-deps`.
 *
 * Ce test fige la raison, pour que personne ne « simplifie » l'overlay en se
 * disant que la commande ne parle pas de base de données.
 */
test('F38-007 — les services applicatifs dependent bien de postgres et redis', function () {
    $compose = (string) file_get_contents(racineDepotOverlay() . '/docker-compose.yml');

    expect(substr_count($compose, 'depends_on'))->toBeGreaterThanOrEqual(
        4,
        'Le fichier de base ne declare plus les dependances attendues : la raison pour laquelle '
        . '`docker compose up -d api` monte aussi postgres et redis a disparu, et il faut '
        . 'reverifier ce que ce test protege.'
    );

    expect($compose)->toContain('55432:5432');
    expect($compose)->toContain('56379:6379');

    $prod = (string) file_get_contents(racineDepotOverlay() . '/docker-compose.prod.yml');

    // `!override` et non `ports: []` : Compose FUSIONNE les listes. Sans le tag,
    // la publication du fichier de base survivrait a l'overlay.
    expect(substr_count($prod, 'ports: !override []'))->toBeGreaterThanOrEqual(
        2,
        "L'overlay ne retire plus les publications de ports avec `!override`. Sans ce tag, "
        . 'Compose FUSIONNE les listes et la publication du fichier de base survit.'
    );
});
