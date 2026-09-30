<?php

namespace App\Crm\Campagnes;

use App\Models\EmailAudience;
use InvalidArgumentException;

/**
 * À QUI ÉCRIRE DANS CHAQUE ORGANISATION — le réglage d'une audience.
 *
 * Une audience retient des ORGANISATIONS. Ce réglage dit quelles adresses de
 * chacune deviennent des destinataires (REQ-CAM-007, 079, 082) :
 *
 *  - `personne_sinon_generique` (défaut, règle de #253) : les personnes
 *    nommées éligibles ; s'il n'y en a aucune, l'adresse générique ;
 *  - `generique`  : seulement l'adresse générique (`companies.email_generic`
 *    et canaux typés `generique` de `signals.contact_channels`) ;
 *  - `nominatives`: seulement les personnes nommées (`contacts.email` et
 *    canaux typés `nominatif`) ;
 *  - `les_deux`   : l'une et les autres.
 *
 * `fonctions` restreint les PERSONNES à celles dont la fonction
 * (`contacts.role`, JAMAIS `title`, REQ-CAM-079) contient l'un de ces mots,
 * sans tenir compte de la casse ni des accents (« président » retient
 * « Président », « Présidente », « Vice-président »). Une adresse nominative
 * sans fonction connue (canal typé) n'est alors pas retenue.
 *
 * `personnesListees` : seules les personnes COCHÉES elles-mêmes dans une
 * liste manuelle exigée par l'audience (« seulement certains contacts »).
 *
 * `avecAdressesPartagees` : garder les adresses de cabinet comptable ou de
 * domiciliation portées par plusieurs fiches (écartées par défaut, #260).
 */
final class ReglageDestinataires
{
    public const PERSONNE_SINON_GENERIQUE = 'personne_sinon_generique';

    public const GENERIQUE = 'generique';

    public const NOMINATIVES = 'nominatives';

    public const LES_DEUX = 'les_deux';

    /** @var list<string> */
    public const MODES = [self::PERSONNE_SINON_GENERIQUE, self::GENERIQUE, self::NOMINATIVES, self::LES_DEUX];

    public const FONCTIONS_MAX = 20;

    public const FONCTION_LONGUEUR_MAX = 60;

    /**
     * @param  list<string>  $fonctions  mots de fonction, tels que saisis
     */
    public function __construct(
        public readonly string $mode = self::PERSONNE_SINON_GENERIQUE,
        public readonly array $fonctions = [],
        public readonly bool $personnesListees = false,
        public readonly bool $avecAdressesPartagees = false,
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Réglage des destinataires inconnu : « {$mode} ».");
        }
        if (count($fonctions) > self::FONCTIONS_MAX) {
            throw new InvalidArgumentException('Au plus ' . self::FONCTIONS_MAX . ' fonctions.');
        }
    }

    /**
     * Une fonction ne porte que des lettres, chiffres, espaces et `' ’ - . / &` :
     * ni virgule, ni guillemet, ni accolade — elle s'écrit donc telle quelle
     * dans un tableau Postgres (`TEXT[]`).
     */
    public const MOTIF_FONCTION = "/^[\pL\pN][\pL\pN '’\-.\/&]*$/u";

    public static function deLAudience(EmailAudience $audience): self
    {
        return new self(
            (string) ($audience->getAttribute('destinataires_mode') ?? self::PERSONNE_SINON_GENERIQUE),
            self::depuisTableauPg($audience->getAttribute('destinataires_fonctions')),
            (bool) $audience->getAttribute('destinataires_personnes_listees'),
            (bool) $audience->getAttribute('destinataires_avec_adresses_partagees'),
        );
    }

    /**
     * @param  array<string, mixed>  $donnees  clés `mode`, `fonctions`, `personnes_listees`, `avec_adresses_partagees`
     */
    public static function depuisTableau(array $donnees): self
    {
        $fonctions = $donnees['fonctions'] ?? [];

        return new self(
            is_string($donnees['mode'] ?? null) ? $donnees['mode'] : self::PERSONNE_SINON_GENERIQUE,
            is_array($fonctions) ? self::nettoyerFonctions($fonctions) : [],
            (bool) ($donnees['personnes_listees'] ?? false),
            (bool) ($donnees['avec_adresses_partagees'] ?? false),
        );
    }

    /**
     * @param  array<mixed>  $fonctions
     * @return list<string>
     */
    public static function nettoyerFonctions(array $fonctions): array
    {
        $propres = [];
        foreach ($fonctions as $f) {
            if (! is_string($f)) {
                continue;
            }
            $f = trim(preg_replace('/\s+/u', ' ', $f) ?? '');
            if ($f !== '') {
                $propres[self::normaliser($f)] = mb_substr($f, 0, self::FONCTION_LONGUEUR_MAX);
            }
        }

        return array_values($propres);
    }

    /**
     * `TEXT[]` Postgres => liste PHP. Les fonctions ne portent ni virgule ni
     * guillemet (`MOTIF_FONCTION`) : le découpage est sans ambiguïté.
     *
     * @return list<string>
     */
    public static function depuisTableauPg(mixed $brut): array
    {
        if (is_array($brut)) {
            return array_values(array_filter($brut, 'is_string'));
        }
        if (! is_string($brut)) {
            return [];
        }
        $interieur = trim($brut, '{}');
        if ($interieur === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $v): string => trim($v, '"'),
            explode(',', $interieur),
        ), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Liste PHP => littéral `TEXT[]`. Refuse une valeur hors `MOTIF_FONCTION`.
     *
     * @param  list<string>  $fonctions
     */
    public static function versTableauPg(array $fonctions): string
    {
        foreach ($fonctions as $f) {
            if (preg_match(self::MOTIF_FONCTION, $f) !== 1) {
                throw new InvalidArgumentException('Fonction refusée : lettres, chiffres, espaces, apostrophe, tiret, point, barre oblique et esperluette seulement.');
            }
        }

        return '{' . implode(',', array_map(static fn (string $f): string => '"' . $f . '"', $fonctions)) . '}';
    }

    /** La fonction de cette personne est-elle retenue ? (toujours vrai sans filtre) */
    public function retientFonction(?string $role): bool
    {
        if ($this->fonctions === []) {
            return true;
        }
        if ($role === null || trim($role) === '') {
            return false;
        }
        $r = self::normaliser($role);
        foreach ($this->fonctions as $f) {
            if (str_contains($r, self::normaliser($f))) {
                return true;
            }
        }

        return false;
    }

    /** Minuscules, sans accents, espaces et tirets réduits. */
    public static function normaliser(string $texte): string
    {
        $t = mb_strtolower(trim($texte));
        $t = strtr($t, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ú' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        ]);

        return trim(preg_replace('/[\s\-_]+/u', ' ', $t) ?? '');
    }

    /** @return array{mode: string, fonctions: list<string>, personnes_listees: bool, avec_adresses_partagees: bool} */
    public function enTableau(): array
    {
        return [
            'mode' => $this->mode,
            'fonctions' => $this->fonctions,
            'personnes_listees' => $this->personnesListees,
            'avec_adresses_partagees' => $this->avecAdressesPartagees,
        ];
    }
}
