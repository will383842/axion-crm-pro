<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi L6-0 — index PARTIEL d'expression de `envoi_evenements` pour les compteurs de campagne (cahier L5-L6 § 5).
 * AJOUT D'INDEX SEUL : aucune ligne lue ni écrite, `envoi_evenements` reste en ajout seul.
 *
 * Expression `(id_injection)::text` : `envoi_messages.id_injection` et `id_injection_serveur` sont du texte (forme Kumo
 * non UUID possible) ; la comparaison se fait donc en texte.
 *
 * Hors transaction (`$withinTransaction = false`) : CONCURRENTLY n'est pas permis dans un bloc de transaction, et il ne
 * bloque aucune écriture (`lock_timeout` borne l'attente du verrou initial). Dans une transaction (tests), index simple.
 * Un index de CE nom laissé INVALIDE par une création interrompue est supprimé (lui seul) puis recréé. `up()` rejouable.
 *
 * `down()` INERTE (corps vide voulu, NR-17) : retirer l'index ferait revenir les compteurs à un balayage complet.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'envoi_evenements_injection_texte';

    public function up(): void
    {
        $horsTransaction = DB::transactionLevel() === 0;
        DB::statement("SET lock_timeout = '30s'");
        try {
            $invalide = DB::selectOne(
                "SELECT 1 AS e FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE c.relname = ? AND n.nspname = 'public' AND NOT i.indisvalid",
                [self::NOM],
            );
            if ($invalide !== null && $horsTransaction) {
                DB::unprepared('DROP INDEX CONCURRENTLY IF EXISTS public.' . self::NOM);
            }
            $concurremment = $horsTransaction ? 'CONCURRENTLY ' : '';
            DB::unprepared(
                "CREATE INDEX {$concurremment}IF NOT EXISTS " . self::NOM
                . ' ON envoi_evenements (workspace_id, (id_injection::text)) WHERE id_injection IS NOT NULL',
            );
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /** Retour arrière INERTE : l'index est conservé. */
    public function down(): void {}
};
