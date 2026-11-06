<?php

namespace App\Envoi;

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use DateTimeImmutable;

/**
 * Le « socle local » : enveloppe du contrat. Ordre des portes, à chaque
 * injection, lues À CHAQUE FOIS (jamais mises en cache) :
 *   1. ENVOI_REEL_ACTIF éteint  => exception, zéro appel au serveur ;
 *   2. Tout arrêter             => exception ;
 *   3. mode témoins             => refuse toute adresse hors liste ;
 *   4. plafond dur du jour      => report au lendemain ;
 *   5. seulement alors, le serveur est appelé.
 * Le mode témoins ne s'éteint que par `envoi:temoins-off` (voir DepotSocle).
 */
final class PasserelleLocale implements ServeurEnvoi
{
    public function __construct(
        private readonly ServeurEnvoi $serveur,
        private readonly DepotSocle $depot,
        private readonly bool|\Closure $reelActif,
        private readonly int|\Closure $plafondJour,
        private readonly ?\Closure $maintenant = null,
        private readonly string $espaceDefaut = '',
    ) {}

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        if (! $this->reel()) {
            throw EnvoiRefuse::envoiReelInactif();
        }
        $ws = $message->workspaceId;
        if ($this->depot->toutArreter($ws)) {
            throw EnvoiRefuse::toutArreter();
        }
        if ($this->depot->modeTemoins($ws) && ! $this->depot->estTemoin($ws, $message->destinataire)) {
            throw EnvoiRefuse::horsTemoins();
        }
        $jour = $this->jour();
        if ($this->depot->injectesLe($ws, $jour) >= $this->plafond()) {
            return ResultatInjection::reporte($jour->modify('+1 day'));
        }

        $resultat = $this->serveur->injecter($message);
        if ($resultat->accepte) {
            $this->depot->noterInjection($ws, $jour);
        }

        return $resultat;
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        return $this->reel() ? $this->serveur->etatEnvoi($idInjection) : EtatMessage::Inconnu;
    }

    public function pause(): void
    {
        if ($this->reel()) {
            $this->serveur->pause();
        }
    }

    public function reprise(): void
    {
        if ($this->reel()) {
            $this->serveur->reprise();
        }
    }

    public function modeTemoins(): bool
    {
        return $this->espaceDefaut === '' ? true : $this->depot->modeTemoins($this->espaceDefaut);
    }

    public function etat(): EtatServeur
    {
        return $this->reel() ? $this->serveur->etat() : new EtatServeur(true, 0);
    }

    public function sante(): SanteServeur
    {
        return $this->reel() ? $this->serveur->sante() : new SanteServeur(false, 'envoi réel inactif');
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        return $this->reel() ? $this->serveur->lireEvenements($curseur, $limite) : new LotEvenements([], $curseur);
    }

    private function reel(): bool
    {
        return $this->reelActif instanceof \Closure ? (bool) ($this->reelActif)() : $this->reelActif;
    }

    private function plafond(): int
    {
        return $this->plafondJour instanceof \Closure ? (int) ($this->plafondJour)() : $this->plafondJour;
    }

    private function jour(): DateTimeImmutable
    {
        $maintenant = $this->maintenant === null ? new DateTimeImmutable : ($this->maintenant)();

        return $maintenant->setTime(0, 0);
    }
}
