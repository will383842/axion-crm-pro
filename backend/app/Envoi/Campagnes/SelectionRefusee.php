<?php

namespace App\Envoi\Campagnes;

/**
 * X1-L2 — refus de la sélection, motif dans une liste fermée. Jamais de `QueryException` brute vers l'appelant.
 */
final class SelectionRefusee extends \RuntimeException
{
    public function __construct(public readonly string $motif, public readonly bool $reessayable, string $message, ?\Throwable $prev = null)
    {
        parent::__construct($message, 0, $prev);
    }

    public static function populationNonOuverte(): self
    {
        return new self('population_non_ouverte', false, 'Cette population n\'est pas ouverte à la sélection.');
    }

    public static function cibleIntrouvable(): self
    {
        return new self('cible_introuvable', false, 'La cible de la campagne est introuvable.');
    }

    public static function cibleNonSupportee(): self
    {
        return new self('cible_non_supportee', false, 'Une cible par identifiants de contacts n\'est pas prise en charge.');
    }

    public static function lectureOppositionImpossible(\Throwable $prev): self
    {
        return new self('lecture_opposition_impossible', true, 'Les exclusions n\'ont pas pu être lues : aucun destinataire n\'est rendu.', $prev);
    }

    public static function lectureTropLongue(): self
    {
        return new self('lecture_trop_longue', true, 'La lecture a pris trop de temps : réessayez avec un lot plus petit.');
    }

    public static function filtreOrigineInactif(): self
    {
        return new self('filtre_origine_inactif', false, 'Le filtre par réseau d\'origine est inactif dans cet espace : rien n\'est sélectionné.');
    }

    public static function lectureOrigineImpossible(\Throwable $prev): self
    {
        return new self('lecture_origine_impossible', true, 'Les origines n\'ont pas pu être lues : aucun destinataire n\'est rendu.', $prev);
    }

    public static function campagneIntrouvable(): self
    {
        return new self('campagne_introuvable', false, 'Campagne introuvable.');
    }

    public static function tailleInvalide(): self
    {
        return new self('taille_invalide', false, 'La taille du lot est de 1 à 500 et le curseur positif ou nul.');
    }
}
