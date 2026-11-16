<?php

namespace Tests\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Chemin LÉGAL pour relier une campagne à sa séquence (lot X1-L1) : la campagne est « programmee », sans lien ; on crée la séquence
 * AVEC campagne_id (elle naît en pause), ses étapes, puis on pose envoi_campagnes.sequence_id (une seule fois, version + 1).
 * Les tests n'écrivent plus jamais un UUID au hasard dans sequence_id : la base le refuse (clé étrangère + garde).
 */
final class LienCampagneSequence
{
    /**
     * @return array{sequence: string, version: int} identifiant de la séquence et nouvelle version de la campagne
     */
    public static function poser(ConnectionInterface $c, string $ws, string $campagne, int $versionActuelle, int $nbEtapes): array
    {
        $sequence = self::creerSequence($c, $ws, $campagne, $nbEtapes);
        $version = $versionActuelle + 1;
        $c->table('envoi_campagnes')->where('id', $campagne)->update(['sequence_id' => $sequence, 'version' => $version]);

        return ['sequence' => $sequence, 'version' => $version];
    }

    /** Crée une séquence (reliée à la campagne si $campagne n'est pas nul) et ses étapes ; rend son identifiant. */
    public static function creerSequence(ConnectionInterface $c, string $ws, ?string $campagne, int $nbEtapes, array $plus = []): string
    {
        $sequence = (string) Str::uuid();
        $c->table('envoi_sequences')->insert(['id' => $sequence, 'workspace_id' => $ws, 'nom' => 'Séquence fictive', 'campagne_id' => $campagne] + $plus);
        for ($rang = 1; $rang <= $nbEtapes; $rang++) {
            $c->table('envoi_sequence_etapes')->insert([
                'workspace_id' => $ws, 'sequence_id' => $sequence, 'rang' => $rang, 'delai_jours_ouvres' => $rang === 1 ? 0 : 2,
                'sujet' => 'Sujet fictif ' . $rang, 'corps' => 'Corps fictif ' . $rang,
            ]);
        }

        return $sequence;
    }
}
