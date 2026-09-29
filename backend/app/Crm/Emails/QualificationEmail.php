<?php

namespace App\Crm\Emails;

use App\Crm\Personnes\NatureEmail;
use App\Services\Email\MxEmailValidator;

/**
 * CE QU'ON SAIT D'UNE ADRESSE SANS INTERROGER LE RÉSEAU — règles PURES.
 *
 * Aucune liste n'est recopiée ici : chacune vit déjà dans le dépôt, et une
 * copie finirait par diverger (le dépôt le constate partout).
 *
 *  - JETABLE : `MxEmailValidator::DISPOSABLE_DOMAINS` (la liste la plus
 *    complète du dépôt ; celle de `RealSmtpProber` en est un sous-ensemble).
 *  - WEBMAIL grand public : `NatureEmail::estGrandPublic` — la définition de
 *    Will (amendement du 24/09), familles `hotmail.*`, `yahoo.*`… comprises.
 *    Un webmail est MARQUÉ, jamais rejeté : la décision de lui écrire ou non
 *    appartient à la campagne (décision D3), pas à la vérification.
 *  - TYPE générique / nominatif : la règle de l'étape 4 de l'import des
 *    fédérations (`consolider_contacts.py`, liste `GENERIQUE`), complétée des
 *    rôles de `MxEmailValidator::ROLE_PREFIXES`. Un mot de la liste doit
 *    former le DÉBUT de la partie locale, suivi de rien, d'un chiffre ou d'un
 *    séparateur (`.`, `-`, `_`, `+`) : la règle d'origine, qui ne regardait
 *    que le préfixe, rangeait « cristina.lopez@ » en générique à cause de
 *    `cr` (conseil régional) — une personne classée boîte, le côté NON
 *    protecteur. Tout ce qui n'est pas générique est nominatif : dans le
 *    doute, on protège (une adresse nominative est une donnée personnelle).
 */
final class QualificationEmail
{
    /**
     * Mots de boîte générique de l'import des fédérations (étape 4,
     * `consolider_contacts.py`), dans l'ordre d'origine.
     *
     * @var list<string>
     */
    public const MOTS_GENERIQUES_FEDERATIONS = [
        'contact', 'info', 'infos', 'accueil', 'secretariat', 'secr', 'direction', 'communication', 'com', 'presse',
        'federation', 'syndicat', 'admin', 'administration', 'bonjour', 'hello', 'office', 'courrier', 'mail', 'siege',
        'standard', 'adherent', 'adherents', 'service', 'services', 'juridique', 'evenement', 'evenements', 'formation',
        'partenariat', 'partenariats', 'ud', 'ul', 'fd', 'cd', 'cr', 'ur', 'ordre', 'chambre', 'cci', 'cma', 'union',
        'asso', 'association',
    ];

    /** Longueur maximale d'une adresse (RFC 5321, chemin). */
    private const LONGUEUR_MAX = 254;

    public static function normaliser(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Le domaine, en ASCII (un domaine internationalisé est converti en
     * punycode : c'est sous cette forme que le DNS le connaît). Null si
     * l'adresse n'a pas de domaine exploitable.
     */
    public static function domaine(string $email): ?string
    {
        $email = self::normaliser($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domaine = rtrim(substr($email, $at + 1), '.');
        if ($domaine === '') {
            return null;
        }
        if (preg_match('/[^\x20-\x7e]/', $domaine) === 1) {
            $ascii = idn_to_ascii($domaine, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false || $ascii === '') {
                return null;
            }
            $domaine = strtolower($ascii);
        }

        return $domaine;
    }

    public static function syntaxeValide(string $email): bool
    {
        $email = self::normaliser($email);
        if ($email === '' || strlen($email) > self::LONGUEUR_MAX) {
            return false;
        }
        $at = strrpos($email, '@');
        $domaine = self::domaine($email);
        if ($at === false || $domaine === null) {
            return false;
        }
        // Un domaine a au moins deux libellés, chacun conforme (RFC 1035) :
        // `filter_var` accepte `x@localhost` et `x@exemple`.
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domaine) !== 1) {
            return false;
        }

        return filter_var(substr($email, 0, $at) . '@' . $domaine, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function estJetable(string $domaine): bool
    {
        return in_array(strtolower($domaine), MxEmailValidator::DISPOSABLE_DOMAINS, true);
    }

    public static function estWebmail(string $domaine): bool
    {
        return NatureEmail::estGrandPublic(strtolower($domaine));
    }

    /** `generique` ou `nominatif` — voir l'en-tête. */
    public static function type(string $email): string
    {
        $email = self::normaliser($email);
        $at = strrpos($email, '@');
        $local = $at === false ? $email : substr($email, 0, $at);

        return preg_match(self::motifGenerique(), $local) === 1 ? 'generique' : 'nominatif';
    }

    private static function motifGenerique(): string
    {
        static $motif = null;
        if ($motif === null) {
            $mots = array_values(array_unique(array_merge(self::MOTS_GENERIQUES_FEDERATIONS, MxEmailValidator::ROLE_PREFIXES)));
            // Les plus longs d'abord : `infos` avant `info`, `services` avant `service`.
            usort($mots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $motif = '/^(?:' . implode('|', array_map(static fn (string $m): string => preg_quote($m, '/'), $mots)) . ')(?:$|[0-9._+-])/';
        }

        return $motif;
    }
}
