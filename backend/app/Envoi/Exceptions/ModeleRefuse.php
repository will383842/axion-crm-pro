<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Écriture de modèle refusée (modèle introuvable dans l'espace, modèle archivé). */
final class ModeleRefuse extends RuntimeException
{
    /** Code machine stable du contrat (`Erreur.error`) quand le statut HTTP seul ne suffit pas. */
    public ?string $codeMachine = null;

    public static function versionPerimee(int $base, int $courante): self
    {
        $e = new self("La version de base ({$base}) n'est plus la dernière ({$courante}) : relisez avant d'enregistrer.", 409);
        $e->codeMachine = 'version_perimee';

        return $e;
    }

    public static function introuvable(): self
    {
        return new self('Modèle introuvable dans cet espace.', 404);
    }

    public static function transition(string $de, string $vers): self
    {
        return new self("Transition de modèle interdite : {$de} -> {$vers}.", 409);
    }

    public static function contenu(string $raison): self
    {
        return new self("Modèle refusé : {$raison}.", 422);
    }

    public static function archive(): self
    {
        return new self('Modèle archivé : plus de nouvelle version.', 409);
    }
}
