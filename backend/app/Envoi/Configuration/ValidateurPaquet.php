<?php

namespace App\Envoi\Configuration;

use App\Envoi\Dns\ConfigurationDomaine;
use InvalidArgumentException;

/** Liste blanche stricte du paquet. Aucune normalisation : ce qui n'est pas déjà canonique est refusé. */
final class ValidateurPaquet
{
    public const PROFONDEUR_MAX = 3;

    public const DOMAINES_MAX = 50;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private const DOMAINE = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/D';

    /**
     * Profondeur et largeur bornées, par pile (aucune récursion) : à contrôler AVANT tout parcours récursif.
     *
     * @param  array<mixed>  $brut
     */
    public static function dansLesBornes(array $brut): bool
    {
        if (isset($brut['domaines']) && is_array($brut['domaines']) && count($brut['domaines']) > self::DOMAINES_MAX) {
            return false;
        }
        $pile = [[$brut, 1]];
        while ($pile !== []) {
            [$noeud, $profondeur] = array_pop($pile);
            if ($profondeur > self::PROFONDEUR_MAX || count($noeud) > self::DOMAINES_MAX) {
                return false;
            }
            foreach ($noeud as $enfant) {
                if (is_array($enfant)) {
                    $pile[] = [$enfant, $profondeur + 1];
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $brut
     *
     * @throws PaquetInvalide
     */
    public static function verifier(string $workspaceId, array $brut): void
    {
        if (! self::dansLesBornes($brut)) {
            throw new PaquetInvalide('structure hors bornes (profondeur ou nombre de domaines)');
        }
        if (preg_match(self::UUID, $workspaceId) !== 1) {
            throw new PaquetInvalide('espace attendu invalide');
        }
        self::cles($brut, ['version', 'espace', 'domaines'], 'paquet');
        if (($brut['version'] ?? null) !== 1) {
            throw new PaquetInvalide('version non supportée');
        }
        if (($brut['espace'] ?? null) !== $workspaceId) {
            throw new PaquetInvalide('espace du paquet différent de l\'espace visé');
        }
        $domaines = $brut['domaines'];
        if (! is_array($domaines) || $domaines === [] || ! array_is_list($domaines)) {
            throw new PaquetInvalide('domaines absents ou mal formés');
        }
        $plafondDur = min((int) config('envoi.plafond_jour'), (int) config('envoi.plafond_global_jour'));
        if ($plafondDur < 1) {
            throw new PaquetInvalide('plafond dur local invalide');
        }
        $vus = [];
        $somme = 0;
        foreach ($domaines as $d) {
            if (! is_array($d)) {
                throw new PaquetInvalide('domaine mal formé');
            }
            self::cles($d, ['domaine', 'debit_heure', 'plafond_jour'], 'domaine');
            $nom = $d['domaine'];
            if (! is_string($nom) || preg_match(self::DOMAINE, $nom) !== 1) {
                throw new PaquetInvalide('domaine non canonique');
            }
            try {
                new ConfigurationDomaine($nom, 's1', $nom, '192.0.2.1');
            } catch (InvalidArgumentException $e) {
                throw new PaquetInvalide($e->getMessage());
            }
            if (isset($vus[$nom])) {
                throw new PaquetInvalide('domaine en double');
            }
            $vus[$nom] = true;
            $debit = $d['debit_heure'];
            $plafond = $d['plafond_jour'];
            if (! is_int($debit) || ! is_int($plafond) || $plafond < 1 || $plafond > $plafondDur) {
                throw new PaquetInvalide('plafond du domaine invalide ou au-dessus du plafond dur local');
            }
            if ($debit < 1 || $debit > $plafond) {
                throw new PaquetInvalide('débit invalide (entre 1 et le plafond du domaine)');
            }
            $somme += $plafond;
        }
        if ($somme > $plafondDur) {
            throw new PaquetInvalide('somme des plafonds au-dessus du plafond dur local');
        }
    }

    /**
     * @param  array<mixed>  $tableau
     * @param  list<string>  $attendues
     *
     * @throws PaquetInvalide
     */
    private static function cles(array $tableau, array $attendues, string $quoi): void
    {
        $cles = array_map('strval', array_keys($tableau));
        sort($cles);
        sort($attendues);
        if ($cles !== $attendues) {
            throw new PaquetInvalide("clés inattendues ou manquantes ({$quoi})");
        }
    }
}
