<?php

namespace App\Envoi\Gardes;

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\Segments;
use App\Crm\Personnes\NatureEmail;
use App\Support\EligibiliteCampagne;
use App\Socle\Espace\FonctionsEspace;
use App\Support\ListeSuppression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
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

    public const SEGMENT_INCONNU = 'segment_inconnu';

    public const SEGMENT_SENSIBLE = 'segment_sensible_refuse';

    public const DOMAINE_LISTE_NOIRE = 'domaine_envoi_sur_liste_noire';

    public const DATE_FUTURE = 'date_de_collecte_dans_le_futur';

    public const FUSEAU = 'Europe/Paris';

    /**
     * Segments sensibles de la spec 26 § 6.4 : refusés EXPLICITEMENT (jamais
     * ouverts par cette porte, quel que soit le réglage). Comparaison par
     * radical, sur le nom en minuscules.
     *
     * @var list<string>
     */
    public const SEGMENTS_SENSIBLES = ['syndicat', 'sante', 'santé', 'insee', 'roumanie'];

    /** Domaines de messagerie grand public, sous-domaines compris (`mail.yahoo.com`). */
    private static function estWebmail(string $email): bool
    {
        if (NatureEmail::de($email) === 'perso') {
            return true;
        }
        $domaine = mb_strtolower(trim(substr($email, (int) strrpos($email, '@') + 1)));
        $libelles = explode('.', $domaine);
        while (count($libelles) > 2) {
            array_shift($libelles);
            if (NatureEmail::estGrandPublic(implode('.', $libelles))) {
                return true;
            }
        }

        return false;
    }

    /** Jours CALENDAIRES (fuseau Paris, indépendant de l'heure d'été) de $depuis à $jusqua. */
    private static function joursCalendaires(DateTimeInterface $depuis, DateTimeInterface $jusqua): int
    {
        $paris = new DateTimeZone(self::FUSEAU);
        $a = DateTimeImmutable::createFromInterface($depuis)->setTimezone($paris)->setTime(0, 0);
        $b = DateTimeImmutable::createFromInterface($jusqua)->setTimezone($paris)->setTime(0, 0);

        return (int) $a->diff($b)->format('%r%a');
    }

    /** SIREN valide = 9 chiffres ET clé de Luhn correcte (et pas « 000000000 »). */
    private static function sirenValide(?string $siren): bool
    {
        $s = $siren === null ? '' : trim($siren);
        if (preg_match('/^\d{9}$/', $s) !== 1 || $s === '000000000') {
            return false;
        }
        $somme = 0;
        for ($i = 0; $i < 9; $i++) {
            $c = (int) $s[8 - $i];
            if ($i % 2 === 1) {
                $c *= 2;
                if ($c > 9) {
                    $c -= 9;
                }
            }
            $somme += $c;
        }

        return $somme % 10 === 0;
    }

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
        if ($d->premierContact && self::estWebmail($d->email)) {
            return new VerdictEnvoi(self::WEBMAIL_PREMIER_CONTACT);
        }
        if (($m = EligibiliteAdresse::motif(mb_strtolower(trim($d->email)), $d->occurrences, $d->nonInformes)) !== null) {
            return new VerdictEnvoi($m);
        }
        if (EligibiliteCampagne::peutRecevoir($d->email, 'business') === false) {
            return new VerdictEnvoi(EligibiliteAdresse::OPPOSITION);
        }
        if (self::verdictRecent($d, $maintenant) === false) {
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
        if (($m = self::garderSegment($d)) !== null) {
            return new VerdictEnvoi($m, $alertes);
        }

        return new VerdictEnvoi(null, $alertes);
    }

    /**
     * FAIL CLOSED (spec 26 § 6.4) : segment absent/vide = refus ; segment
     * sensible = refus explicite ; segment non listé = refus ; segment connu =
     * ouvert SEULEMENT si le réglage PAR ESPACE (`fonctions_espace`) l'ouvre.
     * Ni `crm.segments_ouverts` ni `Segments::OUVERTS_PAR_DEFAUT` ne comptent ici.
     */
    private static function garderSegment(DemandeEnvoi $d): ?string
    {
        $segment = $d->segment === null ? '' : mb_strtolower(trim($d->segment));
        if ($segment === '') {
            return self::SEGMENT_INCONNU;
        }
        foreach (self::SEGMENTS_SENSIBLES as $radical) {
            if (str_contains($segment, $radical)) {
                return self::SEGMENT_SENSIBLE;
            }
        }
        if (in_array($segment, Segments::CONNUS, true) === false) {
            return self::SEGMENT_INCONNU;
        }

        return in_array($segment, FonctionsEspace::segmentsOuverts($d->workspaceId), true) ? null : self::SEGMENT_FERME;
    }

    private static function garderPays(DemandeEnvoi $d): ?string
    {
        $pays = $d->paysOrganisation === null ? '' : strtoupper(trim($d->paysOrganisation));
        if ($pays !== '') {
            return $pays === 'FR' ? null : self::PAYS_HORS_FRANCE;
        }

        // Pays non renseigné : seul un SIREN valide (9 chiffres + clé de Luhn) prouve la France.
        return self::sirenValide($d->siren) ? null : self::PAYS_INCONNU;
    }

    private static function verdictRecent(DemandeEnvoi $d, DateTimeImmutable $maintenant): bool
    {
        $ligne = DB::table('envoi_verdicts_adresse')
            ->where('workspace_id', $d->workspaceId)
            ->where('empreinte_adresse', ListeSuppression::empreinte(mb_strtolower(trim($d->email))))
            ->orderByDesc('verifie_le')
            ->first();

        // Le DERNIER verdict fait foi : un « invalide » récent annule un « valide » plus ancien.
        if ($ligne === null || $ligne->verdict !== 'valide') {
            return false;
        }

        // Jours CALENDAIRES de Paris : ni décalage d'heure d'été, ni fuseau de l'appelant.
        // Un verdict daté du FUTUR n'est pas une preuve : refusé.
        $verifieLe = new DateTimeImmutable((string) $ligne->verifie_le);
        if ($verifieLe->getTimestamp() > $maintenant->getTimestamp()) {
            return false;
        }

        return self::joursCalendaires($verifieLe, $maintenant) < self::VERDICT_MAX_JOURS;
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

        if (filter_var($dom->liste_noire ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return self::DOMAINE_LISTE_NOIRE;
        }

        $age = self::joursCalendaires(new DateTimeImmutable((string) $dom->achete_le, new DateTimeZone(self::FUSEAU)), $maintenant);
        if ($age < self::DOMAINE_AGE_MIN_JOURS) {
            return self::DOMAINE_TROP_JEUNE;
        }

        $paris = new DateTimeZone(self::FUSEAU);
        $jour = $maintenant->setTimezone($paris)->format('Y-m-d');
        foreach (['dmarc_verifie_le', 'spf_verifie_le', 'dkim_verifie_le'] as $col) {
            if ($dom->{$col} === null
                || (new DateTimeImmutable((string) $dom->{$col}))->setTimezone($paris)->format('Y-m-d') !== $jour) {
                return self::DOMAINE_DNS_NON_VERIFIE;
            }
        }

        return null;
    }

    /** @param  list<string>  $alertes */
    private static function garderArt14(DemandeEnvoi $d, DateTimeImmutable $maintenant, array &$alertes): ?string
    {
        if ($d->donneesCollecteesLe !== null && $d->donneesCollecteesLe->getTimestamp() > $maintenant->getTimestamp()) {
            return self::DATE_FUTURE;
        }
        // Une « première information » datée du FUTUR n'a pas eu lieu : elle ne vaut pas information donnée.
        if ($d->premiereInfoLe !== null && $d->premiereInfoLe->getTimestamp() <= $maintenant->getTimestamp()) {
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
