<?php

namespace App\Crm\Campagnes;

use App\Crm\Emails\VerificationEmail;
use App\Crm\Personnes\NatureEmail;
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

    public const INVALIDE = 'invalide';

    public const NON_VERIFIEE = 'non_verifiee';

    public const PERSONNELLE = 'personnelle';

    public const DEJA_INFORMEE = 'deja_informee';

    public const OPPOSITION = 'opposition';

    public const ADRESSE_PARTAGEE = 'adresse_partagee';

    /** @var list<string> */
    public const MOTIFS = [
        self::ENTREPRISE_INDIVIDUELLE, self::INVALIDE, self::NON_VERIFIEE, self::PERSONNELLE,
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
     * @param  list<array<string, mixed>>  $occurrences  clés lues : entreprise_individuelle, status, verification, perso, deja_informe
     */
    public static function motif(string $email, array $occurrences, bool $nonInformes = false): ?string
    {
        // Une seule occurrence rattachée à un entrepreneur individuel suffit :
        // la même boîte ne part pas « par » une autre fiche.
        if (self::une($occurrences, static fn (array $o): bool => ($o['entreprise_individuelle'] ?? false) === true)) {
            return self::ENTREPRISE_INDIVIDUELLE;
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
