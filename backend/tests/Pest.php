<?php

use Tests\TestCase;

// Les 25 fichiers de `tests/Feature` déclarent eux-mêmes
// `uses(TestCase::class, RefreshDatabase::class)`. Étendre AUSSI le dossier ici
// faisait échouer Pest au chargement de la suite — « Test case Tests\TestCase
// can not be used. The folder … already uses the test case » — c'est-à-dire que
// la suite backend NE POUVAIT PAS DÉMARRER (échec masqué en CI par un
// `composer test || true`, cf. PR « fix/ci-reelle » du 2026-08-13).
// On n'étend donc que `Unit`, dont la majorité des fichiers ne déclare rien.
pest()->extend(TestCase::class)->in('Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): bool
{
    return true;
}
