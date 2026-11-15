<?php

namespace App\Envoi\Depot;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Conservation de `envoi_tentatives` (D16) : détail gardé 400 jours, puis agrégé par jour dans `envoi_tentatives_jours`.
 *
 * Boucle sur les espaces non supprimés, chacun sous son contexte, et appelle la fonction `envoi_tentatives_archiver`
 * (qui filtre `workspace_id` et ne rend que des jours entiers). Aucune donnée de personne : seules les lignes
 * techniques de `envoi_tentatives` sont concernées. Un espace en erreur n'arrête pas les suivants.
 */
final class RetentionTentatives
{
    public const CONSERVATION_JOURS = 400;

    /** @return array{espaces: int, lignes: int, erreurs: int} */
    public function archiver(?DateTimeImmutable $maintenant = null): array
    {
        $jour = ($maintenant ?? new DateTimeImmutable('now'))
            ->setTimezone(new DateTimeZone('Europe/Paris'))
            ->modify('-' . self::CONSERVATION_JOURS . ' days')
            ->format('Y-m-d');

        $bilan = ['espaces' => 0, 'lignes' => 0, 'erreurs' => 0];
        foreach (DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id') as $espace) {
            $bilan['espaces']++;
            try {
                $bilan['lignes'] += WorkspaceContext::run((string) $espace, fn (): int => DB::transaction(fn (): int => (int) DB::selectOne(
                    'SELECT envoi_tentatives_archiver(?::uuid, ?::date) AS n',
                    [(string) $espace, $jour],
                )->n));
            } catch (Throwable) {
                $bilan['erreurs']++;
            }
        }

        return $bilan;
    }
}
