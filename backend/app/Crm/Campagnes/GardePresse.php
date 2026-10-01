<?php

namespace App\Crm\Campagnes;

use App\Crm\FichesProtegees;
use App\Crm\Presse\QualificationPresse;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * LA PRESSE N'ENTRE QUE PAR SON SEGMENT — jamais par un chemin général
 * (relecture sécurité de #264, 2026-09-30 ; ouverture du 01/10/2026).
 *
 * Une fiche qui porte le tag de la presse harmonisée
 * (`FichesProtegees::TAG_PRESSE`), et une personne de la presse
 * (`estContactPresseSql`), n'entrent dans AUCUNE audience, liste manuelle,
 * export, waterfall ni segment autre que `presse` — que le segment presse soit
 * ouvert ou fermé. Décision de Will du 01/10/2026 : la presse s'OUVRE, mais
 * elle n'entre que par DEUX portes, où la provenance de chaque adresse est
 * jugée (`AdressePresseFiable`) : le segment presse
 * (`crm:campagne:destinataires presse`) et l'AUDIENCE PRESSE (critère
 * `segment eq presse`, `AudienceBuilderService::CHAMP_SEGMENT`), toutes deux
 * refusées quand le segment est fermé (`Segments::ouvert(PRESSE)`). Une
 * audience de prospection générale ne l'aspire jamais.
 *
 * Pourquoi une garde À PART de `FichesProtegees::exclure` : la protection est
 * une règle générale que d'autres chemins lèvent sciemment — une liste
 * manuelle exigée admet une fiche protégée qu'elle nomme. La presse, elle, ne
 * passe JAMAIS par une de ces levées (choix documenté du 01/10 : une liste
 * manuelle refuse toujours un journaliste ; la presse a son propre segment,
 * qui seul applique la règle de provenance). Tout chemin qui fait entrer des
 * fiches dans une audience appelle CETTE garde, en plus de la sienne :
 *
 *  - une requête sur `companies` : `GardePresse::exclure($query)` ;
 *  - du SQL écrit à la main : `GardePresse::conditionSql('alias.id')` ;
 *  - une fiche seule (évaluation en mémoire) : `GardePresse::admissible($id)` ;
 *  - une requête sur `contacts` : `exclureContacts` / `conditionContactsSql`.
 *
 * Branchée sur : `AudienceBuilderService` (`refresh()`, `preview()`,
 * `evaluateForCompany()` — waterfall), `RefreshAudienceChunkJob`,
 * `ResolveurDestinataires`, `AudiencesController::members`, listes manuelles,
 * `EligibiliteCampagne::appliquerContacts`, export CSV des fiches, et les
 * segments `organisateurs-evenements` / `federations` de
 * `crm:campagne:destinataires`.
 */
final class GardePresse
{
    /**
     * Le segment presse est-il ouvert (`crm.segments_ouverts`) ? Ne lève
     * AUCUNE des exclusions ci-dessous : il ne commande que
     * `crm:campagne:destinataires presse`.
     */
    public static function ouverte(): bool
    {
        return Segments::ouvert(Segments::PRESSE);
    }

    /**
     * Retire les fiches de presse d'une requête sur `companies` (modifie la
     * requête en place).
     */
    public static function exclure(EloquentBuilder|QueryBuilder $query, string $colonneId = 'companies.id'): void
    {
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
     */
    public static function conditionSql(string $colonneId = 'companies.id'): string
    {
        return 'NOT EXISTS (SELECT 1 FROM company_tag gp_ct JOIN tags gp_t ON gp_t.id = gp_ct.tag_id'
            . " WHERE gp_ct.company_id = {$colonneId} AND gp_t.slug = '" . str_replace("'", "''", FichesProtegees::TAG_PRESSE) . "')";
    }

    /** Cette fiche peut-elle entrer dans une audience (hors segment presse) ? */
    public static function admissible(int $companyId): bool
    {
        return ! DB::table('company_tag')
            ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.company_id', $companyId)
            ->where('tags.slug', FichesProtegees::TAG_PRESSE)
            ->exists();
    }

    /**
     * SQL : ce contact n'est PAS une personne de la presse (journaliste
     * harmonisé `journaliste:<id>`, ou personne entrée par la source
     * `presse-2026` — harmonisation ou liste de diffusion). `$alias` n'est
     * jamais une donnée utilisateur.
     *
     * C'est la règle PAR CONTACT (relecture sécurité de #264) : une fiche peut
     * porter à la fois un autre segment (un groupe de presse qui organise des
     * salons est aussi un organisateur d'événements) et des journalistes —
     * ceux-là ne partent JAMAIS par cet autre segment.
     */
    public static function conditionContactsSql(string $alias = 'contacts'): string
    {
        return 'NOT ' . self::estContactPresseSql($alias);
    }

    /**
     * SQL : ce contact EST une personne de la presse. `$alias` n'est jamais
     * une donnée utilisateur.
     */
    public static function estContactPresseSql(string $alias = 'contacts'): string
    {
        return "(COALESCE({$alias}.external_ref, '') LIKE 'journaliste:%'"
            . " OR COALESCE({$alias}.sources, '[]'::jsonb) @> '[\"" . QualificationPresse::SOURCE . "\"]'::jsonb)";
    }

    /**
     * Retire les personnes de la presse d'une requête sur `contacts` (modifie
     * la requête en place). À appeler par TOUT chemin général qui produit des
     * adresses d'envoi.
     */
    public static function exclureContacts(EloquentBuilder|QueryBuilder $query, string $alias = 'contacts'): void
    {
        $query->whereRaw(self::conditionContactsSql($alias));
    }
}
