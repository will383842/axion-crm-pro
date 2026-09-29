<?php

namespace App\Crm\Brave;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LE QUOTA BRAVE EN BASE (production) — table `brave_quota_mensuel`, une
 * ligne par mois civil UTC et par usage.
 *
 * La réservation vérifie le sous-quota ET le plafond global, puis compte, dans
 * une transaction courte tenue par un verrou consultatif : deux passages
 * concurrents (le passage des fédérations et un job d'enrichissement) ne
 * peuvent pas dépasser le plafond à eux deux. Une requête qui échoue ensuite
 * (délai, 5xx, 429) reste comptée : Brave la décompte aussi.
 *
 * Sous-quota ou plafond global à 0 : refus IMMÉDIAT, sans lire la base —
 * c'est le cas de l'enrichissement par défaut.
 */
class QuotaBraveEnBase extends PlafondsBrave
{
    private const VERROU = 'brave_quota_mensuel';

    public function reserver(string $usage): bool
    {
        if ($this->plafond($usage) < 1 || $this->plafond() < 1) {
            return false;
        }

        $mois = $this->mois();

        return DB::transaction(function () use ($usage, $mois): bool {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::VERROU]);

            $parUsage = DB::table('brave_quota_mensuel')->where('mois', $mois)->pluck('requetes', 'usage')->all();
            $total = (int) array_sum($parUsage);
            if (! $this->autorise($usage, (int) ($parUsage[$usage] ?? 0), $total)) {
                return false;
            }

            DB::statement(
                'INSERT INTO brave_quota_mensuel (mois, usage, requetes, created_at, updated_at)
                 VALUES (?, ?, 1, now(), now())
                 ON CONFLICT (mois, usage) DO UPDATE
                     SET requetes = brave_quota_mensuel.requetes + 1, updated_at = now()',
                [$mois, $usage],
            );

            return true;
        });
    }

    public function consommees(?string $usage = null): int
    {
        $requete = DB::table('brave_quota_mensuel')->where('mois', $this->mois());
        if ($usage !== null) {
            $requete->where('usage', $usage);
        }

        return (int) $requete->sum('requetes');
    }

    private function mois(): string
    {
        return Carbon::now('UTC')->startOfMonth()->toDateString();
    }
}
