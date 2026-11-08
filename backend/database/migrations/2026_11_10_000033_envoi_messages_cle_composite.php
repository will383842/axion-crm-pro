<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L1 (audit 07/11) — intégrité référentielle COMPOSITE.
 * Un message ne peut pas pointer l'inscription d'un autre espace : la clé
 * étrangère devient (workspace_id, inscription_id) -> envoi_inscriptions (workspace_id, id).
 * ADDITIF SEULEMENT : on AJOUTE une contrainte d'unicité et une clé étrangère ;
 * la clé étrangère simple de 000032 reste en place, aucune ligne n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_inscriptions_workspace_id_id_key' AND conrelid = 'envoi_inscriptions'::regclass) THEN
                    ALTER TABLE envoi_inscriptions ADD CONSTRAINT envoi_inscriptions_workspace_id_id_key UNIQUE (workspace_id, id);
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_messages_workspace_inscription_fk' AND conrelid = 'envoi_messages'::regclass) THEN
                    ALTER TABLE envoi_messages ADD CONSTRAINT envoi_messages_workspace_inscription_fk
                        FOREIGN KEY (workspace_id, inscription_id) REFERENCES envoi_inscriptions (workspace_id, id);
                END IF;
            END $$
            SQL,
        );
        // Les deux tables restent sous RLS FORCE (réaffirmé : sans effet si déjà posé).
        DB::statement('ALTER TABLE envoi_inscriptions FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_messages FORCE ROW LEVEL SECURITY');
    }

    /** Retour arrière INERTE : les contraintes restent. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_messages IS 'Envoi P0 L1 : table conservee au retour arriere (cle composite conservee).'");
    }
};
