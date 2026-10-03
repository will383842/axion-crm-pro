<?php

namespace App\Crm\ProvenanceTiers;

use RuntimeException;

/**
 * EMPREINTE D'UN TÉLÉPHONE pour `opt_out.phone_hash` (N12, 03/10/2026).
 *
 * HMAC-SHA256 À CLÉ du numéro normalisé, JAMAIS un SHA nu : l'espace des
 * numéros français tient en quelques centaines de millions de valeurs, et un
 * `sha256('06…')` s'inverse par force brute en quelques minutes. Sans la clé,
 * l'empreinte ne dit rien.
 *
 * La clé vit dans l'environnement (`CRM_OPT_OUT_PHONE_HMAC_KEY`, lue par
 * `config/crm.php`), jamais dans le dépôt. Absente ou trop courte : aucune
 * empreinte n'est calculée, on LÈVE — une empreinte calculée avec une clé vide
 * serait un SHA déguisé, et une clé changée en silence rendrait muettes toutes
 * les oppositions déjà enregistrées. Activer la fonctionnalité
 * (`crm.provenance_tiers.actif`) sans clé fait refuser le démarrage
 * (`ProvenanceTiersServiceProvider`).
 *
 * ⚠️ La clé ne se change pas : la changer, c'est perdre la correspondance avec
 * chaque empreinte déjà écrite.
 */
final class EmpreinteTelephone
{
    /** Longueur minimale d'une clé acceptable (en octets). */
    public const LONGUEUR_MINIMALE_CLE = 32;

    public static function de(string $telephone): string
    {
        return hash_hmac('sha256', self::normaliser($telephone), self::cle());
    }

    /**
     * Même normalisation que les oppositions par téléphone existantes
     * (`SiteGdprService::optOutTelephone`, `DeduplicationService::addOptOut`) :
     * espaces, points et tirets ôtés.
     */
    public static function normaliser(string $telephone): string
    {
        return (string) preg_replace('/[\s.-]/', '', trim($telephone));
    }

    /** Lève si la clé est absente ou trop courte. */
    public static function exigerCle(): void
    {
        self::cle();
    }

    private static function cle(): string
    {
        $cle = config('crm.provenance_tiers.cle_empreinte_telephone');
        if (! is_string($cle) || strlen($cle) < self::LONGUEUR_MINIMALE_CLE) {
            throw new RuntimeException(sprintf(
                'Configuration invalide : CRM_OPT_OUT_PHONE_HMAC_KEY (crm.provenance_tiers.cle_empreinte_telephone) '
                . 'est absente ou fait moins de %d caractères. Aucune empreinte de téléphone ne peut être calculée sans clé.',
                self::LONGUEUR_MINIMALE_CLE,
            ));
        }

        return $cle;
    }
}
