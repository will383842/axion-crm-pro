<?php

namespace App\Envoi\Contrat;

enum EtatMessage: string
{
    case EnFile = 'en_file';
    case Envoye = 'envoye';
    case Rebond = 'rebond';
    case Refuse = 'refuse';
    case Inconnu = 'inconnu';
}
