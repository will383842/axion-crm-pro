<?php

namespace App\Providers;

use App\Envoi\Chauffe\CompteurRetours;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\LecteurReports;
use App\Envoi\Chauffe\LecteurReportsDb;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Chauffe\ResolveurMx;
use App\Envoi\Chauffe\ToutArreter;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Depot\DepotTentatives;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\ArretParEntrepriseL1;
use App\Envoi\Desinscription\EffetsOpposition;
use App\Envoi\Desinscription\JetonDesinscription;
use App\Envoi\Desinscription\OppositionEnregistree;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\Imap\AdresseParEmpreinteCrm;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\FabriqueLecteursNonFournie;
use App\Envoi\Imap\RecepteurRetours;
use App\Envoi\Imap\RecepteurRetoursCrm;
use App\Envoi\Imap\RecepteurRetoursEnChaine;
use App\Envoi\Imap\RecepteurRetoursFreins;
use App\Envoi\Imap\RecepteurRetoursReponses;
use App\Envoi\Imap\RecepteurRetoursSequence;
use App\Envoi\Imap\RegistreMessagesEnvoyes;
use App\Envoi\Imap\RegistreMessagesEnvoyesDb;
use App\Envoi\Imap\ReleveImap;
use App\Envoi\ListesNoires\AlerteurJournal;
use App\Envoi\ListesNoires\DepotControles;
use App\Envoi\ListesNoires\DepotControlesDb;
use App\Envoi\ListesNoires\ResolveurSysteme;
use App\Envoi\ListesNoires\SourceRessources;
use App\Envoi\ListesNoires\SourceRessourcesDb;
use App\Envoi\ListesNoires\SurveillanceListes;
use App\Envoi\PasserelleLocale;
use App\Envoi\Retours\AppliquerRetour;
use App\Envoi\Sante\CouplageSante;
use App\Envoi\Sante\DepotSante;
use App\Envoi\Sante\DepotSanteDb;
use App\Envoi\Sante\EmetteurAlerteSante;
use App\Envoi\Sante\EmetteurAlerteSocle;
use App\Envoi\Sante\SondeSante;
use App\Envoi\Sante\SondeSanteK1;
use App\Envoi\Sante\SuperviseurSante;
use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Envoi\Smtp\ResolveurDns;
use App\Envoi\Smtp\ResolveurHote;
use App\Envoi\Temoins\LecteurIndesirables;
use App\Envoi\Temoins\LecteurIndesirablesWebklex;
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
        // K1 — client signé HMAC : tirage par curseur et commandes (interfaces NOUVELLES ;
        // `ServeurEnvoi` n'a reçu aucune méthode).
        $this->app->bind(ResolveurHote::class, ResolveurDns::class);
        $this->app->bind(ClientServeurEnvoiHmac::class);
        $this->app->bind(TireurEvenements::class, ClientServeurEnvoiHmac::class);
        $this->app->bind(CommandeurServeur::class, ClientServeurEnvoiHmac::class);
        $this->app->singleton(DepotChauffe::class);
        $this->app->singleton(ResolveurFournisseur::class, ResolveurMx::class);
        $this->app->singleton(AppliquerRetour::class, fn ($app) => new CompteurRetours($app->make(DepotChauffe::class), $app->make(ResolveurFournisseur::class)));
        // R06 : source réelle des reports (table vide au palier 0 ; frein NON actif avant T2). `AucunReportConnu` reste pour les tests.
        $this->app->singleton(LecteurReports::class, LecteurReportsDb::class);
        $this->app->singleton(DepotTentatives::class);
        $this->app->singleton(GardeChauffe::class, fn ($app) => new GardeChauffe($app->make(DepotChauffe::class), $app->make(DepotSocle::class), $app->make(ResolveurFournisseur::class), $app->make(Alerte::class), $app->make(LecteurReports::class)));
        $this->app->singleton(FauxServeurEnvoi::class);
        $this->app->singleton(ToutArreter::class, fn ($app) => new ToutArreter($app->make(DepotSocle::class), $app->make(ServeurEnvoi::class), $app->make(Alerte::class)));
        // K15 supervision : sonde K1, alerte par le Socle (jamais une boîte de prospection).
        $this->app->bind(SondeSante::class, SondeSanteK1::class);
        $this->app->bind(DepotSante::class, DepotSanteDb::class);
        $this->app->bind(EmetteurAlerteSante::class, fn ($app) => new EmetteurAlerteSocle($app->make(Alerte::class)));
        $this->app->bind(SuperviseurSante::class, fn ($app) => new SuperviseurSante(
            $app->make(SondeSante::class),
            $app->make(DepotSante::class),
            $app->make(EmetteurAlerteSante::class),
            $app->make(DepotChauffe::class),
            $app->make(CommandeurServeur::class),
            (array) config('envoi.sante'),
        ));
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
            // Garde M2 : tant que le retrait des envois en file OU l'arrêt par entreprise est la liaison « absente », une
            // opposition ne retirerait RIEN (file, collègues de l'entreprise) : l'envoi réel est tenu pour inactif.
            fn (): bool => (bool) config('envoi.reel_actif')
                && ! ($app->make(RetraitEnvoisEnFile::class) instanceof RetraitEnvoisEnFileAbsent)
                && ! ($app->make(ArretParEntreprise::class) instanceof ArretParEntrepriseAbsent),
            fn (): int => (int) config('envoi.plafond_jour'),
            null,
            '',
            fn (): int => (int) config('envoi.plafond_global_jour'),
            fn (): ?string => WorkspaceContext::current(),
            $app->make(GardeChauffe::class),
            $app->make(DepotTentatives::class),
        ));

        // M3 — relève IMAP branchée. Ordre de la chaîne (docs/envoi/rebonds-plaintes-k11.md) : 1. CRM (arrêts permanents,
        // `crm:campagne:retours`), 2. freins L7, 3. état des séquences (réponse humaine => arrêt). Le journal
        // (`envoi_evenements`) est écrit par `ReleveImap` lui-même, dans la même transaction que les trois récepteurs.
        // Aucune écriture dans `envoi_drapeaux`. Tout reste derrière ENVOI_RELEVE_ACTIF (faux) et ENVOI_REEL_ACTIF (faux).
        $this->app->bind(RecepteurRetours::class, fn ($app) => new RecepteurRetoursEnChaine(
            new RecepteurRetoursCrm,
            new RecepteurRetoursFreins($app->make(AppliquerRetour::class)),
            RecepteurRetoursSequence::parDefaut(),
            $app->make(RecepteurRetoursReponses::class),
        ));
        $this->app->bind(RegistreMessagesEnvoyes::class, fn () => new RegistreMessagesEnvoyesDb(
            static fn (string $espace, string $empreinte): ?string => AdresseParEmpreinteCrm::trouver($espace, $empreinte),
        ));
        // La fabrique RÉELLE de lecteurs (boîtes, secrets) n'est pas livrée : par défaut, échec visible et propre, sans réseau.
        $this->app->bind(FabriqueLecteurs::class, FabriqueLecteursNonFournie::class);
        $this->app->bind(ReleveImap::class, fn ($app) => new ReleveImap(
            $app->make(FabriqueLecteurs::class),
            $app->make(RegistreMessagesEnvoyes::class),
            $app->make(RecepteurRetours::class),
            $app->make(Alerte::class),
        ));

        // R16 — relève des indésirables des témoins : lecteur réel (Webklex, hôtes en liste fermée), résolu seulement derrière les deux interrupteurs.
        $this->app->bind(LecteurIndesirables::class, LecteurIndesirablesWebklex::class);

        // L5 désinscription : liaisons par défaut, remplacées par la file (retrait) et par L1 (arrêt par entreprise).
        $this->app->bind(RetraitEnvoisEnFile::class, RetraitEnvoisEnFileAbsent::class);
        $this->app->bind(ArretParEntreprise::class, ArretParEntrepriseL1::class);
    }

    public function boot(): void
    {
        Event::listen(OppositionEnregistree::class, EffetsOpposition::class);
        JetonDesinscription::garderAuDemarrage($this->app->environment());
        // K15 / ADR K0 : envoi réel + K1 sans supervision = signalé au démarrage (journal critique), pas passé sous silence.
        $this->app->booted(static fn () => CouplageSante::signaler());
    }
}
