<?php

/**
 * GARDE : AUCUN MESSAGE DANS UN `toContain()`. JAMAIS.
 *
 * POURQUOI CETTE GARDE EXISTE, ET C'EST UN AVEU CHIFFRÉ.
 *
 * `expect()->toContain()` de Pest est **variadique** : `toContain(mixed ...$needles)`.
 * Tous ses arguments sont des **valeurs à chercher**. Un message d'échec passé en
 * second argument devient donc une **deuxième valeur à trouver** — et la garde
 * cherche sa propre phrase d'explication dans la donnée qu'elle inspecte.
 *
 * Elle rougit alors **éternellement**, y compris une fois le défaut corrigé. Et
 * c'est la pire forme de faux rouge : le message affiché est exactement celui
 * qu'on a écrit, si bien qu'on croit avoir trouvé le défaut qu'on décrivait.
 *
 * 🔴 LE COMPTE, POUR LA SEULE JOURNÉE DU 2026-08-20 :
 *
 *     1. `ServeurHttpDeProductionTest` .......... 6 occurrences
 *     2. `JournalAuditCloisonneTest` ............ 2
 *     3. `PatronHmacDeReferenceTest` ............ 1
 *     4. `CloisonnementDesListesTest` ........... 1
 *     5. `DiagnosticConnexionSansEcritureTest` .. 1  (variante : motif accentué)
 *     6. `IndexServentLesRequetesTest` .......... 1
 *
 * Le piège a été **documenté en tête de trois fichiers** après la première
 * occurrence. Il s'est refermé trois fois de plus **après** cette documentation,
 * dont deux fois dans l'heure qui suivait.
 *
 * 🔑 **Le savoir ne suffit pas. Il faut la règle mécanique.** C'est précisément
 * la leçon que l'audit tire ailleurs — une garde vaut mieux qu'un commentaire —
 * appliquée à l'outil qui écrit les gardes.
 *
 * ── CE QUI RESTE PERMIS, ET C'EST L'ESSENTIEL DE LA FINESSE ────────────────
 *
 * `toContain('a', 'b', 'c')` avec de VRAIES valeurs est légitime, et le dépôt en
 * contient plusieurs (listes de tags, de départements, de clés attendues). La
 * garde ne les interdit pas : elle repère ce qui **ressemble à une phrase** —
 * long, espacé, ponctué — et rien d'autre.
 *
 * L'alternative correcte est `assertStringContainsString($aiguille, $meule,
 * $message)` ou `assertContains($aiguille, $tableau, $message)`, qui portent un
 * vrai message d'échec.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * Un argument ressemble-t-il à un message plutôt qu'à une valeur ?
 *
 * Le seuil est volontairement prudent : on préfère laisser passer un message
 * court que rougir sur une vraie valeur. Une garde qui crie au loup finit
 * désactivée, et c'est pire que pas de garde.
 */
function ressembleAUnMessage(string $argument): bool
{
    $t = trim($argument);

    // Pas une chaîne littérale : variable, constante, appel — on ne juge pas.
    if (! preg_match("/^'(.*)'$/s", $t, $m) && ! preg_match('/^"(.*)"$/s', $t, $m)) {
        return false;
    }

    $contenu = $m[1];

    // Une phrase : longue, et faite de plusieurs mots.
    return mb_strlen($contenu) >= 40 && substr_count($contenu, ' ') >= 5;
}

/**
 * @return list<array{fichier: string, ligne: int, extrait: string}>
 */
function appelsToContainAvecMessage(string $source, string $fichier): array
{
    $trouves = [];

    // On capture l'intérieur de `toContain( ... )` en équilibrant les
    // parenthèses : un motif naïf s'arrêterait à la première `)` d'une chaîne.
    $position = 0;
    while (($i = strpos($source, 'toContain(', $position)) !== false) {
        $debut = $i + strlen('toContain(');
        $niveau = 1;
        $j = $debut;
        $dansChaine = null;

        while ($j < strlen($source) && $niveau > 0) {
            $c = $source[$j];
            if ($dansChaine !== null) {
                if ($c === '\\') {
                    $j += 2;

                    continue;
                }
                if ($c === $dansChaine) {
                    $dansChaine = null;
                }
            } elseif ($c === "'" || $c === '"') {
                $dansChaine = $c;
            } elseif ($c === '(') {
                $niveau++;
            } elseif ($c === ')') {
                $niveau--;
            }
            $j++;
        }

        $arguments = substr($source, $debut, $j - $debut - 1);
        $position = $j;

        // Découpe sur les virgules de PREMIER niveau uniquement.
        $morceaux = [];
        $courant = '';
        $niveau = 0;
        $dansChaine = null;
        for ($k = 0; $k < strlen($arguments); $k++) {
            $c = $arguments[$k];
            if ($dansChaine !== null) {
                $courant .= $c;
                if ($c === '\\') {
                    $courant .= $arguments[$k + 1] ?? '';
                    $k++;

                    continue;
                }
                if ($c === $dansChaine) {
                    $dansChaine = null;
                }

                continue;
            }
            if ($c === "'" || $c === '"') {
                $dansChaine = $c;
            } elseif ($c === '(' || $c === '[') {
                $niveau++;
            } elseif ($c === ')' || $c === ']') {
                $niveau--;
            } elseif ($c === ',' && $niveau === 0) {
                $morceaux[] = $courant;
                $courant = '';

                continue;
            }
            $courant .= $c;
        }
        $morceaux[] = $courant;

        foreach (array_slice($morceaux, 1) as $argument) {
            if (ressembleAUnMessage($argument)) {
                $ligne = substr_count(substr($source, 0, $i), "\n") + 1;
                $trouves[] = [
                    'fichier' => $fichier,
                    'ligne' => $ligne,
                    'extrait' => mb_substr(trim($argument), 0, 70),
                ];
                break;
            }
        }
    }

    return $trouves;
}

test('toContain — TEMOIN NEGATIF : le balayage sait distinguer un message d une valeur', function () {
    $fautif = "expect(\$x)->toContain('/api/v1/chez-moi', 'La route ne rend rien du tout : ce vert ne prouverait rien.');";
    $legitime = "expect(\$slugs)->toContain('pays-ro', 'nature-association', 'size-pme');";

    // Sans ces deux cas, la garde ci-dessous ne prouverait rien : ni qu'elle
    // voit le defaut, ni qu'elle laisse passer l'usage normal.
    expect(appelsToContainAvecMessage($fautif, 'x.php'))->toHaveCount(1);
    expect(appelsToContainAvecMessage($legitime, 'x.php'))->toHaveCount(0);
});

test('toContain — aucun test du depot ne passe un MESSAGE a toContain', function () {
    $racine = base_path('tests');

    expect(is_dir($racine))->toBeTrue(
        "Le banc ne voit pas {$racine} : cette garde passerait au vert sur zero fichier.",
    );

    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));
    $fautifs = [];
    $inspectes = 0;

    foreach ($fichiers as $fichier) {
        if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }
        $inspectes++;
        $relatif = str_replace($racine . DIRECTORY_SEPARATOR, '', $fichier->getPathname());
        $source = (string) file_get_contents($fichier->getPathname());

        foreach (appelsToContainAvecMessage($source, $relatif) as $t) {
            $fautifs[] = "{$t['fichier']}:{$t['ligne']}  →  {$t['extrait']}…";
        }
    }

    // TEMOIN DE COUVERTURE : sans lui, un chemin faux rendrait zero fichier et
    // la garde passerait au vert sans rien inspecter. Le pire des verts.
    expect($inspectes)->toBeGreaterThan(
        50,
        "Seulement {$inspectes} fichiers de test inspectes : le balayage ne voit pas ce qu'il croit voir.",
    );

    expect($fautifs)->toBe(
        [],
        '`expect()->toContain()` de Pest est VARIADIQUE : tous ses arguments sont des VALEURS a '
        . 'chercher. Un message passe en second argument devient une deuxieme valeur, et la '
        . "garde cherche sa propre phrase d'explication dans la donnee qu'elle inspecte. Elle "
        . "rougit alors ETERNELLEMENT, en affichant exactement le message qu'on a ecrit -- si "
        . "bien qu'on croit avoir trouve le defaut qu'on decrivait.\n\n"
        . "Ce piege s'est referme SIX FOIS le 2026-08-20, dont trois APRES avoir ete documente "
        . "en tete de trois fichiers. Le savoir ne suffit pas.\n\n"
        . "Ecrire plutot :\n"
        . "  \$this->assertStringContainsString(\$aiguille, \$meule, \$message);\n"
        . "  \$this->assertContains(\$aiguille, \$tableau, \$message);\n\n"
        . "Occurrences :\n  - " . implode("\n  - ", $fautifs),
    );
});
