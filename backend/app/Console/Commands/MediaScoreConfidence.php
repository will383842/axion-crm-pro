<?php

namespace App\Console\Commands;

use App\Services\Email\EmailConfidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * media:score-confidence — calcule la confiance email A/B/C des médias qui ont un email.
 *
 * Même barème que les contacts entreprises (EmailConfidenceService) :
 *  A = email sur le domaine du site du média, B = domaine pro, C = boîte grand public.
 * Reprenable (ne traite que email_confidence IS NULL sauf --refresh), borné (--limit).
 */
class MediaScoreConfidence extends Command
{
    protected $signature = 'media:score-confidence {--limit=0} {--batch=2000} {--refresh}';

    protected $description = 'Calcule la confiance email A/B/C des médias (même barème que les contacts).';

    public function __construct(private readonly EmailConfidenceService $confidence)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $batch = max(100, (int) $this->option('batch'));
        $refresh = (bool) $this->option('refresh');

        $lastId = 0;
        $done = 0;

        while (true) {
            $q = DB::table('media')
                ->whereNull('deleted_at')
                ->whereNotNull('email')
                ->where('id', '>', $lastId);
            if (! $refresh) {
                $q->whereNull('email_confidence');
            }

            $rows = $q->orderBy('id')->limit($batch)->get(['id', 'email', 'website']);
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $m) {
                $lastId = (int) $m->id;
                $conf = $this->confidence->score((string) $m->email, $m->website);
                if ($conf !== null) {
                    DB::table('media')->where('id', $m->id)->update(['email_confidence' => $conf]);
                    $done++;
                }
            }

            if ($limit > 0 && $done >= $limit) {
                break;
            }
        }

        $this->info("Confiance email posée sur {$done} média(s).");

        return self::SUCCESS;
    }
}
