<?php

namespace App\Envoi\Campagnes;

use App\Crm\Origines\RequetesOrigines;
use App\Socle\Espace\FonctionsEspace;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Filtre de campagne « réseau d'origine » (lot X1-L2b-B) : `{"reseaux": ["linkedin", …]}`.
 *
 * Objet de forme SEULEMENT : aucune écriture, aucune sélection. Il ne peut que RESTREINDRE une cible.
 * Toute forme douteuse lève \InvalidArgumentException (échec fermé : l'appelant refuse, il n'ignore pas).
 */
final class FiltreOrigine
{
    public const RESEAUX_MAX = 10;

    /** @param list<string> $reseaux triés, sans doublon, chacun conforme à RequetesOrigines::JETON */
    private function __construct(public readonly array $reseaux) {}

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
                throw new InvalidArgumentException('filtre_origine illisible');
            }
        }
        if (! is_array($valeur) || array_keys($valeur) !== ['reseaux']) {
            throw new InvalidArgumentException('filtre_origine : forme invalide');
        }

        // Même ensemble de réseaux quel que soit l'ordre ou les doublons : la lecture normalise, elle n'élargit rien.
        return self::depuisSaisie($valeur['reseaux']);
    }

    /** Depuis une saisie (écran, API) : trim, déduplique, trie ; 1..RESEAUX_MAX jetons. */
    public static function depuisSaisie(mixed $reseaux): self
    {
        if (! is_array($reseaux) || ! array_is_list($reseaux)) {
            throw new InvalidArgumentException('reseaux : liste attendue');
        }
        $jetons = [];
        foreach ($reseaux as $r) {
            if (! is_string($r)) {
                throw new InvalidArgumentException('reseaux : chaînes attendues');
            }
            $r = trim($r);
            if (preg_match(RequetesOrigines::JETON, $r) !== 1 || str_contains($r, "\n")) {
                throw new InvalidArgumentException('reseaux : jeton invalide');
            }
            $jetons[$r] = true;
        }
        $liste = array_map('strval', array_keys($jetons));
        sort($liste, SORT_STRING);
        if ($liste === [] || count($liste) > self::RESEAUX_MAX) {
            throw new InvalidArgumentException('reseaux : de 1 à ' . self::RESEAUX_MAX . ' réseaux');
        }

        return new self($liste);
    }

    /** @return array{reseaux: list<string>} forme EXACTE écrite en base */
    public function enColonne(): array
    {
        return ['reseaux' => $this->reseaux];
    }

    /**
     * Les portes de la lecture des origines, lues à l'instant, dans cet ordre, au premier faux on s'arrête
     * (drapeau éteint : aucune requête). Aucune écriture.
     */
    public static function actif(string $ws): bool
    {
        return (bool) config('origines.lecture_enabled')
            && (bool) config('origines.v3_enabled')
            && Schema::hasColumn('personnes', 'origines')
            && FonctionsEspace::ouverte($ws, FonctionsEspace::ORIGINES_LECTURE);
    }
}
