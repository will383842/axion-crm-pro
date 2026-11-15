<?php

namespace App\Envoi\Sequence;

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Message\ComposeurMessage;
use App\Envoi\Message\SignatureDkim;
use App\Envoi\Reglages\DepotReglages;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Expéditeur PAR ESPACE (L8-COMPO). `ParametresExpedition` étant fixé dans le constructeur du `Sequenceur`, la fabrique
 * `sequenceurPour` construit un `Sequenceur` par espace (jamais un singleton). Les faits viennent de la base :
 * identité (`DepotReglages::identite`, R14), domaine d'envoi (`envoi_domaines`) et adresse de la boîte active de l'espace
 * sur ce domaine (`envoi_boites`). Identité, domaine ou boîte manquant : refus (aucune injection possible).
 */
final class SourceExpeditionDb
{
    public function __construct(private readonly DepotReglages $reglages) {}

    /** @throws RuntimeException identité, domaine ou boîte manquant */
    public function parametresPour(string $ws): ParametresExpedition
    {
        $identite = $this->identiteComplete($ws);
        [$domaine, $expediteur] = $this->domaineEtBoite($ws);

        return new ParametresExpedition($domaine, $expediteur, trim($identite['raison_sociale'] . ' ' . $identite['forme']));
    }

    /** @throws RuntimeException */
    public function composeurPour(string $ws): ComposeurSequenceMessage
    {
        $identite = $this->identiteComplete($ws);
        [$domaine] = $this->domaineEtBoite($ws);

        return new ComposeurSequenceMessage(new ComposeurMessage($domaine, null, SignatureDkim::Serveur), $identite);
    }

    /** @throws RuntimeException */
    public function sequenceurPour(string $ws, ServeurEnvoi $serveur, LecteurFiche $fiches): Sequenceur
    {
        return new Sequenceur($serveur, $fiches, $this->composeurPour($ws), $this->parametresPour($ws));
    }

    /** @return array<string, mixed> */
    private function identiteComplete(string $ws): array
    {
        $identite = $this->reglages->identite($ws);
        foreach (['raison_sociale', 'adresse_postale', 'bloc_information'] as $champ) {
            if (trim((string) $identite[$champ]) === '') {
                throw new RuntimeException("Identité de l'espace incomplète ({$champ}) : aucun message ne peut être composé.");
            }
        }

        return $identite;
    }

    /** @return array{0: string, 1: string} [domaine d'envoi, adresse d'expéditeur] */
    private function domaineEtBoite(string $ws): array
    {
        $lignes = WorkspaceContext::run($ws, fn (): array => [
            DB::table('envoi_domaines')->where('workspace_id', $ws)->orderBy('id')->pluck('nom')->all(),
            DB::table('envoi_boites')->where('workspace_id', $ws)->where('etat', 'active')->orderBy('cree_le')->pluck('adresse')->all(),
        ]);
        [$domaines, $boites] = $lignes;
        foreach ($domaines as $domaine) {
            foreach ($boites as $adresse) {
                if (str_ends_with(mb_strtolower((string) $adresse), '@' . mb_strtolower((string) $domaine))) {
                    return [(string) $domaine, (string) $adresse];
                }
            }
        }

        throw new RuntimeException("Aucun domaine d'envoi avec une boîte active dans l'espace : aucun expéditeur.");
    }
}
