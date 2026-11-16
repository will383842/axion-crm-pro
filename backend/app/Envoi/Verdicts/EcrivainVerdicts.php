<?php

namespace App\Envoi\Verdicts;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * L'unique écrivain de `envoi_verdicts_adresse` : INSERT seulement (le rôle applicatif n'a ni UPDATE ni DELETE).
 * Pas de nouvelle ligne si le dernier verdict est identique (verdict, source, motif) et a moins de 20 jours.
 * Aucune adresse en clair : la clé est l'empreinte.
 */
final class EcrivainVerdicts
{
    public const RAFRAICHIR_APRES_JOURS = 20;

    public static function schemaPret(): bool
    {
        return Schema::hasTable('envoi_verdicts_adresse') && Schema::hasColumn('envoi_verdicts_adresse', 'motif');
    }

    /**
     * @param  array<string, VerdictAdresse|null>  $verdicts
     * @return int lignes insérées
     */
    public function ecrire(string $ws, array $verdicts, string $source, DateTimeImmutable $maintenant): int
    {
        if (WorkspaceContext::current() !== $ws) {
            throw new RuntimeException('Écriture des verdicts refusée : le contexte d’espace n’est pas celui de l’espace visé.');
        }
        if (preg_match('/^[a-z0-9-]{1,32}$/', $source) !== 1) {
            throw new InvalidArgumentException('Source de verdict invalide.');
        }
        $a_ecrire = array_filter($verdicts, static fn ($v): bool => $v instanceof VerdictAdresse);
        if ($a_ecrire === []) {
            return 0;
        }
        if (! self::schemaPret()) {
            throw new RuntimeException('Schéma des verdicts incomplet (colonne motif absente).');
        }

        $derniers = [];
        foreach (array_chunk(array_keys($a_ecrire), 500) as $paquet) {
            $lignes = DB::select(
                'SELECT DISTINCT ON (empreinte_adresse) empreinte_adresse, verdict, source, motif, verifie_le
                 FROM envoi_verdicts_adresse WHERE workspace_id = ? AND empreinte_adresse IN (' . implode(', ', array_fill(0, count($paquet), '?')) . ')
                 ORDER BY empreinte_adresse, verifie_le DESC, id DESC',
                array_merge([$ws], $paquet),
            );
            foreach ($lignes as $l) {
                $derniers[trim((string) $l->empreinte_adresse)] = $l;
            }
        }

        $n = 0;
        $limite = $maintenant->modify('-' . self::RAFRAICHIR_APRES_JOURS . ' days')->getTimestamp();
        foreach ($a_ecrire as $empreinte => $v) {
            $d = $derniers[$empreinte] ?? null;
            if ($d !== null && $d->verdict === $v->verdict && $d->source === $source && $d->motif === $v->motif
                && (new DateTimeImmutable((string) $d->verifie_le))->getTimestamp() > $limite) {
                continue;
            }
            DB::table('envoi_verdicts_adresse')->insert([
                'workspace_id' => $ws, 'empreinte_adresse' => (string) $empreinte, 'verdict' => $v->verdict,
                'verifie_le' => $v->verifieLe->format('c'), 'source' => $source, 'motif' => $v->motif,
            ]);
            $n++;
        }

        return $n;
    }
}
