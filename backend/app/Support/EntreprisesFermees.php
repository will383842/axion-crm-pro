<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as ContratBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Spatie\QueryBuilder\QueryBuilder as SpatieQueryBuilder;

/**
 * LES ENTREPRISES FERMÉES SELON L'INSEE, MASQUÉES PAR DÉFAUT DANS LA CONSOLE.
 *
 * Décision du propriétaire (04/10/2026) : une fiche que la mise à jour
 * mensuelle (`crm:insee:mise-a-jour-mensuelle`) a marquée fermée
 * (`companies.insee_ferme_le IS NOT NULL`) n'apparaît plus dans les listes,
 * la recherche globale ni les compteurs des écrans. RIEN n'est supprimé ni
 * modifié en base : c'est un FILTRE de lecture, levé par `fermees=inclure`
 * (toutes) ou `fermees=seules` (uniquement les fermées). La fiche d'une
 * entreprise fermée reste accessible par son lien direct.
 *
 * Décision du propriétaire (27/10/2026, remplace celle du 05/10) : les
 * fermées sont masquées PARTOUT par défaut, EXPORTS COMPRIS (entreprises et
 * personnes), contacts, audiences (aperçu, membres, compteurs), listes
 * manuelles et indicateurs de l'accueil. `fermees=inclure|seules` les remet
 * quand l'écran ou l'export l'accepte. Seule la fiche détaillée reste
 * accessible par son lien direct (pastille « Fermée »).
 *  - ENVOIS : une fiche fermée, et chacune de ses adresses (boîte générique,
 *    canaux, personnes), n'est JAMAIS destinataire d'une campagne — motif
 *    `entreprise_fermee` d'`App\Crm\Campagnes\EligibiliteAdresse`.
 *  - CONTACTS : `appliquerAuxContacts()` filtre sur la fiche rattachée
 *    (clé primaire de `companies`) ; un contact sans fiche reste visible.
 *  - Limite connue : la vue matérialisée `coverage_matrix_cells` (carte de
 *    couverture) compte encore les fermées ; la redéfinir exige de recréer la
 *    vue, hors de ce lot.
 *
 * ── Performance (≈ 4,4 M de fiches, rôle `axion_app`) ─────────────────────
 * `IS NULL` / `IS NOT NULL` ne sont pas des appels de fonction : sous la RLS
 * forcée, le planificateur les pousse dans les conditions d'index (ce qu'il
 * refuse à un `ILIKE`, non « leakproof »).
 *
 * Mais `insee_ferme_le IS NULL` sur un COMPTAGE casserait les parcours
 * d'index seul (« Index Only Scan », `Heap Fetches: 0`) dont vivent les
 * totaux : la colonne n'est dans aucun de ces index, il faudrait relire le
 * tas (≈ 9 Go) pour chaque fiche. On compte donc PAR DIFFÉRENCE :
 *
 *     ouvertes = toutes (plan INCHANGÉ, même clé de cache qu'avant)
 *              − fermées (index partiel `idx_companies_ws_fermees`, minuscule)
 *
 * La page elle-même (`ORDER BY quality_score DESC LIMIT 100`) garde son index
 * `idx_companies_workspace_score` : la condition écarte au passage les
 * quelques fermées rencontrées, sans changer le plan.
 */
final class EntreprisesFermees
{
    /** Par défaut : les fermées sont masquées. */
    public const MASQUER = 'masquer';

    /** `fermees=inclure` : ouvertes ET fermées. */
    public const INCLURE = 'inclure';

    /** `fermees=seules` : uniquement les fermées. */
    public const SEULES = 'seules';

    public const COLONNE = 'insee_ferme_le';

    /**
     * Le mode demandé. Toute valeur inconnue (ou absente) vaut « masquer » :
     * une adresse bricolée ne fait jamais réapparaître les fermées.
     */
    public static function mode(mixed $brut): string
    {
        $valeur = is_string($brut) ? strtolower(trim($brut)) : '';

        return in_array($valeur, [self::INCLURE, self::SEULES], true) ? $valeur : self::MASQUER;
    }

    /**
     * Pose la condition du mode sur une requête de `companies`.
     *
     * Accepte aussi l'enveloppe `Spatie\QueryBuilder\QueryBuilder` de la
     * liste : elle ne réalise pas le contrat `Builder`, mais relaie `whereNull`
     * à son constructeur (`__call`). On ne la déballe pas : cf. l'avertissement
     * de `CompaniesController::export()` sur `getEloquentBuilder()`.
     *
     * @template T of ContratBuilder|SpatieQueryBuilder
     *
     * @param  T  $requete
     * @return T
     */
    public static function appliquer(ContratBuilder|SpatieQueryBuilder $requete, string $mode, string $table = 'companies'): ContratBuilder|SpatieQueryBuilder
    {
        $colonne = $table === '' ? self::COLONNE : $table . '.' . self::COLONNE;

        if ($mode === self::SEULES) {
            $requete->whereNotNull($colonne);
        } elseif ($mode !== self::INCLURE) {
            $requete->whereNull($colonne);
        }

        return $requete;
    }

    /**
     * Pose la condition du mode sur une requête de `contacts`, selon la fiche
     * rattachée (`$colonneFiche`, ex. `contacts.company_id`). Un contact sans
     * fiche n'est jamais « fermé ». Recherche par clé primaire de `companies`
     * (ou par l'index partiel des fermées pour `seules`) : pas de balayage.
     *
     * `$colonneFiche` est INTERPOLÉE dans le SQL : elle doit être de la forme
     * `table.colonne` (identifiants simples), sans quoi on lève une exception.
     *
     * @template T of Builder|EloquentBuilder|SpatieQueryBuilder
     *
     * @param  T  $requete
     * @return T
     */
    public static function appliquerAuxContacts(Builder|EloquentBuilder|SpatieQueryBuilder $requete, string $mode, string $colonneFiche = 'contacts.company_id'): Builder|EloquentBuilder|SpatieQueryBuilder
    {
        if (preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/', $colonneFiche) !== 1) {
            throw new \InvalidArgumentException('Colonne de fiche invalide : ' . $colonneFiche);
        }

        $fermee = 'SELECT 1 FROM companies fe_c WHERE fe_c.id = ' . $colonneFiche . ' AND fe_c.' . self::COLONNE . ' IS NOT NULL';

        if ($mode === self::SEULES) {
            $requete->whereRaw('EXISTS (' . $fermee . ')');
        } elseif ($mode !== self::INCLURE) {
            $requete->whereRaw('NOT EXISTS (' . $fermee . ')');
        }

        return $requete;
    }

    /**
     * Le total d'une liste selon le mode, PAR DIFFÉRENCE (cf. en-tête), au
     * travers du cache des totaux (`TotalListe`).
     *
     * `$base` est la requête SANS la condition des fermées. Elle n'est pas
     * modifiée (clones).
     */
    public static function total(Builder $base, string $mode, string $workspaceId): int
    {
        if ($mode === self::INCLURE) {
            return TotalListe::pour($base, $workspaceId);
        }

        $fermees = TotalListe::pour(self::appliquer(clone $base, self::SEULES), $workspaceId);
        if ($mode === self::SEULES) {
            return $fermees;
        }

        // Les deux totaux ont chacun leur fraîcheur (60 s) : jamais négatif.
        return max(0, TotalListe::pour($base, $workspaceId) - $fermees);
    }

    /**
     * Le total d'une liste de CONTACTS selon le mode, PAR DIFFÉRENCE (cf.
     * en-tête) : tous les contacts (plan inchangé, 1,3 M de lignes) moins ceux
     * des fiches fermées. Jamais de `NOT EXISTS` sur le comptage.
     *
     * `$base` est la requête SANS la condition des fermées ; elle n'est pas
     * modifiée (clones).
     */
    public static function totalContacts(Builder $base, string $mode, string $workspaceId, string $colonneFiche = 'contacts.company_id'): int
    {
        if ($mode === self::INCLURE) {
            return TotalListe::pour($base, $workspaceId);
        }

        $fermees = TotalListe::pour(self::appliquerAuxContacts(clone $base, self::SEULES, $colonneFiche), $workspaceId);
        if ($mode === self::SEULES) {
            return $fermees;
        }

        return max(0, TotalListe::pour($base, $workspaceId) - $fermees);
    }
}
