<?php

namespace App\Crm\Emails\Dns;

use RuntimeException;

/**
 * Le format des messages DNS (RFC 1035 §4), réduit à ce dont la vérification
 * a besoin : fabriquer une QUESTION (MX, A ou AAAA), lire une RÉPONSE, et en
 * CONCLURE. Fonctions PURES : aucune entrée-sortie ici, tout se teste octet par
 * octet sans réseau.
 */
final class MessageDns
{
    public const TYPE_A = 1;

    public const TYPE_CNAME = 5;

    public const TYPE_MX = 15;

    public const TYPE_AAAA = 28;

    public const RCODE_OK = 0;

    public const RCODE_NXDOMAIN = 3;

    /** Une question récursive (RD) pour `$domaine`, type `$type`, classe IN. */
    public static function requete(int $id, string $domaine, int $type): string
    {
        $nom = '';
        foreach (explode('.', rtrim($domaine, '.')) as $libelle) {
            $longueur = strlen($libelle);
            if ($longueur === 0 || $longueur > 63) {
                throw new RuntimeException('Libellé DNS invalide.');
            }
            $nom .= chr($longueur) . $libelle;
        }

        return pack('nnnnnn', $id & 0xFFFF, 0x0100, 1, 0, 0, 0) . $nom . "\0" . pack('nn', $type, 1);
    }

    /**
     * @return array{id: int, reponse: bool, tronque: bool, rcode: int, question: string, qtype: int, enregistrements: list<array{type: int, cible: ?string}>}
     */
    public static function lire(string $paquet): array
    {
        if (strlen($paquet) < 12) {
            throw new RuntimeException('Réponse DNS trop courte.');
        }
        $entete = unpack('nid/ndrapeaux/nqd/nan/nns/nar', substr($paquet, 0, 12));
        if ($entete === false) {
            throw new RuntimeException('En-tête DNS illisible.');
        }
        $drapeaux = (int) $entete['drapeaux'];
        $position = 12;

        $question = '';
        $qtype = 0;
        for ($i = 0; $i < (int) $entete['qd']; $i++) {
            [$nom, $position] = self::nom($paquet, $position);
            $q = self::entiers($paquet, $position, 'ntype/nclasse', 4);
            if ($i === 0) {
                $question = $nom;
                $qtype = $q['type'];
            }
            $position += 4;
        }

        $enregistrements = [];
        for ($i = 0; $i < (int) $entete['an']; $i++) {
            [, $position] = self::nom($paquet, $position);
            $rr = self::entiers($paquet, $position, 'ntype/nclasse/Nttl/nlongueur', 10);
            $position += 10;
            if ($position + $rr['longueur'] > strlen($paquet)) {
                throw new RuntimeException('Enregistrement DNS tronqué.');
            }
            $cible = null;
            if ($rr['type'] === self::TYPE_MX) {
                if ($rr['longueur'] < 3) {
                    throw new RuntimeException('Enregistrement MX illisible.');
                }
                [$cible] = self::nom($paquet, $position + 2);
            }
            $enregistrements[] = ['type' => $rr['type'], 'cible' => $cible];
            $position += $rr['longueur'];
        }

        return [
            'id' => (int) $entete['id'],
            'reponse' => ($drapeaux & 0x8000) !== 0,
            'tronque' => ($drapeaux & 0x0200) !== 0,
            'rcode' => $drapeaux & 0x000F,
            'question' => $question,
            'qtype' => $qtype,
            'enregistrements' => $enregistrements,
        ];
    }

    /**
     * Ce qu'une réponse MX permet de conclure, ou null s'il faut demander
     * l'adresse (A puis AAAA : le « MX implicite »).
     *
     * @param  array{rcode: int, tronque: bool, enregistrements: list<array{type: int, cible: ?string}>}  $reponse
     */
    public static function conclureMx(array $reponse): ?ResultatDns
    {
        if ($reponse['rcode'] === self::RCODE_NXDOMAIN) {
            return new ResultatDns(ResultatDns::INEXISTANT);
        }
        if ($reponse['rcode'] !== self::RCODE_OK) {
            return new ResultatDns(ResultatDns::INDETERMINE);
        }
        $cibles = [];
        foreach ($reponse['enregistrements'] as $e) {
            if ($e['type'] === self::TYPE_MX) {
                $cibles[] = (string) $e['cible'];
            }
        }
        if ($cibles !== []) {
            // RFC 7505 : le MX nul est SEUL. Accompagné d'un vrai MX, il ne
            // décide rien — le vrai MX reçoit.
            $vraies = array_values(array_filter($cibles, static fn (string $c): bool => $c !== ''));

            return $vraies === []
                ? new ResultatDns(ResultatDns::MX_NUL)
                : new ResultatDns(ResultatDns::MX, $vraies[0]);
        }

        // Tronquée sans enregistrement lisible : on ne sait pas.
        return $reponse['tronque'] ? new ResultatDns(ResultatDns::INDETERMINE) : null;
    }

    /**
     * Ce qu'une réponse A (ou AAAA) permet de conclure, ou null si le domaine
     * existe sans cette adresse (demander le type suivant, ou conclure
     * `sans_courrier` après le dernier).
     *
     * @param  array{rcode: int, tronque: bool, enregistrements: list<array{type: int, cible: ?string}>}  $reponse
     */
    public static function conclureAdresse(array $reponse, int $type): ?ResultatDns
    {
        if ($reponse['rcode'] === self::RCODE_NXDOMAIN) {
            return new ResultatDns(ResultatDns::INEXISTANT);
        }
        if ($reponse['rcode'] !== self::RCODE_OK) {
            return new ResultatDns(ResultatDns::INDETERMINE);
        }
        foreach ($reponse['enregistrements'] as $e) {
            if ($e['type'] === $type) {
                return new ResultatDns(ResultatDns::A);
            }
        }

        return $reponse['tronque'] ? new ResultatDns(ResultatDns::INDETERMINE) : null;
    }

    /**
     * Un nom de domaine à `$position`, pointeurs de compression compris
     * (RFC 1035 §4.1.4). Rend le nom (sans point final, minuscules ; `''`
     * pour la racine) et la position qui SUIT le nom à l'endroit lu.
     *
     * @return array{0: string, 1: int}
     */
    private static function nom(string $paquet, int $position): array
    {
        $libelles = [];
        $suite = null;
        $sauts = 0;
        $taille = strlen($paquet);
        while (true) {
            if ($position >= $taille) {
                throw new RuntimeException('Nom DNS tronqué.');
            }
            $longueur = ord($paquet[$position]);
            if ($longueur === 0) {
                $position++;

                break;
            }
            if (($longueur & 0xC0) === 0xC0) {
                if ($position + 1 >= $taille || ++$sauts > 32) {
                    throw new RuntimeException('Pointeur DNS invalide.');
                }
                $suite ??= $position + 2;
                $position = (($longueur & 0x3F) << 8) | ord($paquet[$position + 1]);

                continue;
            }
            if ($longueur > 63 || $position + 1 + $longueur > $taille) {
                throw new RuntimeException('Libellé DNS invalide.');
            }
            $libelles[] = substr($paquet, $position + 1, $longueur);
            $position += 1 + $longueur;
        }

        return [strtolower(implode('.', $libelles)), $suite ?? $position];
    }

    /**
     * @return array<string, int>
     */
    private static function entiers(string $paquet, int $position, string $format, int $longueur): array
    {
        if ($position + $longueur > strlen($paquet)) {
            throw new RuntimeException('Réponse DNS tronquée.');
        }
        $valeurs = unpack($format, substr($paquet, $position, $longueur));
        if ($valeurs === false) {
            throw new RuntimeException('Réponse DNS illisible.');
        }

        return array_map('intval', $valeurs);
    }
}
