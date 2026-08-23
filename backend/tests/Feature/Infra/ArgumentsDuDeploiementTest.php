<?php

/**
 * LES ARGUMENTS DU DEPLOIEMENT ARRIVENT-ILS OU ON CROIT ?
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI S'EST PASSE LE 2026-08-23, ET QUI A COUTE TROIS DEPLOIEMENTS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * En ajoutant deux arguments a l'appel `ssh ... 'bash -s' -- ...`, un `\n`
 * LITTERAL — deux caracteres, pas un retour a la ligne — s'est glisse dans la
 * ligne :
 *
 *     "$EXPECTED_SHA" "$INSEE_API_KEY" \n            "$GH_TOKEN_LECTURE" ...
 *
 * Pour bash, `\n` hors guillemets est le mot `n`. Il est donc parti comme
 * ARGUMENT a part entiere, et tout ce qui suivait a GLISSE D'UN CRAN :
 *
 *     $5 = "n"                      <- lu comme le jeton GitHub
 *     $6 = <le jeton>               <- lu comme le nom du depot
 *
 * Le script a fabrique l'URL `https://x-access-token:n@github.com/<jeton>.git`
 * et GitHub a repondu « Not Found ». Rien dans le decalage ne se voyait : les
 * deux variables etaient NON VIDES, donc le garde-fou
 * `[ -n "$GH_TOKEN_LECTURE" ] && [ -n "$GH_DEPOT" ]` du script a laisse passer.
 *
 * 🔴 ET LE DIAGNOSTIC A ETE FAUX PENDANT DEUX TENTATIVES. Le premier echec
 * (« Invalid username or token ») a ete impute a la forme du
 * `credential.helper`, qui n'y etait pour rien : le jeton valait deja « n ».
 * Un mauvais diagnostic coute plus cher que le defaut lui-meme.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CETTE GARDE MESURE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * L'invariant est simple et il n'existait nulle part : **le nombre d'arguments
 * ENVOYES doit egaler le nombre d'arguments LUS**. Un decalage d'un cran est
 * invisible a la lecture et catastrophique a l'execution.
 *
 * On mesure aussi l'absence de `\n` litteral dans les blocs `run`, parce que
 * c'est la faute qui a produit le decalage — et qu'elle est indetectable a
 * l'oeil dans un fichier de 500 lignes.
 */

use Symfony\Component\Yaml\Yaml;

/** Le workflow, tel qu'il est sur le disque. */
function deploiementYaml(): array
{
    // ⚠️ PAS `base_path()`. Ce fichier ne monte pas l'application Laravel (il
    // n'a pas besoin d'une base), donc le conteneur n'est pas l'Application et
    // `base_path()` y leve « Call to undefined method Container::basePath() ».
    // Un chemin relatif au fichier marche partout — y compris sur le banc, ou
    // `backend/` est monte en `/var/www/html` et `.github/` en `/var/www/.github`.
    $chemin = dirname(__DIR__, 4) . '/.github/workflows/deploy-direct-ssh.yml';

    // Un fichier absent rendrait cette garde muette : on echoue plutot que
    // d'ignorer. Un test ignore est un vert deguise.
    test()->assertFileExists(
        $chemin,
        'Le workflow de deploiement est introuvable : cette garde ne mesurerait RIEN.',
    );

    return Yaml::parseFile($chemin);
}

/** Le bloc `run` de l'etape qui ouvre la connexion SSH. */
function blocDeploiement(): string
{
    foreach (deploiementYaml()['jobs']['deploy']['steps'] as $etape) {
        $script = $etape['run'] ?? '';
        if (str_contains($script, "'bash -s'")) {
            return $script;
        }
    }

    test()->fail("Aucune etape n'ouvre la connexion `bash -s` : le balayage ne voit pas ce qu'il croit voir.");
}

test('DEPLOIEMENT-ARGS — autant d arguments envoyes que lus', function (): void {
    $script = blocDeploiement();

    // ── ce qui est ENVOYE ──────────────────────────────────────────────────
    // La ligne `'bash -s' --` et sa suite, jusqu'au heredoc.
    $position = strpos($script, "'bash -s' --");
    expect($position)->not->toBeFalse();

    $apres = substr($script, $position + strlen("'bash -s' --"));
    $finHeredoc = strpos($apres, "<<'REMOTE'");
    expect($finHeredoc)->not->toBeFalse('Le heredoc `REMOTE` a disparu : la forme de l appel a change.');

    $listeArguments = substr($apres, 0, $finHeredoc);

    // 🔴 LE DEFAUT DU 2026-08-23, MESURE DIRECTEMENT. Un `\n` litteral part
    // comme le mot `n` et decale tout ce qui suit.
    expect(str_contains($listeArguments, '\\n'))->toBeFalse(
        "La liste d'arguments contient un `\\n` LITTERAL. Pour bash c'est le mot `n` : "
        . "il part comme argument et DECALE tout ce qui suit d'un cran.\n"
        . 'Ligne fautive : ' . trim($listeArguments),
    );

    preg_match_all('/"\$\{?[A-Z_]+\}?"/', $listeArguments, $envoyes);
    $nombreEnvoyes = count($envoyes[0]);

    // ── ce qui est LU ──────────────────────────────────────────────────────
    preg_match_all('/^\s*[A-Z_]+="\$\{?(\d+)(?::-)?\}?"/m', $script, $lus);
    $positionsLues = array_map('intval', $lus[1]);

    // TEMOIN : sans lecture vue, l'egalite finale serait vraie sur du neant.
    expect(count($positionsLues))->toBeGreaterThanOrEqual(
        4,
        'Moins de 4 arguments lus : le motif de lecture ne reconnait plus le script.',
    );

    $nombreLus = max($positionsLues);

    expect($nombreEnvoyes)->toBe(
        $nombreLus,
        "Le script SSH envoie {$nombreEnvoyes} arguments et en lit {$nombreLus}.\n"
        . "Un decalage d'un cran est INVISIBLE a la lecture et fatal a l'execution : "
        . 'le 2026-08-23, le serveur a recu le mot `n` comme jeton GitHub et le jeton '
        . "comme nom de depot.\n"
        . 'Envoyes : ' . implode(' ', $envoyes[0]),
    );

    // Et les positions lues doivent etre CONTIGUES depuis 1 : lire $1,$2,$3,$6
    // passerait le test du maximum tout en sautant deux arguments.
    sort($positionsLues);
    expect($positionsLues)->toBe(
        range(1, $nombreLus),
        'Les positions lues ne sont pas contigues depuis 1 : un argument est saute.',
    );
});

test('DEPLOIEMENT-ARGS — TEMOIN : la garde sait detecter un decalage', function (): void {
    // Sans ce temoin, la garde pourrait etre verte parce que ses motifs ne
    // reconnaissent plus rien — le defaut n. 1 de ce depot.
    $faux = <<<'FAUX'
        ssh host 'bash -s' -- "$A" "$B" "$C" <<'REMOTE'
        A="$1"
        B="$2"
        C="$3"
        D="${4:-}"
        REMOTE
        FAUX;

    $position = strpos($faux, "'bash -s' --");
    $apres = substr($faux, $position + strlen("'bash -s' --"));
    $liste = substr($apres, 0, strpos($apres, "<<'REMOTE'"));

    preg_match_all('/"\$\{?[A-Z_]+\}?"/', $liste, $envoyes);
    preg_match_all('/^\s*[A-Z_]+="\$\{?(\d+)(?::-)?\}?"/m', $faux, $lus);

    // 3 envoyes, 4 lus : le decalage DOIT etre visible.
    expect(count($envoyes[0]))->toBe(3);
    expect(max(array_map('intval', $lus[1])))->toBe(4);
});
