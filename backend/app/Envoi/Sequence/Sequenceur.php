<?php

namespace App\Envoi\Sequence;

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Emails\QualificationEmail;
use App\Envoi\Campagnes\RepliGenerique;
use App\Envoi\Contrat\ConsultationAcceptation;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\NatureReport;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\InjectionIncertaine;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Envoi\Message\LecteurVariables;
use App\Envoi\Message\LecteurVariablesDb;
use App\Envoi\Message\MessageRefuse;
use App\Envoi\Message\ValeursVariables;
use App\Envoi\Message\VariablesMessage;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Smtp\EnvoiSmtpRefuse;
use App\Support\ListeSuppression;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Séquenceur (envoi P0, lot L1). Pour une inscription échue :
 *   1. verrou de ligne (SELECT … FOR UPDATE) : une seule exécution à la fois par inscription ;
 *   2. arrêt par ENTREPRISE (une autre personne a répondu / s'est opposée) et par ADRESSE
 *      (STOP, opposition ou rebond dur sur une autre inscription de la même adresse) ;
 *   3. fenêtre horaire Europe/Paris, jours ouvrés français : sinon l'échéance est reportée ;
 *   4. réservation (inscription, étape) : unicité en base (ON CONFLICT DO NOTHING) ;
 *   5. fiche relue EN BASE, puis `EnvoiAutorise::verifier` ;
 *   6. composition (relance dans le MÊME fil : In-Reply-To / References) ;
 *   7. `$serveur` (la PasserelleLocale), hors transaction ; puis conclusion.
 * DETTES I2 ET I3 SOLDÉES (L8-F14a, « réserver, injecter, conclure ») : `traiter` se découpe en trois temps. A : transaction
 * courte (réservation d'étape et portes) validée par un COMMIT ; l'appel à la passerelle a lieu HORS de toute
 * transaction (donc réservation déjà validée avant l'appel : un échec de COMMIT ne défait plus un message parti — I2 —
 * et ni le verrou de ligne ni le verrou par jour de la passerelle ne sont tenus pendant le réseau — I3) ; C : transaction
 * courte qui conclut (l'étape n'avance qu'une fois, la bascule est idempotente). Avec Kumo ou l'adaptateur SMTP, la garde
 * « aucune transaction englobante » est ainsi respectée. Une réservation restée « reserve » après une issue incertaine
 * ne repart JAMAIS toute seule (perdre un message plutôt que l'envoyer deux fois).
 * La version du message est celle FIGÉE à l'inscription. Aucune mesure d'ouverture.
 * Aucun effacement : un message refusé ou reporté reste en base avec son motif.
 */
final class Sequenceur
{
    public const ENVOYE = 'envoye';

    public const ARRETEE = 'arretee';

    public const DEJA = 'deja_traite';

    public const HORS_FENETRE = 'hors_fenetre';

    public const REFUSE = 'refuse';

    public const REPORTE = 'reporte';

    public const IGNORE = 'ignore';

    /** Exception technique rattrapée pour UNE inscription (voir `echec()`) : les autres continuent. */
    public const ERREUR = 'erreur';

    /** Reports consécutifs (refus PASSAGERS de la porte) au-delà desquels l'inscription est arrêtée avec alerte. */
    public const REPORTS_MAX = 10;

    public const MOTIF_REPORTS_EPUISES = 'reports_epuises';

    /** B3-B : jours calendaires sans verdict avant l'alerte (aucun arrêt). */
    public const VERDICT_ABSENT_ALERTE_JOURS = 7;

    /**
     * Une ligne « reserve » sans issue depuis ce délai (horloge injectée) est REPRISE par le chemin de rejeu (même clé,
     * même Message-ID) : plantage entre la réservation et l'appel, ou entre l'appel et la conclusion. La passerelle dit
     * ce qui s'est passé : acceptée => conclure sans renvoyer ; jamais réservée => injecter ; incertaine => arrêt + alerte.
     */
    public const RESERVATION_REPRISE_HEURES = 1;

    /** Exception du serveur d'envoi après réservation : le message est peut-être parti, il ne repart jamais. */
    public const MOTIF_ISSUE_INCERTAINE = 'issue_incertaine';

    /** Nombre d'échecs techniques d'une même inscription avant l'alerte (puis tous les N échecs). */
    public const ECHECS_AVANT_ALERTE = 5;

    public function __construct(
        private readonly ServeurEnvoi $serveur,
        private readonly LecteurFiche $fiches,
        private readonly ComposeurSequence $composeur,
        private readonly ParametresExpedition $expedition,
        private readonly RegistreInjectionsSequence $registre = new RegistreInjectionsSequence,
        private ?LecteurVariables $variables = null,
        private ?RelationAvancee $relation = null,
        private readonly ?ChoixBoite $choixBoite = null,
    ) {}

    /** @var list<array{0: string, 1: array<string, mixed>}> alertes critiques en attente du COMMIT de la transaction en cours */
    private array $alertes = [];

    /**
     * Traite toutes les inscriptions échues de l'espace courant.
     *
     * @return array<string, int> compte par résultat
     */
    public function executer(string $ws, DateTimeImmutable $maintenant, int $limite = 100): array
    {
        // Une séquence EN PAUSE (dernier geste de `envoi_sequences_gestes`, D6) est sautée ici : ses inscriptions ne sont pas
        // traitées, donc leur compteur de reports ne bouge pas. Le geste est lu AVANT le traitement, puis RELU avant chaque inscription (une pause posée en plein cycle arrête les suivantes) : le stop gagne toujours.
        $ids = DB::table('envoi_inscriptions AS i')->where('i.workspace_id', $ws)->where('i.statut', 'active')
            ->where('i.prochaine_echeance', '<=', $maintenant->format('c'))
            ->whereRaw("COALESCE((SELECT g.geste FROM envoi_sequences_gestes g WHERE g.workspace_id = i.workspace_id AND g.sequence_id = i.sequence_id ORDER BY g.cree_le DESC, g.id DESC LIMIT 1), 'reprise') <> 'pause'")
            ->orderBy('i.prochaine_echeance')->limit($limite)->pluck('i.id')->all();
        $bilan = [];
        foreach ($ids as $id) {
            // Le filtre ci-dessus ne voit que l'état au début du cycle : une pause posée PENDANT le cycle (jusqu'à `$limite`
            // inscriptions) doit arrêter les suivantes. On relit donc le dernier geste de la séquence de l'inscription (même
            // sous-requête indexée) avant chaque traitement ; en pause, on saute sans toucher à `reports`.
            if ($this->sequenceEnPause($ws, (string) $id)) {
                continue;
            }
            try {
                $r = $this->traiter($ws, (string) $id, $maintenant);
            } catch (Throwable $e) {
                // Exception technique de `traiter` (temps A ou C défait, passerelle en échec AVANT sa réservation, refus
                // définitif repris ailleurs) : aucune issue incertaine ici (celle-ci arrête l'inscription sans exception).
                // La ligne « reserve » éventuelle est reprise plus tard par le rejeu. On note l'échec HORS de toute transaction.
                $r = $this->echec($ws, (string) $id, $e, $maintenant);
            }
            $bilan[$r] = ($bilan[$r] ?? 0) + 1;
        }

        return $bilan;
    }

    /** Dernier geste de la séquence de l'inscription (`envoi_sequences_gestes`, D6) : `pause` => séquence en pause. */
    private function sequenceEnPause(string $ws, string $inscriptionId): bool
    {
        return DB::table('envoi_inscriptions AS i')->where('i.workspace_id', $ws)->where('i.id', $inscriptionId)
            ->whereRaw("COALESCE((SELECT g.geste FROM envoi_sequences_gestes g WHERE g.workspace_id = i.workspace_id AND g.sequence_id = i.sequence_id ORDER BY g.cree_le DESC, g.id DESC LIMIT 1), 'reprise') = 'pause'")
            ->exists();
    }

    /**
     * « Réserver, injecter, conclure » en TROIS temps (dette I2/I3 soldée) :
     *   A. transaction courte : verrou de ligne, arrêts, fenêtre, réservation (inscription, étape), porte, composition. COMMIT ;
     *   B. l'appel à la passerelle, HORS de toute transaction (aucun verrou de ligne ni de jour tenu pendant le réseau) ;
     *   C. transaction courte : conclusion, idempotente (le message passe de « reserve » à « envoye » UNE fois, et l'étape
     *      n'avance que si cette bascule a eu lieu).
     * Issue de B : refus net ou report => `reporte` (plafonné) ; refus définitif (5xx) => refus et arrêt ; issue INCERTAINE
     * (`InjectionIncertaine`, ou résultat `incertain`) => inscription arrêtée sans report, une alerte, le message ne repart
     * JAMAIS ; toute autre exception n'a rien envoyé et remonte à `executer`. Une ligne « reserve » restée sans issue est
     * reprise par le rejeu après `RESERVATION_REPRISE_HEURES`. Option la plus prudente : perdre plutôt que doubler.
     */
    public function traiter(string $ws, string $inscriptionId, DateTimeImmutable $maintenant): string
    {
        try {
            $plan = DB::transaction(fn () => $this->reserverEtPreparer($ws, $inscriptionId, $maintenant));
        } catch (Throwable $e) {
            $this->alertes = []; // la transaction est défaite : aucune alerte sur un état qui n'existe plus
            throw $e;
        }
        $this->emettreAlertes(); // APRÈS le COMMIT de A, jamais dedans
        if (is_string($plan)) {
            return $plan;
        }

        try {
            $res = $this->serveur->injecter($plan['message']);
        } catch (EnvoiRefuse $e) {
            if ($e->getCode() === EnvoiRefuse::ISSUE_INCERTAINE) {
                // Issue d'une injection précédente incertaine : JAMAIS de report (il reboucherait chaque jour). Inscription arrêtée
                // en attente de vérification humaine, UNE alerte ; la ligne reste `a_verifier` côté passerelle.
                return $this->conclure($plan, fn (\stdClass $ins): string => $this->arreterIssueIncertaine($ins, null, $maintenant, ! $e->alerteDejaEmise), $maintenant);
            }
            if ($e->estOpposition()) {
                // Le STOP gagne : opposition validée entre la réservation et l'injection. Refus DÉFINITIF et arrêt (garde:opposition),
                // trace pour l'adresse et l'entreprise ; la place de la passerelle est déjà rendue.
                return $this->conclure($plan, fn (\stdClass $ins): string => $this->refuser($ins, $plan['rang'], EligibiliteAdresse::OPPOSITION, $maintenant), $maintenant);
            }
            if ($e->estAttente()) {
                // Report d'ATTENTE (envoi réel éteint, arrêt général, mode témoins) : ni compteur, ni alerte, ni limite.
                return $this->conclure($plan, fn (\stdClass $ins): string => $this->reporter($ins, $plan['rang'], $e->getMessage(), $plan['fenetre'], $maintenant), $maintenant);
            }

            return $this->conclure($plan, fn (\stdClass $ins): string => $this->reporterAvecPlafond($ins, $plan['rang'], $e->getMessage(), $plan['fenetre'], $maintenant), $maintenant);
        } catch (RefusDefinitifServeur) {
            // Refus définitif (5xx) : refus et arrêt, pas de report quotidien.
            return $this->conclure($plan, fn (\stdClass $ins): string => $this->refuser($ins, $plan['rang'], 'refus_definitif_du_serveur', $maintenant), $maintenant);
        } catch (EnvoiSmtpRefuse|ServeurEnvoiRefuse $e) {
            // Refus CERTAIN fait avant toute transmission (déjà libéré par la passerelle) : ni alerte « incertaine », ni arrêt ;
            // report soumis au plafond.
            return $this->conclure($plan, fn (\stdClass $ins): string => $this->reporterAvecPlafond($ins, $plan['rang'], 'refus_avant_envoi', $plan['fenetre'], $maintenant), $maintenant);
        } catch (InjectionIncertaine $e) {
            // La passerelle a gardé la place (`a_verifier`) : le message ne repart JAMAIS, l'inscription attend une vérification humaine.
            return $this->conclure($plan, fn (\stdClass $ins): string => $this->arreterIssueIncertaine($ins, $e->getPrevious() === null ? null : $e->getPrevious()::class, $maintenant), $maintenant);
        }
        // Toute autre exception (avant la réservation de la passerelle, erreur SQL passagère) n'a rien envoyé : elle remonte
        // à `executer` (compteur d'échecs, échéance repoussée) ; la ligne « reserve » est reprise plus tard par le rejeu.
        if ($res->incertain) {
            // Issue incertaine rapportée : JAMAIS de report, arrêt en attente de vérification humaine, une alerte.
            return $this->conclure($plan, fn (\stdClass $ins): string => $this->arreterIssueIncertaine($ins, null, $maintenant), $maintenant);
        }
        if (! $res->accepte) {
            if ($res->nature === NatureReport::Attente) {
                // Plafond du jour, quota de chauffe, pause, frein, boîte occupée : attente, l'inscription reste active sans compteur.
                return $this->conclure($plan, fn (\stdClass $ins): string => $this->reporter($ins, $plan['rang'], 'reporte_par_la_passerelle', $plan['fenetre'], $maintenant), $maintenant);
            }

            return $this->conclure($plan, fn (\stdClass $ins): string => $this->reporterAvecPlafond($ins, $plan['rang'], 'reporte_par_la_passerelle', $plan['fenetre'], $maintenant), $maintenant);
        }

        return $this->conclure($plan, function (\stdClass $ins) use ($plan, $res, $maintenant): string {
            $rang = $plan['rang'];
            // Idempotent : seule la bascule « reserve » -> « envoye » avance l'étape, une seule fois.
            $basculees = DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->where('statut', 'reserve')
                ->update(['statut' => 'envoye', 'refus' => null, ...$this->colonnesIdentifiants($plan['idRegistre'], $res->idInjection), 'maj_le' => $maintenant->format('c')]);
            if ($basculees !== 1) {
                return self::DEJA;
            }
            $this->enregistrerEnvoye($ins, $rang, $plan['messageId'], $plan['idRegistre'], $res->idInjection);
            if ($ins->statut === 'active' && (int) $ins->etape_courante === $rang) {
                $this->avancer($ins, $rang, $plan['version'], $plan['messageId'], $plan['references'], $plan['fenetre'], $maintenant);
            }

            return self::ENVOYE;
        }, $maintenant);
    }

    /**
     * Temps C : transaction courte, ligne d'inscription relue sous verrou, alertes émises après le COMMIT.
     *
     * @param  array{ins: \stdClass, rang: int, version: array<string, mixed>, messageId: string, references: list<string>, fenetre: FenetreHoraire, message: MessageAEnvoyer, idRegistre: ?string}  $plan
     */
    private function conclure(array $plan, \Closure $f, DateTimeImmutable $maintenant): string
    {
        try {
            $r = DB::transaction(function () use ($plan, $f): string {
                $ins = DB::table('envoi_inscriptions')->where('workspace_id', $plan['ins']->workspace_id)->where('id', $plan['ins']->id)->lockForUpdate()->first();

                return $ins === null ? self::IGNORE : $f($ins);
            });
        } catch (Throwable $e) {
            $this->alertes = [];
            throw $e;
        }
        $this->emettreAlertes();

        return $r;
    }

    private function emettreAlertes(): void
    {
        $alertes = $this->alertes;
        $this->alertes = [];
        foreach ($alertes as [$message, $contexte]) {
            Log::critical($message, $contexte);
        }
    }

    /**
     * Temps A (dans la transaction ouverte par `traiter`).
     *
     * @return string|array{ins: \stdClass, rang: int, version: array<string, mixed>, messageId: string, references: list<string>, fenetre: FenetreHoraire, message: MessageAEnvoyer, idRegistre: ?string}
     */
    private function reserverEtPreparer(string $ws, string $inscriptionId, DateTimeImmutable $maintenant): string|array
    {
        $ins = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $inscriptionId)->lockForUpdate()->first();
        if ($ins === null || $ins->statut !== 'active' || new DateTimeImmutable((string) $ins->prochaine_echeance) > $maintenant) {
            return self::IGNORE;
        }
        $version = json_decode((string) $ins->version_figee, true, 512, JSON_THROW_ON_ERROR);
        $rang = (int) $ins->etape_courante;
        $repli = self::estRepli($ins);
        $etape = $version['etapes'][$rang - 1] ?? null;
        // L5b : un repli sur l'adresse générale n'a JAMAIS de relance. Défense en plus du CHECK « une seule étape » de la base.
        if ($etape === null || ($repli && $rang > 1)) {
            return $this->arreter($ins, 'etape_inconnue', $maintenant);
        }

        if ($this->entrepriseArretee($ws, (int) $ins->entreprise_id, (string) $ins->id)) {
            return $this->arreter($ins, Evenement::MOTIF_ENTREPRISE, $maintenant);
        }

        // Rendez-vous pris ou entreprise devenue cliente : AVANT la fenêtre et la réservation (rien n'est réservé).
        $this->relation ??= new RelationAvancee;
        if (($m = $this->relation->motif($ws, (int) $ins->entreprise_id, (int) $ins->contact_id, new DateTimeImmutable((string) $ins->inscrite_le))) !== null) {
            return $this->arreter($ins, $m, $maintenant);
        }

        // R7 (repli) : AVANT la fenêtre et la réservation, rien n'est réservé. Arrêt définitif, jamais un report.
        if ($repli && $this->repliCaduc($ws, $ins)) {
            return $this->arreter($ins, 'repli_generique_caduc', $maintenant);
        }

        $fenetre = new FenetreHoraire((int) $version['fenetre']['debut'], (int) $version['fenetre']['fin']);
        if (! $fenetre->contient($maintenant)) {
            $this->echeance($ins, $fenetre->prochain($maintenant));

            return self::HORS_FENETRE;
        }

        // STOP, opposition, rebond dur : valent pour l'ADRESSE, pas pour une seule inscription.
        if ($this->adresseArretee($ws, (string) $ins->empreinte_adresse, (string) $ins->id)) {
            return $this->arreter($ins, Evenement::MOTIF_ADRESSE, $maintenant);
        }

        // Le fil : « References » = tout le fil dans l'ordre ; « In-Reply-To » = le message PRÉCÉDENT (pas le premier).
        $references = $ins->fil_references === null || trim((string) $ins->fil_references) === '' ? [] : explode(' ', trim((string) $ins->fil_references));
        $inReplyTo = $references === [] ? null : $references[array_key_last($references)];

        // X11-B : boîte du fil (rotation à la 1re étape, figée ensuite). Sans `ChoixBoite` (tests, ouvriers) : expéditeur fixe d'origine.
        $expediteur = $this->expedition->expediteur;
        if ($this->choixBoite !== null) {
            try {
                $expediteur = $this->choixBoite->pour($ws, $ins, $this->expedition->domaine, $maintenant)['adresse'];
            } catch (EnvoiRefuse $e) {
                if (! $e->estAttente()) {
                    throw $e;
                }

                return $this->reporter($ins, $rang, $e->getMessage(), $fenetre, $maintenant); // rien n'est réservé, aucun compteur
            }
        }

        $reservation = $this->reserver($ins, $rang, $inReplyTo, $maintenant);
        if ($reservation === null) {
            // Ligne « reserve » récente (une autre exécution est en cours) : on REPOUSSE l'échéance au lieu d'occuper une des
            // places de `executer` à chaque passage ; les lignes anciennes sont reprises par `reserver`.
            $this->repousserApresLigneEnCours($ins, $rang, $maintenant);

            return self::DEJA;
        }
        [$messageId, $reprise] = $reservation;

        $fiche = $this->fiches->lire($ws, (int) $ins->contact_id);
        if ($fiche === null || ListeSuppression::empreinte($fiche->email) !== trim((string) $ins->empreinte_adresse)) {
            return $this->refuserSaufDejaParti($ins, $rang, 'fiche_introuvable_ou_adresse_changee', $reprise, $maintenant);
        }
        if ($fiche->exclusion !== null) {
            return $this->refuserSaufDejaParti($ins, $rang, $fiche->exclusion, $reprise, $maintenant);
        }
        $demande = $this->demande($ws, $fiche, $rang, (string) $ins->empreinte_adresse, $repli);
        $verdict = EnvoiAutorise::verifier($demande, $maintenant);
        if (! $verdict->autorise()) {
            $motif = (string) $verdict->refus;
            $empreinte = trim((string) $ins->empreinte_adresse);
            // Refus PASSAGER (DNS du jour non vérifié, verdict d'adresse périmé) : on reporte, on n'arrête pas.
            if ($this->passager($ws, $motif, $empreinte)) {
                $parti = $reprise ? $this->dejaParti($ins, $rang, $maintenant) : false;

                return $parti ? $this->avancerSiActive($ins, $rang, $version, $messageId, $references, $fenetre, $maintenant) : $this->reporterAvecPlafond($ins, $rang, $motif, $fenetre, $maintenant);
            }
            // B3-B : adresse PAS ENCORE vérifiée (aucun verdict, ou « inconnu ») : l'opposition prime (arrêt définitif) ; sinon
            // report SANS compteur, le rafraîchissement des verdicts finit par la vérifier. Rien ne part sans verdict « valide ».
            if ($motif === EnvoiAutorise::VERDICT_ABSENT && $this->pasEncoreVerifiee($ws, $empreinte)) {
                $opposition = EnvoiAutorise::oppositionSeule($demande);
                if ($opposition !== null) {
                    return $this->refuserSaufDejaParti($ins, $rang, $opposition, $reprise, $maintenant);
                }
                if ($reprise && $this->dejaParti($ins, $rang, $maintenant)) {
                    return $this->avancerSiActive($ins, $rang, $version, $messageId, $references, $fenetre, $maintenant);
                }
                $this->alerterVerdictAbsentPersistant($ins, $rang, $maintenant);

                return $this->reporter($ins, $rang, $motif, $fenetre, $maintenant);
            }

            return $this->refuserSaufDejaParti($ins, $rang, $motif, $reprise, $maintenant);
        }

        // C1 : identifiant STABLE choisi par le CRM avant le réseau (même clé => même UUID), dans la transaction du temps A.
        $cle = 'seq-' . $ins->id . '-' . $rang;
        $inj = $this->registre->reserver($ws, $cle, $this->expedition->domaine);
        $this->alertes = [...$this->alertes, ...$this->registre->alertes];
        $this->registre->alertes = [];

        $sujet = ($rang > 1 && stripos((string) $etape['sujet'], 're:') !== 0) ? 'Re: ' . $etape['sujet'] : (string) $etape['sujet'];
        $brut = $this->composeur->composer(new DemandeComposition(
            $ws,
            $fiche->email,
            $expediteur,
            $sujet,
            (string) $etape['corps'],
            $messageId,
            $inReplyTo,
            $references,
            $this->variablesDe($ws, $fiche->contactId, $sujet, (string) $etape['corps']),
        ));

        return [
            'ins' => $ins, 'rang' => $rang, 'version' => $version, 'messageId' => $messageId, 'references' => $references, 'fenetre' => $fenetre,
            'message' => new MessageAEnvoyer($ws, $fiche->email, $expediteur, $sujet, $brut, $cle, $inj['id'] ?? null, $inj['cheminRetour'] ?? null),
            'idRegistre' => $inj['id'] ?? null,
        ];
    }

    /**
     * F2a : valeurs nettoyées des champs de fusion du destinataire. Gabarit sans variable : AUCUNE lecture. Un nom hors
     * liste fermée n'est pas lu (il prendra son repli).
     *
     * @return array<string, string>
     */
    private function variablesDe(string $ws, int $contactId, string $sujet, string $corps): array
    {
        $noms = array_values(array_intersect(VariablesMessage::utilisees($sujet, $corps), VariablesMessage::NOMS));
        if ($noms === []) {
            return [];
        }
        $this->variables ??= new LecteurVariablesDb;

        return ValeursVariables::nettoyer($this->variables->pourContacts($ws, [$contactId], $noms)[$contactId] ?? [])['valeurs'];
    }

    /**
     * Une AUTRE personne de l'entreprise a répondu ou s'est opposée : soit son inscription est arrêtée pour ce motif
     * (y compris l'opposition constatée par la porte : `garde:opposition`), soit la TRACE de l'événement existe
     * (même si son inscription est « terminee » : le statut ne dit plus rien).
     */
    private function entrepriseArretee(string $ws, int $entrepriseId, string $inscriptionId): bool
    {
        $arretee = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('entreprise_id', $entrepriseId)
            ->where('id', '<>', $inscriptionId)->where('statut', 'arretee')
            ->whereIn('motif_arret', [Evenement::REPONSE, Evenement::OPPOSITION, Evenement::MOTIF_GARDE_OPPOSITION, Evenement::MOTIF_ENTREPRISE])->exists();

        return $arretee || DB::table('envoi_inscriptions_evenements')->where('workspace_id', $ws)->where('entreprise_id', $entrepriseId)
            ->where('inscription_id', '<>', $inscriptionId)->whereIn('evenement', Evenement::PROPAGENT_A_L_ENTREPRISE)->exists();
    }

    private function adresseArretee(string $ws, string $empreinte, string $inscriptionId): bool
    {
        $arretee = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('empreinte_adresse', $empreinte)
            ->where('id', '<>', $inscriptionId)->where('statut', 'arretee')
            ->whereIn('motif_arret', [...Evenement::VALENT_POUR_L_ADRESSE, Evenement::MOTIF_GARDE_OPPOSITION, Evenement::MOTIF_ADRESSE])->exists();

        return $arretee || DB::table('envoi_inscriptions_evenements')->where('workspace_id', $ws)->where('empreinte_adresse', $empreinte)
            ->where('inscription_id', '<>', $inscriptionId)->whereIn('evenement', Evenement::VALENT_POUR_L_ADRESSE)->exists();
    }

    /**
     * Exception technique sur UNE inscription : compteur, dernière erreur (classe et motifs, jamais d'adresse), échéance
     * repoussée (1 h par échec, 24 h au plus) ; alerte au 5ᵉ échec puis tous les 5. L'inscription reste active.
     */
    private function echec(string $ws, string $inscriptionId, Throwable $e, DateTimeImmutable $maintenant): string
    {
        try {
            $detail = $e::class . ($e instanceof MessageRefuse ? ' : ' . implode(' ; ', $e->motifs) : '');
            $echecs = (int) DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $inscriptionId)->where('statut', 'active')->value('echecs') + 1;
            DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $inscriptionId)->where('statut', 'active')->update([
                'echecs' => $echecs,
                'derniere_erreur' => mb_substr($detail, 0, 500),
                'derniere_erreur_le' => $maintenant->format('c'),
                'prochaine_echeance' => $maintenant->modify('+' . min(24, $echecs) . ' hours')->format('c'),
            ]);
            if ($echecs % self::ECHECS_AVANT_ALERTE === 0) {
                Log::critical('envoi.sequenceur_inscription_en_erreur : intervention requise.', ['workspace_id' => $ws, 'inscription_id' => $inscriptionId, 'echecs' => $echecs, 'erreur' => $e::class]);
            }
        } catch (Throwable $autre) {
            Log::error('envoi.sequenceur_echec_non_note', ['workspace_id' => $ws, 'inscription_id' => $inscriptionId, 'erreur' => $autre::class]);
        }

        return self::ERREUR;
    }

    /**
     * Refus qui se lève sans qu'une personne ait rien décidé, ou réglage d'espace : voir `RefusSequenceur` (table
     * explicite). Cas particulier : `VERDICT_ABSENT` n'est reporté AVEC plafond que si le DERNIER verdict de l'adresse est
     * « valide » (donc périmé ou daté du futur) ; un verdict « invalide » ou « risque » est DÉFINITIF ; l'absence de verdict
     * (ou « inconnu ») est reportée sans compteur par `pasEncoreVerifiee` (B3-B).
     */
    private function passager(string $ws, string $motif, string $empreinte): bool
    {
        if (RefusSequenceur::temporaire($motif)) {
            return true;
        }

        return $motif === EnvoiAutorise::VERDICT_ABSENT && $this->dernierVerdict($ws, $empreinte) === 'valide';
    }

    /** Aucun verdict pour cette adresse dans CET espace, ou le dernier est « inconnu » (vérification pas encore concluante). */
    private function pasEncoreVerifiee(string $ws, string $empreinte): bool
    {
        return in_array($this->dernierVerdict($ws, $empreinte), [null, 'inconnu'], true);
    }

    private function dernierVerdict(string $ws, string $empreinte): ?string
    {
        $dernier = DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)
            ->where('empreinte_adresse', $empreinte)
            ->orderByDesc('verifie_le')->value('verdict');

        return $dernier === null ? null : (string) $dernier;
    }

    /** Alerte bornée : message « reporté » faute de verdict depuis 7 jours ou plus. Journal sans adresse ; aucun arrêt. */
    private function alerterVerdictAbsentPersistant(\stdClass $ins, int $rang, DateTimeImmutable $maintenant): void
    {
        $cree = DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->value('cree_le');
        if ($cree !== null && $maintenant->getTimestamp() - (new DateTimeImmutable((string) $cree))->getTimestamp() >= self::VERDICT_ABSENT_ALERTE_JOURS * 86400) {
            Log::warning('envoi.verdict_absent_persistant', ['workspace_id' => $ins->workspace_id, 'inscription_id' => $ins->id]);
        }
    }

    /** Une ligne « reserve » ou autre non reprenable : l'inscription n'est pas retraitée avant une heure. */
    private function repousserApresLigneEnCours(\stdClass $ins, int $rang, DateTimeImmutable $maintenant): void
    {
        $ligne = DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->first(['statut', 'maj_le']);
        $base = $ligne !== null && $ligne->statut === 'reserve' ? new DateTimeImmutable((string) $ligne->maj_le) : $maintenant;
        $quand = $base->modify('+' . self::RESERVATION_REPRISE_HEURES . ' hours +1 minute');
        $this->echeance($ins, $quand > $maintenant ? $quand : $maintenant->modify('+1 hour'));
    }

    /**
     * Reprise après plantage : si la passerelle a DÉJÀ accepté ce message, il est parti. Il se conclut « envoyé » (jamais
     * « refusé »). Retourne vrai si c'est le cas.
     */
    private function dejaParti(\stdClass $ins, int $rang, DateTimeImmutable $maintenant): bool
    {
        if (! ($this->serveur instanceof ConsultationAcceptation)) {
            return false;
        }
        $cle = 'seq-' . $ins->id . '-' . $rang;
        $res = $this->serveur->dejaAccepte((string) $ins->workspace_id, $cle);
        if ($res === null) {
            return false;
        }
        // Même identifiant qu'au premier passage : relu (jamais retiré au sort) dans le registre.
        $idRegistre = $this->registre->reserver((string) $ins->workspace_id, $cle, $this->expedition->domaine)['id'] ?? null;
        $this->alertes = [...$this->alertes, ...$this->registre->alertes];
        $this->registre->alertes = [];
        DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->where('statut', 'reserve')
            ->update(['statut' => 'envoye', 'refus' => null, ...$this->colonnesIdentifiants($idRegistre, $res->idInjection), 'maj_le' => $maintenant->format('c')]);
        $messageId = DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->value('message_id');
        if (is_string($messageId)) {
            $this->enregistrerEnvoye($ins, $rang, $messageId, $idRegistre, $res->idInjection);
        }

        return true;
    }

    /**
     * Les deux colonnes d'identifiant de `envoi_messages` (C1) : `id_injection` = identifiant du REGISTRE quand il existe (celui
     * que la relève et le VERP rendent), sinon celui du serveur (smtp, domaine inconnu : chemin d'avant) ;
     * `id_injection_serveur` = identifiant rendu par le serveur seulement s'il diffère de celui du registre.
     *
     * @return array{id_injection: ?string, id_injection_serveur: ?string}
     */
    private function colonnesIdentifiants(?string $idRegistre, ?string $idServeur): array
    {
        return [
            'id_injection' => $idRegistre ?? $idServeur,
            'id_injection_serveur' => $idRegistre !== null && $idServeur !== null && $idServeur !== $idRegistre ? $idServeur : null,
        ];
    }

    /**
     * Registre des messages envoyés (L8-COMPO), dans la transaction de conclusion, une fois par message accepté : le Message-ID
     * (empreinte) et l'empreinte du destinataire, sans adresse ni corps, pour que la relève retrouve le message. Depuis C1,
     * l'identifiant du registre est choisi par le CRM avant l'envoi (`RegistreInjectionsSequence`) : il est écrit tel quel, aussi
     * dans `envoi_messages.id_injection` (plus de devinette « sim- => faux »). Sans identifiant de registre (domaine sur smtp ou
     * inconnu de l'espace), le chemin d'avant s'applique : UUID de `envoi_injections_smtp` ou UUID local. Écriture sous point de
     * sauvegarde : une panne du registre NE DÉFAIT PAS la conclusion d'un message réellement parti, mais elle n'est jamais
     * silencieuse (alerte critique après le COMMIT).
     */
    private function enregistrerEnvoye(\stdClass $ins, int $rang, string $messageId, ?string $idRegistre, ?string $idServeur): void
    {
        try {
            DB::transaction(function () use ($ins, $rang, $messageId, $idRegistre, $idServeur): void {
                $ws = (string) $ins->workspace_id;
                $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
                $id = $idRegistre;
                if ($id === null && $idServeur !== null && preg_match($uuid, $idServeur) === 1
                    && DB::table('envoi_injections_smtp')->where('workspace_id', $ws)->where('id_injection', $idServeur)->exists()) {
                    $id = $idServeur;
                }
                if ($id === null) {
                    $cle = 'seq-' . $ins->id . '-' . $rang;
                    DB::table('envoi_injections_locales')->insertOrIgnore([
                        'workspace_id' => $ws, 'cle_idempotence' => $cle,
                        'adaptateur' => $idServeur !== null && str_starts_with($idServeur, 'sim-') ? 'faux' : 'kumo',
                    ]);
                    $id = (string) DB::table('envoi_injections_locales')->where('workspace_id', $ws)->where('cle_idempotence', $cle)->value('id_injection');
                }
                DB::table('envoi_messages_envoyes')->insertOrIgnore([
                    'workspace_id' => $ws,
                    'message_id_empreinte' => hash('sha256', 'mid:' . trim($messageId, " \t\r\n<>")),
                    'id_injection' => $id,
                    'empreinte_destinataire' => trim((string) $ins->empreinte_adresse),
                ]);
            });
        } catch (Throwable $e) {
            $this->alertes[] = ['envoi.registre_ecriture_echouee : message parti mais absent du registre, intervention requise.', ['workspace_id' => $ins->workspace_id, 'inscription_id' => $ins->id, 'etape' => $rang, 'erreur' => $e::class]];
        }
    }

    /**
     * Refus DÉFINITIF d'une garde ; sauf si la passerelle avait déjà accepté le message (reprise) : alors « envoyé », puis
     * l'inscription est arrêtée (la garde le demande) au lieu de passer la ligne « refuse ».
     */
    private function refuserSaufDejaParti(\stdClass $ins, int $rang, string $motif, bool $reprise, DateTimeImmutable $maintenant): string
    {
        if ($reprise && $this->dejaParti($ins, $rang, $maintenant)) {
            $this->arreter($ins, 'garde:' . $motif, $maintenant);
            if ($motif === EligibiliteAdresse::OPPOSITION) {
                Inscriptions::tracer((string) $ins->workspace_id, $ins, Evenement::OPPOSITION, $maintenant);
            }

            return self::ARRETEE;
        }

        return $this->refuser($ins, $rang, $motif, $maintenant);
    }

    /**
     * @param  array<string, mixed>  $version
     * @param  list<string>  $references
     */
    private function avancerSiActive(\stdClass $ins, int $rang, array $version, string $messageId, array $references, FenetreHoraire $fenetre, DateTimeImmutable $maintenant): string
    {
        $this->avancer($ins, $rang, $version, $messageId, $references, $fenetre, $maintenant);

        return self::ENVOYE;
    }

    /**
     * Réserve (inscription, étape). null = déjà réservé/envoyé/refusé par une autre exécution. Sinon [Message-ID, reprise] :
     * reprise vrai si une ligne « reserve » ancienne a été reprise (plantage avant la conclusion).
     *
     * @return array{0: string, 1: bool}|null
     */
    private function reserver(\stdClass $ins, int $rang, ?string $inReplyTo, DateTimeImmutable $maintenant): ?array
    {
        $messageId = '<' . Str::uuid() . '@' . $this->expedition->domaine . '>';
        $lignes = DB::table('envoi_messages')->insertOrIgnore([
            'workspace_id' => $ins->workspace_id,
            'inscription_id' => $ins->id,
            'etape' => $rang,
            'statut' => 'reserve',
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            // L'instant du traitement, jamais l'horloge de la base (M3).
            'cree_le' => $maintenant->format('c'),
            'maj_le' => $maintenant->format('c'),
        ]);
        if ($lignes === 1) {
            return [$messageId, false];
        }
        // Une ligne « reporte » (passerelle pleine) peut être reprise ; toute autre est définitive.
        $repris = DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->where('statut', 'reporte')
            ->update(['statut' => 'reserve', 'message_id' => $messageId, 'in_reply_to' => $inReplyTo, 'maj_le' => $maintenant->format('c')]);

        if ($repris === 1) {
            return [$messageId, false];
        }
        // Ligne « reserve » ancienne : reprise (on réclame la ligne en avançant `maj_le`, une seule exécution gagne) avec le MÊME Message-ID.
        $reprise = DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->where('statut', 'reserve')
            ->where('maj_le', '<', $maintenant->modify('-' . (self::RESERVATION_REPRISE_HEURES * 60 + 1) . ' minutes')->format('c'))
            ->update(['maj_le' => $maintenant->format('c')]);
        if ($reprise === 1) {
            Log::warning('envoi.sequenceur_reprise_reservation : étape « reserve » reprise par le chemin de rejeu.', ['workspace_id' => $ins->workspace_id, 'inscription_id' => $ins->id, 'etape' => $rang]);

            return [(string) DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->value('message_id'), true];
        }

        return null;
    }

    private function demande(string $ws, Fiche $f, int $rang, string $empreinte, bool $repli = false): DemandeEnvoi
    {
        $dejaEnvoye = DB::table('envoi_messages as m')->join('envoi_inscriptions as i', 'i.id', '=', 'm.inscription_id')
            ->where('m.workspace_id', $ws)->where('i.empreinte_adresse', $empreinte)->where('m.statut', 'envoye')->exists();

        return new DemandeEnvoi(
            $ws,
            $f->email,
            $f->occurrences,
            $f->pays,
            $f->siren,
            $this->expedition->domaine,
            $this->expedition->entiteLegale,
            $f->collecteeLe,
            $f->premiereInfoLe,
            $f->segment,
            $rang === 1 && ! $dejaEnvoye,
            false,
            $repli, // L5b : la porte n'admet `risque/generique` que pour un repli (L5a)
        );
    }

    private static function estRepli(\stdClass $ins): bool
    {
        return in_array($ins->repli_generique ?? false, [true, 't', 'true', 1, '1'], true);
    }

    /**
     * R7 : le repli n'a plus lieu d'être si l'interrupteur de l'espace n'est plus « true » (absent, illisible = éteint), ou si
     * une personne NOMMÉE (adresse nominative, contact vivant) existe désormais dans l'entreprise. Toute erreur de lecture remonte :
     * la transaction de réservation est défaite et rien ne part.
     */
    private function repliCaduc(string $ws, \stdClass $ins): bool
    {
        if (! RepliGenerique::ouvert($ws)) {
            return true;
        }
        $adresses = DB::table('contacts')->where('workspace_id', $ws)->where('company_id', (int) $ins->entreprise_id)->whereNull('deleted_at')
            ->whereNotNull('email')->orderBy('id')->selectRaw('email::text AS email')->pluck('email');
        foreach ($adresses as $adresse) {
            if (trim((string) $adresse) !== '' && QualificationEmail::type((string) $adresse) === 'nominatif') {
                return true;
            }
        }

        return false;
    }

    private function refuser(\stdClass $ins, int $rang, string $motif, DateTimeImmutable $maintenant): string
    {
        DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)
            ->update(['statut' => 'refuse', 'refus' => $motif, 'maj_le' => $maintenant->format('c')]);
        $this->arreter($ins, 'garde:' . $motif, $maintenant);
        if ($motif === EligibiliteAdresse::OPPOSITION) {
            // Opposition constatée par la porte (liste de suppression) : elle vaut comme l'événement « opposition »
            // (adresse ET entreprise), et sa trace ne dépend pas du statut de l'inscription.
            Inscriptions::tracer((string) $ins->workspace_id, $ins, Evenement::OPPOSITION, $maintenant);
        }

        return self::REFUSE;
    }

    /** Report d'un refus PASSAGER, plafonné : au plafond, refus définitif + arrêt + alerte (une cause « passagère » qui dure demande un humain). */
    private function reporterAvecPlafond(\stdClass $ins, int $rang, string $motif, FenetreHoraire $fenetre, DateTimeImmutable $maintenant): string
    {
        $reports = (int) $ins->reports + 1; // la ligne est verrouillée par `traiter` : pas de course
        if ($reports >= self::REPORTS_MAX) {
            DB::table('envoi_inscriptions')->where('id', $ins->id)->update(['reports' => $reports]);
            $this->alertes[] = ['envoi.sequenceur_reports_epuises : inscription arrêtée, intervention requise.', ['workspace_id' => $ins->workspace_id, 'inscription_id' => $ins->id, 'reports' => $reports, 'dernier_motif' => $motif]];
            DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)
                ->update(['statut' => 'refuse', 'refus' => $motif, 'maj_le' => $maintenant->format('c')]);

            $this->arreter($ins, self::MOTIF_REPORTS_EPUISES, $maintenant);

            return self::REFUSE;
        }
        DB::table('envoi_inscriptions')->where('id', $ins->id)->update(['reports' => $reports]);

        return $this->reporter($ins, $rang, $motif, $fenetre, $maintenant);
    }

    /** Issue incertaine : inscription arrêtée (jamais reportée) et UNE alerte critique. */
    private function arreterIssueIncertaine(\stdClass $ins, ?string $erreur, DateTimeImmutable $maintenant, bool $alerter = true): string
    {
        if ($alerter) { // pas de seconde alerte si la passerelle vient d'en émettre une pour le même incident (réservation périmée)
            $this->alertes[] = ['envoi.sequenceur_issue_incertaine : inscription arrêtée, intervention requise.', ['workspace_id' => $ins->workspace_id, 'inscription_id' => $ins->id, 'erreur' => $erreur]];
        }

        return $this->arreter($ins, self::MOTIF_ISSUE_INCERTAINE, $maintenant);
    }

    private function reporter(\stdClass $ins, int $rang, string $motif, FenetreHoraire $fenetre, DateTimeImmutable $maintenant): string
    {
        DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)
            ->update(['statut' => 'reporte', 'refus' => $motif, 'maj_le' => $maintenant->format('c')]);
        $this->echeance($ins, $fenetre->echeance($maintenant, 1));

        return self::REPORTE;
    }

    private function arreter(\stdClass $ins, string $motif, DateTimeImmutable $maintenant): string
    {
        DB::table('envoi_inscriptions')->where('id', $ins->id)->update([
            'statut' => 'arretee', 'motif_arret' => $motif, 'arretee_le' => $maintenant->format('c'),
        ]);

        return self::ARRETEE;
    }

    private function echeance(\stdClass $ins, DateTimeImmutable $quand): void
    {
        DB::table('envoi_inscriptions')->where('id', $ins->id)->update(['prochaine_echeance' => $quand->format('c')]);
    }

    /**
     * @param  array<string, mixed>  $version
     * @param  list<string>  $references
     */
    private function avancer(\stdClass $ins, int $rang, array $version, string $messageId, array $references, FenetreHoraire $fenetre, DateTimeImmutable $maintenant): void
    {
        $fil = $ins->fil_message_id ?? $messageId;
        $refs = trim(implode(' ', [...$references, $messageId]));
        $maj = ['fil_message_id' => $fil, 'fil_references' => $refs, 'reports' => 0];
        if ($rang >= count($version['etapes'])) {
            $maj += ['statut' => 'terminee'];
        } else {
            $maj += [
                'etape_courante' => $rang + 1,
                'prochaine_echeance' => $fenetre->echeance($maintenant, (int) $version['etapes'][$rang]['delai_jours_ouvres'])->format('c'),
            ];
        }
        DB::table('envoi_inscriptions')->where('id', $ins->id)->update($maj);
    }
}
