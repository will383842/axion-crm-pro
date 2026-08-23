<?php

/**
 * GARDE D'INFRASTRUCTURE — audit 360, constat A-003 / A09-005 (S2).
 *
 * LE DÉFAUT, ET POURQUOI IL EST INVISIBLE.
 *
 * Le dépôt a reçu un `.gitattributes` le 2026-08-19 (`* text=auto eol=lf`,
 * `*.sh text eol=lf`). Poser le fichier ne suffit pas : git n'applique la règle
 * qu'aux copies **matérialisées après**. Les fichiers déjà présents sur disque
 * restent tels quels — LF dans l'index, CRLF dans la copie de travail.
 *
 * Et c'est exactement ce qui s'était produit. Mesure du 2026-08-22 sur la copie
 * de travail principale (`C:/Users/willi/Documents/Projets/Axion-CRM-Pro`) :
 * **huit** scripts sur seize étaient encore en CRLF, alors que le worktree créé
 * après le `.gitattributes` les portait tous en LF.
 *
 *     backend/database/perf/mesure_reference.sh    infra/scripts/dr-drill.sh
 *     infra/docker/entrypoint-prod.sh              infra/scripts/setup-backup.sh
 *     infra/scripts/backup-postgres.sh             infra/scripts/setup-hetzner-cpx22.sh
 *     infra/scripts/configure-prod-env.sh          infra/scripts/verifier-sauvegarde.sh
 *
 * Ce défaut est SILENCIEUX côté dépôt : `git diff` ne montre rien et la CI est
 * verte, puisque ce qui est **enregistré** est correct. Il ne se voit que le
 * jour où l'on envoie un de ces fichiers directement sur un serveur Linux, sans
 * passer par git — un `scp`, un `docker cp`, un copier-coller :
 *
 *     /root/verifier-ports-publies.sh: line 39: $'\r': command not found
 *
 * Un script shell avec des retours chariot est inexécutable sous Linux. Trois
 * des huit fichiers ci-dessus sont précisément ceux qu'on envoie ainsi
 * (`configure-prod-env.sh`, `setup-hetzner-cpx22.sh`, `verifier-sauvegarde.sh`).
 *
 * CE QUE CETTE GARDE PROUVE, ET CE QU'ELLE NE PROUVE PAS.
 *
 * Elle lit la COPIE DE TRAVAIL — c'est tout l'intérêt, puisque le mensonge est
 * là et nulle part ailleurs. Elle rougit donc dans l'arbre où la
 * renormalisation n'a pas eu lieu, et reste verte partout ailleurs. Elle ne
 * prouve rien sur les autres worktrees ni sur ce qui tourne sur le serveur :
 * chaque copie doit être renormalisée pour son propre compte.
 *
 * LE GESTE DE RÉPARATION, quand elle rougit :
 *
 *     git add --renormalize -- '*.sh'     # puis commit
 *
 * ou, sans passer par l'index : réécrire les fichiers cités en remplaçant
 * `\r\n` par `\n`. Les deux donnent le même contenu ; le second ne produit
 * aucun diff, puisque l'index était déjà en LF.
 */

use Tests\TestCase;

uses(TestCase::class);

/** Racine du dépôt vue depuis l'application Laravel. */
function racineDepotFinsDeLigne(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les scripts shell VERSIONNÉS du dépôt, en ne se fiant PAS à
 * `RecursiveDirectoryIterator`.
 *
 * ⚠️ Mesure de cette campagne : cet itérateur a TRONQUÉ le parcours dans 14
 * gardes sur 56 — il rendait 42 fichiers sur 300 sans le dire. Une garde qui
 * inspecte moins de fichiers qu'elle ne croit passe au vert pour rien.
 *
 * On écarte `node_modules`, `vendor`, `.git` et les artefacts de build : ces
 * fichiers ne sont pas versionnés, ne sont pas soumis au `.gitattributes`, et
 * leurs fins de ligne ne sont pas notre affaire.
 *
 * @return list<string>
 */
function scriptsShellVersionnes(string $racine): array
{
    $ignores = ['node_modules', 'vendor', '.git', 'storage', 'dist', 'build', '.pnpm-store'];
    $trouves = [];
    $entrees = scandir($racine);

    if ($entrees === false) {
        return [];
    }

    foreach ($entrees as $entree) {
        if ($entree === '.' || $entree === '..' || in_array($entree, $ignores, true)) {
            continue;
        }

        $chemin = $racine . DIRECTORY_SEPARATOR . $entree;

        if (is_dir($chemin)) {
            $trouves = array_merge($trouves, scriptsShellVersionnes($chemin));

            continue;
        }

        if (str_ends_with($entree, '.sh')) {
            $trouves[] = $chemin;
        }
    }

    return $trouves;
}

/** Un contenu porte-t-il au moins un retour chariot de fin de ligne ? */
function porteDesFinsDeLigneWindows(string $contenu): bool
{
    return str_contains($contenu, "\r\n");
}

/**
 * TÉMOIN NÉGATIF — sans lui, cette garde pourrait ne rien savoir détecter et
 * passer au vert sur un arbre entièrement en CRLF.
 */
test('A-003 — TEMOIN : la garde sait distinguer un fichier CRLF d un fichier LF', function () {
    expect(porteDesFinsDeLigneWindows("#!/bin/sh\r\necho ok\r\n"))->toBeTrue(
        'La garde ne reconnait pas un contenu CRLF : elle ne peut donc rien prouver. '
        . 'Repare `porteDesFinsDeLigneWindows()` avant de croire son vert.',
    );

    expect(porteDesFinsDeLigneWindows("#!/bin/sh\necho ok\n"))->toBeFalse(
        'La garde crie au loup sur un contenu deja en LF : elle rougira eternellement. '
        . 'Repare `porteDesFinsDeLigneWindows()`.',
    );
});

/**
 * La règle doit rester posée : sans elle, toute copie fraîche repart en CRLF et
 * le défaut revient de lui-même à la prochaine `git clone` sous Windows.
 */
test('A-003 — la regle .gitattributes qui force le LF est toujours posee', function () {
    $chemin = racineDepotFinsDeLigne() . '/.gitattributes';

    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$chemin}. Sans `.gitattributes`, git rematerialise les scripts "
        . 'en CRLF sous Windows a chaque `git clone`, et le constat A-003 renait entier. '
        . 'Remets le fichier avant de toucher a cette garde.',
    );

    $contenu = (string) file_get_contents($chemin);

    foreach (['* text=auto eol=lf', '*.sh'] as $regle) {
        expect(str_contains($contenu, $regle))->toBeTrue(
            "La regle `{$regle}` a disparu de .gitattributes. C'est elle qui force le LF dans la "
            . 'COPIE DE TRAVAIL, pas seulement dans l index — la retirer rend le defaut A-003 '
            . 'silencieux a nouveau. Restaure la ligne.',
        );
    }
});

test('A-003 — aucun script shell du depot n est en CRLF dans la copie de travail', function () {
    $racine = racineDepotFinsDeLigne();
    $scripts = scriptsShellVersionnes($racine);

    // TÉMOIN DE COUVERTURE : sans lui, une racine fausse rendrait zéro fichier
    // et la garde certifierait l'absence d'un défaut qu'elle n'a pas inspecté.
    // Mesure du 2026-08-22 : 16 scripts dans l'arbre principal, 18 dans ce
    // worktree. Le plancher est volontairement sous les deux.
    expect(count($scripts))->toBeGreaterThan(
        12,
        'Seulement ' . count($scripts) . " scripts .sh inspectes sous {$racine} : le balayage ne "
        . "voit pas ce qu'il croit voir. Monte la racine du depot avant de croire ce vert.",
    );

    $fautifs = [];
    foreach ($scripts as $chemin) {
        if (porteDesFinsDeLigneWindows((string) file_get_contents($chemin))) {
            $fautifs[] = str_replace($racine . DIRECTORY_SEPARATOR, '', $chemin);
        }
    }

    expect($fautifs)->toBe(
        [],
        'Ces scripts shell sont en CRLF dans la COPIE DE TRAVAIL. Envoyes tels quels sur un '
        . "serveur Linux (scp, docker cp, copier-coller), ils echouent des la premiere ligne :\n"
        . "    line 39: \$'\\r': command not found\n\n"
        . 'Le defaut est invisible cote depot — `git diff` ne montre rien, la CI est verte — car '
        . "l index, lui, est bien en LF. Seule la copie de travail ment.\n\n"
        . "GESTE : `git add --renormalize -- '*.sh'` puis commit ; ou reecrire ces fichiers en "
        . "remplacant \\r\\n par \\n, ce qui ne produit aucun diff.\n\n"
        . "Fichiers :\n  - " . implode("\n  - ", $fautifs),
    );
});
