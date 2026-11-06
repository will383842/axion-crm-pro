<?php

namespace App\Envoi\Message;

/**
 * Garde-fou du contenu : renvoie les MOTIFS (lisibles) pour lesquels un message
 * ne doit pas partir. Aucun envoi, aucun effet de bord.
 */
final class ControleurContenu
{
    /** @var list<string> hôtes/fragments typiques de traqueurs de clics ou d'ouvertures */
    private const TRACEURS = [
        'click.', 'clicks.', 'track.', 'tracking.', 'trk.', 'links.', 'redirect.', 'open.',
        'mandrillapp.com', 'sendgrid.net', 'list-manage.com', 'mailchi.mp', 'hubspotlinks',
        'mailtrack', 'mixmax', 'yesware', 'bit.ly', 'tinyurl.com',
    ];

    /**
     * @param list<string> $contenus  textes à contrôler (sujet, texte, HTML, pied, adresses, liens)
     * @param list<string> $piecesJointes
     * @return list<string> motifs de refus ; vide = accepté
     */
    public function analyser(array $contenus, array $piecesJointes = []): array
    {
        $tout = implode("\n", $contenus);
        $motifs = [];

        if ($piecesJointes !== [] || preg_match('/content-disposition\s*:\s*attachment/i', $tout) === 1) {
            $motifs[] = 'Pièce jointe interdite : le message ne doit contenir aucune pièce jointe.';
        }

        if (preg_match_all('/\{\{(.*?)\}\}/s', $tout, $m) > 0) {
            foreach ($m[1] as $interieur) {
                $morceaux = explode('|', $interieur, 2);
                $nom = trim($morceaux[0]);
                if (! isset($morceaux[1]) || trim($morceaux[1]) === '') {
                    $motifs[] = "Variable « {{{$nom}}} » sans valeur de repli : écrire {{{$nom}|valeur}}.";
                }
            }
        }

        if (preg_match('/(?<![a-z0-9-])axion-ia\.com/i', $tout) === 1) {
            $motifs[] = 'Lien ou adresse vers axion-ia.com (domaine principal) interdit : utiliser le domaine d\'envoi.';
        }

        if (preg_match('/grenoble/iu', $tout) === 1) {
            $motifs[] = 'Le mot « Grenoble » est interdit dans le message.';
        }

        if ($this->aPixelDeSuivi($tout)) {
            $motifs[] = 'Pixel ou balise de suivi interdit (image 1x1, image de suivi d\'ouverture).';
        }

        if ($this->aLienTraque($tout)) {
            $motifs[] = 'Lien réécrit ou redirigé par un traqueur interdit : les liens doivent pointer directement vers leur destination.';
        }

        return $motifs;
    }

    /**
     * @param list<string> $contenus
     * @param list<string> $piecesJointes
     */
    public function exiger(array $contenus, array $piecesJointes = []): void
    {
        $motifs = $this->analyser($contenus, $piecesJointes);
        if ($motifs !== []) {
            throw new MessageRefuse($motifs);
        }
    }

    private function aPixelDeSuivi(string $html): bool
    {
        if (preg_match_all('/<img\b[^>]*>/i', $html, $imgs) > 0) {
            foreach ($imgs[0] as $img) {
                if (preg_match('/\b(width|height)\s*=\s*["\']?\s*[01]\s*(px)?\s*["\'\s>\/]/i', $img) === 1
                    || preg_match('/(width|height)\s*:\s*[01]\s*(px)?\s*(;|["\'])/i', $img) === 1
                    || preg_match('/(pixel|beacon|track|open(ed)?\.(gif|png)|\/o\/|spacer)/i', $img) === 1) {
                    return true;
                }
            }
        }

        return preg_match('/<(img|link|iframe|script)\b[^>]*(tracking|beacon|pixel)/i', $html) === 1;
    }

    private function aLienTraque(string $contenu): bool
    {
        if (preg_match_all('#https?://[^\s"\'<>)]+#i', $contenu, $urls) === 0) {
            return false;
        }
        foreach ($urls[0] as $url) {
            $hote = strtolower((string) parse_url($url, PHP_URL_HOST));
            foreach (self::TRACEURS as $t) {
                if ($hote !== '' && str_contains($hote . '/', $t)) {
                    return true;
                }
            }
            $requete = (string) parse_url($url, PHP_URL_QUERY);
            // Paramètre portant une autre URL : signature d'une redirection.
            if (preg_match('/(^|&)(url|u|redirect|redirect_uri|target|dest|link|r)=(https?(%3a|:)|%2f%2f)/i', $requete) === 1) {
                return true;
            }
        }

        return false;
    }
}
