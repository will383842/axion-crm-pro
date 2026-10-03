<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * Le résumé de « Santé du système » a été calculé, mais au moins une rubrique
 * est tombée dans son filet (F39-007 : journalisée, valeur neutre rendue).
 *
 * Levée DEPUIS le calcul mis en cache (`Cache::flexible`) pour qu'un résumé
 * incomplet ne soit JAMAIS écrit dans le cache : ses zéros de repli y
 * passeraient pour des mesures pendant une heure. Le contrôleur la rattrape et
 * sert le résumé à l'appelant, sans le garder.
 *
 * Ce n'est pas une panne à remonter : la panne de chaque rubrique est déjà
 * journalisée là où elle s'est produite. D'où `ShouldntReport` : dans le
 * recalcul différé, Laravel la rattrape par `rescue()` (file des rappels
 * différés) et la SIGNALERAIT sinon au gestionnaire d'exceptions — une erreur
 * au journal et dans Sentry toutes les 5 min, pour une panne déjà journalisée.
 */
final class ResumeObservabiliteIncomplet extends \RuntimeException implements ShouldntReport
{
    /** @param  array<string, mixed>  $resume */
    public function __construct(public readonly array $resume)
    {
        parent::__construct('Résumé de santé du système incomplet : non mis en cache.');
    }
}
