<?php

declare(strict_types=1);

/**
 * =============================================================================
 * GARDE — la baseline PHPStan ne peut que DÉCROÎTRE, et le socle CRM n'y entre
 * jamais.
 * =============================================================================
 *
 * Pourquoi ce test existe.
 *
 * `phpstan-baseline.neon` gèle la dette de niveau 8 constatée le 2026-08-13.
 * Rien, jusqu'au 2026-08-18, n'empêchait ce fichier de GROSSIR : il suffisait
 * de le régénérer pour faire taire une erreur neuve, et la CI restait verte.
 * Pire, `phpstan.neon` portait `reportUnmatchedIgnoredErrors: false` : une
 * entrée devenue obsolète ne faisait rien rougir non plus. La baseline pouvait
 * donc dire n'importe quoi dans les deux sens. Preuve constatée : son en-tête
 * annonçait « 339 entrées / 443 erreurs » alors qu'elle en contenait 337 / 441
 * — deux entrées avaient disparu sans que rien ne le signale.
 *
 * Ce test pose trois invariants. Le troisième est le vrai enjeu du chantier :
 * un simple compteur global ne suffit pas, puisqu'on pourrait retirer une
 * entrée ailleurs et en ajouter une sur le socle CRM sans bouger le total.
 *
 *   1. le nombre d'entrées et la somme des `count:` ne dépassent JAMAIS les
 *      plafonds figés ci-dessous ;
 *   2. l'en-tête du fichier annonce les nombres réellement présents (c'est
 *      exactement ce qui avait dérivé) ;
 *   3. AUCUNE entrée ne vise le socle CRM — écrit après le gel, propre au
 *      niveau 8 : le code neuf n'entre pas dans la baseline.
 *
 * Il vit dans la suite Pest et NON dans PHPStan : `phpstan.neon` n'analyse que
 * `app`, `config`, `database`, `routes` — `tests/` est hors périmètre, une
 * règle PHPStan personnalisée ne serait donc jamais exécutée sur elle-même.
 *
 * QUAND TU FAIS BAISSER LA DETTE : mets à jour les deux plafonds ci-dessous ET
 * l'en-tête de `phpstan-baseline.neon` dans le MÊME commit. Ces nombres ne
 * remontent jamais.
 */

/** Plafonds figés le 2026-08-18. Ne peuvent que DÉCROÎTRE. */
const BASELINE_MAX_ENTREES = 211;
const BASELINE_MAX_ERREURS = 248;

/**
 * Chemins sur lesquels aucune entrée de baseline n'est tolérée.
 *
 * `app/Models/Activity.php` y figure alors que le modèle n'existe pas encore
 * (la table `activities` existe, elle) : c'est délibéré — le jour où quelqu'un
 * le crée, il naît sous la garde.
 *
 * @var list<string>
 */
const BASELINE_CHEMINS_INTERDITS = [
    'app/Crm/',
    'app/Http/Controllers/Api/Crm/',
    'app/Models/Contact.php',
    'app/Models/Candidate.php',
    'app/Models/Company.php',
    'app/Models/Activity.php',
    'app/Models/Tag.php',
];

/**
 * Lit la baseline et en extrait une entrée par bloc `-`.
 *
 * Analyse textuelle volontaire : dépendre d'un parseur NEON ferait dépendre la
 * garde d'une librairie de dev (nette/neon n'est tiré que par PHPStan). Les
 * fins de ligne sont normalisées : sous Windows le fichier est en CRLF et une
 * recherche de `\n` littéral y serait aveugle.
 *
 * @return array{entrees: list<array{message: string, identifier: ?string, count: int, path: string}>, blocs: int}
 */
function lireBaselinePhpstan(): array
{
    $chemin = dirname(__DIR__, 2) . '/phpstan-baseline.neon';
    $brut = file_get_contents($chemin);

    if ($brut === false) {
        throw new RuntimeException("Baseline PHPStan illisible : {$chemin}");
    }

    $texte = str_replace(["\r\n", "\r"], "\n", $brut);
    $lignes = explode("\n", $texte);

    $blocs = [];
    $courant = null;
    foreach ($lignes as $ligne) {
        if (preg_match('/^\t{2}-\s*$/', $ligne) === 1) {
            if ($courant !== null) {
                $blocs[] = $courant;
            }
            $courant = [];

            continue;
        }
        if ($courant !== null) {
            $courant[] = $ligne;
        }
    }
    if ($courant !== null) {
        $blocs[] = $courant;
    }

    $entrees = [];
    foreach ($blocs as $i => $bloc) {
        $message = $identifier = $path = null;
        $count = null;
        foreach ($bloc as $ligne) {
            if (preg_match("/^\t{3}message: '(.*)'$/", $ligne, $m) === 1) {
                $message = $m[1];
            } elseif (preg_match("/^\t{3}identifier: (.*)$/", $ligne, $m) === 1) {
                $identifier = trim($m[1]);
            } elseif (preg_match("/^\t{3}count: (\d+)$/", $ligne, $m) === 1) {
                $count = (int) $m[1];
            } elseif (preg_match("/^\t{3}path: (.*)$/", $ligne, $m) === 1) {
                $path = trim($m[1]);
            }
        }

        // Un bloc qu'on ne sait pas lire fait ROUGIR : sans cela, un changement
        // de format de PHPStan rendrait la garde muette (0 entrée lue = vert).
        if ($message === null || $path === null || $count === null) {
            throw new RuntimeException(
                "Bloc n°{$i} de phpstan-baseline.neon illisible (message/count/path manquant). " .
                "Le format du fichier a changé : adapte lireBaselinePhpstan(), n'affaiblis pas la garde.",
            );
        }

        $entrees[] = [
            'message' => $message,
            'identifier' => $identifier,
            'count' => $count,
            'path' => $path,
        ];
    }

    return ['entrees' => $entrees, 'blocs' => count($blocs)];
}

test('le parseur de baseline lit vraiment des entrées', function () {
    ['entrees' => $entrees] = lireBaselinePhpstan();

    // Témoin : si cette garde tombait à zéro entrée lue, tous les plafonds
    // seraient satisfaits pour de mauvaises raisons.
    expect($entrees)->not->toBeEmpty();
    expect($entrees[0]['path'])->toStartWith('app/');
});

test('la baseline PHPStan ne grossit pas', function () {
    ['entrees' => $entrees] = lireBaselinePhpstan();

    $nbEntrees = count($entrees);
    $nbErreurs = array_sum(array_column($entrees, 'count'));

    // Assertions PHPUnit et non `expect()` : les expectations Pest concernées
    // sont variadiques, un second argument y serait lu comme une autre valeur
    // attendue et non comme un message. Vu à l'écriture de cette garde.
    $this->assertLessThanOrEqual(
        BASELINE_MAX_ENTREES,
        $nbEntrees,
        "phpstan-baseline.neon contient {$nbEntrees} entrées pour un plafond de " .
        BASELINE_MAX_ENTREES . '. La baseline ne peut que DÉCROÎTRE : corrige le ' .
        'code plutôt que de régénérer le fichier.',
    );

    $this->assertLessThanOrEqual(
        BASELINE_MAX_ERREURS,
        $nbErreurs,
        "phpstan-baseline.neon cumule {$nbErreurs} erreurs (somme des `count:`) " .
        'pour un plafond de ' . BASELINE_MAX_ERREURS . '. Une entrée dont le `count:` ' .
        'grossit est une régression, même si le nombre d\'entrées ne bouge pas.',
    );
});

test('l\'en-tête de la baseline annonce les nombres réellement présents', function () {
    ['entrees' => $entrees] = lireBaselinePhpstan();

    $nbEntrees = count($entrees);
    $nbErreurs = array_sum(array_column($entrees, 'count'));

    $chemin = dirname(__DIR__, 2) . '/phpstan-baseline.neon';
    $entete = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($chemin));

    // C'est très exactement la dérive constatée : l'en-tête annonçait 339/443,
    // le fichier en portait 337/441, et rien ne le signalait.
    $this->assertStringContainsString(
        "ÉTAT COURANT (2026-08-18) : {$nbEntrees} entrées / {$nbErreurs} erreurs",
        $entete,
        "L'en-tête de phpstan-baseline.neon ne correspond plus au contenu " .
        "({$nbEntrees} entrées / {$nbErreurs} erreurs mesurées). Mets-le à jour.",
    );
});

test('aucune entrée de baseline ne vise le socle CRM', function () {
    ['entrees' => $entrees] = lireBaselinePhpstan();

    $fautives = [];
    foreach ($entrees as $entree) {
        foreach (BASELINE_CHEMINS_INTERDITS as $interdit) {
            $vise = str_ends_with($interdit, '/')
                ? str_starts_with($entree['path'], $interdit)
                : $entree['path'] === $interdit;

            if ($vise) {
                $fautives[] = $entree['path'] . ' → ' . $entree['message'];
            }
        }
    }

    $this->assertSame([], $fautives, sprintf(
        "Le socle CRM a été écrit APRÈS le gel du 2026-08-13 et est propre au niveau 8.\n" .
        "Aucune entrée de baseline ne doit le viser — ces %d entrées le font :\n  - %s\n" .
        "Corrige le code (types de retour de relations, @property PHPDoc), ne gèle pas l'erreur.",
        count($fautives),
        implode("\n  - ", $fautives),
    ));
});

test('reportUnmatchedIgnoredErrors reste activé', function () {
    $chemin = dirname(__DIR__, 2) . '/phpstan.neon';
    $conf = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($chemin));

    // Sans ce drapeau, une entrée obsolète ne fait rien rougir : la baseline
    // peut alors mentir sans conséquence, et le compteur ci-dessus ne protège
    // plus de rien (on pourrait laisser s'accumuler des lignes mortes).
    $this->assertStringContainsString(
        'reportUnmatchedIgnoredErrors: true',
        $conf,
        'phpstan.neon doit garder `reportUnmatchedIgnoredErrors: true`. À `false`, ' .
        'une entrée de baseline qui ne correspond plus à aucune erreur reste ' .
        'invisible — la baseline devient une garde qui ne garde rien.',
    );
});
