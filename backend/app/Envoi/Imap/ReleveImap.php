<?php

namespace App\Envoi\Imap;

use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Relève des réponses et rebonds d'une boîte d'envoi.
 *
 *  - IDEMPOTENTE : un événement est identifié par (boîte, UIDVALIDITY, UID) ;
 *    relire deux fois ne crée rien. Si UIDVALIDITY change, on relit TOUTE la
 *    boîte depuis l'UID 0 ; l'empreinte du Message-ID (unique par boîte)
 *    écarte ce qui était déjà enregistré : ni doublon, ni perte.
 *  - RATTACHEMENT par In-Reply-To / References / Message-ID cité par le DSN,
 *    via le registre des messages envoyés. Non rattaché = « à rattacher »
 *    (revue humaine) : AUCUN effet, jamais d'opposition automatique.
 *  - Seuls l'empreinte, la classe et des identifiants techniques sont écrits :
 *    ni corps, ni adresse en clair. Aucune mesure d'ouverture.
 *  - Chaque message est traité dans UNE transaction : l'événement et son effet
 *    (arrêt) réussissent ensemble ou pas du tout ; en cas d'échec de l'arrêt,
 *    l'événement n'est pas noté et sera relu.
 *  - La relève ne modifie jamais la boîte et n'efface rien.
 */
final class ReleveImap
{
    public const FENETRE_RATTRAPAGE_JOURS = 30;

    private const CLASSES_OPPOSITION = [ClasseRetour::Desinscription, ClasseRetour::Hostile];

    public function __construct(
        private readonly LecteurBoite $lecteur,
        private readonly RegistreMessagesEnvoyes $registre,
        private readonly RecepteurRetours $recepteur,
    ) {}

    /** Relève courante : uniquement ce qui suit le curseur. */
    public function relever(string $workspaceId, string $boite): BilanReleve
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $boite): BilanReleve {
            $validite = $this->lecteur->uidValidity($boite);
            $curseur = DB::table('envoi_curseurs_imap')->where('workspace_id', $workspaceId)->where('boite', $boite)->first();
            $bilan = new BilanReleve;
            $uidApres = 0;
            if ($curseur !== null && (int) $curseur->uid_validity === $validite) {
                $uidApres = (int) $curseur->dernier_uid;
            } elseif ($curseur !== null) {
                $bilan->uidValiditeChangee = true;
            }

            $this->traiter($workspaceId, $boite, $validite, $this->lecteur->lire($boite, $uidApres), $bilan, false);
            $this->poserCurseur($workspaceId, $boite, $validite, 0);

            return $bilan;
        });
    }

    /**
     * Rattrapage après panne : on IGNORE le curseur (peut-être plus récent que
     * la base restaurée) et on rejoue la fenêtre de 30 jours qui précède
     * l'heure de la sauvegarde restaurée, jusqu'à maintenant. Les oppositions
     * déjà rattachées sont ré-appliquées (idempotent) même si l'événement
     * existe : un arrêt ne doit jamais manquer.
     */
    public function rattraper(string $workspaceId, string $boite, DateTimeImmutable $sauvegardeLe): BilanReleve
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $boite, $sauvegardeLe): BilanReleve {
            $validite = $this->lecteur->uidValidity($boite);
            $depuis = $sauvegardeLe->modify('-' . self::FENETRE_RATTRAPAGE_JOURS . ' days');
            $bilan = new BilanReleve;
            $this->traiter($workspaceId, $boite, $validite, $this->lecteur->lire($boite, 0, $depuis), $bilan, true);
            $this->poserCurseur($workspaceId, $boite, $validite, 0);

            return $bilan;
        });
    }

    /** @param list<MessageBrut> $messages */
    private function traiter(string $workspaceId, string $boite, int $validite, array $messages, BilanReleve $bilan, bool $rattrapage): void
    {
        foreach ($messages as $m) {
            DB::transaction(function () use ($workspaceId, $boite, $validite, $m, $bilan, $rattrapage): void {
                $bilan->lus++;
                $classe = Classificateur::classer($m);
                $origine = $this->registre->retrouver($workspaceId, self::identifiantsCandidats($m));
                $expediteur = self::adresseDe($m->expediteur);

                $insere = DB::table('envoi_evenements')->insertOrIgnore([
                    'workspace_id' => $workspaceId,
                    'boite' => $boite,
                    'uid_validity' => $validite,
                    'uid' => $m->uid,
                    'empreinte_message' => self::empreinteMessage($m),
                    'classe' => $classe->value,
                    'statut' => $origine !== null ? 'rattache' : 'a_rattacher',
                    'id_injection' => $origine?->idInjection,
                    'empreinte_expediteur' => ListeSuppression::empreinte($expediteur),
                    'empreinte_destinataire' => $origine !== null ? ListeSuppression::empreinte($origine->adresse) : null,
                    'code_dsn' => $m->statutDsn,
                    'recu_le' => $m->recuLe->format('c'),
                    'cree_le' => now(),
                ]) > 0;

                if (! $insere) {
                    $bilan->doublons++;
                }
                if ($insere) {
                    $bilan->nouveaux++;
                    $bilan->parClasse[$classe->value] = ($bilan->parClasse[$classe->value] ?? 0) + 1;
                    $origine !== null ? $bilan->rattaches++ : $bilan->aRattacher++;
                }

                // Effet : seulement si rattaché. Nouveau, ou opposition rejouée en rattrapage.
                $rejouer = ! $insere && $rattrapage && in_array($classe, self::CLASSES_OPPOSITION, true);
                if ($origine !== null && ($insere || $rejouer)) {
                    $this->recepteur->recevoir($workspaceId, new RetourDetecte(
                        $classe,
                        $origine->idInjection,
                        $origine->adresse,
                        in_array($classe, self::CLASSES_OPPOSITION, true) && mb_strtolower($expediteur) !== mb_strtolower(trim($origine->adresse)) ? $expediteur : null,
                        $boite . ':' . $validite . ':' . $m->uid,
                    ));
                }

                $this->poserCurseur($workspaceId, $boite, $validite, $m->uid);
            });
        }
    }

    /** Le curseur ne recule jamais pour une même UIDVALIDITY ; il repart de $uid si elle change. */
    private function poserCurseur(string $workspaceId, string $boite, int $validite, int $uid): void
    {
        $existant = DB::table('envoi_curseurs_imap')->where('workspace_id', $workspaceId)->where('boite', $boite)->first();
        if ($existant === null) {
            DB::table('envoi_curseurs_imap')->insert([
                'workspace_id' => $workspaceId, 'boite' => $boite, 'uid_validity' => $validite,
                'dernier_uid' => $uid, 'maj_le' => now(),
            ]);

            return;
        }
        $nouveau = (int) $existant->uid_validity === $validite ? max((int) $existant->dernier_uid, $uid) : $uid;
        DB::table('envoi_curseurs_imap')->where('id', $existant->id)->update([
            'uid_validity' => $validite, 'dernier_uid' => $nouveau, 'maj_le' => now(),
        ]);
    }

    /** @return list<string> */
    private static function identifiantsCandidats(MessageBrut $m): array
    {
        $ids = [$m->inReplyTo, ...array_reverse($m->references), $m->messageIdDsn];
        $propres = [];
        foreach ($ids as $id) {
            $id = trim((string) $id, " \t\r\n<>");
            if ($id !== '') {
                $propres[$id] = true;
            }
        }

        return array_map('strval', array_keys($propres));
    }

    private static function empreinteMessage(MessageBrut $m): string
    {
        $id = trim((string) $m->messageId, " \t\r\n<>");

        // Sans Message-ID : empreinte synthétique stable (jamais le corps).
        return hash('sha256', $id !== '' ? 'mid:' . $id : 'syn:' . self::adresseDe($m->expediteur) . '|' . $m->recuLe->format('c') . '|' . $m->sujet);
    }

    private static function adresseDe(string $expediteur): string
    {
        if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $expediteur, $r) === 1) {
            return trim($r[1]);
        }

        return trim($expediteur);
    }
}
