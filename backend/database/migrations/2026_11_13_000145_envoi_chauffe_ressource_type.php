<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K8 — la chauffe de l'IP est séparée de celle du domaine. AJOUT SEUL : une colonne, rien n'est supprimé.
 * `boite` porte la référence de la ressource ; `ressource_type` dit de quelle sorte elle est. Les lignes
 * existantes (boîtes, donc chauffe par domaine) prennent 'domaine'. La clé unique reste valide : une
 * référence d'IP ne peut pas être l'identifiant d'une boîte.
 * down() est inerte (ajout seul) ; up() est rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE envoi_chauffe_jours ADD COLUMN IF NOT EXISTS ressource_type TEXT NOT NULL DEFAULT 'domaine' CHECK (ressource_type IN ('ip', 'domaine'))");
        DB::statement("COMMENT ON COLUMN envoi_chauffe_jours.ressource_type IS 'K8 : ip ou domaine. Les compteurs d IP ne comptent jamais dans les totaux du domaine.'");
    }

    public function down(): void
    {
        // Inerte voulu : on ne retire jamais une colonne de compteurs d'envoi (ajout seul, aucun effacement).
    }
};
