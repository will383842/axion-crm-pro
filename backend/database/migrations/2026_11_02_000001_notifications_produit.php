<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot S9 — la cloche sait de quel produit vient chaque alerte, et sa gravité.
 *
 * Ajout seul, compatible avec la version précédente du code : les deux colonnes
 * ont une valeur par défaut, les anciennes lignes deviennent « crm / info ».
 * Retour arrière : `down()` retire les deux colonnes (aucune donnée de personne
 * n'y est stockée, seulement des libellés d'alerte).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE notifications
                ADD COLUMN IF NOT EXISTS produit TEXT NOT NULL DEFAULT 'crm',
                ADD COLUMN IF NOT EXISTS gravite TEXT NOT NULL DEFAULT 'info';
            ALTER TABLE notifications
                ADD CONSTRAINT notifications_produit_check CHECK (produit IN ('crm', 'envoi', 'socle')),
                ADD CONSTRAINT notifications_gravite_check CHECK (gravite IN ('critique', 'attention', 'info'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE notifications
                DROP CONSTRAINT IF EXISTS notifications_produit_check,
                DROP CONSTRAINT IF EXISTS notifications_gravite_check,
                DROP COLUMN IF EXISTS produit,
                DROP COLUMN IF EXISTS gravite;
        SQL);
    }
};
