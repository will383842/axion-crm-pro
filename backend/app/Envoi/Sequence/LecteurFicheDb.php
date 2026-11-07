<?php

namespace App\Envoi\Sequence;

use App\Crm\Campagnes\Segments;
use App\Crm\Emails\VerificationEmail;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Lecture DEPUIS LA BASE (contacts, companies, tags) : l'appelant ne fournit aucun fait. */
final class LecteurFicheDb implements LecteurFiche
{
    public function lire(string $workspaceId, int $contactId): ?Fiche
    {
        $l = DB::table('contacts as c')
            ->join('companies as o', 'o.id', '=', 'c.company_id')
            ->where('c.workspace_id', $workspaceId)
            ->where('o.workspace_id', $workspaceId)
            ->where('c.id', $contactId)
            ->whereNull('c.deleted_at')
            ->first(['c.id', 'c.company_id', 'c.email', 'c.email_status', 'o.country_code', 'o.siren', 'o.first_info_at', 'o.created_at']);
        if ($l === null || $l->email === null || trim((string) $l->email) === '') {
            return null;
        }

        $noms = DB::table('company_tag as ct')
            ->join('tags as t', 't.id', '=', 'ct.tag_id')
            ->where('ct.company_id', $l->company_id)
            ->where('t.workspace_id', $workspaceId)
            ->pluck('t.slug')->map(fn ($n): string => mb_strtolower(trim((string) $n)))->all();
        $segment = null;
        foreach (Segments::CONNUS as $s) {
            if (in_array(mb_strtolower(Segments::tag($s)), $noms, true)) {
                $segment = $s;
                break;
            }
        }

        $verification = match ((string) $l->email_status) {
            'valid' => VerificationEmail::VALIDE,
            'invalid' => VerificationEmail::INVALIDE,
            'disposable' => VerificationEmail::JETABLE,
            default => null,
        };

        return new Fiche(
            (int) $l->id,
            (int) $l->company_id,
            (string) $l->email,
            $l->country_code === null ? null : (string) $l->country_code,
            $l->siren === null ? null : (string) $l->siren,
            $segment,
            $l->created_at === null ? null : new DateTimeImmutable((string) $l->created_at),
            $l->first_info_at === null ? null : new DateTimeImmutable((string) $l->first_info_at),
            [['verification' => $verification]],
        );
    }
}
