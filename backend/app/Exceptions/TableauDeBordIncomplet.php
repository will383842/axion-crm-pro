<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * Les chiffres du tableau de bord ont été calculés, mais au moins un compteur
 * est tombé dans son filet (journalisé, rendu `null`).
 *
 * Même mécanique que `ResumeObservabiliteIncomplet` (F39-007) : levée DEPUIS
 * le calcul confié à `Cache::flexible`, c'est la seule façon d'empêcher
 * `flexible` d'écrire ce que rend le calcul. Un résultat partiel ne doit
 * JAMAIS entrer dans le cache : ses `null` y resteraient trente minutes alors
 * que la requête suivante aurait peut-être réussi.
 *
 *  - Dans la requête : `DashboardController::stats()` la rattrape et sert les
 *    chiffres partiels à l'écran, sans les garder.
 *  - Dans le recalcul différé : Laravel la rattrape par `rescue()`. La valeur
 *    en cache reste l'ancienne, complète ; le recalcul suivant retentera.
 *    `ShouldntReport` : chaque panne de compteur est déjà journalisée là où
 *    elle s'est produite, inutile de la remonter une seconde fois à Sentry.
 */
final class TableauDeBordIncomplet extends \RuntimeException implements ShouldntReport
{
    /** @param  array<string, mixed>  $chiffres */
    public function __construct(public readonly array $chiffres)
    {
        parent::__construct('Chiffres du tableau de bord incomplets : non mis en cache.');
    }
}
