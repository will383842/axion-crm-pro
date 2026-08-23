<?php

namespace App\Services\Proxies;

use App\Data\Proxies\ProxyEndpointData;
use Illuminate\Support\Facades\Log;

/**
 * 🔴 C19-011 (S3) — LA SONDE DE SANTE NE VERIFIAIT AUCUN CERTIFICAT.
 *
 * Mesure du 2026-08-22 : les deux fournisseurs de mandataire ecrivaient, mot
 * pour mot, `Http::withOptions(['proxy' => $endpoint->toProxyUrl(), 'verify' => false])`
 * avant d'appeler `https://api.ipify.org?format=json` (WebshareProvider:70,
 * IPRoyalProvider:56). Consequence exacte : le mandataire pouvait rendre
 * n'importe quelle reponse pour `api.ipify.org`, et `healthCheck()` la validait
 * des qu'elle contenait une IP bien formee. La SEULE sonde du sous-systeme etait
 * donc aveugle a la substitution qu'elle a pour role de detecter.
 *
 * Ce trait existe pour que la decision « verifie-t-on le certificat ? » soit
 * ECRITE A UN SEUL ENDROIT. Deux copies de cette regle, et l'un des deux
 * fournisseurs se remettrait a sonder les yeux fermes sans que personne le voie
 * — c'est le patron A-011 de ce depot (le correctif porte sur un site, pas sur
 * son jumeau).
 */
trait VerificationTlsSonde
{
    /**
     * Faut-il verifier le certificat TLS de la sonde de ce fournisseur ?
     *
     * Le defaut est `true`. Un `false` explicite reste possible — certains
     * mandataires HTTPS presentent un certificat d'interception, et remettre la
     * verification chez eux ferait passer des points de sortie SAINS pour morts,
     * donc `pickEndpoint()` leverait une RuntimeException et la collecte
     * s'arreterait. Mais ce `false` doit se DIRE : une verification desactivee et
     * muette redevient l'etat par defaut invisible qu'on repare ici.
     */
    protected function verifierTlsDeLaSonde(string $fournisseur, ProxyEndpointData $endpoint): bool
    {
        $verifier = (bool) config("crm.proxies.verify_tls.{$fournisseur}", true);

        if (! $verifier) {
            // ⚠️ On journalise `host:port`, JAMAIS `toProxyUrl()` : cette URL
            // porte l'identifiant et le mot de passe du mandataire en clair.
            Log::warning('proxy.health_check.tls_non_verifie', [
                'provider' => $fournisseur,
                'endpoint' => $endpoint->host . ':' . $endpoint->port,
                'cle' => "crm.proxies.verify_tls.{$fournisseur}",
            ]);
        }

        return $verifier;
    }
}
