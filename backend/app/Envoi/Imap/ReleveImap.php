<?php

namespace App\Envoi\Imap;

use App\Socle\Alertes\Alerte;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Relève des réponses et rebonds d'une boîte d'envoi.
 *
 *  - IDEMPOTENTE : un événement est identifié par (boîte, UIDVALIDITY, UID) ;
 *    relire deux fois ne crée rien. Si UIDVALIDITY change, on relit TOUTE la
 *    boîte depuis l'UID 0 ; l'empreinte du Message-ID (unique par boîte)
 *    écarte ce qui était déjà enregistré : ni doublon, ni perte.
 *  - RATTACHEMENT d'un rapport d'abus ARF : jeton VERP signé SEUL (jamais le Message-ID cité).
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
 *    continue et le curseur avance.
 *  - ERREUR PROPRE À UN MESSAGE (code non nul de `crm:campagne:retours`, exception base…) : elle ne bloque
 *    PAS la boîte. Elle est journalisée (classe technique seulement) et comptée dans `envoi_echecs_releve` ;
 *    les messages suivants sont traités ; le curseur reste AVANT le premier échec (relu au passage suivant) ;
 *    `ReleveIncomplete` est levée en fin de passage. Après MAX_TENTATIVES échecs, le message est mis à
 *    l'écart : « à rattacher » SANS effet (revue humaine) et le curseur peut passer.
 *  - Une PANNE du lecteur (réseau, IMAP) est une exception technique : rien n'est noté, le curseur n'avance pas.
 *  - Par passage : au plus LIMITE_LOT UID bruts (le reste au passage suivant ; `lotPlein` suit les UID bruts).
 *  - `RetourDetecte::cle` = empreinte du message (stable) : les freins de L7 comptent une seule fois.
 *  - La file « à rattacher » exige un circuit de REVUE HUMAINE avant mise en
 *    service (lot L8) : une opposition qui y dort n'est PAS appliquée.
 *  - La relève ne modifie jamais la boîte et n'efface rien.
 */
final class ReleveImap
{
    public const FENETRE_RATTRAPAGE_JOURS = 30;

    public const LIMITE_LOT = 200;

    /** Échecs propres à un même message avant sa mise à l'écart (revue humaine). */
    public const MAX_TENTATIVES = 3;

    private const CLASSES_OPPOSITION = [ClasseRetour::Desinscription, ClasseRetour::Hostile];

    private const CLASSES_REBOND = [ClasseRetour::RebondDur, ClasseRetour::RebondMou];

    public function __construct(
        private readonly LecteurBoite $lecteur,
        private readonly RegistreMessagesEnvoyes $registre,
        private readonly RecepteurRetours $recepteur,
        private readonly ?Alerte $alerte = null,
    ) {}

    /** Relève courante : uniquement ce qui suit le curseur. */
    public function relever(string $workspaceId, string $boite): BilanReleve
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $boite): BilanReleve {
            $this->domainesEnCache = []; // un seul SELECT par relève et par espace
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
            $bilan->lotPlein = $lot->suite;
            $premierEchec = $this->traiter($workspaceId, $boite, $validite, $lot->messages, $bilan, false);
            // Le curseur ne dépasse jamais le premier message en échec : il sera relu.
            $this->poserCurseur($workspaceId, $boite, $validite, $premierEchec !== null ? max($uidApres, $premierEchec - 1) : $lot->dernierUid);
            if ($premierEchec !== null) {
                throw new ReleveIncomplete($bilan);
            }

            return $bilan;
        });
    }

    /**
     * Rattrapage après panne : on IGNORE le curseur (peut-être plus récent que
     * la base restaurée) et on rejoue la fenêtre de 30 jours qui précède
     * l'heure de la sauvegarde restaurée, jusqu'à maintenant. Les oppositions
     * déjà rattachées sont ré-appliquées (idempotent) même si l'événement
     * existe : un arrêt ne doit jamais manquer. On PAGINE SUR LES UID BRUTS
     * jusqu'à la fin réelle de la boîte : un lot entièrement filtré par la date
     * n'arrête pas la boucle.
     */
    public function rattraper(string $workspaceId, string $boite, DateTimeImmutable $sauvegardeLe): BilanReleve
    {
        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $boite, $sauvegardeLe): BilanReleve {
            $validite = $this->lecteur->uidValidity($boite);
            $depuis = $sauvegardeLe->modify('-' . self::FENETRE_RATTRAPAGE_JOURS . ' days');
            $bilan = new BilanReleve;
            $apres = 0;
            $echec = false;
            do {
                $lot = $this->lecteur->lire($boite, $apres, $depuis, self::LIMITE_LOT);
                $echec = $this->traiter($workspaceId, $boite, $validite, $lot->messages, $bilan, true) !== null || $echec;
                $suite = $lot->suite && $lot->dernierUid > $apres; // garde-fou : la boucle avance toujours
                $apres = max($apres, $lot->dernierUid);
            } while ($suite);
            $this->poserCurseur($workspaceId, $boite, $validite, 0);
            if ($echec) {
                throw new ReleveIncomplete($bilan);
            }

            return $bilan;
        });
    }

    /**
     * @param  list<MessageBrut>  $messages
     * @return ?int UID du premier message en échec de ce lot (null si aucun)
     */
    private function traiter(string $workspaceId, string $boite, int $validite, array $messages, BilanReleve $bilan, bool $rattrapage): ?int
    {
        $premierEchec = null;
        foreach ($messages as $m) {
            $bilan->lus++;
            try {
                try {
                    $r = DB::transaction(fn (): array => $this->appliquer($workspaceId, $boite, $validite, $m, $rattrapage));
                } catch (RetourInapplicable) {
                    // Isolé PAR MESSAGE : sans effet, à rattacher (revue humaine), la boîte continue.
                    $r = DB::transaction(fn (): array => $this->noterSansEffet($workspaceId, $boite, $validite, $m));
                }
            } catch (Exception $e) {
                // Erreur PROPRE à ce message (le lecteur, lui, a déjà tout livré) : isoler, compter, passer au suivant.
                $empreinte = self::empreinteMessage($m);
                DB::table('envoi_echecs_releve')->insert([
                    'workspace_id' => $workspaceId, 'boite' => $boite, 'empreinte_message' => $empreinte,
                    'motif' => $e::class, 'cree_le' => now(),
                ]);
                $tentatives = DB::table('envoi_echecs_releve')->where('workspace_id', $workspaceId)->where('boite', $boite)->where('empreinte_message', $empreinte)->count();
                Log::warning('Relève IMAP : message en échec', ['boite' => $boite, 'uid' => $m->uid, 'tentative' => $tentatives, 'erreur' => $e::class]);
                if ($tentatives < self::MAX_TENTATIVES) {
                    $bilan->echecs++;
                    $premierEchec ??= $m->uid;

                    continue;
                }
                // Mis à l'écart : « à rattacher », SANS effet, pour revue humaine ; le curseur peut passer.
                Log::error('Relève IMAP : message mis à l\'écart après échecs répétés', ['boite' => $boite, 'uid' => $m->uid, 'erreur' => $e::class]);
                $r = DB::transaction(fn (): array => $this->noterSansEffet($workspaceId, $boite, $validite, $m));
                $this->alerterSiOppositionPerdue($m, $boite);
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

        return $premierEchec;
    }

    /**
     * Un STOP / une plainte que le CRM n'a pas pu appliquer après MAX_TENTATIVES dort dans la file « à rattacher » :
     * il faut qu'un humain le sache (jamais d'adresse ni de contenu dans l'alerte).
     */
    private function alerterSiOppositionPerdue(MessageBrut $m, string $boite): void
    {
        if (! in_array(Classificateur::classer($m), self::CLASSES_OPPOSITION, true)) {
            return;
        }
        try {
            ($this->alerte ?? app(Alerte::class))->envoyer(
                'Relève IMAP : un STOP n\'a pas pu être appliqué',
                'Une opposition (STOP ou plainte) a échoué ' . self::MAX_TENTATIVES . ' fois côté CRM : elle est dans la file « à rattacher », SANS effet. À traiter à la main avant tout nouvel envoi.',
                'envoi',
                'critique',
                null,
                ['boite' => $boite, 'uid' => $m->uid],
            );
        } catch (\Throwable) {
            // une alerte ne casse jamais la relève
        }
    }

    /** @return array{insere: bool, classe: string, rattache: bool, enErreur: bool} */
    private function appliquer(string $workspaceId, string $boite, int $validite, MessageBrut $m, bool $rattrapage): array
    {
        if ($m->ecarte !== null) {
            return $this->noterSansEffet($workspaceId, $boite, $validite, $m);
        }

        $classe = Classificateur::classer($m, $this->domainesDEnvoi($workspaceId));
        // RÈGLE DE FER : un rapport d'abus ARF ne se rattache QUE par un jeton VERP signé. Le Message-ID qu'il cite
        // peut être forgé ou recopié : sans VERP signé, « à rattacher », JAMAIS d'opposition automatique.
        // Les autres retours : VERP d'abord (preuve la plus forte), puis le Message-ID cité ou répondu.
        $origine = $this->parVerp($workspaceId, $m);
        if ($m->typeArf !== null && ! AnalyseArf::typeConnu($m->typeArf)) {
            $origine = null; // Feedback-Type illisible ou INCONNU : « à rattacher », jamais d'effet deviné
        }
        if ($origine === null && $m->typeArf === null) {
            $origine = $this->registre->retrouver($workspaceId, self::identifiantsCandidats($m));
        }
        $expediteur = self::adresseDe($m->expediteur); // null si multiple, accentuée ou mal formée

        if ($origine !== null && in_array($classe, self::CLASSES_REBOND, true)) {
            // Un rebond dont le Final-Recipient n'est pas l'adresse à laquelle on a écrit (alias, redirection),
            // ou SANS Final-Recipient (un rebond dur ne prouve alors rien sur cette adresse), est douteux : revue humaine.
            $destinataire = $m->destinataireDsn === null ? null : mb_strtolower(trim($m->destinataireDsn));
            if ($destinataire === null ? $classe === ClasseRetour::RebondDur : $destinataire !== mb_strtolower(trim($origine->adresse))) {
                $origine = null;
            }
        }

        if ($origine !== null && $m->typeArf !== null && $m->destinataireArf !== null
            && mb_strtolower(trim($m->destinataireArf)) !== mb_strtolower(trim($origine->adresse))) {
            $origine = null; // rapport d'abus sur une autre adresse que celle écrite : revue humaine
        }

        $insere = $this->inserer($workspaceId, $boite, $validite, $m, $classe, $origine, $expediteur ?? $m->expediteur);

        // Effet : seulement si rattaché. Nouveau, ou opposition rejouée en rattrapage.
        $opposition = in_array($classe, self::CLASSES_OPPOSITION, true);
        $rejouer = ! $insere && $rattrapage && $opposition;
        // Un ARF « not-spam » / « auth-failure » est mémorisé mais NON transmis : ce n'est ni une plainte ni une auto-réponse.
        $sansEffetArf = $m->typeArf !== null && ! in_array($m->typeArf, AnalyseArf::TYPES_PLAINTE, true);
        if ($origine !== null && ! $sansEffetArf && ($insere || $rejouer)) {
            $this->recepteur->recevoir($workspaceId, new RetourDetecte(
                $classe,
                $origine->idInjection,
                $origine->adresse,
                $opposition && $m->typeArf === null && $expediteur !== null && mb_strtolower($expediteur) !== mb_strtolower(trim($origine->adresse)) ? $expediteur : null,
                self::empreinteMessage($m), // clé STABLE (ni UID, ni UIDVALIDITY) : un retour n'est compté qu'une fois
            ));
        }

        return ['insere' => $insere, 'classe' => $classe->value, 'rattache' => $origine !== null, 'enErreur' => false];
    }

    /** @var array<string, list<string>> */
    private array $domainesEnCache = [];

    /** @return list<string> */
    private function domainesDEnvoi(string $workspaceId): array
    {
        return $this->domainesEnCache[$workspaceId] ??= array_values(DB::table('envoi_domaines')->where('workspace_id', $workspaceId)->pluck('nom')->map(fn ($n): string => (string) $n)->all());
    }

    /**
     * Événement « à rattacher » SANS aucun effet (message écarté, illisible ou arrêt inapplicable).
     *
     * @return array{insere: bool, classe: string, rattache: bool, enErreur: bool}
     */
    private function noterSansEffet(string $workspaceId, string $boite, int $validite, MessageBrut $m): array
    {
        $classe = $m->ecarte !== null ? ClasseRetour::ReponseHumaine : Classificateur::classer($m, $this->domainesDEnvoi($workspaceId));
        $insere = $this->inserer($workspaceId, $boite, $validite, $m, $classe, null, self::adresseDe($m->expediteur) ?? $m->expediteur);

        return ['insere' => $insere, 'classe' => $classe->value, 'rattache' => false, 'enErreur' => true];
    }

    private function inserer(string $workspaceId, string $boite, int $validite, MessageBrut $m, ClasseRetour $classe, ?MessageOrigine $origine, string $expediteur): bool
    {
        return DB::table('envoi_evenements')->insertOrIgnore([
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

    /** Origine d'après un VERP `rb-<jeton>@bounce.<domaine>` signé, parmi les adresses du message. */
    private function parVerp(string $workspaceId, MessageBrut $m): ?MessageOrigine
    {
        foreach ($m->adressesLocales as $adresse) {
            $id = Verp::idInjectionDepuis($adresse);
            $origine = $id === null ? null : $this->registre->retrouverParInjection($workspaceId, $id);
            if ($origine !== null) {
                return $origine;
            }
        }

        return null;
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

    /**
     * Empreinte STABLE d'un message, indépendante des UID et d'UIDVALIDITY :
     *  1. son Message-ID ; 2. à défaut, le HASH DE SON CONTENU (message lu ou écarté) ;
     *  3. à défaut (lecteur sans contenu), expéditeur + INTERNALDATE + objet.
     */
    private static function empreinteMessage(MessageBrut $m): string
    {
        $id = trim((string) $m->messageId, " \t\r\n<>");
        if ($id !== '') {
            return hash('sha256', 'mid:' . $id);
        }
        if ($m->empreinteContenu !== null) {
            return hash('sha256', 'contenu:' . $m->empreinteContenu);
        }

        return hash('sha256', 'syn:' . mb_strtolower(trim($m->expediteur)) . '|' . $m->recuLe->format('c') . '|' . trim($m->sujet));
    }

    /**
     * Adresse de l'expéditeur, ou null si le champ est multiple, mal formé ou
     * non ASCII (non traitable sans risque) : jamais d'exception ici.
     */
    private static function adresseDe(string $expediteur): ?string
    {
        $expediteur = trim($expediteur);
        try {
            $chevrons = Pcre::tousLesGroupes1('/<([^<>]*)>/', $expediteur);
            if (count($chevrons) > 1) {
                return null;
            }
            if (count($chevrons) === 1) {
                $candidate = trim($chevrons[0]);
            } elseif (Pcre::correspond('/[\s,;]/', $expediteur)) {
                return null;
            } else {
                $candidate = $expediteur;
            }
        } catch (EchecAnalyse) {
            return null; // non traitable sans risque : jamais d'effet deviné
        }

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false ? $candidate : null;
    }
}
