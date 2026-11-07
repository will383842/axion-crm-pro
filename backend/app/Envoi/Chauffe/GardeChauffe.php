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
 * planifiable) décide et ouvre les pauses. Les alertes passent par Socle/Alertes
 * (alertes INTERNES seulement) : jamais depuis une boîte de prospection.
 */
final class GardeChauffe
{
    public const QUOTA_ATTEINT = 'quota_de_chauffe_atteint';

    public function __construct(
        private readonly DepotChauffe $depot,
        private readonly DepotSocle $socle,
        private readonly ?Alerte $alerte = null,
    ) {}

    /** Motif de report, ou null si l'envoi peut partir. */
    public function refus(MessageAEnvoyer $m, DateTimeImmutable $jour, DateTimeImmutable $maintenant): ?string
    {
        $ws = $m->workspaceId;
        $fournisseur = Fournisseur::de($m->destinataire);
        foreach ($this->depot->pausesActives($ws) as $p) {
            if ($p->portee === 'global' || $p->fournisseur === $fournisseur) {
                return 'pause:' . $p->motif;
            }
        }
        $s = $this->seuils($ws);
        $humain = Freins::freinHumain($this->depot->reponsesEnAttente($ws), (int) $s['humain_pause_a']);
        if ($humain === Freins::PAUSE) {
            return 'pause:' . DecisionFrein::REPONSES_NON_TRAITEES;
        }
        if (Freins::hommeMort($this->depot->derniereValidation($ws), $maintenant, (int) $s['homme_mort_jours']) !== null) {
            return 'pause:' . DecisionFrein::HOMME_MORT;
        }
        $debut = $this->depot->premierJourBoite($ws, $m->expediteur) ?? $jour;
        $quota = CourbeChauffe::quotaBoite(CourbeChauffe::jourDeChauffe($debut, $jour));
        if ($humain === Freins::RALENTI) {
            $quota = (int) floor($quota * Seuils::HUMAIN_FACTEUR_RALENTI);
        }

        return $this->depot->envoyesBoite($ws, $m->expediteur, $jour) >= $quota ? self::QUOTA_ATTEINT : null;
    }

    public function noter(MessageAEnvoyer $m, DateTimeImmutable $jour): void
    {
        $this->depot->incrementer($m->workspaceId, $m->expediteur, $jour, Fournisseur::de($m->destinataire), 'envoyes');
    }

    /** Un message est arrivé en indésirables chez un témoin : pause vers CE fournisseur seulement. */
    public function indesirableChezTemoin(string $ws, string $adresseTemoin): bool
    {
        $d = Freins::indesirableTemoin(Fournisseur::de($adresseTemoin));

        return $this->declarer($ws, $d);
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
        $t = $this->depot->totauxDuJour($ws, $jour);
        $decisions = array_filter([
            Freins::rebondsDurs($t['envoyes'], $t['rebonds_durs'], $s['rebonds_durs_taux_max']),
            Freins::plaintes($t['envoyes'], $t['plaintes'], $s['plaintes_taux_max']),
            Freins::hommeMort($this->depot->derniereValidation($ws), $maintenant, (int) $s['homme_mort_jours']),
        ]);
        if (Freins::freinHumain($this->depot->reponsesEnAttente($ws), (int) $s['humain_pause_a']) === Freins::PAUSE) {
            $decisions[] = new DecisionFrein(DecisionFrein::REPONSES_NON_TRAITEES);
        }
        foreach ([Fournisseur::GMAIL, Fournisseur::MICROSOFT] as $f) {
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

        return array_values(array_filter($decisions, fn (DecisionFrein $d): bool => $this->declarer($ws, $d)));
    }

    private function declarer(string $ws, DecisionFrein $d): bool
    {
        if (! $this->depot->ouvrirPause($ws, $d->motif, $d->fournisseur)) {
            return false;
        }
        $this->socle->journaliser($ws, 'pause_auto', 'freins', ['motif' => $d->motif, 'fournisseur' => $d->fournisseur]);
        $this->alerte?->envoyer(
            'Envoi en pause' . ($d->fournisseur !== null ? ' vers ' . $d->fournisseur : '') . ' : ' . $d->motif,
            'Un frein automatique a mis l’envoi en pause. Vérifier le tableau de bord, puis lever la pause par envoi:lever-pause.',
            'envoi',
            'critique',
        );

        return true;
    }

    /** @return array{rebonds_durs_taux_max: float, plaintes_taux_max: float, homme_mort_jours: float, humain_pause_a: float, reponse_ratio_min: float} */
    private function seuils(string $ws): array
    {
        return [
            'rebonds_durs_taux_max' => Seuils::resserre('rebonds_durs_taux_max', Seuils::REBONDS_DURS_TAUX_MAX, $this->depot->seuil($ws, 'rebonds_durs_taux_max')),
            'plaintes_taux_max' => Seuils::resserre('plaintes_taux_max', Seuils::PLAINTES_TAUX_MAX, $this->depot->seuil($ws, 'plaintes_taux_max')),
            'homme_mort_jours' => Seuils::resserre('homme_mort_jours', Seuils::HOMME_MORT_JOURS, $this->depot->seuil($ws, 'homme_mort_jours')),
            'humain_pause_a' => Seuils::resserre('humain_pause_a', Seuils::HUMAIN_PAUSE_A, $this->depot->seuil($ws, 'humain_pause_a')),
            'reponse_ratio_min' => Seuils::resserre('reponse_ratio_min', Seuils::REPONSE_RATIO_MIN, $this->depot->seuil($ws, 'reponse_ratio_min')),
        ];
    }
}
