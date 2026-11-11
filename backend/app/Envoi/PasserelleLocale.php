<?php

namespace App\Envoi;

use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\RenvoiParMemeCle;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le « socle local » : enveloppe du contrat. Ordre des portes, à chaque
 * injection, lues À CHAQUE FOIS (jamais mises en cache) :
 *   1. ENVOI_REEL_ACTIF éteint  => exception, zéro appel au serveur, zéro accès base ;
 *   2. message d'un autre espace que le contexte courant => exception ;
 *   puis, SOUS UN VERROU PAR JOUR (transaction + pg_advisory_xact_lock) :
 *   3. Tout arrêter             => exception ;
 *   4. mode témoins             => refuse toute adresse hors liste (empreintes) ;
 *   5. rejeu idempotent (même clé) => passe sans recompter ;
 *   5b. (L7) hors fenêtre d'envoi 8 h-19 h, homme mort (silence de sante()), pauses, freins, expéditeur inconnu,
 *       quota de chauffe de la boîte et du domaine => report au lendemain ;
 *   6. plafonds durs du jour (par espace ET global, bornés à 300) => report au lendemain ;
 *   7. (L8-F14a) « réserver, injecter, conclure » : la RÉSERVATION (qui compte dans les plafonds) se valide dans la
 *      transaction courte A ; le serveur est appelé HORS de toute transaction (B) ; la conclusion (`acceptee`,
 *      `liberee`, `a_verifier`) se note dans une transaction courte C. Issue incertaine : la place reste prise, alerte,
 *      et JAMAIS de renvoi automatique sauf serveur dédoublonnant par clé (RenvoiParMemeCle). Deux workers ne
 *      peuvent pas dépasser le plafond : la réservation est comptée sous le même verrou.
 * Le jour est le jour civil d'Europe/Paris (JourEnvoi).
 * Le mode témoins ne s'éteint que par `envoi:temoins-off` (voir DepotSocle).
 */
final class PasserelleLocale implements ServeurEnvoi
{
    /** Borne haute V0 des plafonds (par espace et global) : au-delà, la configuration est rejetée. */
    public const PLAFOND_MAX = 300;

    /** Une réservation sans issue au-delà de ce délai passe en `a_verifier`. */
    public const DELAI_RESERVATION_MINUTES = 15;

    private const RESERVEE = 'reservee_ici';

    public function __construct(
        private readonly ServeurEnvoi $serveur,
        private readonly DepotSocle $depot,
        private readonly bool|\Closure $reelActif,
        private readonly int|\Closure $plafondJour,
        private readonly ?\Closure $maintenant = null,
        private readonly string $espaceDefaut = '',
        private readonly int|\Closure|null $plafondGlobal = null,
        private readonly ?\Closure $contexteCourant = null,
        private readonly ?GardeChauffe $chauffe = null,
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
        // Fournisseur résolu UNE fois, AVANT le verrou (jamais de DNS sous verrou), puis réutilisé par refus() et noter().
        $fournisseur = $this->chauffe?->preparer($message);
        $plafond = $this->plafond($this->plafondJour);
        $global = $this->plafond($this->plafondGlobal ?? self::PLAFOND_MAX);

        // (A) Transaction COURTE sous le verrou du jour : portes, chauffe, plafonds, puis RÉSERVATION (elle compte). COMMIT.
        $decision = $this->depot->sousVerrouInjection($jour, function () use ($message, $ws, $jour, $plafond, $global, $fournisseur): ResultatInjection|string {
            // (D) Réservation restée `reservee` trop longtemps : issue inconnue => `a_verifier` (compte toujours), alerte.
            foreach ($this->depot->perimerReservations($ws, $this->instant()->modify('-' . self::DELAI_RESERVATION_MINUTES . ' minutes')) as $cle) {
                Log::critical('envoi.injection_a_verifier : réservation restée sans issue, intervention requise.', ['workspace_id' => $ws, 'cle' => $cle]);
            }
            if ($this->depot->toutArreter($ws)) {
                throw EnvoiRefuse::toutArreter();
            }
            if ($this->depot->modeTemoins($ws) && ! $this->depot->estTemoin($ws, $message->destinataire)) {
                throw EnvoiRefuse::horsTemoins();
            }
            // Rejeu (même clé) : rien n'est recompté, l'appel se fait HORS verrou et hors transaction.
            $etat = $this->depot->etatInjection($ws, $message->cleIdempotence);
            if ($etat !== null) {
                return $etat;
            }
            if ($this->depot->injectesLe($ws, $jour) >= $plafond || $this->depot->injectesLeGlobal($jour) >= $global) {
                return ResultatInjection::reporte($jour->modify('+1 day'));
            }
            // Chauffe et freins (lot L7) : pause ou quota => report, aucune réservation.
            if ($this->chauffe?->refus($message, $jour, $this->instant(), $fournisseur) !== null) {
                return ResultatInjection::reporte($jour->modify('+1 day'));
            }
            $this->depot->reserverInjection($ws, $jour, $message->cleIdempotence);

            return self::RESERVEE;
        });
        if ($decision instanceof ResultatInjection) {
            return $decision;
        }
        if ($decision !== self::RESERVEE) {
            return $this->rejouer($message, $decision);
        }

        // (B) HORS de toute transaction : le seul endroit où le serveur est appelé.
        try {
            $resultat = $this->serveur->injecter($message);
        } catch (EnvoiRefuse $e) {
            $this->conclure($ws, $jour, $message, 'liberee'); // refus net : rien n'est parti, la place est rendue
            throw $e;
        } catch (Throwable $e) {
            // Issue incertaine (coupure après envoi possible) : la place reste prise, une alerte part.
            $this->conclure($ws, $jour, $message, 'a_verifier');
            Log::critical('envoi.injection_a_verifier : issue incertaine, intervention requise.', ['workspace_id' => $ws, 'erreur' => $e::class]);
            throw $e;
        }

        // (C) Transaction COURTE : la conclusion (et, si acceptée, la chauffe) se notent une seule fois.
        $this->conclure($ws, $jour, $message, $resultat->accepte ? 'acceptee' : 'liberee', $fournisseur);

        return $resultat;
    }

    /** Renvoi d'une clé déjà réservée : seulement si le serveur dédoublonne lui-même (Kumo, simulateur), sinon JAMAIS. */
    private function rejouer(MessageAEnvoyer $message, string $etat): ResultatInjection
    {
        if ($this->serveur instanceof RenvoiParMemeCle || $etat === 'acceptee') {
            return $this->serveur->injecter($message);
        }
        throw EnvoiRefuse::issueIncertaine(); // `reservee` ou `a_verifier` en SMTP direct : on perd plutôt que de doubler
    }

    private function conclure(string $ws, DateTimeImmutable $jour, MessageAEnvoyer $message, string $statut, ?string $fournisseur = null): void
    {
        $this->depot->sousVerrouInjection($jour, function () use ($ws, $jour, $message, $statut, $fournisseur): void {
            if ($this->depot->concluireInjection($ws, $jour, $message->cleIdempotence, $statut) && $statut === 'acceptee') {
                $this->chauffe?->noter($message, $jour, $fournisseur);
            }
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
        if (! $this->reel()) {
            return new SanteServeur(false, 'envoi réel inactif');
        }
        // Homme mort (INF-025, plan 26) : le battement n'est noté que si le serveur répond OK ;
        // 15 minutes sans battement OK = pause de toutes les files (dans la fenêtre d'envoi, la seule où l'on envoie).
        $sante = $this->serveur->sante();
        if ($sante->ok) {
            $this->chauffe?->battre($this->instant());
        }

        return $sante;
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

    private function instant(): DateTimeImmutable
    {
        return $this->maintenant === null ? now()->toDateTimeImmutable() : DateTimeImmutable::createFromInterface(($this->maintenant)());
    }

    private function jour(): DateTimeImmutable
    {
        return JourEnvoi::de($this->maintenant === null ? null : ($this->maintenant)());
    }
}
