<?php

/**
 * GARDE : LE CONTROLE PINT DE LA CI VOIT-IL VRAIMENT TOUT LE BACKEND, ET DIT-IL
 * LA VERITE SUR SA PROPRE DETTE ? — constats H46-007 (S3) et H46-006 (S3).
 *
 * ── H46-007 : L ANGLE MORT DU PATHSPEC ────────────────────────────────────
 *
 * Le pas « Pint — format des fichiers PHP modifies » listait les fichiers avec
 *
 *     git diff --name-only ... -- 'backend/**\/*.php'
 *
 * SANS la magie `:(glob)`. Sans elle, git n applique pas la semantique
 * « `**` = zero ou plusieurs repertoires » : `**` n est qu un `*` ordinaire, et
 * le `/` qui le suit doit exister. Mesure du 2026-08-22, en lecture seule :
 *
 *     git ls-files -- 'backend/tests/**\/*.php'   → 194 fichiers
 *     backend/tests en contient                    → 197
 *
 * les trois absents etant exactement ceux poses DIRECTEMENT sous le dossier
 * (Pest.php, TestCase.php, bootstrap.php). Le meme trou existait donc pour tout
 * `.php` pose a la racine de `backend/`. Aucun ne s y trouve aujourd hui : le
 * defaut etait LATENT, et un defaut latent dans un gate est le pire des deux —
 * il ne se manifeste que le jour ou il laisse passer quelque chose.
 *
 * ── H46-006 : LE NOMBRE FIGE DANS L EN-TETE ───────────────────────────────
 *
 * L en-tete annoncait « le depot compte 276 fichiers non formates ». La mesure
 * d audit du 2026-08-19 en donnait 174, et le commit 32f6f46 du 2026-08-21 en a
 * reformate 95 de plus : le chiffre etait faux d au moins deux generations. Un
 * nombre fige dans un commentaire perime sans que rien ne rougisse. On y ecrit
 * desormais le GESTE qui le mesure, pas le resultat — et cette garde refuse
 * qu un nouveau nombre y soit reinscrit.
 *
 * ⚠️ PIEGE DE BANC, deja documente par `DeploiementsDependentDeLaCiTest` : dans
 * le conteneur de tests, `/var/www/.github` est une COPIE, pas un montage, et
 * elle peut etre perimee. En CI la garde lit le vrai arbre.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function cheminCiH46(): string
{
    return (realpath(base_path('..')) ?: base_path('..')) . '/.github/workflows/ci.yml';
}

/**
 * Le `run:` du pas Pint du job `backend`.
 *
 * On passe par le YAML plutot que par un `grep` sur le texte : un `grep`
 * trouverait le pathspec cite dans un commentaire (il l est, juste au-dessus)
 * et rendrait un vert sur une phrase au lieu d une commande.
 */
function scriptDuPasPintH46(): string
{
    /** @var array<string, mixed> $ci */
    $ci = (array) Yaml::parseFile(cheminCiH46());
    /** @var array<string, mixed> $jobs */
    $jobs = is_array($ci['jobs'] ?? null) ? $ci['jobs'] : [];
    /** @var array<int, array<string, mixed>> $pas */
    $pas = is_array($jobs['backend']['steps'] ?? null) ? $jobs['backend']['steps'] : [];

    foreach ($pas as $etape) {
        $nom = is_array($etape) ? (string) ($etape['name'] ?? '') : '';
        if (str_starts_with($nom, 'Pint')) {
            return (string) ($etape['run'] ?? '');
        }
    }

    return '';
}

/**
 * Les lignes de COMMANDE du pas Pint, commentaires shell retires.
 *
 * ⚠️ Sans ce filtrage, la garde se mentirait a elle-meme : le pas porte un
 * commentaire qui CITE la forme fautive (`'backend/**\/*.php'`) pour expliquer
 * pourquoi elle a ete abandonnee. Une garde qui lit un commentaire au lieu
 * d une commande certifie ce qu elle n inspecte pas.
 */
function lignesActivesDuPasPintH46(string $script): string
{
    // `\R` et non `PHP_EOL` : le decoupage doit tenir quel que soit le systeme
    // qui joue la garde, et non celui qui l a ecrite.
    $lignes = preg_split('/\R/', $script) ?: [];

    $actives = array_filter(
        $lignes,
        static fn (string $ligne): bool => ! str_starts_with(ltrim($ligne), '#'),
    );

    return implode("\n", $actives);
}

/**
 * Ce que git fait REELLEMENT d un pathspec sans magie `:(glob)` : fnmatch sans
 * FNM_PATHNAME, ou `*` traverse les `/` mais ou chaque `/` litteral du motif
 * doit se retrouver dans le chemin. C est la meme semantique que celle qui a
 * cache trois fichiers a la mesure du 2026-08-22.
 */
function pathspecSansGlobAttrapeH46(string $motif, string $chemin): bool
{
    return fnmatch($motif, $chemin);
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOIN — le defaut existe-t-il vraiment, et sait-on le voir ?
// ─────────────────────────────────────────────────────────────────────────────

test('H46-007 — TEMOIN : sans :(glob), le motif backend/**/*.php rate les fichiers poses a la racine de backend/', function () {
    // Le cas qui marchait, et qui masquait le trou.
    expect(pathspecSansGlobAttrapeH46('backend/**/*.php', 'backend/app/Models/Company.php'))->toBeTrue();

    // LE TROU. Trois fichiers reels de ce depot, sous backend/tests/ :
    expect(pathspecSansGlobAttrapeH46('backend/tests/**/*.php', 'backend/tests/Pest.php'))->toBeFalse();
    expect(pathspecSansGlobAttrapeH46('backend/tests/**/*.php', 'backend/tests/TestCase.php'))->toBeFalse();
    expect(pathspecSansGlobAttrapeH46('backend/tests/**/*.php', 'backend/tests/bootstrap.php'))->toBeFalse();

    // Et le meme trou un cran plus haut, celui que le pas Pint avait :
    expect(pathspecSansGlobAttrapeH46('backend/**/*.php', 'backend/artisan.php'))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// LES GARDES
// ─────────────────────────────────────────────────────────────────────────────

test('H46-007 — le pathspec du pas Pint porte la magie :(glob)', function () {
    $script = lignesActivesDuPasPintH46(scriptDuPasPintH46());

    // TEMOIN DE NON-VACUITE : sans pas Pint lu, tout ce qui suit serait un vert
    // a vide — exactement le defaut que cette campagne poursuit.
    $this->assertNotSame('', trim($script), sprintf(
        'Aucun pas dont le nom commence par « Pint » dans le job `backend` de %s. '
        . 'La garde H46-007 n a rien inspecte : verifier le nom du pas, ou le chemin lu.',
        cheminCiH46(),
    ));

    $this->assertTrue(
        str_contains($script, "':(glob)backend/**/*.php'"),
        sprintf(
            "Le pas Pint ne filtre plus sur `':(glob)backend/**/*.php'`.\n\n"
            . "Sans la magie `:(glob)`, git traite `**` comme un `*` ordinaire et EXIGE un\n"
            . "segment de repertoire : tout `.php` pose directement sous `backend/` echappe au\n"
            . "controle de format, sans que rien ne le dise (constat H46-007). Mesure du\n"
            . "2026-08-22 : `git ls-files -- 'backend/tests/**/*.php'` rend 194 fichiers pour\n"
            . "197 presents, les 3 absents etant ceux de la racine du dossier.\n\n"
            . "GESTE : dans le pas « Pint — format des fichiers PHP modifies »,\n"
            . "  git diff --name-only --diff-filter=ACMR \"\$base\"...HEAD -- ':(glob)backend/**/*.php'\n\n"
            . "Script lu :\n%s",
            $script,
        ),
    );

    // Et la forme fautive ne doit pas reapparaitre ailleurs dans le meme script
    // (par exemple sur un second `git diff` ajoute plus tard).
    $this->assertFalse(
        (bool) preg_match('/(?<!\\(glob\\))backend\\/\\*\\*\\/\\*\\.php/', str_replace(':(glob)backend/**/*.php', '', $script)),
        'Le pathspec sans magie `backend/**/*.php` est revenu dans le pas Pint. '
        . 'Il rate les fichiers poses a la racine de backend/ (H46-007) : le prefixer de `:(glob)`.',
    );
});

test('H46-006 — l en-tete de ci.yml n annonce plus un nombre fige de fichiers non formates', function () {
    $texte = (string) file_get_contents(cheminCiH46());

    // TEMOIN DE NON-VACUITE : on lit bien l en-tete Pint, pas un fichier vide.
    $this->assertTrue(
        str_contains($texte, 'fichiers non format'),
        'L en-tete de ci.yml ne parle plus du tout de la dette de format Pint : la garde '
        . 'H46-006 n a plus rien a inspecter. Si le paragraphe a ete deplace, deplacer la garde avec.',
    );

    $trouve = [];
    preg_match_all('/\b(\d+)\s+fichiers?\s+non\s+format/ui', $texte, $trouve);

    $this->assertSame([], $trouve[1], sprintf(
        "L en-tete de ci.yml reinscrit un nombre fige de fichiers non formates : %s.\n\n"
        . "Un chiffre dans un commentaire perime en silence — celui-ci annoncait 276 quand la\n"
        . "mesure du 2026-08-19 en donnait 174, et le commit 32f6f46 du 2026-08-21 en a\n"
        . "reformate 95 de plus (constat H46-006). Personne ne rougit quand un commentaire ment.\n\n"
        . 'GESTE : ecrire le GESTE qui mesure, pas le resultat — `cd backend && '
        . './vendor/bin/pint --test | tail -1`.',
        implode(', ', $trouve[1]),
    ));
});
