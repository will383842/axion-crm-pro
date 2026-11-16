<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi C1-0 — deux index PARTIELS de `envoi_messages` pour la recherche par identifiant d'injection (cahier C1-F2A §1.3).
 * AJOUT D'INDEX SEUL, aucune ligne touchée. Aujourd'hui aucun index ne sert la recherche de `RecepteurRetoursSequence`.
 *
 * Hors transaction (`$withinTransaction = false`) : CONCURRENTLY n'est pas permis dans un bloc de transaction, et il ne
 * bloque aucune écriture (verrou SHARE UPDATE EXCLUSIVE seulement ; `lock_timeout` borne l'attente du verrou initial).
 * Dans une transaction (tests), index simple. Un index laissé INVALIDE par une création interrompue est supprimé (lui seul :
 * DROP INDEX CONCURRENTLY) puis recréé. `up()` rejouable (IF NOT EXISTS).
 *
 * `down()` INERTE (corps vide voulu, NR-17) : un index n'est pas une donnée, mais le retirer ferait revenir la recherche
 * à un balayage complet de la table.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> nom de l'index => colonne */
    private const INDEX = [
        'envoi_messages_injection' => 'id_injection',
        'envoi_messages_injection_serveur' => 'id_injection_serveur',
    ];

    public function up(): void
    {
        $horsTransaction = DB::transactionLevel() === 0;
        DB::statement("SET lock_timeout = '30s'");
        try {
            foreach (self::INDEX as $nom => $colonne) {
                $invalide = DB::selectOne(
                    "SELECT 1 AS e FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE c.relname = ? AND n.nspname = 'public' AND NOT i.indisvalid",
                    [$nom],
                );
                if ($invalide !== null && $horsTransaction) {
                    DB::unprepared("DROP INDEX CONCURRENTLY IF EXISTS public.{$nom}");
                }
                $concurremment = $horsTransaction ? 'CONCURRENTLY ' : '';
                DB::unprepared(
                    "CREATE INDEX {$concurremment}IF NOT EXISTS {$nom} ON envoi_messages (workspace_id, {$colonne}) WHERE {$colonne} IS NOT NULL",
                );
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /** Retour arrière INERTE : les index sont conservés. */
    public function down(): void {}
};
