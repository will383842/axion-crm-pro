<?php

namespace App\Envoi\Configuration;

use App\Envoi\Dns\ConfigurationDomaine;
use InvalidArgumentException;

final class ValidateurPaquet
{
    /**
     * @param  array<string, mixed>  $brut
     *
     * @throws PaquetInvalide
     */
    public static function verifier(array $brut): void
    {
        $domaines = $brut['domaines'] ?? null;
        if (! is_array($domaines) || $domaines === [] || ! array_is_list($domaines)) {
            throw new PaquetInvalide('domaines absents');
        }
        $plafondDur = max((int) config('envoi.plafond_global_jour'), (int) config('envoi.plafond_jour'));
        foreach ($domaines as $d) {
            if (! is_array($d)) {
                throw new PaquetInvalide('domaine mal formé');
            }
            try {
                new ConfigurationDomaine((string) ($d['domaine'] ?? ''), (string) ($d['selecteur'] ?? ''), (string) ($d['domaine'] ?? ''), '192.0.2.1');
            } catch (InvalidArgumentException $e) {
                throw new PaquetInvalide($e->getMessage());
            }
            $cle = (string) ($d['cle_publique'] ?? '');
            if (preg_match('/^[A-Za-z0-9+\/=]{40,}$/', $cle) !== 1) {
                throw new PaquetInvalide('clé publique DKIM absente ou invalide (jamais de clé privée)');
            }
            $debit = $d['debit_heure'] ?? null;
            $plafond = $d['plafond_jour'] ?? null;
            if (! is_int($debit) || $debit < 1 || ! is_int($plafond) || $plafond < 1) {
                throw new PaquetInvalide('débit ou plafond invalide');
            }
            if ($plafond > $plafondDur) {
                throw new PaquetInvalide('plafond souhaité au-dessus du plafond dur local');
            }
        }
    }
}
