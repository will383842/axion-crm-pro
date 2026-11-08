<?php

namespace App\Envoi\Imap;

use App\Envoi\Contrat\EvenementEnvoi;
use App\Envoi\Retours\AppliquerRetour;

/**
 * Alimente les freins de L7 : chaque retour qui compte (désinscription, plainte, rebond dur, rebond mou)
 * est transmis à `AppliquerRetour` sous la forme d'un `EvenementEnvoi`. L'identifiant de l'événement est
 * l'EMPREINTE DU MESSAGE (stable : ni UID, ni UIDVALIDITY), pour que L7 compte une seule fois.
 */
final class RecepteurRetoursFreins implements RecepteurRetours
{
    private const TYPES = [
        'desinscription' => 'desinscription',
        'hostile' => 'plainte',
        'rebond_dur' => 'rebond_dur',
        'rebond_mou' => 'rebond_mou',
    ];

    public function __construct(private readonly AppliquerRetour $freins) {}

    public function recevoir(string $workspaceId, RetourDetecte $retour): void
    {
        $type = self::TYPES[$retour->classe->value] ?? null;
        if ($type === null) {
            return;
        }
        $this->freins->appliquer($workspaceId, new EvenementEnvoi($retour->cle, $type, $retour->idInjection, $retour->adresse));
    }
}
