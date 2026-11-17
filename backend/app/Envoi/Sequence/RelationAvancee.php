<?php

namespace App\Envoi\Sequence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * La relation commerciale a avancé sans passer par la séquence : l'entreprise est devenue cliente, ou un rendez-vous a été
 * pris. Une relance de prospection n'a plus de sens : le séquenceur arrête l'inscription (RR-5C, cahier D3-D6 § 10).
 *
 * Lecture seule du CRM. Deux requêtes au plus, chacune filtrée sur `workspace_id` (la RLS est contournée sous le
 * propriétaire : seul ce filtre cloisonne). Rien n'est écrit ici, rien n'est supprimé.
 */
final class RelationAvancee
{
    public const MOTIF_CLIENT = 'client';

    public const MOTIF_RENDEZ_VOUS = 'rendez_vous';

    /** Un rendez-vous plus ancien que ce délai avant l'inscription n'arrête pas la séquence (ESCALADE 4). */
    public const JOURS_RENDEZ_VOUS = 30;

    /** @return ?string `client`, `rendez_vous`, ou null : la séquence continue. */
    public function motif(string $ws, int $entrepriseId, int $contactId, DateTimeImmutable $inscriteLe): ?string
    {
        if (DB::table('companies')->where('workspace_id', $ws)->where('id', $entrepriseId)->where('lifecycle_stage', 'client')->exists()) {
            return self::MOTIF_CLIENT;
        }

        $depuis = $inscriteLe->modify('-' . self::JOURS_RENDEZ_VOUS . ' days')->format('c');
        $rendezVous = DB::table('activities')->where('workspace_id', $ws)->where('created_at', '>=', $depuis)
            ->where(function ($q) use ($entrepriseId, $contactId) {
                $q->where(fn ($r) => $r->where('kind', 'calendly_booked')->where('subject_type', 'company')->where('subject_id', $entrepriseId))
                    ->orWhere(fn ($r) => $r->where('kind', 'task')->whereRaw("payload->>'genre' = 'rendez_vous'")
                        ->where(fn ($s) => $s->where(fn ($t) => $t->where('subject_type', 'company')->where('subject_id', $entrepriseId))
                            ->orWhere(fn ($t) => $t->where('subject_type', 'contact')->where('subject_id', $contactId))));
            })->exists();

        return $rendezVous ? self::MOTIF_RENDEZ_VOUS : null;
    }
}
