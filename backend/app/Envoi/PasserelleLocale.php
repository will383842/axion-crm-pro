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
 *   1. ENVOI_REEL_ACTIF éteint  => exception, zéro appel au serveur, zéro accès base ;
 *   2. message d'un autre espace que le contexte courant => exception ;
 *   puis, SOUS UN VERROU PAR JOUR (transaction + pg_advisory_xact_lock) :
 *   3. Tout arrêter             => exception ;
 *   4. mode témoins             => refuse toute adresse hors liste (empreintes) ;
 *   5. rejeu idempotent (même clé) => passe sans recompter ;
 *   6. plafonds durs du jour (par espace ET global, bornés à 300) => report au lendemain ;
 *   7. seulement alors, le serveur est appelé, et l'injection acceptée est notée
 *      dans la même transaction : deux workers ne peuvent pas dépasser le plafond.
 * Le jour est le jour civil d'Europe/Paris (JourEnvoi).
 * Le mode témoins ne s'éteint que par `envoi:temoins-off` (voir DepotSocle).
 */
final class PasserelleLocale implements ServeurEnvoi
{
    /** Borne haute V0 des plafonds (par espace et global) : au-delà, la configuration est rejetée. */
    public const PLAFOND_MAX = 300;

    public function __construct(
        private readonly ServeurEnvoi $serveur,
        private readonly DepotSocle $depot,
        private readonly bool|\Closure $reelActif,
        private readonly int|\Closure $plafondJour,
        private readonly ?\Closure $maintenant = null,
        private readonly string $espaceDefaut = '',
        private readonly int|\Closure|null $plafondGlobal = null,
        private readonly ?\Closure $contexteCourant = null,
    ) {}

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        // Porte 1, sans aucun accès base : drapeau éteint => rien ne se passe.
        if (! $this->reel()) {
            throw EnvoiRefuse::envoiReelInactif();
        }
        $ws = $message->workspaceId;
        if ($this->contexteCourant !== null && ($this->contexteCourant)() !== $ws) {
            throw EnvoiRefuse::autreEspace();
        }
        $jour = $this->jour();
        $plafond = $this->plafond($this->plafondJour);
        $global = $this->plafond($this->plafondGlobal ?? self::PLAFOND_MAX);

        // Les autres portes, le comptage, l'appel et la note sont sous UN verrou.
        return $this->depot->sousVerrouInjection($jour, function () use ($message, $ws, $jour, $plafond, $global): ResultatInjection {
            if ($this->depot->toutArreter($ws)) {
                throw EnvoiRefuse::toutArreter();
            }
            if ($this->depot->modeTemoins($ws) && ! $this->depot->estTemoin($ws, $message->destinataire)) {
                throw EnvoiRefuse::horsTemoins();
            }
            // Rejeu idempotent (même clé) : le serveur renvoie le même résultat, rien n'est recompté.
            if ($this->depot->dejaInjecte($ws, $message->cleIdempotence)) {
                return $this->serveur->injecter($message);
            }
            if ($this->depot->injectesLe($ws, $jour) >= $plafond || $this->depot->injectesLeGlobal($jour) >= $global) {
                return ResultatInjection::reporte($jour->modify('+1 day'));
            }

            $resultat = $this->serveur->injecter($message);
            if ($resultat->accepte) {
                $this->depot->noterInjection($ws, $jour, $message->cleIdempotence);
            }

            return $resultat;
        });
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

    private function plafond(int|\Closure $valeur): int
    {
        $n = $valeur instanceof \Closure ? (int) $valeur() : $valeur;
        if ($n < 0 || $n > self::PLAFOND_MAX) {
            throw EnvoiRefuse::plafondInvalide($n, self::PLAFOND_MAX);
        }

        return $n;
    }

    private function jour(): DateTimeImmutable
    {
        return JourEnvoi::de($this->maintenant === null ? null : ($this->maintenant)());
    }
}
