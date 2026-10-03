<?php

namespace App\Exceptions;

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
 * journalisée là où elle s'est produite.
 */
final class ResumeObservabiliteIncomplet extends \RuntimeException
{
    /** @param  array<string, mixed>  $resume */
    public function __construct(public readonly array $resume)
    {
        parent::__construct('Résumé de santé du système incomplet : non mis en cache.');
    }
}
