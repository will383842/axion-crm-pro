<?php

namespace App\Envoi\Chauffe;

use App\Envoi\Contrat\EvenementEnvoi;
use App\Envoi\JourEnvoi;
use App\Envoi\Retours\AppliquerRetour;
use Closure;
use DateTimeImmutable;

/**
 * Écrit réellement les compteurs des freins (rebonds durs, plaintes, réponses) à partir des
 * événements de retour, sans dépendre du lot Retours : tout appelant de `AppliquerRetour`
 * (le futur lot Retours, une commande) alimente les freins. Un événement ne compte qu'UNE fois
 * (son identifiant est la clé d'idempotence, dans le journal). Aucune ouverture n'est mesurée :
 * tout autre type d'événement est ignoré.
 */
final class CompteurRetours implements AppliquerRetour
{
    /** type d'événement de retour => colonne du compteur. Liste FERMÉE. */
    public const TYPES = ['rebond_dur' => 'rebonds_durs', 'plainte' => 'plaintes', 'reponse' => 'reponses'];

    public function __construct(
        private readonly DepotChauffe $depot,
        private readonly ResolveurFournisseur $resolveur,
        private readonly ?Closure $maintenant = null,
    ) {}

    public function appliquer(string $workspaceId, EvenementEnvoi $evenement): void
    {
        $colonne = self::TYPES[$evenement->type] ?? null;
        if ($colonne === null) {
            return;
        }
        $instant = $this->maintenant === null ? now()->toDateTimeImmutable() : DateTimeImmutable::createFromInterface(($this->maintenant)());
        $this->depot->compterRetour($workspaceId, $evenement->id, $colonne, JourEnvoi::de($instant), $this->resolveur->fournisseurDe($evenement->adresse));
    }
}
