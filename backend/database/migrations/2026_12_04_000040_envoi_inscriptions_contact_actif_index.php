<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi X1-L1 — index partiel des inscriptions actives par personne (l'unicité « une personne n'est inscrite qu'à une séquence active »
 * et la recherche des inscriptions actives d'un contact s'appuient dessus). AJOUT D'INDEX SEUL.
 * Hors transaction, comme le reste du dépôt : CONCURRENTLY n'est pas permis dans un bloc de transaction. Dans une transaction (tests),
 * index simple. Un index laissé INVALIDE par une création interrompue est supprimé (lui seul : DROP INDEX CONCURRENTLY) puis recréé.
 * `down()` inerte ; `up()` rejouable (IF NOT EXISTS).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $horsTransaction = DB::transactionLevel() === 0;
        $invalide = DB::selectOne(
            "SELECT 1 AS e FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = 'idx_envoi_inscriptions_contact_actif' AND n.nspname = 'public' AND NOT i.indisvalid",
        );
        if ($invalide !== null && $horsTransaction) {
            DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS public.idx_envoi_inscriptions_contact_actif');
        }
        if ($horsTransaction) {
            DB::unprepared("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_envoi_inscriptions_contact_actif ON envoi_inscriptions (workspace_id, contact_id) WHERE statut = 'active'");
        } else {
            DB::unprepared("CREATE INDEX IF NOT EXISTS idx_envoi_inscriptions_contact_actif ON envoi_inscriptions (workspace_id, contact_id) WHERE statut = 'active'");
        }
    }

    /** Retour arrière INERTE : l'index est conservé. */
    public function down(): void {}
};
