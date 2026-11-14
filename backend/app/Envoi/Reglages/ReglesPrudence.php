<?php

namespace App\Envoi\Reglages;

/**
 * Règles de prudence : la SEULE classe qui connaît le SENS de chaque seuil. `DepotChauffe::fixerSeuil` est un UPSERT sans
 * sens : le « durcir seulement » ne vit donc pas dans le contrôleur. Un maximum ne peut que BAISSER, un minimum que MONTER,
 * une fenêtre que se RÉTRÉCIR, une interdiction que rester vraie, une liste de jours que perdre des jours.
 * Les valeurs par défaut sont les constantes prudentes actuelles (`Seuils`, `config/envoi.php`) ; celles sans équivalent
 * dans le code (plaintes pour mille, pression par organisation) sont des PLAFONDS de départ à confirmer par le propriétaire.
 */
final class ReglesPrudence
{
    public const MAX = 'max';

    public const HEURE_DEBUT = 'heure_debut';

    public const HEURE_FIN = 'heure_fin';

    public const VRAI = 'vrai';

    public const JOURS = 'jours';

    public const JOURS_OUVRES = ['lun', 'mar', 'mer', 'jeu', 'ven'];

    /** @return array<string, string> champ => sens */
    public static function sens(): array
    {
        return [
            'pause_si_refus_pct' => self::MAX, 'pause_si_plaintes_pour_mille' => self::MAX, 'frein_reponses_par_jour' => self::MAX,
            'pression_max_par_organisation_30j' => self::MAX, 'plafond_jour' => self::MAX, 'heure_de' => self::HEURE_DEBUT,
            'heure_a' => self::HEURE_FIN, 'jours_ouvres_seulement' => self::VRAI, 'test_du_matin_bloquant' => self::VRAI, 'jours' => self::JOURS,
        ];
    }

    /** @return array<string, mixed> valeurs par défaut (les plus prudentes connues) */
    public static function defauts(): array
    {
        return [
            'pause_si_refus_pct' => 2.0, 'pause_si_plaintes_pour_mille' => 1.0, 'test_du_matin_bloquant' => true,
            'frein_reponses_par_jour' => 15, 'pression_max_par_organisation_30j' => 3, 'heure_de' => '08:00', 'heure_a' => '19:00',
            'jours_ouvres_seulement' => true, 'plafond_jour' => (int) config('envoi.plafond_jour', 20), 'jours' => self::JOURS_OUVRES,
        ];
    }

    /**
     * @param  array<string, mixed>  $actuel
     * @param  array<string, mixed>  $demande  seuls les champs présents ici ET connus sont examinés
     * @return array<string, mixed> $actuel durci des champs demandés
     *
     * @throws AssouplissementRefuse si au moins un champ serait plus lâche (aucun champ n'est alors retenu)
     */
    public static function durcir(array $actuel, array $demande): array
    {
        $refuses = [];
        foreach (self::sens() as $champ => $sens) {
            if (! array_key_exists($champ, $demande)) {
                continue;
            }
            $a = $actuel[$champ] ?? self::defauts()[$champ];
            $d = $demande[$champ];
            $ok = match ($sens) {
                self::MAX => is_numeric($d) && (float) $d <= (float) $a + 1e-9,
                self::HEURE_DEBUT => is_string($d) && strcmp($d, (string) $a) >= 0,
                self::HEURE_FIN => is_string($d) && strcmp($d, (string) $a) <= 0,
                self::VRAI => $d === true || $a !== true,
                default => is_array($d) && array_diff($d, (array) $a) === [],
            };
            if (! $ok) {
                $refuses[] = $champ;
            }
        }
        if ($refuses !== []) {
            throw new AssouplissementRefuse($refuses);
        }

        return array_merge($actuel, array_intersect_key($demande, self::sens()));
    }

    /**
     * Erreur de FORME (type, bornes, heure illisible, début après fin) ou null.
     *
     * @param  array<string, mixed>  $r
     */
    public static function invalide(array $r): ?string
    {
        foreach (['pause_si_refus_pct', 'pause_si_plaintes_pour_mille'] as $c) {
            if (isset($r[$c]) && (! is_numeric($r[$c]) || $r[$c] < 0 || $r[$c] > 100)) {
                return "« {$c} » doit être un nombre entre 0 et 100.";
            }
        }
        foreach (['frein_reponses_par_jour', 'pression_max_par_organisation_30j', 'plafond_jour'] as $c) {
            if (isset($r[$c]) && (! is_int($r[$c]) || $r[$c] < ($c === 'frein_reponses_par_jour' ? 0 : 1) || $r[$c] > 100000)) {
                return "« {$c} » doit être un entier positif.";
            }
        }
        foreach (['heure_de', 'heure_a'] as $c) {
            if (isset($r[$c]) && (! is_string($r[$c]) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $r[$c]))) {
                return "« {$c} » doit être une heure HH:MM.";
            }
        }
        if (isset($r['heure_de'], $r['heure_a']) && strcmp($r['heure_de'], $r['heure_a']) >= 0) {
            return 'L’heure de début doit précéder l’heure de fin.';
        }
        foreach (['jours_ouvres_seulement', 'test_du_matin_bloquant'] as $c) {
            if (isset($r[$c]) && ! is_bool($r[$c])) {
                return "« {$c} » doit être vrai ou faux.";
            }
        }
        if (isset($r['jours']) && (! is_array($r['jours']) || array_diff($r['jours'], ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim']) !== [])) {
            return 'Les jours doivent être parmi lun, mar, mer, jeu, ven, sam, dim.';
        }

        return null;
    }
}
