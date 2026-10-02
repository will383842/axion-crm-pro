<?php

namespace App\Console\Commands;

use App\Models\EmailAudience;
use App\Services\Audiences\AudienceBuilderService;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refresh quotidien de toutes les audiences actives avec auto_refresh=true.
 * Schedulé via routes/console.php à 04:00 UTC.
 *
 * 🔴 LOT 3 (2026-10-02) — « Dernier rafraîchissement : jamais ».
 *
 * La tâche tourne bien chaque nuit (journal du planificateur, code de sortie
 * 0) — et n'avait JAMAIS rafraîchi une seule audience. `email_audiences` porte
 * `FORCE ROW LEVEL SECURITY` ; la commande interrogeait la table SANS contexte
 * d'espace, sous le rôle applicatif `axion_app` : la RLS rendait ZÉRO ligne,
 * la commande annonçait « Refresh 0 audience(s) » et sortait en succès. Les
 * trois audiences de production (« Prospects contactables » : 676 membres
 * figés depuis le 14 juillet) portaient `refreshed_at = NULL`.
 *
 * On parcourt désormais les espaces (table `workspaces`, hors RLS) et on
 * rafraîchit les audiences de chacun SOUS SON CONTEXTE (`WorkspaceContext::run`),
 * comme `crm:recalculer-quality-score`.
 */
class AudiencesFullRefreshCommand extends Command
{
    protected $signature = 'audiences:full-refresh
        {--workspace= : Limiter à un workspace UUID}
        {--audience= : Limiter à une audience ID}';

    protected $description = 'Refresh tous les audience_members pour les audiences actives + auto_refresh';

    public function handle(AudienceBuilderService $builder): int
    {
        $espaces = DB::table('workspaces')->orderBy('id')->pluck('id')->map(static fn ($id): string => (string) $id);
        if ($ws = $this->option('workspace')) {
            $espaces = $espaces->filter(static fn (string $id): bool => $id === (string) $ws);
        }

        $ok = 0;
        $failed = 0;
        $vues = 0;

        foreach ($espaces as $espace) {
            WorkspaceContext::run($espace, function () use ($builder, $espace, &$ok, &$failed, &$vues): void {
                $query = EmailAudience::query()
                    ->where('workspace_id', $espace)
                    ->where('is_active', true)
                    ->where('auto_refresh', true)
                    ->whereNull('deleted_at');

                if ($id = $this->option('audience')) {
                    $query->where('id', (int) $id);
                }

                foreach ($query->get() as $audience) {
                    $vues++;
                    try {
                        $builder->refresh($audience);
                        $ok++;
                        $this->line(" ✓ #{$audience->id} {$audience->name} → {$audience->fresh()?->member_count} membres");
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error(" ✗ #{$audience->id} {$audience->name} : {$e->getMessage()}");
                        Log::error('audiences:full-refresh — echec', ['audience_id' => $audience->id, 'exception' => $e->getMessage()]);
                    }
                }
            });
        }

        $this->info("Audiences vues : {$vues} · rafraîchies : {$ok} · en échec : {$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
