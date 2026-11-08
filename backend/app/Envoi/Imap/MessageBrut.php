<?php

namespace App\Envoi\Imap;

use DateTimeImmutable;

/**
 * Un message lu dans une boîte d'envoi — EN MÉMOIRE SEULEMENT.
 *
 * Le corps et les adresses ne sortent jamais de ce cycle de lecture : on ne
 * persiste que des empreintes et la classe du retour (voir `ReleveImap`).
 */
final class MessageBrut
{
    /**
     * @param  list<string>  $references  en-tête References, Message-ID sans chevrons
     * @param  array<string, string>  $enTetes  en-têtes utiles, clés en minuscules
     * @param  ?string  $statutDsn  « 5.1.1 », « 4.2.2 »… si c'est un rapport de remise
     * @param  ?string  $messageIdDsn  Message-ID du message d'origine cité par le rapport
     * @param  ?string  $destinataireDsn  Final-Recipient du rapport
     * @param  ?string  $empreinteContenu  SHA-256 du message brut (stable : indépendant des UID) ; null si non lu
     * @param  bool  $analyseEchouee  l'analyse du texte a échoué (UTF-8 invalide, limite PCRE) : traité en STOP par prudence
     * @param  ?string  $typeArf  Feedback-Type d'un rapport ARF (« abuse », « fraud », « not-spam »…) ; son Message-ID cité est dans `messageIdDsn`
     * @param  ?string  $destinataireArf  Original-Rcpt-To du rapport ARF
     * @param  list<string>  $adressesLocales  adresses du message reçu et Original-Mail-From : VERP, abuse@
     * @param  ?string  $ecarte  « taille » ou « illisible » : message non lu, classé « à rattacher » sans effet
     */
    public function __construct(
        public readonly int $uid,
        public readonly ?string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $expediteur,
        public readonly DateTimeImmutable $recuLe,
        public readonly string $sujet,
        public readonly string $corps,
        public readonly array $enTetes = [],
        public readonly ?string $statutDsn = null,
        public readonly ?string $messageIdDsn = null,
        public readonly ?string $destinataireDsn = null,
        public readonly ?string $ecarte = null,
        public readonly ?string $empreinteContenu = null,
        public readonly bool $analyseEchouee = false,
        public readonly ?string $typeArf = null,
        public readonly ?string $destinataireArf = null,
        public readonly array $adressesLocales = [],
    ) {}
}
