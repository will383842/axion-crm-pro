<?php

namespace App\Envoi\Orchestration;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\Sequenceur;
use App\Socle\Produits\ProduitsEspace;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Chef d'orchestre de l'envoi (D9) : un cycle = périmer les réservations restées sans issue, puis faire exécuter le
 * séquenceur, ESPACE PAR ESPACE. Aucun envoi réel tant que `ENVOI_REEL_ACTIF=false`.
 *
 * Règles (toutes testées) :
 *  - porte fermée (`PorteEnvoiReelle::ouverte()`, le même prédicat que la passerelle) : le cycle sort aussitôt, sans
 *    toucher au séquenceur, à une échéance ni à une réservation ;
 *  - un espace n'est traité que s'il est actif (corbeille exclue), a le produit Envoi, n'est pas sous arrêt général, a pour
 *    DERNIÈRE décision de feu vert « accorde » et n'a aucun point bloquant rouge (hors `auto.reel_actif_faux`) ;
 *  - chaque espace est traité sous son `WorkspaceContext`, avec un `Sequenceur` construit pour lui par la fabrique ;
 *  - une exception dans un espace n'arrête pas les autres ; deux cycles simultanés : un seul travaille (verrou) ;
 *  - le bilan journalisé ne contient que des compteurs (aucune adresse).
 */
final class ChefOrchestre
{
    /** Borne de garde (secondes) : aucun nouvel espace n'est commencé au-delà. Strictement inférieure à l'expiration du verrou. */
    public const BORNE_SECONDES = 120;

    public const VERROU_SECONDES = 600;

    public const VERROU = 'envoi:cycle';

    /** @var Closure(string): Sequenceur */
    private readonly Closure $fabrique;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $horloge;

    /**
     * @param  Closure(string): Sequenceur  $fabrique  construit un Sequenceur POUR l'espace donné (jamais un singleton)
     * @param  (Closure(): DateTimeImmutable)|null  $horloge
     */
    public function __construct(
        private readonly PorteEnvoiReelle $porte,
        private readonly DepotSocle $depot,
        private readonly ListeFeuVert $feuVert,
        Closure $fabrique,
        ?Closure $horloge = null,
    ) {
        $this->fabrique = $fabrique;
        $this->horloge = $horloge ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /** @return array<string, int|string|bool> compteurs seulement (jamais d'adresse) */
    public function cycle(): array
    {
        if (! $this->porte->ouverte()) {
            return ['porte_ouverte' => false, 'espaces' => 0, 'traites' => 0, 'sautes' => 0, 'erreurs' => 0];
        }
        $verrou = Cache::lock(self::VERROU, self::VERROU_SECONDES);
        if (! $verrou->get()) {
            return ['porte_ouverte' => true, 'verrou_pris' => true, 'espaces' => 0, 'traites' => 0, 'sautes' => 0, 'erreurs' => 0];
        }
        try {
            $debut = ($this->horloge)();
            $bilan = ['porte_ouverte' => true, 'espaces' => 0, 'traites' => 0, 'sautes' => 0, 'erreurs' => 0, 'inscriptions' => 0, 'hors_borne' => 0];
            $this->perimer();
            foreach (DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id') as $espace) {
                $ws = (string) $espace;
                $bilan['espaces']++;
                if (($this->horloge)()->getTimestamp() - $debut->getTimestamp() >= self::BORNE_SECONDES) {
                    $bilan['hors_borne']++;

                    continue;
                }
                try {
                    $n = $this->traiterEspace($ws);
                } catch (Throwable $e) {
                    $bilan['erreurs']++;
                    // Classe seulement : un message d'exception peut contenir une donnée de personne.
                    Log::error('envoi.cycle : espace en erreur.', ['workspace_id' => $ws, 'exception' => $e::class]);

                    continue;
                }
                if ($n === null) {
                    $bilan['sautes']++;
                } else {
                    $bilan['traites']++;
                    $bilan['inscriptions'] += $n;
                }
            }
            Log::info('envoi.cycle : bilan.', $bilan);

            return $bilan;
        } finally {
            $verrou->release();
        }
    }

    /** @return int|null nombre d'inscriptions traitées, ou null si l'espace est sauté */
    private function traiterEspace(string $ws): ?int
    {
        return WorkspaceContext::run($ws, function () use ($ws): ?int {
            $motif = $this->motifDeSaut($ws);
            if ($motif !== null) {
                $this->journaliserSaut($ws, $motif);

                return null;
            }
            $sequenceur = ($this->fabrique)($ws);
            if (WorkspaceContext::current() !== $ws) {
                throw new RuntimeException('Espace traité différent de l\'espace de construction du séquenceur.');
            }

            return array_sum($sequenceur->executer($ws, ($this->horloge)()));
        });
    }

    private function motifDeSaut(string $ws): ?string
    {
        if (! ProduitsEspace::actif($ws, 'envoi')) {
            return 'produit_envoi_absent';
        }
        if ($this->depot->toutArreter($ws)) {
            return 'arret_general';
        }
        $decision = $this->feuVert->derniereDecision($ws);
        if ($decision === null || $decision['decision'] !== 'accorde') {
            return 'feu_vert_non_accorde';
        }
        foreach ($this->feuVert->points($ws) as $p) {
            if ($p->bloquant && $p->code !== ListeFeuVert::POINT_EXPLOITATION) {
                return 'bloquant_rouge';
            }
        }

        return null;
    }

    /** Une seule ligne par espace, motif et jour (le cycle tourne chaque minute). */
    private function journaliserSaut(string $ws, string $motif): void
    {
        $cle = 'envoi.cycle.saut.' . $ws . '.' . $motif . '.' . ($this->horloge)()->format('Y-m-d');
        if (Cache::add($cle, 1, 86400)) {
            Log::info('envoi.cycle : espace sauté.', ['workspace_id' => $ws, 'motif' => $motif]);
        }
    }

    private function perimer(): void
    {
        $avant = ($this->horloge)()->modify('-' . PasserelleLocale::DELAI_RESERVATION_MINUTES . ' minutes');
        foreach ($this->depot->perimerToutesLesReservations($avant) as [$ws, $cle]) {
            Log::critical('envoi.injection_a_verifier : réservation restée sans issue, intervention requise.', ['workspace_id' => $ws, 'cle' => $cle]);
        }
    }
}
