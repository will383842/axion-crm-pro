<?php

/**
 * LOT N2 — le workflow `.github/workflows/surveillance-canaux.yml` garde ses
 * promesses de structure : planifié chaque heure, borné, appelant une commande
 * qui EXISTE, traitant « serveur injoignable » comme une alerte, une issue
 * par type d'alerte, et jamais la sortie d'erreur de ssh dans une issue
 * publique (elle peut nommer le serveur).
 *
 * En CI (`actions/checkout`), la garde lit le vrai arbre.
 */

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function n2WorkflowSource(): string
{
    $chemin = (realpath(base_path('..')) ?: base_path('..')) . '/.github/workflows/surveillance-canaux.yml';
    expect(is_file($chemin))->toBeTrue("Workflow introuvable : {$chemin}");

    return (string) file_get_contents($chemin);
}

/** @return array<mixed> */
function n2Workflow(): array
{
    $wf = Yaml::parse(n2WorkflowSource());
    expect($wf)->toBeArray();

    /** @var array<mixed> $wf */
    return $wf;
}

test('planifié toutes les heures, et relançable à la main', function () {
    $wf = n2Workflow();
    // Selon la version de YAML, la clé `on` peut être lue comme le booléen true.
    $declencheurs = $wf['on'] ?? $wf[true] ?? $wf[1] ?? null;

    expect($declencheurs)->toBeArray()
        ->and($declencheurs)->toHaveKey('workflow_dispatch')
        ->and($declencheurs['schedule'][0]['cron'] ?? null)->toMatch('/^\d{1,2} \* \* \* \*$/');
});

test('chaque job porte une borne de durée (F38-008)', function () {
    $jobs = n2Workflow()['jobs'] ?? [];
    expect($jobs)->toHaveKeys(['verifier', 'alerte']);

    foreach ($jobs as $nom => $job) {
        expect(is_array($job) && array_key_exists('timeout-minutes', $job))->toBeTrue("Job « {$nom} » sans timeout-minutes");
    }
});

test('la commande appelée existe, et la mesure est en lecture seule (aucune option d’écriture)', function () {
    $source = n2WorkflowSource();

    expect($source)->toContain('php artisan crm:canaux:etat')
        ->and(array_keys(Artisan::all()))->toContain('crm:canaux:etat')
        ->and($source)->not->toContain('-u root');
});

test('« serveur injoignable » (ssh 255) est une alerte, et l’alerte suit un job de mesure en échec', function () {
    $wf = n2Workflow();
    $source = n2WorkflowSource();

    expect($source)->toContain('"$CODE" -eq 255')
        ->and($source)->toContain('controle_impossible')
        ->and($wf['jobs']['alerte']['if'] ?? null)->toBe('failure()')
        ->and($wf['jobs']['alerte']['needs'] ?? null)->toBe(['verifier']);
});

test('une issue par type : recherche d’une issue ouverte au même libellé avant d’en créer', function () {
    $source = n2WorkflowSource();

    expect($source)->toContain('gh issue list --repo "$DEPOT" --state open --label "$LIBELLE"')
        ->and($source)->toContain('gh issue create --repo "$DEPOT" --title "$TITRE" --label "$LIBELLE"');
});

test('la sortie d’erreur de ssh ne part JAMAIS dans une issue (dépôt public)', function () {
    $wf = n2Workflow();
    $alerte = json_encode($wf['jobs']['alerte'] ?? [], JSON_THROW_ON_ERROR);
    $sorties = json_encode($wf['jobs']['verifier']['outputs'] ?? [], JSON_THROW_ON_ERROR);

    expect($alerte)->not->toContain('erreurs.txt')
        ->and($sorties)->not->toContain('erreurs');
});
