<?php

namespace App\ProspectionLinkedIn;

use App\Envoi\Conformite\RegistreOppositionLinkedin;
use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * PONT-LINKEDIN LI-C — geste « opposition » d'un prospect, EN UNE TRANSACTION et dans l'ordre : registre LinkedIn, puis (fiche
 * rattachée avec adresse) registre manuel d'adresse — ce qui prévient le site —, puis état `ecarte` et journal. Une erreur à
 * n'importe quelle étape annule TOUT (le registre fait foi : rien d'à moitié écrit) et lève `RuntimeException` (échec fermé).
 * Rien n'est supprimé ; la fiche et le journal restent. Aucun appel vers LinkedIn.
 */
final class OpposerProspect
{
    public function __construct(
        private readonly ?RegistreOppositionLinkedin $registreLinkedin = null,
        private readonly ?RegistreOppositionManuel $registreAdresse = null,
        private readonly ?DepotProspects $depot = null,
    ) {}

    /**
     * @return array{etat: string, sans_effet: bool, rejeu: bool}
     *
     * @throws RuntimeException prospect introuvable, ou une étape a échoué (tout est annulé)
     */
    public function opposer(string $workspaceId, string $prospectId, string $cleIdempotence, string $auteur): array
    {
        try {
            return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $prospectId, $cleIdempotence, $auteur): array {
                return DB::transaction(function () use ($workspaceId, $prospectId, $cleIdempotence, $auteur): array {
                    $p = DB::table('pl_prospects')->where('workspace_id', $workspaceId)->where('id', $prospectId)->lockForUpdate()->first();
                    if ($p === null) {
                        throw new RuntimeException('Prospect introuvable.');
                    }
                    ($this->registreLinkedin ?? new RegistreOppositionLinkedin)->inscrire($workspaceId, (string) $p->linkedin_slug, 'opposition de la personne (file LinkedIn)', $auteur, 'prospection_linkedin');

                    $adresse = $p->contact_id === null ? null : DB::table('contacts')
                        ->where('workspace_id', $workspaceId)->where('id', $p->contact_id)->value('email');
                    if (is_string($adresse) && str_contains($adresse, '@')) {
                        ($this->registreAdresse ?? new RegistreOppositionManuel)->inscrire($workspaceId, 'adresse', $adresse, 'opposition de la personne (file LinkedIn)', $auteur);
                    }

                    return ($this->depot ?? new DepotProspects)->appliquerGeste($workspaceId, $prospectId, 'opposition', $cleIdempotence, $auteur);
                });
            });
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('Opposition impossible à enregistrer : rien n’a été écrit.');
        }
    }
}
