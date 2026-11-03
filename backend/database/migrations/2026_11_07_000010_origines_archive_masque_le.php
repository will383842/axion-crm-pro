<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contrat v3 de l'origine (ADR § 7, I5) — ADDITIF SEULEMENT.
 *
 * `personne_origines_archive.masque_le` : marqueur horodaté de MISE À L'ÉCART
 * de la ligne d'archive, aligné sur le `masque_le` de l'entrée de la fiche.
 * Masquer n'écrit QUE ce marqueur : `origine` (dont `commentaire_ref`) n'est
 * jamais modifié, rien n'est supprimé.
 *
 * L'archive reste en AJOUT SEUL pour le rôle applicatif : il n'a ni UPDATE de
 * table, ni DELETE, ni TRUNCATE. On lui accorde seulement l'UPDATE de CETTE
 * colonne (droit par colonne) — il ne peut donc toujours pas réécrire `origine`.
 *
 * Aucun DROP ; `down()` ne retire rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE personne_origines_archive ADD COLUMN IF NOT EXISTS masque_le TIMESTAMPTZ NULL');
        DB::statement("COMMENT ON COLUMN personne_origines_archive.masque_le IS 'Mise a l ecart (ADR 7, I5) : masquee des ecrans et de l API, jamais supprimee, incluse dans l export art. 15.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT UPDATE (masque_le) ON public.personne_origines_archive TO ' . $role);
        }
    }

    /**
     * Retour arrière INERTE mais réel (NR-17 : aller, retour, aller sans erreur) :
     * on ne retire ni colonne ni marqueur, on ne fait que le consigner.
     */
    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN personne_origines_archive.masque_le IS 'Mise a l ecart (ADR 7, I5) : conservee au retour arriere.'");
    }
};
