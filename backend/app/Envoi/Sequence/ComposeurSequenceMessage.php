<?php

namespace App\Envoi\Sequence;

use App\Envoi\Desinscription\InscriptionsEnvoi;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Message\ComposeurMessage;
use App\Envoi\Message\DemandeMessage;
use RuntimeException;

/**
 * Compositeur de séquence (L8-COMPO) : branche `ComposeurSequence` sur `ComposeurMessage` pour UN espace.
 * Ajoute ce que `DemandeComposition` ne porte pas : lien et `mailto` de désinscription (jeton signé, référence
 * opaque, aucune donnée de personne), entité, adresse postale, texte article 14 (identité de l'espace, jamais inventée).
 * Le Message-ID RÉSERVÉ par le séquenceur remplace celui que `ComposeurMessage` tire au hasard : c'est lui qui est
 * écrit dans `envoi_messages_envoyes` et retrouvé par la relève. Sans signature DKIM côté CRM (le remplacement
 * invaliderait la signature) : le serveur signe. Aucun suivi d'ouverture.
 */
final class ComposeurSequenceMessage implements ComposeurSequence
{
    /** @param array<string, mixed> $identite lue par `SourceExpeditionDb` (déjà contrôlée : aucun champ vide) */
    public function __construct(private readonly ComposeurMessage $composeur, private readonly array $identite) {}

    public function composer(DemandeComposition $d): string
    {
        if (preg_match('/^<[^<>\s]+@[^<>\s]+>$/', $d->messageId) !== 1) {
            throw new RuntimeException('Message-ID réservé invalide.');
        }
        [$local] = explode('@', $d->expediteur, 2);
        $url = JetonDesinscription::url($d->workspaceId, InscriptionsEnvoi::creer($d->workspaceId, $d->destinataire));
        $entite = trim((string) $this->identite['raison_sociale'] . ' ' . (string) $this->identite['forme']);

        $brut = $this->composeur->composer(new DemandeMessage(
            partieLocale: $local,
            nomAffiche: (string) $this->identite['raison_sociale'],
            replyTo: $d->expediteur,
            destinataire: $d->destinataire,
            sujet: $d->sujet,
            texte: $d->corps,
            html: '<p>' . self::corpsHtml($d->corps) . '</p>',
            // C1 : ce jeton (dérivé du Message-ID) n'est plus émis ; il ne sert qu'au contrôle de forme de DemandeMessage.
            jetonEnvoi: substr(hash('sha256', $d->messageId), 0, 32),
            urlDesinscription: $url,
            mailtoDesinscription: 'mailto:' . $d->expediteur . '?subject=STOP',
            entite: $entite,
            adressePostale: (string) $this->identite['adresse_postale'],
            texteArt14: (string) $this->identite['bloc_information'],
            inReplyTo: $d->inReplyTo,
            variables: $d->variables,
            references: $d->references,
        ));

        $remplace = preg_replace('/^Message-ID: <[^>\r\n]*>/mi', 'Message-ID: ' . $d->messageId, $brut, 1, $n);
        if ($n !== 1 || $remplace === null) {
            throw new RuntimeException('En-tête Message-ID introuvable dans le message composé.');
        }

        return $remplace;
    }

    /**
     * Corps en HTML : le texte HORS des `{{nom|repli}}` est échappé ; les `{{…}}` restent intacts, car le rendu
     * (`ComposeurMessage::rendre`) échappe lui-même la valeur et le repli, UNE fois (sinon « l'équipe » deviendrait `&amp;#039;`).
     */
    private static function corpsHtml(string $corps): string
    {
        $parties = preg_split('/(\{\{.*?\}\})/s', $corps, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$corps];
        $html = '';
        foreach ($parties as $i => $partie) {
            $html .= $i % 2 === 1 ? $partie : nl2br(htmlspecialchars($partie, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $html;
    }
}
