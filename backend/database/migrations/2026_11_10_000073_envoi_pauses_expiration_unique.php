<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L7 (correctifs) — AJOUTS SEULS sur `envoi_pauses` :
 *  - `expire_le` : une pause peut avoir une durée (2 plaintes en 7 jours = arrêt de 48 h) ;
 *  - index UNIQUE partiel : une seule pause ACTIVE par (espace, motif, fournisseur), même sous concurrence.
 * down() : l'index se retire ; la colonne n'est retirée que si aucune ligne ne l'utilise (aucun effacement).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE envoi_pauses ADD COLUMN IF NOT EXISTS expire_le TIMESTAMPTZ NULL');
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS envoi_pauses_active_unique
             ON envoi_pauses (workspace_id, motif, COALESCE(fournisseur, '')) WHERE levee_le IS NULL",
        );
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS envoi_pauses_active_unique;
            DO $$
            DECLARE n BIGINT;
            BEGIN
                IF EXISTS (SELECT 1 FROM information_schema.columns
                           WHERE table_schema = current_schema() AND table_name = 'envoi_pauses' AND column_name = 'expire_le') THEN
                    EXECUTE 'SELECT COUNT(*) FROM envoi_pauses WHERE expire_le IS NOT NULL' INTO n;
                    IF n > 0 THEN
                        RAISE EXCEPTION 'envoi_pauses.expire_le est utilisée : retour refusé (aucun effacement)';
                    END IF;
                END IF;
            END $$;
            ALTER TABLE IF EXISTS envoi_pauses DROP COLUMN IF EXISTS expire_le;
        SQL);
    }
};
