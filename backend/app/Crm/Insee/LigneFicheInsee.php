<?php

namespace App\Crm\Insee;

use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\NomenclatureNaf;
use App\Data\Sources\InseeCompanyData;

/**
 * LA FICHE `companies` NÉE D'UN SIÈGE INSEE — écrite UNE fois.
 *
 * `prospection:collect` (import par département) et
 * `crm:insee:mise-a-jour-mensuelle` (créations du mois, lot N8) créent la
 * MÊME ligne pour la même entreprise : mêmes colonnes, même secteur et même
 * taille (`App\Crm\Referentiels`), mêmes champs complémentaires en
 * `metadata`. Deux copies finiraient par diverger.
 */
final class LigneFicheInsee
{
    /**
     * @return array<string, mixed>
     */
    public static function depuis(InseeCompanyData $data, string $workspaceId, string $departement): array
    {
        $extra = self::champsComplementaires($data->raw);
        // LE calcul unique (`App\Crm\Referentiels`) : la collecte,
        // l'enrichissement et le reclassement de masse rangent une même
        // entreprise dans le même secteur et la même taille.
        $naf = NomenclatureNaf::classer($data->naf);
        $categorie = is_array($data->raw['uniteLegale'] ?? null) ? ($data->raw['uniteLegale']['categorieEntreprise'] ?? null) : null;

        return [
            'workspace_id' => $workspaceId,
            'siren' => $data->siren,
            'denomination' => $data->denomination,
            'naf' => $data->naf,
            'legal_form' => $data->legalForm,
            'effectif_range' => $data->effectifRange,
            'size_category' => Classement::tailleDepuisInsee(
                $data->effectifRange,
                is_string($categorie) ? $categorie : null,
            ),
            'sector_main' => $naf->secteur,
            'naf_nomenclature' => $naf->nomenclature,
            'naf_rev2' => $naf->codeRev2,
            'entity_nature' => self::nature($data),
            'region_code' => Classement::regionDuDepartement($departement),
            'address' => $data->address,
            'postcode' => $data->postcode,
            'city' => $data->city,
            'city_name' => $data->city,
            'insee' => $data->insee,
            'siret' => is_string($data->raw['siret'] ?? null) ? $data->raw['siret'] : null,
            'enseigne' => $extra['enseigne'] ?? null,
            'metadata' => json_encode($extra, JSON_UNESCAPED_UNICODE),
            'discovery_source' => 'insee',
            'department_code' => $departement,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * La nature de la fiche. Une société commerciale (5xxx) — tout ce que
     * créait l'import initial — reste `entreprise`, comme avant. Les familles
     * ouvertes le 04/10/2026 (`FamillesInsee`) sont rangées par la règle
     * existante (`Classement::natureDeduite` : 92xx association, NAF 94.1x et
     * 94.20Z organisation professionnelle, 84.xx institution), sinon par leur
     * famille : droit public (7) `institution`, groupement de droit privé (9)
     * `association`, le reste (6, 8) `entreprise`.
     */
    public static function nature(InseeCompanyData $data): string
    {
        $famille = FamillesInsee::familleDe($data->legalForm);
        if ($famille === null || $famille === '5') {
            return 'entreprise';
        }
        [$nature] = Classement::natureDeduite($data->legalForm, $data->naf, null, $data->siren);

        return $nature ?? match ($famille) {
            '7' => 'institution',
            '9' => 'association',
            default => 'entreprise',
        };
    }

    /**
     * Département d'un code commune INSEE (5 caractères) : trois chiffres
     * outre-mer (97x), `2A`/`2B` pour la Corse, deux ailleurs. Null si le
     * code n'en est pas un.
     */
    public static function departementDeCommune(?string $codeCommune): ?string
    {
        $c = strtoupper(trim((string) $codeCommune));
        if (preg_match('/^(\d{2}|2A|2B)[0-9]{3}$/', $c) !== 1) {
            return null;
        }

        return str_starts_with($c, '97') ? substr($c, 0, 3) : substr($c, 0, 2);
    }

    /**
     * Champs INSEE supplémentaires utiles (stockés en metadata JSONB) : SIRET,
     * enseigne, catégorie officielle (TPE/PME/ETI/GE), date de création, forme
     * juridique, ESS, coordonnées GPS Lambert.
     *
     * @param  array<string,mixed>  $raw  établissement INSEE brut
     * @return array<string,mixed>
     */
    public static function champsComplementaires(array $raw): array
    {
        $u = is_array($raw['uniteLegale'] ?? null) ? $raw['uniteLegale'] : [];
        $periode = is_array($raw['periodesEtablissement'][0] ?? null) ? $raw['periodesEtablissement'][0] : [];
        $adr = is_array($raw['adresseEtablissement'] ?? null) ? $raw['adresseEtablissement'] : [];

        return array_filter([
            'siret' => $raw['siret'] ?? null,
            'sigle' => $u['sigleUniteLegale'] ?? null,
            'enseigne' => $periode['enseigne1Etablissement'] ?? null,
            'categorie_entreprise' => $u['categorieEntreprise'] ?? null,      // TPE/PME/ETI/GE officiel INSEE
            'date_creation' => $u['dateCreationUniteLegale'] ?? null,
            'forme_juridique' => $u['categorieJuridiqueUniteLegale'] ?? null,
            'employeur' => $u['caractereEmployeurUniteLegale'] ?? null,   // O = a des salariés
            'ess' => $u['economieSocialeSolidaireUniteLegale'] ?? null,
            'gps_lambert_x' => $adr['coordonneeLambertAbscisseEtablissement'] ?? null,
            'gps_lambert_y' => $adr['coordonneeLambertOrdonneeEtablissement'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
