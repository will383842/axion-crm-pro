<?php

/**
 * GARDE : LES AUDITS DE DEPENDANCES MESURENT VRAIMENT — constat H47-001 (S1).
 *
 * CE QUI ETAIT MESURE, ET C'EST UNE EXECUTION, PAS UNE LECTURE.
 *
 * Le job `composer-audit` de `.github/workflows/security.yml` etait ceci :
 *
 *     - uses: actions/checkout@v4
 *     - uses: shivammathur/setup-php@v2
 *     - working-directory: backend
 *       run: composer audit --no-dev || true
 *
 * Il n'a JAMAIS audite un seul paquet PHP. Deux defauts independants, chacun
 * suffisant a lui seul :
 *
 *  1. `composer audit` audite par defaut le depot INSTALLE
 *     (`vendor/composer/installed.json`), PAS `composer.lock`. Ce job ne fait
 *     jamais `composer install` : il n'y a donc pas de `vendor/`. Mesure du
 *     2026-08-21, repertoire contenant le vrai `composer.json` + `composer.lock`
 *     et rien d'autre :
 *
 *         $ composer audit --no-dev
 *         No packages - skipping audit.
 *         $ echo $?
 *         0
 *
 *     La preuve que rien n'etait regarde : reseau coupe
 *     (COMPOSER_DISABLE_NETWORK=1), meme texte, meme zero — la base d'avis
 *     n'est meme pas interrogee. La meme commande avec `--locked`, reseau
 *     coupe, echoue sur « request canceled:
 *     https://packagist.org/api/security-advisories/ ». `--locked` est donc
 *     exactement ce qui fait REGARDER les paquets. C'est ce que mesurent les
 *     deux premiers tests de ce fichier, et ils le mesurent HORS LIGNE, donc
 *     sans dependre de packagist.
 *
 *  2. `|| true` : la seconde serrure. Le jour ou quelqu'un aurait ajoute
 *     `composer install`, l'echec serait reste invisible malgre tout.
 *
 * SITE JUMEAU (motif A-011). Le job `pnpm-audit` portait le meme `|| true`, et
 * il coutait plus cher : `pnpm audit` LIT bien le lockfile sans installation.
 * Ce job mesurait donc correctement et jetait le resultat. Mesure du
 * 2026-08-21 sur les vrais lockfiles, sans `node_modules` :
 *
 *     frontend : 18 high + 1 critical   (`pnpm audit` sort en 1)
 *     workers  : 10 high + 1 critical   (`pnpm audit` sort en 1)
 *
 * Vingt-neuf avis high ou critical passaient inapercus a chaque execution.
 *
 * CE QUE CETTE GARDE MESURE, ET POURQUOI ELLE N'A PAS BESOIN DU RESEAU.
 *
 * Une garde qui se contenterait de relire le YAML prouverait que la LIGNE
 * existe, pas que le job ROUGIRAIT. Une garde qui appellerait packagist
 * prouverait le rouge, mais deviendrait rouge le jour ou le reseau tousse — et
 * un rouge qui n'apprend rien finit ignore. Ce fichier fait donc autrement :
 * il EXTRAIT du YAML le code de decision reellement livre (le `php -r` du job
 * composer, le `node -e` du job pnpm) et l'execute sur des charges temoins.
 * On teste le code qui tournera en CI, pas une copie, et sans sortir.
 *
 * Le bout qui ne peut pas se passer du reseau — « composer audit --locked sort
 * en 1 sur une vraie CVE » — a ete mesure a la main le 2026-08-21 : le vrai
 * lock avec la seule version de `guzzlehttp/guzzle` ramenee de 7.15.3 a 7.13.1
 * rend 6 avis dont CVE-2026-69246, et le script livre sort en 1. La charge
 * temoin utilisee plus bas est celle de cette mesure.
 *
 * ⚠️ PIEGE DE BANC, PAYE DEUX FOIS LE 2026-08-21.
 *
 * Dans le conteneur `a35r`, `/var/www/.github` n'est PAS un montage : c'est une
 * COPIE, et douze agents la partagent. Pendant l'ecriture de ce fichier elle a
 * purement DISPARU entre deux commandes. Un vert obtenu en local ne prouve donc
 * rien tant qu'on n'a pas compare l'empreinte :
 *
 *     docker cp .github/. a35r:/var/www/.github/
 *     docker exec a35r md5sum /var/www/.github/workflows/security.yml
 *
 * En CI (`actions/checkout`), la garde lit le vrai arbre.
 * `DeploiementsDependentDeLaCiTest` documente le meme decalage.
 */

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotH47(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function workflowSecuriteH47(): array
{
    $chemin = racineDepotH47() . '/.github/workflows/security.yml';

    Assert::assertFileExists(
        $chemin,
        'security.yml est introuvable. En local, la copie /var/www/.github du conteneur '
        . 'a35r se fait effacer par les autres agents : re-copier avant de croire un resultat.',
    );

    return Yaml::parseFile($chemin);
}

/**
 * Les jobs d'audit de dependances, DEDUITS du YAML.
 *
 * Surtout pas une liste ecrite a la main : c'est exactement ce qui a laisse
 * deux ecritures de C21-001 ouvertes. Un troisieme job d'audit ajoute demain
 * doit tomber sous la garde sans que personne n'ait a y penser.
 *
 * @return array<string, array<string, mixed>>
 */
function jobsDAuditH47(): array
{
    $jobs = workflowSecuriteH47()['jobs'] ?? [];
    $retenus = [];

    foreach ($jobs as $nom => $job) {
        if (str_ends_with((string) $nom, '-audit')) {
            $retenus[$nom] = $job;
        }
    }

    return $retenus;
}

/**
 * Extrait un bloc `<outil> -<flag> '<code>'` d'un script d'etape.
 *
 * On execute le code REELLEMENT LIVRE, pas une transcription : une garde qui
 * teste sa propre copie du script ne verra jamais la divergence.
 */
function codeInlineH47(string $script, string $ouverture): string
{
    $debut = strpos($script, $ouverture);

    Assert::assertNotFalse(
        $debut,
        "L'ouverture " . $ouverture . ' est absente du script livre : la garde ne peut plus '
        . 'extraire le code de decision, donc elle ne mesure plus rien. Adapter la garde '
        . 'AVANT de changer la forme du script.',
    );

    $reste = substr($script, $debut + strlen($ouverture));
    $fin = strrpos($reste, "'");

    Assert::assertNotFalse($fin, 'Bloc inline non termine dans le script livre.');

    return substr($reste, 0, $fin);
}

/** Le script de l'etape bloquante d'un job d'audit (celle qui n'etouffe rien). */
function scriptBloquantH47(string $nomJob): string
{
    $job = jobsDAuditH47()[$nomJob] ?? null;

    Assert::assertIsArray($job, "Le job {$nomJob} a disparu de security.yml.");

    foreach ($job['steps'] ?? [] as $etape) {
        $run = $etape['run'] ?? null;
        if (is_string($run) && str_contains($run, 'audit') && ! etouffeH47($run)) {
            return $run;
        }
    }

    Assert::fail(
        "Aucune etape d'audit non etouffee dans le job {$nomJob} : toutes se terminent par "
        . 'un `|| true` ou equivalent. C est exactement le defaut H47-001.',
    );
}

/**
 * Le script, prive de ses blocs `php -r '…'` / `node -e '…'`.
 *
 * ⚠️ MESURE, PAS THEORIE : sans ce retrait, le balayage « composer audit porte
 * bien --locked » rougissait sur la ligne
 *
 *     fwrite(STDERR, "ECHEC DE MESURE : composer audit n a pas rendu ...");
 *
 * qui est un MESSAGE, pas une invocation. Un faux rouge, et le genre le plus
 * couteux : on croit avoir trouve le defaut qu'on decrivait.
 */
function scriptSansBlocsInlineH47(string $script): string
{
    return (string) preg_replace("/(php -r|node -e) '.*'/s", '$1 <bloc-inline>', $script);
}

/** Le script avale-t-il son propre code de sortie ? */
function etouffeH47(string $script): bool
{
    foreach (['|| true', '|| :', '||true', '; true', '|| exit 0'] as $motif) {
        // La derniere commande du script est celle qui decide du sort de l'etape.
        $lignes = array_values(array_filter(array_map('trim', explode("\n", $script)), fn ($l) => $l !== ''));
        $derniere = end($lignes) ?: '';
        if (str_contains($derniere, $motif)) {
            return true;
        }
    }

    return false;
}

function execH47(string $commande, string $repertoire): array
{
    $sortie = [];
    $code = 0;
    exec('cd ' . escapeshellarg($repertoire) . ' && ' . $commande . ' 2>&1', $sortie, $code);

    return [implode("\n", $sortie), $code];
}

function repertoireTemporaireH47(string $suffixe): string
{
    $dir = sys_get_temp_dir() . '/h47-' . $suffixe . '-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    return $dir;
}

// ---------------------------------------------------------------------------
// 1. LE DEFAUT, REPRODUIT. Hors ligne, donc deterministe.
// ---------------------------------------------------------------------------

test('la forme d avant — composer audit sans --locked — n audite AUCUN paquet', function (): void {
    if (! trim((string) shell_exec('command -v composer'))) {
        $this->markTestSkipped('composer absent du PATH : ce test mesure le comportement du binaire.');
    }

    $dir = repertoireTemporaireH47('avant');
    copy(base_path('composer.json'), $dir . '/composer.json');
    copy(base_path('composer.lock'), $dir . '/composer.lock');

    // Reseau coupe A DESSEIN : si la commande rendait 0 seulement parce qu'elle
    // n'a pas pu joindre packagist, la demonstration ne vaudrait rien. Ici elle
    // rend 0 SANS MEME ESSAYER de sortir, et c'est tout le defaut.
    [$sortie, $code] = execH47('COMPOSER_DISABLE_NETWORK=1 composer audit --no-dev', $dir);

    // ⚠️ ON NE FIGE PAS LE LIBELLE EXACT DE COMPOSER, ET C'EST UNE MESURE QUI
    //    L'A IMPOSE.
    //
    // Ce test attendait mot pour mot « No packages - skipping audit ». Mesure du
    // 2026-08-21 :
    //
    //   banc `a35r`, composer 2.7.9 ...... « No packages - skipping audit. »
    //   CI, `tools: composer:v2` (v2 la
    //   plus recente) .................... « No installed packages found.
    //                                        Please run "composer install"
    //                                        before running "audit" or pass
    //                                        "--locked" ... »
    //
    // Le COMPORTEMENT est identique — aucun paquet audite, code 0 — et le
    // nouveau libelle est meme plus explicite. Seule la phrase a change. Une
    // garde qui epingle la prose d'un outil que la CI met a jour toute seule
    // rougit un jour sans qu'aucun code du depot n'ait bouge : c'est le cas ici,
    // et ce test-la aurait fini par etre retire au lieu d'etre lu.
    //
    // Ce qui est verifie ci-dessous, et qui EST le constat H47-001 : la commande
    // annonce qu'elle n'a rien a auditer, elle rend SUCCES, et elle n'a consulte
    // aucun bulletin.
    $formulations = [
        'No packages - skipping audit',   // composer <= 2.7
        'No installed packages found',    // composer >= 2.8
    ];
    $reconnue = false;
    foreach ($formulations as $forme) {
        if (str_contains($sortie, $forme)) {
            $reconnue = true;
            break;
        }
    }
    $this->assertTrue(
        $reconnue,
        'La forme d avant devrait annoncer qu elle n a aucun paquet a auditer, sous l une '
        . 'des formulations connues de composer (' . implode(' | ', $formulations) . '). '
        . 'Si le libelle a encore change, AJOUTER la nouvelle forme ici plutot que de '
        . 'retirer la garde. Sortie obtenue : ' . $sortie,
    );

    // 🔴 LE CODE DE SORTIE A CHANGE, ET C'EST UNE BONNE NOUVELLE : L'AMONT A
    //    CORRIGE H47-001.
    //
    // Le constat d'origine tenait en ceci : « la commande rend SUCCES en
    // n'auditant rien ». Mesure du 2026-08-21 :
    //
    //   composer 2.7.9 (banc) ....... code 0   <- le defaut, tel qu'il a ete constate
    //   composer >= 2.8 (CI) ........ code 1   <- l'editeur refuse desormais
    //
    // On n'exige donc plus `0`. Exiger `0` reviendrait a EXIGER que le defaut
    // survive : la garde serait devenue rouge le jour ou l'amont le repare, et
    // quelqu'un l'aurait retiree en croyant qu'elle s'est trompee.
    //
    // Ce qui reste vrai des DEUX cotes, et qui EST le constat : sans `--locked`,
    // et sans `vendor/`, la commande ne consulte AUCUN bulletin. Le paquet n'est
    // pas audite — qu'elle le dise par 0 ou par 1. C'est pour cela que le job CI
    // doit porter `--locked`, ce que verifie le test suivant.
    expect($code)->toBeIn(
        [0, 1],
        'Sortie inattendue de composer audit sans --locked (code ' . $code . ') : '
        . $sortie,
    );

    $this->assertStringNotContainsString(
        'security-advisories',
        $sortie,
        'Elle n interroge meme pas la base d avis — c est cela, le constat H47-001, '
        . 'et c est vrai quel que soit le code de sortie.',
    );
});

test('--locked est CE QUI FAIT REGARDER LES PAQUETS', function (): void {
    if (! trim((string) shell_exec('command -v composer'))) {
        $this->markTestSkipped('composer absent du PATH : ce test mesure le comportement du binaire.');
    }

    $dir = repertoireTemporaireH47('locked');
    copy(base_path('composer.json'), $dir . '/composer.json');
    copy(base_path('composer.lock'), $dir . '/composer.lock');

    // Meme repertoire, meme absence de vendor/, meme reseau coupe : SEUL
    // `--locked` change. La commande part alors chercher la base d avis — donc
    // elle a bien lu les paquets du lock. C est le temoin de discrimination, et
    // il ne coute pas une seule requete reseau.
    [$sortie, $code] = execH47('COMPOSER_DISABLE_NETWORK=1 composer audit --locked --no-dev', $dir);

    $this->assertNotSame(
        0,
        $code,
        'Avec --locked et le reseau coupe, la commande doit echouer faute de pouvoir '
        . 'joindre la base d avis. Sortie : ' . $sortie,
    );

    // ⚠️ Composer REPLIE l'URL sur la largeur du terminal (80 colonnes en
    // l'absence de tty) : la sortie brute porte « security-advi » puis
    // « sories/ » sur deux lignes. Chercher la chaine telle quelle echouait —
    // et c'etait un faux rouge, pas un defaut. On compare donc sur une copie
    // sans espaces, ce qui recolle les morceaux quel que soit le point de pli.
    $recolle = preg_replace('/\s+/', '', $sortie) ?? '';

    $this->assertStringContainsString(
        'packagist.org/api/security-advisories',
        $recolle,
        'Elle doit citer la base d avis qu elle tentait de joindre — preuve qu elle avait '
        . 'bien des paquets a auditer. Sortie : ' . $sortie,
    );
});

// ---------------------------------------------------------------------------
// 2. LE CODE DE DECISION REELLEMENT LIVRE, execute sur charges temoins.
// ---------------------------------------------------------------------------

test('la garde composer livree rougit sur un avis, verdit a vide, et rougit si la mesure echoue', function (): void {
    $code = codeInlineH47(scriptBloquantH47('composer-audit'), "php -r '");
    $dir = repertoireTemporaireH47('decision-php');
    file_put_contents($dir . '/garde.php', "<?php\n" . $code);

    // (a) Charge relevee le 2026-08-21 sur le vrai lock dont la seule version de
    //     guzzle a ete ramenee de 7.15.3 a 7.13.1 : 6 avis, dont CVE-2026-69246.
    file_put_contents($dir . '/audit-composer.json', json_encode([
        'advisories' => [
            'guzzlehttp/guzzle' => [
                ['cve' => 'CVE-2026-69246', 'title' => 'Noncanonical host can bypass host-based checks'],
                ['cve' => 'CVE-2026-69247', 'title' => 'temoin'],
            ],
        ],
        'abandoned' => [],
    ]));
    [$sortie, $statut] = execH47('php garde.php', $dir);
    $this->assertSame(1, $statut, 'Un avis de securite doit faire ROUGIR le job. Sortie : ' . $sortie);
    $this->assertStringContainsString('guzzlehttp/guzzle', $sortie, 'Le rouge doit NOMMER le paquet.');

    // (b) L etat du depot ce jour : zero avis sur les dependances de production.
    file_put_contents($dir . '/audit-composer.json', json_encode(['advisories' => [], 'abandoned' => []]));
    [$sortie, $statut] = execH47('php garde.php', $dir);
    $this->assertSame(0, $statut, 'Zero avis doit verdir. Sortie : ' . $sortie);

    // (c) Un paquet ABANDONNE ne doit pas rougir : c est de l hygiene, pas une
    //     vulnerabilite. `doctrine/annotations` est dans ce cas, et une garde
    //     rouge en permanence se contourne puis s ignore.
    file_put_contents($dir . '/audit-composer.json', json_encode([
        'advisories' => [],
        'abandoned' => ['doctrine/annotations' => 'none'],
    ]));
    [$sortie, $statut] = execH47('php garde.php', $dir);
    $this->assertSame(0, $statut, 'Un paquet abandonne seul ne doit pas rougir. Sortie : ' . $sortie);

    // (d) MESURE IMPOSSIBLE : ne pas savoir n est pas un feu vert. C est
    //     precisement la confusion que `|| true` installait.
    file_put_contents($dir . '/audit-composer.json', 'ceci n est pas du json');
    [$sortie, $statut] = execH47('php garde.php', $dir);
    $this->assertSame(2, $statut, 'Une mesure illisible doit rougir, pas verdir. Sortie : ' . $sortie);
});

test('le cliquet pnpm livre rougit sur une AUGMENTATION et sur un lot non releve', function (): void {
    if (! trim((string) shell_exec('command -v node'))) {
        $this->markTestSkipped('node absent du PATH : ce test execute le code de decision livre.');
    }

    $code = codeInlineH47(scriptBloquantH47('pnpm-audit'), "node -e '");
    $dir = repertoireTemporaireH47('decision-node');
    file_put_contents($dir . '/garde.js', $code);
    mkdir($dir . '/.github', 0o777, true);

    $ecrisCliquet = function (array $lots) use ($dir): void {
        file_put_contents($dir . '/.github/audit-node-cliquet.json', json_encode(['lots' => $lots]));
    };
    $ecrisMesure = function (int $high, int $critical) use ($dir): void {
        file_put_contents($dir . '/audit-node.json', json_encode([
            'metadata' => ['vulnerabilities' => ['info' => 0, 'low' => 0, 'moderate' => 0, 'high' => $high, 'critical' => $critical]],
        ]));
    };
    $joue = fn (string $lot) => execH47('LOT=' . $lot . ' GITHUB_WORKSPACE=' . escapeshellarg($dir) . ' node garde.js', $dir);

    $ecrisCliquet(['frontend' => ['high' => 18, 'critical' => 1]]);

    // (a) Le compte du jour : vert.
    $ecrisMesure(18, 1);
    [$sortie, $statut] = $joue('frontend');
    $this->assertSame(0, $statut, 'Le compte releve doit verdir. Sortie : ' . $sortie);

    // (b) TEMOIN NEGATIF : un avis high de plus. Rouge.
    $ecrisMesure(19, 1);
    [$sortie, $statut] = $joue('frontend');
    $this->assertSame(1, $statut, 'Une augmentation doit ROUGIR. Sortie : ' . $sortie);
    $this->assertStringContainsString('AUGMENTATION', $sortie, 'Le rouge doit dire ce qui a augmente.');

    // (c) Un critical de plus, high inchange : rouge aussi. Sans ce cas, une
    //     garde qui ne regarderait que `high` passerait pour bonne.
    $ecrisMesure(18, 2);
    [$sortie, $statut] = $joue('frontend');
    $this->assertSame(1, $statut, 'Une augmentation des critical doit ROUGIR aussi. Sortie : ' . $sortie);

    // (d) Une baisse verdit, mais le job DIT qu il faut resserrer : un cliquet
    //     qu on ne resserre jamais se desserre tout seul.
    $ecrisMesure(12, 0);
    [$sortie, $statut] = $joue('frontend');
    $this->assertSame(0, $statut, 'Une baisse doit verdir. Sortie : ' . $sortie);
    $this->assertStringContainsString('RESSERRER', $sortie, 'Une baisse doit reclamer le resserrage du cliquet.');

    // (e) TEMOIN DE COUVERTURE : un lot absent du releve ne doit PAS passer en
    //     silence. Sans ce garde-fou, ajouter un lot a la matrice creerait
    //     exactement le trou que le cliquet est cense fermer.
    $ecrisMesure(18, 1);
    [$sortie, $statut] = $joue('un-lot-jamais-releve');
    $this->assertSame(2, $statut, 'Un lot non releve doit rougir. Sortie : ' . $sortie);

    // (f) Mesure illisible : rouge.
    file_put_contents($dir . '/audit-node.json', 'pas du json');
    [$sortie, $statut] = $joue('frontend');
    $this->assertSame(2, $statut, 'Une mesure illisible doit rougir, pas verdir. Sortie : ' . $sortie);
});

// ---------------------------------------------------------------------------
// 3. LE YAML : que la forme corrigee ne se fasse pas defaire en silence.
// ---------------------------------------------------------------------------

test('aucun job d audit n etouffe son verdict', function (): void {
    $jobs = jobsDAuditH47();

    // TEMOIN DE COUVERTURE : si le balayage ne voit plus rien, il doit rougir.
    // Une garde qui balaie zero site rend vert sans avoir rien mesure — c est
    // le defaut meme que ce fichier corrige.
    $this->assertGreaterThanOrEqual(
        2,
        count($jobs),
        'Le balayage doit trouver au moins les deux jobs d audit (composer-audit, pnpm-audit). '
        . 'Trouves : ' . implode(', ', array_keys($jobs)),
    );

    foreach ($jobs as $nom => $job) {
        $this->assertNotSame(
            true,
            $job['continue-on-error'] ?? false,
            "Le job {$nom} porte continue-on-error : son rouge ne compterait pas.",
        );

        $etapesAudit = 0;
        foreach ($job['steps'] ?? [] as $etape) {
            $run = $etape['run'] ?? null;
            if (! is_string($run) || ! str_contains($run, 'audit')) {
                continue;
            }
            $etapesAudit++;

            if (! etouffeH47($run)) {
                $this->assertNotSame(
                    true,
                    $etape['continue-on-error'] ?? false,
                    "Une etape bloquante de {$nom} porte continue-on-error : c est le "
                    . 'defaut retire du job trivy le 2026-08-16, revenu par une autre porte.',
                );
            }
        }

        $this->assertGreaterThan(0, $etapesAudit, "Le job {$nom} n invoque plus aucun audit.");

        // La ligne de force : il DOIT rester une etape capable de rougir.
        $this->assertIsString(
            scriptBloquantH47($nom),
            "Le job {$nom} n a plus aucune etape d audit capable de rougir.",
        );
    }
});

test('tout appel a composer audit porte --locked, sans quoi il n audite rien', function (): void {
    $trouves = 0;

    foreach (jobsDAuditH47() as $nom => $job) {
        foreach ($job['steps'] ?? [] as $etape) {
            $run = $etape['run'] ?? null;
            if (! is_string($run)) {
                continue;
            }
            // On ne juge que les INVOCATIONS, pas les messages qui parlent de
            // l'outil depuis l'interieur d'un bloc inline.
            $run = scriptSansBlocsInlineH47($run);
            if (! str_contains($run, 'composer audit')) {
                continue;
            }
            $trouves++;

            foreach (explode("\n", $run) as $ligne) {
                if (! str_contains($ligne, 'composer audit')) {
                    continue;
                }
                $this->assertStringContainsString(
                    '--locked',
                    $ligne,
                    "Dans {$nom}, « composer audit » sans --locked audite le repertoire INSTALLE. "
                    . 'Or ce job ne fait pas composer install : il rendrait '
                    . '« No packages - skipping audit » et un zero. Ligne : ' . trim($ligne),
                );
            }
        }
    }

    $this->assertGreaterThan(0, $trouves, 'Plus aucun appel a composer audit : la garde ne mesure plus rien.');
});

test('le cliquet couvre EXACTEMENT la matrice pnpm, et il est date', function (): void {
    $chemin = racineDepotH47() . '/.github/audit-node-cliquet.json';
    $this->assertFileExists($chemin, 'Le releve du cliquet Node est le pivot du job pnpm-audit.');

    $cliquet = json_decode((string) file_get_contents($chemin), true);
    $this->assertIsArray($cliquet, 'Le cliquet doit etre du JSON lisible.');
    $this->assertArrayHasKey('mesure_le', $cliquet, 'Un releve sans date ne se relit pas : on ne sait plus de quand il parle.');
    $this->assertNotEmpty($cliquet['mesure_le']);

    // La liste attendue est DEDUITE de la matrice du workflow, jamais ecrite a
    // la main : c est le seul moyen qu un lot ajoute demain ne passe pas au
    // travers. Une garde de completude a liste manuelle ne voit jamais le site
    // qu on a oublie.
    $matrice = jobsDAuditH47()['pnpm-audit']['strategy']['matrix']['path'] ?? [];
    $this->assertNotEmpty($matrice, 'La matrice pnpm-audit est vide ou a change de forme.');

    $releves = array_keys($cliquet['lots'] ?? []);
    sort($matrice);
    sort($releves);

    $this->assertSame(
        $matrice,
        $releves,
        'Le cliquet doit couvrir exactement la matrice. Un lot en trop desserre la garde '
        . 'sans que personne ne le voie ; un lot manquant la rend aveugle sur ce lot.',
    );

    foreach ($cliquet['lots'] as $lot => $compte) {
        foreach (['high', 'critical'] as $severite) {
            $this->assertArrayHasKey($severite, $compte, "Le lot {$lot} ne releve pas {$severite}.");
            $this->assertIsInt($compte[$severite], "Le releve {$severite} de {$lot} doit etre un entier.");
        }
    }
});

test('un audit rouge sur main reveille encore un humain', function (): void {
    $alerte = workflowSecuriteH47()['jobs']['alerte'] ?? null;
    $this->assertIsArray($alerte, "Le job d'alerte a disparu : un rouge sur le cron ne previendrait plus personne.");

    foreach (array_keys(jobsDAuditH47()) as $nom) {
        $this->assertContains(
            $nom,
            $alerte['needs'] ?? [],
            "Le job {$nom} n est pas dans les `needs` de `alerte` : son rouge n ouvrirait aucune issue. "
            . 'Le workflow a deja vecu cinq echecs hebdomadaires consecutifs que personne n a vus.',
        );
    }
});
