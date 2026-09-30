<?php

namespace App\Crm\Relations;

use App\Crm\Emails\QualificationEmail;
use App\Crm\Taxonomy;

/**
 * UNE LIGNE du fichier de `crm:relations:importer`, validée.
 *
 * Format (JSON Lines — un objet JSON par ligne, UTF-8) :
 *
 *     {"source":"site-client","siren":"123456789","denomination":"…",
 *      "email":"…","relation_type":"client","lifecycle_stage":"client"}
 *
 *  - `source`           OBLIGATOIRE — d'où vient la ligne (`site-client`,
 *                       `site-contact`, `site-rdv`…) : minuscules, chiffres,
 *                       tirets, 64 caractères au plus. Recopiée dans la
 *                       timeline de la fiche ;
 *  - `siren`            9 chiffres (espaces tolérés) ou null ;
 *  - `denomination`     texte ou null — JAMAIS utilisé pour rapprocher (un nom
 *                       ne désigne pas une fiche avec certitude) ni affiché ;
 *  - `email`            adresse ou null ;
 *  - `relation_type`    une valeur de `Taxonomy::BUSINESS_RELATION_TYPES`, ou null ;
 *  - `lifecycle_stage`  `nouveau` | `qualifie` | `opportunite` | `client`, ou null.
 *
 * Il faut au moins un `siren` ou un `email` (de quoi rapprocher), et au moins
 * un `relation_type` ou un `lifecycle_stage` (quelque chose à poser). Toute
 * autre clé est refusée : une faute de frappe (`relation`, `stage`) ne doit
 * pas passer pour une ligne vide.
 */
final class LigneRelation
{
    /** @var list<string> */
    public const CLES = ['source', 'siren', 'denomination', 'email', 'relation_type', 'lifecycle_stage'];

    private function __construct(
        public readonly int $numero,
        public readonly string $source,
        public readonly ?string $siren,
        public readonly ?string $email,
        public readonly ?string $relationType,
        public readonly ?string $lifecycleStage,
    ) {}

    /**
     * La ligne validée, ou le MOTIF du rejet (chaîne).
     */
    public static function lire(int $numero, string $brut): self|string
    {
        try {
            $donnees = json_decode($brut, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'json_invalide';
        }
        if (! is_array($donnees) || array_is_list($donnees)) {
            return 'pas_un_objet';
        }
        foreach (array_keys($donnees) as $cle) {
            if (! in_array($cle, self::CLES, true)) {
                return 'cle_inconnue';
            }
        }

        $source = $donnees['source'] ?? null;
        if (! is_string($source) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $source) !== 1) {
            return 'source_invalide';
        }

        $siren = $donnees['siren'] ?? null;
        if ($siren !== null) {
            if (! is_string($siren) && ! is_int($siren)) {
                return 'siren_invalide';
            }
            $siren = preg_replace('/\s+/', '', (string) $siren) ?? '';
            if (preg_match('/^\d{9}$/', $siren) !== 1) {
                return 'siren_invalide';
            }
        }

        $email = $donnees['email'] ?? null;
        if ($email !== null && ! is_string($email)) {
            return 'email_invalide';
        }
        $email = is_string($email) && trim($email) !== '' ? QualificationEmail::normaliser($email) : null;

        $denomination = $donnees['denomination'] ?? null;
        if ($denomination !== null && ! is_string($denomination)) {
            return 'denomination_invalide';
        }

        $relation = $donnees['relation_type'] ?? null;
        if ($relation !== null && (! is_string($relation) || ! in_array($relation, Taxonomy::BUSINESS_RELATION_TYPES, true))) {
            return 'relation_inconnue';
        }
        $etape = $donnees['lifecycle_stage'] ?? null;
        if ($etape !== null && (! is_string($etape) || ! in_array($etape, PromotionRelation::ETAPES_IMPORTABLES, true))) {
            return is_string($etape) && in_array($etape, Taxonomy::BUSINESS_LIFECYCLE_STAGES, true)
                ? 'etape_non_importable'
                : 'etape_inconnue';
        }

        if ($siren === null && $email === null) {
            return 'ni_siren_ni_email';
        }
        if ($relation === null && $etape === null) {
            return 'rien_a_poser';
        }

        return new self($numero, $source, $siren, $email, $relation, $etape);
    }

    /** Le domaine de l'adresse, sans `www.`, ou null. */
    public function domaine(): ?string
    {
        if ($this->email === null || ! QualificationEmail::syntaxeValide($this->email)) {
            return null;
        }
        $domaine = QualificationEmail::domaine($this->email);

        return $domaine === null ? null : (str_starts_with($domaine, 'www.') ? substr($domaine, 4) : $domaine);
    }
}
