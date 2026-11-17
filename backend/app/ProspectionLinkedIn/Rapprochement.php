<?php

namespace App\ProspectionLinkedIn;

use App\Support\LinkedinSlug;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PONT-LINKEDIN LI-B1 — rapprochement d'un profil avec une fiche, par lecture à la volée de `contacts.linkedin_url`
 * (aucune colonne ajoutée, ESCALADE E7). LECTURE SEULE, filtrée par espace.
 */
final class Rapprochement
{
    /**
     * @return array{contact_id: ?int, ambigu: bool} un seul contact ⇒ son id ; plusieurs ⇒ aucun et `ambigu`
     *
     * @throws RuntimeException lecture impossible (l'appelant refuse)
     */
    public function contactPour(string $workspaceId, string $slug): array
    {
        $motif = '%/in/' . addcslashes($slug, '%_\\') . '%';
        try {
            $lignes = WorkspaceContext::run($workspaceId, static fn () => DB::table('contacts')
                ->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereNotNull('linkedin_url')
                ->whereRaw('lower(linkedin_url) LIKE ?', [mb_strtolower($motif)])
                ->orderBy('id')->limit(50)->get(['id', 'linkedin_url']));
        } catch (QueryException) {
            throw new RuntimeException('Lecture des contacts impossible.');
        }

        $ids = [];
        foreach ($lignes as $l) {
            if (LinkedinSlug::normalize((string) $l->linkedin_url) === $slug) {
                $ids[] = (int) $l->id;
            }
        }

        return count($ids) === 1
            ? ['contact_id' => $ids[0], 'ambigu' => false]
            : ['contact_id' => null, 'ambigu' => count($ids) > 1];
    }
}
