<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Écriture de campagne ou de modèle refusée (transition, contenu, version périmée). Code 409 : conflit d'état. */
final class CampagneRefusee extends RuntimeException
{
    public const STATUT_HTTP = 409;

    /** Code machine stable de la réponse JSON (`error`). */
    public string $codeErreur = 'conflit';

    private function code(string $code): self
    {
        $this->codeErreur = $code;

        return $this;
    }

    /** Reprise refusée : un frein (arrêt général ou pause automatique) est actif. */
    public static function frein(string $code, string $raison): self
    {
        return (new self($raison, self::STATUT_HTTP))->code($code);
    }

    public static function transition(string $de, string $vers): self
    {
        return (new self("Transition de campagne interdite : {$de} -> {$vers}.", self::STATUT_HTTP))->code('transition_refusee');
    }

    public static function sansSequence(string $vers): self
    {
        return (new self("Le statut « {$vers} » est impossible : cette campagne n'a pas de séquence.", self::STATUT_HTTP))->code('sans_sequence');
    }

    public static function contenu(string $raison): self
    {
        return (new self("Campagne refusée : {$raison}.", 422))->code('validation');
    }

    public static function versionPerimee(): self
    {
        return (new self('Conflit : la campagne a changé depuis votre lecture (version périmée).', self::STATUT_HTTP))->code('version_perimee');
    }

    public static function cleReutilisee(): self
    {
        return (new self('Clé d\'idempotence déjà utilisée pour une autre campagne ou une autre transition.', self::STATUT_HTTP))->code('cle_reutilisee');
    }

    public static function cleCodeAbsente(): self
    {
        return (new self('Codes de campagne indisponibles : clé serveur absente ou trop courte (porte fermée).', 503))->code('indisponible');
    }

    public static function introuvable(): self
    {
        return (new self('Introuvable dans cet espace.', 404))->code('non_trouve');
    }
}
