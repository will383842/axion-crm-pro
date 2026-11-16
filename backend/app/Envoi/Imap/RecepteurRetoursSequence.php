<?php

namespace App\Envoi\Imap;

use App\Envoi\Sequence\Evenement;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\RegistreInjectionsSequence;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Collection;
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
            $ids = $this->inscriptionsRattachees($workspaceId, $retour->idInjection);
            foreach ($ids as $id) {
                $this->inscriptions->evenement($workspaceId, (string) $id, Evenement::REPONSE, $maintenant);
            }
        });
    }

    /**
     * C1-2 — lecture des DEUX formes d'identifiant pendant la transition (cahier C1, §1.5). Un identifiant rattache les
     * messages où : (1) `id_injection` le porte (nouvelle forme, ou VERP Kumo ancien) ; (2) `id_injection_serveur` le porte ;
     * (3) s'il s'agit d'un UUID du registre local dont la clé est `seq-<inscription>-<étape>` : le message de cette
     * inscription et de cette étape (ancienne forme : le serveur seul était écrit dans `envoi_messages`).
     * Branches 2 et 3 à retirer dans un lot ultérieur, pas ici. Filtre d'espace explicite (la RLS est contournée en propriétaire).
     *
     * @return Collection<int, mixed>
     */
    private function inscriptionsRattachees(string $workspaceId, string $id)
    {
        $cle = null;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1) {
            $nomCle = DB::table('envoi_injections_locales')->where('workspace_id', $workspaceId)->where('id_injection', strtolower($id))->value('cle_idempotence');
            $cle = is_string($nomCle) ? RegistreInjectionsSequence::inscriptionDeCle($nomCle) : null;
        }

        return DB::table('envoi_messages')->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($id, $cle): void {
                $q->where('id_injection', $id)->orWhere('id_injection_serveur', $id);
                if ($cle !== null) {
                    $q->orWhere(fn ($r) => $r->where('inscription_id', $cle[0])->where('etape', $cle[1]));
                }
            })
            ->orderBy('inscription_id')->distinct()->pluck('inscription_id');
    }
}
