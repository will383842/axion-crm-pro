<?php

namespace Tests\Support;

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use Closure;

/**
 * Décorateur d'essai : exécute `$avant` JUSTE avant de déléguer `injecter()` (donc après le COMMIT du temps A du séquenceur),
 * pour rejouer une opposition qui arrive entre la réservation et l'injection.
 */
final class ServeurAvantInjection implements ServeurEnvoi
{
    /** @param  Closure(MessageAEnvoyer): void  $avant */
    public function __construct(private readonly ServeurEnvoi $interne, private readonly Closure $avant) {}

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        ($this->avant)($message);

        return $this->interne->injecter($message);
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        return $this->interne->etatEnvoi($idInjection);
    }

    public function pause(): void
    {
        $this->interne->pause();
    }

    public function reprise(): void
    {
        $this->interne->reprise();
    }

    public function modeTemoins(): bool
    {
        return $this->interne->modeTemoins();
    }

    public function etat(): EtatServeur
    {
        return $this->interne->etat();
    }

    public function sante(): SanteServeur
    {
        return $this->interne->sante();
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        return $this->interne->lireEvenements($curseur, $limite);
    }
}
