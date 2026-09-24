<?php

namespace App\Crm\Personnes;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * RÈGLES DE LECTURE des personnes et de leurs abonnements, écrites UNE fois
 * (lot L4-C).
 *
 * ── `eligiblesALaDiffusion()` : la seule définition de « qui peut recevoir la
 * lettre » ────────────────────────────────────────────────────────────────────
 *
 * Aucun envoi n'existe (décision de Will du 2026-09-24 : l'outil d'envoi
 * viendra plus tard, et il sera alimenté par le SITE, seul porteur de la
 * preuve du consentement). Cette requête existe pour que le jour où un
 * segment du CRM se branche sur une liste, il n'y ait qu'UNE règle à lire et
 * qu'elle soit déjà testée :
 *
 *   - abonnement `lettre` au statut `abonne` ;
 *   - AUCUNE opposition générale (`opt_out` `business`) ;
 *   - AUCUNE suppression technique DURE (rebond dur, plainte).
 *
 * Un demandeur du guide qui n'a pas coché la case n'a pas d'abonnement : il
 * n'est JAMAIS éligible, par construction.
 */
final class Abonnements
{
    /** Raisons de suppression qui interdisent tout envoi. */
    public const SUPPRESSIONS_BLOQUANTES = ['hard_bounce', 'complaint'];

    public static function eligiblesALaDiffusion(string $workspaceId, string $canal = 'lettre'): Builder
    {
        $requete = DB::table('personnes')
            ->join('abonnements', 'abonnements.personne_id', '=', 'personnes.id')
            ->where('personnes.workspace_id', $workspaceId)
            ->where('abonnements.canal', $canal)
            ->where('abonnements.statut', 'abonne')
            ->whereNotNull('personnes.email_hash')
            ->select('personnes.*');

        return self::exclureOpposees($requete);
    }

    /**
     * PROSPECTION INDIVIDUELLE AUTORISÉE ? La règle, écrite UNE fois (lot L4-C,
     * relecture RGPD) : sans consentement, la CNIL n'admet la prospection que
     * d'un PROFESSIONNEL (L.34-5 CPCE).
     *
     *   - adresse `pro` → oui (intérêt légitime B2B, objet lié à la profession) ;
     *   - adresse `perso` → seulement si elle est ABONNÉE à la lettre (elle a
     *     coché la case : consentement) ;
     *   - adresse `inconnue` (aucune adresse connue, p. ex. une fiche née d'un
     *     désabonnement) → non.
     *
     * Les oppositions et adresses mortes se retirent en plus, par
     * `exclureOpposees()`. Jumelle SQL : `limiterAuxProspectables()`.
     */
    public static function prospectionAutorisee(?string $nature, ?string $statutLettre): bool
    {
        return $nature === 'pro' || ($nature === 'perso' && $statutLettre === 'abonne');
    }

    /**
     * Jumelle SQL de `prospectionAutorisee()`. La requête doit joindre
     * `abonnements` (canal `lettre`) sous son nom de table.
     */
    public static function limiterAuxProspectables(Builder $requete): Builder
    {
        return $requete->where(function (Builder $q): void {
            $q->where('personnes.email_nature', 'pro')
                ->orWhere(function (Builder $perso): void {
                    $perso->where('personnes.email_nature', 'perso')
                        ->where('abonnements.statut', 'abonne');
                });
        });
    }

    /**
     * Retire d'une requête sur `personnes` celles qui se sont opposées à toute
     * prospection ou dont l'adresse est techniquement morte. Sert à l'export de
     * la console comme à l'éligibilité : UNE seule écriture de la règle.
     */
    public static function exclureOpposees(Builder $requete): Builder
    {
        return $requete
            ->whereNotExists(function (Builder $sous): void {
                $sous->select(DB::raw('1'))
                    ->from('opt_out')
                    ->whereColumn('opt_out.email_hash', 'personnes.email_hash')
                    ->where('opt_out.scope', 'business');
            })
            ->whereNotExists(function (Builder $sous): void {
                $sous->select(DB::raw('1'))
                    ->from('email_suppressions')
                    ->whereColumn('email_suppressions.email_hash', 'personnes.email_hash')
                    ->where('email_suppressions.scope', 'business')
                    ->whereIn('email_suppressions.reason', self::SUPPRESSIONS_BLOQUANTES);
            });
    }
}
