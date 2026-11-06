<?php

namespace App\Envoi\Gardes;

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\Segments;
use App\Crm\Personnes\NatureEmail;
use App\Support\EligibiliteCampagne;
use App\Support\ListeSuppression;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * PORTE UNIQUE D'ENVOI (envoi P0, lot L3).
 *
 * Tout envoi passe ici : au FIGEMENT de la liste, puis à nouveau MOINS DE 15
 * MINUTES avant l'injection (`AGE_MAX_INJECTION_MINUTES`). Une liste est une
 * photo ; la porte se repose au dernier moment. `verifier()` reçoit l'heure
 * courante pour rester testable. Elle ne fait que LIRE : aucune écriture,
 * aucun effacement, aucune mesure d'ouverture.
 *
 * Elle enveloppe `EligibiliteCampagne::peutRecevoir` et
 * `EligibiliteAdresse::motif` (appelés, jamais modifiés) et ajoute, dans cet
 * ordre, les gardes ci-dessous. Premier refus rencontré = motif rendu.
 */
final class EnvoiAutorise
{
    public const AGE_MAX_INJECTION_MINUTES = 15;

    public const VERDICT_MAX_JOURS = 30;      // < 30 jours : 29 passe, 30 et 31 refusent

    public const DOMAINE_AGE_MIN_JOURS = 21;  // ≥ 21 jours

    public const ART14_ALERTE_JOURS = 21;

    public const ART14_LIMITE_JOURS = 30;

    public const PAYS_HORS_FRANCE = 'pays_hors_france';

    public const PAYS_INCONNU = 'pays_inconnu';

    public const WEBMAIL_PREMIER_CONTACT = 'webmail_premier_contact';

    public const VERDICT_ABSENT = 'verdict_adresse_absent_ou_perime';

    public const DOMAINE_INCONNU = 'domaine_envoi_inconnu';

    public const DOMAINE_TROP_JEUNE = 'domaine_envoi_trop_jeune';

    public const DOMAINE_DNS_NON_VERIFIE = 'domaine_envoi_dns_non_verifie_ce_jour';

    public const ART14_DEPASSE = 'art14_information_non_donnee_j30';

    public const ART14_ALERTE = 'art14_alerte_j21';

    public const ART14_DATE_INCONNUE = 'art14_date_de_collecte_inconnue';

    public const ENTITE_ABSENTE = 'entite_legale_expeditrice_absente';

    public const SEGMENT_FERME = 'segment_ferme';

    /** Fiche prête à injecter : la photo du figement ne doit pas dater de plus de 15 minutes. */
    public static function photoEncoreValable(DateTimeInterface $figeeLe, DateTimeInterface $maintenant): bool
    {
        return ($maintenant->getTimestamp() - $figeeLe->getTimestamp()) < self::AGE_MAX_INJECTION_MINUTES * 60
            && $maintenant->getTimestamp() >= $figeeLe->getTimestamp();
    }

    public static function verifier(DemandeEnvoi $d, ?DateTimeInterface $maintenant = null): VerdictEnvoi
    {
        $maintenant = DateTimeImmutable::createFromInterface($maintenant ?? new DateTimeImmutable);
        $alertes = [];

        if (($m = self::garderPays($d)) !== null) {
            return new VerdictEnvoi($m);
        }
        if ($d->premierContact && NatureEmail::de($d->email) === 'perso') {
            return new VerdictEnvoi(self::WEBMAIL_PREMIER_CONTACT);
        }
        if (($m = EligibiliteAdresse::motif(mb_strtolower(trim($d->email)), $d->occurrences, $d->nonInformes)) !== null) {
            return new VerdictEnvoi($m);
        }
        if (! EligibiliteCampagne::peutRecevoir($d->email, 'business')) {
            return new VerdictEnvoi(EligibiliteAdresse::OPPOSITION);
        }
        if (! self::verdictRecent($d, $maintenant)) {
            return new VerdictEnvoi(self::VERDICT_ABSENT);
        }
        if (($m = self::garderDomaine($d, $maintenant)) !== null) {
            return new VerdictEnvoi($m);
        }
        if (($m = self::garderArt14($d, $maintenant, $alertes)) !== null) {
            return new VerdictEnvoi($m, $alertes);
        }
        if ($d->entiteLegaleExpeditrice === null || trim($d->entiteLegaleExpeditrice) === '') {
            return new VerdictEnvoi(self::ENTITE_ABSENTE, $alertes);
        }
        // Segments sensibles : fermés par défaut ; un segment inconnu ou non ouvert est refusé.
        if ($d->segment !== null && ! Segments::ouvert($d->segment, $d->workspaceId)) {
            return new VerdictEnvoi(self::SEGMENT_FERME, $alertes);
        }

        return new VerdictEnvoi(null, $alertes);
    }

    private static function garderPays(DemandeEnvoi $d): ?string
    {
        $pays = $d->paysOrganisation === null ? '' : strtoupper(trim($d->paysOrganisation));
        if ($pays !== '') {
            return $pays === 'FR' ? null : self::PAYS_HORS_FRANCE;
        }

        // Pays non renseigné : seul un SIREN valide (9 chiffres) prouve la France.
        return $d->siren !== null && preg_match('/^\d{9}$/', trim($d->siren)) === 1 ? null : self::PAYS_INCONNU;
    }

    private static function verdictRecent(DemandeEnvoi $d, DateTimeImmutable $maintenant): bool
    {
        $limite = $maintenant->modify('-' . self::VERDICT_MAX_JOURS . ' days');
        $ligne = DB::table('envoi_verdicts_adresse')
            ->where('workspace_id', $d->workspaceId)
            ->where('empreinte_adresse', ListeSuppression::empreinte(mb_strtolower(trim($d->email))))
            ->orderByDesc('verifie_le')
            ->first();

        // Le DERNIER verdict fait foi : un « invalide » récent annule un « valide » plus ancien.
        if ($ligne === null || $ligne->verdict !== 'valide') {
            return false;
        }

        return (new DateTimeImmutable((string) $ligne->verifie_le))->getTimestamp() > $limite->getTimestamp();
    }

    private static function garderDomaine(DemandeEnvoi $d, DateTimeImmutable $maintenant): ?string
    {
        $dom = DB::table('envoi_domaines')
            ->where('workspace_id', $d->workspaceId)
            ->where('nom', mb_strtolower(trim($d->domaineExpediteur)))
            ->first();
        if ($dom === null) {
            return self::DOMAINE_INCONNU;
        }

        $age = (int) (new DateTimeImmutable((string) $dom->achete_le, $maintenant->getTimezone()))->setTime(0, 0)->diff($maintenant->setTime(0, 0))->format('%r%a');
        if ($age < self::DOMAINE_AGE_MIN_JOURS) {
            return self::DOMAINE_TROP_JEUNE;
        }

        $jour = $maintenant->format('Y-m-d');
        foreach (['dmarc_verifie_le', 'spf_verifie_le', 'dkim_verifie_le'] as $col) {
            if ($dom->{$col} === null
                || (new DateTimeImmutable((string) $dom->{$col}))->setTimezone($maintenant->getTimezone())->format('Y-m-d') !== $jour) {
                return self::DOMAINE_DNS_NON_VERIFIE;
            }
        }

        return null;
    }

    /** @param  list<string>  $alertes */
    private static function garderArt14(DemandeEnvoi $d, DateTimeImmutable $maintenant, array &$alertes): ?string
    {
        if ($d->premiereInfoLe !== null) {
            return null;
        }
        if ($d->donneesCollecteesLe === null) {
            return self::ART14_DATE_INCONNUE;
        }
        $debut = DateTimeImmutable::createFromInterface($d->donneesCollecteesLe)->setTimezone($maintenant->getTimezone())->setTime(0, 0);
        $jours = (int) $debut->diff($maintenant->setTime(0, 0))->format('%r%a');
        if ($jours >= self::ART14_LIMITE_JOURS) {
            return self::ART14_DEPASSE;
        }
        if ($jours >= self::ART14_ALERTE_JOURS) {
            $alertes[] = self::ART14_ALERTE;
        }

        return null;
    }
}
