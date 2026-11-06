<?php

namespace App\Envoi\Message;

use DateTimeImmutable;

/**
 * Tout ce qu'il faut pour composer UN message. Aucune valeur par défaut
 * « inventée » : l'entité, l'adresse postale et le texte d'information
 * art. 14 RGPD sont fournis par l'appelant (ils viennent de la configuration
 * juridique, jamais du code).
 *
 * Les gabarits (`sujet`, `texte`, `html`) peuvent contenir `{{variable|repli}}` :
 * la valeur de repli est OBLIGATOIRE.
 */
final class DemandeMessage
{
    /**
     * @param array<string,string> $variables valeurs des variables des gabarits
     * @param list<string> $references identifiants du fil (relance)
     * @param list<string> $piecesJointes toujours refusées : présent pour que le refus soit explicite
     */
    public function __construct(
        public readonly string $partieLocale,
        public readonly string $nomAffiche,
        public readonly string $replyTo,
        public readonly string $destinataire,
        public readonly string $sujet,
        public readonly string $texte,
        public readonly string $html,
        public readonly string $jetonEnvoi,
        public readonly string $urlDesinscription,
        public readonly string $mailtoDesinscription,
        public readonly string $entite,
        public readonly string $adressePostale,
        public readonly string $texteArt14,
        public readonly array $variables = [],
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly ?DateTimeImmutable $date = null,
        public readonly array $piecesJointes = [],
    ) {}
}
