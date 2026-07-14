<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcule `periodicity` pour les médias issus des registres CPPAP/SPEL déjà en
 * base, en réutilisant la MÊME logique que l'import
 * ({@see ImportMediaFromOpendatasoft::derivePeriodicity()}).
 *
 * ⚠️ ÉTAT 2026-07-14 : la dérivation renvoie actuellement NULL (aucune source
 * fiable — le dataset ne porte pas de périodicité et la lettre du n° CPPAP encode
 * le régime, pas la périodicité). Cette commande existe donc surtout comme
 * POINT D'ENTRÉE reprenable : le jour où `derivePeriodicity()` sait dériver (nouveau
 * champ API, table de correspondance officielle…), un `media:backfill-periodicity`
 * repeuple toute la base SANS toucher au code d'import.
 *
 * REPRENABLE + IDEMPOTENT : ne traite que les médias cppap/spel dont periodicity
 * est NULL, par chunks bornés. N'écrase jamais une valeur déjà posée.
 */
class MediaBackfillPeriodicity extends Command
{
    protected $signature = 'media:backfill-periodicity {--limit=0 : Nombre max de médias à traiter (0 = tous)} {--batch=1000 : Taille de chunk}';

    protected $description = 'Recalcule periodicity pour les médias cppap/spel (réutilise la logique d\'import). Reprenable.';

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $batch = max(100, (int) $this->option('batch'));

        $processed = 0;
        $written = 0;
        $lastId = 0;

        while (true) {
            $take = $batch;
            if ($limit > 0) {
                $take = min($take, $limit - $processed);
            }
            if ($take <= 0) {
                break;
            }

            $medias = DB::table('media')
                ->whereIn('source', ['cppap', 'spel'])
                ->whereNull('periodicity')
                ->whereNull('deleted_at')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($take)
                ->get(['id', 'cppap_number']);

            if ($medias->isEmpty()) {
                break;
            }

            $updates = []; // [id => periodicity]
            foreach ($medias as $m) {
                $lastId = (int) $m->id;
                $processed++;
                $value = ImportMediaFromOpendatasoft::derivePeriodicity($m->cppap_number);
                if ($value !== null) {
                    $updates[(int) $m->id] = $value;
                }
            }

            if ($updates !== []) {
                $written += $this->flush($updates);
            }

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $this->info("✅ Terminé : {$processed} médias examinés · {$written} périodicités écrites.");
        if ($written === 0 && $processed > 0) {
            $this->warn('Aucune périodicité dérivable (source fiable indisponible — cf. ImportMediaFromOpendatasoft::derivePeriodicity()).');
        }

        return self::SUCCESS;
    }

    /**
     * Écrit un chunk (bulk UPDATE … FROM VALUES). Garde-fou anti-course :
     * periodicity IS NULL → idempotent, n'écrase jamais une valeur existante.
     *
     * @param  array<int,string>  $updates  id => periodicity
     */
    private function flush(array $updates): int
    {
        $now = now()->format('Y-m-d H:i:sP');
        $rows = [];
        $bindings = [$now];
        foreach ($updates as $id => $periodicity) {
            $rows[] = '(?::bigint, ?::text)';
            $bindings[] = $id;
            $bindings[] = $periodicity;
        }

        $values = implode(',', $rows);
        $sql = "UPDATE media AS m
                SET periodicity = v.periodicity,
                    updated_at = ?::timestamptz
                FROM (VALUES {$values}) AS v(id, periodicity)
                WHERE m.id = v.id
                  AND m.periodicity IS NULL";

        return DB::update($sql, $bindings);
    }
}
