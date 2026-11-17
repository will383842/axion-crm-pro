<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Envoi L0b (PLAN ADRESSES GÉNÉRALES, #680) — « un seul message de repli par entreprise, à vie, tous statuts » : index UNIQUE PARTIEL
 * (workspace_id, entreprise_id) WHERE repli_generique (AJOUT D'INDEX SEUL, même schéma que 2026_12_05_000180).
 *
 * Contrôle préalable HORS RLS (table en FORCE ROW LEVEL SECURITY) : si des entreprises ont déjà plusieurs inscriptions de repli, la migration
 * S'ARRÊTE avec un message sans donnée : elle ne corrige RIEN et ne supprime RIEN. Hors transaction : CREATE UNIQUE INDEX CONCURRENTLY
 * (la table n'est pas bloquée) ; dans une transaction (tests) : index simple. Verrou court (lock_timeout 5 s). Un index de CE nom laissé
 * INVALIDE (création interrompue ou doublon écrit entre le contrôle et la création) est supprimé, lui seul.
 * `down()` inerte ; `up()` rejouable.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'envoi_inscriptions_repli_entreprise_unique';

    public function up(): void
    {
        $horsTransaction = DB::transactionLevel() === 0;
        $n = $this->nombreEntreprisesEnDouble($horsTransaction);
        if ($n > 0) {
            throw new RuntimeException($this->message($n));
        }
        $this->avecVerrouCourt($horsTransaction, function () use ($horsTransaction) {
            $this->supprimerSiInvalide($horsTransaction);
            $concurrent = $horsTransaction ? 'CONCURRENTLY ' : '';
            try {
                DB::unprepared("CREATE UNIQUE INDEX {$concurrent}IF NOT EXISTS " . self::INDEX . ' ON envoi_inscriptions (workspace_id, entreprise_id) WHERE repli_generique');
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                // Un doublon écrit entre le contrôle et la création : ne JAMAIS laisser un index invalide.
                $this->supprimerSiInvalide($horsTransaction);
                throw new RuntimeException($this->message(max(1, $this->nombreEntreprisesEnDouble($horsTransaction))), 0, $e);
            }
        });
    }

    /** Retour arrière INERTE : l'index est conservé. */
    public function down(): void {}

    private function message(int $n): string
    {
        return "envoi_inscriptions : {$n} entreprise(s) ont plusieurs inscriptions de repli dans un même espace ; la migration s'arrête, rien n'est modifié. À traiter à la main avant de relancer.";
    }

    /** lock_timeout 5 s : dans une transaction, borné à elle (LOCAL) ; hors transaction, remis à sa valeur après coup. */
    private function avecVerrouCourt(bool $horsTransaction, Closure $f): void
    {
        if (! $horsTransaction) {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $f();

            return;
        }
        DB::statement("SET lock_timeout = '5s'");
        try {
            $f();
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    private function nombreEntreprisesEnDouble(bool $horsTransaction): int
    {
        $sql = 'SELECT count(*) AS n FROM (SELECT 1 FROM envoi_inscriptions WHERE repli_generique
                GROUP BY workspace_id, entreprise_id HAVING count(*) > 1) d';
        if ($horsTransaction) {
            return (int) DB::transaction(function () use ($sql) {
                DB::statement('SET LOCAL row_security = off');

                return DB::selectOne($sql)->n;
            });
        }
        $avant = (string) DB::selectOne("SELECT current_setting('row_security') AS v")->v;
        DB::statement("SELECT set_config('row_security', 'off', true)");
        try {
            return (int) DB::selectOne($sql)->n;
        } finally {
            DB::statement("SELECT set_config('row_security', ?, true)", [$avant]);
        }
    }

    private function supprimerSiInvalide(bool $horsTransaction): void
    {
        $invalide = DB::selectOne(
            'SELECT 1 AS e FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = ? AND n.nspname = \'public\' AND NOT i.indisvalid',
            [self::INDEX],
        );
        if ($invalide === null) {
            return;
        }
        DB::unprepared('DROP INDEX ' . ($horsTransaction ? 'CONCURRENTLY ' : '') . 'IF EXISTS public.' . self::INDEX);
    }
};
