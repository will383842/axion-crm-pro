<?php

namespace App\Support;

/**
 * Masquage des coordonnées pour les comptes en LECTURE SEULE (plan §2.10).
 *
 * Un `viewer` doit pouvoir consulter et se repérer, pas repartir avec 665 771
 * adresses lisibles à l'écran. L'export lui est déjà fermé (`data.export`) ;
 * sans masquage, il lui suffirait de faire défiler et de recopier.
 *
 * ── Ce que le masquage N'EST PAS ─────────────────────────────────────────
 * Ce n'est pas une mesure de sécurité au sens fort : la donnée transite
 * toujours par le serveur, et qui a le droit de la lire l'a vraiment. C'est
 * une réduction d'exposition — le principe de minimisation du RGPD appliqué à
 * l'affichage. On le dit ici pour que personne ne s'en croie protégé contre un
 * accès direct à la base.
 *
 * ── Pourquoi laisser le domaine visible ──────────────────────────────────
 *
 * `p***@axion-ia.com` reste utile : un opérateur reconnaît l'entreprise et
 * peut travailler. Masquer le domaine aussi rendrait la liste illisible sans
 * rien protéger de plus — le domaine se déduit de la fiche entreprise.
 */
final class MasquageCoordonnees
{
    public const PERMISSION = 'contacts.view_pii';

    /**
     * L'utilisateur courant doit-il voir des coordonnées masquées ?
     *
     * Ferme par DÉFAUT : sans utilisateur ou sans droit, on masque. Une garde
     * dont l'état inconnu vaut « montre tout » n'est pas une garde.
     */
    public static function requis(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return true;
        }

        return ! $user->can(self::PERMISSION);
    }

    /**
     * `pierre.durand@acme.fr` → `p***@acme.fr`
     *
     * On garde la première lettre : elle suffit à reconnaître un interlocuteur
     * déjà connu sans livrer l'adresse à qui ne la connaît pas.
     */
    public static function email(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return $email;
        }

        $position = mb_strrpos($email, '@');

        // Pas d'arobase : la valeur n'est pas une adresse (donnée abîmée). On
        // masque TOUT plutôt que de la laisser passer par défaut.
        if ($position === false || $position === 0) {
            return '***';
        }

        $locale = mb_substr($email, 0, $position);
        $domaine = mb_substr($email, $position);

        return mb_substr($locale, 0, 1) . '***' . $domaine;
    }

    /**
     * `+33612345678` → `+336******78`
     *
     * Les deux derniers chiffres suffisent à confirmer un numéro qu'on a déjà
     * sous les yeux — c'est la vérification qu'un lecteur seul peut avoir à
     * faire — sans permettre de le reconstituer.
     */
    public static function telephone(?string $telephone): ?string
    {
        if ($telephone === null || trim($telephone) === '') {
            return $telephone;
        }

        $valeur = trim($telephone);
        $longueur = mb_strlen($valeur);

        // Trop court pour qu'un masque partiel garde quoi que ce soit.
        if ($longueur <= 6) {
            return '***';
        }

        return mb_substr($valeur, 0, 4) . str_repeat('*', max(1, $longueur - 6)) . mb_substr($valeur, -2);
    }
}
