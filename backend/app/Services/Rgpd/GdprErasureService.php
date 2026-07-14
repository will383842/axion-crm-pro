<?php

namespace App\Services\Rgpd;

use App\Services\Audit\AuditHashChain;
use App\Services\Dedup\DeduplicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Effacement RGPD art. 17 — transaction multi-tables atomique.
 * Toutes les données PII d'un sujet (email/phone) sont supprimées en cascade,
 * un opt-out cross-workspace est créé pour bloquer toute future collecte.
 */
class GdprErasureService
{
    public function __construct(
        private readonly AuditHashChain $audit,
        private readonly DeduplicationService $dedup,
    ) {}

    /** @return array{deleted: array<string,int>, opt_out_added: bool} */
    public function erase(string $subjectEmail, ?string $phone = null, ?string $reason = 'gdpr_art17'): array
    {
        return DB::transaction(function () use ($subjectEmail, $phone, $reason) {
            $email = strtolower(trim($subjectEmail));
            $deleted = [];

            $deleted['contacts'] = DB::table('contacts')->where('email', $email)->delete();
            $deleted['email_validations'] = DB::table('email_validations')->where('email', $email)->delete();
            $deleted['rgpd_requests'] = DB::table('rgpd_requests')->where('subject_email', $email)->where('type', '!=', 'erasure')->delete();
            $deleted['notifications'] = DB::table('notifications')->whereRaw('body ILIKE ?', ['%' . $email . '%'])->delete();
            $deleted['magic_links'] = DB::table('magic_links')->where('email', $email)->delete();

            // Journalistes (données personnelles B2B) : anonymisation + opt-out + soft-delete
            // plutôt que suppression dure, pour conserver la traçabilité de l'effacement.
            $deleted['journalists'] = DB::table('journalists')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($email, $phone) {
                    $q->where('email', $email);
                    if ($phone !== null && $phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                })
                ->update([
                    'email'      => null,
                    'phone'      => null,
                    'opt_out'    => true,
                    'deleted_at' => now(),
                ]);

            // Médias : neutralise un email de contact rédaction correspondant au sujet.
            $deleted['media_email'] = DB::table('media')->where('email', $email)->update(['email' => null]);

            // Audit log — la suppression elle-même
            $this->audit->record([
                'workspace_id' => null,
                'user_id'      => null,
                'method'       => 'GDPR_ERASURE',
                'path'         => '/internal/gdpr/erase',
                'status'       => 200,
                'ip'           => null,
                'user_agent'   => null,
                'payload_hash' => hash('sha256', $email . '|' . ($phone ?? '')),
            ]);

            // Opt-out cross-workspace (bloque future collecte)
            $this->dedup->addOptOut($email, $phone, source: 'gdpr_erasure', reason: $reason);

            Log::info('GDPR erasure complete', ['email' => $email, 'deleted' => $deleted]);

            return ['deleted' => $deleted, 'opt_out_added' => true];
        });
    }
}
