<?php

/**
 * GARDE — audit 360, B16-014 (S3, documentation) : le runbook de rotation des
 * secrets prescrivait TROIS commandes artisan qui n'existent pas.
 *
 * Mesure du 2026-08-22, `infra/runbooks/05-rotate-secrets.md` :
 *   - :78 `php artisan model:rotate-keys --tables=users`
 *   - :87 « Marquer le breakpoint via `audit:checkpoint` artisan command »
 *   - :93 `php artisan llm:smoke-test`
 * Aucune des trois n'est déclarée dans `backend/app/Console/Commands/`, ni par
 * le framework.
 *
 * Pourquoi c'est plus qu'une coquille : ce runbook se déroule APRÈS avoir
 * changé un secret, c'est-à-dire à un moment où l'on ne peut plus revenir en
 * arrière. L'opérateur arrivait au pas 4 et tombait sur un
 * `Command "..." is not defined` — bloqué, en incident, sans savoir si la
 * rotation est finie ou à moitié faite.
 *
 * CE QUE CETTE GARDE INSPECTE, et rien d'autre : chaque occurrence de
 * `artisan <commande>` dans ce runbook doit correspondre à une commande
 * RÉELLEMENT enregistrée. Elle n'énumère pas les trois noms fautifs à la main —
 * une liste écrite à la main ne dirait rien de la QUATRIÈME commande fantôme
 * qu'on ajoutera demain.
 */

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

/** Les runbooks vivent à la racine du dépôt, pas dans `backend/`. */
function cheminRunbookRotationSecrets(): string
{
    return (realpath(base_path('..')) ?: base_path('..')) . '/infra/runbooks/05-rotate-secrets.md';
}

test('B16-014 — toute commande `artisan ...` du runbook de rotation existe reellement', function () {
    $chemin = cheminRunbookRotationSecrets();
    $this->assertFileExists($chemin, 'Le runbook « rotation des secrets » est introuvable.');
    $contenu = (string) file_get_contents($chemin);

    // TÉMOIN — c'est bien ce runbook, et il prescrit toujours des commandes
    // artisan : sans cela, la garde passerait au vert sur un fichier vidé.
    $this->assertStringContainsString(
        'AUDIT_HASH_CHAIN_SECRET',
        $contenu,
        "Ce n'est pas le runbook de rotation des secrets : la garde ne mesure plus rien.",
    );

    // Un nom de commande artisan : `groupe:verbe-compose` ou `verbe` seul.
    preg_match_all('/\bartisan\s+([a-z][a-z0-9:_-]*)/i', $contenu, $trouvees);
    $citees = array_values(array_unique($trouvees[1] ?? []));

    $this->assertNotEmpty(
        $citees,
        'Aucune commande artisan trouvée dans le runbook : soit le fichier a changé '
        . "de forme, soit l'expression d'extraction ne mord plus. Une garde qui "
        . "n'inspecte plus rien doit rougir, pas verdir.",
    );

    $enregistrees = array_keys(Artisan::all());
    $fantomes = array_values(array_diff($citees, $enregistrees));

    expect($fantomes)->toBe(
        [],
        'B16-014 rouvert : le runbook infra/runbooks/05-rotate-secrets.md prescrit '
        . 'des commandes artisan qui n existent pas — ' . implode(', ', $fantomes) . '. '
        . 'Un operateur les atteint APRES avoir change le secret, donc sans retour '
        . 'possible, et tombe sur `Command is not defined`. Geste : soit ecrire la '
        . 'commande dans backend/app/Console/Commands/, soit reecrire le pas du '
        . 'runbook en disant explicitement qu il N EST PAS OUTILLE et ce qu il faut '
        . 'faire a la place.',
    );
});
