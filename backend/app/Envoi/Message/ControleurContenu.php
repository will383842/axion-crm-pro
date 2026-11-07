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
        // En cas de doute (regex en échec, Normalizer absent), on BLOQUE : jamais de contrôle aveugle.
        try {
            $tout = self::normaliser($brut);
            $nu = preg_replace('/<[^>]*>/', '', $tout);
            if ($nu === null) {
                throw self::doute();
            }
            $sansBalises = self::normaliser($nu);
        } catch (MessageRefuse $e) {
            return $e->motifs;
        }
        $motifs = [];

        try {
            return $this->controler($tout, $sansBalises, $brut, $piecesJointes, $html, $motifs);
        } catch (MessageRefuse $e) {
            return $e->motifs;
        }
    }

    /**
     * @param  list<string>  $piecesJointes
     * @param  list<string>  $html
     * @param  list<string>  $motifs
     * @return list<string>
     */
    private function controler(string $tout, string $sansBalises, string $brut, array $piecesJointes, array $html, array $motifs): array
    {
        if ($piecesJointes !== [] || self::trouve('/content-disposition\s*:\s*attachment/i', $tout)) {
            $motifs[] = 'Pièce jointe interdite : le message ne doit contenir aucune pièce jointe.';
        }

        if (($nbVariables = preg_match_all('/\{\{(.*?)\}\}/s', $brut, $m)) === false) {
            throw self::doute();
        } elseif ($nbVariables > 0) {
            foreach ($m[1] as $interieur) {
                $morceaux = explode('|', $interieur, 2);
                $nom = trim($morceaux[0]);
                if (! isset($morceaux[1]) || trim($morceaux[1]) === '') {
                    $motifs[] = "Variable « {{{$nom}}} » sans valeur de repli : écrire {{{$nom}|valeur}}.";
                }
            }
        }

        if (self::trouve('/(?<![a-z0-9-])axion-ia\.com/i', $tout)
            || self::trouve('/(?<![a-z0-9-])axion-ia\.com/i', $sansBalises)) {
            $motifs[] = 'Lien ou adresse vers axion-ia.com (domaine principal) interdit : utiliser le domaine d\'envoi.';
        }

        if (self::trouve('/grenoble/iu', $tout) || self::trouve('/grenoble/iu', $sansBalises)) {
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

    private static function doute(): MessageRefuse
    {
        return new MessageRefuse(['Contenu refusé : analyse impossible (doute = blocage).']);
    }

    /** preg_match booléen ; un échec de regex (false) refuse le message au lieu de passer. */
    private static function trouve(string $motif, string $sujet): bool
    {
        $r = preg_match($motif, $sujet);
        if ($r === false) {
            throw self::doute();
        }

        return $r === 1;
    }

    /** Forme canonique pour la recherche de motifs interdits (jamais utilisée pour envoyer). */
    private static function normaliser(string $v): string
    {
        for ($i = 0; $i < 3; $i++) {
            // Entités numériques décodées même sans « ; » final (les clients mail le font), puis nommées.
            $r = preg_replace_callback('/&#(x[0-9a-f]{1,6}|[0-9]{1,7});?/i', static function (array $m): string {
                $cp = strtolower($m[1][0]) === 'x' ? (int) hexdec(substr($m[1], 1)) : (int) $m[1];

                return $cp > 0 && $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF)
                    ? mb_scrub((string) mb_chr($cp, 'UTF-8'), 'UTF-8') : '';
            }, $v);
            if ($r === null) {
                throw self::doute();
            }
            $v = mb_scrub(html_entity_decode($r, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');
            $v = mb_scrub(rawurldecode($v), 'UTF-8');
        }
        // NFKC d'abord (compatibilité), puis retrait de tous les invisibles : format (Cf : bidi, joiners,
        // soft-hyphen, tags…), sélecteurs de variante, remplisseurs hangul, CGJ, sélecteurs mongols.
        if (! class_exists(\Normalizer::class)) {
            throw new MessageRefuse(['Contenu refusé : extension intl absente, normalisation NFKC impossible.']);
        }
        $n = \Normalizer::normalize($v, \Normalizer::FORM_KC);
        if ($n === false) {
            throw new MessageRefuse(['Contenu refusé : normalisation NFKC impossible (doute = blocage).']);
        }
        $v = preg_replace('/[\p{Cf}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{034F}\x{180B}-\x{180F}]/u', '', $n);
        if ($v === null) {
            throw self::doute();
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
        $nb = preg_match_all('#(?:https?:)?[/\\\\]{2}[^\s"\'<>)]+#i', $contenu, $urls);
        if ($nb === false) {
            throw self::doute();
        }
        if ($nb === 0) {
            return false;
        }
        foreach ($urls[0] as $brut) {
            $nu = preg_replace('#^(?:https?:)?[/\\\\]+#i', '', str_replace('\\', '/', $brut));
            if ($nu === null) {
                throw self::doute();
            }
            $url = 'https://' . ltrim($nu, '/');
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
            if (self::trouve('/(^|&)[^=&]*=(https?(%3a|:)|%2f%2f|\/\/)/i', $requete)) {
                return true;
            }
        }

        return false;
    }
}
