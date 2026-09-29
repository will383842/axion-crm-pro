<?php

namespace App\Crm\Emails;

use App\Crm\Emails\Dns\ResultatDns;
use RuntimeException;

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
 *  - `empreinte` HMAC-SHA256 de l'adresse normalisée, avec une clé SECRÈTE
 *                dérivée de `APP_KEY` (`empreinte()`) : lie la fiche de
 *                vérification à l'adresse qu'elle décrit. Si l'adresse change,
 *                la vérification ne vaut plus — et on le voit sans garder
 *                l'adresse en clair une seconde fois. 🔴 PAS un SHA-256 nu :
 *                celui-là se recalcule à partir d'une adresse devinée, et
 *                servait d'oracle à un compte qui ne voit les adresses que
 *                masquées (relecture S1). Elle ne sort d'ailleurs jamais par
 *                l'API pour qui n'a pas `contacts.view_pii`
 *                (`MasquageCoordonnees`). Changer `APP_KEY` rend toutes les
 *                vérifications « non vérifiées » : il suffit de relancer ;
 *  - `email_status_avant` / `email_status_pose` (personnes seulement) : le
 *                statut d'AVANT que la vérification ne le change, et celui
 *                qu'ELLE a posé — voir `statutContact()` ;
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
 *
 * ── Le sens de `valid` (relecture E3, décision documentée) ──────────────────
 *
 * `MxEmailValidator` rend `risky` pour un webmail et `role` pour une adresse
 * de rôle. Ici, `email_status` ne dit que la DÉLIVRABILITÉ du domaine : un
 * webmail qui reçoit est `valid`, marqué `webmail: true` dans la fiche de
 * vérification — `risky` n'existe d'ailleurs pas dans la contrainte CHECK de
 * `contacts.email_status`. Le caractère générique ou nominatif est le `type`,
 * pas le statut : la vérification ne pose jamais `role` (elle ne le retire
 * jamais non plus). La politique d'envoi (pas d'adresse perso en campagne,
 * décision D3) reste celle de la liste de campagne, qui lit `NatureEmail`.
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
    private const CLES = ['type', 'domaine_verifie', 'verifie_le', 'verifie_par', 'statut', 'motif', 'webmail', 'fiches', 'empreinte',
        'email_status_avant', 'email_status_pose'];

    /** Statuts qui disent « on ne sait rien » : la vérification peut les remplacer. */
    private const STATUTS_VIDES = [null, '', 'unknown'];

    /**
     * L'empreinte d'une adresse : HMAC-SHA256, clé dérivée de `APP_KEY` (jamais
     * stockée en base, jamais dans une sauvegarde). Domaine séparé
     * (`crm:emails:verifier|`) : elle ne se confond avec aucun autre HMAC.
     */
    public static function empreinte(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), self::cle());
    }

    private static function cle(): string
    {
        $cle = config('app.key');
        if (! is_string($cle) || trim($cle) === '') {
            throw new RuntimeException('APP_KEY absente : aucune empreinte de vérification sans clé secrète.');
        }

        return hash_hmac('sha256', 'crm:emails:verifier|empreinte', $cle, true);
    }

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
        $empreinte = $fiche['empreinte'] ?? null;
        if (! is_string($empreinte) || ! hash_equals(self::empreinte($email), $empreinte)) {
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
     * Le `contacts.email_status` après vérification, et ce qu'il faut en
     * RETENIR pour pouvoir le défaire (relecture E1).
     *
     * Le principe : la vérification ne sait que « le domaine reçoit / ne
     * reçoit pas ». Quand elle DÉGRADE un statut (`invalid`, `disposable`),
     * elle note le statut d'AVANT (`avant`) et celui qu'elle a posé (`pose`).
     * Quand le domaine revient, elle ne rétablit QUE ce qu'elle avait elle-même
     * changé, et le rétablit à sa valeur d'avant :
     *
     *  - un `invalid` venu d'ailleurs (rebond dur de `crm:campagne:retours`,
     *    fournisseur qui a constaté que la BOÎTE n'existe pas) n'est jamais
     *    « posé par nous » — même si une vérification pendant une panne du
     *    domaine a conclu `invalide` par-dessus : il ne redevient JAMAIS `valid` ;
     *  - `catchall` et `role`, dégradés pendant une panne, reviennent tels
     *    quels — jamais `valid` à leur place ;
     *  - un statut vide ou `unknown` devient `valid` (posé par nous).
     *
     * « Posé par nous » n'est cru que si le statut ACTUEL est encore celui que
     * nous avions posé : si quelqu'un l'a changé depuis, c'est lui qui a raison.
     *
     * @return array{statut: ?string, avant: ?string, pose: ?string}
     */
    public static function statutContact(?string $actuel, string $statut, mixed $ancienne, string $email): array
    {
        $nous = self::statutDe($ancienne, $email) !== null
            && is_array($ancienne)
            && is_string($ancienne['email_status_pose'] ?? null)
            && $ancienne['email_status_pose'] === $actuel;
        // Le statut « des autres » : celui d'avant notre changement, s'il est de nous.
        $autres = $nous ? (is_string($ancienne['email_status_avant'] ?? null) ? $ancienne['email_status_avant'] : null) : $actuel;

        if ($statut === self::VALIDE) {
            if (in_array($autres, self::STATUTS_VIDES, true)) {
                return ['statut' => 'valid', 'avant' => $autres, 'pose' => 'valid'];
            }

            // Le statut des autres, rétabli tel quel — `invalid` compris.
            return ['statut' => $autres, 'avant' => null, 'pose' => null];
        }

        $cible = $statut === self::JETABLE ? 'disposable' : 'invalid';
        if ($autres === $cible) {
            // Déjà à cette valeur, posée par un autre : ce n'est pas la nôtre.
            return ['statut' => $autres, 'avant' => null, 'pose' => null];
        }

        return ['statut' => $cible, 'avant' => $autres, 'pose' => $cible];
    }
}
