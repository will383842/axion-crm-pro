<?php

namespace App\Crm\Guides;

/**
 * Traduit l'IDENTIFIANT public d'un guide (ex. `guide-ia`) en lien, grâce à la
 * table fermée `config/guides.php`. Classe PURE (aucune base, aucun réseau).
 *
 * Le lien vient UNIQUEMENT de la table, jamais d'une donnée reçue ; il est
 * public et identique pour tout le monde (aucune donnée de personne, aucun
 * jeton `?t=`). `null` = pas de lien (interrupteur éteint, identifiant inconnu,
 * ou entrée de table qui ne passe pas la garde).
 */
final class LienGuide
{
    public const STATUT_DISPONIBLE = 'disponible';

    public const STATUT_ETEINT = 'eteint';

    public const STATUT_REFUSE = 'refuse';

    private const IDENTIFIANT = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    private const HOTE = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/';

    private const CHEMIN = '#^/[A-Za-z0-9/._-]*$#';

    /** Hôte public autorisé par défaut (surchargeable par `guides.hote_autorise`). */
    public const HOTE_AUTORISE_DEFAUT = 'axion-ia.com';

    /** Hôte de test (domaine réservé) : accepté seulement dans ces environnements. */
    public const HOTE_TEST = 'exemple.invalid';

    private const ENVIRONNEMENTS_TEST = ['testing', 'local', 'demo'];

    public static function actif(): bool
    {
        return (bool) config('guides.lien_enabled', false);
    }

    /**
     * État du lien pour l'écran : éteint avant tout, puis refusé si le profil
     * n'a pas le droit (lecture seule), sinon disponible.
     */
    public static function statut(bool $droit): string
    {
        if (! self::actif()) {
            return self::STATUT_ETEINT;
        }

        return $droit ? self::STATUT_DISPONIBLE : self::STATUT_REFUSE;
    }

    /** Nom affiché d'un guide connu de la table (sans adresse) ; `null` si inconnu. */
    public static function nom(?string $guide): ?string
    {
        $e = self::entree($guide);
        $nom = $e['nom'] ?? null;

        return is_string($nom) && $nom !== '' ? mb_substr($nom, 0, 120) : null;
    }

    /** @return array{url: string, libelle: string}|null */
    public static function pour(?string $guide): ?array
    {
        if (! self::actif()) {
            return null;
        }
        $e = self::entree($guide);
        $url = $e['url'] ?? null;
        $libelle = $e['libelle'] ?? null;
        if (! is_string($url) || ! self::urlValide($url) || ! is_string($libelle) || trim($libelle) === '') {
            return null;
        }

        return ['url' => $url, 'libelle' => mb_substr($libelle, 0, 120)];
    }

    /** @return array<string, mixed>|null */
    private static function entree(?string $guide): ?array
    {
        if ($guide === null || preg_match(self::IDENTIFIANT, $guide) !== 1) {
            return null;
        }
        $table = config('guides.publics', []);
        $e = is_array($table) && array_key_exists($guide, $table) ? $table[$guide] : null;

        return is_array($e) ? $e : null;
    }

    /** https, sans port, identifiant, paramètre ni fragment ; hôte simple (pas d'IDN) ; chemin simple. */
    private static function urlValide(string $url): bool
    {
        if (strlen($url) > 300 || preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '?') || str_contains($url, '#')) {
            return false;
        }
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || ! isset($p['host'], $p['path'])
            || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return false;
        }

        return preg_match(self::HOTE, $p['host']) === 1 && ! str_contains($p['host'], 'xn--')
            && self::hoteAutorise(strtolower($p['host'])) && preg_match(self::CHEMIN, $p['path']) === 1;
    }

    /** Hôte exact autorisé ; l'hôte de test seulement hors production. */
    private static function hoteAutorise(string $hote): bool
    {
        $autorise = config('guides.hote_autorise', self::HOTE_AUTORISE_DEFAUT);
        $autorise = is_string($autorise) && $autorise !== '' ? strtolower($autorise) : self::HOTE_AUTORISE_DEFAUT;
        if ($hote === $autorise) {
            return true;
        }

        return $hote === self::HOTE_TEST && app()->environment(self::ENVIRONNEMENTS_TEST);
    }
}
