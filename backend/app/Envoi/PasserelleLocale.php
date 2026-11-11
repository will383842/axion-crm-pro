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
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Smtp\EnvoiSmtpRefuse;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
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

    /**
     * Une réservation sans issue au-delà de ce délai passe en `a_verifier`. 60 minutes : bien plus que le délai d'un
     * appel SMTP lent ou d'une reprise, pour qu'une réservation encore VIVANTE ne soit pas périmée sous les pieds de son worker.
     */
    public const DELAI_RESERVATION_MINUTES = 60;

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
            // (D) Réservation restée `reservee` trop longtemps (même horloge que la réservation) : issue inconnue => `a_verifier`
            // (compte toujours). L'alerte ne part qu'APRÈS le COMMIT de cette conclusion.
            foreach ($this->depot->perimerReservations($ws, $this->instant()->modify('-' . self::DELAI_RESERVATION_MINUTES . ' minutes')) as $cle) {
                DB::afterCommit(fn () => Log::critical('envoi.injection_a_verifier : réservation restée sans issue, intervention requise.', ['workspace_id' => $ws, 'cle' => $cle]));
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
            // Les réservations en vol (ou d'issue incertaine) comptent dans les quotas : sous le même verrou, aucun dépassement.
            $ressources = $this->chauffe?->ressources($message) ?? [];
            $enCours = $this->depot->reservationsEnCours($ws, $jour, $ressources['boite'] ?? null, $ressources['domaine'] ?? null);
            if ($this->chauffe?->refus($message, $jour, $this->instant(), $fournisseur, $enCours) !== null) {
                return ResultatInjection::reporte($jour->modify('+1 day'));
            }
            $this->depot->reserverInjection($ws, $jour, $message->cleIdempotence, $this->instant(), $ressources);

            return self::RESERVEE;
        });
        if ($decision instanceof ResultatInjection) {
            return $decision;
        }
        if ($decision !== self::RESERVEE) {
            return $this->rejouer($message, $decision, $ws, $jour, $fournisseur);
        }

        // (B) HORS de toute transaction : le seul endroit où le serveur est appelé.
        try {
            $resultat = $this->serveur->injecter($message);
        } catch (EnvoiRefuse|RefusDefinitifServeur $e) {
            // Refus CERTAIN (porte du serveur, 5xx définitif) : rien n'est parti, la place est rendue, pas d'alerte « incertaine ».
            $this->conclure($ws, $jour, $message, 'liberee');
            throw $e;
        } catch (EnvoiSmtpRefuse|ServeurEnvoiRefuse $e) {
            // Refus local fait AVANT toute transmission (enveloppe, expéditeur, message vide, transaction englobante, boîte
            // introuvable, garde de Kumo) : refus CERTAIN, la place est rendue, aucune alerte.
            $this->conclure($ws, $jour, $message, 'liberee');
            throw $e;
        } catch (Throwable $e) {
            // Échec APRÈS le début de transmission (coupure, réponse inconnue) : issue incertaine, la place reste prise.
            // L'alerte ne part qu'après le COMMIT de `a_verifier`.
            $this->conclure($ws, $jour, $message, 'a_verifier');
            DB::afterCommit(fn () => Log::critical('envoi.injection_a_verifier : issue incertaine, intervention requise.', ['workspace_id' => $ws, 'erreur' => $e::class]));
            throw $e;
        }

        // (C) Transaction COURTE : la conclusion (et, si acceptée, la chauffe) se notent une seule fois.
        // Accepté => acceptee ; INCERTAIN (report sans exception : coupure après DATA, réponse inconnue, clé a_verifier ou
        // injection_en_cours côté serveur) => a_verifier (la place COMPTE toujours, le plafond N n'est jamais dépassé) ;
        // report CERTAIN (rien n'est parti) => liberee.
        if ($resultat->accepte) {
            $statut = 'acceptee';
        } elseif ($resultat->incertain) {
            $statut = 'a_verifier';
        } else {
            $statut = 'liberee';
        }
        $this->conclure($ws, $jour, $message, $statut, $fournisseur, $resultat->idInjection);
        if ($statut === 'a_verifier') {
            DB::afterCommit(fn () => Log::critical('envoi.injection_a_verifier : issue incertaine rapportée par le serveur, intervention requise.', ['workspace_id' => $ws]));
        }

        return $resultat;
    }

    /**
     * Rejeu d'une clé déjà réservée.
     *   - `acceptee` : le message est parti, le serveur n'est PAS rappelé (l'identifiant conservé est rendu) ;
     *   - `reservee` / `a_verifier` : renvoi seulement si le serveur dédoublonne lui-même (Kumo, simulateur), sinon JAMAIS ;
     *     un renvoi CONFIRMÉ (`accepte`) conclut la ligne en `acceptee` (une seule fois) et note la chauffe.
     */
    private function rejouer(MessageAEnvoyer $message, string $etat, string $ws, DateTimeImmutable $jour, ?string $fournisseur): ResultatInjection
    {
        if ($etat === 'acceptee') {
            $id = $this->depot->idInjection($ws, $message->cleIdempotence);
            if ($id !== null) {
                return ResultatInjection::accepte($id);
            }
            // Ligne ancienne sans identifiant conservé : repli sur le serveur (il dédoublonne par clé).
        } elseif (! ($this->serveur instanceof RenvoiParMemeCle)) {
            throw EnvoiRefuse::issueIncertaine(); // `reservee` ou `a_verifier` en SMTP direct : on perd plutôt que de doubler
        }
        $resultat = $this->serveur->injecter($message);
        if ($etat !== 'acceptee' && $resultat->accepte) {
            $this->conclure($ws, $jour, $message, 'acceptee', $fournisseur, $resultat->idInjection);
        }

        return $resultat;
    }

    private function conclure(string $ws, DateTimeImmutable $jour, MessageAEnvoyer $message, string $statut, ?string $fournisseur = null, ?string $idInjection = null): void
    {
        $this->depot->sousVerrouInjection($jour, function () use ($ws, $jour, $message, $statut, $fournisseur, $idInjection): void {
            // Acceptation TARDIVE (réservation périmée en `a_verifier` pendant l'appel, ou renvoi confirmé) : `concluireInjection`
            // ajoute la confirmation (journal « ajout seul », transition a_verifier -> acceptee, une seule fois) et rend vrai.
            // Option la plus prudente : un message PARTI est toujours compté dans la chauffe (jamais sauté), quitte à compter
            // une fois de plus ; la place, elle, ne se libère jamais en retard.
            if ($this->depot->concluireInjection($ws, $jour, $message->cleIdempotence, $statut, $idInjection) && $statut === 'acceptee') {
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
