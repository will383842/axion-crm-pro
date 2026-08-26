<?php

namespace App\Support;

/**
 * Normalisation d'une URL de profil LinkedIn en SLUG canonique.
 *
 * ── Pourquoi une classe pour ça ────────────────────────────────────────────
 * Le slug est la clé d'identité la plus fiable dont on dispose sur un
 * journaliste : contrairement au nom, il ne connaît pas d'homonyme ; contrairement
 * à l'email, il est public et stable. Mais copié-collé depuis un navigateur, le
 * MÊME profil arrive sous une demi-douzaine de formes :
 *
 *   https://www.linkedin.com/in/marie-durand-1a2b3c/
 *   https://fr.linkedin.com/in/marie-durand-1a2b3c
 *   https://linkedin.com/in/marie-durand-1a2b3c?trk=public_profile_browsemap
 *   linkedin.com/in/marie-durand-1a2b3c/?originalSubdomain=fr
 *   https://www.linkedin.com/in/marie-durand-1a2b3c/en
 *
 * Stocker ces chaînes telles quelles, c'est garantir que l'index unique ne voie
 * jamais deux fois la même personne — donc que la colonne censée dédoublonner
 * DUPLIQUE. C'est le mode de doublon n°1 de toute base alimentée à la main.
 *
 * ── Ce que la classe REFUSE, et pourquoi c'est le point important ──────────
 * Seuls les profils de PERSONNE (`/in/…`) rendent un slug. Une page d'entreprise
 * (`/company/tf1`), une page d'école (`/school/…`), un post ou une newsletter
 * rendent `null`. Sans ce refus, `/company/le-figaro` deviendrait l'« identité »
 * du premier journaliste du Figaro saisi, et le second entrerait en collision
 * avec lui sur l'index unique — deux personnes distinctes fusionnées par une
 * URL de rédaction. Un `null` est ici une réponse juste, pas un échec.
 */
final class LinkedinSlug
{
    /**
     * Réduit une URL (ou un slug déjà nu) à son slug canonique, en minuscules.
     *
     * @return string|null `null` si l'entrée n'est pas un profil de personne
     *                     exploitable — jamais une chaîne vide.
     */
    public static function normalize(?string $input): ?string
    {
        $value = trim((string) $input);
        if ($value === '') {
            return null;
        }

        // Entrée déjà nue (« marie-durand-1a2b3c ») : pas de séparateur de
        // chemin, pas de point. On l'accepte telle quelle — c'est ce que saisit
        // quelqu'un qui recopie la fin de l'URL, et le refuser obligerait à
        // reconstruire une URL complète pour rien.
        if (! str_contains($value, '/') && ! str_contains($value, '.')) {
            return self::clean($value);
        }

        // Le parseur d'URL a besoin d'un schéma ; « linkedin.com/in/x » n'en a
        // pas quand on colle depuis la barre d'adresse.
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'https://' . ltrim($value, '/');
        }

        $host = parse_url($value, PHP_URL_HOST);
        $path = parse_url($value, PHP_URL_PATH);
        if (! is_string($host) || ! is_string($path)) {
            return null;
        }

        // Sous-domaines de langue : www., fr., de., br.… Tous servent le même
        // profil. On n'accepte en revanche que le domaine linkedin lui-même :
        // une URL d'un autre site n'a rien à faire dans cette colonne.
        $host = strtolower($host);
        if (! preg_match('#(^|\.)linkedin\.com$#', $host)) {
            return null;
        }

        // `/in/<slug>` et rien d'autre. Le segment peut être suivi d'une locale
        // (`/in/x/en`) ou d'un onglet (`/in/x/details/experience`) : on ne garde
        // que le segment qui suit `/in/`.
        // ⚠️ Délimiteur `~` et NON `#` : le motif contient lui-même un `#` (dans
        // la classe `[^/?#]`), et PHP ne le protège pas d'être lu comme fin de
        // motif — même à l'intérieur d'une classe de caractères. Avec `#`,
        // `preg_match` rendait `false` et « Internal error » : cette fonction
        // ne renvoyait JAMAIS de slug. Mesuré le 2026-08-26, au rejeu du lot.
        if (! preg_match('~/in/([^/?#]+)~i', $path, $m)) {
            return null;
        }

        // Les slugs contenant des accents arrivent percent-encodés.
        $slug = rawurldecode($m[1]);

        return self::clean($slug);
    }

    /**
     * Deux URL rendent-elles le même profil ? `false` dès que l'une des deux
     * n'est pas un profil de personne : on ne déclare pas identiques deux
     * choses qu'on n'a pas su lire.
     */
    public static function sameProfile(?string $a, ?string $b): bool
    {
        $left = self::normalize($a);

        return $left !== null && $left === self::normalize($b);
    }

    private static function clean(string $slug): ?string
    {
        $slug = mb_strtolower(trim($slug, "/ \t\n\r\0\x0B"));

        // Un slug LinkedIn est alphanumérique + tirets (et unicode pour les
        // profils non latins). On rejette tout ce qui contient un caractère de
        // structure d'URL résiduel : c'est le signe qu'on a mal découpé, et il
        // vaut mieux ne rien stocker qu'une clé fausse.
        // Même piège de délimiteur qu'au-dessus : `preg_match` rendait `false`,
        // donc cette garde de structure ne rejetait RIEN.
        if ($slug === '' || preg_match('~[/?#\s]~', $slug)) {
            return null;
        }

        // Bornes LinkedIn : 3 à 100 caractères. Au-delà, ce n'est pas un slug.
        $length = mb_strlen($slug);
        if ($length < 3 || $length > 100) {
            return null;
        }

        return $slug;
    }
}
