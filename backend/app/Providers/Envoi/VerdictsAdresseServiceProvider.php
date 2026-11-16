<?php

namespace App\Providers\Envoi;

use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResolveurDnsUdp;
use App\Envoi\Verdicts\QuotaDns;
use App\Envoi\Verdicts\VerificateurAdresse;
use App\Envoi\Verdicts\VerificateurInterne;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Liaisons du lot « verdicts d'adresse » (point d'entrée préparé par #606, EnvoiServiceProvider intact).
 * `VerificateurAdresse` est lié au vérificateur INTERNE et à rien d'autre (aucun fournisseur externe).
 */
class VerdictsAdresseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(QuotaDns::class, fn (): QuotaDns => new QuotaDns);
        $this->app->bind(VerificateurInterne::class, fn ($app): VerificateurInterne => new VerificateurInterne(
            $app->make(QuotaDns::class),
            // Appelée au premier besoin : en test, le résolveur lié (interdit ou simulé) est repris tel quel.
            static fn (): ResolveurDns => app()->bound(ResolveurDns::class)
                ? app(ResolveurDns::class)
                : new ResolveurDnsUdp(
                    ResolveurDnsUdp::serveurParDefaut(config('crm.emails_verification.resolveur'))
                        ?? throw new RuntimeException('Aucun résolveur DNS configuré : aucune vérification de domaine.'),
                    4,
                    20,
                    3000,
                    2,
                ),
        ));
        $this->app->bind(VerificateurAdresse::class, VerificateurInterne::class);
    }

    public function boot(): void
    {
        // Aucune planification dans ce lot (CODE-2).
    }
}
