<?php

namespace App\Envoi\Message;

/**
 * Garde-fou du contenu : renvoie les MOTIFS (lisibles) pour lesquels un message
 * ne doit pas partir. Aucun envoi, aucun effet de bord.
 */
final class ControleurContenu
{
    /**
     * Traqueurs de liens : sous-domaines typiques (préfixe de hôte, finissant par « . ») ou domaines
     * entiers. « open. » et « links. » seuls sont trop larges (open.spotify.com) : retirés ; les
     * pixels d'ouverture sont de toute façon couverts par le refus de toute image.
     *
     * @var list<string>
     */
    private const TRACEURS = [
        'click.', 'clicks.', 'track.', 'tracking.', 'trk.', 'redirect.', 'email-tracking.',
        'mandrillapp.com', 'sendgrid.net', 'list-manage.com', 'mailchi.mp', 'hubspotlinks.com',
        'mailtrack.io', 'mixmax.com', 'yesware.com', 'bit.ly', 'tinyurl.com', 'ct.sendgrid.net',
    ];

    /**
     * @param  list<string>  $contenus  textes à contrôler (sujet, texte, HTML, pied, adresses, liens)
     * @param  list<string>  $piecesJointes
     * @return list<string> motifs de refus ; vide = accepté
     */
    public function analyser(array $contenus, array $piecesJointes = []): array
    {
        $brut = implode("\n", $contenus);
        // Anti-contournement : entités HTML décodées (deux fois), puis version sans balises.
        $tout = self::decoder($brut);
        $sansBalises = self::decoder((string) preg_replace('/<[^>]*>/', '', $tout));
        $motifs = [];

        if ($piecesJointes !== [] || preg_match('/content-disposition\s*:\s*attachment/i', $tout) === 1) {
            $motifs[] = 'Pièce jointe interdite : le message ne doit contenir aucune pièce jointe.';
        }

        if (preg_match_all('/\{\{(.*?)\}\}/s', $brut, $m) > 0) {
            foreach ($m[1] as $interieur) {
                $morceaux = explode('|', $interieur, 2);
                $nom = trim($morceaux[0]);
                if (! isset($morceaux[1]) || trim($morceaux[1]) === '') {
                    $motifs[] = "Variable « {{{$nom}}} » sans valeur de repli : écrire {{{$nom}|valeur}}.";
                }
            }
        }

        if (preg_match('/(?<![a-z0-9-])axion-ia\.com/i', $tout) === 1
            || preg_match('/(?<![a-z0-9-])axion-ia\.com/i', $sansBalises) === 1) {
            $motifs[] = 'Lien ou adresse vers axion-ia.com (domaine principal) interdit : utiliser le domaine d\'envoi.';
        }

        if (preg_match('/grenoble/iu', $tout) === 1 || preg_match('/grenoble/iu', $sansBalises) === 1) {
            $motifs[] = 'Le mot « Grenoble » est interdit dans le message.';
        }

        if ($this->aPixelDeSuivi($tout)) {
            $motifs[] = 'Pixel ou image interdits : aucune image (distante, intégrée, masquée ou de suivi d\'ouverture) ni ressource externe dans un message du palier 0, aucune mesure d\'ouverture.';
        }

        if ($this->aLienTraque($tout)) {
            $motifs[] = 'Lien réécrit ou redirigé par un traqueur interdit : les liens doivent pointer directement vers leur destination.';
        }

        return $motifs;
    }

    /**
     * @param  list<string>  $contenus
     * @param  list<string>  $piecesJointes
     */
    public function exiger(array $contenus, array $piecesJointes = []): void
    {
        $motifs = $this->analyser($contenus, $piecesJointes);
        if ($motifs !== []) {
            throw new MessageRefuse($motifs);
        }
    }

    private static function decoder(string $v): string
    {
        for ($i = 0; $i < 2; $i++) {
            $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $v;
    }

    /** Palier 0 : AUCUNE image ni ressource externe (donc aucun pixel, quelle que soit sa dissimulation). */
    private function aPixelDeSuivi(string $html): bool
    {
        return preg_match('/<\s*(img|image|picture|source|video|audio|embed|object|iframe|script|link|input)\b/i', $html) === 1
            || preg_match('/\burl\s*\(|@import|\bbackground\s*=|\bsrcset\s*=/i', $html) === 1;
    }

    private function aLienTraque(string $contenu): bool
    {
        if (preg_match_all('#https?://[^\s"\'<>)]+#i', $contenu, $urls) === 0) {
            return false;
        }
        foreach ($urls[0] as $url) {
            $hote = strtolower((string) parse_url($url, PHP_URL_HOST));
            foreach (self::TRACEURS as $t) {
                $prefixe = str_ends_with($t, '.');
                if ($hote !== '' && ($prefixe
                    ? (str_starts_with($hote, $t) || str_contains($hote, '.' . $t))
                    : ($hote === $t || str_ends_with($hote, '.' . $t)))) {
                    return true;
                }
            }
            $requete = (string) parse_url($url, PHP_URL_QUERY);
            // Tout paramètre dont la valeur est une autre URL : signature d'une redirection.
            if (preg_match('/(^|&)[^=&]*=(https?(%3a|:)|%2f%2f)/i', $requete) === 1) {
                return true;
            }
        }

        return false;
    }
}
