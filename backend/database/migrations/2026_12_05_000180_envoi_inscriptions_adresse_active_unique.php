<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P2-B — « une inscription active par adresse et par espace » : index UNIQUE PARTIEL (AJOUT D'INDEX SEUL).
 *
 * Contrôle préalable HORS RLS (la table est en FORCE ROW LEVEL SECURITY : sans `row_security = off`, le propriétaire sans contexte d'espace
 * verrait zéro ligne ; s'il est soumis à la RLS, PostgreSQL lève une erreur = échec fermé). Si des doublons actifs existent, la migration
 * S'ARRÊTE avec un message sans adresse ni empreinte : elle ne corrige RIEN et ne supprime RIEN (décision du fondateur).
 * Hors transaction : CREATE UNIQUE INDEX CONCURRENTLY (la table n'est pas bloquée) ; dans une transaction (tests) : index simple.
 * Un index de CE nom laissé INVALIDE (création interrompue ou doublon écrit entre le contrôle et la création) est supprimé, lui seul :
 * un index unique invalide continue d'imposer l'unicité, il ne doit pas rester. `down()` inerte ; `up()` rejouable.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'envoi_inscriptions_adresse_active_unique';

    public function up(): void
    {
        $horsTransaction = DB::transactionLevel() === 0;
        $n = $this->nombreAdressesEnDouble($horsTransaction);
        if ($n > 0) {
            throw new RuntimeException($this->message($n));
        }
        $this->supprimerSiInvalide($horsTransaction);
        $concurrent = $horsTransaction ? 'CONCURRENTLY ' : '';
        try {
            DB::unprepared("CREATE UNIQUE INDEX {$concurrent}IF NOT EXISTS " . self::INDEX . " ON envoi_inscriptions (workspace_id, empreinte_adresse) WHERE statut = 'active'");
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            // Un doublon écrit par l'ancien code entre le contrôle et la création : ne JAMAIS laisser un index invalide.
            $this->supprimerSiInvalide($horsTransaction);
            throw new RuntimeException($this->message(max(1, $this->nombreAdressesEnDouble($horsTransaction))), 0, $e);
        }
    }

    /** Retour arrière INERTE : l'index est conservé. */
    public function down(): void {}

    private function message(int $n): string
    {
        return "envoi_inscriptions : {$n} adresse(s) ont plusieurs inscriptions actives dans un même espace ; la migration s'arrête, rien n'est modifié. À traiter à la main avant de relancer.";
    }

    private function nombreAdressesEnDouble(bool $horsTransaction): int
    {
        $sql = "SELECT count(*) AS n FROM (SELECT 1 FROM envoi_inscriptions WHERE statut = 'active'
                GROUP BY workspace_id, empreinte_adresse HAVING count(*) > 1) d";
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
