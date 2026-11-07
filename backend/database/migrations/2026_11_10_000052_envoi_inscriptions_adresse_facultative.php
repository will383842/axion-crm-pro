<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L5 — l'adresse en clair n'est plus écrite (décision du 2026-08-18 :
 * la liste de suppression ne garde JAMAIS l'adresse en clair).
 *
 * ADDITIF SEULEMENT : aucune donnée n'est supprimée ni modifiée. La colonne
 * `adresse` devient FACULTATIVE (NULL accepté) et n'est plus alimentée ; les
 * lignes existantes la conservent telle quelle (leur retrait relève d'une
 * décision séparée). L'empreinte (`empreinte_adresse`, déjà présente) porte
 * désormais seule l'identité, avec une référence au contact (`contact_id`,
 * sans clé étrangère : aucune cascade ne doit effacer une inscription).
 * `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE envoi_inscriptions ALTER COLUMN adresse DROP NOT NULL');
        DB::statement('ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS contact_id BIGINT');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_inscriptions_contact ON envoi_inscriptions (workspace_id, contact_id) WHERE contact_id IS NOT NULL');
    }

    /** Retour arrière INERTE : rien ne s'efface, la colonne reste facultative. */
    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN envoi_inscriptions.contact_id IS 'Envoi P0 L5 : reference du contact (sans adresse en clair). Conservee au retour arriere.'");
    }
};
