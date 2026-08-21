<?php

/**
 * GARDE DU BRANCHEMENT DE LA GARDE DES PORTS — constats F38-003 / F40-004 / F40-005.
 *
 * ── CE QUE CE FICHIER PROTEGE, ET POURQUOI IL EXISTE ───────────────────────
 *
 * Le 2026-08-19, la production publiait `0.0.0.0:55432->5432` (Postgres) et
 * `0.0.0.0:56379->6379` (Redis), Redis SANS mot de passe, devant une base de
 * 4 295 349 fiches. La garde CI `config-prod` etait VERTE, le deploiement etait
 * VERT. Les deux disaient vrai : le FICHIER fusionne ne publiait bien que 80 et
 * 443. C'est le REEL qui divergeait.
 *
 * `infra/scripts/verifier-ports-publies.sh` a ete ecrit ce jour-la pour mesurer
 * le reel — `docker ps`, pas `docker compose config`. Il est sain : rejoue en
 * lecture seule sur ce poste le 2026-08-20, il rend
 *
 *   - `axion-crm-pro` (defaut « 80 443 ») ....... exit 1, « ports publies sur
 *      internet alors qu'ils ne devraient pas : 55432 »   <- il SAIT rougir,
 *      et sur exactement le defaut du 19/08
 *   - projet inexistant ......................... exit 2, « la mesure n'a rien vu »
 *   - `bookforge` avec « 5433 6380 » ............ exit 0
 *   - `bookforge` avec liste VIDE ............... exit 1
 *
 * Le defaut n'est donc PAS dans le script. Il est dans son BRANCHEMENT :
 *
 *   F38-003 / F40-004 — un seul appel dans tout le depot, dans le workflow de
 *   PREPRODUCTION, et son code de retour y est neutralise par
 *   `|| echo "(controle des ports en echec - voir ci-dessus)"`, qui ne touche
 *   pas la variable `ok` evaluee dix lignes plus bas. La pile qui a ete percee
 *   — `axion-crm-pro` — n'est mesuree par AUCUN job.
 *
 *   F40-005 — `deploy-staging.yml` fait DEUX `up` (les quatre services
 *   applicatifs, puis `postgres redis`) ; `deploy-direct-ssh.yml` s'arrete au
 *   premier. Avec `--no-deps`, aucun pas du deploiement de production ne peut
 *   donc appliquer une modification de `docker-compose*.yml` portant sur
 *   `postgres`, `redis` ou `reverb`. Ces conteneurs gardent la configuration de
 *   leur creation, indefiniment. C'est le « piege 18 », repare pour la
 *   preproduction et jamais retroporte.
 *
 * ── LE TROISIEME SITE, QUE L'AUDIT NE NOMME PAS ────────────────────────────
 *
 * L'audit nomme deux fichiers. Il y en a TROIS. `diag-website-status.yml` est un
 * `workflow_dispatch` — declenchable en un clic depuis l'interface GitHub — qui
 * fait `docker compose up -d` sur la production, SANS `--no-deps` et SANS nommer
 * de service : il recree TOUTE la pile, postgres et redis compris. C'est le
 * chemin le plus court pour rouvrir la faille, et c'est justement celui qu'aucun
 * controle ne suivait.
 *
 * D'ou le TEMOIN D'ENUMERATION ci-dessous : la liste des fichiers surveilles
 * n'est pas ecrite a la main puis crue sur parole, elle est CONFRONTEE au
 * balayage de tout `.github/workflows/`. La lecon a ete payee trois fois sur
 * `PileDeProductionSansOverlayTest` : quand une garde enumere son perimetre,
 * ce n'est pas la garde qu'il faut croire, c'est l'ENUMERATION.
 *
 * ── AVERTISSEMENT DE BANC ──────────────────────────────────────────────────
 *
 * Le conteneur de mesure `a35r` ne monte PAS `.github/` : c'est une copie
 * deposee par `docker cp`, pas un montage. Une copie perimee ferait lire a
 * cette garde l'etat d'avant le correctif — et elle rougirait. C'est le bon
 * sens : le vert exige la copie a jour.
 *
 *     docker cp .github a35r:/var/www/.github
 *
 * ⚠️ Aucun `expect()->toContain($x, $message)` ici : Pest est variadique et le
 * message deviendrait une seconde valeur cherchee. Voir
 * `AucunMessageDansToContainTest`.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * La racine du depot.
 *
 * ⚠️ `base_path('..')` et NON `base_path()` : `.github/` et `infra/` vivent
 * au-dessus de l'application Laravel.
 */
function racineDepotGardePorts(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les workflows qui RECREENT une pile Docker sur un serveur, et le projet
 * Compose que chacun pilote.
 *
 * Enumere a la main — mais confronte au balayage par le temoin d'enumeration,
 * qui rougit si un quatrieme workflow apparait ou si l'un d'eux disparait.
 *
 * @return array<string, string> chemin relatif => projet Compose attendu
 */
function pilesPiloteesParLesWorkflows(): array
{
    return [
        // PRODUCTION — 4 295 349 fiches. Ports publics legitimes : 80 et 443.
        '.github/workflows/deploy-direct-ssh.yml' => 'axion-crm-pro',
        '.github/workflows/diag-website-status.yml' => 'axion-crm-pro',
        // PREPRODUCTION — ses deux publications sont sur 172.17.0.1 (passerelle
        // du pont Docker, non routable). Liste des ports publics AUTORISES :
        // VIDE. Passer « 8081 8082 » ferait rougir le temoin positif du script
        // sur une pile parfaitement conforme.
        '.github/workflows/deploy-staging.yml' => 'axion-crm-staging',
    ];
}

/**
 * Le mode d'emploi que l'exploitant doit suivre pour F38-002.
 *
 * La protection de branche se regle par l'API GitHub, pas dans le depot : cette
 * garde ne peut donc pas la corriger. Elle peut en revanche garantir que
 * l'instruction ecrite pour l'exploitant existe et ne prescrit pas des noms de
 * contexte fantomes.
 */
function cheminModeEmploiProtection(): string
{
    return '.github/PROTECTION-DE-BRANCHE.md';
}

/**
 * Decoupe un script shell en lignes LOGIQUES : les continuations `\` sont
 * recollees, les commentaires et les lignes vides retires.
 *
 * ⚠️ Sans le recollage, le defaut F38-003 serait INVISIBLE : dans
 * `deploy-staging.yml`, l'appel est en ligne 175 et le `|| echo` qui l'avale est
 * en ligne 176, apres un `\`. Un balayage ligne a ligne verrait un appel
 * parfaitement sain.
 *
 * Le numero rendu est celui de la PREMIERE ligne physique, pour que le message
 * d'echec designe le bon endroit du fichier.
 *
 * @return list<array{ligne: int, texte: string}>
 */
function lignesLogiquesShell(string $contenu): array
{
    $brutes = explode("\n", str_replace("\r\n", "\n", $contenu));
    $total = count($brutes);
    $sorties = [];

    for ($i = 0; $i < $total; $i++) {
        $depart = $i + 1;
        $texte = rtrim($brutes[$i]);

        while (str_ends_with($texte, '\\') && $i + 1 < $total) {
            $texte = rtrim(substr($texte, 0, -1)) . ' ' . trim($brutes[$i + 1]);
            $i++;
        }

        $nu = ltrim($texte);
        if ($nu === '' || str_starts_with($nu, '#')) {
            continue;
        }

        $sorties[] = ['ligne' => $depart, 'texte' => $texte];
    }

    return $sorties;
}

/**
 * Les appels a `verifier-ports-publies.sh`, avec le projet mesure et l'etat de
 * leur code de retour.
 *
 * `avalee` = le `||` retombe sur une commande qui reussit TOUJOURS. L'echec
 * disparait alors, y compris le `exit 2` « je n'ai rien pu mesurer » — qui est
 * precisement le temoin interne du script. Un `|| echo` ne degrade pas la
 * garde : il l'annule.
 *
 * `|| ok=0` n'est PAS une neutralisation : il alimente une variable evaluee
 * ensuite. Le test qui l'accepte verifie separement que cette evaluation existe.
 *
 * @return list<array{ligne: int, texte: string, projet: string, avalee: bool}>
 */
function appelsGardeDesPorts(string $contenu): array
{
    $trouves = [];

    foreach (lignesLogiquesShell($contenu) as $ligne) {
        if (! str_contains($ligne['texte'], 'verifier-ports-publies.sh')) {
            continue;
        }

        // Le projet Compose est le premier argument apres le chemin du script.
        //
        // ⚠️ La ponctuation de fin de commande fait partie du jeton et n'a rien
        // a voir avec le nom du projet. Mesure du 2026-08-20 : la forme
        // recommandee `if ! bash …/verifier-ports-publies.sh axion-crm-pro; then`
        // rendait « axion-crm-pro; » -- et cette garde rougissait sur son PROPRE
        // correctif, en annoncant qu'il mesurait la mauvaise pile. Un rouge pour
        // la mauvaise raison est aussi nuisible qu'un vert pour la mauvaise
        // raison : c'est celui-la qu'on desarme.
        $projet = '';
        if (preg_match('/verifier-ports-publies\.sh["\']?\s+("[^"]*"|\'[^\']*\'|\S+)/', $ligne['texte'], $m) === 1) {
            $projet = trim($m[1], "\"' \t;&|");
        }

        $avalee = preg_match('/\|\|\s*(echo|printf|true)\b/', $ligne['texte']) === 1
            || preg_match('/\|\|\s*:(\s|$)/', $ligne['texte']) === 1;

        $trouves[] = [
            'ligne' => $ligne['ligne'],
            'texte' => trim($ligne['texte']),
            'projet' => $projet,
            'avalee' => $avalee,
        ];
    }

    return $trouves;
}

/**
 * Les invocations de `docker compose up` d'un script, avec les services nommes.
 *
 * Une liste de services VIDE signifie « toute la pile » — c'est le cas de
 * `diag-website-status.yml`, et c'est le plus dangereux des trois.
 *
 * @return list<array{ligne: int, texte: string, services: list<string>, sansDeps: bool}>
 */
function invocationsUpAvecServices(string $contenu): array
{
    $trouvees = [];

    foreach (lignesLogiquesShell($contenu) as $ligne) {
        if (preg_match('/docker[\s-]compose\s+.*\bup\b/', $ligne['texte']) !== 1) {
            continue;
        }

        // On coupe au premier tube simple, puis on retire les redirections :
        // `docker compose up -d 2>&1 | tail -30` ne nomme aucun service.
        $commande = preg_split('/(?<!\|)\|(?!\|)/', $ligne['texte'])[0] ?? $ligne['texte'];
        $commande = (string) preg_replace('/\d*>&?\S*/', ' ', $commande);

        $apres = preg_split('/\bup\b/', $commande, 2)[1] ?? '';
        $services = [];
        foreach (preg_split('/\s+/', trim($apres)) ?: [] as $jeton) {
            if ($jeton === '' || str_starts_with($jeton, '-')) {
                continue;
            }
            $services[] = $jeton;
        }

        $trouvees[] = [
            'ligne' => $ligne['ligne'],
            'texte' => trim($ligne['texte']),
            'services' => $services,
            'sansDeps' => str_contains($ligne['texte'], '--no-deps'),
        ];
    }

    return $trouvees;
}

/**
 * Le fichier laisse-t-il `postgres` et `redis` hors de portee du deploiement ?
 *
 * C'est le piege 18, formule mecaniquement : il existe au moins un `up` qui
 * NOMME ses services avec `--no-deps` (donc qui n'emporte pas les dependances),
 * et AUCUN `up` du fichier ne couvre la base et le cache — ni en les nommant,
 * ni en montant toute la pile.
 */
function laBaseEtLeCacheSontHorsDePorteeDuDeploiement(string $contenu): bool
{
    $unUpNommeAvecNoDeps = false;
    $unUpCouvreBaseEtCache = false;

    foreach (invocationsUpAvecServices($contenu) as $up) {
        if ($up['services'] === []) {
            // `up -d` nu : toute la pile, donc la base et le cache.
            $unUpCouvreBaseEtCache = true;

            continue;
        }

        if ($up['sansDeps']) {
            $unUpNommeAvecNoDeps = true;
        }

        if (in_array('postgres', $up['services'], true) && in_array('redis', $up['services'], true)) {
            $unUpCouvreBaseEtCache = true;
        }
    }

    return $unUpNommeAvecNoDeps && ! $unUpCouvreBaseEtCache;
}

/**
 * Les noms de job declares par tous les workflows.
 *
 * Un job est indente de 2 espaces sous `jobs:`, sa cle `name:` de 4. Les `name:`
 * d'etapes sont a 6 espaces ou plus, precedes d'un tiret : le motif ne les
 * attrape pas. C'est ce nom qui devient le CONTEXTE requis par la protection de
 * branche.
 *
 * @return list<string>
 */
function nomsDesJobsDeTousLesWorkflows(): array
{
    $noms = [];

    foreach (glob(racineDepotGardePorts() . '/.github/workflows/*.yml') ?: [] as $chemin) {
        foreach (explode("\n", (string) file_get_contents($chemin)) as $ligne) {
            if (preg_match('/^ {4}name:\s*(\S.*?)\s*$/', rtrim($ligne, "\r"), $m) === 1) {
                $noms[] = trim($m[1], "\"'");
            }
        }
    }

    return $noms;
}

/**
 * Repli des accents et de la casse.
 *
 * ⚠️ Un controle sur du texte francais ne se joue pas sur des lettres
 * accentuees : « Les scripts d'infra sont-ils executables ? » traverse un
 * fichier Markdown, un fichier YAML, un `docker cp` et un moteur de regex — et
 * il suffit d'un octet different pour que la comparaison echoue sans que rien
 * ne soit casse.
 */
function repliSansAccent(string $texte): string
{
    $table = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ];

    return strtr(mb_strtolower($texte, 'UTF-8'), $table);
}

/**
 * Les contextes que le mode d'emploi prescrit a l'exploitant.
 *
 * @return list<string>
 */
function contextesPrescritsParLeModeEmploi(string $contenu): array
{
    if (preg_match('/CONTEXTES-REQUIS:DEBUT(.*?)CONTEXTES-REQUIS:FIN/s', $contenu, $m) !== 1) {
        return [];
    }

    $contextes = [];
    foreach (explode("\n", $m[1]) as $ligne) {
        if (preg_match('/^\s*-\s+`([^`]+)`\s*$/u', rtrim($ligne, "\r"), $c) === 1) {
            $contextes[] = $c[1];
        }
    }

    return $contextes;
}

// ═══════════════════════════════════════════════════════════════════════════
// TEMOINS — sans eux, tout ce qui suit peut passer au vert sans rien mesurer.
// ═══════════════════════════════════════════════════════════════════════════

test('F38-003 — TEMOIN DE MONTAGE : le banc voit les workflows et le script mesure', function () {
    $attendus = array_merge(
        array_keys(pilesPiloteesParLesWorkflows()),
        ['infra/scripts/verifier-ports-publies.sh'],
    );

    $manquants = [];
    $vides = [];

    foreach ($attendus as $relatif) {
        $chemin = racineDepotGardePorts() . '/' . $relatif;
        if (! is_file($chemin)) {
            $manquants[] = $relatif;

            continue;
        }
        if (filesize($chemin) === 0) {
            $vides[] = $relatif;
        }
    }

    expect($manquants)->toBe(
        [],
        'Le banc ne voit pas ces fichiers : ' . implode(', ', $manquants) . '. '
        . "Une garde qui n'a rien a inspecter passe au vert sans rien prouver -- c'est le "
        . "defaut exact trouve le 19/08 sur les gardes de ce dossier.\n"
        . '⚠️ Le conteneur de mesure ne MONTE pas `.github/`, il en porte une COPIE : '
        . '`docker cp .github a35r:/var/www/.github`.',
    );

    expect($vides)->toBe([], 'Fichiers vus mais VIDES : ' . implode(', ', $vides));
});

test('F38-003 — TEMOIN D ENUMERATION : aucun workflow qui recree une pile n echappe a la liste', function () {
    $dossier = racineDepotGardePorts() . '/.github/workflows';
    $fichiers = glob($dossier . '/*.yml') ?: [];

    // Sans ce plancher, un `glob` qui rend zero fichier laisserait le reste du
    // test conclure « aucun workflow oublie » — un vert sur du vide.
    expect(count($fichiers))->toBeGreaterThanOrEqual(
        10,
        'Seulement ' . count($fichiers) . " fichiers de workflow balayes dans {$dossier} : "
        . 'le balayage ne voit pas ce qu il croit voir.',
    );

    $balayes = [];
    foreach ($fichiers as $chemin) {
        $relatif = '.github/workflows/' . basename($chemin);
        if (invocationsUpAvecServices((string) file_get_contents($chemin)) !== []) {
            $balayes[] = $relatif;
        }
    }

    sort($balayes);
    $enumeres = array_keys(pilesPiloteesParLesWorkflows());
    sort($enumeres);

    expect($balayes)->toBe(
        $enumeres,
        'La liste ecrite a la main dans `pilesPiloteesParLesWorkflows()` ne correspond plus a ce '
        . "que le balayage trouve.\n\n"
        . 'Trouve par balayage : ' . implode(', ', $balayes) . "\n"
        . 'Enumere a la main   : ' . implode(', ', $enumeres) . "\n\n"
        . "Un workflow qui recree une pile Docker sans figurer dans la liste n'est mesure par "
        . 'AUCUN des controles ci-dessous. La lecon a ete payee trois fois sur '
        . "`PileDeProductionSansOverlayTest` : ce n'est pas la garde qu'il faut croire, c'est "
        . "l'ENUMERATION qu'il faut challenger.",
    );
});

test('F38-003 — TEMOIN NEGATIF : le balayage sait reperer un appel avale, absent, ou nu', function () {
    // 1. La forme EXACTE du defaut, continuation comprise. C'est ce que
    //    `deploy-staging.yml` portait en 175-176.
    $avale = "bash \"\$CHEMIN/infra/scripts/verifier-ports-publies.sh\" axion-crm-staging \"\" \\\n"
        . "  || echo \"(controle des ports en echec - voir ci-dessus)\"\n";
    $trouves = appelsGardeDesPorts($avale);

    expect($trouves)->toHaveCount(1);
    expect($trouves[0]['projet'])->toBe('axion-crm-staging');
    expect($trouves[0]['avalee'])->toBeTrue(
        'Le balayage ne recolle pas la continuation `\\` : il verrait un appel parfaitement sain '
        . 'la ou le `|| echo` de la ligne suivante annule tout. Sans ce recollage, la garde '
        . 'ci-dessous passerait au vert sur le defaut F38-003 lui-meme.',
    );

    // 2. Les formes SAINES doivent etre reconnues saines, ET rendre le bon nom
    //    de projet -- sinon la garde rougit sur son PROPRE correctif.
    //
    //    ⚠️ Le troisieme cas est celui qui a mordu le 2026-08-20 : la
    //    ponctuation `;` de `if ! … ; then` restait collee au jeton, la garde
    //    lisait « axion-crm-pro; » et annoncait que le correctif mesurait la
    //    mauvaise pile. Un rouge pour la mauvaise raison est celui qu'on
    //    finit par desarmer.
    foreach ([
        'bash "$PROJECT_PATH/infra/scripts/verifier-ports-publies.sh" axion-crm-pro' => 'axion-crm-pro',
        'bash infra/scripts/verifier-ports-publies.sh axion-crm-staging "" || ok=0' => 'axion-crm-staging',
        'if ! bash infra/scripts/verifier-ports-publies.sh axion-crm-pro; then' => 'axion-crm-pro',
    ] as $saine => $projetAttendu) {
        $lu = appelsGardeDesPorts($saine);
        expect($lu)->toHaveCount(1);
        expect($lu[0]['avalee'])->toBeFalse('Forme saine prise pour une neutralisation : ' . $saine);
        expect($lu[0]['projet'])->toBe($projetAttendu, 'Projet mal extrait de : ' . $saine);
    }

    // 3. Un appel simplement commente ne compte pas : sinon il suffirait
    //    d'ecrire la commande en commentaire pour verdir la garde.
    expect(appelsGardeDesPorts("# bash infra/scripts/verifier-ports-publies.sh axion-crm-pro\n"))
        ->toHaveCount(0);

    // 4. Aucun appel du tout — cas de `deploy-direct-ssh.yml` avant correctif.
    expect(appelsGardeDesPorts("docker compose up -d --no-deps api app\n"))->toHaveCount(0);
});

test('F40-005 — TEMOIN NEGATIF : le balayage sait reperer un deploiement qui oublie sa base', function () {
    // La forme EXACTE de `deploy-direct-ssh.yml:200` avant correctif.
    $fautif = "docker compose up -d --build --force-recreate --no-deps api app horizon scheduler\n";
    expect(laBaseEtLeCacheSontHorsDePorteeDuDeploiement($fautif))->toBeTrue(
        'Le balayage ne voit pas le piege 18 sur la ligne qui le porte : la garde ci-dessous '
        . 'passerait au vert sur le defaut lui-meme.',
    );

    // La forme corrigee, celle de `deploy-staging.yml:140-143`.
    $corrige = $fautif . "docker compose up -d --no-deps postgres redis\n";
    expect(laBaseEtLeCacheSontHorsDePorteeDuDeploiement($corrige))->toBeFalse();

    // Le cas de `diag-website-status.yml` : `up -d` nu, donc toute la pile.
    $toutePile = "docker compose up -d 2>&1 | tail -30\n";
    expect(laBaseEtLeCacheSontHorsDePorteeDuDeploiement($toutePile))->toBeFalse();
    expect(invocationsUpAvecServices($toutePile)[0]['services'])->toBe(
        [],
        '`up -d 2>&1 | tail -30` ne nomme AUCUN service : si le balayage y lit « 2 » ou « tail », '
        . "il croira que la commande cible un service precis et manquera le fait qu'elle recree "
        . 'TOUTE la pile de production.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// LES CONSTATS
// ═══════════════════════════════════════════════════════════════════════════

test('F38-003 / F40-004 — toute pile recreee par un workflow est mesuree sur ses ports REELS', function () {
    $sansMesure = [];
    $mauvaisProjet = [];

    foreach (pilesPiloteesParLesWorkflows() as $relatif => $projetAttendu) {
        $chemin = racineDepotGardePorts() . '/' . $relatif;
        if (! is_file($chemin)) {
            continue; // le temoin de montage l'a deja dit
        }

        $appels = appelsGardeDesPorts((string) file_get_contents($chemin));

        if ($appels === []) {
            $sansMesure[] = "{$relatif}  (pile « {$projetAttendu} »)";

            continue;
        }

        $projets = array_column($appels, 'projet');
        if (! in_array($projetAttendu, $projets, true)) {
            $mauvaisProjet[] = "{$relatif} mesure « " . implode(', ', $projets)
                . " » au lieu de « {$projetAttendu} »";
        }
    }

    expect($sansMesure)->toBe(
        [],
        'Ces workflows RECREENT une pile Docker sur un serveur et ne mesurent JAMAIS les ports '
        . "qu'elle publie reellement :\n  - " . implode("\n  - ", $sansMesure) . "\n\n"
        . "`config-prod` prouve que le FICHIER fusionne est juste. Il l'etait deja le 19/08, "
        . 'pendant que Postgres repondait sur 0.0.0.0:55432 devant 4 295 349 fiches et que Redis, '
        . 'SANS mot de passe, acceptait FLUSHALL. Entre le fichier et le reel il y a un '
        . "deploiement qui, pour postgres et redis, ne fait rien.\n\n"
        . "Correctif : apres le bloc de sante des conteneurs,\n"
        . "    if ! bash \"\$PROJECT_PATH/infra/scripts/verifier-ports-publies.sh\" axion-crm-pro; then\n"
        . "      echo \"::error::…\" ; exit 1\n"
        . "    fi\n",
    );

    expect($mauvaisProjet)->toBe(
        [],
        'Un appel mesure la mauvaise pile. Le script rend `exit 2` sur un projet inexistant '
        . "(« la mesure n'a rien vu ») -- mais un projet qui EXISTE et n'est pas celui qu'on croit "
        . "rendrait un vert parfaitement trompeur.\n  - " . implode("\n  - ", $mauvaisProjet),
    );
});

test('F38-003 / F40-004 — aucun appel de la garde des ports n a son code de retour avale', function () {
    $avales = [];

    foreach (array_keys(pilesPiloteesParLesWorkflows()) as $relatif) {
        $chemin = racineDepotGardePorts() . '/' . $relatif;
        if (! is_file($chemin)) {
            continue;
        }

        foreach (appelsGardeDesPorts((string) file_get_contents($chemin)) as $appel) {
            if ($appel['avalee']) {
                $avales[] = "{$relatif}:{$appel['ligne']}  ->  {$appel['texte']}";
            }
        }
    }

    expect($avales)->toBe(
        [],
        "Ces appels neutralisent le code de retour de la garde des ports.\n\n"
        . "Le `||` retombe sur une commande qui reussit toujours : l'echec disparait, et avec lui "
        . "le `exit 2` « aucun conteneur trouve, la mesure n'a rien vu » -- qui est le temoin "
        . 'INTERNE du script, celui qui refuse de rassurer sur du vide. Un `|| echo` ne degrade '
        . "pas cette garde : il l'annule.\n\n"
        . "Ecrire `|| ok=0` (variable evaluee ensuite) ou laisser l'appel nu sous `set -e`.\n\n"
        . "Appels avales :\n  - " . implode("\n  - ", $avales),
    );
});

test('F38-003 — la preproduction evalue bien la variable que son appel alimente', function () {
    $chemin = racineDepotGardePorts() . '/.github/workflows/deploy-staging.yml';
    $contenu = (string) file_get_contents($chemin);

    $alimenteOk = false;
    foreach (appelsGardeDesPorts($contenu) as $appel) {
        if (str_contains($appel['texte'], 'ok=0')) {
            $alimenteOk = true;
        }
    }

    // Si l'appel passe par `|| ok=0`, alors l'evaluation de `ok` DOIT exister,
    // sinon la variable ne sert a rien et l'echec est avale autrement.
    if ($alimenteOk) {
        $this->assertStringContainsString(
            '[ "$ok" -eq 1 ]',
            $contenu,
            "L'appel de la garde des ports alimente `ok=0`, mais `deploy-staging.yml` n'evalue "
            . "plus `ok`. La variable ne sert alors a rien : l'echec est avale par un autre "
            . 'chemin, et le job reste vert.',
        );
    }

    // Et dans tous les cas, la preproduction doit passer une liste de ports
    // autorises VIDE : ses deux publications sont sur 172.17.0.1, une adresse
    // de passerelle Docker non routable. Passer « 8081 8082 » ferait rougir le
    // temoin positif du script sur une pile parfaitement conforme.
    $this->assertStringContainsString(
        'verifier-ports-publies.sh" axion-crm-staging ""',
        $contenu,
        'La preproduction doit etre mesuree avec une liste de ports publics autorises VIDE. '
        . '⚠️ Le script lit `${2-80 443}` SANS les deux-points : un second argument vide vaut '
        . '« rien n est autorise », alors qu avec les deux-points il serait retombe sur '
        . '« 80 443 » et aurait annonce « ports attendus absents : 80 443 » sur une pile saine.',
    );
});

test('F40-005 — le deploiement de production peut appliquer un changement sur postgres et redis', function () {
    $horsDePortee = [];

    foreach (array_keys(pilesPiloteesParLesWorkflows()) as $relatif) {
        $chemin = racineDepotGardePorts() . '/' . $relatif;
        if (! is_file($chemin)) {
            continue;
        }

        if (laBaseEtLeCacheSontHorsDePorteeDuDeploiement((string) file_get_contents($chemin))) {
            $horsDePortee[] = $relatif;
        }
    }

    expect($horsDePortee)->toBe(
        [],
        'PIEGE 18 -- dans ces fichiers, tous les `docker compose up` nomment leurs services avec '
        . "`--no-deps`, et aucun ne couvre `postgres` ni `redis` :\n  - "
        . implode("\n  - ", $horsDePortee) . "\n\n"
        . 'Consequence jamais tiree : une modification de `docker-compose*.yml` portant sur '
        . '`postgres`, `redis` ou `reverb` est INAPPLICABLE par ce deploiement. Ces conteneurs '
        . "gardent la configuration de leur creation, indefiniment. C'est dans cet ecart qu'une "
        . "base de 4 295 349 fiches est restee ouverte sur internet du 17/05 au 19/08.\n\n"
        . 'Le correctif existe deja, ecrit pour la PREPRODUCTION le 19/08 et jamais retroporte '
        . "(`deploy-staging.yml:143`). Ajouter, apres le `up` des services applicatifs :\n"
        . "    docker compose up -d --no-deps postgres redis\n\n"
        . '`up -d` SANS `--force-recreate` ne recree que si la configuration fusionnee a '
        . "reellement change : aucune interruption dans le cas courant.\n"
        . '⚠️ `reverb` reste VOLONTAIREMENT absent : il est arrete depuis 5 semaines, Echo est '
        . "desactive cote frontend et le Caddyfile n'a aucune route WebSocket vers lui. "
        . "L'ajouter le DEMARRERAIT.",
    );
});

test('F38-002 — le mode d emploi de la protection de branche existe et ne prescrit aucun contexte fantome', function () {
    $chemin = racineDepotGardePorts() . '/' . cheminModeEmploiProtection();

    expect(is_file($chemin))->toBeTrue(
        cheminModeEmploiProtection() . " est absent.\n\n"
        . "La protection de la branche `main` n'exige que 4 contextes sur 36 jobs : "
        . '`config-prod`, `caddyfile-valide`, `scripts-executables` et `axe-core Playwright` '
        . "n'en font pas partie. Les gardes nees des deux incidents les plus graves du produit "
        . 'peuvent donc rougir SANS empecher la fusion -- mesure : la PR #186 a fusionne le '
        . "2026-08-19 a 09:15:13, alors que son `Container scan (Trivy)` n'a rendu son verdict "
        . "qu'a 09:17.\n\n"
        . "Cela se regle par l'API GitHub, PAS dans le depot : ce depot ne peut pas le corriger. "
        . "Il peut en revanche porter l'instruction que l'exploitant doit suivre. Sans ce "
        . 'fichier, le constat F38-002 ne laisse aucune trace actionnable.',
    );

    $contenu = (string) file_get_contents($chemin);
    $prescrits = contextesPrescritsParLeModeEmploi($contenu);

    // TEMOIN : un mode d'emploi qui ne prescrit rien ne vaut rien.
    expect(count($prescrits))->toBeGreaterThanOrEqual(
        4,
        'Le mode d emploi ne prescrit que ' . count($prescrits) . ' contexte(s) entre les balises '
        . '`CONTEXTES-REQUIS:DEBUT` / `CONTEXTES-REQUIS:FIN`. Soit les balises ont disparu, soit '
        . "la liste est vide : dans les deux cas il n'y a plus rien a suivre.",
    );

    $nomsDeJobs = array_map('repliSansAccent', nomsDesJobsDeTousLesWorkflows());

    // TEMOIN DE MESURE : si l'extraction des noms de job rend peu de chose, la
    // comparaison ci-dessous conclurait « tous fantomes » ou « aucun fantome »
    // selon le hasard, sans rien mesurer.
    expect(count($nomsDeJobs))->toBeGreaterThanOrEqual(
        20,
        'Seulement ' . count($nomsDeJobs) . ' noms de job extraits des workflows : le motif '
        . 'd extraction ne voit pas ce qu il croit voir, et la verification des contextes ne '
        . 'prouve rien.',
    );

    // TEMOIN NEGATIF : un nom fantome doit etre reconnu comme fantome.
    expect(in_array(repliSansAccent('Contexte qui n existe nulle part'), $nomsDeJobs, true))->toBeFalse();

    $fantomes = [];
    foreach ($prescrits as $contexte) {
        // Un job de matrice rend « Nom (valeurs) » : on compare sur le nom de
        // base, qui est ce que le YAML declare.
        if (! in_array(repliSansAccent($contexte), $nomsDeJobs, true)) {
            $fantomes[] = $contexte;
        }
    }

    expect($fantomes)->toBe(
        [],
        "Le mode d emploi prescrit des contextes qu'AUCUN job ne declare :\n  - "
        . implode("\n  - ", $fantomes) . "\n\n"
        . "GitHub attend INDEFINIMENT un contexte requis qui n'arrive jamais : poser l'un de "
        . 'ces noms dans `required_status_checks.contexts` figerait `main` pour de bon, et le '
        . "seul moyen d'en sortir serait de desactiver la protection -- c'est-a-dire de faire "
        . "l'inverse de ce que ce constat demande.\n\n"
        . "Le nom d'un contexte est le `name:` du job, pas son identifiant.",
    );
});
