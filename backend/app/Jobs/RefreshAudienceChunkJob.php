<?php

namespace App\Jobs;

use App\Models\AudienceMember;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Services\Audiences\AudienceBuilderService;
use App\Support\WaterfallSentry;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint H5 — Job de refresh d'un chunk de companies pour une audience donnée.
 *
 * Dispatched par AudienceBuilderService::refresh() via Bus::batch() pour audiences
 * > 5000 companies. Idempotent : INSERT ... ON CONFLICT DO NOTHING.
 *
 * Queue dédiée : audiences-refresh (max 10 workers en prod).
 */
class RefreshAudienceChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(
        public readonly int $audienceId,
        public readonly int $offset,
        public readonly int $limit,
    ) {
        $this->onQueue('audiences-refresh');
    }

    public function handle(AudienceBuilderService $builder): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $audience = EmailAudience::find($this->audienceId);
        if (! $audience) {
            Log::warning('RefreshAudienceChunkJob: audience missing', ['id' => $this->audienceId]);
            return;
        }

        try {
            $criteria = is_array($audience->criteria) ? $audience->criteria : [];

            // Récupère le chunk de company IDs (offset+limit sur companies workspace)
            // On utilise une sub-query du builder pour respecter le DSL criteria.
            $companyQuery = $builder->buildPublicQuery($audience->workspace_id, $criteria);

            $companyIds = $companyQuery
                ->select('companies.id')
                ->skip($this->offset)
                ->take($this->limit)
                ->pluck('id')
                ->all();

            if (empty($companyIds)) {
                return;
            }

            // INSERT ... ON CONFLICT DO NOTHING via DB direct (~x5 vs Eloquent)
            $contactRows = DB::table('contacts')
                ->whereIn('company_id', $companyIds)
                ->where('email_status', 'valid')
                ->select('id', 'company_id')
                ->get();

            $contactsByCompany = $contactRows->groupBy('company_id');
            $rows = [];
            foreach ($companyIds as $companyId) {
                $contacts = $contactsByCompany->get($companyId, collect());
                if ($contacts->isEmpty()) {
                    $rows[] = [
                        'audience_id'  => $audience->id,
                        'company_id'   => $companyId,
                        'contact_id'   => null,
                        'workspace_id' => $audience->workspace_id,
                        'added_at'     => now(),
                    ];
                } else {
                    foreach ($contacts as $c) {
                        $rows[] = [
                            'audience_id'  => $audience->id,
                            'company_id'   => $companyId,
                            'contact_id'   => $c->id,
                            'workspace_id' => $audience->workspace_id,
                            'added_at'     => now(),
                        ];
                    }
                }
            }

            DB::table('audience_members')->insertOrIgnore($rows);
        } catch (\Throwable $e) {
            WaterfallSentry::capture(null, 'audience-refresh-chunk', $e);
            throw $e;  // re-throw pour que Horizon mark failed + retry
        }
    }
}
