<?php

namespace App\Crm\Emails\Dns;

/**
 * Résout des domaines : MX, sinon A (puis AAAA). JAMAIS de sondage SMTP.
 *
 * Aucune implémentation ne parle à un serveur de messagerie : on demande au
 * DNS si le domaine reçoit du courrier, jamais au serveur si la boîte existe
 * (un `RCPT TO` abîme la réputation de l'expéditeur — interdit ici).
 */
interface ResolveurDns
{
    /**
     * @param  list<string>  $domaines  domaines ASCII, en minuscules, distincts
     * @return array<string, ResultatDns> un résultat pour CHAQUE domaine demandé
     */
    public function resoudre(array $domaines): array;

    /** Ce qui identifie le résolveur dans le cache et le journal (`1.1.1.1:53`). */
    public function nom(): string;
}
