<?php

namespace App\Crm\Emails;

use App\Crm\Emails\Dns\ResultatDns;
use App\Support\ListeSuppression;

/**
 * LA FICHE DE VÉRIFICATION D'UNE ADRESSE — une définition, lue et écrite ici.
 *
 * ── Le vocabulaire (celui de l'import des fédérations, PR #255) ────────────
 *
 * L'import des fédérations range déjà, pour chaque adresse qu'il pose,
 * `{type, domaine_verifie, verifie_le, source}` — dans
 * `signals.contact_channels.details[adresse]`, dans
 * `signals.email_generic_verification`, et dans `contacts.metadata`
 * (`email_type`, `domaine_verifie`, `domaine_verifie_le`). La vérification
 * écrit AUX MÊMES ENDROITS, avec les mêmes clés, et y ajoute :
 *
 *  - `statut`    `valide` | `invalide` | `jetable` ;
 *  - `motif`     pourquoi : `mx`, `a` (le domaine reçoit), `mx_nul`,
 *                `sans_courrier`, `inexistant` (il ne reçoit rien), `syntaxe`,
 *                `jetable` ;
 *  - `webmail`   messagerie grand public — MARQUÉE, jamais rejetée ;
 *  - `fiches`    nombre de fiches (organisations et personnes de l'espace) qui
 *                portent l'adresse : > 1, elle est PARTAGÉE ;
 *  - `empreinte` SHA-256 de l'adresse normalisée (`ListeSuppression::empreinte`) :
 *                lie la fiche de vérification à l'adresse qu'elle décrit. Si
 *                l'adresse change, la vérification ne vaut plus — et on le
 *                voit sans garder l'adresse en clair une seconde fois ;
 *  - `verifie_par` `crm:emails:verifier`. La clé `source` de l'import (d'où
 *                vient l'adresse) n'est JAMAIS réécrite : la provenance reste.
 *
 * Une adresse n'est JAMAIS supprimée ni réécrite : `invalide`, elle reste sur
 * sa fiche, lisible pour audit, avec son motif et sa date.
 *
 * ── Qui la lit ──────────────────────────────────────────────────────────────
 *
 * `crm:campagne:destinataires` ne retient une adresse que si
 * `statutDe()` rend `valide` (et, pour une personne, si son `email_status`
 * n'est ni `invalid` ni `disposable` — un rebond dur y écrit `invalid`).
 */
final class VerificationEmail
{
    public const SOURCE = 'crm:emails:verifier';

    public const VALIDE = 'valide';

    public const INVALIDE = 'invalide';

    public const JETABLE = 'jetable';

    /** @var list<string> */
    public const STATUTS = [self::VALIDE, self::INVALIDE, self::JETABLE];

    public const MOTIF_SYNTAXE = 'syntaxe';

    public const MOTIF_JETABLE = 'jetable';

    /** Les clés dont un changement justifie une réécriture de la fiche. */
    private const CLES = ['type', 'domaine_verifie', 'verifie_le', 'verifie_par', 'statut', 'motif', 'webmail', 'fiches', 'empreinte'];

    /**
     * Le verdict d'une adresse, ou null si on ne peut pas conclure (DNS
     * indéterminé) — auquel cas RIEN n'est écrit.
     *
     * @return array{statut: string, motif: string, domaine_verifie: bool}|null
     */
    public static function conclure(string $email, ?ResultatDns $dns): ?array
    {
        if (! QualificationEmail::syntaxeValide($email)) {
            return ['statut' => self::INVALIDE, 'motif' => self::MOTIF_SYNTAXE, 'domaine_verifie' => false];
        }
        $domaine = (string) QualificationEmail::domaine($email);
        if (QualificationEmail::estJetable($domaine)) {
            return ['statut' => self::JETABLE, 'motif' => self::MOTIF_JETABLE, 'domaine_verifie' => false];
        }
        if ($dns === null || $dns->recoit() === null) {
            return null;
        }

        return $dns->recoit()
            ? ['statut' => self::VALIDE, 'motif' => $dns->verdict, 'domaine_verifie' => true]
            : ['statut' => self::INVALIDE, 'motif' => $dns->verdict, 'domaine_verifie' => false];
    }

    /** Faut-il interroger le DNS pour cette adresse ? (syntaxe valide, domaine non jetable) */
    public static function demandeLeDns(string $email): bool
    {
        return QualificationEmail::syntaxeValide($email)
            && ! QualificationEmail::estJetable((string) QualificationEmail::domaine($email));
    }

    /**
     * Le statut qu'a posé CETTE vérification pour CETTE adresse, ou null :
     * jamais vérifiée, vérifiée par autre chose, ou pour une autre adresse.
     */
    public static function statutDe(mixed $fiche, string $email): ?string
    {
        if (! is_array($fiche) || ($fiche['verifie_par'] ?? null) !== self::SOURCE) {
            return null;
        }
        if (($fiche['empreinte'] ?? null) !== ListeSuppression::empreinte($email)) {
            return null;
        }
        $statut = $fiche['statut'] ?? null;

        return is_string($statut) && in_array($statut, self::STATUTS, true) ? $statut : null;
    }

    /**
     * La fiche à écrire : l'ancienne (ses clés inconnues sont gardées), et le
     * TYPE déjà connu garde la priorité — celui de l'import vient du fichier
     * source, qui en sait plus que la règle.
     *
     * @param  array<string, mixed>  $calcul  type, domaine_verifie, verifie_le, statut, motif, webmail, fiches, empreinte
     * @return array<string, mixed>
     */
    public static function fusionner(mixed $ancienne, array $calcul): array
    {
        $ancienne = is_array($ancienne) ? $ancienne : [];
        $type = $ancienne['type'] ?? null;
        $nouvelle = array_merge($ancienne, $calcul, ['verifie_par' => self::SOURCE]);
        if (in_array($type, ['generique', 'nominatif'], true)) {
            $nouvelle['type'] = $type;
        }

        return $nouvelle;
    }

    /** @param  array<string, mixed>  $nouvelle */
    public static function change(mixed $ancienne, array $nouvelle): bool
    {
        $ancienne = is_array($ancienne) ? $ancienne : [];
        foreach (self::CLES as $cle) {
            if (($ancienne[$cle] ?? null) !== ($nouvelle[$cle] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La date à écrire : celle de la résolution DNS du domaine ; pour un
     * verdict sans DNS (syntaxe, jetable), la date déjà écrite si le verdict
     * n'a pas bougé (sinon chaque passage réécrirait la fiche pour rien), et
     * aujourd'hui sinon.
     *
     * @param  array{statut: string, motif: string}  $verdict
     */
    public static function date(mixed $ancienne, array $verdict, ?string $dateDns, string $aujourdhui): string
    {
        if ($dateDns !== null) {
            return $dateDns;
        }
        if (is_array($ancienne) && ($ancienne['verifie_par'] ?? null) === self::SOURCE
            && ($ancienne['statut'] ?? null) === $verdict['statut'] && ($ancienne['motif'] ?? null) === $verdict['motif']
            && is_string($ancienne['verifie_le'] ?? null)) {
            return $ancienne['verifie_le'];
        }

        return $aujourdhui;
    }

    /**
     * Le `contacts.email_status` après vérification.
     *
     *  - `invalide` / `jetable` l'emportent toujours : le domaine ne reçoit
     *    rien, quoi qu'ait dit un autre outil.
     *  - `valide` ne dit que « le DOMAINE reçoit » : il ne remplace qu'un statut
     *    vide ou `unknown` — ou un `invalid`/`disposable` que CETTE vérification
     *    avait elle-même posé (le domaine a été rétabli). Jamais un `invalid`
     *    venu d'ailleurs : un rebond dur (`crm:campagne:retours`) ou un
     *    fournisseur qui a constaté que la BOÎTE n'existe pas en savent plus
     *    que le DNS.
     */
    public static function statutContact(?string $actuel, string $statut, mixed $ancienne, string $email): string
    {
        if ($statut === self::INVALIDE) {
            return 'invalid';
        }
        if ($statut === self::JETABLE) {
            return 'disposable';
        }
        if ($actuel === null || $actuel === '' || $actuel === 'unknown') {
            return 'valid';
        }
        $avant = self::statutDe($ancienne, $email);
        if (($actuel === 'invalid' && $avant === self::INVALIDE) || ($actuel === 'disposable' && $avant === self::JETABLE)) {
            return 'valid';
        }

        return $actuel;
    }
}
