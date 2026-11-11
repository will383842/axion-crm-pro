<?php

namespace App\Envoi\Conformite;

use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Models\Workspace;
use App\Support\ListeSuppression;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * « Puis-je écrire à… ? » (lot R05). LECTURE SEULE : la porte `EnvoiAutorise::verifier` est appelée telle quelle,
 * sur la fiche de l'espace courant ; rien n'est écrit, rien n'est envoyé.
 *
 * Anti-oracle : tout ce qui est lu l'est AVEC le `workspace_id` de l'appelant. Une adresse que cet espace ne connaît pas
 * (inconnue du monde ou connue d'un autre espace seulement) reçoit donc la même réponse, construite sans rien lire
 * ailleurs. L'adresse ne sort jamais de cette classe : ni journal, ni exception, ni réponse.
 */
final class PuisJeEcrire
{
    /** @return array{peut: bool, motif: ?string} */
    public function repondre(string $workspaceId, string $adresse, DateTimeInterface $maintenant): array
    {
        $adresse = mb_strtolower(trim($adresse));

        if (DB::table('envoi_oppositions')->where('workspace_id', $workspaceId)->where('empreinte_adresse', ListeSuppression::empreinte($adresse))->exists()) {
            return ['peut' => false, 'motif' => MotifRegroupe::OPPOSITION];
        }
        $contactId = DB::table('contacts')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->where('email', $adresse)->orderBy('id')->value('id');
        $fiche = $contactId === null ? null : (new LecteurFicheDb)->lire($workspaceId, (int) $contactId);
        if ($fiche === null) {
            return ['peut' => false, 'motif' => MotifRegroupe::NON_VERIFIEE];
        }
        if ($fiche->exclusion !== null) {
            return ['peut' => false, 'motif' => MotifRegroupe::de($fiche->exclusion)];
        }

        $dejaEnvoye = DB::table('envoi_messages as m')->join('envoi_inscriptions as i', 'i.id', '=', 'm.inscription_id')
            ->where('m.workspace_id', $workspaceId)->where('i.empreinte_adresse', ListeSuppression::empreinte($adresse))->where('m.statut', 'envoye')->exists();
        // Domaine et entité expéditrice de l'espace : le premier domaine d'envoi connu et le nom de l'espace (aucun n'est envoyé).
        $domaine = (string) DB::table('envoi_domaines')->where('workspace_id', $workspaceId)->orderBy('nom')->value('nom');
        $entite = (string) Workspace::query()->whereKey($workspaceId)->value('name');

        $verdict = EnvoiAutorise::verifier(new DemandeEnvoi(
            $workspaceId,
            $fiche->email,
            $fiche->occurrences,
            $fiche->pays,
            $fiche->siren,
            $domaine,
            $entite,
            $fiche->collecteeLe,
            $fiche->premiereInfoLe,
            $fiche->segment,
            ! $dejaEnvoye,
        ), $maintenant);

        return ['peut' => $verdict->autorise(), 'motif' => MotifRegroupe::de($verdict->refus)];
    }
}
