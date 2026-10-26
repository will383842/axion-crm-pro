<?php

namespace App\Crm\Pharmacies;

/**
 * GROUPEMENT D'UNE PHARMACIE, déduit de l'enseigne et du site web.
 *
 * Mots-clés publics des grands groupements et enseignes. Le texte est mis en
 * minuscules, sans accent, puis comparé sous deux formes : avec ses mots
 * (« plus pharmacie ») et compacté sans séparateur (« pluspharmacie »), ce
 * qui couvre à la fois une enseigne et un nom de domaine.
 *
 * Limite assumée : un mot-clé générique peut coïncider avec une adresse
 * (« Pharmacie Lafayette » rue Lafayette). La valeur n'est écrite que dans un
 * champ vide de `metadata.groupement`, jamais par-dessus une valeur posée.
 */
final class Groupement
{
    /**
     * Libellé du groupement => mots-clés (forme compactée, sans accent).
     *
     * @var array<string, list<string>>
     */
    public const MOTS_CLES = [
        'Pharmacie Lafayette' => ['pharmacielafayette'],
        'Giphar' => ['giphar'],
        'Pharmacie Alliance' => ['pharmaciealliance', 'pharmaciesalliance'],
        'Leadersanté' => ['leadersante'],
        'Plus Pharmacie' => ['pluspharmacie'],
        'Pharmactiv' => ['pharmactiv'],
        'Evolupharm' => ['evolupharm'],
        'Pharmavie' => ['pharmavie'],
        'Optipharm' => ['optipharm'],
        'Mon Pharmacien' => ['monpharmacien'],
        'Welcoop' => ['welcoop'],
        'PHR' => ['groupephr'],
        'Aprium' => ['apriumpharmacie', 'aprium'],
        'Santé Pharma' => ['santepharma', 'sante pharma'],
        'Pharmodel' => ['pharmodel'],
        'Univers Pharmacie' => ['universpharmacie'],
    ];

    /**
     * Mots courts, susceptibles de se retrouver au milieu d'un autre mot
     * (« giphar » dans un nom propre…) : cherchés comme MOT ENTIER, avec des
     * limites de mots, jamais comme simple suite de lettres.
     *
     * @var list<string>
     */
    private const MOTS_COURTS = ['aprium', 'pharmavie', 'giphar', 'santepharma', 'sante pharma'];

    /** Le groupement reconnu dans l'enseigne, sinon dans le site ; null si aucun. */
    public static function detecter(?string $enseigne, ?string $website): ?string
    {
        foreach ([$enseigne, self::hote($website)] as $texte) {
            $phrase = self::normaliser((string) $texte);
            $compact = str_replace(' ', '', $phrase);
            if ($compact === '') {
                continue;
            }
            foreach (self::MOTS_CLES as $libelle => $mots) {
                foreach ($mots as $mot) {
                    if (in_array($mot, self::MOTS_COURTS, true)
                        ? preg_match('/(?<![a-z0-9])' . $mot . '(?![a-z0-9])/', $phrase) === 1
                        : str_contains($compact, $mot)) {
                        return $libelle;
                    }
                }
            }
        }

        return null;
    }

    private static function hote(?string $website): string
    {
        $website = trim((string) $website);
        if ($website === '') {
            return '';
        }
        $hote = parse_url(str_contains($website, '://') ? $website : 'http://' . $website, PHP_URL_HOST);

        return is_string($hote) ? $hote : '';
    }

    /** Minuscules, sans accent, mots séparés par une espace. */
    private static function normaliser(string $texte): string
    {
        $texte = mb_strtolower(trim($texte));
        $sans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
        $texte = $sans === false ? $texte : $sans;

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texte));
    }
}
