<?php

namespace App\Envoi\Orchestration;

use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use Illuminate\Contracts\Container\Container;

/**
 * Prédicat de la porte « envoi réel », extrait à l'identique de la fermeture de EnvoiServiceProvider (garde M2) :
 * le drapeau est actif ET le retrait des envois en file est branché ET l'arrêt par entreprise est branché.
 */
final class PorteEnvoiReelle
{
    public function __construct(private readonly Container $app) {}

    public function ouverte(): bool
    {
        return (bool) config('envoi.reel_actif')
            && ! ($this->resoudre(RetraitEnvoisEnFile::class) instanceof RetraitEnvoisEnFileAbsent)
            && ! ($this->resoudre(ArretParEntreprise::class) instanceof ArretParEntrepriseAbsent);
    }

    /** Type volontairement opaque : la liaison réelle dépend du conteneur, pas de la valeur par défaut que voit l'analyse statique. */
    private function resoudre(string $classe): mixed
    {
        return $this->app->make($classe);
    }
}
