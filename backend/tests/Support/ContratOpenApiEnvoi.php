<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Validateur minimal d'un contrat OpenAPI 3.1 d'Envoi (`backend/openapi/envoi/*.yaml`) : vérifie une RÉPONSE réelle
 * contre le schéma déclaré. Lecture seule. Couvre ce que le contrat emploie : $ref (fichiers relatifs compris), type
 * (dont 'null'), required, properties, items, enum, oneOf, minimum, maxLength, pattern, format date / date-time / email.
 * Les propriétés non déclarées sont tolérées (le contrat n'écrit pas additionalProperties: false).
 */
final class ContratOpenApiEnvoi
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [];

    public function __construct(private readonly string $dossier = __DIR__ . '/../../openapi/envoi') {}

    /** @return list<string> erreurs ; vide = conforme */
    public function erreursReponse(string $zone, string $chemin, string $methode, int $statut, mixed $corps): array
    {
        $doc = $this->charger($zone . '.yaml');
        $schema = $doc['paths'][$chemin][strtolower($methode)]['responses'][(string) $statut]['content']['application/json']['schema'] ?? null;
        if (! is_array($schema)) {
            return ["aucun schéma pour {$methode} {$chemin} {$statut}"];
        }

        return $this->valider($schema, $corps, '$', $zone . '.yaml');
    }

    /** @return array<string, mixed> */
    private function charger(string $fichier): array
    {
        return $this->documents[$fichier] ??= (array) Yaml::parseFile($this->dossier . '/' . $fichier);
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function resoudre(string $ref, string $fichier): array
    {
        [$f, $pointeur] = str_contains($ref, '#') ? explode('#', $ref, 2) : [$ref, ''];
        $cible = $f === '' ? $fichier : basename($f);
        $noeud = $this->charger($cible);
        foreach (array_filter(explode('/', $pointeur), 'strlen') as $cle) {
            $noeud = $noeud[$cle] ?? throw new \RuntimeException("référence introuvable : {$ref}");
        }

        return [$noeud, $cible];
    }

    /** @return list<string> */
    private function valider(array $schema, mixed $v, string $chemin, string $fichier): array
    {
        if (isset($schema['$ref'])) {
            [$s, $f] = $this->resoudre((string) $schema['$ref'], $fichier);

            return $this->valider($s, $v, $chemin, $f);
        }
        if (isset($schema['oneOf'])) {
            $ok = 0;
            $dernieres = [];
            foreach ($schema['oneOf'] as $alt) {
                $e = $this->valider($alt, $v, $chemin, $fichier);
                $e === [] ? $ok++ : $dernieres = $e;
            }

            return $ok === 1 ? [] : ["{$chemin} : {$ok} alternatives valides sur " . count($schema['oneOf']) . ($dernieres ? ' (' . $dernieres[0] . ')' : '')];
        }
        $erreurs = [];
        if (isset($schema['type']) && ! $this->typeOk((string) $schema['type'], $v)) {
            return ["{$chemin} : type attendu {$schema['type']}, reçu " . get_debug_type($v)];
        }
        if (isset($schema['enum']) && ! in_array($v, $schema['enum'], true)) {
            $erreurs[] = "{$chemin} : valeur hors énumération";
        }
        if (isset($schema['minimum']) && is_numeric($v) && $v < $schema['minimum']) {
            $erreurs[] = "{$chemin} : inférieur au minimum";
        }
        if (($schema['format'] ?? null) === 'date' && is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            $erreurs[] = "{$chemin} : date invalide";
        }
        if (($schema['format'] ?? null) === 'date-time' && is_string($v) && (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $v) !== 1 || strtotime($v) === false)) {
            $erreurs[] = "{$chemin} : date-heure invalide";
        }
        if (($schema['format'] ?? null) === 'email' && is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            $erreurs[] = "{$chemin} : adresse e-mail invalide";
        }
        if (isset($schema['maxLength']) && is_string($v) && mb_strlen($v) > $schema['maxLength']) {
            $erreurs[] = "{$chemin} : trop long";
        }
        if (isset($schema['pattern']) && is_string($v) && preg_match('~' . str_replace('~', '\\~', (string) $schema['pattern']) . '~u', $v) !== 1) {
            $erreurs[] = "{$chemin} : ne respecte pas le motif";
        }
        if (is_array($v) && ($schema['type'] ?? null) === 'object') {
            foreach ($schema['required'] ?? [] as $cle) {
                if (! array_key_exists($cle, $v)) {
                    $erreurs[] = "{$chemin}.{$cle} : champ requis absent";
                }
            }
            foreach ($schema['properties'] ?? [] as $cle => $sous) {
                if (array_key_exists($cle, $v)) {
                    array_push($erreurs, ...$this->valider($sous, $v[$cle], "{$chemin}.{$cle}", $fichier));
                }
            }
        }
        if (is_array($v) && ($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
            foreach ($v as $i => $element) {
                array_push($erreurs, ...$this->valider($schema['items'], $element, "{$chemin}[{$i}]", $fichier));
            }
        }

        return $erreurs;
    }

    private function typeOk(string $type, mixed $v): bool
    {
        return match ($type) {
            'null' => $v === null,
            'string' => is_string($v),
            'integer' => is_int($v),
            'number' => is_int($v) || is_float($v),
            'boolean' => is_bool($v),
            'array' => is_array($v) && array_is_list($v),
            'object' => is_array($v) && ($v === [] || ! array_is_list($v)),
            default => true,
        };
    }
}
