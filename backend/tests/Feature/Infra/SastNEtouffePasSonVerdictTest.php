<?php

/**
 * GARDE : LE SAST N'ETOUFFE PLUS SON VERDICT — constat F38-013 (S2).
 *
 * CE QUI ETAIT LA, ET OU.
 *
 * Le job `semgrep` de `.github/workflows/security.yml` etait ceci :
 *
 *     - run: semgrep ci --config=... --sarif --output semgrep.sarif
 *       continue-on-error: true
 *     - uses: github/codeql-action/upload-sarif@v3
 *       if: always()
 *
 * Une directive `continue-on-error: true` SANS UN MOT, dans le fichier meme
 * dont le bloc `trivy`, treize lignes plus bas, explique pourquoi elle a ete
 * retiree de son voisin : « une vulnerabilite CRITICAL ou HIGH n'a JAMAIS pu
 * faire rougir une PR. Le resultat partait dans l'onglet Security, que personne
 * ne consulte. » Le SAST etait donc le dernier des trois a rendre un vert
 * inconditionnel — et le seul a le faire sans justification ecrite, dans un
 * fichier qui en exige une de tous les autres.
 *
 * CE QUE CETTE GARDE MESURE, ET CE QU'ELLE NE MESURE PAS.
 *
 * ✅ Elle mesure que le verdict est LU. Le code de sortie de `semgrep ci`
 *    discrimine : 0 = rien de bloquant, 1 = decouvertes, >= 2 = l'OUTIL a
 *    echoue. Ce dernier cas doit sortir en ROUGE — ne pas savoir n'est pas un
 *    feu vert, meme regle que le job `composer-audit`. C'est le coeur de la
 *    garde, et elle le mesure en EXECUTANT le script livre contre un faux
 *    `semgrep` dont on choisit le code de sortie, pas en relisant la prose du
 *    YAML : une garde qui verifie qu'une ligne existe prouve que la ligne
 *    existe, pas que le job rougirait.
 *
 * ⛔ Elle ne mesure PAS que les DECOUVERTES bloquent, parce qu'elles ne
 *    bloquent pas encore, et que c'est ecrit dans le workflow. Chaque garde de
 *    ce fichier de workflow a ete armee APRES une mesure ; le compte de
 *    decouvertes de `semgrep ci` sur `main` n'a jamais ete mesure. Le jour ou
 *    il le sera, l'armement consistera a rendre 1 sur `code = 1` — et cette
 *    garde ACCEPTE deja les deux formes sur ce cas precis, pour ne pas punir
 *    l'armement qu'elle reclame. Ce qu'elle interdit, c'est le silence : sur
 *    une decouverte, le journal doit dire quelque chose.
 */

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotF38013(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function jobSemgrepF38013(): array
{
    $chemin = racineDepotF38013() . '/.github/workflows/security.yml';

    Assert::assertFileExists(
        $chemin,
        'security.yml est introuvable. En local, la copie /var/www/.github du conteneur de banc '
        . 'se fait effacer par les autres agents : re-copier avant de croire un resultat.',
    );

    $job = Yaml::parseFile($chemin)['jobs']['semgrep'] ?? null;

    Assert::assertIsArray(
        $job,
        'Le job `semgrep` a disparu de security.yml : le depot n a plus aucune analyse statique. '
        . 'Si le retrait est voulu, retirer aussi ce fichier de garde ET la mention du job dans '
        . 'les `needs` du job `alerte` — sinon le workflow ne demarre plus.',
    );

    return $job;
}

/** Le script de l'etape qui invoque reellement `semgrep ci`. */
function scriptSemgrepF38013(): string
{
    foreach (jobSemgrepF38013()['steps'] ?? [] as $etape) {
        $run = $etape['run'] ?? null;
        if (is_string($run) && str_contains($run, 'semgrep ci')) {
            return $run;
        }
    }

    Assert::fail(
        'Aucune etape n invoque `semgrep ci` dans le job `semgrep` : le job existe mais '
        . 'n analyse plus rien. C est un vert qui ne mesure aucun code.',
    );
}

/**
 * Joue le script LIVRE contre un faux `semgrep` dont on choisit le code de sortie.
 *
 * @return array{0: string, 1: int} sortie melangee, code de sortie du script
 */
function joueScriptSemgrepF38013(string $script, int $codeDuFauxSemgrep): array
{
    $dir = sys_get_temp_dir() . '/f38013-' . bin2hex(random_bytes(6));
    mkdir($dir . '/bin', 0o777, true);

    // Le faux outil imite le vrai sur le seul point qui compte ici : il ecrit
    // le SARIF quand il a pu analyser (0 ou 1), et il n ecrit RIEN quand il a
    // echoue (>= 2). Sans cette fidelite, le cas « l outil est tombe » ne
    // serait pas reproduit, et la garde certifierait ce qu elle n a pas vu.
    file_put_contents(
        $dir . '/bin/semgrep',
        "#!/bin/sh\n"
        . "echo \"faux semgrep : code \$CODE_SEMGREP\"\n"
        . "if [ \"\$CODE_SEMGREP\" -lt 2 ]; then : > semgrep.sarif; fi\n"
        . "exit \$CODE_SEMGREP\n",
    );
    chmod($dir . '/bin/semgrep', 0o755);

    file_put_contents($dir . '/garde.sh', $script);

    $sortie = [];
    $code = 0;
    exec(
        'cd ' . escapeshellarg($dir)
        . ' && PATH=' . escapeshellarg($dir . '/bin') . ':$PATH'
        . ' CODE_SEMGREP=' . (int) $codeDuFauxSemgrep
        . ' sh garde.sh 2>&1',
        $sortie,
        $code,
    );

    return [implode("\n", $sortie), $code];
}

test('F38-013 — TEMOIN NEGATIF : le faux semgrep discrimine bien les trois cas', function (): void {
    if (! trim((string) shell_exec('command -v sh'))) {
        $this->markTestSkipped('sh absent du PATH : cette garde execute le script livre.');
    }

    // Sans ce temoin, un faux outil qui rendrait toujours 0 ferait passer les
    // tests suivants au vert sans avoir rien reproduit — le pire des verts.
    $sonde = "semgrep ci --sarif --output semgrep.sarif\n"
        . "code=\$?\n"
        . "if [ -f semgrep.sarif ]; then echo SARIF-ECRIT; else echo SARIF-ABSENT; fi\n"
        . "exit \$code\n";

    [$sortie, $code] = joueScriptSemgrepF38013($sonde, 0);
    expect($code)->toBe(0);
    expect(str_contains($sortie, 'SARIF-ECRIT'))->toBeTrue('Code 0 : le faux outil doit ecrire le SARIF. Sortie : ' . $sortie);

    [$sortie, $code] = joueScriptSemgrepF38013($sonde, 2);
    expect($code)->toBe(2);
    expect(str_contains($sortie, 'SARIF-ABSENT'))->toBeTrue(
        'Code 2 : le faux outil ne doit PAS ecrire le SARIF — c est ce cas qui reproduit '
        . 'l echec d outil. Sortie : ' . $sortie,
    );
});

test('F38-013 — le job semgrep ne porte plus aucun `continue-on-error`', function (): void {
    $job = jobSemgrepF38013();

    $this->assertNotSame(
        true,
        $job['continue-on-error'] ?? false,
        "Le job `semgrep` porte `continue-on-error: true` : son rouge ne compterait pas.\n"
        . 'C est le defaut retire du job `trivy` le 2026-08-16, revenu par une autre porte. '
        . 'GESTE : retirer la directive et LIRE le code de sortie, comme le fait le script livre.',
    );

    $etapes = $job['steps'] ?? [];
    $this->assertNotEmpty($etapes, 'Le job `semgrep` n a plus aucune etape.');

    foreach ($etapes as $i => $etape) {
        $this->assertNotSame(
            true,
            $etape['continue-on-error'] ?? false,
            "L etape n° {$i} du job `semgrep` porte `continue-on-error: true`.\n"
            . "Une seule etape etouffee suffit a rendre le job vert quoi qu il trouve — c est\n"
            . 'exactement le constat F38-013. GESTE : retirer la directive ; si l etape doit '
            . 'rester non bloquante, le dire dans le script (`exit 0` explicite) et ECRIRE POURQUOI, '
            . 'comme l exigent tous ses voisins dans ce fichier.',
        );
    }
});

test('F38-013 — un echec de l OUTIL fait rougir, une analyse propre verdit', function (): void {
    if (! trim((string) shell_exec('command -v sh'))) {
        $this->markTestSkipped('sh absent du PATH : cette garde execute le script livre.');
    }

    $script = scriptSemgrepF38013();

    // (a) Analyse propre : vert. Une garde qui rougirait ici serait une garde
    //     toujours rouge, et une garde toujours rouge se contourne puis s ignore.
    [$sortie, $code] = joueScriptSemgrepF38013($script, 0);
    $this->assertSame(
        0,
        $code,
        "Une analyse SANS decouverte doit verdir. Sortie :\n" . $sortie,
    );

    // (b) LE COEUR DE LA GARDE. L outil est tombe (registre injoignable,
    //     configuration refusee, SARIF non ecrit) : ROUGE. Ne pas savoir n est
    //     pas un feu vert — c est precisement la confusion que
    //     `continue-on-error: true` installait, et la meme regle que le job
    //     `composer-audit` applique deja.
    [$sortie, $code] = joueScriptSemgrepF38013($script, 2);
    $this->assertNotSame(
        0,
        $code,
        "Un ECHEC DE L OUTIL doit faire ROUGIR le job, et il rend 0.\n\n"
        . "Ne pas savoir n est pas la meme chose que savoir que tout va bien.\n"
        . "GESTE : dans l etape `semgrep ci` de .github/workflows/security.yml, capturer le code\n"
        . "de sortie et `exit 2` des qu il vaut 2 ou plus. Ne PAS remettre `continue-on-error`.\n\n"
        . 'Sortie du script livre : ' . $sortie,
    );
    $this->assertTrue(
        str_contains($sortie, 'ECHEC DE MESURE'),
        "Le rouge doit DIRE que c est la mesure qui a echoue, pas une decouverte : sans cela,\n"
        . "qui lit le journal cherchera une vulnerabilite qui n existe pas.\n"
        . 'Sortie : ' . $sortie,
    );

    // (c) DECOUVERTES. Le blocage n est pas arme — la mesure prealable n a
    //     jamais ete faite — mais le SILENCE, lui, est interdit : le journal
    //     doit nommer le geste. Les deux codes sont acceptes ici A DESSEIN,
    //     pour que cette garde ne rougisse pas le jour ou quelqu un arme
    //     enfin le blocage apres avoir mesure. Une garde qui punit le
    //     durcissement qu elle reclame finit retiree.
    [$sortie, $code] = joueScriptSemgrepF38013($script, 1);
    $this->assertContains(
        $code,
        [0, 1],
        'Sur une decouverte, le job doit rendre 0 (non arme, documente) ou 1 (arme). Il rend '
        . $code . ", ce qui est le code reserve a l echec d outil.\nSortie : " . $sortie,
    );
    $this->assertTrue(
        str_contains($sortie, 'decouverte') || str_contains($sortie, 'Semgrep'),
        "Une decouverte ne doit JAMAIS passer en silence : le journal doit la nommer et dire\n"
        . 'quoi en faire. Sortie : ' . $sortie,
    );
});

test('F38-013 — l envoi du SARIF est conditionne a l existence du fichier', function (): void {
    // Defaut n° 3 du bloc `trivy`, corrige chez lui le 2026-08-16 et reste
    // ouvert ici jusqu au 2026-08-22 : sans cette condition, un semgrep tombe
    // avant d ecrire le fichier fait rougir le job sur « Path does not exist ».
    // Un rouge qui n apprend rien finit ignore — et il masque le vrai motif.
    $trouvee = false;

    foreach (jobSemgrepF38013()['steps'] ?? [] as $etape) {
        if (! str_contains((string) ($etape['uses'] ?? ''), 'upload-sarif')) {
            continue;
        }
        $trouvee = true;

        $condition = (string) ($etape['if'] ?? '');
        $this->assertTrue(
            str_contains($condition, 'hashFiles'),
            'L envoi du SARIF n est pas conditionne a l existence du fichier (`if: ' . $condition . "`).\n"
            . "GESTE : `if: always() && hashFiles('semgrep.sarif') != ''`, comme les trois etapes\n"
            . 'equivalentes des jobs `trivy` et `trivy-images-tirees`.',
        );
    }

    $this->assertTrue(
        $trouvee,
        'Aucune etape `upload-sarif` dans le job `semgrep` : l inventaire ne remonte plus dans '
        . "l onglet Security. Le SARIF est la MEMOIRE du SAST — la garde bloque, la memoire\n"
        . 'conserve ce qui n a pas encore de correctif.',
    );
});
