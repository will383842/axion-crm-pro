<?php

namespace App\Envoi\Imap;

use App\Envoi\Sequence\Evenement;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * S11 — réponse humaine rattachée : la séquence du destinataire s'ARRÊTE (et, comme le veut `Inscriptions`, celles
 * des autres personnes de la même entreprise). Rattachement exact : l'injection du retour -> les messages du
 * séquenceur (`envoi_messages.id_injection`) -> leur inscription. Aucune adresse n'est lue ni écrite ici.
 *
 * S13 — absence du bureau : PAS de pause. L'état « en pause » n'existe pas (la contrainte de `envoi_inscriptions.statut`
 * n'admet que active/terminee/arretee) : l'inventer exigerait une migration et un contrôle côté tirage. L'auto-réponse
 * n'arrête donc rien (ni arrêt par erreur, ni relance retardée) ; voir `docs/envoi/rebonds-plaintes-k11.md`.
 *
 * Idempotent (l'événement est tracé une fois par inscription), ajout seul, aucun envoi.
 */
final class RecepteurRetoursSequence implements RecepteurRetours
{
    public function __construct(private readonly Inscriptions $inscriptions) {}

    public static function parDefaut(): self
    {
        return new self(new Inscriptions(new LecteurFicheDb));
    }

    public function recevoir(string $workspaceId, RetourDetecte $retour): void
    {
        if ($retour->classe !== ClasseRetour::ReponseHumaine) {
            return;
        }
        $maintenant = DateTimeImmutable::createFromInterface(now());
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $retour, $maintenant): void {
            $ids = DB::table('envoi_messages')->where('workspace_id', $workspaceId)
                ->where('id_injection', $retour->idInjection)->orderBy('inscription_id')->distinct()->pluck('inscription_id');
            foreach ($ids as $id) {
                $this->inscriptions->evenement($workspaceId, (string) $id, Evenement::REPONSE, $maintenant);
            }
        });
    }
}
