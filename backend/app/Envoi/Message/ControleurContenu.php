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
     * @param  list<string>  $contenus  textes à contrôler (sujet, texte, pied, adresses, liens)
     * @param  list<string>  $piecesJointes
     * @param  list<string>  $html  fragments HTML : contrôlés par LISTE BLANCHE stricte, refusés (jamais nettoyés)
     * @return list<string> motifs de refus ; vide = accepté
     */
    public function analyser(array $contenus, array $piecesJointes = [], array $html = []): array
    {
        $brut = implode("\n", [...$contenus, ...$html]);
        // Anti-contournement : entités (même sans « ; »), %xx, caractères invisibles, point idéographique.
        $tout = self::normaliser($brut);
        $sansBalises = self::normaliser((string) preg_replace('/<[^>]*>/', '', $tout));
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

        foreach ($html as $fragment) {
            foreach (HtmlListeBlanche::refus($fragment) as $motif) {
                $motifs[] = $motif;
            }
        }

        if ($this->aLienTraque($tout)) {
            $motifs[] = 'Lien réécrit ou redirigé par un traqueur interdit : les liens doivent pointer directement vers leur destination.';
        }

        return array_values(array_unique($motifs));
    }

    /**
     * @param  list<string>  $contenus
     * @param  list<string>  $piecesJointes
     * @param  list<string>  $html
     */
    public function exiger(array $contenus, array $piecesJointes = [], array $html = []): void
    {
        $motifs = $this->analyser($contenus, $piecesJointes, $html);
        if ($motifs !== []) {
            throw new MessageRefuse($motifs);
        }
    }

    /** Forme canonique pour la recherche de motifs interdits (jamais utilisée pour envoyer). */
    private static function normaliser(string $v): string
    {
        for ($i = 0; $i < 3; $i++) {
            // Entités numériques décodées même sans « ; » final (les clients mail le font), puis nommées.
            $v = (string) preg_replace_callback('/&#(x[0-9a-f]{1,6}|[0-9]{1,7});?/i', static function (array $m): string {
                $cp = strtolower($m[1][0]) === 'x' ? hexdec(substr($m[1], 1)) : (int) $m[1];

                return $cp > 0 && $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF) ? mb_chr($cp, 'UTF-8') : '';
            }, $v);
            $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $v = rawurldecode($v);
        }
        // Soft-hyphen, espaces de largeur nulle, BOM, joiners.
        $v = (string) preg_replace('/[\x{00AD}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}\x{034F}\x{180E}]/u', '', $v);
        if (class_exists(\Normalizer::class)) {
            $v = \Normalizer::normalize($v, \Normalizer::FORM_KC) ?: $v;
        }

        // Points typographiques ou idéographiques équivalents à « . ».
        return strtr($v, ["\u{3002}" => '.', "\u{FF0E}" => '.', "\u{FF61}" => '.', "\u{FE52}" => '.', "\u{2024}" => '.']);
    }

    /** Hôte normalisé : minuscules, point final retiré, punycode (IDN). */
    private static function normaliserHote(string $hote): string
    {
        $hote = rtrim(strtolower(trim($hote)), '.');
        if ($hote !== '' && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($hote, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $hote = rtrim($ascii, '.');
            }
        }

        return $hote;
    }

    private function aLienTraque(string $contenu): bool
    {
        // https://x, http://x, //x, https:\\x, \\x : tout ce qui désigne un hôte.
        if (preg_match_all('#(?:https?:)?[/\\\\]{2}[^\s"\'<>)]+#i', $contenu, $urls) === 0) {
            return false;
        }
        foreach ($urls[0] as $brut) {
            $url = 'https://' . ltrim((string) preg_replace('#^(?:https?:)?[/\\\\]+#i', '', str_replace('\\', '/', $brut)), '/');
            $hote = self::normaliserHote((string) parse_url($url, PHP_URL_HOST));
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
            if (preg_match('/(^|&)[^=&]*=(https?(%3a|:)|%2f%2f|\/\/)/i', $requete) === 1) {
                return true;
            }
        }

        return false;
    }
}
