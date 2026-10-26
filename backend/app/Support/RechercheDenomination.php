<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

/**
 * RECHERCHE PAR DÉNOMINATION — l'unique endroit où l'on écrit cette condition.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ────────────────────────────────────────────
 *
 * Constats `G41-002` (recherche du hub) et `G41-003`/`G41-004` (filtre
 * `filter[denomination]` de la liste entreprises) : **deux sites, un seul et
 * même défaut**, chercher sur `denomination` au lieu de
 * `denomination_normalized`.
 *
 * Le patron le plus coûteux de ce dépôt (`A-011`, 20+ cas) est « le correctif
 * existe déjà quelque part et n'a pas été porté ailleurs ». Il s'était déjà
 * produit ICI : `GlobalSearchController` cherche sur la bonne colonne depuis le
 * 2026-08-20, et ni le hub ni la liste entreprises n'en ont profité. On écrit
 * donc la condition UNE fois, et les deux sites l'appellent.
 *
 * ── LA COLONNE, ET L'INDEX QUI LA COUVRE ────────────────────────────────────
 *
 * `companies.denomination_normalized` est une colonne
 * `GENERATED ALWAYS AS (normalize_name(denomination)) STORED`, maintenue par
 * Postgres seul, et couverte par l'index trigrammes
 * `idx_companies_denomination_trgm` **depuis le 2026-05-16**.
 *
 * Mesuré ce jour sur 150 000 fiches, `filter[denomination]` :
 *
 *     sur `denomination` .............. Seq Scan            145,7 ms
 *     sur `denomination_normalized` ... Bitmap Index Scan     2,7 ms
 *
 * 54 fois, et l'écart croît avec le volume — la production en porte 4,29 M.
 *
 * ⚠️ `denomination` NE PORTE AUCUN INDEX. Vérifié au schéma le 2026-08-20. Le
 * commentaire de `ContactsHubController::applySearch` invoquait « un index
 * B-tree posé par la migration 2026_07_09_000004 » : cette migration crée
 * `idx_companies_denom_btree` sur `(workspace_id, denomination_normalized)` —
 * une AUTRE colonne. Et un B-tree ne sert de toute façon jamais un `ILIKE`.
 *
 * ── ⚠️ LE TERME DOIT ÊTRE NORMALISÉ, ET C'EST LA MOITIÉ QU'ON OUBLIE ────────
 *
 * `normalize_name()` met en minuscules, RETIRE LES ACCENTS, et SUPPRIME les
 * articles (`de|du|la|le|les|d|l`). « La Boulangerie Crème Brûlée » est donc
 * stockée « boulangerie creme brulee ».
 *
 * Basculer sur la colonne normalisée sans normaliser le terme rend la recherche
 * MUETTE sur ce que les gens tapent. Mesuré :
 *
 *     denomination_normalized ILIKE '%Crème%' .............. 0 résultat
 *     denomination_normalized ILIKE '%La Boulangerie%' ..... 0 résultat
 *     denomination_normalized ILIKE '%'||normalize_name(…)||'%'  1 résultat
 *
 * On échangerait un défaut de lenteur contre un défaut de justesse — qui est
 * PIRE : une liste lente se voit, une liste vide se croit.
 *
 * D'où l'appel à la MÊME fonction SQL des deux côtés. Réimplémenter `unaccent`
 * et le retrait des articles en PHP marcherait le jour où on l'écrit, et
 * divergerait silencieusement au premier changement de la fonction SQL.
 *
 * Garde : `tests/Feature/Infra/VolumeDeProductionHubConsoleTest.php`.
 */
final class RechercheDenomination
{
    /**
     * `normalize_name(?)` est appliqué AU TERME, pas à la colonne : la colonne
     * est déjà normalisée et stockée. Appliquer la fonction à la colonne
     * (`normalize_name(denomination) ILIKE …`) rendrait l'index trigrammes
     * inutilisable — c'est le même défaut sous une autre forme.
     *
     * Vérifié par `EXPLAIN` : cette condition emploie bien
     * `idx_companies_denomination_trgm` (Bitmap Index Scan, 1,6 ms).
     */
    private const CONDITION = "companies.denomination_normalized ILIKE '%' || normalize_name(?) || '%'";

    /**
     * Applique la recherche en ET. `$valeur` accepte une chaîne ou un tableau
     * (`filter[denomination]=a,b`), auquel cas les termes sont en OU — c'est la
     * sémantique de `AllowedFilter::partial` qu'on remplace, et la changer
     * modifierait silencieusement des vues enregistrées existantes.
     *
     * @param  Builder<Company>  $query
     */
    public static function appliquer(Builder $query, mixed $valeur): void
    {
        $termes = self::termes($valeur);

        if ($termes === []) {
            return;
        }

        // Groupé : sans la parenthèse, un OU de termes s'échapperait du reste
        // des filtres et rendrait la liste ENTIÈRE (le défaut classique du
        // filtre qui « ne filtre plus » dès qu'on tape une virgule).
        $query->where(function (Builder $groupe) use ($termes): void {
            foreach ($termes as $i => $terme) {
                $i === 0
                    ? $groupe->whereRaw(self::CONDITION, [$terme])
                    : $groupe->orWhereRaw(self::CONDITION, [$terme]);
            }
        });
    }

    /**
     * Plafond de termes confiés à `entreprises_liste_denomination_ids` (la
     * fonction refuse au-delà). Plus de termes : on retombe sur `appliquer`,
     * lent mais juste — tronquer la liste de termes rendrait moins de fiches
     * sans le dire.
     */
    private const TERMES_MAX_DANS_ESPACE = 20;

    /**
     * MÊME recherche que `appliquer`, mais servie par l'index trigrammes SOUS
     * LA RLS — pour la liste « Entreprises » (`CompaniesController`).
     *
     * 🔴 Sous le rôle applicatif `axion_app` (RLS forcée), `ILIKE` n'est pas
     * « leakproof » : Postgres ne peut l'évaluer qu'APRÈS le filtre de la
     * politique, et `appliquer` balaie ≈ 4,4 M de lignes (diagnostic du
     * 26/10/2026, §2.4). Les identifiants sont donc cherchés par
     * `entreprises_liste_denomination_ids` (SECURITY DEFINER, migration
     * `2026_10_26_000010`), qui ne rend RIEN si `$workspaceId` n'est pas
     * l'espace du contexte de la connexion ; la requête de liste les RELIT par
     * une semi-jointure ordinaire, sous la RLS, avec son propre
     * `workspace_id = ?` et tous ses autres filtres — double garde. Patron de
     * `entreprises_choix_ids` (#292), SANS plafond : la liste est paginée et
     * son total (`TotalListe`) doit rester exact.
     *
     * Sémantique identique à `appliquer` : termes échappés des jokers `LIKE`,
     * normalisés par `normalize_name` (dans la fonction), en OU.
     *
     * @param  Builder<Company>  $query
     */
    public static function appliquerDansEspace(Builder $query, mixed $valeur, string $workspaceId): void
    {
        $termes = self::termes($valeur);

        if ($termes === []) {
            return;
        }

        if (count($termes) > self::TERMES_MAX_DANS_ESPACE) {
            self::appliquer($query, $valeur);

            return;
        }

        $query->whereRaw(
            'companies.id IN (SELECT t.id FROM public.entreprises_liste_denomination_ids(?::uuid, ?::text[]) AS t(id))',
            [$workspaceId, RechercheEntreprisesParNom::tableauPg($termes)],
        );
    }

    /**
     * La condition seule, en ET — pour s'insérer dans un groupe de recherche
     * déjà ouvert (la recherche multi-champs du hub : dénomination OU SIREN OU
     * personne rattachée, où celle-ci est la première branche).
     *
     * @param  Builder<Company>  $query
     */
    public static function et(Builder $query, string $terme): void
    {
        $query->whereRaw(self::CONDITION, [self::echapper($terme)]);
    }

    /**
     * @return list<string>
     */
    private static function termes(mixed $valeur): array
    {
        $brut = is_array($valeur) ? $valeur : [$valeur];

        $termes = [];
        foreach ($brut as $v) {
            if (! is_string($v) && ! is_numeric($v)) {
                continue;
            }
            $terme = trim((string) $v);
            if ($terme !== '') {
                $termes[] = self::echapper($terme);
            }
        }

        return array_values(array_unique($termes));
    }

    /**
     * Neutralise les jokers `LIKE`. Sans cela, `filter[denomination]=%` rend la
     * liste ENTIÈRE — un balayage de 4,29 M de lignes déclenché depuis l'URL,
     * exactement la famille de défaut qu'on répare ici.
     *
     * `\` d'abord, sinon on ré-échapperait les échappements qu'on vient de
     * poser.
     */
    private static function echapper(string $terme): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terme);
    }
}
