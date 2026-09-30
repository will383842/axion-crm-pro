<?php

namespace App\Crm\Campagnes;

use App\Crm\FichesProtegees;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * LA PRESSE N'ENTRE DANS AUCUNE AUDIENCE TANT QUE WILL NE L'A PAS OUVERTE —
 * quel que soit le chemin (relecture sécurité de #264, 2026-09-30).
 *
 * Une fiche qui porte le tag de la presse harmonisée
 * (`FichesProtegees::TAG_PRESSE`) ne peut entrer dans une audience, une liste
 * ou une campagne QUE si le segment `presse` est dans `Segments::OUVERTS`.
 *
 * Pourquoi une garde À PART de `FichesProtegees::exclure` : la protection est
 * une règle générale que d'autres chemins lèvent sciemment — une liste
 * manuelle exigée (#266, pas encore fusionnée) admet une fiche protégée
 * qu'elle nomme. La presse, elle, ne doit JAMAIS passer par une de ces
 * levées : l'ouvrir est une décision de Will, et une seule (ajouter
 * `Segments::PRESSE` à `Segments::OUVERTS`). Tout chemin qui fait entrer des
 * fiches dans une audience appelle CETTE garde, en plus de la sienne :
 *
 *  - une requête sur `companies` : `GardePresse::exclure($query)` ;
 *  - du SQL écrit à la main : `GardePresse::conditionSql('alias.id')` ;
 *  - une fiche seule (évaluation en mémoire) : `GardePresse::admissible($id)`.
 *
 * Déjà branchée : `AudienceBuilderService::buildQuery()` (donc `refresh()`,
 * `preview()` et `RefreshAudienceChunkJob`) et `evaluateForCompany()`
 * (waterfall). #266 n'a qu'à l'appeler sur son chemin des listes exigées.
 *
 * Le paramètre `$ouverts` n'existe que pour qu'un test prouve la garde dans
 * les deux états ; le code applicatif ne le passe jamais.
 */
final class GardePresse
{
    /** @param  list<string>  $ouverts */
    public static function ouverte(array $ouverts = Segments::OUVERTS): bool
    {
        return in_array(Segments::PRESSE, $ouverts, true);
    }

    /**
     * Retire les fiches de presse d'une requête sur `companies` tant que le
     * segment est fermé (modifie la requête en place).
     *
     * @param  list<string>  $ouverts
     */
    public static function exclure(EloquentBuilder|QueryBuilder $query, string $colonneId = 'companies.id', array $ouverts = Segments::OUVERTS): void
    {
        if (self::ouverte($ouverts)) {
            return;
        }
        $query->whereNotExists(function (QueryBuilder $sub) use ($colonneId): void {
            $sub->selectRaw('1')
                ->from('company_tag as gp_ct')
                ->join('tags as gp_t', 'gp_t.id', '=', 'gp_ct.tag_id')
                ->whereColumn('gp_ct.company_id', $colonneId)
                ->where('gp_t.slug', FichesProtegees::TAG_PRESSE);
        });
    }

    /**
     * La même condition en SQL brut. `$colonneId` n'est jamais une donnée
     * utilisateur. Alias internes `gp_ct`/`gp_t` réservés (même piège que
     * `FichesProtegees::conditionSql`).
     *
     * @param  list<string>  $ouverts
     */
    public static function conditionSql(string $colonneId = 'companies.id', array $ouverts = Segments::OUVERTS): string
    {
        if (self::ouverte($ouverts)) {
            return 'TRUE';
        }

        return 'NOT EXISTS (SELECT 1 FROM company_tag gp_ct JOIN tags gp_t ON gp_t.id = gp_ct.tag_id'
            . " WHERE gp_ct.company_id = {$colonneId} AND gp_t.slug = '" . str_replace("'", "''", FichesProtegees::TAG_PRESSE) . "')";
    }

    /**
     * Cette fiche peut-elle entrer dans une audience ?
     *
     * @param  list<string>  $ouverts
     */
    public static function admissible(int $companyId, array $ouverts = Segments::OUVERTS): bool
    {
        if (self::ouverte($ouverts)) {
            return true;
        }

        return ! DB::table('company_tag')
            ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.company_id', $companyId)
            ->where('tags.slug', FichesProtegees::TAG_PRESSE)
            ->exists();
    }
}
