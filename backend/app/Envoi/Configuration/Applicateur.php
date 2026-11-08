<?php

namespace App\Envoi\Configuration;

use Throwable;

/** Valide à côté, applique, et rétablit tout seul si l'application échoue. Aucun envoi réel. */
final class Applicateur
{
    public function __construct(
        private readonly CibleConfiguration $cible,
        private readonly DepotVersions $versions,
        private readonly AlerteDerive $alerte,
    ) {}

    /** @param array<string, mixed> $brut */
    public function appliquer(string $workspaceId, array $brut): StatutVersion
    {
        $paquet = Paquet::deBrut($brut);
        $empreinte = $paquet->empreinte();

        $cles = GardeSocle::clesVisees($brut);
        if ($cles !== []) {
            $this->alerte->signaler($workspaceId, $cles);
            $this->versions->noter($workspaceId, $empreinte, StatutVersion::DeriveRefusee, implode(',', $cles));

            return StatutVersion::DeriveRefusee;
        }
        try {
            ValidateurPaquet::verifier($brut);
            if (! $this->cible->valider($paquet)) {
                throw new PaquetInvalide('refusé par la validation du service');
            }
        } catch (PaquetInvalide $e) {
            $this->versions->noter($workspaceId, $empreinte, StatutVersion::Invalide, $e->getMessage());

            return StatutVersion::Invalide;
        }
        try {
            $this->cible->appliquer($paquet);
        } catch (Throwable $e) {
            $this->cible->retablir();
            $this->versions->noter($workspaceId, $empreinte, StatutVersion::RetourArriere, $e::class);

            return StatutVersion::RetourArriere;
        }
        $this->versions->noter($workspaceId, $empreinte, StatutVersion::Appliquee);

        return StatutVersion::Appliquee;
    }
}
