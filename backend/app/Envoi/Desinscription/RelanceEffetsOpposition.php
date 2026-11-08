<?php

namespace App\Envoi\Desinscription;

use App\Support\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Relance les effets (retrait de la file, arrêt par entreprise) des oppositions dont `effets_termines_le` est NULL.
 * Idempotente, ne supprime rien. Une opposition en échec est retentée avec un délai CROISSANT (2, 4, 8… min,
 * plafonné à 60) ; après 10 échecs une alerte critique est émise (une fois) mais on ne renonce jamais : l'opposition
 * est un droit. L'état (essais, délai) vit en BASE (`envoi_relances_effets`) : `cache:clear` ne le perd pas.
 * Les oppositions encore dans leur délai sont EXCLUES de la sélection : elles ne consomment pas le lot.
 * Les oppositions d'un espace supprimé sont journalisées (au plus un avertissement par jour et par espace).
 */
final class RelanceEffetsOpposition
{
    public const LOT = 500;

    private const ALERTE_APRES = 10;

    private const DELAI_MAX_MIN = 60;

    private const CLE_ESPACE_SUPPRIME = 'espace_supprime';

    public function __construct(private readonly TerminerEffetsOpposition $effets) {}

    /** @return int nombre d'oppositions dont les effets ont été terminés par ce passage */
    public function executer(): int
    {
        $maintenant = Carbon::now()->utc();
        foreach (DB::table('workspaces')->whereNotNull('deleted_at')->pluck('id') as $supprime) {
            $this->journaliserEspaceSupprime((string) $supprime, $maintenant);
        }
        $relancees = 0;
        foreach (DB::table('workspaces')->whereNull('deleted_at')->pluck('id') as $workspaceId) {
            $relancees += $this->relancerEspace((string) $workspaceId, $maintenant);
        }

        return $relancees;
    }

    private function journaliserEspaceSupprime(string $espace, Carbon $maintenant): void
    {
        $n = WorkspaceContext::run($espace, fn () => DB::table('envoi_oppositions')->where('workspace_id', $espace)->whereNull('effets_termines_le')->count());
        if ($n === 0) {
            return;
        }
        // Un journal par jour (UTC) et par espace : la ligne n'est prise que si le dernier journal date d'avant aujourd'hui.
        $pris = WorkspaceContext::run($espace, fn (): int => DB::affectingStatement(
            'INSERT INTO envoi_relances_effets (workspace_id, cle, journal_le) VALUES (?, ?, ?)
             ON CONFLICT (workspace_id, cle) DO UPDATE SET journal_le = EXCLUDED.journal_le, mis_a_jour_le = EXCLUDED.journal_le
             WHERE envoi_relances_effets.journal_le IS NULL OR envoi_relances_effets.journal_le < ?',
            [$espace, self::CLE_ESPACE_SUPPRIME, self::ts($maintenant), self::ts($maintenant->copy()->startOfDay())],
        ));
        if ($pris === 1) {
            Log::warning("Opposition(s) à terminer dans un espace supprimé : {$n}.", ['workspace_id' => $espace]);
        }
    }

    private function relancerEspace(string $workspaceId, Carbon $maintenant): int
    {
        // Les oppositions encore dans leur délai croissant ne sont PAS sélectionnées : elles ne mangent pas le lot.
        $lignes = WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_oppositions as o')
            ->leftJoin('envoi_relances_effets as r', function ($j): void {
                $j->on('r.workspace_id', '=', 'o.workspace_id')->whereRaw("r.cle = 'opposition:' || o.id::text");
            })
            ->where('o.workspace_id', $workspaceId)->whereNull('o.effets_termines_le')
            ->where(fn ($q) => $q->whereNull('r.pas_avant')->orWhere('r.pas_avant', '<=', self::ts($maintenant)))
            ->orderBy('o.id')->limit(self::LOT)->get(['o.id', 'r.essais']));

        $relancees = 0;
        foreach ($lignes as $ligne) {
            $id = (int) $ligne->id;
            if ($this->effets->pour($workspaceId, $id)) {
                $relancees++;

                continue;
            }
            $essais = (int) $ligne->essais + 1;
            $delai = min(2 ** $essais, self::DELAI_MAX_MIN);
            $this->noterEchec($workspaceId, $id, $essais, $maintenant->copy()->addMinutes($delai), $maintenant);
            if ($essais === self::ALERTE_APRES) {
                Log::critical("Opposition {$id} : effets toujours non terminés après {$essais} relances.", ['workspace_id' => $workspaceId]);
            }
        }

        return $relancees;
    }

    private function noterEchec(string $workspaceId, int $id, int $essais, Carbon $pasAvant, Carbon $maintenant): void
    {
        WorkspaceContext::run($workspaceId, fn () => DB::statement(
            'INSERT INTO envoi_relances_effets (workspace_id, cle, essais, pas_avant, alerte_le, mis_a_jour_le) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (workspace_id, cle) DO UPDATE SET essais = EXCLUDED.essais, pas_avant = EXCLUDED.pas_avant,
                 alerte_le = COALESCE(envoi_relances_effets.alerte_le, EXCLUDED.alerte_le), mis_a_jour_le = EXCLUDED.mis_a_jour_le',
            [$workspaceId, "opposition:{$id}", $essais, self::ts($pasAvant), $essais === self::ALERTE_APRES ? self::ts($maintenant) : null, self::ts($maintenant)],
        ));
    }

    private static function ts(Carbon $t): string
    {
        return $t->copy()->utc()->format('Y-m-d H:i:sP');
    }
}
