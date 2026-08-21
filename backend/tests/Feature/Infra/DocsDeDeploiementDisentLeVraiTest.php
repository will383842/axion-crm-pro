<?php

/**
 * GARDE — A09-001 / A09-002 / A09-009 : UN DOCUMENT QUI MENT EST PIRE QU'UN
 * DOCUMENT ABSENT, PARCE QU'ON LE SUIT.
 *
 * ── CE QUI A ETE MESURE, LE 2026-08-20, AVANT D'ECRIRE CE FICHIER ────────────
 *
 * `_AUDIT/DEPLOY-PIPELINE.md` decrivait, en trois endroits (son schema ligne
 * 14, son bloc « Rollback rapide » ligne 84, sa « procedure manuelle
 * equivalente » ligne 97), la commande de redemarrage suivante :
 *
 *     docker compose up -d --force-recreate api app
 *
 * Or `.github/workflows/deploy-direct-ssh.yml:200` joue, et joue seul :
 *
 *     docker compose up -d --build --force-recreate --no-deps api app horizon scheduler
 *
 * Quatre ecarts, et chacun a deja coute :
 *   - `--no-deps` ABSENT : sans lui, Compose monte aussi `postgres` et `redis`
 *     (les trois services portent `depends_on`). Depuis un shell qui ne porte
 *     pas `COMPOSE_FILE`, cela RECREE la base et le cache depuis le seul
 *     `docker-compose.yml`, qui publie 55432 et 56379 sur `0.0.0.0`. C'est la
 *     faille du 2026-08-19, celle par laquelle 4 295 349 lignes `companies` ont
 *     ete lues depuis internet. La procedure manuelle du document la rouvrait.
 *   - `--build` ABSENT : le frontend n'aurait jamais ete reconstruit.
 *   - `horizon` et `scheduler` ABSENTS : constate en direct le 2026-08-16,
 *     `api` avait 3 minutes quand `horizon` en avait 28 heures.
 *   - `COMPOSE_FILE` ABSENT du document entier : la production repartait en
 *     cible `dev`, avec le bind qui masque le `vendor` de l'image.
 *
 * Le meme document citait `php artisan config:clear` (lignes 16 et 98) comme
 * derniere etape du deploiement. Cette commande a ete RETIREE du workflow :
 * c'est `infra/docker/entrypoint-prod.sh` qui construit les caches de
 * configuration et de routes au demarrage, avec l'environnement reel ; les
 * vider juste apres annulerait son travail. Le workflow joue `cache:clear`, qui
 * n'est pas la meme chose (cache applicatif Redis).
 *
 * Il annoncait enfin un `deploy-prod.yml` qui n'existe dans aucun etat du
 * depot, et un `git reset --hard origin/main` remplace le 2026-08-13 par un
 * reset sur le SHA exact valide par la CI.
 *
 * ── CE QUE CETTE GARDE MESURE ───────────────────────────────────────────────
 *
 * Elle ne juge pas la prose. Elle verifie que CHAQUE COMMANDE CITEE EXISTE :
 *
 *   1. tout `php artisan <nom>` cite dans les deux documents est un nom
 *      REELLEMENT enregistre dans le noyau Artisan de ce depot ;
 *   2. tout `docker compose …` cite par `_AUDIT/DEPLOY-PIPELINE.md` est
 *      textuellement l'une des commandes que le workflow joue ;
 *   3. et RECIPROQUEMENT — c'est le controle qui aurait attrape `--no-deps` —
 *      toute commande `docker compose` que le workflow joue est citee par le
 *      document. Un document qui OMET n'est pas moins faux qu'un document qui
 *      invente ;
 *   4. toute cible `make …`, tout fichier `.github/workflows/*.yml`, tout
 *      chemin et tout identifiant en capitales cites existent.
 *
 * ⚠️ LE BANC NE MONTE PAS LA RACINE DU DEPOT. Le conteneur de mesure ne voit
 * que `backend/` (monte), plus quelques fichiers copies a la main. Les fichiers
 * inspectes ici doivent donc y etre deposes AVANT la mesure :
 *
 *     docker exec a35r sh -c 'mkdir -p /var/www/_AUDIT /var/www/_REPORTS /var/www/frontend'
 *     docker cp .github/.    a35r:/var/www/.github/
 *     docker cp _AUDIT/.     a35r:/var/www/_AUDIT/
 *     docker cp _REPORTS/.   a35r:/var/www/_REPORTS/
 *     docker cp frontend/src a35r:/var/www/frontend/
 *     for d in spec poc _PROMPTS; do docker cp $d a35r:/var/www/; done
 *     docker cp TODO.md     a35r:/var/www/TODO.md
 *     docker cp Makefile    a35r:/var/www/Makefile
 *     docker cp .env.example a35r:/var/www/.env.example
 *
 * En CI le probleme n'existe pas : `actions/checkout` pose l'arbre entier et
 * `base_path('..')` est la vraie racine.
 *
 * Le premier temoin refuse le vert si l'un manque ; le second refuse le vert si
 * la copie du workflow est PERIMEE — une copie d'hier ferait mesurer le
 * pipeline d'hier, et c'est exactement le genre de vert qu'on ne veut pas.
 */

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

/**
 * La racine du depot.
 *
 * `base_path('..')` et NON `base_path()` : les fichiers inspectes vivent
 * au-dessus de l'application (`_AUDIT/`, `.github/`, `TODO.md`, `Makefile`).
 */
function docsDeploiementRacine(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les quatre fichiers de la mesure, nommes un par un.
 *
 * @return array<string, string>
 */
function docsDeploiementFichiers(): array
{
    return [
        'doc' => '_AUDIT/DEPLOY-PIPELINE.md',
        'todo' => 'TODO.md',
        'workflow' => '.github/workflows/deploy-direct-ssh.yml',
        'makefile' => 'Makefile',
    ];
}

function docsDeploiementChemin(string $cle): string
{
    return docsDeploiementResoudre(docsDeploiementFichiers()[$cle]);
}

/**
 * Resout un chemin RELATIF A LA RACINE DU DEPOT en chemin absolu.
 *
 * ⚠️ Le cas `backend/…` est traite a part, et ce n'est pas une commodite :
 * `base_path()` EST, par definition de Laravel, le repertoire `backend/` de ce
 * depot — en CI comme au banc. Le resoudre directement rend ce controle
 * independant de la presence d'un `backend/` VU DEPUIS LA RACINE, ce que le
 * conteneur de mesure ne peut pas offrir (il ne monte que l'application, sur
 * `/var/www/html`). Sans cette resolution, la garde declarait « chemin
 * inexistant » sur `backend/config/crm.php` — c'est-a-dire qu'elle mesurait le
 * montage du banc, pas le document. Piege paye a l'ecriture, le 2026-08-20.
 */
function docsDeploiementResoudre(string $relatif): string
{
    if (str_starts_with($relatif, 'backend/')) {
        return base_path(substr($relatif, strlen('backend/')));
    }

    return docsDeploiementRacine() . '/' . $relatif;
}

function docsDeploiementLire(string $cle): string
{
    $chemin = docsDeploiementChemin($cle);

    return is_file($chemin) ? (string) file_get_contents($chemin) : '';
}

/**
 * Normalise une commande shell pour la comparer d'un fichier a l'autre.
 *
 * ⚠️ Le retrait du `)"` final ne se fait QUE sur la paire complete : une ligne
 * qui se termine par `"$svc"` (c'est le cas de `docker compose logs`) perdrait
 * sinon son guillemet et ne correspondrait plus a elle-meme. Piege paye a
 * l'ecriture de cette garde.
 */
function docsDeploiementNormaliser(string $commande): string
{
    $c = trim($commande);

    // `PS_OUT="$(docker compose ps …)"` : la substitution de commande laisse
    // `)"` derriere elle. On ne retire que cette paire-la.
    if (str_ends_with($c, ')"')) {
        $c = rtrim(substr($c, 0, -2));
    }

    return trim((string) preg_replace('/\s+/', ' ', $c));
}

/**
 * Les invocations `docker compose …` d'un texte.
 *
 * Un commentaire (`#`) ou un `echo` PARLE d'une commande, il ne la joue pas :
 * les compter reviendrait a exiger que le document recopie les commentaires du
 * workflow. Ils sont donc ecartes, des deux cotes.
 *
 * @return list<string>
 */
function docsDeploiementCommandesCompose(string $contenu): array
{
    $trouvees = [];

    foreach (explode("\n", $contenu) as $ligne) {
        $nue = trim($ligne);

        if ($nue === '' || str_starts_with($nue, '#') || str_starts_with($nue, 'echo ')) {
            continue;
        }

        $pos = strpos($nue, 'docker compose ');
        if ($pos === false) {
            continue;
        }

        $trouvees[] = docsDeploiementNormaliser(substr($nue, $pos));
    }

    return array_values(array_unique($trouvees));
}

/**
 * Les noms de commandes Artisan cites dans un texte.
 *
 * @return list<string>
 */
function docsDeploiementCommandesArtisan(string $contenu): array
{
    preg_match_all('/php artisan ([a-zA-Z0-9:_-]+)/', $contenu, $m);

    return array_values(array_unique($m[1]));
}

/**
 * Les cibles `make …` citees entre accents graves.
 *
 * On exige les accents graves : « make » se rencontre en prose anglaise, et une
 * garde qui rougit sur de la prose finit assouplie.
 *
 * @return list<string>
 */
function docsDeploiementCiblesMake(string $contenu): array
{
    preg_match_all('/`make ([a-z0-9_-]+)`/', $contenu, $m);

    return array_values(array_unique($m[1]));
}

/**
 * Les cibles REELLEMENT declarees par le Makefile.
 *
 * @return list<string>
 */
function docsDeploiementCiblesDuMakefile(string $makefile): array
{
    preg_match_all('/^([a-z0-9_-]+):/m', $makefile, $m);

    return array_values(array_unique($m[1]));
}

/**
 * Les chemins de DEPOT cites entre accents graves.
 *
 * Retenus : ceux qui portent un `/` et aucune espace. Ecartes, et chacun pour
 * une raison :
 *   - les URL : elles ne designent rien dans l'arborescence ;
 *   - les motifs a jokers (`_AUDIT/**`) : ils ne designent pas un fichier ;
 *   - les chemins ABSOLUS (`/opt/axion-crm-pro`) : ils designent le SERVEUR,
 *     pas ce depot, et les chercher ici ferait rougir la garde sur une phrase
 *     parfaitement exacte.
 *
 * ⚠️ CONSEQUENCE ASSUMEE : un document ne peut pas citer entre accents graves
 * un chemin qu'il denonce comme MORT. Il doit l'ecrire en clair. C'est le prix
 * a payer pour que le controle n'ait aucune echappatoire — et une echappatoire
 * (« sauf si la ligne contient tel mot ») serait utilisee dans les six mois.
 *
 * @return list<string>
 */
function docsDeploiementCheminsCites(string $contenu): array
{
    preg_match_all('/`([^`\s]+\/[^`\s]*)`/', $contenu, $m);

    $chemins = [];
    foreach ($m[1] as $chemin) {
        if (str_starts_with($chemin, 'http') || str_contains($chemin, '*') || str_starts_with($chemin, '/')) {
            continue;
        }
        $chemins[] = rtrim($chemin, '/');
    }

    return array_values(array_unique($chemins));
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOINS — sans eux, tout ce qui suit peut passer au vert sur du vide
// ─────────────────────────────────────────────────────────────────────────────

test('A09 — TEMOIN : le banc voit les quatre fichiers de la mesure', function () {
    $manquants = [];

    foreach (docsDeploiementFichiers() as $cle => $relatif) {
        if (! is_file(docsDeploiementChemin($cle))) {
            $manquants[] = $relatif;
        }
    }

    expect($manquants)->toBe(
        [],
        'Le banc ne voit pas : ' . implode(', ', $manquants) . ".\n"
        . "Une garde documentaire qui n'a rien a lire passe au vert sans rien prouver.\n"
        . "Le conteneur de mesure ne monte que `backend/` ; deposer les fichiers :\n"
        . "  docker cp .github/. a35r:/var/www/.github/\n"
        . "  docker cp TODO.md   a35r:/var/www/TODO.md\n"
        . "  docker cp Makefile  a35r:/var/www/Makefile\n"
        . "  docker exec a35r mkdir -p /var/www/_AUDIT\n"
        . '  docker cp _AUDIT/DEPLOY-PIPELINE.md a35r:/var/www/_AUDIT/DEPLOY-PIPELINE.md',
    );
});

test('A09 — TEMOIN : la copie du workflow dans le banc n est pas perimee', function () {
    $workflow = docsDeploiementLire('workflow');

    // Quatre ancres mesurees le 2026-08-20 sur `.github/workflows/deploy-direct-ssh.yml`.
    // Elles ne sont pas l'objet de la garde : elles prouvent que la copie du banc
    // est bien celle d'aujourd'hui. Une copie de la veille ferait rougir tout ce
    // fichier pour une raison etrangere au document, ce qui est le meilleur moyen
    // de faire ignorer une garde.
    $ancres = [
        'AXION_DEPLOY_SCRIPT_COMPLETE',
        '--no-deps',
        'export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"',
        'uses: ./.github/workflows/ci.yml',
    ];

    $absentes = [];
    foreach ($ancres as $ancre) {
        if (! str_contains($workflow, $ancre)) {
            $absentes[] = $ancre;
        }
    }

    expect($absentes)->toBe(
        [],
        'La copie de `deploy-direct-ssh.yml` presente au banc ne porte plus : '
        . implode(' | ', $absentes) . ".\n"
        . 'Soit la copie est PERIMEE (`docker cp .github/. a35r:/var/www/.github/`), soit le '
        . 'pipeline a change — et dans ce second cas il faut relire le workflow et REECRIRE '
        . '`_AUDIT/DEPLOY-PIPELINE.md`, pas assouplir cette garde.',
    );
});

test('A09 — TEMOIN NEGATIF : l extracteur sait distinguer une commande inventee d une vraie', function () {
    $fabrique = <<<'TXT'
    # docker compose up -d --commentaire-a-ne-pas-compter
    echo "docker compose up -d --echo-a-ne-pas-compter"
    docker compose up -d --force-recreate api app
    docker compose up -d --build --force-recreate --no-deps api app horizon scheduler
    docker compose logs --tail=50 "$svc"
    TXT;

    $trouvees = docsDeploiementCommandesCompose($fabrique);

    // Ni le commentaire ni l'`echo` ne sont comptes : sinon la reciproque du
    // controle 3 exigerait que le document recopie les commentaires du workflow.
    expect($trouvees)->toHaveCount(3);

    // La forme AMPUTEE (celle que le document portait) et la forme REELLE sont
    // bien deux chaines differentes : c'est la seule raison pour laquelle le
    // controle qui suit peut rougir.
    $this->assertContains('docker compose up -d --force-recreate api app', $trouvees);
    $this->assertContains('docker compose up -d --build --force-recreate --no-deps api app horizon scheduler', $trouvees);

    // Le guillemet final de `"$svc"` doit SURVIVRE a la normalisation.
    $this->assertContains('docker compose logs --tail=50 "$svc"', $trouvees);

    // Et la substitution de commande doit rendre la MEME chaine que la commande nue.
    $substituee = docsDeploiementCommandesCompose('PS_OUT="$(docker compose ps --format \'{{.Service}}\')"');
    $this->assertContains("docker compose ps --format '{{.Service}}'", $substituee);
});

test('A09 — TEMOIN NEGATIF : le noyau Artisan sait refuser un nom invente', function () {
    $connues = array_keys(Artisan::all());

    // TEMOIN DE MONTAGE : un noyau vide rendrait le controle 1 vrai par vacuite.
    expect(count($connues))->toBeGreaterThan(50);

    $this->assertContains('migrate', $connues);
    $this->assertContains('cache:clear', $connues);
    $this->assertNotContains('config:cette-commande-n-existe-pas', $connues);
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. Toute commande Artisan citee existe
// ─────────────────────────────────────────────────────────────────────────────

test('A09 — toute commande `php artisan` citee par les documents existe vraiment', function () {
    $connues = array_keys(Artisan::all());
    $inventees = [];

    foreach (['doc', 'todo'] as $cle) {
        foreach (docsDeploiementCommandesArtisan(docsDeploiementLire($cle)) as $nom) {
            if (! in_array($nom, $connues, true)) {
                $inventees[] = docsDeploiementFichiers()[$cle] . ' → php artisan ' . $nom;
            }
        }
    }

    expect($inventees)->toBe(
        [],
        "Ces documents prescrivent des commandes Artisan qui n'existent pas dans ce depot.\n"
        . "Un exploitant en urgence les recopiera telles quelles.\n\n  - "
        . implode("\n  - ", $inventees),
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Toute commande Compose citee par le document est jouee par le workflow
// ─────────────────────────────────────────────────────────────────────────────

test('A09 — toute commande `docker compose` citee par DEPLOY-PIPELINE.md est celle du workflow', function () {
    $reelles = docsDeploiementCommandesCompose(docsDeploiementLire('workflow'));
    $citees = docsDeploiementCommandesCompose(docsDeploiementLire('doc'));

    // TEMOIN : si le workflow ne rendait aucune commande, le controle serait vrai
    // par vacuite et n'importe quelle invention passerait.
    expect(count($reelles))->toBeGreaterThan(
        3,
        'Aucune commande `docker compose` extraite du workflow : la comparaison ne mesure rien.',
    );

    $fausses = [];
    foreach ($citees as $commande) {
        if (! in_array($commande, $reelles, true)) {
            $fausses[] = $commande;
        }
    }

    expect($fausses)->toBe(
        [],
        "`_AUDIT/DEPLOY-PIPELINE.md` decrit des commandes que le deploiement NE JOUE PAS.\n\n"
        . "Commandes du document, absentes du workflow :\n  - " . implode("\n  - ", $fausses)
        . "\n\nCommandes REELLEMENT jouees par `.github/workflows/deploy-direct-ssh.yml` :\n  - "
        . implode("\n  - ", $reelles),
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. LA RECIPROQUE — c'est elle qui aurait attrape `--no-deps`
// ─────────────────────────────────────────────────────────────────────────────

test('A09 — toute commande `docker compose` jouee par le workflow est citee par le document', function () {
    $reelles = docsDeploiementCommandesCompose(docsDeploiementLire('workflow'));
    $citees = docsDeploiementCommandesCompose(docsDeploiementLire('doc'));

    expect(count($reelles))->toBeGreaterThan(3);

    $omises = [];
    foreach ($reelles as $commande) {
        if (! in_array($commande, $citees, true)) {
            $omises[] = $commande;
        }
    }

    expect($omises)->toBe(
        [],
        "Le deploiement joue ces commandes, et `_AUDIT/DEPLOY-PIPELINE.md` ne les dit pas.\n\n"
        . "C'est par cette omission que le document prescrivait un `docker compose up` SANS "
        . '`--no-deps` : Compose monte alors `postgres` et `redis` (les trois services portent '
        . '`depends_on`) et, hors `COMPOSE_FILE`, les RECREE avec 55432 et 56379 publies sur '
        . "0.0.0.0 — la faille du 2026-08-19.\n\nCommandes omises :\n  - "
        . implode("\n  - ", $omises),
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. Le document est un RUNBOOK : ses `docker compose up` doivent porter l'overlay
// ─────────────────────────────────────────────────────────────────────────────

test('A09 — aucun `docker compose up` du document ne recree la pile sans l overlay de production', function () {
    $lignes = explode("\n", docsDeploiementLire('doc'));
    $overlayPose = false;
    $nues = [];

    foreach ($lignes as $i => $ligne) {
        $nue = trim($ligne);

        if ($nue === '' || str_starts_with($nue, '#') || str_starts_with($nue, 'echo ')) {
            continue;
        }

        if (preg_match('/COMPOSE_FILE\s*=\s*"?[^"\s]*docker-compose\.prod\.yml/', $nue) === 1) {
            $overlayPose = true;
        }

        if (! str_contains($nue, 'docker compose ') || ! preg_match('/docker compose\s+up\b/', $nue)) {
            continue;
        }

        $surLaLigne = preg_match('/-f\s+\S*docker-compose\.prod\.yml/', $nue) === 1;

        if (! $overlayPose && ! $surLaLigne) {
            $nues[] = 'ligne ' . ($i + 1) . ' : ' . $nue;
        }
    }

    expect($nues)->toBe(
        [],
        "Ce document est un RUNBOOK : ce qu'il imprime, un operateur le joue a la main, en "
        . "urgence, dans un shell neuf qui ne porte aucun export.\n\n"
        . 'Ces `docker compose up` repartent alors du seul `docker-compose.yml`, qui PUBLIE '
        . 'Postgres sur 55432 et Redis sur 56379. Compose voit une configuration differente de '
        . "celle des conteneurs en place et il les RECREE, ports compris.\n\n"
        . 'Correctif : `export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"` '
        . "AVANT la commande, dans le meme bloc, comme le fait `deploy-direct-ssh.yml`.\n\n"
        . "Lignes nues :\n  - " . implode("\n  - ", $nues),
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 5. Fichiers, cibles et identifiants cites : tous doivent exister
// ─────────────────────────────────────────────────────────────────────────────

test('A09 — tout workflow cite par les documents existe dans `.github/workflows/`', function () {
    $inexistants = [];

    foreach (['doc', 'todo'] as $cle) {
        preg_match_all('/\.github\/workflows\/([a-z0-9._-]+\.yml)/', docsDeploiementLire($cle), $m);
        foreach (array_unique($m[1]) as $fichier) {
            if (! is_file(docsDeploiementRacine() . '/.github/workflows/' . $fichier)) {
                $inexistants[] = docsDeploiementFichiers()[$cle] . ' → ' . $fichier;
            }
        }
    }

    expect($inexistants)->toBe(
        [],
        "Ces documents renvoient a des workflows qui n'existent pas.\n"
        . "(`deploy-prod.yml` etait de ceux-la : il n'a jamais existe dans ce depot ; le CRM "
        . "ne se deploie pas par Coolify, c'est le site Axion-IA qui le fait.)\n\n  - "
        . implode("\n  - ", $inexistants),
    );
});

test('A09 — toute cible `make` citee par les documents existe dans le Makefile', function () {
    $cibles = docsDeploiementCiblesDuMakefile(docsDeploiementLire('makefile'));

    // TEMOIN : un Makefile absent ou illisible rendrait ce controle vrai par vacuite.
    expect(count($cibles))->toBeGreaterThan(10);
    $this->assertContains('test-backend', $cibles);

    $inventees = [];
    foreach (['doc', 'todo'] as $cle) {
        foreach (docsDeploiementCiblesMake(docsDeploiementLire($cle)) as $cible) {
            if (! in_array($cible, $cibles, true)) {
                $inventees[] = docsDeploiementFichiers()[$cle] . ' → make ' . $cible;
            }
        }
    }

    expect($inventees)->toBe(
        [],
        "Ces documents prescrivent des cibles `make` absentes du Makefile :\n  - "
        . implode("\n  - ", $inventees),
    );
});

test('A09 — TEMOIN : l arborescence du depot est visible depuis le banc', function () {
    // Sans ce temoin, le controle suivant declare « chemin inexistant » chaque
    // fois que le BANC est amptue — et le document se fait accuser a la place du
    // montage. C'est arrive le 2026-08-20 : seize chemins parfaitement valides
    // signales absents parce que le conteneur ne monte que `backend/`.
    //
    // On REFUSE, ici, plutot que de sauter le controle : un test ignore est un
    // vert deguise.
    $requis = ['_AUDIT', '_PROMPTS', '_REPORTS', 'frontend', 'infra', 'poc', 'spec', '.github'];

    $manquants = [];
    foreach ($requis as $repertoire) {
        if (! is_dir(docsDeploiementRacine() . '/' . $repertoire)) {
            $manquants[] = $repertoire;
        }
    }

    expect($manquants)->toBe(
        [],
        'Le banc ne voit pas ces repertoires de la racine : ' . implode(', ', $manquants) . ".\n"
        . "Le controle des chemins de TODO.md accuserait alors le document d'une amputation du "
        . "banc. En CI le probleme n'existe pas (`actions/checkout` pose l'arbre entier) ; au "
        . "banc local, deposer les repertoires cites :\n"
        . "  for d in spec poc _REPORTS _PROMPTS _AUDIT; do docker cp \$d a35r:/var/www/; done\n"
        . "  docker exec a35r sh -c 'mkdir -p /var/www/frontend' && docker cp frontend/src a35r:/var/www/frontend/",
    );
});

test('A09 — tout chemin cite par TODO.md existe dans le depot', function () {
    $absents = [];

    foreach (docsDeploiementCheminsCites(docsDeploiementLire('todo')) as $chemin) {
        if (! file_exists(docsDeploiementResoudre($chemin))) {
            $absents[] = $chemin;
        }
    }

    expect($absents)->toBe(
        [],
        "`TODO.md` renvoie a des chemins qui n'existent pas.\n"
        . "Un lecteur qui suit ces renvois conclut que le depot est ailleurs qu'il n'est ; "
        . "c'est exactement ce que faisait la version d'avant le 2026-08-20, qui se declarait "
        . "« source de verite » en decrivant un depot d'AVANT la premiere ligne de code.\n\n"
        . "⚠️ Si l'un de ces chemins est cite PRECISEMENT parce qu'il est mort, il faut "
        . "l'ecrire sans accents graves : ce controle n'a pas d'echappatoire, deliberement.\n\n  - "
        . implode("\n  - ", $absents),
    );
});

test('A09 — tout identifiant en capitales cite par DEPLOY-PIPELINE.md existe ailleurs que dans lui-meme', function () {
    $doc = docsDeploiementLire('doc');
    $workflow = docsDeploiementLire('workflow');
    $envExemple = (string) @file_get_contents(docsDeploiementRacine() . '/.env.example');

    // TEMOIN : les deux references doivent etre non vides, sinon tout identifiant
    // serait declare invente et la garde rougirait pour la mauvaise raison.
    expect(strlen($workflow))->toBeGreaterThan(1000);
    expect(strlen($envExemple))->toBeGreaterThan(1000);

    preg_match_all('/`([A-Z][A-Z0-9_]{3,})`/', $doc, $m);

    $inventes = [];
    foreach (array_unique($m[1]) as $identifiant) {
        if (! str_contains($workflow, $identifiant) && ! str_contains($envExemple, $identifiant)) {
            $inventes[] = $identifiant;
        }
    }

    expect($inventes)->toBe(
        [],
        "`_AUDIT/DEPLOY-PIPELINE.md` nomme des secrets ou des variables qu'aucun workflow ni "
        . "`.env.example` ne connait. Un exploitant les creera dans l'interface GitHub, ou ils "
        . "ne serviront a rien — et croira le deploiement configure.\n\n  - "
        . implode("\n  - ", $inventes),
    );
});
