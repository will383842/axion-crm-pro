<?php

namespace App\Services\Triage;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Décide du prospection_status final d'une company après enrichissement complet :
 *  - ready_for_outreach    : au moins 1 contact avec email_status=valid
 *  - partial_email         : email_generic présent (companies.email_generic) OU contact email_status=unknown
 *  - archived_no_email     : aucun email exploitable → archive (re-scrape mensuel)
 *
 * Si la company est marquée entreprise_radiee en amont, on respecte cet état.
 */
class TriageAutoService
{
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

        // Contacts avec email valide → ready
        $hasValidContact = DB::table('contacts')
            ->where('company_id', $company->id)
            ->where('email_status', 'valid')
            ->exists();
        if ($hasValidContact) {
            $newStatus = 'ready_for_outreach';
            $this->applyStatus($company, $newStatus, null);
            return ['status' => $newStatus, 'archive_reason' => null];
        }

        // Email générique ou contact unknown → partial
        $hasGenericEmail = ! empty($company->email_generic);
        $hasUnknownContact = DB::table('contacts')
            ->where('company_id', $company->id)
            ->whereIn('email_status', ['unknown', 'catchall'])
            ->exists();
        if ($hasGenericEmail || $hasUnknownContact) {
            $newStatus = 'partial_email';
            $this->applyStatus($company, $newStatus, null);
            return ['status' => $newStatus, 'archive_reason' => null];
        }

        // Sinon archive (no_email) — rescrape mensuel
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
        $company->prospection_status = $status;
        $company->archive_reason = $reason;
        $company->save();
    }
}
