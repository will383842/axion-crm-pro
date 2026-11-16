<?php

namespace App\Envoi\Serveur\Kumo;

use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Registre DURABLE de l'adaptateur Kumo (dette K9-registre-durable) : tables `envoi_kumo_injections`, `envoi_kumo_etat`,
 * `envoi_kumo_5xx` (C3-0, RLS FORCE par espace, ni DELETE ni TRUNCATE). Rien n'est jamais effacé : la remise à zéro des
 * 5xx écrit `remise_5xx_le`, `tout()` borne seulement la LECTURE. Aucune adresse n'est stockée.
 *
 * Chaque méthode s'exécute sous `WorkspaceContext::run($ws, …)` ET filtre `workspace_id` explicitement (deux barrières).
 * Atomicité : `ecrireSi` est un compare-and-set exécuté par Postgres (INSERT … ON CONFLICT DO NOTHING, ou UPDATE
 * conditionnel sur l'état ET l'instant, anti-ABA) ; `prochaineSequence` est un upsert qui verrouille la ligne de l'espace.
 *
 * Limite documentée : `tout()` ne rend que les 30 derniers jours (sur `instant`), triés par clé d'idempotence.
 */
final class RegistreInjectionsDb implements RegistreInjections
{
    private const FENETRE_TOUT_SECONDES = 30 * 86400;

    public function lire(string $workspaceId, string $cle): ?array
    {
        return $this->dans($workspaceId, function () use ($workspaceId, $cle): ?array {
            $l = DB::table('envoi_kumo_injections')->where('workspace_id', $workspaceId)->where('cle_idempotence', $cle)->first();

            return $l === null ? null : self::ligne($l);
        });
    }

    public function ecrire(string $workspaceId, string $cle, array $ligne): void
    {
        $this->dans($workspaceId, function () use ($workspaceId, $cle, $ligne): void {
            // Identité (id_injection) figée par le déclencheur : seule la valeur d'origine est conservée. `seq` posé reste.
            DB::statement(
                'INSERT INTO envoi_kumo_injections (workspace_id, cle_idempotence, id_injection, etat, reporte_au, instant, jeton, code, seq)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (workspace_id, cle_idempotence) DO UPDATE SET
                    etat = EXCLUDED.etat, reporte_au = EXCLUDED.reporte_au, instant = EXCLUDED.instant, jeton = EXCLUDED.jeton,
                    code = EXCLUDED.code, seq = COALESCE(envoi_kumo_injections.seq, EXCLUDED.seq)',
                self::valeurs($workspaceId, $cle, $ligne),
            );
        });
    }

    public function ecrireSi(string $workspaceId, string $cle, ?string $etatAttendu, ?int $instantAttendu, array $ligne): bool
    {
        return $this->dans($workspaceId, function () use ($workspaceId, $cle, $etatAttendu, $instantAttendu, $ligne): bool {
            if ($etatAttendu === null) {
                if ($instantAttendu !== null) {
                    return false; // « clé absente » n'a pas d'instant
                }

                return DB::affectingStatement(
                    'INSERT INTO envoi_kumo_injections (workspace_id, cle_idempotence, id_injection, etat, reporte_au, instant, jeton, code, seq)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (workspace_id, cle_idempotence) DO NOTHING',
                    self::valeurs($workspaceId, $cle, $ligne),
                ) === 1;
            }
            if ($instantAttendu === null) {
                return false; // une ligne existante a toujours un instant
            }

            return DB::affectingStatement(
                'UPDATE envoi_kumo_injections
                    SET etat = ?, reporte_au = ?, instant = ?, jeton = ?, code = ?, seq = COALESCE(seq, ?::BIGINT)
                  WHERE workspace_id = ? AND cle_idempotence = ? AND etat = ? AND instant = ?',
                [
                    $ligne['etat'], $ligne['reporte_au'], $ligne['instant'], $ligne['jeton'], $ligne['code'] ?? null, $ligne['seq'] ?? null,
                    $workspaceId, $cle, $etatAttendu, $instantAttendu,
                ],
            ) === 1;
        });
    }

    public function tout(string $workspaceId): array
    {
        return $this->dans($workspaceId, function () use ($workspaceId): array {
            $depuis = Carbon::now()->getTimestamp() - self::FENETRE_TOUT_SECONDES;
            $sortie = [];
            foreach (DB::table('envoi_kumo_injections')->where('workspace_id', $workspaceId)->where('instant', '>=', $depuis)->orderBy('cle_idempotence')->get() as $l) {
                $sortie[(string) $l->cle_idempotence] = self::ligne($l);
            }

            return $sortie;
        });
    }

    public function enPause(string $workspaceId): bool
    {
        return $this->dans($workspaceId, fn (): bool => (bool) DB::table('envoi_kumo_etat')->where('workspace_id', $workspaceId)->value('en_pause'));
    }

    public function fixerPause(string $workspaceId, bool $pause): void
    {
        $this->dans($workspaceId, function () use ($workspaceId, $pause): void {
            DB::statement(
                'INSERT INTO envoi_kumo_etat (workspace_id, en_pause) VALUES (?, ?)
                 ON CONFLICT (workspace_id) DO UPDATE SET en_pause = EXCLUDED.en_pause',
                [$workspaceId, $pause ? 't' : 'f'],
            );
        });
    }

    public function noter5xx(string $workspaceId, int $instant): void
    {
        $this->dans($workspaceId, function () use ($workspaceId, $instant): void {
            DB::table('envoi_kumo_5xx')->insert(['workspace_id' => $workspaceId, 'instant' => $instant]);
        });
    }

    public function compter5xx(string $workspaceId, int $depuis): int
    {
        return $this->dans($workspaceId, function () use ($workspaceId, $depuis): int {
            $remise = (int) DB::table('envoi_kumo_etat')->where('workspace_id', $workspaceId)->value('remise_5xx_le');

            return DB::table('envoi_kumo_5xx')->where('workspace_id', $workspaceId)->where('instant', '>=', max($depuis, $remise))->count();
        });
    }

    /**
     * Remise à zéro SANS effacer : `remise_5xx_le` passe juste APRÈS le plus récent des « maintenant » et des 5xx déjà notés,
     * pour que même une horloge figée (ou des instants notés dans le futur proche) ne ressuscite pas l'ancien passif.
     * Contrepartie assumée : un 5xx noté dans la seconde qui suit la remise n'est pas compté.
     */
    public function reinitialiser5xx(string $workspaceId): void
    {
        $this->dans($workspaceId, function () use ($workspaceId): void {
            DB::statement(
                'INSERT INTO envoi_kumo_etat (workspace_id, remise_5xx_le)
                 VALUES (?, GREATEST(?::BIGINT, COALESCE((SELECT max(instant) FROM envoi_kumo_5xx WHERE workspace_id = ?), 0)) + 1)
                 ON CONFLICT (workspace_id) DO UPDATE SET remise_5xx_le = GREATEST(envoi_kumo_etat.remise_5xx_le, EXCLUDED.remise_5xx_le)',
                [$workspaceId, Carbon::now()->getTimestamp(), $workspaceId],
            );
        });
    }

    public function prochaineSequence(string $workspaceId): int
    {
        return $this->dans($workspaceId, function () use ($workspaceId): int {
            $r = DB::selectOne(
                'INSERT INTO envoi_kumo_etat (workspace_id, derniere_seq) VALUES (?, 1)
                 ON CONFLICT (workspace_id) DO UPDATE SET derniere_seq = envoi_kumo_etat.derniere_seq + 1
                 RETURNING derniere_seq',
                [$workspaceId],
            );

            return (int) $r->derniere_seq;
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $f
     * @return T
     */
    private function dans(string $workspaceId, \Closure $f): mixed
    {
        foreach (['envoi_kumo_injections', 'envoi_kumo_etat', 'envoi_kumo_5xx'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("registre Kumo durable indisponible : table {$table} absente (migration C3-0 non appliquée).");
            }
        }

        return WorkspaceContext::run($workspaceId, $f);
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return list<mixed>
     */
    private static function valeurs(string $workspaceId, string $cle, array $ligne): array
    {
        return [$workspaceId, $cle, $ligne['id'], $ligne['etat'], $ligne['reporte_au'], $ligne['instant'], $ligne['jeton'], $ligne['code'] ?? null, $ligne['seq'] ?? null];
    }

    /** @return array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string, seq?: int, code?: int} */
    private static function ligne(\stdClass $l): array
    {
        $r = ['id' => (string) $l->id_injection, 'etat' => (string) $l->etat, 'reporte_au' => $l->reporte_au === null ? null : (int) $l->reporte_au,
            'instant' => (int) $l->instant, 'jeton' => (string) $l->jeton];
        if ($l->seq !== null) {
            $r['seq'] = (int) $l->seq;
        }
        if ($l->code !== null) {
            $r['code'] = (int) $l->code;
        }

        return $r;
    }
}
