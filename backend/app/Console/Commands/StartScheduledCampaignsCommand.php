<?php

namespace App\Console\Commands;

use App\Jobs\LaunchCampaignJob;
use App\Models\ScrapingCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class StartScheduledCampaignsCommand extends Command
{
    protected $signature = 'campaigns:start-scheduled';

    protected $description = 'Démarre les campagnes scheduled dont scheduled_at <= now() (cron * * * * *)';

    public function handle(): int
    {
        if (! Schema::hasTable('scraping_campaigns')) {
            $this->warn('Table scraping_campaigns absente, skip.');
            return self::SUCCESS;
        }

        $count = 0;
        ScrapingCampaign::query()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->chunkById(50, function ($campaigns) use (&$count) {
                foreach ($campaigns as $campaign) {
                    $campaign->update([
                        'status'     => 'running',
                        'started_at' => now(),
                    ]);
                    LaunchCampaignJob::dispatch($campaign->id);
                    $count++;
                }
            });

        $this->info("Démarré {$count} campagne(s) scheduled.");
        return self::SUCCESS;
    }
}
