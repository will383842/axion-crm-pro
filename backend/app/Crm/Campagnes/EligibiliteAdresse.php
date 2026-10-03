<?php

namespace App\Crm\Campagnes;

use App\Crm\Emails\VerificationEmail;
use App\Crm\Personnes\NatureEmail;
use App\Crm\Sites\QuarantaineSite;
use App\Support\EligibiliteCampagne;

/**
 * POURQUOI UNE ADRESSE NE PART PAS — la règle de #253, écrite UNE fois.
 *
 * `crm:campagne:destinataires` (la liste en fichier) et
 * `ResolveurDestinataires` (l'aperçu d'une audience) décident avec CETTE
 * fonction : deux copies de la règle finiraient par diverger, et l'aperçu
 * annoncerait un chiffre que la liste ne donnerait pas.
 *
 * Une adresse est jugée sur TOUTES ses occurrences (une même boîte portée par
 * plusieurs fiches ou personnes) : une seule qui l'exige suffit à l'écarter.
 * Premier motif rencontré, dans cet ordre :
 *
 *  0. `entreprise_individuelle` l'adresse est rattachée à une fiche
 *                     d'entrepreneur individuel (catégorie juridique INSEE
 *                     commençant par 1, `companies.legal_form`) : jamais
 *                     destinataire d'une campagne, quelle que soit la
 *                     qualité de l'adresse (03/10/2026). Le drapeau est posé
 *                     par l'appelant sur les fiches qu'il a DÉJÀ lues —
 *                     aucune requête de plus, aucun balayage de `companies`.
 *                     Une forme juridique absente, vide ou non codée
 *                     (« SAS » en clair) n'exclut pas : on n'exclut que ce
 *                     qui est su. Segment ou audience PRESSE : le motif de
 *                     PROVENANCE (`AdressePresseFiable` : site deviné,
 *                     journaliste sans accès ou retiré) est jugé et compté
 *                     AVANT celui-ci ; une adresse EI de provenance non
 *                     fiable est donc comptée sous sa provenance — exclue
 *                     de toute façon ;
 *  0 bis. `site_non_verifie` l'adresse vient (ou peut venir) d'un site
 *                     DEVINÉ et non vérifié : quarantaine du lot N5
 *                     (`QuarantaineSite`, 03/10/2026) — jamais envoyée,
 *                     quelle que soit sa vérification. Comme pour l'EI, le
 *                     drapeau est posé par l'appelant sur les fiches et
 *                     personnes qu'il a DÉJÀ lues. Hors segment presse
 *                     seulement : la presse juge déjà la provenance
 *                     (`AdressePresseFiable`, plus fine : une adresse de
 *                     liste presse sur un site deviné y reste fiable) ;
 *  0 ter. `information_tiers_insuffisante` l'adresse est portée par une
 *                     personne apportée par un tiers (canal Axion Partners,
 *                     `contacts_provenances_tiers`) dont la version du texte
 *                     d'information reçu est inconnue ou < 5
 *                     (`ProvenanceTiers`, 03/10/2026). Même patron que le
 *                     motif précédent : le drapeau est posé par l'appelant
 *                     sur les personnes qu'il a DÉJÀ lues (une sous-requête
 *                     indexée par personne, `informationInsuffisanteSql`) ;
 *                     une personne sans provenance tiers n'est pas concernée.
 *                     Ordre convenu avec #311 : EI → `site_non_verifie` →
 *                     ce motif, tous AVANT `invalide` (une adresse exclue
 *                     pour deux raisons est comptée sous un motif visible de
 *                     tous les rôles ; celui-ci n'est servi qu'au owner) ;
 *  1. `invalide`      syntaxe, `email_status` invalid/disposable, ou
 *                     vérification `invalide`/`jetable` ;
 *  2. `non_verifiee`  aucune occurrence vérifiée `valide` par
 *                     `crm:emails:verifier` (#261) ;
 *  3. `personnelle`   messagerie grand public ou adresse marquée personnelle
 *                     (décision D3 : jamais en campagne) ;
 *  4. `deja_informee` seulement si demandé (`--non-informes`) ;
 *  5. `opposition`    `EligibiliteCampagne::peutRecevoir` (opposition ou
 *                     suppression, portée business) — la porte de B15-009.
 *
 * L'exclusion des adresses de cabinet ou de domiciliation partagées
 * (`AdressesPartagees::exclues`, motif `adresse_partagee`) se pose APRÈS,
 * par paquet : c'est une question posée à la base pour toutes les adresses
 * d'un coup.
 *
 * Aucune ligne ici n'envoie quoi que ce soit.
 */
final class EligibiliteAdresse
{
    public const ENTREPRISE_INDIVIDUELLE = 'entreprise_individuelle';

    public const SITE_NON_VERIFIE = QuarantaineSite::MOTIF;

    public const INFORMATION_TIERS_INSUFFISANTE = 'information_tiers_insuffisante';

    public const INVALIDE = 'invalide';

    public const NON_VERIFIEE = 'non_verifiee';

    public const PERSONNELLE = 'personnelle';

    public const DEJA_INFORMEE = 'deja_informee';

    public const OPPOSITION = 'opposition';

    public const ADRESSE_PARTAGEE = 'adresse_partagee';

    /** @var list<string> */
    public const MOTIFS = [
        self::ENTREPRISE_INDIVIDUELLE, self::SITE_NON_VERIFIE, self::INFORMATION_TIERS_INSUFFISANTE, self::INVALIDE, self::NON_VERIFIEE, self::PERSONNELLE,
        self::DEJA_INFORMEE, self::OPPOSITION, self::ADRESSE_PARTAGEE,
    ];

    /**
     * Vrai pour un entrepreneur individuel : catégorie juridique INSEE dont
     * le premier chiffre est 1 (1000 entrepreneur individuel, etc.). Une
     * valeur absente, vide ou qui ne commence pas par 1 (5710 SAS, « SAS »
     * en clair…) n'est PAS une entreprise individuelle : on n'exclut que ce
     * qui est su.
     */
    public static function estEntrepriseIndividuelle(mixed $formeJuridique): bool
    {
        return is_string($formeJuridique) && preg_match('/^1/', trim($formeJuridique)) === 1;
    }

    /**
     * @param  string  $email  adresse NORMALISÉE (minuscules, sans espaces)
     * @param  list<array<string, mixed>>  $occurrences  clés lues : entreprise_individuelle, site_non_verifie, information_tiers_insuffisante, status, verification, perso, deja_informe
     */
    public static function motif(string $email, array $occurrences, bool $nonInformes = false): ?string
    {
        // Une seule occurrence rattachée à un entrepreneur individuel suffit :
        // la même boîte ne part pas « par » une autre fiche.
        if (self::une($occurrences, static fn (array $o): bool => ($o['entreprise_individuelle'] ?? false) === true)) {
            return self::ENTREPRISE_INDIVIDUELLE;
        }
        // Quarantaine (lot N5) : une seule occurrence venue d'un site deviné
        // non vérifié suffit — on ne sait pas à qui est cette boîte.
        if (self::une($occurrences, static fn (array $o): bool => ($o['site_non_verifie'] ?? false) === true)) {
            return self::SITE_NON_VERIFIE;
        }
        // Une personne apportée par un tiers sans information suffisante : la
        // même boîte ne part pas non plus par une autre fiche.
        if (self::une($occurrences, static fn (array $o): bool => ($o['information_tiers_insuffisante'] ?? false) === true)) {
            return self::INFORMATION_TIERS_INSUFFISANTE;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || self::une($occurrences, static fn (array $o): bool => in_array($o['status'] ?? null, ['invalid', 'disposable'], true))
            || self::une($occurrences, static fn (array $o): bool => in_array($o['verification'] ?? null, [VerificationEmail::INVALIDE, VerificationEmail::JETABLE], true))) {
            return self::INVALIDE;
        }
        // Seule une adresse VÉRIFIÉE valide part : jamais une adresse dont on
        // ne sait pas si son domaine reçoit du courrier.
        if (! self::une($occurrences, static fn (array $o): bool => ($o['verification'] ?? null) === VerificationEmail::VALIDE)) {
            return self::NON_VERIFIEE;
        }
        if (NatureEmail::de($email) === 'perso' || self::une($occurrences, static fn (array $o): bool => ($o['perso'] ?? false) === true)) {
            return self::PERSONNELLE;
        }
        if ($nonInformes && self::une($occurrences, static fn (array $o): bool => ($o['deja_informe'] ?? false) === true)) {
            return self::DEJA_INFORMEE;
        }
        if (! EligibiliteCampagne::peutRecevoir($email, 'business')) {
            return self::OPPOSITION;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $occurrences  clés lues : status, verification, perso, deja_informe
     * @param  callable(array<string, mixed>): bool  $test
     */
    private static function une(array $occurrences, callable $test): bool
    {
        foreach ($occurrences as $o) {
            if ($test($o)) {
                return true;
            }
        }

        return false;
    }
}
