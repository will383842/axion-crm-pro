<?php

namespace App\Services\Triage;

use App\Models\Company;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Décide du prospection_status final d'une company après enrichissement complet.
 *
 * Sprint H8 (2026-05-18) — Doctrine Will : un email même incertain est
 * envoyable. La distinction valid / unknown / catchall ne sert plus à
 * filtrer l'inclusion en audience, juste à informer le commercial.
 *
 *  - ready_for_outreach : ≥ 1 contact dont email_status est CONTACTABLE
 *                          (valid|catchall|unknown), OU company.email_generic
 *                          présent (contact@…, info@…, hello@…).
 *  - partial_email      : statut historique conservé pour rétro-compat mais
 *                          plus utilisé en logique d'inclusion audience.
 *  - archived_no_email  : vraiment aucun email — re-scrape mensuel.
 *
 * Si la company est marquée entreprise_radiee en amont, on respecte cet état.
 */
class TriageAutoService
{
    /**
     * Statuts d'email considérés comme contactables (= envoyables).
     * Sprint H8 doctrine Will : on inclut les unknown/catchall qui pourraient
     * bouncer plutôt que de perdre des leads.
     */
    public const CONTACTABLE_EMAIL_STATUSES = ['valid', 'catchall', 'unknown', 'role'];

    /**
     * Retourne le status décidé et l'archive_reason éventuelle.
     *
     * @return array{status: string, archive_reason: ?string}
     */
    public function triage(Company $company): array
    {
        // Respecter un statut radié déjà posé (ex: par step1 INSEE si etatAdministratif != 'A')
        if ($company->prospection_status === 'archived_no_email'
            && $company->archive_reason === 'entreprise_radiee') {
            return ['status' => 'archived_no_email', 'archive_reason' => 'entreprise_radiee'];
        }

        // Sprint H8 — un email contactable (valid|catchall|unknown) OU un
        // email_generic suffit pour passer ready_for_outreach.
        $hasContactableContact = DB::table('contacts')
            ->where('company_id', $company->id)
            ->whereIn('email_status', self::CONTACTABLE_EMAIL_STATUSES)
            ->exists();
        $hasGenericEmail = ! empty($company->email_generic);

        if ($hasContactableContact || $hasGenericEmail) {
            $newStatus = 'ready_for_outreach';
            $this->applyStatus($company, $newStatus, null);
            return ['status' => $newStatus, 'archive_reason' => null];
        }

        // Vraiment aucun email → archive (re-scrape mensuel via H6)
        $newStatus = 'archived_no_email';
        $reason = 'no_email';
        $this->applyStatus($company, $newStatus, $reason);
        return ['status' => $newStatus, 'archive_reason' => $reason];
    }

    private function applyStatus(Company $company, string $status, ?string $reason): void
    {
        if ($company->prospection_status === $status
            && $company->archive_reason === $reason) {
            return;
        }
        $oldStatus = $company->prospection_status;
        $company->prospection_status = $status;
        $company->archive_reason = $reason;
        $company->save();

        // Sprint H4 — Audit transition uniquement vers archived (info la plus exploitable)
        if ($status === 'archived_no_email' && $oldStatus !== 'archived_no_email') {
            AuditLogger::log('company.archived', [
                'workspace_id'   => (string) $company->workspace_id,
                'resource_type'  => 'company',
                'resource_id'    => (string) $company->id,
                'siren'          => $company->siren,
                'archive_reason' => $reason,
                'previous_status'=> $oldStatus,
            ]);
        }
    }
}
