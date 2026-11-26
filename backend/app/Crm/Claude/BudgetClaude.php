<?php

namespace App\Crm\Claude;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * LE COMPTEUR GLOBAL DE DÉPENSE CLAUDE — table `depenses_claude_jour`.
 *
 * Montants en micro-dollars (entiers). Le coût MAXIMAL d'un appel est RÉSERVÉ
 * avant l'envoi, dans une transaction tenue par un verrou consultatif : deux
 * passages concurrents ne dépassent jamais le plafond à eux deux. Refus si une
 * borne serait dépassée : exécution, jour, poste ou total. Le total additionne
 * TOUS les jours et TOUS les postes, quelle que soit la clé (le budget n'est
 * pas par compte).
 *
 * `reserver()` refuse tout tant que `crm.enrichissement.payant_actif` est
 * fermé (défaut). Si le coût réel dépasse le maximum réservé, la réserve peut
 * dépasser un plafond : l'argent est déjà dépensé, on le compte quand même.
 *
 * Après l'appel, `ajuster()` remplace la réservation par le coût réel. Si la
 * réponse est illisible (coupure, 5xx), on n'appelle pas `ajuster()` avec un
 * réel : la réservation maximale est GARDÉE (prudence).
 *
 * Les plafonds de la configuration (`crm.claude.*`) ne peuvent QUE baisser les
 * constantes codées en dur ; une valeur plus haute est ignorée et signalée.
 */
class BudgetClaude
{
    public const PLAFOND_TOTAL_MICRO = 200_000_000;

    public const PLAFOND_JOUR_MICRO = 15_000_000;

    public const PLAFOND_EXECUTION_MICRO = 5_000_000;

    public const POSTE_RECHERCHE_WEB = 'recherche-web';

    public const POSTE_ARBITRAGE = 'arbitrage';

    public const POSTE_EXTRACTION = 'extraction';

    /** Plafond de chaque poste, en micro-dollars. */
    public const PLAFONDS_POSTES = [
        self::POSTE_RECHERCHE_WEB => 165_000_000,
        self::POSTE_ARBITRAGE => 20_000_000,
        self::POSTE_EXTRACTION => 20_000_000,
    ];

    private const VERROU = 'depenses-claude';

    /** Réservé dans CETTE exécution (une instance = une exécution). */
    private int $reserveExecution = 0;

    /**
     * Réserve `$microMax` pour `$poste`. Rend le jour (UTC, `Y-m-d`) de la
     * réservation, à repasser à `ajuster()` ; `null` si une borne est dépassée.
     */
    public function reserver(string $poste, int $microMax): ?string
    {
        $this->verifierPoste($poste);
        if ($microMax < 0) {
            throw new InvalidArgumentException('Une réservation ne peut pas être négative.');
        }
        // Interrupteur général : fermé par défaut, aucun appel payant sans accord.
        if (! config('crm.enrichissement.payant_actif', false)) {
            return null;
        }

        $jour = Carbon::now('UTC')->toDateString();
        if ($this->reserveExecution + $microMax > $this->plafond('plafond_execution_usd', self::PLAFOND_EXECUTION_MICRO)) {
            return null;
        }

        $accepte = DB::transaction(function () use ($poste, $microMax, $jour): bool {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::VERROU]);

            [$total, $parJour, $parPoste] = $this->sommes($jour, $poste);

            if ($total + $microMax > $this->plafondTotal()
                || $parJour + $microMax > $this->plafond('plafond_jour_usd', self::PLAFOND_JOUR_MICRO)
                || $parPoste + $microMax > $this->plafondPoste($poste)) {
                return false;
            }

            DB::statement(
                'INSERT INTO depenses_claude_jour (jour, poste, reserve_micro, reel_micro, appels, created_at, updated_at)
                 VALUES (?, ?, ?, 0, 1, now(), now())
                 ON CONFLICT (jour, poste) DO UPDATE
                     SET reserve_micro = depenses_claude_jour.reserve_micro + EXCLUDED.reserve_micro,
                         appels = depenses_claude_jour.appels + 1, updated_at = now()',
                [$jour, $poste, $microMax],
            );

            return true;
        });

        if (! $accepte) {
            return null;
        }
        $this->reserveExecution += $microMax;

        return $jour;
    }

    /**
     * Remplace la réservation `$microMax` par le coût réel. `$microReel` à
     * `null` (réponse illisible) : la réservation maximale est gardée.
     */
    public function ajuster(string $poste, string $jour, int $microMax, ?int $microReel): void
    {
        $this->verifierPoste($poste);
        if ($microReel === null) {
            return;
        }
        $microReel = max(0, $microReel);

        DB::transaction(function () use ($poste, $jour, $microMax, $microReel): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::VERROU]);
            $lignes = DB::update(
                'UPDATE depenses_claude_jour
                    SET reserve_micro = GREATEST(reserve_micro - ? + ?, 0),
                        reel_micro = reel_micro + ?, updated_at = now()
                  WHERE jour = ? AND poste = ?',
                [$microMax, $microReel, $microReel, $jour, $poste],
            );
            if ($lignes === 0) {
                Log::warning('Ajustement Claude sans ligne : la dépense réelle n\'est pas comptée.', ['jour' => $jour, 'poste' => $poste]);
            }
        });
        $this->reserveExecution = max(0, $this->reserveExecution - $microMax + $microReel);
    }

    /** Ce qu'on peut encore réserver aujourd'hui (toutes bornes), en micro-dollars. */
    public function restant(?string $poste = null): int
    {
        $jour = Carbon::now('UTC')->toDateString();
        [$total, $parJour, $parPoste] = $this->sommes($jour, $poste);

        $bornes = [
            $this->plafondTotal() - $total,
            $this->plafond('plafond_jour_usd', self::PLAFOND_JOUR_MICRO) - $parJour,
            $this->plafond('plafond_execution_usd', self::PLAFOND_EXECUTION_MICRO) - $this->reserveExecution,
        ];
        if ($poste !== null) {
            $this->verifierPoste($poste);
            $bornes[] = $this->plafondPoste($poste) - $parPoste;
        }

        return max(0, min($bornes));
    }

    /**
     * Réservé en tout, ce jour-là, et pour ce poste (tous jours confondus).
     *
     * @return array{int, int, int}
     */
    private function sommes(string $jour, ?string $poste): array
    {
        $total = $parJour = $parPoste = 0;
        foreach (DB::table('depenses_claude_jour')->get(['jour', 'poste', 'reserve_micro']) as $ligne) {
            $montant = (int) $ligne->reserve_micro;
            $total += $montant;
            $parJour += substr((string) $ligne->jour, 0, 10) === $jour ? $montant : 0;
            $parPoste += $ligne->poste === $poste ? $montant : 0;
        }

        return [$total, $parJour, $parPoste];
    }

    public function plafondTotal(): int
    {
        return $this->plafond('plafond_total_usd', self::PLAFOND_TOTAL_MICRO);
    }

    private function plafondPoste(string $poste): int
    {
        return $this->plafond('plafonds_postes_usd.' . $poste, self::PLAFONDS_POSTES[$poste]);
    }

    /** La constante, abaissée (jamais relevée) par la configuration en dollars. */
    private function plafond(string $cle, int $constante): int
    {
        $usd = config('crm.claude.' . $cle);
        if ($usd === null || $usd === '' || ! is_numeric($usd)) {
            return $constante;
        }

        $micro = (int) round(((float) $usd) * 1_000_000);
        if ($micro > $constante) {
            Log::warning('Plafond Claude de la configuration ignoré : il dépasse le plafond codé en dur.', ['cle' => $cle]);

            return $constante;
        }

        return max(0, $micro);
    }

    private function verifierPoste(string $poste): void
    {
        if (! isset(self::PLAFONDS_POSTES[$poste])) {
            throw new InvalidArgumentException("Poste Claude inconnu : {$poste}.");
        }
    }
}
