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
 *  - ISOLEMENT PAR MESSAGE : un message piégé (From multiple, accentué, mal
 *    formé, trop gros, illisible) ou dont l'arrêt est inapplicable
 *    (`RetourInapplicable`) devient « à rattacher » SANS effet ; la boîte
 *    continue et le curseur avance. Seule une vraie indisponibilité (toute
 *    autre exception : CRM, base) interrompt la relève, sans noter l'événement.
 *  - Par passage : au plus LIMITE_LOT messages (le reste au passage suivant).
 *  - La file « à rattacher » exige un circuit de REVUE HUMAINE avant mise en
 *    service (lot L8) : une opposition qui y dort n'est PAS appliquée.
 *  - La relève ne modifie jamais la boîte et n'efface rien.
 */
final class ReleveImap
{
    public const FENETRE_RATTRAPAGE_JOURS = 30;

    public const LIMITE_LOT = 200;

    private const CLASSES_OPPOSITION = [ClasseRetour::Desinscription, ClasseRetour::Hostile];

    private const CLASSES_REBOND = [ClasseRetour::RebondDur, ClasseRetour::RebondMou];

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

            $lot = $this->lecteur->lire($boite, $uidApres, null, self::LIMITE_LOT);
            $bilan->lotPlein = count($lot) >= self::LIMITE_LOT;
            $this->traiter($workspaceId, $boite, $validite, $lot, $bilan, false);
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
            $apres = 0;
            do {
                $lot = $this->lecteur->lire($boite, $apres, $depuis, self::LIMITE_LOT);
                $this->traiter($workspaceId, $boite, $validite, $lot, $bilan, true);
                foreach ($lot as $m) {
                    $apres = max($apres, $m->uid);
                }
            } while (count($lot) >= self::LIMITE_LOT);
            $this->poserCurseur($workspaceId, $boite, $validite, 0);

            return $bilan;
        });
    }

    /** @param list<MessageBrut> $messages */
    private function traiter(string $workspaceId, string $boite, int $validite, array $messages, BilanReleve $bilan, bool $rattrapage): void
    {
        foreach ($messages as $m) {
            $bilan->lus++;
            try {
                $r = DB::transaction(fn (): array => $this->appliquer($workspaceId, $boite, $validite, $m, $rattrapage));
            } catch (RetourInapplicable) {
                // Isolé PAR MESSAGE : sans effet, à rattacher (revue humaine), la boîte continue.
                $r = DB::transaction(fn (): array => $this->noterSansEffet($workspaceId, $boite, $validite, $m));
            }
            if ($r['enErreur']) {
                $bilan->enErreur++;
            }
            if ($r['insere']) {
                $bilan->nouveaux++;
                $bilan->parClasse[$r['classe']] = ($bilan->parClasse[$r['classe']] ?? 0) + 1;
                $r['rattache'] ? $bilan->rattaches++ : $bilan->aRattacher++;
            } else {
                $bilan->doublons++;
            }
        }
    }

    /** @return array{insere: bool, classe: string, rattache: bool, enErreur: bool} */
    private function appliquer(string $workspaceId, string $boite, int $validite, MessageBrut $m, bool $rattrapage): array
    {
        if ($m->ecarte !== null) {
            return $this->noterSansEffet($workspaceId, $boite, $validite, $m);
        }

        $classe = Classificateur::classer($m);
        $origine = $this->registre->retrouver($workspaceId, self::identifiantsCandidats($m));
        $expediteur = self::adresseDe($m->expediteur); // null si multiple, accentuée ou mal formée

        // Un rebond dont le Final-Recipient n'est pas l'adresse à laquelle on a écrit
        // (alias, redirection) ne prouve rien sur cette adresse : revue humaine.
        if ($origine !== null && $m->destinataireDsn !== null && in_array($classe, self::CLASSES_REBOND, true)
            && mb_strtolower(trim($m->destinataireDsn)) !== mb_strtolower(trim($origine->adresse))) {
            $origine = null;
        }

        $insere = $this->inserer($workspaceId, $boite, $validite, $m, $classe, $origine, $expediteur ?? $m->expediteur);

        // Effet : seulement si rattaché. Nouveau, ou opposition rejouée en rattrapage.
        $opposition = in_array($classe, self::CLASSES_OPPOSITION, true);
        $rejouer = ! $insere && $rattrapage && $opposition;
        if ($origine !== null && ($insere || $rejouer)) {
            $this->recepteur->recevoir($workspaceId, new RetourDetecte(
                $classe,
                $origine->idInjection,
                $origine->adresse,
                $opposition && $expediteur !== null && mb_strtolower($expediteur) !== mb_strtolower(trim($origine->adresse)) ? $expediteur : null,
                $boite . ':' . $validite . ':' . $m->uid,
            ));
        }

        $this->poserCurseur($workspaceId, $boite, $validite, $m->uid);

        return ['insere' => $insere, 'classe' => $classe->value, 'rattache' => $origine !== null, 'enErreur' => false];
    }

    /**
     * Événement « à rattacher » SANS aucun effet (message écarté, illisible ou arrêt inapplicable).
     *
     * @return array{insere: bool, classe: string, rattache: bool, enErreur: bool}
     */
    private function noterSansEffet(string $workspaceId, string $boite, int $validite, MessageBrut $m): array
    {
        $classe = $m->ecarte !== null ? ClasseRetour::ReponseHumaine : Classificateur::classer($m);
        $insere = $this->inserer($workspaceId, $boite, $validite, $m, $classe, null, self::adresseDe($m->expediteur) ?? $m->expediteur);
        $this->poserCurseur($workspaceId, $boite, $validite, $m->uid);

        return ['insere' => $insere, 'classe' => $classe->value, 'rattache' => false, 'enErreur' => true];
    }

    private function inserer(string $workspaceId, string $boite, int $validite, MessageBrut $m, ClasseRetour $classe, ?MessageOrigine $origine, string $expediteur): bool
    {
        return DB::table('envoi_evenements')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'boite' => $boite,
            'uid_validity' => $validite,
            'uid' => $m->uid,
            'empreinte_message' => self::empreinteMessage($m, $validite),
            'classe' => $classe->value,
            'statut' => $origine !== null ? 'rattache' : 'a_rattacher',
            'id_injection' => $origine?->idInjection,
            'empreinte_expediteur' => ListeSuppression::empreinte($expediteur),
            'empreinte_destinataire' => $origine !== null ? ListeSuppression::empreinte($origine->adresse) : null,
            'code_dsn' => $m->statutDsn,
            'recu_le' => $m->recuLe->format('c'),
            'cree_le' => now(),
        ]) > 0;
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

    private static function empreinteMessage(MessageBrut $m, int $validite): string
    {
        $id = trim((string) $m->messageId, " \t\r\n<>");
        if ($id !== '') {
            return hash('sha256', 'mid:' . $id);
        }
        // Message écarté sans Message-ID : pas d'autre repère que sa position.
        if ($m->ecarte !== null) {
            return hash('sha256', 'ecarte:' . $validite . ':' . $m->uid);
        }

        // Sans Message-ID : empreinte synthétique STABLE (jamais le corps), fondée sur
        // l'expéditeur, la date INTERNALDATE (fixée par le serveur, pas l'en-tête Date
        // que l'expéditeur choisit) et l'objet : elle survit à une renumérotation.
        return hash('sha256', 'syn:' . mb_strtolower(trim($m->expediteur)) . '|' . $m->recuLe->format('c') . '|' . trim($m->sujet));
    }

    /**
     * Adresse de l'expéditeur, ou null si le champ est multiple, mal formé ou
     * non ASCII (non traitable sans risque) : jamais d'exception ici.
     */
    private static function adresseDe(string $expediteur): ?string
    {
        $expediteur = trim($expediteur);
        $n = preg_match_all('/<([^<>]*)>/', $expediteur, $r);
        if ($n > 1) {
            return null;
        }
        if ($n === 1) {
            $candidate = trim($r[1][0]);
        } elseif (preg_match('/[\s,;]/', $expediteur) === 1) {
            return null;
        } else {
            $candidate = $expediteur;
        }

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false ? $candidate : null;
    }
}
