<?php

namespace App\Envoi\Campagnes;

/**
 * Plan de séquence tiré de la saisie d'une campagne (lot X1-L3). PUR : aucune base, aucune horloge.
 * Contrôles dans l'ordre ; le premier qui échoue donne le motif (voir ConstructionRefusee).
 */
final class PlanSequence
{
    private const OUVRES = ['jeu', 'lun', 'mar', 'mer', 'ven'];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @param list<array{rang:int, message_id:string, delai:int}> $etapes */
    private function __construct(public readonly int $fenetreDebut, public readonly int $fenetreFin, public readonly array $etapes) {}

    /** @throws ConstructionRefusee */
    public static function depuisCampagne(\stdClass $campagne): self
    {
        $saisie = is_string($campagne->saisie ?? null) ? json_decode($campagne->saisie, true) : ($campagne->saisie ?? null);
        $contenu = is_array($saisie) && is_array($saisie['contenu'] ?? null) ? $saisie['contenu'] : [];

        // 1. jours : absents = lundi-vendredi ; présents = exactement lundi-vendredi
        if (array_key_exists('jours', $contenu) && $contenu['jours'] !== null) {
            $jours = $contenu['jours'];
            if (! is_array($jours) || ! array_is_list($jours) || self::trie($jours) !== self::OUVRES) {
                throw new ConstructionRefusee('jours_non_applicables', false, 'Seuls les jours du lundi au vendredi sont pris en charge.');
            }
        }
        $sequence = $contenu['sequence'] ?? null;
        // 2. objet_b dans n'importe quelle étape
        if (is_array($sequence)) {
            foreach ($sequence as $e) {
                $b = is_array($e) ? ($e['objet_b'] ?? null) : null;
                if ($b !== null && $b !== '' && $b !== false && $b !== []) {
                    throw new ConstructionRefusee('objet_b_non_applicable', false, 'Un objet alternatif (objet_b) n\'est pas pris en charge.');
                }
            }
        }
        // 3. forme de la séquence
        if (! is_array($sequence) || ! array_is_list($sequence) || $sequence === [] || count($sequence) > 3) {
            throw self::invalide('la séquence compte 1 à 3 étapes');
        }
        $etapes = [];
        foreach ($sequence as $i => $e) {
            if (! is_array($e) || ($e['etape'] ?? null) !== $i + 1 || ! is_string($e['message_id'] ?? null) || preg_match(self::UUID, $e['message_id']) !== 1
                || ! is_int($e['delai_jours'] ?? null) || $e['delai_jours'] < 0 || $e['delai_jours'] > 60) {
                throw self::invalide('étape ' . ($i + 1) . ' invalide (numéro, message type, délai de 0 à 60 jours)');
            }
            $etapes[] = ['rang' => $i + 1, 'message_id' => strtolower($e['message_id']), 'delai' => $e['delai_jours']];
        }
        // 4. cohérence avec le nombre d'étapes annoncé
        if (count($etapes) !== (int) $campagne->nb_etapes) {
            throw new ConstructionRefusee('sequence_incoherente', false, 'Le nombre d\'étapes saisies ne correspond pas à celui de la campagne.');
        }
        // 5. heures : absentes = 9 et 17 ; présentes = « HH:00 », 0 <= de < a <= 24, sans arrondi
        $debut = 9;
        $fin = 17;
        if (array_key_exists('heures', $contenu) && $contenu['heures'] !== null) {
            $h = $contenu['heures'];
            if (! is_array($h) || ! is_string($h['de'] ?? null) || ! is_string($h['a'] ?? null)
                || preg_match('/^(\d{2}):00$/', $h['de'], $d) !== 1 || preg_match('/^(\d{2}):00$/', $h['a'], $a) !== 1) {
                throw self::invalide('heures au format HH:00 seulement');
            }
            [$debut, $fin] = [(int) $d[1], (int) $a[1]];
            if ($debut < 0 || $debut >= $fin || $fin > 24) {
                throw self::invalide('heures : 0 <= début < fin <= 24');
            }
        }

        // 6. boites et plafond_jour : ignorés ici (le lancement les refuse)
        return new self($debut, $fin, $etapes);
    }

    private static function invalide(string $raison): ConstructionRefusee
    {
        return new ConstructionRefusee('etapes_invalides', false, 'Séquence invalide : ' . $raison . '.');
    }

    /**
     * @param  array<mixed>  $jours
     * @return list<mixed>
     */
    private static function trie(array $jours): array
    {
        sort($jours);

        return $jours;
    }
}
