<?php

/**
 * GARDE : DEUX FICHIERS DE TEST NE PEUVENT PAS DÉCLARER LA MÊME FONCTION GLOBALE.
 *
 * 🔴 CE QUI EST ARRIVÉ, LE 2026-08-21.
 *
 * Deux agents travaillant en parallèle sur deux lots sans rapport ont chacun
 * écrit, dans leur propre fichier de test, une aide nommée
 * `codeSansCommentaires()`. L'une retire les `#` d'un script shell, l'autre les
 * commentaires PHP : deux fonctions différentes, un seul nom.
 *
 * Pris séparément, **les deux fichiers sont verts**. Joués ensemble, la campagne
 * meurt :
 *
 *     ERROR  Fatal error: Cannot redeclare codeSansCommentaires()
 *     (previously declared in tests/Feature/Infra/IndexEmailRgpdServentLesRequetesTest.php:264)
 *     in tests/Feature/Infra/SauvegardeEmporteLesExtensionsTest.php on line 246
 *
 * Ce n'est pas « un test rouge » : c'est **toute la suite qui n'a pas de
 * résultat**. Aucun compte, aucun verdict, rien à commiter. Et le message
 * n'accuse ni l'un ni l'autre — il accuse leur rencontre.
 *
 * ⚠️ POURQUOI UNE GARDE, ET PAS SEULEMENT UNE CONSIGNE.
 *
 * Parce que le défaut n'est visible d'**aucun des deux points de vue**. L'agent
 * qui écrit le second fichier ne peut pas savoir que le nom est pris : il ne l'a
 * pas lu, et rien ne le lui dira tant qu'il joue son fichier seul — ce que la
 * borne de temps de dix minutes l'encourage précisément à faire.
 *
 * *Le savoir ne suffit pas : il faut la règle mécanique.* C'est la même leçon
 * que `AucunMessageDansToContainTest`, payée une deuxième fois, sur un autre
 * objet.
 *
 * Pest place les fonctions déclarées dans un fichier de test dans l'espace de
 * noms GLOBAL, pour toute la durée de la campagne. Il n'y a pas de portée par
 * fichier, et il n'y en aura pas.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * Les fonctions globales déclarées par les fichiers de test, par nom.
 *
 * On lit `^function nom(` en début de ligne : c'est la seule forme qui déclare
 * dans l'espace global. Une méthode de classe est indentée, une fermeture n'a
 * pas de nom, un `function` dans un commentaire de bloc porte une étoile devant.
 *
 * @return array<string, list<string>>
 */
function fonctionsGlobalesDesTests(?string $racine = null): array
{
    $racine ??= base_path('tests');
    $parNom = [];

    if (! is_dir($racine)) {
        return $parNom;
    }

    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));

    foreach ($fichiers as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());

        // On retire les commentaires AVANT de compter : un docbloc qui explique
        // « function machin() » n'est pas une déclaration. Le tokeniseur de PHP
        // sait où finit un commentaire ; une expression régulière ne le sait pas.
        $code = '';
        foreach (token_get_all($source) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        preg_match_all('/^function\s+(\w+)\s*\(/m', $code, $trouvees);

        foreach ($trouvees[1] as $nom) {
            $parNom[$nom][] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $fichier->getPathname());
        }
    }

    return $parNom;
}

test('AUCUNE fonction globale n est declaree par deux fichiers de test', function () {
    $parNom = fonctionsGlobalesDesTests();

    // TEMOIN DE COUVERTURE : sans lui, un chemin faux rendrait zero fonction et
    // « aucune collision » serait vrai pour la mauvaise raison. Le pire des verts.
    expect(count($parNom))->toBeGreaterThan(
        200,
        'Seulement ' . count($parNom) . ' fonctions globales vues, contre 443 relevees le '
        . "2026-08-21 : le balayage ne voit pas ce qu'il croit voir.",
    );

    $collisions = [];
    foreach ($parNom as $nom => $fichiers) {
        $distincts = array_values(array_unique($fichiers));
        if (count($distincts) > 1) {
            $collisions[] = $nom . '()  ->  ' . implode('  ET  ', $distincts);
        }
    }

    expect($collisions)->toBe(
        [],
        'Deux fichiers de test declarent la MEME fonction globale. Joues separement ils sont '
        . "verts ; joue ensemble, la campagne meurt sur « Cannot redeclare », et il n'y a alors "
        . "AUCUN resultat — ni compte, ni verdict.\n\n"
        . "Renommer l'une des deux, en donnant au nom ce qu'il fait ET sur quoi (par exemple "
        . '`shellSansDieses` plutot que `codeSansCommentaires`), ou la remonter dans '
        . "`tests/Support/` si les deux fichiers en ont vraiment besoin.\n\nCollisions :\n  - "
        . implode("\n  - ", $collisions),
    );
});

test('TEMOIN NEGATIF : le detecteur VOIT une collision quand il y en a une', function () {
    // Sans ce cas, le test ci-dessus pourrait rendre « aucune collision » sur
    // n'importe quoi. On fabrique deux fichiers qui se marchent dessus, et on
    // exige que l'instrument les trouve.
    $bac = sys_get_temp_dir() . '/a35-collisions-' . bin2hex(random_bytes(4));
    mkdir($bac . '/sous', 0o777, true);

    file_put_contents($bac . '/un.php', "<?php\nfunction memeNom(): int { return 1; }\n");
    file_put_contents($bac . '/sous/deux.php', "<?php\nfunction memeNom(): int { return 2; }\n");

    // Et une DECLARATION EN COMMENTAIRE, qui ne doit PAS compter : c'est
    // exactement la faute qui rendrait cette garde insupportable, en accusant
    // les docblocs qui expliquent une aide.
    file_put_contents(
        $bac . '/trois.php',
        "<?php\n/**\n * function memeNom() est expliquee ici, pas declaree.\n */\nfunction autreNom(): int { return 3; }\n",
    );

    $parNom = fonctionsGlobalesDesTests($bac);

    try {
        expect(array_keys($parNom))->toContain('memeNom');
        expect(count(array_unique($parNom['memeNom'] ?? [])))->toBe(
            2,
            'Le detecteur ne voit pas les deux declarations concurrentes : il ne peut donc pas '
            . 'garder quoi que ce soit.',
        );
        expect(count(array_unique($parNom['autreNom'] ?? [])))->toBe(
            1,
            'Le detecteur compte une declaration ECRITE DANS UN COMMENTAIRE : il rougirait sur '
            . 'les docblocs qui expliquent une aide, et quelqu un finirait par le desactiver.',
        );
    } finally {
        array_map('unlink', glob($bac . '/sous/*.php') ?: []);
        array_map('unlink', glob($bac . '/*.php') ?: []);
        @rmdir($bac . '/sous');
        @rmdir($bac);
    }
});

test('TEMOIN DE COUVERTURE : un chemin faux rend un balayage VIDE, pas un vert', function () {
    // Le complément du témoin chiffré : on prouve que le balayage rend bien vide
    // quand il ne voit rien, plutôt que de lever ou de rendre n'importe quoi.
    expect(fonctionsGlobalesDesTests(base_path('tests-qui-n-existe-pas')))->toBe([]);
});
