<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L1 (audit 07/11) — `search_path` FIXE sur le déclencheur de figement.
 * La règle du dépôt (depuis 2026_08_16_200000) : toute fonction du dépôt a un `search_path` fixe
 * (`PrivilegesDeLaFonctionDefinerCouvertureTest`). ADDITIF SEULEMENT : on règle une propriété de la
 * fonction (`ALTER FUNCTION`), on ne touche ni à la table ni aux lignes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER FUNCTION envoi_inscriptions_fige() SET search_path = pg_catalog, public');
        // Les tables du séquenceur restent sous RLS FORCE (réaffirmé : sans effet si déjà posé).
        DB::statement('ALTER TABLE envoi_inscriptions FORCE ROW LEVEL SECURITY');
    }

    /** Retour arrière INERTE : le réglage reste. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_inscriptions IS 'Envoi P0 L1 : table conservee au retour arriere (search_path de la fonction de figement conserve).'");
    }
};
