<?php

namespace App\Providers;

use App\Envoi\Chauffe\CompteurRetours;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Chauffe\ResolveurMx;
use App\Envoi\Chauffe\ToutArreter;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\EffetsOpposition;
use App\Envoi\Desinscription\OppositionEnregistree;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\ListesNoires\AlerteurJournal;
use App\Envoi\ListesNoires\DepotControles;
use App\Envoi\ListesNoires\DepotControlesDb;
use App\Envoi\ListesNoires\ResolveurSysteme;
use App\Envoi\ListesNoires\SourceRessources;
use App\Envoi\ListesNoires\SourceRessourcesDb;
use App\Envoi\ListesNoires\SurveillanceListes;
use App\Envoi\PasserelleLocale;
use App\Envoi\Retours\AppliquerRetour;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Socle\Alertes\Alerte;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Palier 0 : le seul transport branché est le simulateur. */
class EnvoiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DepotSocle::class, DepotSocleDb::class);
        $this->app->singleton(DepotChauffe::class);
        $this->app->singleton(ResolveurFournisseur::class, ResolveurMx::class);
        $this->app->singleton(AppliquerRetour::class, fn ($app) => new CompteurRetours($app->make(DepotChauffe::class), $app->make(ResolveurFournisseur::class)));
        $this->app->singleton(GardeChauffe::class, fn ($app) => new GardeChauffe($app->make(DepotChauffe::class), $app->make(DepotSocle::class), $app->make(ResolveurFournisseur::class), $app->make(Alerte::class)));
        $this->app->singleton(FauxServeurEnvoi::class);
        $this->app->singleton(ToutArreter::class, fn ($app) => new ToutArreter($app->make(DepotSocle::class), $app->make(ServeurEnvoi::class), $app->make(Alerte::class)));
        $this->app->singleton(DepotControles::class, DepotControlesDb::class);
        $this->app->singleton(SourceRessources::class, SourceRessourcesDb::class);
        $this->app->singleton(SurveillanceListes::class, fn ($app) => new SurveillanceListes(
            new ResolveurSysteme,
            $app->make(DepotControles::class),
            new AlerteurJournal($app->make(DepotSocle::class)),
            array_values(array_map('strval', (array) config('envoi.listes_noires.zones_ip'))),
            array_values(array_map('strval', (array) config('envoi.listes_noires.zones_domaine'))),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable,
        ));
        $this->app->singleton(ServeurEnvoi::class, fn ($app) => new PasserelleLocale(
            $app->make(FauxServeurEnvoi::class),
            $app->make(DepotSocle::class),
            // Garde M2 : tant que le retrait des envois en file est la liaison « absente », une opposition
            // ne retirerait RIEN de la file (étape suivante d'une séquence) : l'envoi réel est tenu pour inactif.
            fn (): bool => (bool) config('envoi.reel_actif')
                && ! ($app->make(RetraitEnvoisEnFile::class) instanceof RetraitEnvoisEnFileAbsent),
            fn (): int => (int) config('envoi.plafond_jour'),
            null,
            '',
            fn (): int => (int) config('envoi.plafond_global_jour'),
            fn (): ?string => WorkspaceContext::current(),
            $app->make(GardeChauffe::class),
        ));

        // L5 désinscription : liaisons par défaut, remplacées par la file (retrait) et par L1 (arrêt par entreprise).
        $this->app->bind(RetraitEnvoisEnFile::class, RetraitEnvoisEnFileAbsent::class);
        $this->app->bind(ArretParEntreprise::class, ArretParEntrepriseAbsent::class);
    }

    public function boot(): void
    {
        Event::listen(OppositionEnregistree::class, EffetsOpposition::class);
    }
}
