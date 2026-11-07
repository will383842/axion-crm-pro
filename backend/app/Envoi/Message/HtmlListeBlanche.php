<?php

namespace App\Envoi\Message;

/**
 * Analyseur STRICT du HTML d'un message de prospection (palier 0) : liste blanche, aucun nettoyage.
 *
 * Décision produit : aucune image distante, aucun pixel de suivi, aucune ressource externe.
 * Plutôt que de chercher les contournements (liste noire), on n'accepte que :
 *  - les balises p, br, a, b, strong, i, em, u, ul, ol, li, hr, div, span ;
 *  - l'unique attribut href, sur « a », en https:// ou mailto: ;
 *  - des entités bien formées (&nom; ou &#N; / &#xH; AVEC point-virgule) ;
 *  - des balises bien formées et bien imbriquées, sans commentaire ni déclaration.
 * Tout le reste est REFUSÉ avec un motif lisible (le HTML n'est jamais modifié en silence).
 */
final class HtmlListeBlanche
{
    private const BALISES = ['p', 'br', 'a', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'hr', 'div', 'span'];

    private const VIDES = ['br', 'hr'];

    private const MOTIF_RESSOURCE = 'Pixel ou image interdits : aucune image (distante, intégrée, masquée ou de suivi d\'ouverture) ni ressource externe (style, feuille, police, VML, SVG…) dans un message du palier 0, aucune mesure d\'ouverture.';

    /** Balises/attributs par lesquels une ressource externe peut se charger. */
    private const BALISES_RESSOURCE = ['img', 'image', 'picture', 'source', 'video', 'audio', 'embed', 'object', 'iframe',
        'script', 'link', 'input', 'style', 'svg', 'body', 'head', 'html', 'meta', 'base', 'frame', 'frameset', 'track',
        'feimage', 'use', 'canvas', 'applet', 'bgsound', 'form', 'button', 'table', 'td', 'th', 'tr'];

    private const ATTRIBUTS_RESSOURCE = ['style', 'background', 'srcset', 'src', 'poster', 'data', 'dynsrc', 'lowsrc', 'xlink:href', 'ping', 'action', 'formaction'];

    /** @return list<string> motifs de refus ; vide = HTML accepté */
    public static function refus(string $html): array
    {
        $motifs = [];
        $pile = [];
        $pos = 0;
        $n = strlen($html);

        if (preg_match('//u', $html) !== 1) {
            return ['HTML refusé : encodage UTF-8 invalide.'];
        }

        while ($pos < $n) {
            $lt = strpos($html, '<', $pos);
            $texte = substr($html, $pos, ($lt === false ? $n : $lt) - $pos);
            self::controlerTexte($texte, $motifs);
            if ($lt === false) {
                break;
            }
            if (preg_match('#\G<(/?)([A-Za-z][A-Za-z0-9:_-]*)((?:\s+[A-Za-z][A-Za-z0-9:_-]*(?:\s*=\s*(?:"[^"<>]*"|\'[^\'<>]*\'))?)*)\s*(/?)>#', $html, $m, 0, $lt) !== 1) {
                // Commentaire, déclaration, balise mal formée, attribut sans guillemets, « < » isolé…
                $motifs[] = 'HTML refusé : balise, commentaire ou déclaration mal formé(e) près de « ' . self::extrait($html, $lt) . ' » (liste blanche stricte).';
                $motifs = self::ressourceSiMarque($html, $lt, $motifs);
                break;
            }
            $pos = $lt + strlen($m[0]);
            [$fermante, $nom, $attrs, $autoFermante] = [$m[1] === '/', strtolower($m[2]), $m[3], $m[4] === '/'];

            if (! in_array($nom, self::BALISES, true)) {
                $motifs[] = in_array($nom, self::BALISES_RESSOURCE, true) || str_contains($nom, ':')
                    ? self::MOTIF_RESSOURCE
                    : "HTML refusé : balise <{$nom}> non autorisée (autorisées : " . implode(', ', self::BALISES) . ').';

                continue;
            }
            if ($fermante) {
                if ($attrs !== '' || $autoFermante || in_array($nom, self::VIDES, true) || array_pop($pile) !== $nom) {
                    $motifs[] = "HTML refusé : balise fermante </{$nom}> inattendue ou mal imbriquée.";
                }

                continue;
            }
            self::controlerAttributs($nom, $attrs, $motifs);
            if (in_array($nom, self::VIDES, true)) {
                continue;
            }
            if ($autoFermante) {
                $motifs[] = "HTML refusé : balise <{$nom}/> auto-fermante non autorisée.";

                continue;
            }
            $pile[] = $nom;
        }
        if ($pile !== []) {
            $motifs[] = 'HTML refusé : balise <' . end($pile) . '> jamais fermée.';
        }

        return array_values(array_unique($motifs));
    }

    /** @param list<string> $motifs */
    private static function controlerTexte(string $texte, array &$motifs): void
    {
        // Toute « & » doit introduire une entité bien formée (numérique : point-virgule obligatoire).
        if (preg_match_all('/&(?!(?:#[0-9]{1,7}|#[xX][0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});)/', $texte, $x) > 0) {
            $motifs[] = 'HTML refusé : entité mal formée (« & » nu ou entité numérique sans « ; »). Écrire &amp; pour une esperluette.';
        }
        if (preg_match('/&#[xX]?[0-9a-fA-F]+;/', $texte, $e) === 1) {
            $cp = preg_match('/^&#[xX]/', $e[0]) === 1 ? hexdec(substr($e[0], 3, -1)) : (int) substr($e[0], 2, -1);
            if ($cp < 32 && ! in_array($cp, [9, 10, 13], true) || ($cp >= 0xD800 && $cp <= 0xDFFF) || $cp > 0x10FFFF) {
                $motifs[] = 'HTML refusé : entité numérique invalide (caractère de contrôle ou hors plage).';
            }
        }
    }

    /** @param list<string> $motifs */
    private static function controlerAttributs(string $nom, string $attrs, array &$motifs): void
    {
        if (trim($attrs) === '') {
            return;
        }
        preg_match_all('/([A-Za-z][A-Za-z0-9:_-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))?/', $attrs, $tous, PREG_SET_ORDER);
        $vus = [];
        foreach ($tous as $a) {
            $attr = strtolower($a[1]);
            $valeur = ($a[2] ?? '') !== '' ? $a[2] : ($a[3] ?? '');
            if (isset($vus[$attr])) {
                $motifs[] = "HTML refusé : attribut « {$attr} » en double.";
            }
            $vus[$attr] = true;
            if (in_array($attr, self::ATTRIBUTS_RESSOURCE, true)) {
                $motifs[] = self::MOTIF_RESSOURCE;
            } elseif ($attr !== 'href' || $nom !== 'a') {
                $motifs[] = "HTML refusé : attribut « {$attr} » non autorisé sur <{$nom}> (seul href est permis, sur <a>).";
            } else {
                self::controlerHref($valeur, $motifs);
            }
        }
    }

    /** @param list<string> $motifs */
    private static function controlerHref(string $valeur, array &$motifs): void
    {
        $url = html_entity_decode($valeur, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('/[\x00-\x20\x7f\\\\<>"\'`]|[^\x00-\x7f]/', $url) === 1) {
            $motifs[] = 'HTML refusé : href contenant espace, antislash, guillemet, caractère de contrôle ou non ASCII.';

            return;
        }
        if (preg_match('#^https://[^/?\#]+#', $url) !== 1 && preg_match('/^mailto:[^\s]+$/', $url) !== 1) {
            $motifs[] = 'HTML refusé : href doit commencer par https:// (avec hôte) ou mailto: (http, //, javascript:, data:… interdits).';
        }
    }

    /**
     * @param  list<string>  $motifs
     * @return list<string>
     */
    private static function ressourceSiMarque(string $html, int $lt, array $motifs): array
    {
        $morceau = substr($html, $lt, 400);
        if (preg_match('/^<[\/!?\s]*(?:' . implode('|', array_map('preg_quote', self::BALISES_RESSOURCE)) . '|[a-z]+:)/i', $morceau) === 1
            || preg_match('/\b(?:' . implode('|', self::ATTRIBUTS_RESSOURCE) . ')\s*=/i', $morceau) === 1) {
            $motifs[] = self::MOTIF_RESSOURCE;
        }

        return $motifs;
    }

    private static function extrait(string $html, int $lt): string
    {
        return str_replace(["\r", "\n"], ' ', substr($html, $lt, 40));
    }
}
