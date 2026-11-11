<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Valide une réponse JSON contre un schéma de backend/openapi/envoi/*.yaml (sous-ensemble utilisé par ces contrats :
 * $ref locaux ou vers un autre fichier du dossier, type, required, properties, enum, const, pattern, minimum/maximum,
 * minLength/maxLength, items, oneOf, format date et date-time). Renvoie la liste des écarts (vide = conforme).
 */
final class ContratOpenApiEnvoi
{
    /** @var array<string, array<string, mixed>> */
    private static array $fichiers = [];

    /** @return list<string> écarts */
    public static function ecarts(string $fichier, string $schema, mixed $valeur): array
    {
        $e = [];
        self::verifier($fichier, ['$ref' => '#/components/schemas/' . $schema], $valeur, $schema, $e);

        return $e;
    }

    /** @return array<string, mixed> */
    private static function charger(string $fichier): array
    {
        return self::$fichiers[$fichier] ??= Yaml::parseFile(base_path('openapi/envoi/' . $fichier));
    }

    /** @param list<string> $e */
    private static function verifier(string $fichier, array $s, mixed $v, string $chemin, array &$e): void
    {
        if (isset($s['$ref'])) {
            [$autre, $pointeur] = explode('#', (string) $s['$ref'], 2) + [1 => ''];
            $cible = $autre === '' ? $fichier : basename($autre);
            $n = self::charger($cible);
            foreach (array_filter(explode('/', $pointeur)) as $cle) {
                $n = $n[$cle];
            }
            self::verifier($cible, $n, $v, $chemin, $e);

            return;
        }
        if (isset($s['oneOf'])) {
            foreach ($s['oneOf'] as $option) {
                $essai = [];
                self::verifier($fichier, $option, $v, $chemin, $essai);
                if ($essai === []) {
                    return;
                }
            }
            $e[] = "$chemin : aucune alternative oneOf ne convient";

            return;
        }
        if (array_key_exists('const', $s) && $v !== $s['const']) {
            $e[] = "$chemin : valeur constante attendue";
        }
        if (isset($s['enum']) && ! in_array($v, $s['enum'], true)) {
            $e[] = "$chemin : hors énumération";
        }
        $type = $s['type'] ?? null;
        $ok = match ($type) {
            'string' => is_string($v), 'integer' => is_int($v), 'boolean' => is_bool($v), 'null' => $v === null,
            'array' => is_array($v) && array_is_list($v), 'object' => is_array($v) && ! ($v !== [] && array_is_list($v)),
            default => true,
        };
        if (! $ok) {
            $e[] = "$chemin : type $type attendu";

            return;
        }
        if (is_string($v)) {
            if (isset($s['pattern']) && preg_match('/' . str_replace('/', '\/', $s['pattern']) . '/u', $v) !== 1) {
                $e[] = "$chemin : motif non respecté";
            }
            if ((isset($s['minLength']) && mb_strlen($v) < $s['minLength']) || (isset($s['maxLength']) && mb_strlen($v) > $s['maxLength'])) {
                $e[] = "$chemin : longueur hors bornes";
            }
            if (($s['format'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                $e[] = "$chemin : date attendue";
            }
            if (($s['format'] ?? '') === 'date-time' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $v) !== 1) {
                $e[] = "$chemin : date-heure ISO 8601 attendue";
            }
        }
        if (is_int($v) && ((isset($s['minimum']) && $v < $s['minimum']) || (isset($s['maximum']) && $v > $s['maximum']))) {
            $e[] = "$chemin : entier hors bornes";
        }
        if ($type === 'array') {
            foreach ($v as $i => $item) {
                self::verifier($fichier, $s['items'] ?? [], $item, "{$chemin}[$i]", $e);
            }
        }
        if ($type === 'object') {
            foreach ($s['required'] ?? [] as $cle) {
                if (! array_key_exists($cle, $v)) {
                    $e[] = "$chemin : clé « $cle » manquante";
                }
            }
            foreach ($s['properties'] ?? [] as $cle => $sous) {
                if (array_key_exists($cle, $v)) {
                    self::verifier($fichier, $sous, $v[$cle], "$chemin.$cle", $e);
                }
            }
        }
    }
}
