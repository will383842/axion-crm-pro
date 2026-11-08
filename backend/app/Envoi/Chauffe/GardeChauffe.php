<?php

namespace App\Envoi\Chauffe;

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\JourEnvoi;
use App\Socle\Alertes\Alerte;
use DateTimeImmutable;

/**
 * Chauffe + freins, branchés sur la passerelle locale (appelés SOUS son verrou du jour).
 * `refus()` ne fait que LIRE ; `noter()` compte l'envoi accepté ; `evaluer()` (commande
 * planifiée) décide et ouvre les pauses. Les alertes passent par Socle/Alertes
 * (alertes INTERNES seulement) : jamais depuis une boîte de prospection.
 *
 * Les freins ouvrent des pauses GLOBALES (tout l'espace), sauf ceux qui visent un fournisseur
 * précis (indésirable chez un témoin, baisse du taux de réponse). La pause globale est la plus
 * stricte : on la garde plutôt que de la restreindre au fournisseur d'où vient le signal.
 */
final class GardeChauffe
{
    public const QUOTA_ATTEINT = 'quota_de_chauffe_atteint';

    public const QUOTA_DOMAINE_ATTEINT = 'quota_du_domaine_atteint';

    public const EXPEDITEUR_INCONNU = 'expediteur_inconnu';

    public const HORS_FENETRE = 'hors_fenetre_envoi';

    public function __construct(
        private readonly DepotChauffe $depot,
        private readonly DepotSocle $socle,
        private readonly ResolveurFournisseur $resolveur,
        private readonly ?Alerte $alerte = null,
    ) {}

    /** Adresse expéditeur normalisée (casse, espaces) pour la retrouver dans `envoi_boites`. */
    public static function boite(string $expediteur): string
    {
        return mb_strtolower(trim($expediteur));
    }

    /**
     * Résout le fournisseur du destinataire UNE SEULE FOIS, AVANT le verrou de la passerelle (le DNS ne tourne
     * jamais sous verrou) ; la valeur est ensuite réutilisée par `refus()` et `noter()`.
     * Échec DNS = {@see Fournisseur::INCONNU} : bloqué par toute pause de fournisseur (fail-closed).
     */
    public function preparer(MessageAEnvoyer $m): string
    {
        return $this->resolveur->fournisseurDe($m->destinataire);
    }

    /** Homme mort INF-025 : la passerelle note chaque appel à sante() qui répond OK. */
    public function battre(DateTimeImmutable $maintenant): void
    {
        $this->depot->noterBattement($maintenant);
    }

    /**
     * Motif de report, ou null si l'envoi peut partir. Ordre : fenêtre d'envoi, homme mort (toujours, dans la
     * fenêtre), pauses, frein humain, expéditeur connu, quota de la boîte, quota du domaine.
     *
     * @param  ?string  $fournisseur  valeur déjà résolue par `preparer()` ; null = résolu ici (hors passerelle)
     */
    public function refus(MessageAEnvoyer $m, DateTimeImmutable $jour, DateTimeImmutable $maintenant, ?string $fournisseur = null): ?string
    {
        // Jamais d'envoi hors de la fenêtre [08:00, 19:00[ (Paris) : sans cela, l'homme mort ne jouerait pas.
        if (! Freins::dansFenetreEnvoi($maintenant)) {
            return self::HORS_FENETRE;
        }
        // INF-025 : sans appel à sante() OK depuis 15 minutes, TOUTES les files sont en pause (en permanence, dans la fenêtre).
        if (Freins::santeSilencieuse($this->depot->dernierBattement(), $maintenant)) {
            return 'pause:' . DecisionFrein::SANTE_SILENCIEUSE;
        }
        $ws = $m->workspaceId;
        $fournisseur ??= $this->resolveur->fournisseurDe($m->destinataire);
        foreach ($this->depot->pausesActives($ws, $maintenant) as $p) {
            // Fournisseur INCONNU (DNS en échec) : toute pause de fournisseur le bloque (fail-closed).
            if ($p->portee === 'global' || $fournisseur === Fournisseur::INCONNU || $p->fournisseur === $fournisseur) {
                return 'pause:' . $p->motif;
            }
        }
        $s = $this->seuils($ws);
        $humain = Freins::freinHumain($this->depot->reponsesEnAttente($ws), (int) $s['humain_pause_a']);
        if ($humain === Freins::PAUSE) {
            return 'pause:' . DecisionFrein::REPONSES_NON_TRAITEES;
        }
        // Le quota se compte par boîte RÉELLE (`envoi_boites`), jamais par une chaîne libre : expéditeur inconnu = refus.
        $boite = $this->depot->boiteId($ws, $m->expediteur);
        if ($boite === null) {
            return self::EXPEDITEUR_INCONNU;
        }
        $quota = CourbeChauffe::quotaBoite($this->jourDeChauffe($ws, $boite, $jour, $s['jour_effectif_part']));
        if ($humain === Freins::RALENTI) {
            $quota = (int) floor($quota * Seuils::HUMAIN_FACTEUR_RALENTI);
        }
        if ($this->depot->envoyesBoite($ws, $boite, $jour) >= $quota) {
            return self::QUOTA_ATTEINT;
        }
        // Quota du DOMAINE d'envoi : créer des boîtes sur le même domaine ne multiplie pas la montée de chauffe.
        if ($this->depot->envoyesDomaine($ws, Fournisseur::domaine($m->expediteur), $jour) >= Seuils::DOMAINE_FACTEUR * $quota) {
            return self::QUOTA_DOMAINE_ATTEINT;
        }

        return null;
    }

    public function noter(MessageAEnvoyer $m, DateTimeImmutable $jour, ?string $fournisseur = null): void
    {
        $boite = $this->depot->boiteId($m->workspaceId, $m->expediteur)
            ?? throw new \LogicException('envoi accepté pour un expéditeur qui n\'est pas une boîte d\'envoi');
        $this->depot->incrementer($m->workspaceId, $boite, $jour, $fournisseur ?? $this->resolveur->fournisseurDe($m->destinataire), 'envoyes');
    }

    /** Un message est arrivé en indésirables chez un témoin : pause vers CE fournisseur seulement. */
    public function indesirableChezTemoin(string $ws, string $adresseTemoin, ?DateTimeImmutable $maintenant = null): bool
    {
        $f = $this->resolveur->fournisseurDe($adresseTemoin);
        // Témoin dont le fournisseur est introuvable : on ne peut pas cibler, donc pause GLOBALE (la plus stricte).
        $d = $f === Fournisseur::INCONNU ? new DecisionFrein(DecisionFrein::INDESIRABLE_TEMOIN) : Freins::indesirableTemoin($f);

        return $this->declarer($ws, $d, $maintenant ?? now()->toDateTimeImmutable());
    }

    /**
     * Évalue tous les freins mesurables pour la journée de $maintenant et ouvre les pauses.
     *
     * @return list<DecisionFrein> les freins qui ont ouvert une pause neuve
     */
    public function evaluer(string $ws, DateTimeImmutable $maintenant): array
    {
        $jour = JourEnvoi::de($maintenant);
        $s = $this->seuils($ws);
        $sept = $this->depot->totaux7Jours($ws, $jour);
        $decisions = array_filter([
            Freins::rebondsDurs($sept['envoyes'], $sept['rebonds_durs'], $this->depot->totauxDuJour($ws, $jour)['rebonds_durs'], $s['rebonds_durs_taux_max'], (int) $s['rebonds_durs_jour_max']),
        ]);

        // Plaintes : on ne recompte pas celles qu'un arrêt de 48 h a déjà consommées.
        $plainte = Freins::plaintes($this->depot->plaintes7Jours($ws, $jour, $this->depot->derniereOuverture($ws, DecisionFrein::PLAINTES_ARRET)));
        if ($plainte?->motif === DecisionFrein::PLAINTES_ARRET) {
            $decisions[] = $plainte;
        } elseif ($plainte !== null) {
            $this->alerterUneFoisParJour($ws, 'plainte_relecture', $jour, 'Plainte reçue : relecture demandée', 'Une plainte est arrivée sur les 7 derniers jours : le palier de chauffe est tenu. Relire le contenu et la cible avant de poursuivre.');
        }
        if (Freins::freinHumain($this->depot->reponsesEnAttente($ws), (int) $s['humain_pause_a']) === Freins::PAUSE) {
            $decisions[] = new DecisionFrein(DecisionFrein::REPONSES_NON_TRAITEES);
        }
        foreach (Fournisseur::TOUS as $f) {
            // Fenêtres COMPLÈTES seulement (jours révolus) : la dernière fournée complète contre les 3 d'avant.
            // Limite documentée : un événement de retour ne porte pas la date d'envoi du message d'origine, donc
            // une réponse est comptée le jour où elle est traitée (pas sur la fournée qui l'a provoquée).
            $fournees = $this->depot->fourneesPrecedentes($ws, $f, $jour, Seuils::REPONSE_FOURNEES_COMPAREES + 1);
            $d = Freins::reponsesEnBaisse(
                $f,
                $fournees[0] ?? ['envoyes' => 0, 'reponses' => 0],
                array_slice($fournees, 1),
                $s['reponse_ratio_min'],
            );
            if ($d !== null) {
                $decisions[] = $d;
            }
        }
        // Tableau de bord non validé : ALERTE seulement (l'arrêt, lui, vient de l'homme mort INF-025).
        if (Freins::hommeMort($this->depot->derniereValidation($ws), $maintenant, (int) $s['homme_mort_jours']) !== null) {
            $this->alerterUneFoisParJour($ws, 'tableau_non_valide_alerte', $jour, 'Tableau de bord d’envoi non validé', 'Le tableau de bord n’a pas été validé depuis ' . (int) $s['homme_mort_jours'] . ' jours (ou jamais). L’envoi n’est pas arrêté : valider par envoi:valider-tableau.');
        }

        return array_values(array_filter($decisions, fn (DecisionFrein $d): bool => $this->declarer($ws, $d, $maintenant)));
    }

    /** Jour de chauffe EFFECTIF (volume réel minimal par jour), avec « palier tenu » tant qu'une plainte récente (7 jours) est dans la fenêtre. */
    private function jourDeChauffe(string $ws, string $boite, DateTimeImmutable $jour, float $part): int
    {
        $jours = CourbeChauffe::joursEffectifs($this->depot->volumesParJour($ws, $boite, $jour), $part);
        $jc = CourbeChauffe::jourDeChauffe($jours, $jour);
        $plainte = $this->depot->dernierJourPlainte($ws, $jour);
        if ($plainte === null) {
            return $jc;
        }
        $fin = CourbeChauffe::finDuPalier(CourbeChauffe::jourDeChauffe($jours, $plainte));

        return $fin === null ? $jc : min($jc, $fin);
    }

    private function declarer(string $ws, DecisionFrein $d, DateTimeImmutable $maintenant): bool
    {
        $manuelle = $this->depot->derniereLeveeManuelle($ws, $d->motif, $d->fournisseur);
        if (! $this->depot->ouvrirPause($ws, $d->motif, $d->fournisseur, $maintenant, $d->dureeHeures)) {
            return false;
        }
        $details = ['motif' => $d->motif, 'fournisseur' => $d->fournisseur, 'duree_heures' => $d->dureeHeures];
        if ($d->motif === DecisionFrein::PLAINTES_ARRET) {
            // Plaintes du jour à l'instant de l'arrêt : celles d'après ne seront pas ignorées au recomptage.
            $details['plaintes_jour'] = $this->depot->totauxDuJour($ws, JourEnvoi::de($maintenant))['plaintes'];
        }
        if ($manuelle !== null) {
            $details['rouvert_apres_levee_par'] = $manuelle['par'];
        }
        $this->socle->journaliser($ws, 'pause_auto', 'freins', $details, JourEnvoi::de($maintenant));
        $corps = $d->dureeHeures !== null
            ? "Un frein automatique a mis l’envoi en pause pour {$d->dureeHeures} h (elle expire d’elle-même). Vérifier le tableau de bord."
            : 'Un frein automatique a mis l’envoi en pause. Vérifier le tableau de bord, puis lever la pause par envoi:lever-pause.';
        if ($manuelle !== null) {
            $corps .= " Ce frein avait été levé à la main (par {$manuelle['par']}, le {$manuelle['le']}) et se rouvre : la cause n’a pas disparu. Ne pas relever la pause sans l’avoir traitée.";
        }
        $this->alerte?->envoyer(
            'Envoi en pause' . ($d->fournisseur !== null ? ' vers ' . $d->fournisseur : '') . ' : ' . $d->motif . ($manuelle !== null ? ' (frein levé à la main, rouvert)' : ''),
            $corps,
            'envoi',
            'critique',
        );

        return true;
    }

    private function alerterUneFoisParJour(string $ws, string $commande, DateTimeImmutable $jour, string $titre, string $corps): void
    {
        if ($this->depot->dejaJournaliseLe($ws, $commande, $jour)) {
            return;
        }
        $this->socle->journaliser($ws, $commande, 'freins', [], $jour);
        $this->alerte?->envoyer($titre, $corps, 'envoi', 'critique');
    }

    /** @return array{rebonds_durs_taux_max: float, rebonds_durs_jour_max: float, homme_mort_jours: float, humain_pause_a: float, reponse_ratio_min: float, jour_effectif_part: float} */
    private function seuils(string $ws): array
    {
        return [
            'rebonds_durs_taux_max' => Seuils::resserre('rebonds_durs_taux_max', Seuils::REBONDS_DURS_TAUX_MAX, $this->depot->seuil($ws, 'rebonds_durs_taux_max')),
            'rebonds_durs_jour_max' => Seuils::resserre('rebonds_durs_jour_max', Seuils::REBONDS_DURS_JOUR_MAX, $this->depot->seuil($ws, 'rebonds_durs_jour_max')),
            'homme_mort_jours' => Seuils::resserre('homme_mort_jours', Seuils::HOMME_MORT_JOURS, $this->depot->seuil($ws, 'homme_mort_jours')),
            'humain_pause_a' => Seuils::resserre('humain_pause_a', Seuils::HUMAIN_PAUSE_A, $this->depot->seuil($ws, 'humain_pause_a')),
            'reponse_ratio_min' => Seuils::resserre('reponse_ratio_min', Seuils::REPONSE_RATIO_MIN, $this->depot->seuil($ws, 'reponse_ratio_min')),
            'jour_effectif_part' => Seuils::resserre('jour_effectif_part', Seuils::JOUR_EFFECTIF_PART, $this->depot->seuil($ws, 'jour_effectif_part')),
        ];
    }
}
