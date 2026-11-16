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

    public static function sansSequence(string $vers, ?string $message = null): self
    {
        return (new self($message ?? "Le statut « {$vers} » est impossible : cette campagne n'a pas de séquence.", self::STATUT_HTTP))->code('sans_sequence');
    }

    /** Préparation (matérialisation) absente ou inachevée : seuls les comptes sont dits, jamais une adresse. */
    public static function materialisationEnCours(int $inscrits, ?int $membres): self
    {
        $sur = $membres === null ? '' : " sur {$membres}";

        return (new self("La préparation n'est pas terminée : {$inscrits} destinataire(s) inscrit(s){$sur}. Réessayez quand elle sera finie.", self::STATUT_HTTP))->code('materialisation_en_cours');
    }

    /** Préparation bloquée ; `$motif` vient de la liste fermée de la base. */
    public static function materialisationBloquee(string $motif): self
    {
        return (new self("La préparation est bloquée (motif : {$motif}) : la campagne ne peut pas être lancée.", self::STATUT_HTTP))->code('materialisation_bloquee');
    }

    public static function aucunDestinataire(): self
    {
        return (new self('Personne à écrire : tous les destinataires ont été écartés. Arrêtez la campagne.', self::STATUT_HTTP))->code('aucun_destinataire');
    }

    /** La base a refusé le geste « reprise » de la séquence (déclencheur) : tout est annulé. */
    public static function repriseRefuseeBase(): self
    {
        return (new self('La base a refusé la reprise de la séquence : rien n\'a été changé.', self::STATUT_HTTP))->code('reprise_refusee_base');
    }

    /** Verrou occupé au-delà du délai (lock_timeout) ou interblocage détecté : à réessayer. */
    public static function concurrence(): self
    {
        return (new self('Une autre opération touche cette campagne : réessayez dans un instant.', self::STATUT_HTTP))->code('concurrence');
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
