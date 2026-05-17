<?php

namespace App\Console\Commands;

use App\Models\EmailAudience;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Console\Command;

/**
 * Refresh quotidien de toutes les audiences actives avec auto_refresh=true.
 * Schedulé via routes/console.php à 04:00 UTC.
 */
class AudiencesFullRefreshCommand extends Command
{
    protected $signature = 'audiences:full-refresh
        {--workspace= : Limiter à un workspace UUID}
        {--audience= : Limiter à une audience ID}';

    protected $description = 'Refresh tous les audience_members pour les audiences actives + auto_refresh';

    public function handle(AudienceBuilderService $builder): int
    {
        $query = EmailAudience::query()
            ->where('is_active', true)
            ->where('auto_refresh', true)
            ->whereNull('deleted_at');

        if ($ws = $this->option('workspace')) {
            $query->where('workspace_id', $ws);
        }
        if ($id = $this->option('audience')) {
            $query->where('id', (int) $id);
        }

        $audiences = $query->get();
        $this->info("Refresh {$audiences->count()} audience(s)...");

        $ok = 0;
        $failed = 0;
        foreach ($audiences as $audience) {
            try {
                $builder->refresh($audience);
                $ok++;
                $this->line(" ✓ #{$audience->id} {$audience->name} → {$audience->fresh()->member_count} members");
            } catch (\Throwable $e) {
                $failed++;
                $this->error(" ✗ #{$audience->id} {$audience->name} : {$e->getMessage()}");
            }
        }

        $this->info("Done: {$ok} OK, {$failed} failed");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
