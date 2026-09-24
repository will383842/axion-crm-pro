<?php

namespace App\Crm\Personnes;

/**
 * NATURE D'UNE ADRESSE : professionnelle, personnelle ou inconnue.
 *
 * Elle ne sert pas à trier pour le plaisir : sans consentement, la CNIL
 * n'admet la prospection que d'un PROFESSIONNEL, sur un objet lié à sa
 * profession. Un demandeur du guide qui écrit depuis une messagerie grand
 * public n'est donc jamais prospectable sans avoir coché la case de la lettre.
 *
 * ── Qui décide ──────────────────────────────────────────────────────────────
 *
 * Le SITE (amendement de Will du 2026-09-24 : la nature est « décidée côté
 * serveur » du site, qui affiche ou non la case). Quand l'événement porte
 * `payload.email_nature`, `PersonnesIngestService` la retient. Cette classe
 * est le REPLI (format actuel du site, qui ne l'envoie pas) et le CONTRE-
 * CONTRÔLE : si l'une OU l'autre liste dit « perso », une inscription par
 * intérêt légitime est refusée. Deux listes peuvent diverger ; le doute range
 * alors la personne du côté protecteur.
 *
 * ── La règle, et ce qu'elle ne protège pas ──────────────────────────────────
 *
 * Grand public = un domaine de la liste fermée, OU une FAMILLE de domaines
 * (`hotmail.*`, `outlook.*`, `live.*`, `yahoo.*`, `gmx.*`… — la liste de
 * l'amendement) sous n'importe quelle extension nationale (`hotmail.co.uk`,
 * `gmx.de`, `yahoo.com.br`). Tout AUTRE domaine est classé `pro` : c'est la
 * définition même de l'amendement (« domaine qui n'est pas un webmail grand
 * public »), pas une prudence. Un domaine personnel exotique inconnu de la
 * liste est donc classé `pro` — c'est le côté NON protecteur, assumé par la
 * règle de Will ; il se corrige en ajoutant le domaine ici ET côté site.
 */
final class NatureEmail
{
    /**
     * Domaines de messagerie grand public (France d'abord, puis internationaux).
     * Liste FERMÉE et relue : on n'y devine rien, on y ajoute à la main.
     *
     * @var list<string>
     */
    public const DOMAINES_GRAND_PUBLIC = [
        // France
        'orange.fr', 'wanadoo.fr', 'free.fr', 'sfr.fr', 'neuf.fr', 'laposte.net',
        'bbox.fr', 'numericable.fr', 'aliceadsl.fr', 'club-internet.fr', 'cegetel.net',
        '9online.fr', 'voila.fr',
        // Internationaux
        'gmail.com', 'googlemail.com', 'ymail.com', 'rocketmail.com',
        'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'aol.fr',
        'protonmail.com', 'protonmail.ch', 'proton.me', 'pm.me',
        'tutanota.com', 'tutanota.de', 'tuta.io', 'zoho.com', 'yandex.com', 'yandex.ru',
        'mail.com', 'mail.ru', 'web.de', 't-online.de', 'libero.it', 'seznam.cz',
    ];

    /**
     * FAMILLES de domaines grand public : le premier libellé, sous n'importe
     * quelle extension nationale (voir `estExtensionNationale`).
     *
     * @var list<string>
     */
    public const FAMILLES_GRAND_PUBLIC = [
        'hotmail', 'outlook', 'live', 'yahoo', 'gmx', 'aol', 'windowslive',
    ];

    public static function de(?string $email): string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return 'inconnue';
        }

        $domaine = mb_strtolower(trim(substr($email, (int) strrpos($email, '@') + 1)));

        if ($domaine === '') {
            return 'inconnue';
        }

        return self::estGrandPublic($domaine) ? 'perso' : 'pro';
    }

    public static function estGrandPublic(string $domaine): bool
    {
        if (in_array($domaine, self::DOMAINES_GRAND_PUBLIC, true)) {
            return true;
        }

        $libelles = explode('.', $domaine);
        $famille = array_shift($libelles);

        return in_array($famille, self::FAMILLES_GRAND_PUBLIC, true) && self::estExtensionNationale($libelles);
    }

    /**
     * `com`, `fr`, `co.uk`, `com.br`… : un ou deux libellés d'au plus trois
     * lettres. `live.example.fr` n'est PAS de la famille `live` (le deuxième
     * libellé est un nom, pas une extension).
     *
     * @param  list<string>  $libelles
     */
    private static function estExtensionNationale(array $libelles): bool
    {
        if ($libelles === [] || count($libelles) > 2) {
            return false;
        }

        foreach ($libelles as $libelle) {
            if (preg_match('/^[a-z]{2,3}$/', $libelle) !== 1) {
                return false;
            }
        }

        return true;
    }
}
