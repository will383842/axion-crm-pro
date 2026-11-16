<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P2-A — unicité « une personne n'est inscrite qu'une fois par séquence » (équivalent de UNIQUE (workspace_id, sequence_id, contact_id)).
 * AJOUT D'INDEX SEUL, partiel : aucune ligne n'a de `personne_id`, donc aucun doublon possible. Hors transaction (CONCURRENTLY) ; dans une
 * transaction (tests), index simple. Un index de CE nom laissé INVALIDE est supprimé (lui seul) puis recréé. `down()` inerte ; `up()` rejouable.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $horsTransaction = DB::transactionLevel() === 0;
        $invalide = DB::selectOne(
            "SELECT 1 AS e FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = 'envoi_inscriptions_personne_unique' AND n.nspname = 'public' AND NOT i.indisvalid",
        );
        if ($invalide !== null && $horsTransaction) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS public.envoi_inscriptions_personne_unique');
        }
        $concurrent = $horsTransaction ? 'CONCURRENTLY ' : '';
        DB::unprepared("CREATE UNIQUE INDEX {$concurrent}IF NOT EXISTS envoi_inscriptions_personne_unique ON envoi_inscriptions (workspace_id, sequence_id, personne_id) WHERE personne_id IS NOT NULL");
    }

    /** Retour arrière INERTE : l'index est conservé. */
    public function down(): void {}
};
