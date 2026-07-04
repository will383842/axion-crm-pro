<?php

namespace App\Services\Prospection;

/**
 * Classe une entreprise dans un secteur d'activité lisible (btp, sante, commerce…)
 * à partir de son code NAF/APE, via la DIVISION NAF (2 premiers chiffres).
 *
 * Source de vérité du mapping division → secteur (réutilisé par la collecte ET
 * par la commande de reclassement). Les libellés secteurs correspondent aux
 * filtres du front (CompaniesListPage : it_saas, btp, sante, commerce, …).
 */
class SectorClassifier
{
    /** @var array<string, list<string>> secteur => divisions NAF (2 chiffres) */
    public const DIVISIONS = [
        'agro_alimentaire'        => ['01', '02', '03', '10', '11'],
        'industrie'               => ['05', '06', '07', '08', '09', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32', '33', '35', '36', '37', '38', '39'],
        'btp'                     => ['41', '42', '43'],
        'commerce'                => ['45', '46', '47'],
        'transport'               => ['49', '50', '51', '52', '53'],
        'hotellerie_restauration' => ['55', '56'],
        'it_saas'                 => ['58', '62', '63'],
        'finance_assurance'       => ['64', '65', '66'],
        'services_pro'            => ['69', '70', '71', '72', '73', '74', '77', '78', '80', '81', '82'],
        'sante'                   => ['75', '86', '87', '88'],
        'immobilier'              => ['68'],
        'enseignement'            => ['85'],
        'services_personnels'     => ['95', '96'],
        'arts_loisirs'            => ['90', '91', '92', '93'],
    ];

    /** Renvoie le secteur d'une entreprise depuis son NAF (ou 'autre'). */
    public static function fromNaf(?string $naf): string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $naf);
        $division = substr((string) $digits, 0, 2);
        if ($division === '') {
            return 'autre';
        }
        foreach (self::DIVISIONS as $sector => $divisions) {
            if (in_array($division, $divisions, true)) {
                return $sector;
            }
        }
        return 'autre';
    }
}
