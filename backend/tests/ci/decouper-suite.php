<?php

/**
 * Decoupe la suite Pest en N parts pour la CI (job `backend`, matrice `part`).
 *
 * Usage (depuis backend/) :  php tests/ci/decouper-suite.php <part> <total>
 *
 * - liste les *Test.php de tests/Unit et tests/Feature, tries ;
 * - les repartit par taille de fichier (glouton : le plus gros d'abord, dans la
 *   part la moins chargee) — deterministe, sans dependance au hasard ;
 * - VERIFIE lui-meme que les parts ne se recoupent pas et couvrent tous les
 *   fichiers (sinon : exit 1, la CI rougit) ;
 * - ecrit phpunit-part.xml : copie de phpunit-ci.xml dont SEUL le bloc
 *   <testsuites> est remplace (le bloc <php> reste identique).
 *
 * Les fonctions sont reutilisees par tests/Unit/DecoupageSuiteCiTest.php.
 */

/**
 * @return array<string, int> chemin relatif a backend/ => taille en octets, trie par chemin
 */
function decoupageListerFichiers(string $racineBackend): array
{
    $fichiers = [];

    foreach (['tests/Unit', 'tests/Feature'] as $dossier) {
        $iterateur = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($racineBackend . '/' . $dossier, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterateur as $fichier) {
            if ($fichier->isFile() && str_ends_with($fichier->getFilename(), 'Test.php')) {
                $relatif = $dossier . '/' . substr($fichier->getPathname(), strlen($racineBackend . '/' . $dossier) + 1);
                $fichiers[str_replace('\\', '/', $relatif)] = (int) $fichier->getSize();
            }
        }
    }

    ksort($fichiers, SORT_STRING);

    return $fichiers;
}

/**
 * Fichiers COUPLES : ils partagent un etat defini dans l'un d'eux (ex. une
 * constante globale) et doivent rester dans la MEME part. Le decoupage a revele
 * ce couplage (CI du 2026-11-07) ; tant qu'il n'est pas defait dans les tests,
 * on le respecte ici plutot que d'affaiblir quoi que ce soit.
 *
 * @return list<list<string>>
 */
function decoupageGroupesIndissociables(): array
{
    return [
        // AuditHashChainTest lit SECRET_CHAINE_DE_TEST, defini par AuditHashChainExtendedTest.
        ['tests/Unit/Audit/AuditHashChainExtendedTest.php', 'tests/Unit/Audit/AuditHashChainTest.php'],
        // SequenceurConcurrenceTest ecrit pour de bon dans companies/contacts, hors
        // transaction (puis nettoie). EffacementServiParDesIndexTest depend de l'etat
        // de ces tables. Ils doivent toujours tourner dans la MEME part, sinon la CI
        // n'exerce jamais ce scenario (la repartition par taille les separerait).
        ['tests/Feature/Envoi/SequenceurConcurrenceTest.php', 'tests/Feature/Infra/EffacementServiParDesIndexTest.php'],
    ];
}

/**
 * Fichiers INCOMPATIBLES dans une meme part : le premier ecrit pour de bon en
 * base (hors transaction) et pollue les index que le second inspecte. Chaque
 * part CI a sa base PostgreSQL neuve : deux parts differentes = aucune pollution.
 * C'est un contournement d'isolation ; la pollution reste a corriger a la source.
 *
 * @return list<list<string>> couples de fichiers qui ne doivent JAMAIS partager une part
 */
function decoupageFichiersIncompatibles(): array
{
    return [
        // SequenceurConcurrenceTest ecrit dans companies/contacts ; EffacementServiParDesIndexTest
        // echoue (index « etranger a l'effacement ») s'il tourne apres lui dans la meme base.
        ['tests/Feature/Envoi/SequenceurConcurrenceTest.php', 'tests/Feature/Infra/EffacementServiParDesIndexTest.php'],
    ];
}

/**
 * @param  list<string>  $part  fichiers deja dans la part
 * @param  list<string>  $unite  fichiers a placer
 * @param  list<list<string>>  $incompatibles
 */
function decoupageContientIncompatible(array $part, array $unite, array $incompatibles): bool
{
    foreach ($incompatibles as $couple) {
        if (array_intersect($couple, $unite) !== [] && array_intersect($couple, $part) !== []) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<string, int>  $fichiers
 * @param  list<list<string>>|null  $incompatibles  par defaut : decoupageFichiersIncompatibles()
 * @return array<int, list<string>> numero de part (1..N) => fichiers
 */
function decoupageRepartir(array $fichiers, int $total, ?array $incompatibles = null): array
{
    $incompatibles ??= decoupageFichiersIncompatibles();
    $parts = [];
    $poids = [];
    for ($i = 1; $i <= $total; $i++) {
        $parts[$i] = [];
        $poids[$i] = 0;
    }

    // Unites de repartition : un fichier seul, ou un groupe indissociable present.
    $unites = [];
    $dansUnGroupe = [];
    foreach (decoupageGroupesIndissociables() as $groupe) {
        $presents = array_values(array_filter($groupe, fn (string $c) => isset($fichiers[$c])));
        if (count($presents) < 2) {
            continue;
        }
        $unites[] = $presents;
        foreach ($presents as $c) {
            $dansUnGroupe[$c] = true;
        }
    }
    foreach (array_keys($fichiers) as $chemin) {
        if (! isset($dansUnGroupe[$chemin])) {
            $unites[] = [$chemin];
        }
    }

    $taille = fn (array $unite): int => array_sum(array_map(fn (string $c) => $fichiers[$c], $unite));

    // La plus grosse d'abord ; a taille egale, ordre alphabetique (deterministe).
    usort($unites, fn (array $a, array $b) => [$taille($b), $a[0]] <=> [$taille($a), $b[0]]);

    foreach ($unites as $unite) {
        // Part la moins chargee parmi celles qui ne contiennent aucun fichier incompatible.
        $cible = null;
        for ($i = 1; $i <= $total; $i++) {
            if (decoupageContientIncompatible($parts[$i], $unite, $incompatibles)) {
                continue;
            }
            if ($cible === null || $poids[$i] < $poids[$cible]) {
                $cible = $i;
            }
        }
        if ($cible === null) {
            throw new RuntimeException('Aucune part compatible pour : ' . implode(', ', $unite) . ' (augmenter le nombre de parts).');
        }
        foreach ($unite as $chemin) {
            $parts[$cible][] = $chemin;
        }
        $poids[$cible] += max(1, $taille($unite));
    }

    foreach ($parts as $i => $liste) {
        sort($liste, SORT_STRING);
        $parts[$i] = $liste;
    }

    return $parts;
}

/**
 * @param  array<int, list<string>>  $parts
 * @param  list<string>  $attendus  tous les fichiers qui doivent etre joues
 * @return list<string> erreurs ; liste vide = partition correcte
 */
function decoupageVerifier(array $parts, array $attendus): array
{
    $erreurs = [];
    $vus = [];

    foreach ($parts as $numero => $liste) {
        if ($liste === []) {
            $erreurs[] = "La part {$numero} est vide.";
        }
        foreach ($liste as $chemin) {
            if (isset($vus[$chemin])) {
                $erreurs[] = "Recouvrement : {$chemin} est dans les parts {$vus[$chemin]} et {$numero}.";

                continue;
            }
            $vus[$chemin] = $numero;
        }
    }

    foreach ($attendus as $chemin) {
        if (! isset($vus[$chemin])) {
            $erreurs[] = "Fichier non couvert : {$chemin} n'est dans aucune part.";
        }
    }

    $inconnus = array_diff(array_keys($vus), $attendus);
    foreach ($inconnus as $chemin) {
        $erreurs[] = "Fichier inconnu : {$chemin} est dans une part mais pas dans la liste attendue.";
    }

    return $erreurs;
}

/**
 * Copie de phpunit-ci.xml dont SEUL le bloc <testsuites> change.
 *
 * @param  list<string>  $fichiers
 */
function decoupageGenererXml(string $xmlCi, array $fichiers): string
{
    $entrees = '';
    foreach ($fichiers as $chemin) {
        $entrees .= '            <file>' . htmlspecialchars($chemin, ENT_XML1) . "</file>\n";
    }
    $bloc = "<testsuites>\n        <testsuite name=\"Part\">\n" . $entrees . "        </testsuite>\n    </testsuites>";

    $compte = 0;
    $genere = preg_replace_callback(
        '~<testsuites>.*?</testsuites>~s',
        fn () => $bloc,
        $xmlCi,
        -1,
        $compte,
    );

    if ($genere === null || $compte !== 1) {
        throw new RuntimeException("phpunit-ci.xml : {$compte} bloc(s) <testsuites> trouve(s), exactement 1 attendu.");
    }

    return $genere;
}

function decoupageMain(array $argv): int
{
    $part = (int) ($argv[1] ?? 0);
    $total = (int) ($argv[2] ?? 0);
    if ($total < 1 || $part < 1 || $part > $total) {
        fwrite(STDERR, "Usage : php tests/ci/decouper-suite.php <part> <total>  (1 <= part <= total)\n");

        return 2;
    }

    $racine = dirname(__DIR__, 2);
    $fichiers = decoupageListerFichiers($racine);
    if ($fichiers === []) {
        fwrite(STDERR, "Aucun *Test.php trouve : decoupage impossible.\n");

        return 1;
    }

    $parts = decoupageRepartir($fichiers, $total);
    $erreurs = decoupageVerifier($parts, array_keys($fichiers));
    if ($erreurs !== []) {
        fwrite(STDERR, "Partition INVALIDE :\n  - " . implode("\n  - ", $erreurs) . "\n");

        return 1;
    }

    $xml = decoupageGenererXml((string) file_get_contents($racine . '/phpunit-ci.xml'), $parts[$part]);
    file_put_contents($racine . '/phpunit-part.xml', $xml);

    fwrite(STDOUT, sprintf("Part %d/%d : %d fichiers sur %d.\n", $part, $total, count($parts[$part]), count($fichiers)));

    return 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    exit(decoupageMain($_SERVER['argv']));
}
