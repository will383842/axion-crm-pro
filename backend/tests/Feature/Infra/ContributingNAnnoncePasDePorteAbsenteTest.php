<?php

/**
 * GARDE : CONTRIBUTING.md N ANNONCE PAS DE PORTE QUE PERSONNE NE TIENT
 * — constat A09-006 (S2).
 *
 * CE QUI ETAIT MESURE, LE 2026-08-22.
 *
 * `CONTRIBUTING.md`, sous le titre « ## Quality gates », listait :
 *
 *     - Pest backend >= 75 % couverture sur services metier
 *     - Vitest frontend >= 60 % couverture (config dans vitest.config.ts)
 *
 * Or **la CI ne mesure la couverture nulle part** : le setup PHP porte
 * `coverage: none`, le pas Pest lance `pest --configuration phpunit-ci.xml`
 * sans option de couverture, et les deux pas frontend lancent `pnpm test`,
 * jamais `pnpm test:coverage`. `frontend/vitest.config.ts` l ecrit lui-meme
 * au-dessus de ses seuils : « SEUILS DECORATIFS EN L ETAT […] ces nombres ne
 * bloquent rien et n ont jamais rien bloque ».
 *
 * POURQUOI C EST PIRE QUE DE NE RIEN ANNONCER. Une revue qui lit « >= 75 % de
 * couverture » en conclut que le risque est couvert et cesse de le regarder.
 * C est la meme lecon que le paragraphe « Verite des gates » du projet voisin :
 * un document qui promet une porte inexistante fabrique une fausse securite.
 *
 * CETTE GARDE A DEUX FACES, ET LA SECONDE COMPTE AUTANT.
 *
 * 1. Le document ne remet pas ces seuils sous « Quality gates ».
 * 2. La CI ne s est pas mise, entre-temps, a MESURER la couverture. Si elle le
 *    fait un jour, cette garde rougit pour dire l inverse : la porte existe, le
 *    document peut de nouveau l annoncer. Sans cette seconde face, la garde
 *    interdirait pour toujours une phrase devenue vraie.
 *
 * ⚠️ PIEGE DE BANC, deja documente par `DeploiementsDependentDeLaCiTest` : dans
 * le conteneur de tests, `/var/www/.github` est une COPIE, pas un montage.
 */

use Tests\TestCase;

uses(TestCase::class);

function racineDepotA09(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Le corps d une section de titre Markdown `## <titre>`, jusqu au titre suivant
 * de meme niveau ou de niveau superieur.
 */
function sectionMarkdownA09(string $document, string $titre): ?string
{
    $lignes = preg_split('/\R/', $document) ?: [];
    $dedans = false;
    $corps = [];

    foreach ($lignes as $ligne) {
        if (preg_match('/^#{1,2}\s+(.*)$/', $ligne, $trouve) === 1) {
            if ($dedans) {
                break;
            }
            $dedans = trim($trouve[1]) === $titre;

            continue;
        }
        if ($dedans) {
            $corps[] = $ligne;
        }
    }

    return $dedans || $corps !== [] ? implode("\n", $corps) : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOIN — le decoupage voit-il bien la bonne section, et rien qu elle ?
// ─────────────────────────────────────────────────────────────────────────────

test('A09-006 — TEMOIN : la section lue s arrete au titre suivant', function () {
    $document = "# Titre\n\n## Quality gates\n\n- PHPStan level 8\n\n## Autre\n\n- couverture 99 %\n";

    $section = (string) sectionMarkdownA09($document, 'Quality gates');

    expect(str_contains($section, 'PHPStan level 8'))->toBeTrue();
    expect(str_contains($section, 'couverture 99 %'))->toBeFalse();
    expect(sectionMarkdownA09($document, 'Section absente'))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// LA GARDE
// ─────────────────────────────────────────────────────────────────────────────

test('A09-006 — CONTRIBUTING.md ne presente pas la couverture comme une porte tant que la CI ne la mesure pas', function () {
    $racine = racineDepotA09();
    $cheminDoc = $racine . '/CONTRIBUTING.md';
    $cheminCi = $racine . '/.github/workflows/ci.yml';

    // TEMOINS DE NON-VACUITE : sans les deux fichiers, tout ce qui suit serait
    // un vert obtenu sans rien lire.
    $this->assertFileExists($cheminDoc, 'CONTRIBUTING.md introuvable : la garde A09-006 n aurait rien inspecte. Racine lue : ' . $racine);
    $this->assertFileExists($cheminCi, 'ci.yml introuvable : la garde A09-006 ne peut pas verifier si la couverture est mesuree. Racine lue : ' . $racine);

    $ci = (string) file_get_contents($cheminCi);

    // ── FACE 2 : l etat de la CI, mesure et non suppose ──────────────────────
    // `coverage: none` sur le setup PHP, et aucun `test:coverage` cote frontend.
    $ciMesureLaCouverture = ! str_contains($ci, 'coverage: none') || str_contains($ci, 'test:coverage');

    if ($ciMesureLaCouverture) {
        $this->fail(
            'La CI s est mise a mesurer la couverture (plus de `coverage: none`, ou un '
            . "`pnpm test:coverage`).\n\nC est une bonne nouvelle, et cette garde a fait son "
            . "temps : la porte annoncee par CONTRIBUTING.md peut redevenir une porte.\n\n"
            . 'GESTE : verifier que les seuils sont bien BLOQUANTS (pas `continue-on-error`), '
            . 'remonter les deux lignes de couverture sous « Quality gates », et retirer ce '
            . 'test avec la section « Objectifs NON gardes ».',
        );
    }

    // ── FACE 1 : le document ─────────────────────────────────────────────────
    $section = sectionMarkdownA09((string) file_get_contents($cheminDoc), 'Quality gates');

    $this->assertNotNull($section, 'CONTRIBUTING.md n a plus de section « ## Quality gates » : la garde A09-006 ne sait plus ou regarder. Si la section a ete renommee, renommer aussi ici.');

    $this->assertFalse(
        str_contains(mb_strtolower((string) $section), 'couverture'),
        'La section « Quality gates » de CONTRIBUTING.md annonce de nouveau un seuil de '
        . "COUVERTURE.\n\n"
        . 'Mesure du 2026-08-22, dans .github/workflows/ci.yml : le setup PHP porte '
        . '`coverage: none`, le pas Pest n a aucune option de couverture, et les pas frontend '
        . 'lancent `pnpm test`, jamais `pnpm test:coverage`. Aucune de ces deux valeurs n a '
        . 'jamais rougi une PR — `frontend/vitest.config.ts` le dit lui-meme : « SEUILS '
        . "DECORATIFS EN L ETAT ».\n\n"
        . 'Annoncer une porte qui n existe pas est PIRE que n en annoncer aucune : une revue '
        . "qui lit le seuil cesse de regarder le risque (constat A09-006).\n\n"
        . 'GESTE : remettre ces lignes sous « ## Objectifs NON gardes — personne ne les '
        . 'mesure ». Pour en faire une vraie porte — decision de Will, pas un correctif : '
        . 'mesurer d abord la couverture reelle, poser le seuil A CETTE VALEUR, puis le faire '
        . "decroitre-vers-le-haut comme la baseline PHPStan.\n\nSection lue :\n" . (string) $section,
    );
});
