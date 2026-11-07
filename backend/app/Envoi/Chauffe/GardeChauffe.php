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

    public function __construct(
        private readonly DepotChauffe $depot,
        private readonly DepotSocle $socle,
        private readonly ResolveurFournisseur $resolveur,
        private readonly ?Alerte $alerte = null,
    ) {}

    /** Adresse expéditeur normalisée : une boîte = une adresse, quelle que soit la casse saisie. */
    public static function boite(string $expediteur): string
    {
        return mb_strtolower(trim($expediteur));
    }

    /** Résout (et met en cache) le fournisseur AVANT le verrou de la passerelle : pas de DNS sous verrou. */
    public function preparer(MessageAEnvoyer $m): void
    {
        $this->resolveur->fournisseurDe($m->destinataire);
    }

    /** Homme mort INF-025 : la passerelle note chaque appel à sante(). */
    public function battre(DateTimeImmutable $maintenant): void
    {
        $this->depot->noterBattement($maintenant);
    }

    /** Motif de report, ou null si l'envoi peut partir. */
    public function refus(MessageAEnvoyer $m, DateTimeImmutable $jour, DateTimeImmutable $maintenant): ?string
    {
        // INF-025 : sans appel à sante() depuis 15 minutes en fenêtre d'envoi, TOUTES les files sont en pause.
        if (Freins::dansFenetreEnvoi($maintenant) && Freins::santeSilencieuse($this->depot->dernierBattement(), $maintenant)) {
            return 'pause:' . DecisionFrein::SANTE_SILENCIEUSE;
        }
        $ws = $m->workspaceId;
        $boite = self::boite($m->expediteur);
        $fournisseur = $this->resolveur->fournisseurDe($m->destinataire);
        foreach ($this->depot->pausesActives($ws, $maintenant) as $p) {
            if ($p->portee === 'global' || $p->fournisseur === $fournisseur) {
                return 'pause:' . $p->motif;
            }
        }
        $s = $this->seuils($ws);
        $humain = Freins::freinHumain($this->depot->reponsesEnAttente($ws), (int) $s['humain_pause_a']);
        if ($humain === Freins::PAUSE) {
            return 'pause:' . DecisionFrein::REPONSES_NON_TRAITEES;
        }
        $quota = CourbeChauffe::quotaBoite($this->jourDeChauffe($ws, $boite, $jour));
        if ($humain === Freins::RALENTI) {
            $quota = (int) floor($quota * Seuils::HUMAIN_FACTEUR_RALENTI);
        }

        return $this->depot->envoyesBoite($ws, $boite, $jour) >= $quota ? self::QUOTA_ATTEINT : null;
    }

    public function noter(MessageAEnvoyer $m, DateTimeImmutable $jour): void
    {
        $this->depot->incrementer($m->workspaceId, self::boite($m->expediteur), $jour, $this->resolveur->fournisseurDe($m->destinataire), 'envoyes');
    }

    /** Un message est arrivé en indésirables chez un témoin : pause vers CE fournisseur seulement. */
    public function indesirableChezTemoin(string $ws, string $adresseTemoin, ?DateTimeImmutable $maintenant = null): bool
    {
        $d = Freins::indesirableTemoin($this->resolveur->fournisseurDe($adresseTemoin));

        return $this->declarer($ws, $d, $maintenant ?? new DateTimeImmutable);
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
            $d = Freins::reponsesEnBaisse(
                $f,
                $this->depot->totauxDuJour($ws, $jour, $f),
                $this->depot->fourneesPrecedentes($ws, $f, $jour, Seuils::REPONSE_FOURNEES_COMPAREES),
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

    /** Jour de chauffe EFFECTIF, avec « palier tenu » tant qu'une plainte récente (7 jours) est dans la fenêtre. */
    private function jourDeChauffe(string $ws, string $boite, DateTimeImmutable $jour): int
    {
        $jours = $this->depot->joursAvecEnvoi($ws, $boite, $jour);
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
        if (! $this->depot->ouvrirPause($ws, $d->motif, $d->fournisseur, $maintenant, $d->dureeHeures)) {
            return false;
        }
        $this->socle->journaliser($ws, 'pause_auto', 'freins', ['motif' => $d->motif, 'fournisseur' => $d->fournisseur, 'duree_heures' => $d->dureeHeures]);
        $this->alerte?->envoyer(
            'Envoi en pause' . ($d->fournisseur !== null ? ' vers ' . $d->fournisseur : '') . ' : ' . $d->motif,
            $d->dureeHeures !== null
                ? "Un frein automatique a mis l’envoi en pause pour {$d->dureeHeures} h (elle expire d’elle-même). Vérifier le tableau de bord."
                : 'Un frein automatique a mis l’envoi en pause. Vérifier le tableau de bord, puis lever la pause par envoi:lever-pause.',
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

    /** @return array{rebonds_durs_taux_max: float, rebonds_durs_jour_max: float, homme_mort_jours: float, humain_pause_a: float, reponse_ratio_min: float} */
    private function seuils(string $ws): array
    {
        return [
            'rebonds_durs_taux_max' => Seuils::resserre('rebonds_durs_taux_max', Seuils::REBONDS_DURS_TAUX_MAX, $this->depot->seuil($ws, 'rebonds_durs_taux_max')),
            'rebonds_durs_jour_max' => Seuils::resserre('rebonds_durs_jour_max', Seuils::REBONDS_DURS_JOUR_MAX, $this->depot->seuil($ws, 'rebonds_durs_jour_max')),
            'homme_mort_jours' => Seuils::resserre('homme_mort_jours', Seuils::HOMME_MORT_JOURS, $this->depot->seuil($ws, 'homme_mort_jours')),
            'humain_pause_a' => Seuils::resserre('humain_pause_a', Seuils::HUMAIN_PAUSE_A, $this->depot->seuil($ws, 'humain_pause_a')),
            'reponse_ratio_min' => Seuils::resserre('reponse_ratio_min', Seuils::REPONSE_RATIO_MIN, $this->depot->seuil($ws, 'reponse_ratio_min')),
        ];
    }
}
