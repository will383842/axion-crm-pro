<?php

/**
 * GARDE — audit 360, A07-009 (S3, documentation) : le runbook de la console
 * locale envoyait l'operateur chercher, dans un WORKTREE TIERS, un fichier
 * versionne a la racine du depot.
 *
 * Ce que disait `_REPORTS/RUNBOOK-CONSOLE-LOCALE.md` avant le 2026-08-22 :
 *
 *     docker compose \
 *       -f docker-compose.yml \
 *       -f C:/Users/willi/Documents/Projets/crmpro-wt-etape0/docker-compose.local.yml \
 *       up -d
 *
 * Or `docker-compose.local.yml` est SUIVI et vit a la racine du depot. Trois
 * autres lignes portaient le meme prefixe (`pnpm --dir .../crmpro-wt-etape0/
 * frontend`, deux fois, et une phrase du §1 qui affirmait que « la surcouche de
 * developpement vit dans le worktree »).
 *
 * Pourquoi c'est un defaut et pas une coquette : un worktree n'est pas un
 * livrable. Il porte le nom d'une etape (`etape0`), il est supprime quand
 * l'etape est soldee, et il n'existe QUE sur le poste de celui qui l'a cree.
 * Le premier operateur qui n'est pas Will suit le runbook a la lettre et
 * recoit `no such file or directory` — sur un document dont l'en-tete promet
 * que « tout ce qui suit a ete execute ».
 *
 * Cette garde ne relit pas la prose : elle verifie que les CHEMINS ABSOLUS
 * vers un worktree ont disparu, et que ce que le runbook nomme a leur place
 * existe reellement dans le depot.
 */

use Tests\TestCase;

uses(TestCase::class);

function cheminRunbookConsoleLocale(): string
{
    // `base_path()` vaut `backend/` : les rapports vivent un cran au-dessus.
    return (realpath(base_path('..')) ?: base_path('..')) . '/_REPORTS/RUNBOOK-CONSOLE-LOCALE.md';
}

test('A07-009 — le runbook de la console locale ne renvoie a AUCUN worktree', function () {
    $chemin = cheminRunbookConsoleLocale();

    // Lecture STRICTE : un runbook absent doit faire rougir, pas faire sauter
    // la garde en silence.
    expect(is_file($chemin))->toBeTrue(
        "A07-009 : `{$chemin}` est introuvable. Si le runbook a ete deplace, corriger le chemin de cette "
        . 'garde — ne pas la neutraliser.',
    );

    $contenu = (string) file_get_contents($chemin);

    // On cherche le CHEMIN, pas le mot : le §1 et le §9.1 mentionnent encore
    // `crmpro-wt-etape0` pour raconter l'historique de la mesure, et c'est
    // legitime. Ce qui ne l'est pas, c'est un chemin de fichier a suivre.
    expect(str_contains($contenu, 'Projets/crmpro-wt-'))->toBeFalse(
        'A07-009 : le runbook de la console locale porte a nouveau un chemin absolu vers un worktree '
        . "(`Projets/crmpro-wt-...`). Un worktree est supprime avec l'etape qu'il sert et n'existe que sur "
        . 'un seul poste : remplacer ce chemin par le fichier versionne du depot principal.',
    );
});

test('A07-009 — ce que le runbook nomme a la place existe vraiment dans le depot', function () {
    // Une garde qui se contenterait d'interdire l'ancien chemin certifierait ce
    // qu'elle n'inspecte pas : elle resterait verte si la correction avait
    // remplace le worktree par un fichier qui n'existe nulle part.
    $racine = realpath(base_path('..')) ?: base_path('..');
    $contenu = (string) file_get_contents(cheminRunbookConsoleLocale());

    expect(str_contains($contenu, '-f docker-compose.local.yml'))->toBeTrue(
        'A07-009 : le §1 du runbook ne pose plus `-f docker-compose.local.yml`. Sans la surcouche, la '
        . 'session ne prend pas (SESSION_DOMAIN, SANCTUM_STATEFUL_DOMAINS) et la console repond 419 : '
        . 'retablir le second `-f`.',
    );

    foreach (['docker-compose.yml', 'docker-compose.local.yml', 'frontend/tests/e2e/console-locale.spec.ts'] as $atteste) {
        expect(is_file($racine . '/' . $atteste))->toBeTrue(
            "A07-009 : le runbook fait jouer `{$atteste}` depuis la racine du depot, et ce fichier n'y est "
            . 'pas. Soit le fichier a bouge et le runbook ment a nouveau, soit il a ete supprime : mettre '
            . 'le runbook a jour AVANT de toucher a cette garde.',
        );
    }
});
