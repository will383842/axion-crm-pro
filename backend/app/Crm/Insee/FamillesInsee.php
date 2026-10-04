<?php

namespace App\Crm\Insee;

/**
 * LES FAMILLES DE CATÉGORIES JURIDIQUES IMPORTÉES DE SIRENE — décision du
 * propriétaire du 04/10/2026, écrite UNE fois.
 *
 * `crm:insee:importer-familles` (l'import de rattrapage) et
 * `crm:insee:mise-a-jour-mensuelle` (les créations du mois) créent des
 * fiches sur CE périmètre, et sur lui seul :
 *
 *  - 5 sociétés commerciales (SARL, SAS, SA…) : toutes ;
 *  - 7 personnes morales de droit public (communes, hôpitaux, établissements
 *    publics…) : toutes ;
 *  - 8 organismes privés spécialisés (mutuelles, syndicats, CSE, ordres…) :
 *    tous ;
 *  - 6 autres personnes morales immatriculées (sociétés civiles, SCI, SCP,
 *    SCM, GAEC…) : SEULEMENT avec salariés ;
 *  - 9 groupements de droit privé (associations…) : SEULEMENT avec salariés.
 *
 * « Avec salariés » : tranche d'effectif de l'unité légale `01` à `53`
 * (Sirene : `trancheEffectifsUniteLegale:[01 TO 53]`) — ni `NN` (non
 * employeuse), ni `00` (0 salarié), ni absente.
 *
 * JAMAIS la famille 1 (entrepreneurs individuels, exclus par décision), ni
 * les familles 2, 3 et 4 (groupements de fait, personnes morales de droit
 * étranger, de droit public soumises au droit commercial) : hors décision.
 * JAMAIS une unité non diffusible (C19-010, `HttpInseeClient::estDiffusible`).
 */
final class FamillesInsee
{
    /** L'étiquette du lot d'import (`companies.metadata->lot_import`). */
    public const LOT = 'insee-familles-2026-10';

    /** @var list<string> les familles importables, dans l'ordre de la décision */
    public const FAMILLES = ['7', '8', '6', '9', '5'];

    /** @var list<string> les familles importées SEULEMENT avec salariés */
    public const AVEC_SALARIES_SEULEMENT = ['6', '9'];

    /** @var array<string, string> libellé de chaque famille */
    public const LIBELLES = [
        '5' => 'sociétés commerciales (rattrapage des absents)',
        '6' => 'sociétés civiles et autres personnes morales immatriculées, avec salariés',
        '7' => 'personnes morales de droit public',
        '8' => 'organismes privés spécialisés (mutuelles, syndicats, CSE, ordres…)',
        '9' => 'associations et groupements de droit privé, avec salariés',
    ];

    /** Les tranches Sirene « avec salariés » : `01` à `53`. */
    private const TRANCHE_AVEC_SALARIES = '/^(0[1-9]|[1-4]\d|5[0-3])$/';

    public static function estFamille(string $famille): bool
    {
        return in_array($famille, self::FAMILLES, true);
    }

    /** La famille d'une catégorie juridique (son premier chiffre), ou null. */
    public static function familleDe(mixed $categorieJuridique): ?string
    {
        $cj = is_string($categorieJuridique) ? trim($categorieJuridique) : '';

        return preg_match('/^\d{4}$/', $cj) === 1 ? $cj[0] : null;
    }

    public static function avecSalaries(mixed $tranche): bool
    {
        return is_string($tranche) && preg_match(self::TRANCHE_AVEC_SALARIES, trim($tranche)) === 1;
    }

    /**
     * Une unité de cette catégorie juridique et de cette tranche d'effectif
     * entre-t-elle dans le périmètre des créations ? (`$famille` : imposée
     * par l'import, null pour la mise à jour mensuelle — toutes les familles.)
     */
    public static function admise(mixed $categorieJuridique, mixed $tranche, ?string $famille = null): bool
    {
        $f = self::familleDe($categorieJuridique);
        if ($f === null || ! self::estFamille($f) || ($famille !== null && $f !== $famille)) {
            return false;
        }

        return ! in_array($f, self::AVEC_SALARIES_SEULEMENT, true) || self::avecSalaries($tranche);
    }

    /**
     * La requête Sirene 3.11 (voie `/siren`) d'une famille : unités ACTIVES
     * et DIFFUSIBLES dans leur période EN COURS, de la famille, avec
     * salariés pour 6 et 9. Le filtre est REVÉRIFIÉ côté PHP (`admise`,
     * `HttpInseeClient::estDiffusible`) : la requête réduit le volume, elle
     * ne fait pas foi.
     */
    public static function requete(string $famille): string
    {
        if (! self::estFamille($famille)) {
            throw new \InvalidArgumentException("Famille INSEE non importable : « {$famille} » (attendu : " . implode(', ', self::FAMILLES) . ').');
        }
        $q = 'periode(etatAdministratifUniteLegale:A AND categorieJuridiqueUniteLegale:' . $famille . '* AND -dateFin:*)'
            . ' AND statutDiffusionUniteLegale:O';
        if (in_array($famille, self::AVEC_SALARIES_SEULEMENT, true)) {
            $q .= ' AND trancheEffectifsUniteLegale:[01 TO 53]';
        }

        return $q;
    }
}
