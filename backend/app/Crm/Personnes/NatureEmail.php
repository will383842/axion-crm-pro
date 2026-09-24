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
 * La règle est volontairement simple et PRUDENTE dans le bon sens : un domaine
 * de messagerie grand public connu → `perso` ; tout autre domaine → `pro`.
 * L'erreur possible (un indépendant qui utilise une adresse grand public) range
 * la personne du côté protecteur. L'erreur inverse (un domaine exotique
 * personnel classé `pro`) est bornée par le fait qu'une personne `pro` sans
 * consentement ne reçoit qu'un suivi INDIVIDUEL, jamais une liste de diffusion.
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
        'gmail.com', 'googlemail.com', 'yahoo.fr', 'yahoo.com', 'ymail.com',
        'hotmail.fr', 'hotmail.com', 'outlook.fr', 'outlook.com', 'live.fr', 'live.com',
        'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'aol.fr',
        'gmx.fr', 'gmx.com', 'gmx.net', 'protonmail.com', 'proton.me', 'pm.me',
        'tutanota.com', 'zoho.com', 'yandex.com', 'mail.com',
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

        return in_array($domaine, self::DOMAINES_GRAND_PUBLIC, true) ? 'perso' : 'pro';
    }
}
