<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VERDICTS-MIGRATION — le POURQUOI d'un verdict d'adresse + droits du cache DNS.
 * ADDITIF SEULEMENT, rejouable. Colonne `motif` nullable (lignes antérieures : NULL),
 * contrainte NOT VALID (aucune ligne relue). `down()` inerte : rien n'est retiré.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE envoi_verdicts_adresse ADD COLUMN IF NOT EXISTS motif TEXT NULL');
        DB::unprepared(
            <<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'envoi_verdicts_adresse_motif_ck'
                      AND conrelid = 'public.envoi_verdicts_adresse'::regclass
                ) THEN
                    ALTER TABLE envoi_verdicts_adresse ADD CONSTRAINT envoi_verdicts_adresse_motif_ck
                        CHECK (motif IS NULL OR motif IN ('syntaxe','jetable','rebond_dur','role_technique','generique',
                                                          'mx','a','mx_nul','sans_courrier','inexistant')) NOT VALID;
                END IF;
            END
            $$;
            SQL,
        );
        DB::statement("COMMENT ON COLUMN envoi_verdicts_adresse.motif IS 'VERDICTS : pourquoi ce verdict (liste fermee). NULL pour les lignes anterieures. Ajout seul.'");

        // UPDATE : nécessaire au `ON CONFLICT DO UPDATE` de `CacheDomaines::enregistrer`.
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.email_domaines TO ' . $role);
        }
    }

    /**
     * Retour arrière INERTE : la colonne, la contrainte et leurs valeurs sont conservées.
     * Corps VIDE voulu (NR-17 classe « vide » ; un COMMENT serait classé « réel »).
     */
    public function down(): void {}
};
