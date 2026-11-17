<?php

namespace App\Envoi\Imap;

use App\Envoi\Desinscription\EnregistrerOpposition;
use App\Envoi\Desinscription\OpposerAdresse;
use App\Envoi\Sequence\Evenement;
use App\Envoi\Sequence\Inscriptions;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ReportAbsence;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * RR-3A : voir `recevoir` (STOP, plainte et rebond dur arrêtent aussi la séquence).
 *
 * S11 — réponse humaine rattachée : la séquence du destinataire s'ARRÊTE (et, comme le veut `Inscriptions`, celles
 * des autres personnes de la même entreprise). Rattachement exact : l'injection du retour -> les messages du
 * séquenceur (`envoi_messages.id_injection`) -> leur inscription. Aucune adresse n'est lue ni écrite ici.
 *
 * S13 (RR-4C) — absence du bureau : aucun arrêt ni nouveau statut ; la prochaine relance est repoussée de 7 jours
 * (`ReportAbsence`, jamais d'avance, jamais sur une inscription arrêtée) ; voir `docs/envoi/rebonds-plaintes-k11.md`.
 *
 * Idempotent (l'événement est tracé une fois par inscription), ajout seul, aucun envoi.
 */
final class RecepteurRetoursSequence implements RecepteurRetours
{
    public function __construct(
        private readonly Inscriptions $inscriptions,
        private readonly InscriptionsDuRetour $rattachees = new InscriptionsDuRetour,
        private readonly ?OpposerAdresse $opposer = null,
    ) {}

    public static function parDefaut(): self
    {
        return new self(new Inscriptions(new LecteurFicheDb), new InscriptionsDuRetour, new OpposerAdresse(app(EnregistrerOpposition::class)));
    }

    /**
     * RR-3A — réponse : `reponse` ; STOP et plainte : `opposition` (adresse ET entreprise) puis registre (canal `releve`) ;
     * rebond dur : `rebond_dur` (adresse). D'abord les arrêts d'inscriptions, ensuite le registre : l'arrêt ne dépend pas des effets aval.
     */
    public function recevoir(string $workspaceId, RetourDetecte $retour): void
    {
        if ($retour->classe === ClasseRetour::AutoReponse) {
            $maintenant = DateTimeImmutable::createFromInterface(now());
            WorkspaceContext::run($workspaceId, fn () => (new ReportAbsence)->reporter(
                $workspaceId,
                $this->rattachees->pour($workspaceId, $retour->idInjection),
                $maintenant,
            ));

            return;
        }
        $evenement = match ($retour->classe) {
            ClasseRetour::ReponseHumaine => Evenement::REPONSE,
            ClasseRetour::Desinscription, ClasseRetour::Hostile => Evenement::OPPOSITION,
            ClasseRetour::RebondDur => Evenement::REBOND_DUR,
            default => null,
        };
        if ($evenement === null) {
            return;
        }
        $maintenant = DateTimeImmutable::createFromInterface(now());
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $retour, $maintenant, $evenement): void {
            $ids = $this->rattachees->pour($workspaceId, $retour->idInjection);
            if ($ids === [] && $evenement === Evenement::REBOND_DUR) {
                $id = DB::table('envoi_inscriptions')->where('workspace_id', $workspaceId)->where('statut', 'active')
                    ->where('empreinte_adresse', ListeSuppression::empreinte($retour->adresse))->orderBy('id')->value('id');
                $ids = $id === null ? [] : [(string) $id];
            }
            foreach ($ids as $id) {
                $this->inscriptions->evenement($workspaceId, $id, $evenement, $maintenant);
            }
            if ($evenement === Evenement::OPPOSITION) {
                $opposer = $this->opposer ?? new OpposerAdresse(app(EnregistrerOpposition::class));
                $empreintes = [ListeSuppression::empreinte($retour->adresse)];
                if ($retour->adresseExpediteur !== null && $retour->adresseExpediteur !== '') {
                    $empreintes[] = ListeSuppression::empreinte($retour->adresseExpediteur);
                }
                foreach (array_unique($empreintes) as $empreinte) {
                    $opposer->executer($workspaceId, $empreinte, EnregistrerOpposition::CANAL_RELEVE);
                }
            }
        });
    }
}
