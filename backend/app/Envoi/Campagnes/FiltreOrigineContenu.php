<?php

namespace App\Envoi\Campagnes;

use App\Crm\Origines\RequetesOrigines;
use InvalidArgumentException;

/**
 * Filtre de campagne « mot / guide d'origine » (lot PONT-LINKEDIN PO-A) : `{"mots": [...], "guides": [...]}`,
 * l'une des deux clés au moins, 1 à 10 jetons par clé (forme exacte du CHECK de la colonne
 * `envoi_campagnes.filtre_origine_contenu`, lot PL-M0A).
 *
 * Objet de forme SEULEMENT : aucune écriture, aucune sélection. Il ne peut que RESTREINDRE une cible.
 * Toute forme douteuse lève \InvalidArgumentException (échec fermé : l'appelant refuse, il n'ignore pas).
 * Un jeton n'est jamais « corrigé » : une majuscule est refusée, seul `trim` est toléré.
 */
final class FiltreOrigineContenu
{
    public const JETONS_MAX = 10;

    private const CLES = ['mots', 'guides'];

    /**
     * @param  list<string>|null  $mots  triés, sans doublon ; null = clé absente
     * @param  list<string>|null  $guides  idem
     */
    private function __construct(public readonly ?array $mots, public readonly ?array $guides) {}

    /** Depuis la valeur de la colonne (string JSON, array ou null). null => pas de filtre. */
    public static function depuisColonne(mixed $valeur): ?self
    {
        if ($valeur === null) {
            return null;
        }
        if (is_string($valeur)) {
            try {
                $valeur = json_decode($valeur, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidArgumentException('filtre_origine_contenu illisible');
            }
        }
        if (! is_array($valeur)) {
            throw new InvalidArgumentException('filtre_origine_contenu : forme invalide');
        }

        return self::depuisSaisie($valeur);
    }

    /**
     * Depuis une saisie (écran, API) : clés `mots` et/ou `guides` ; trim, déduplique, trie.
     *
     * @param  array<mixed>  $saisie
     */
    public static function depuisSaisie(array $saisie): self
    {
        $cles = array_keys($saisie);
        if ($cles === [] || array_diff($cles, self::CLES) !== []) {
            throw new InvalidArgumentException('filtre_origine_contenu : clés mots / guides uniquement');
        }

        return new self(
            array_key_exists('mots', $saisie) ? self::jetons($saisie['mots'], 'mots') : null,
            array_key_exists('guides', $saisie) ? self::jetons($saisie['guides'], 'guides') : null,
        );
    }

    /** @return list<string> */
    private static function jetons(mixed $liste, string $cle): array
    {
        if (! is_array($liste) || ! array_is_list($liste)) {
            throw new InvalidArgumentException("{$cle} : liste attendue");
        }
        $jetons = [];
        foreach ($liste as $j) {
            if (! is_string($j)) {
                throw new InvalidArgumentException("{$cle} : chaînes attendues");
            }
            $j = trim($j);
            if (preg_match(RequetesOrigines::JETON, $j) !== 1 || str_contains($j, "\n")) {
                throw new InvalidArgumentException("{$cle} : jeton invalide");
            }
            $jetons[$j] = true;
        }
        $propres = array_map('strval', array_keys($jetons));
        sort($propres, SORT_STRING);
        if ($propres === [] || count($propres) > self::JETONS_MAX) {
            throw new InvalidArgumentException("{$cle} : de 1 à " . self::JETONS_MAX . ' jetons');
        }

        return $propres;
    }

    /** @return array{mots?: list<string>, guides?: list<string>} forme EXACTE écrite en base */
    public function enColonne(): array
    {
        $c = [];
        if ($this->mots !== null) {
            $c['mots'] = $this->mots;
        }
        if ($this->guides !== null) {
            $c['guides'] = $this->guides;
        }

        return $c;
    }

    /** La porte de la lecture des origines est UNIQUE : celle de `FiltreOrigine` (appelée, jamais recopiée). */
    public static function actif(string $ws): bool
    {
        return FiltreOrigine::actif($ws);
    }
}
