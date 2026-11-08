<?php

namespace App\Envoi\Message;

/**
 * Qui signe en DKIM (ADR-K0 D3). `Serveur` : le serveur d'envoi signe, le CRM ne
 * détient AUCUNE clé privée. `Crm` : réservé au relais tiers temporaire.
 */
enum SignatureDkim: string
{
    case Serveur = 'serveur';
    case Crm = 'crm';
}
