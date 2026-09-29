<?php

namespace App\Crm\Brave;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LE CRÉDIT GRATUIT MENSUEL DE BRAVE — compté en base, jamais dépassé.
 *
 * Budget de Will : ZÉRO euro. Le crédit gratuit de l'API Brave Search couvre
 * environ 1 000 requêtes par mois ; le plafond d'ici (`CRM_BRAVE_QUOTA_MENSUEL`,
 * 900 par défaut) garde une marge.
 *
 * La règle : une requête est RÉSERVÉE avant d'être envoyée, par un seul
 * `INSERT … ON CONFLICT … DO UPDATE … WHERE requetes < plafond`. Si la
 * réservation échoue, la requête n'est PAS envoyée. Conséquences :
 *
 *  - deux passages concurrents ne dépassent pas le plafond à eux deux (la
 *    ligne du mois est verrouillée par l'`UPDATE`) ;
 *  - une requête qui échoue (délai, 5xx, 429) reste comptée : Brave la
 *    décompte aussi, et « compter seulement les succès » dépasserait le
 *    crédit exactement quand l'API va mal.
 *
 * Mois civil UTC.
 */
class QuotaBrave
{
    public const PLAFOND_PAR_DEFAUT = 900;

    public function plafond(): int
    {
        $valeur = config('crm.brave.quota_mensuel', self::PLAFOND_PAR_DEFAUT);

        return max(0, is_numeric($valeur) ? (int) $valeur : self::PLAFOND_PAR_DEFAUT);
    }

    public function consommees(): int
    {
        return (int) DB::table('brave_quota_mensuel')->where('mois', $this->mois())->value('requetes');
    }

    public function restantes(): int
    {
        return max(0, $this->plafond() - $this->consommees());
    }

    /**
     * Réserve UNE requête du mois. `false` = plafond atteint : ne pas envoyer.
     */
    public function reserver(): bool
    {
        $plafond = $this->plafond();
        if ($plafond < 1) {
            return false;
        }

        $ligne = DB::selectOne(
            <<<'SQL'
            INSERT INTO brave_quota_mensuel (mois, requetes, created_at, updated_at)
            VALUES (?, 1, now(), now())
            ON CONFLICT (mois) DO UPDATE
                SET requetes = brave_quota_mensuel.requetes + 1, updated_at = now()
                WHERE brave_quota_mensuel.requetes < ?
            RETURNING requetes
            SQL,
            [$this->mois(), $plafond],
        );

        return $ligne !== null;
    }

    private function mois(): string
    {
        return Carbon::now('UTC')->startOfMonth()->toDateString();
    }
}
