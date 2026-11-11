<?php

namespace App\Envoi\Sequence;

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Envoi\Contrat\ConsultationAcceptation;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\NatureReport;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\InjectionIncertaine;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\Gardes\DemandeEnvoi;
use App\Envoi\Gardes\EnvoiAutorise;
use App\Envoi\Message\MessageRefuse;
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
        $ids = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('statut', 'active')
            ->where('prochaine_echeance', '<=', $maintenant->format('c'))
            ->orderBy('prochaine_echeance')->limit($limite)->pluck('id')->all();
        $bilan = [];
        foreach ($ids as $id) {
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
                ->update(['statut' => 'envoye', 'refus' => null, 'id_injection' => $res->idInjection, 'maj_le' => $maintenant->format('c')]);
            if ($basculees !== 1) {
                return self::DEJA;
            }
            if ($ins->statut === 'active' && (int) $ins->etape_courante === $rang) {
                $this->avancer($ins, $rang, $plan['version'], $plan['messageId'], $plan['references'], $plan['fenetre'], $maintenant);
            }

            return self::ENVOYE;
        }, $maintenant);
    }

    /**
     * Temps C : transaction courte, ligne d'inscription relue sous verrou, alertes émises après le COMMIT.
     *
     * @param  array{ins: \stdClass, rang: int, version: array<string, mixed>, messageId: string, references: list<string>, fenetre: FenetreHoraire, message: MessageAEnvoyer}  $plan
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
     * @return string|array{ins: \stdClass, rang: int, version: array<string, mixed>, messageId: string, references: list<string>, fenetre: FenetreHoraire, message: MessageAEnvoyer}
     */
    private function reserverEtPreparer(string $ws, string $inscriptionId, DateTimeImmutable $maintenant): string|array
    {
        $ins = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('id', $inscriptionId)->lockForUpdate()->first();
        if ($ins === null || $ins->statut !== 'active' || new DateTimeImmutable((string) $ins->prochaine_echeance) > $maintenant) {
            return self::IGNORE;
        }
        $version = json_decode((string) $ins->version_figee, true, 512, JSON_THROW_ON_ERROR);
        $rang = (int) $ins->etape_courante;
        $etape = $version['etapes'][$rang - 1] ?? null;
        if ($etape === null) {
            return $this->arreter($ins, 'etape_inconnue', $maintenant);
        }

        if ($this->entrepriseArretee($ws, (int) $ins->entreprise_id, (string) $ins->id)) {
            return $this->arreter($ins, Evenement::MOTIF_ENTREPRISE, $maintenant);
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
        $verdict = EnvoiAutorise::verifier($this->demande($ws, $fiche, $rang, (string) $ins->empreinte_adresse), $maintenant);
        if (! $verdict->autorise()) {
            $motif = (string) $verdict->refus;
            // Refus PASSAGER (DNS du jour non vérifié, verdict d'adresse périmé) : on reporte, on n'arrête pas.
            if ($this->passager($ws, $motif, $fiche->email)) {
                $parti = $reprise ? $this->dejaParti($ins, $rang, $maintenant) : false;

                return $parti ? $this->avancerSiActive($ins, $rang, $version, $messageId, $references, $fenetre, $maintenant) : $this->reporterAvecPlafond($ins, $rang, $motif, $fenetre, $maintenant);
            }

            return $this->refuserSaufDejaParti($ins, $rang, $motif, $reprise, $maintenant);
        }

        $sujet = ($rang > 1 && stripos((string) $etape['sujet'], 're:') !== 0) ? 'Re: ' . $etape['sujet'] : (string) $etape['sujet'];
        $brut = $this->composeur->composer(new DemandeComposition(
            $ws,
            $fiche->email,
            $this->expedition->expediteur,
            $sujet,
            (string) $etape['corps'],
            $messageId,
            $inReplyTo,
            $references,
        ));

        return [
            'ins' => $ins, 'rang' => $rang, 'version' => $version, 'messageId' => $messageId, 'references' => $references, 'fenetre' => $fenetre,
            'message' => new MessageAEnvoyer($ws, $fiche->email, $this->expedition->expediteur, $sujet, $brut, 'seq-' . $ins->id . '-' . $rang),
        ];
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
     * explicite). Cas particulier : `VERDICT_ABSENT` n'est reporté que si le DERNIER verdict de l'adresse est
     * « valide » (donc périmé ou daté du futur) ; un verdict « invalide » ou jamais posé est DÉFINITIF.
     */
    private function passager(string $ws, string $motif, string $email): bool
    {
        if (RefusSequenceur::temporaire($motif)) {
            return true;
        }
        if ($motif !== EnvoiAutorise::VERDICT_ABSENT) {
            return false;
        }
        $dernier = DB::table('envoi_verdicts_adresse')->where('workspace_id', $ws)
            ->where('empreinte_adresse', ListeSuppression::empreinte(mb_strtolower(trim($email))))
            ->orderByDesc('verifie_le')->value('verdict');

        return $dernier === 'valide';
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
        $res = $this->serveur->dejaAccepte((string) $ins->workspace_id, 'seq-' . $ins->id . '-' . $rang);
        if ($res === null) {
            return false;
        }
        DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->where('statut', 'reserve')
            ->update(['statut' => 'envoye', 'refus' => null, 'id_injection' => $res->idInjection, 'maj_le' => $maintenant->format('c')]);

        return true;
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
            ->where('maj_le', '<', $maintenant->modify('-' . self::RESERVATION_REPRISE_HEURES . ' hours')->format('c'))
            ->update(['maj_le' => $maintenant->format('c')]);
        if ($reprise === 1) {
            Log::warning('envoi.sequenceur_reprise_reservation : étape « reserve » reprise par le chemin de rejeu.', ['workspace_id' => $ins->workspace_id, 'inscription_id' => $ins->id, 'etape' => $rang]);

            return [(string) DB::table('envoi_messages')->where('inscription_id', $ins->id)->where('etape', $rang)->value('message_id'), true];
        }

        return null;
    }

    private function demande(string $ws, Fiche $f, int $rang, string $empreinte): DemandeEnvoi
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
        );
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
