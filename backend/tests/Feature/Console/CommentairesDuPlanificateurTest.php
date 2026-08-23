<?php

/**
 * GARDE — audit 360, A09-012 / B17-007 (S3) : « le commentaire de
 * `routes/console.php` affirme que `companies:rescrape-archives` s'auto-saute ;
 * la commande existe et la tache s'execute ».
 *
 * CE QU'ON A MESURE LE 2026-08-22, AVANT CORRECTIF :
 *
 *   routes/console.php:219-221  « La commande `companies:rescrape-archives` est
 *                                 codee dans le Sprint Hardening (H6). En
 *                                 attendant, le schedule est pose mais
 *                                 s'auto-skip si la commande n'existe pas. »
 *   RescrapeArchivesCommand.php:23   protected $signature = 'companies:rescrape-archives'
 *
 * La commande EXISTAIT. Le `skip()` de :226-228
 * (`! array_key_exists('companies:rescrape-archives', Artisan::all())`) rendait
 * donc false a chaque passage, et la tache `monthlyOn(1, '02:00')` partait pour
 * de bon — jusqu'a 200 EnrichCompanyJob le 1er du mois. Meme patron pour
 * `companies:retry-google-places` (:237-239 face a
 * RetryGooglePlacesCommand.php:25), dont la fermeture etait morte elle aussi.
 *
 * POURQUOI CA MERITE UNE GARDE ET PAS SEULEMENT UNE RELECTURE : un commentaire
 * qui nie l'existence d'une commande fait lire « cette ligne ne fait rien » a
 * quiconque passe. C'est ce qui rend une charge mensuelle reelle invisible en
 * revue — et c'est exactement le genre de mensonge qui survit des mois.
 *
 * CE QUE LA GARDE EXIGE :
 *   1. les deux commandes citees par le constat sont bien enregistrees (sinon
 *      le retrait des `skip()` ferait echouer le planificateur en prod) ;
 *   2. `routes/console.php` ne porte plus aucune fermeture `skip()` dont le
 *      predicat teste l'existence d'une commande QUI EXISTE — une telle
 *      fermeture est morte, et elle masque la disparition de la commande au
 *      lieu de la signaler ;
 *   3. aucun bloc de commentaire du fichier ne nie l'existence d'une commande
 *      qui est, elle, enregistree.
 *
 * TEMOINS (sans eux ce vert ne prouverait rien) :
 *   - couverture : on compte les `Schedule::command(` lus dans le fichier ; si
 *     le chemin devenait faux on lirait zero et les detecteurs seraient verts
 *     sur du vide ;
 *   - negatif : les deux detecteurs sont braques sur le TEXTE D'AVANT, conserve
 *     ici mot pour mot, et doivent y retrouver les defauts. Un detecteur qui ne
 *     voit plus rien nulle part est un detecteur casse, pas un depot sain.
 */

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

// ─────────────────────────────────────────────────────────────────────────────
// L'INSTRUMENT
// ─────────────────────────────────────────────────────────────────────────────

function a09012CheminConsole(): string
{
    return base_path('routes/console.php');
}

function a09012Source(): string
{
    $chemin = a09012CheminConsole();

    return is_file($chemin) ? (string) file_get_contents($chemin) : '';
}

/**
 * Les noms de commandes artisan reellement enregistrees.
 *
 * C'est la meme source que celle qu'interrogeaient les `skip()` retires : si
 * elle mentait, elle mentait deja pour eux.
 *
 * @return array<int, string>
 */
function a09012CommandesEnregistrees(): array
{
    return array_keys(Artisan::all());
}

/**
 * DETECTEUR 1 — les fermetures `skip()` dont le predicat est un test
 * d'existence de commande, quand la commande existe.
 *
 * @param  array<int, string>  $enregistrees
 * @return array<int, string> la liste des reproches ; vide = rien a redire
 */
function a09012FermeturesMortes(string $source, array $enregistrees): array
{
    $reproches = [];

    preg_match_all(
        "/array_key_exists\\(\\s*'([^']+)'\\s*,\\s*Artisan::all\\(\\)\\s*\\)/",
        $source,
        $trouvailles,
    );

    foreach ($trouvailles[1] as $nom) {
        if (in_array($nom, $enregistrees, true)) {
            $reproches[] = sprintf(
                'fermeture skip() morte : le predicat teste l existence de « %s », '
                . 'commande qui EST enregistree — il rend donc toujours false.',
                $nom,
            );
        }
    }

    return $reproches;
}

/**
 * DETECTEUR 2 — les blocs de commentaire qui nient l'existence d'une commande
 * enregistree.
 *
 * On raisonne par BLOC (lignes `//` consecutives) et non par ligne : dans le
 * texte d'origine, le nom de la commande et la negation vivaient sur deux
 * lignes differentes du meme paragraphe.
 *
 * @param  array<int, string>  $enregistrees
 * @return array<int, string>
 */
function a09012CommentairesMenteurs(string $source, array $enregistrees): array
{
    $reproches = [];
    $bloc = [];

    $negations = ['n\'existe pas', 'auto-skip', 'pas encore codee', 'pas encore codée', 'reste a coder', 'reste à coder'];

    $examiner = static function (array $bloc) use ($enregistrees, $negations, &$reproches): void {
        if ($bloc === []) {
            return;
        }

        $texte = implode(' ', $bloc);
        $nie = false;
        foreach ($negations as $negation) {
            if (mb_stripos($texte, $negation) !== false) {
                $nie = true;
                break;
            }
        }

        if (! $nie) {
            return;
        }

        foreach ($enregistrees as $nom) {
            // On exige le nom complet de la commande dans le bloc : « anomaly »
            // seul ne doit pas suffire a accuser un paragraphe.
            if (str_contains($nom, ':') && str_contains($texte, $nom)) {
                $reproches[] = sprintf(
                    'commentaire menteur : un paragraphe nie l existence de « %s », '
                    . 'commande qui EST enregistree. Bloc : %s',
                    $nom,
                    mb_substr($texte, 0, 160),
                );
            }
        }
    };

    foreach (preg_split('/\R/', $source) ?: [] as $ligne) {
        $nue = ltrim((string) $ligne);
        if (str_starts_with($nue, '//')) {
            $bloc[] = ltrim(substr($nue, 2));

            continue;
        }

        $examiner($bloc);
        $bloc = [];
    }

    $examiner($bloc);

    return $reproches;
}

/**
 * LE TEXTE D'AVANT, conserve mot pour mot (routes/console.php:218-239, mesure
 * du 2026-08-22). Il sert de temoin negatif : les deux detecteurs doivent y
 * retrouver ce qu'ils sont censes voir.
 */
function a09012TexteDAvant(): string
{
    return <<<'PHP'
        // Sprint Pipeline 360° — Re-scrape mensuel companies archivées sans email
        // La commande `companies:rescrape-archives` est codée dans le Sprint Hardening (H6).
        // En attendant, le schedule est posé mais s'auto-skip si la commande n'existe pas
        // (pas d'erreur dans schedule:list).
        Schedule::command('companies:rescrape-archives --limit=200')
            ->monthlyOn(1, '02:00')
            ->skip(function (): bool {
                // True = skip ce run. Skip si la commande artisan n'existe pas encore.
                return ! array_key_exists('companies:rescrape-archives', Artisan::all());
            });

        Schedule::command('companies:retry-google-places --limit=500')
            ->monthlyOn(1, '03:00')
            ->skip(function (): bool {
                return ! array_key_exists('companies:retry-google-places', Artisan::all());
            });
        PHP;
}

// ═════════════════════════════════════════════════════════════════════════════
// 1. LE CONSTAT
// ═════════════════════════════════════════════════════════════════════════════

test('A09-012 / B17-007 — les deux commandes que le commentaire disait absentes sont enregistrees', function () {
    $enregistrees = a09012CommandesEnregistrees();

    // TEMOIN DE COUVERTURE : si l'application ne chargeait aucune commande, les
    // deux assertions suivantes rougiraient pour la mauvaise raison. On veut
    // savoir laquelle des deux causes on regarde.
    $this->assertGreaterThan(
        50,
        count($enregistrees),
        'Aucune commande artisan chargee (' . count($enregistrees) . ') : ce n est pas le depot '
        . 'qui est en cause mais le cadre de test. Verifier le bootstrap avant de lire la suite.',
    );

    foreach (['companies:rescrape-archives', 'companies:retry-google-places'] as $nom) {
        expect(in_array($nom, $enregistrees, true))->toBeTrue(
            sprintf(
                'La commande « %s » n est plus enregistree, alors que routes/console.php la planifie '
                . 'SANS filet (les skip() d existence ont ete retires le 2026-08-22, A09-012). '
                . 'Geste : soit recreer la commande, soit retirer sa ligne Schedule::command() '
                . 'de routes/console.php — ne PAS remettre un skip() qui la sauterait en silence.',
                $nom,
            ),
        );
    }
});

test('A09-012 / B17-007 — routes/console.php ne porte plus de skip() mort ni de commentaire menteur', function () {
    $source = a09012Source();

    // TEMOIN DE COUVERTURE, chiffre : mesure du 2026-08-22, le fichier porte 40
    // occurrences de `Schedule::command(`. Si le chemin bougeait, on lirait une
    // chaine vide et les deux detecteurs seraient verts sans avoir rien inspecte.
    $this->assertGreaterThanOrEqual(
        30,
        substr_count($source, 'Schedule::command('),
        'Le fichier planificateur lu a ' . a09012CheminConsole() . ' ne contient pas les taches '
        . 'attendues (' . substr_count($source, 'Schedule::command(') . ' vues, 40 mesurees le 2026-08-22). '
        . 'Geste : corriger le chemin dans a09012CheminConsole() avant de croire ce test.',
    );

    $enregistrees = a09012CommandesEnregistrees();

    $reproches = array_merge(
        a09012FermeturesMortes($source, $enregistrees),
        a09012CommentairesMenteurs($source, $enregistrees),
    );

    // `assertSame` et non `expect()->toBe()` : les expectations Pest sont
    // variadiques, un message y devient une seconde valeur attendue.
    $this->assertSame(
        [],
        $reproches,
        "routes/console.php ment de nouveau sur ce qu il execute :\n - "
        . implode("\n - ", $reproches)
        . "\nGeste : supprimer la fermeture skip() (elle rend toujours false, donc elle ne protege "
        . 'de rien) et remplacer le commentaire par la description de la charge REELLE de la tache.',
    );
});

// ═════════════════════════════════════════════════════════════════════════════
// 2. TEMOIN NEGATIF — l'instrument voit-il encore quelque chose ?
// ═════════════════════════════════════════════════════════════════════════════

test('A09-012 / B17-007 — les deux detecteurs retrouvent le defaut dans le texte d avant', function () {
    $avant = a09012TexteDAvant();
    $enregistrees = a09012CommandesEnregistrees();

    $mortes = a09012FermeturesMortes($avant, $enregistrees);
    $this->assertCount(
        2,
        $mortes,
        'Le detecteur de fermetures skip() mortes ne retrouve plus les 2 cas du 2026-08-22 '
        . '(' . count($mortes) . ' vus). Son vert sur le depot ne prouve donc plus rien. '
        . 'Geste : reparer la regexp de a09012FermeturesMortes() avant de toucher au depot.',
    );

    $menteurs = a09012CommentairesMenteurs($avant, $enregistrees);
    $this->assertGreaterThanOrEqual(
        1,
        count($menteurs),
        'Le detecteur de commentaires menteurs ne retrouve plus le paragraphe « s auto-skip si la '
        . 'commande n existe pas » du 2026-08-22. Geste : reparer a09012CommentairesMenteurs() — '
        . 'son vert sur le depot est actuellement un faux vert.',
    );
});
