<?php

namespace App\Envoi\Serveur;

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Serveur\Kumo\InterrogeurEtatHttps;
use App\Envoi\Serveur\Kumo\RegistreInjections;
use App\Envoi\Serveur\Kumo\TransportHttpsSigne;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * C3-5 : fabrique de l'adaptateur `kumo` pour l'aiguilleur (`Closure(string $workspaceId, string $domaine): ServeurEnvoi`).
 *
 * Construit `ServeurEnvoiKumo` pour UN espace, sur la source (ligne `envoi_serveurs`) ACTIVE de cet espace : exactement une,
 * sinon refus `source_ambigue` (échec fermé, aucune supposition). Sans `envoi.cle_empreinte` : refus (la clé dérive les jetons).
 *
 * AUCUNE connexion à la construction : ni requête HTTP, ni lecture du secret HMAC (le client ne résout le secret et l'hôte qu'au
 * premier appel réseau). Le domaine est reçu pour respecter la signature de l'aiguilleur ; l'adaptateur Kumo est par espace.
 */
final class FabriqueServeurKumo
{
    public function __invoke(string $workspaceId, string $domaine): ServeurEnvoi
    {
        $cle = (string) config('envoi.cle_empreinte', '');
        if ($cle === '') {
            throw EnvoiRefuse::cleEmpreinteAbsente();
        }
        $source = $this->sourceActive($workspaceId);
        $client = app(ClientServeurEnvoiHmac::class);

        return new ServeurEnvoiKumo(
            $workspaceId,
            $source,
            new TransportHttpsSigne($client),
            new InterrogeurEtatHttps($client, $workspaceId, $source),
            app(RegistreInjections::class),
            $client,
            app(DepotSocle::class),
            $cle,
        );
    }

    /** La source active de l'espace : exactement une, sinon refus. */
    private function sourceActive(string $workspaceId): string
    {
        $sources = WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_serveurs')->where('workspace_id', $workspaceId)->where('actif', true)->pluck('source')->all());
        if (count($sources) !== 1) {
            throw EnvoiRefuse::conditionNonRemplie('source_ambigue');
        }

        return (string) $sources[0];
    }
}
